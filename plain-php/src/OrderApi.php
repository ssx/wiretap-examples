<?php

declare(strict_types=1);

namespace App;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Ssx\Wiretap\Guzzle\Stack;

/**
 * A stand-in for the third-party integration you are actually debugging.
 *
 * Note that nothing in the methods below mentions wiretap. The only wiretap
 * line in this class is the handler passed to the constructor.
 */
final class OrderApi
{
    private Client $client;

    public function __construct(string $baseUri = 'https://httpbin.org')
    {
        $this->client = new Client([
            'base_uri' => $baseUri,
            'handler' => Stack::wrap(Wiretap::recorder()),
            'timeout' => 10,
            'http_errors' => false,
        ]);
    }

    /**
     * A normal request carrying a secret header and a secret query parameter.
     *
     * @return array<string, mixed>
     */
    public function createOrder(string $sku, int $quantity): array
    {
        $response = $this->client->post('/post?api_key=SUPER_SECRET_KEY', [
            'headers' => [
                'Authorization' => 'Bearer tok_live_do_not_log_me',
                'Content-Type' => 'application/json',
            ],
            'json' => [
                'sku' => $sku,
                'quantity' => $quantity,
                'customer' => ['email' => 'alice@example.com', 'name' => 'Alice'],
                'card' => ['number' => '4111111111111111', 'cvv' => '123'],
                // A 16-digit order reference. It is not a card number, and
                // the Luhn check is what keeps it readable in the log.
                'order_ref' => '1234567890123456',
            ],
        ]);

        return ['status' => $response->getStatusCode()];
    }

    /**
     * A failing request, so the log has something interesting in it.
     */
    public function brokenCall(): int
    {
        return $this->client->get('/status/502')->getStatusCode();
    }

    /**
     * A call to a blocked host. The request still happens; nothing is
     * recorded about it.
     */
    public function chargeCard(): string
    {
        try {
            $this->client->post('https://api.stripe.com/v1/charges', [
                'timeout' => 3,
                'json' => ['amount' => 1000, 'source' => 'tok_visa'],
            ]);

            return 'sent';
        } catch (GuzzleException $e) {
            // The network call may well fail without credentials; what
            // matters for the example is that wiretap recorded nothing.
            return 'attempted';
        }
    }
}
