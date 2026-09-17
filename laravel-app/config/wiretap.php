<?php

declare(strict_types=1);

use Ssx\Wiretap\Blocklist\PresetBlocklistProvider;

return [

    /*
    |--------------------------------------------------------------------------
    | Enabled
    |--------------------------------------------------------------------------
    |
    | Wiretap is a debugging tool, not a logging product. It records complete
    | request and response bodies, which routinely contain personal data and
    | on a commerce site can contain cardholder data.
    |
    | Turn it on to investigate something, then turn it off again. It defaults
    | to off outside local and testing environments for exactly that reason.
    |
    */

    'enabled' => env('WIRETAP_ENABLED', env('APP_ENV') === 'local'),

    /*
    |--------------------------------------------------------------------------
    | Blocklist
    |--------------------------------------------------------------------------
    |
    | A URL matching any of these produces no record at all. The body is never
    | read, the headers are never copied, nothing enters the buffer. This is a
    | gate, not a filter — use it for anything carrying cardholder data.
    |
    | Patterns:
    |   api.stripe.com                exact host, any scheme or path
    |   *.adyen.com                   the host and any subdomain
    |   api.foo.com/v2/payments*      host plus path prefix
    |   ~^https://api\.foo\.com/v2/~  full-URL regex, tilde-delimited
    |
    */

    'presets' => [
        PresetBlocklistProvider::PAYMENT_GATEWAYS,
        PresetBlocklistProvider::CLOUD_METADATA,
    ],

    'blocklist' => [
        'internal-billing.example.com',
        '*.myacquirer.test',
    ],

    /*
    |--------------------------------------------------------------------------
    | Redaction
    |--------------------------------------------------------------------------
    |
    | Applied at capture, never at display. Body paths use dot notation with
    | `*` wildcards and are matched against decoded JSON, so they are precise
    | in a way a regex over the raw text cannot be.
    |
    | Wiretap cannot know which keys in your payloads are sensitive. These are
    | yours to set.
    |
    */

    'redaction' => [
        'enabled' => env('WIRETAP_REDACT', true),

        'body_paths' => [
            'card.number',
            'card.cvv',
            '*.password',
            'customer.email',
            'payments.*.token',
        ],

        'max_body_bytes' => env('WIRETAP_BODY_LIMIT', 65536),
    ],

    /*
    |--------------------------------------------------------------------------
    | Sampling
    |--------------------------------------------------------------------------
    |
    | Deterministic on the correlation id, so every outbound call made while
    | handling one inbound request shares the same decision. Sampling per call
    | would give you half a conversation.
    |
    | 10000 basis points = keep everything. In production you would drop this
    | and lean on the always-keep rules.
    |
    */

    'sample_rate_basis_points' => env('WIRETAP_SAMPLE_BP', 10000),
    'always_keep_failures' => true,
    'slow_threshold_us' => 2_000_000,

    /*
    |--------------------------------------------------------------------------
    | Storage
    |--------------------------------------------------------------------------
    */

    'path' => storage_path('logs/wiretap'),

    'retention_days' => env('WIRETAP_RETENTION_DAYS', 7),

];
