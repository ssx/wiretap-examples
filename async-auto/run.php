<?php

declare(strict_types=1);

/**
 * Run me:  php run.php
 *
 * Makes eight outbound calls through two "vendor" SDKs — a Guzzle Pool, two
 * getAsync() calls and a hand-rolled curl_multi batch — then prints what
 * wiretap-auto recorded. Neither SDK knows wiretap exists.
 */

use App\CatalogSdk;
use App\ShippingSdk;
use Ssx\Wiretap\Auto\Wiretap;

// wiretap-auto is configured from the environment and registers its hooks
// from Composer's autoloader, so these have to be set before the require.
// In a real deployment they would be in the environment already; defaults
// are filled in here only so the demo runs with no setup.
foreach (['WIRETAP_ENABLED' => 'true', 'WIRETAP_PATH' => __DIR__ . '/var'] as $name => $default) {
    if (getenv($name) === false) {
        putenv("{$name}={$default}");
    }
}

require __DIR__ . '/vendor/autoload.php';

if (!Wiretap::isCapturing()) {
    echo "wiretap-auto is not capturing. Diagnostics:\n\n";
    print_r(Wiretap::diagnostics());
    echo "\nThe usual cause is ext-opentelemetry not being loaded for this PHP binary:\n";
    echo "  pecl install opentelemetry\n";
    echo "  php -m | grep opentelemetry\n";
    exit(1);
}

$file = __DIR__ . '/var/wiretap-' . gmdate('Y-m-d') . '.ndjson';

// The log is appended to, so remember where this run starts.
clearstatcache();
$offset = is_file($file) ? filesize($file) : 0;

echo "Making outbound calls from code with no wiretap wiring...\n\n";

$catalog = new CatalogSdk();

echo "  1. CatalogSdk::fetchProducts()        — Guzzle Pool, 3 concurrent\n";
$started = microtime(true);
$catalog->fetchProducts();
$poolSeconds = microtime(true) - $started;

echo "  2. CatalogSdk::fetchStockAndPricing() — 2 x getAsync(), one is a 503\n";
$catalog->fetchStockAndPricing();

echo "  3. ShippingSdk::quoteAll()            — raw curl_multi, one host blocked\n";
(new ShippingSdk())->quoteAll();

printf("\nThe Pool of three one-second requests took %.1fs.\n", $poolSeconds);

Wiretap::recorder()->flush();

if (!is_file($file)) {
    echo "\nNo log file at {$file}\n";
    exit(1);
}

$lines = array_filter(explode("\n", (string) file_get_contents($file, offset: $offset)));

printf("\n%d exchanges recorded (8 calls were made — one was blocked)\n\n", count($lines));
echo str_repeat('=', 78), "\n";

foreach ($lines as $line) {
    $e = json_decode($line, true);

    printf(
        "%-4s %-3s  %5.0fms  %-6s  %s\n",
        $e['method'],
        $e['status'] ?? 'ERR',
        ($e['timings']['total'] ?? 0) / 1000,
        $e['transport'],
        $e['uri'],
    );

    foreach ($e['request']['headers'] as [$name, $value]) {
        if (in_array(strtolower($name), ['authorization', 'x-api-key'], true)) {
            printf("       %s: %s\n", $name, $value);
        }
    }

    $body = $e['response']['body'] ?? [];

    if (isset($body['bytes'])) {
        printf("       response: %d bytes captured\n", strlen($body['bytes']));
    } elseif (isset($body['omitted_reason'])) {
        printf("       response: not captured (%s)\n", $body['omitted_reason']);
    }

    echo str_repeat('-', 78), "\n";
}

echo "\nCheck the output above:\n";
echo "  - all three Pool requests are there, and the batch took about one\n";
echo "    second rather than three — capture did not serialise them\n";
echo "  - the 503 is recorded with its status, not as a transport error\n";
echo "  - Authorization and X-Api-Key are redacted\n";
echo "  - raw curl_multi responses carry their bodies (RETURNTRANSFER);\n";
echo "    Guzzle's are marked streaming, because Guzzle writes through\n";
echo "    CURLOPT_WRITEFUNCTION and there is no returned string to read\n";
echo "  - api.stripe.com does not appear at all\n";

echo "\nRaw log: {$file}\n";
