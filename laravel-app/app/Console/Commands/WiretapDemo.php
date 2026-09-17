<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\OrderApi;
use Illuminate\Console\Command;
use Ssx\Wiretap\Correlation;
use Ssx\Wiretap\Recorder;

final class WiretapDemo extends Command
{
    protected $signature = 'wiretap:demo';

    protected $description = 'Make three outbound calls and show what wiretap recorded';

    public function handle(OrderApi $api, Recorder $recorder): int
    {
        if (!config('wiretap.enabled')) {
            $this->error('Wiretap is disabled. Set WIRETAP_ENABLED=true in .env');

            return self::FAILURE;
        }

        Correlation::start('artisan-demo');

        $this->info('Making outbound calls...');
        $this->line('');
        $this->line('  1. createOrder()  — normal call carrying secrets');
        $api->createOrder('WIDGET-1', 2);

        $this->line('  2. brokenCall()   — 502, kept regardless of sampling');
        $api->brokenCall();

        $this->line('  3. chargeCard()   — BLOCKED host, nothing recorded');
        $api->chargeCard();

        $recorder->flush();

        $file = rtrim((string) config('wiretap.path'), '/') . '/wiretap-' . gmdate('Y-m-d') . '.ndjson';

        if (!is_file($file)) {
            $this->error("No log file at {$file}");

            return self::FAILURE;
        }

        $lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];

        $this->line('');
        $this->info(sprintf('%d exchanges recorded (3 calls made — one was blocked)', count($lines)));
        $this->line('');

        $this->table(
            ['Method', 'Status', 'ms', 'URI', 'Context'],
            array_map(static function (string $line): array {
                $e = json_decode($line, true);

                return [
                    $e['method'],
                    $e['status'] ?? 'ERR',
                    number_format(($e['timings']['total'] ?? 0) / 1000),
                    \Illuminate\Support\Str::limit($e['uri'], 48),
                    $e['context']['command'] ?? '',
                ];
            }, $lines),
        );

        $first = json_decode($lines[0] ?? '{}', true);

        if (isset($first['request']['body']['bytes'])) {
            $this->line('Request body as recorded:');
            $this->line('  ' . $first['request']['body']['bytes']);
            $this->line('');
        }

        $this->line('Authorization header as recorded:');
        foreach ($first['request']['headers'] ?? [] as [$name, $value]) {
            if (strtolower($name) === 'authorization') {
                $this->line("  {$name}: {$value}");
            }
        }

        $blocked = $recorder->blocklist()->blockCounts();

        if ($blocked !== []) {
            $this->line('');
            $this->line('Blocked (host and count only — no path, no payload):');
            foreach ($blocked as $host => $count) {
                $this->line(sprintf('  %-30s %d', $host, $count));
            }
        }

        $this->line('');
        $this->line("Raw log: {$file}");

        return self::SUCCESS;
    }
}
