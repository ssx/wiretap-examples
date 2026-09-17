# wiretap — Laravel example

A stock Laravel 12 app with wiretap wired in. Nothing in the application code
mentions wiretap; the recording happens because a service provider sets a
global Guzzle handler.

```bash
composer install
cp .env.example .env
php artisan key:generate
echo "WIRETAP_ENABLED=true" >> .env

php artisan wiretap:demo
```

Or exercise the same calls over HTTP:

```bash
php artisan serve
open http://127.0.0.1:8000/wiretap-demo
```

## What it does

Three outbound calls:

1. **`createOrder()`** — carries a bearer token, a secret query parameter, a
   card number, a CVV and a customer email.
2. **`brokenCall()`** — a 502, which the sampler keeps regardless of rate.
3. **`chargeCard()`** — a call to `api.stripe.com`, which is blocked. The
   request still happens; nothing about it is recorded.

## What to look for

| In the request | In the log |
| --- | --- |
| `?api_key=SUPER_SECRET_KEY` | `[REDACTED]` |
| `Authorization: Bearer tok_live_...` | `[REDACTED]` |
| `card.number`, `card.cvv` | `[REDACTED]` |
| `customer.email` | `[REDACTED]` |
| `order_ref: 1234567890123456` | **unchanged** |
| the whole Stripe call | **absent** |

`order_ref` is sixteen digits, exactly like a card number. A naive
`\d{13,19}` rule would redact it and make the log useless for whatever you
were actually debugging. The Luhn check is what keeps it readable.

## The wiring

| File | What it does |
| --- | --- |
| [`config/wiretap.php`](config/wiretap.php) | blocklist, redaction paths, sampling, retention |
| [`app/Providers/WiretapServiceProvider.php`](app/Providers/WiretapServiceProvider.php) | builds the recorder, sets the global handler, adds route/command context |
| [`app/Services/OrderApi.php`](app/Services/OrderApi.php) | a stand-in for your integration — no wiretap references |

The provider is what `ssx/wiretap-laravel` will eventually do for you. It
lives here in full so you can read it rather than take it on trust.

## What this example cannot capture

`Http::globalOptions()` reaches Laravel's HTTP client, and the container
binding reaches anything resolving Guzzle from the container. Neither reaches
a `new GuzzleHttp\Client()` inside your vendor directory, and neither sees raw
`curl_exec()` at all.

For that you need [`ssx/wiretap-auto`](https://github.com/ssx/wiretap-auto),
which hooks the functions themselves via `ext-opentelemetry` and requires no
application changes.

## ⚠️ Turn it off

Wiretap records complete request and response bodies. On a commerce site those
can contain cardholder data. It is not PCI-DSS compliant and it is not GDPR
compliant on its own. `WIRETAP_ENABLED` defaults to off outside `local` for
that reason. See the [main README](https://github.com/ssx/wiretap).
