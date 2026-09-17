<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

/**
 * Visit /wiretap-demo to see the same three calls made during a real HTTP
 * request. The correlation id is seeded from the inbound request, so every
 * outbound call below is grouped under it.
 */
Route::get('/wiretap-demo', function (App\Services\OrderApi $api, Ssx\Wiretap\Recorder $recorder) {
    abort_unless(config('wiretap.enabled'), 404);

    $api->createOrder('WIDGET-1', 2);
    $api->brokenCall();
    $api->chargeCard();

    $recorder->flush();

    $file = rtrim((string) config('wiretap.path'), '/') . '/wiretap-' . gmdate('Y-m-d') . '.ndjson';
    $lines = is_file($file) ? (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: []) : [];

    return response()->json([
        'correlation_id' => Ssx\Wiretap\Correlation::id(),
        'recorded' => count($lines),
        'blocked' => $recorder->blocklist()->blockCounts(),
        'exchanges' => array_map(
            static fn (string $l) => json_decode($l, true),
            array_slice($lines, -3),
        ),
    ], 200, [], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
})->name('wiretap.demo');
