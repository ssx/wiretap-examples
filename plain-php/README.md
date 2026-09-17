# wiretap — plain PHP example

No framework, no container, no magic. Roughly sixty lines of wiring.

```bash
composer install
php run.php
```

## What it does

Makes three outbound calls and prints what wiretap recorded:

1. **`createOrder()`** — a normal call carrying a bearer token, a secret query
   parameter, a card number, a CVV and a customer email.
2. **`brokenCall()`** — a 502, which the sampler keeps regardless of rate.
3. **`chargeCard()`** — a call to `api.stripe.com`, which is on the blocklist.
   The request still happens. Nothing about it is recorded.

## What to look for in the output

| In the request | In the log |
| --- | --- |
| `?api_key=SUPER_SECRET_KEY` | `[REDACTED]` |
| `Authorization: Bearer tok_live_...` | `[REDACTED]` |
| `card.number: 4111111111111111` | `[REDACTED]` |
| `card.cvv: 123` | `[REDACTED]` |
| `customer.email` | `[REDACTED]` |
| `order_ref: 1234567890123456` | **unchanged** |
| the whole Stripe call | **absent** |

That last pair is the point of the Luhn check. `order_ref` is sixteen digits,
exactly like a card number, and a naive `\d{13,19}` rule would redact it —
making the log useless for the thing you were actually debugging.

## The wiring

Two files:

- [`src/Wiretap.php`](src/Wiretap.php) — builds the recorder: blocklist,
  redaction rules, sink, sampler.
- [`src/OrderApi.php`](src/OrderApi.php) — a stand-in for your integration.
  The only wiretap line is `'handler' => Stack::wrap(Wiretap::recorder())`.

Everything else is ordinary Guzzle.
