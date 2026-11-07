# Product discount alerts — 1.4.0-rc1

This optional module defaults off and requires WooCommerce and the marketing consent module. It never infers consent from OTP, purchase or import. A signed-in account must own a verified phone, give current explicit marketing consent, and separately request one alert for an exact published simple product or variation. Requests expire in 30 days; already-discounted products cannot be subscribed. Hidden/password/private products are excluded. The request reserves neither price nor stock.

## Trigger and dispatch

Native WooCommerce sale prices must be finite, below a positive regular price, in their active date window, equal to the effective catalog price, in stock, purchasable and not backordered. Coupons, personalized taxes and dynamic pricing are outside this contract. Parent minimum prices never substitute for an exact variation. Product CRUD, stock transitions, scheduled-sale hooks, date-boundary events and bounded rotating recovery capture changes. Fanout and child scans use batches of 50; recovery considers 20 products per run. A working WP-Cron or system cron is required; notification is eventual, not an exact-time guarantee.

Durable encrypted jobs bind the request, subscriber consent revision, phone identity, settings revision and sale snapshot. Every dispatch rechecks eligibility. A changed or withdrawn unsent sale invalidates stale work and rearms a still-valid request; accepted/unknown/simulated notifications are one-shot. Consent withdrawal, account/phone ownership changes, expiry, deletion or module disable cancel pending work. Global withdrawal remains effective with the discount module or WooCommerce off. Subscriber locks precede product locks; conditional row transitions precede queue insertion inside a transaction, and 51-recipient price-change continuation is tested.

## Transport and interface

Live messages use SMS.ir text/bulk through a permitted marketing line, never OTP verification templates. The server's live switch, private key configuration, public HTTPS, live OTP phone proof and live consent are required. Unsubscribe URLs are opaque, random and mandatory. Local mode needs no API or line, performs no SMS HTTP, and records `simulated`; local requests and consent cannot become live work.

Settings: sending line, plain-text message and optional same-site subscription page URL. Variables: `product_name`, `product_url`, `site_name`, `regular_price`, `sale_price`, `discount_percent`, `unsubscribe_url`; missing unsubscribe is appended. Unknown variables and HTML are rejected. Expansion is one-pass, escaped at display, and bounded. Each recipient gets its own unsubscribe link.

Classic product summaries render automatically. Blocks use `[ani_sms_discount product_id="123"]`; `[ani_sms_discount]` lists account requests. Users can cancel waiting/queued requests. Administrators have paginated diagnostics and a protected local message preview. Nonces, same-origin checks, capability checks and rate limits protect mutations. Persian translations cover all new strings.

## Storage and privacy

Schema 6 adds independent discount requests with encrypted phones, hashed uniqueness, revision and offer keys. Queue snapshots expire within 24 hours. Terminal phone data is cleared and request metadata retained for 30 days. Privacy export/erasure and account/product deletion cancel or remove relevant work. Deactivation unschedules every discount event while preserving settings; uninstall preserves data by default and purges only on explicit preconfiguration. Existing modules and owner data remain independent.
