<?php

declare(strict_types=1);

namespace App;

use Ssx\Wiretap\Blocklist\ArrayBlocklistProvider;
use Ssx\Wiretap\Blocklist\Blocklist;
use Ssx\Wiretap\Blocklist\EnvBlocklistProvider;
use Ssx\Wiretap\Blocklist\PresetBlocklistProvider;
use Ssx\Wiretap\Recorder;
use Ssx\Wiretap\Redaction\RedactionConfig;
use Ssx\Wiretap\Redaction\Redactor;
use Ssx\Wiretap\Sampler;
use Ssx\Wiretap\Sink\MultiSink;
use Ssx\Wiretap\Sink\NdjsonFileSink;

/**
 * Wiretap wiring for an application with no framework and no container.
 *
 * Everything here is ordinary object construction. The only reason this file
 * exists at all is to keep the configuration in one place — there is no magic
 * to hide.
 */
final class Wiretap
{
    private static ?Recorder $recorder = null;

    public static function recorder(): Recorder
    {
        return self::$recorder ??= self::build();
    }

    private static function build(): Recorder
    {
        $blocklist = new Blocklist([
            // Payment gateways, on by default. Any URL matching one of these
            // produces no record at all — the body is never even read.
            new PresetBlocklistProvider([
                PresetBlocklistProvider::PAYMENT_GATEWAYS,
                PresetBlocklistProvider::CLOUD_METADATA,
            ]),

            // Your own endpoints that must never be captured. Wiretap cannot
            // know which of these carry cardholder or personal data. You do.
            new ArrayBlocklistProvider([
                'internal-billing.example.com',
                '*.myacquirer.test',
            ], 'app-config'),

            // Lets an operator add a host without a deploy.
            new EnvBlocklistProvider(),
        ]);

        $redactor = new Redactor(new RedactionConfig(
            // Paths specific to this application's payload shapes.
            bodyPaths: [
                'card.number',
                'card.cvv',
                '*.password',
                'customer.email',
                'payments.*.token',
            ],
            maxBodyBytes: 65536,
        ));

        return new Recorder(
            sink: new MultiSink(
                new NdjsonFileSink(__DIR__ . '/../var'),
            ),
            blocklist: $blocklist,
            redactor: $redactor,
            sampler: new Sampler(
                // Everything, because this is a debugging session. In
                // production you would drop this to a few hundred basis
                // points and rely on the always-keep rules below.
                rateBasisPoints: 10000,
                alwaysKeepFailures: true,
                slowThresholdUs: 2_000_000,
            ),
        );
    }
}
