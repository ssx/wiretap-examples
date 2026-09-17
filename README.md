# wiretap examples

Two working applications you can clone and run, showing
[wiretap](https://github.com/ssx/wiretap) capturing real outbound HTTP calls.

| Example | Framework | Run it |
| --- | --- | --- |
| [`plain-php/`](plain-php) | none | `composer install && php run.php` |
| [`laravel-app/`](laravel-app) | Laravel 12 | `composer install && php artisan wiretap:demo` |

Both make the same three outbound calls against `httpbin.org`:

1. A normal call carrying a bearer token, a secret query parameter, a card
   number, a CVV and a customer email.
2. A 502, which the sampler keeps regardless of sample rate.
3. A call to `api.stripe.com`, which is on the blocklist. The request still
   happens. Nothing about it is recorded.

## What both examples demonstrate

| In the request | In the log |
| --- | --- |
| `?api_key=SUPER_SECRET_KEY` | `[REDACTED]` |
| `Authorization: Bearer tok_live_...` | `[REDACTED]` |
| `card.number`, `card.cvv` | `[REDACTED]` |
| `customer.email` | `[REDACTED]` |
| `order_ref: 1234567890123456` | **unchanged** |
| the entire Stripe call | **absent** |

That last pair is the point. `order_ref` is sixteen digits, exactly like a
card number, and a naive `\d{13,19}` rule would redact it — making the log
useless for whatever you were actually trying to debug. Every PAN candidate is
Luhn-checked, so the real card number goes and the order reference stays.

The Stripe call is different in kind. It is not redacted, it is never observed:
the blocklist runs before any body is read, so the payload never exists in
process memory. That distinction is what makes the blocklist, rather than
redaction, the right control for cardholder data.

## These examples use the middleware, not the hooks

Both wire up `ssx/wiretap-guzzle`, which captures Guzzle clients the
application constructs. That covers the example code, but it cannot see:

- a `new GuzzleHttp\Client()` created inside your vendor directory
- raw `curl_exec()` anywhere at all

Capturing those needs
[`ssx/wiretap-auto`](https://github.com/ssx/wiretap-auto), which hooks the
functions themselves via `ext-opentelemetry` and requires no application
changes.

## ⚠️ These are debugging demos

Wiretap records complete request and response bodies, which routinely contain
personal data and on a commerce site can contain cardholder data. It is not
PCI-DSS compliant and it is not GDPR compliant on its own. Both examples
default to disabled outside local environments. See the
[main README](https://github.com/ssx/wiretap) before running it anywhere real.
