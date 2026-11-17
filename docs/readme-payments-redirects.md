## Payments (Stripe Checkout)

Optional (`payments`, off by default). `lib/features/payments.php` and `admin/payments.php`. No card data touches the site: every payment happens on a Stripe-hosted Checkout page.

**Setup.** Create a Stripe account, copy the secret key (Developers, API keys) and paste it in Settings, Payments. Keys are stored in `data/config.php` as `stripe_secret_key` and `stripe_webhook_secret` (never echoed; an empty field keeps the saved value). Currency (`payments_currency`, default `usd`) and the thank-you message (`payments_success`) live in `content/settings.json`. Test with an `sk_test_` key and card 4242 4242 4242 4242; go live by pasting the `sk_live_` key and adding a live webhook. Owner emails go through `send_mail_owner()`, so Email sending must be set up for them to arrive; orders are saved either way.

**Products** are items of type `products` in `content/products/<slug>.json` (`products` was added to `ITEM_TYPES`; they are written directly, without revisions). Fields: `name`, `price` (smallest currency unit), `description`, `active`, `quantity`, `ask_note`, `collect_phone`. Each product registers the exact shortcode `[pay <slug>]` at load time.

**Flow.**

- `[pay <slug>]` renders a POST form to `/pay/<slug>/` (hidden return path and language, optional quantity 1–10 and 500-character note, honeypot). `GET /pay/<slug>/` is a standalone page with the same form.
- `POST /pay/<slug>/` creates a Checkout Session with curl against `https://api.stripe.com/v1/checkout/sessions` (form-encoded, `Authorization: Bearer`, an `Idempotency-Key`): `mode=payment`, `line_items[0][price_data]` with the product's currency, amount and name, `customer_creation=always`, `phone_number_collection[enabled]=true` when asked, `locale=es` on Spanish pages, `success_url=<site>/pay/thanks/?s={CHECKOUT_SESSION_ID}`, `cancel_url` back to the page with `?pay=cancelled`, and metadata `product`, `quantity`, `lang`, `note`. It answers 303 to the session URL; on any failure it logs the reason and sends the visitor back with `?pay=error`. Rate limit: 20 attempts an hour per address (`data/pay-rate.json`).
- `/pay/thanks/?s=cs_…` retrieves the session with the secret key and, when `payment_status` is `paid`, records the order and shows the success message.
- `POST /pay/webhook/` verifies `Stripe-Signature` (`v1` = HMAC-SHA256 of `timestamp.payload` with the signing secret, 5-minute tolerance, any of several `v1` values accepted) and records `checkout.session.completed` events whose session is paid. Unsigned or stale requests get 400. Add the endpoint in Stripe (Developers, Webhooks) with that single event.
- Orders are `data/orders/<session id>.json` (product, quantity, amount, currency, customer email, name and phone, note, language, test flag, created, how it was recorded, emailed). The same session is never recorded twice, whichever of the two paths gets there first. The Orders screen lists them newest first with totals by month and a CSV download (cells starting with `= + - @` get a leading space).
- A `daily` cron job is registered but only makes sure `data/orders/` exists; nothing is ever trimmed. Refunds are not handled; use the Stripe dashboard.

**Testing without Stripe.** When `$GLOBALS['payments_http']` is a callable `(string $method, string $url, array $fields, array $headers): array{int $status, string $body}`, `stripe_request()` calls it instead of curl. A file in `site/` can set it during local testing (never deploy one); the kit itself never sets it. `stripe_signature_ok($payload, $header, $secret, $tolerance = 300, ?int $now = null)` can be exercised directly with a generated secret.

## Redirects and the 404 log

Always on. `lib/features/redirects.php` and `admin/redirects.php`.

- `data/redirects.json`: `{"redirects": [{"id", "from", "to", "code"}]}`. A request path is matched against `from` case-insensitively, with or without a trailing slash (the core still 301s slash-less addresses to the slash form first, so those take two hops). `to` must be a path on this site (`/…`, not `//…`) or an `https://` URL; 301 or 302. Matching runs in a registered route and again from `not_found()` through `log_404()`, so addresses the core handles itself (`/blog/old-post/`, `/resources/x/`) are redirected too. The home page and anything under `/admin` cannot be a `from`; loops to the same path are refused.
- `log_404(string $path)` is called by `index.php`'s `not_found()` when the function exists (one guarded call). It counts hits per path per day in `data/404s.json` under a file lock, keeps 90 days and at most 500 distinct paths (the least-hit path is dropped when full), and skips paths with a file extension, bot user agents (`is_bot()`), paths over 150 characters, and common scanner noise (`wp-`, `xmlrpc`, `phpmyadmin`, `.env`, `.git`, `cgi-bin`, `//`, `..` and so on).
- The Redirects screen: add/edit/delete with a confirm step, then the top 50 missing addresses of the last 30 days with hits, last seen and an "Add a redirect" link that prefills the from-path (`?a=redirects&from=/path`). The Dashboard card "N addresses got 404s this week" appears only when N is non-zero.
