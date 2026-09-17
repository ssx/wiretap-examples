<?php

declare(strict_types=1);

namespace App\Providers;

use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\ServiceProvider;
use Ssx\Wiretap\Blocklist\ArrayBlocklistProvider;
use Ssx\Wiretap\Blocklist\Blocklist;
use Ssx\Wiretap\Blocklist\EnvBlocklistProvider;
use Ssx\Wiretap\Blocklist\PresetBlocklistProvider;
use Ssx\Wiretap\Contract\ContextEnricher;
use Ssx\Wiretap\Correlation;
use Ssx\Wiretap\Exchange;
use Ssx\Wiretap\Guzzle\Stack;
use Ssx\Wiretap\Recorder;
use Ssx\Wiretap\Redaction\RedactionConfig;
use Ssx\Wiretap\Redaction\Redactor;
use Ssx\Wiretap\Sampler;
use Ssx\Wiretap\Sink\NdjsonFileSink;
use Ssx\Wiretap\Sink\NullSink;

/**
 * Wires wiretap into the container.
 *
 * This is what `ssx/wiretap-laravel` will eventually do for you. It lives in
 * the example app so you can see exactly what it does rather than take it on
 * trust — and so you can copy it into a project that needs something slightly
 * different.
 */
final class WiretapServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(Recorder::class, function (): Recorder {
            $config = config('wiretap');

            $recorder = new Recorder(
                sink: $config['enabled']
                    ? new NdjsonFileSink($config['path'])
                    : new NullSink(),
                blocklist: $this->blocklist($config),
                redactor: new Redactor(new RedactionConfig(
                    enabled: (bool) $config['redaction']['enabled'],
                    bodyPaths: $config['redaction']['body_paths'],
                    maxBodyBytes: (int) $config['redaction']['max_body_bytes'],
                )),
                sampler: new Sampler(
                    rateBasisPoints: (int) $config['sample_rate_basis_points'],
                    alwaysKeepFailures: (bool) $config['always_keep_failures'],
                    slowThresholdUs: $config['slow_threshold_us'],
                ),
                enabled: (bool) $config['enabled'],
            );

            return $recorder->addEnricher($this->enricher());
        });
    }

    public function boot(): void
    {
        if (!config('wiretap.enabled')) {
            return;
        }

        $recorder = $this->app->make(Recorder::class);

        // Adopt the inbound request id so every outbound call made while
        // handling it shares one correlation id.
        Correlation::start(
            request()->header('traceparent')
                ?? request()->header('X-Request-Id')
                ?? null
        );

        // Laravel's own HTTP client is Guzzle underneath, and globalOptions
        // reaches every pending request it creates.
        Http::globalOptions([
            'handler' => Stack::wrap($recorder),
        ]);

        // Anything resolving Guzzle from the container gets a recorded client
        // too. Code doing `new Client()` directly is not reachable this way —
        // that is what ssx/wiretap-auto is for.
        $this->app->bind(\GuzzleHttp\Client::class, fn (): \GuzzleHttp\Client => new \GuzzleHttp\Client([
            'handler' => Stack::wrap($recorder),
        ]));
    }

    /**
     * @param array<string, mixed> $config
     */
    private function blocklist(array $config): Blocklist
    {
        return new Blocklist([
            new PresetBlocklistProvider($config['presets']),
            new ArrayBlocklistProvider($config['blocklist'], 'config:wiretap.blocklist'),
            new EnvBlocklistProvider(),
        ]);
    }

    /**
     * Adds the route, controller or console command that caused the call.
     *
     * The capture layer sits below the framework and cannot know any of this,
     * which is the whole reason the enricher contract exists.
     */
    private function enricher(): ContextEnricher
    {
        return new class implements ContextEnricher {
            public function enrich(Exchange $exchange): Exchange
            {
                if (app()->runningInConsole()) {
                    return $exchange->withContext([
                        'command' => implode(' ', array_slice($_SERVER['argv'] ?? [], 1)) ?: null,
                    ]);
                }

                return $exchange->withContext([
                    'route' => optional(request()->route())->getName(),
                    'uri' => request()->path(),
                    'user_id' => auth()->id(),
                ]);
            }
        };
    }
}
