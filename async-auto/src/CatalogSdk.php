<?php

declare(strict_types=1);

namespace App;

use GuzzleHttp\Client;
use GuzzleHttp\Pool;
use GuzzleHttp\Promise\Utils;
use GuzzleHttp\Psr7\Request;

/**
 * Stands in for a third-party SDK in your vendor directory.
 *
 * It builds its own Guzzle client with no middleware and no way for you to
 * add any. Every call here is asynchronous, so none of it touches curl_exec:
 * Guzzle sends it all through curl_multi_*.
 *
 * There is no wiretap code in this file, and there is none anywhere else in
 * the application either.
 */
final class CatalogSdk
{
    private Client $client;

    public function __construct()
    {
        $this->client = new Client([
            'base_uri' => 'https://httpbin.org',
            'timeout' => 10,
            'http_errors' => false,
            'headers' => ['Authorization' => 'Bearer sk_catalog_SECRET_TOKEN'],
        ]);
    }

    /**
     * Three concurrent product lookups, each taking a second server-side.
     */
    public function fetchProducts(): int
    {
        $requests = static function (): \Generator {
            foreach (['SKU-1', 'SKU-2', 'SKU-3'] as $sku) {
                yield new Request('GET', "/delay/1?sku={$sku}");
            }
        };

        $ok = 0;

        (new Pool($this->client, $requests(), [
            'concurrency' => 3,
            'fulfilled' => static function ($response) use (&$ok): void {
                $ok += $response->getStatusCode() === 200 ? 1 : 0;
            },
        ]))->promise()->wait();

        return $ok;
    }

    /**
     * Two getAsync() calls settled together — one succeeds, one is a 503.
     */
    public function fetchStockAndPricing(): array
    {
        $results = Utils::settle([
            'stock' => $this->client->getAsync('/json'),
            'pricing' => $this->client->getAsync('/status/503'),
        ])->wait();

        return array_map(
            static fn (array $r): int|string => $r['value']?->getStatusCode() ?? $r['reason']->getMessage(),
            $results,
        );
    }
}
