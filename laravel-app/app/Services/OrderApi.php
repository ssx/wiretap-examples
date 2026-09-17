<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;

/**
 * A stand-in for the third-party integration you are debugging.
 *
 * Nothing here mentions wiretap. It uses Laravel's HTTP client exactly as any
 * other service would — the recording happens because the service provider
 * set a global handler.
 */
final class OrderApi
{
    private const BASE = 'https://httpbin.org';

    /**
     * A normal call carrying a bearer token, a secret query parameter, a card
     * number, a CVV and a customer email.
     */
    public function createOrder(string $sku, int $quantity): int
    {
        return Http::withHeaders([
            'Authorization' => 'Bearer tok_live_do_not_log_me',
        ])->post(self::BASE . '/post?api_key=SUPER_SECRET_KEY', [
            'sku' => $sku,
            'quantity' => $quantity,
            'customer' => ['email' => 'alice@example.com', 'name' => 'Alice'],
            'card' => ['number' => '4111111111111111', 'cvv' => '123'],
            // Sixteen digits, exactly like a card number, but it fails the
            // Luhn check — so it stays readable in the log.
            'order_ref' => '1234567890123456',
        ])->status();
    }

    /**
     * A failure, which the sampler keeps regardless of rate.
     */
    public function brokenCall(): int
    {
        return Http::get(self::BASE . '/status/502')->status();
    }

    /**
     * A blocked host. The request still happens; nothing is recorded.
     */
    public function chargeCard(): string
    {
        try {
            Http::timeout(3)->post('https://api.stripe.com/v1/charges', [
                'amount' => 1000,
                'source' => 'tok_visa',
            ]);
        } catch (\Throwable) {
            // Expected without credentials. What matters is that wiretap
            // recorded nothing about it.
        }

        return 'attempted';
    }
}
