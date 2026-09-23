<?php

declare(strict_types=1);

namespace App;

/**
 * Stands in for an older vendor SDK that drives curl_multi by hand.
 *
 * Plenty of payment, shipping and ERP SDKs still look like this. Middleware
 * cannot see it at all, because there is no HTTP client object to attach
 * middleware to.
 */
final class ShippingSdk
{
    /**
     * @return array<string, int> carrier => HTTP status
     */
    public function quoteAll(): array
    {
        $carriers = [
            'royal-mail' => 'https://httpbin.org/anything/quote?carrier=royal-mail',
            'dpd' => 'https://httpbin.org/anything/quote?carrier=dpd',
            // A payment host inside the same batch. It is on wiretap's
            // default blocklist, so the call happens but nothing about it
            // is recorded.
            'stripe' => 'https://api.stripe.com/v1/charges',
        ];

        $multi = curl_multi_init();
        $handles = [];

        foreach ($carriers as $name => $url) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => json_encode(['postcode' => 'SW1A 1AA', 'weight_g' => 1200]),
                CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'X-Api-Key: ship_live_SECRET'],
                CURLOPT_TIMEOUT => 10,
            ]);
            curl_multi_add_handle($multi, $ch);
            $handles[$name] = $ch;
        }

        do {
            $status = curl_multi_exec($multi, $running);
            if ($running) {
                curl_multi_select($multi);
            }
        } while ($running && $status === CURLM_OK);

        $codes = [];

        foreach ($handles as $name => $ch) {
            $codes[$name] = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            curl_multi_remove_handle($multi, $ch);
            curl_close($ch);
        }

        curl_multi_close($multi);

        return $codes;
    }
}
