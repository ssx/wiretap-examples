# wiretap — async capture with no application changes

Two stand-in "vendor" SDKs make eight concurrent outbound calls. Neither has
any wiretap code, and neither does the application. Installing
[`ssx/wiretap-auto`](https://github.com/ssx/wiretap-auto) is the whole
integration.

```bash
pecl install opentelemetry   # once per PHP install
composer install
php run.php
```

Requires PHP 8.2+ with `ext-opentelemetry` loaded (`php -m | grep
opentelemetry`). Without it the package is inert and `run.php` prints the
diagnostics that say why.

## What it does

1. **`CatalogSdk::fetchProducts()`** — a Guzzle `Pool` of three requests to
   `httpbin.org/delay/1`, three at a time.
2. **`CatalogSdk::fetchStockAndPricing()`** — two `getAsync()` calls settled
   together; one returns a 503.
3. **`ShippingSdk::quoteAll()`** — a hand-written `curl_multi_*` loop of the
   kind older payment and shipping SDKs still use. One of its three requests
   goes to `api.stripe.com`, which is on the default blocklist.

None of this touches `curl_exec()`. Guzzle sends every async request, and every
`Pool`, through `curl_multi_*`, and so does the shipping SDK. Middleware cannot
see the shipping SDK at all: there is no client object to attach it to.

## What to look for in the output

| | In the log |
| --- | --- |
| Three `Pool` requests of one second each | three records, batch done in ~1s |
| The 503 | recorded with `status: 503` |
| `Authorization: Bearer sk_catalog_...` | `[REDACTED]` |
| `X-Api-Key: ship_live_SECRET` | `[REDACTED]` |
| Raw `curl_multi` responses | body captured |
| Guzzle responses | `omitted_reason: streaming` |
| The Stripe call | **absent** |

The batch timing matters as much as the records. Instrumentation that made
three concurrent requests take three seconds would have changed what the
application does, which wiretap treats as a worse failure than recording
nothing.

Guzzle's response bodies are absent because Guzzle streams them through
`CURLOPT_WRITEFUNCTION`, so curl never returns a string for the hook to read.
If you own the client, [`ssx/wiretap-guzzle`](https://github.com/ssx/wiretap-guzzle)
records those bodies too, and the two packages do not double-record.

## Configuration

`run.php` fills in `WIRETAP_ENABLED=true` and `WIRETAP_PATH=var/` if they are
not already set, so the demo works with no setup. In a real deployment set them
in the environment — capture is off unless `WIRETAP_ENABLED` is truthy. See the
[wiretap-auto README](https://github.com/ssx/wiretap-auto#nothing-is-captured-until-you-say-so)
for the rest.
