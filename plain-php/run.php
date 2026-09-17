<?php

declare(strict_types=1);

/**
 * Run me:  php run.php
 *
 * Makes three outbound calls, then prints what wiretap recorded about them.
 */

use App\OrderApi;
use App\Wiretap;
use Ssx\Wiretap\Correlation;
use Ssx\Wiretap\Exchange;

require __DIR__ . '/vendor/autoload.php';

// One correlation id for this whole run, so every call below is grouped.
// In a web app you would seed this from an inbound X-Request-Id or
// traceparent header.
Correlation::start('example-run');

$api = new OrderApi();

echo "Making outbound calls...\n\n";

echo "  1. createOrder()  — normal call carrying secrets\n";
$api->createOrder('WIDGET-1', 2);

echo "  2. brokenCall()   — 502, kept regardless of sampling\n";
$api->brokenCall();

echo "  3. chargeCard()   — BLOCKED host, nothing recorded\n";
$api->chargeCard();

// Normally this happens automatically at shutdown. Called explicitly here so
// the file is on disk before we read it back.
Wiretap::recorder()->flush();

$file = __DIR__ . '/var/wiretap-' . gmdate('Y-m-d') . '.ndjson';

if (!is_file($file)) {
    echo "\nNo log file at {$file} — did the calls fail to run?\n";
    exit(1);
}

$lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];

printf("\n%d exchanges recorded (3 calls were made — one was blocked)\n\n", count($lines));
echo str_repeat('=', 78), "\n";

foreach ($lines as $line) {
    $e = json_decode($line, true);

    printf(
        "%-6s %-3s  %6.0fms  %s\n",
        $e['method'],
        $e['status'] ?? 'ERR',
        ($e['timings']['total'] ?? 0) / 1000,
        $e['uri'],
    );

    foreach ($e['request']['headers'] as [$name, $value]) {
        if (in_array(strtolower($name), ['authorization', 'content-type'], true)) {
            printf("       %s: %s\n", $name, $value);
        }
    }

    if (isset($e['request']['body']['bytes'])) {
        printf("       body: %s\n", substr($e['request']['body']['bytes'], 0, 180));
    }

    echo str_repeat('-', 78), "\n";
}

echo "\nCheck the output above:\n";
echo "  - api_key in the URL is redacted\n";
echo "  - Authorization is redacted\n";
echo "  - card.number and card.cvv are redacted\n";
echo "  - customer.email is redacted\n";
echo "  - order_ref 1234567890123456 survives — it fails the Luhn check,\n";
echo "    so it is not mistaken for a card number\n";
echo "  - api.stripe.com does not appear at all\n\n";

$blocked = Wiretap::recorder()->blocklist()->blockCounts();

if ($blocked !== []) {
    echo "Blocked (host and count only — no path, no payload):\n";
    foreach ($blocked as $host => $count) {
        printf("  %-30s %d\n", $host, $count);
    }
}

echo "\nRaw log: {$file}\n";
