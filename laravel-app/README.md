# wiretap — Laravel example

A stock Laravel 12 app with [`ssx/wiretap-laravel`](https://github.com/ssx/wiretap-laravel)
installed. Nothing in the application code mentions wiretap — the package is
auto-discovered and captures Laravel's HTTP client on its own.

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

There is almost none, which is the point.

| File | What it does |
| --- | --- |
| [`config/wiretap.php`](config/wiretap.php) | published from the package; blocklist and redaction paths filled in for this app |
| [`app/Services/OrderApi.php`](app/Services/OrderApi.php) | a stand-in for your integration — no wiretap references |
| [`app/Console/Commands/WiretapDemo.php`](app/Console/Commands/WiretapDemo.php) | drives the three calls and prints the result |

`composer require ssx/wiretap-laravel` and one `.env` line is the whole setup.
The package registers itself through Laravel's auto-discovery.

Two config values are the ones worth setting for any real project — wiretap
cannot know which of your endpoints carry cardholder data, or which keys in
your payloads are sensitive:

```php
'blocklist' => ['*.myacquirer.test'],          // never recorded at all
'redaction' => ['body_paths' => ['card.cvv']], // recorded, but redacted
```

## Commands

The package ships these, against this app's configured log path:

```bash
php artisan wiretap:list --failed
php artisan wiretap:show 1 --curl
php artisan wiretap:trace <correlation-id>
php artisan wiretap:export --out=calls.har
php artisan wiretap:doctor
```

## What this example cannot capture

The package hooks Laravel's HTTP client and the container's Guzzle binding.
Neither reaches a `new GuzzleHttp\Client()` inside your vendor directory, and
neither sees raw `curl_exec()` at all. `php artisan wiretap:doctor` says so
explicitly rather than leaving you to wonder.

For that you need [`ssx/wiretap-auto`](https://github.com/ssx/wiretap-auto),
which hooks the functions themselves via `ext-opentelemetry` and requires no
application changes.

## ⚠️ Turn it off

Wiretap records complete request and response bodies. On a commerce site those
can contain cardholder data. It is not PCI-DSS compliant and it is not GDPR
compliant on its own. `WIRETAP_ENABLED` defaults to off outside `local` for
that reason. See the [main README](https://github.com/ssx/wiretap).
