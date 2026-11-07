# Cart reminder validation - 1.5.0-rc1

All SMS tests are simulated or HTTP-intercepted. No real SMS.ir API account or SMS delivery was used.

## Executed evidence

- WordPress 7.1, WooCommerce 11.2.2 HPOS, PHP 8.3.30: cart acceptance **36 checks**, including return-before-send, inactivity reset, pending/completed order cancellation, opt-out after enqueue, per-episode uniqueness, durable seven-day cooldown, empty/refill, identity ownership, expired opt-in, hidden/zero-quantity items, interrupted worker reconciliation, privacy and module disable.
- Actual owner localhost browser: **25 checks**, using a separate OTP subscriber, explicit marketing and cart opt-ins, native add-to-cart and persistent cart, real page return, a completed WooCommerce CRUD fixture order, local admin settings, invalid-input preservation, nonce denials, mobile overflow and zero JavaScript errors.
- Actual two PHP/MySQL clients: **8 checks**, 21 test accounts, bounded 20-record continuation, one job/outcome per episode, and concurrent return versus pending dispatch. The return's fresh revision survives an old job's cancellation.
- Mocked live bulk boundary: **8 checks**. Local proof/live consent/HTTPS gates, exact text/bulk payload with permitted line and mandatory unsubscribe, acceptance-only result and fallback customer-order reconciliation when a callback is missed.
- WordPress 6.8 without WooCommerce: dependency/disable **6 checks**. Final physical ZIP extraction/activation/class origin/version **6 checks**.
- PHP 8.5.12: final cart acceptance **36 checks** passed.
- Regressions: discount 44, stock 43, marketing 42, orders/HPOS 36, OTP integration 61, local OTP 30, pure 23 and lifecycle/purge 22 checks passed in disposable guarded databases.
- PHPStan level 5 and WordPress Coding Standards pass. All **264** translatable messages have Persian entries.
- Release ZIP: **72 allowlisted files**. No private configuration, API key, SQL dump, session cookies, tests, dependency/vendor tree or reference plugin is packaged.

## Timing and test scope

Inactivity, seven-day cooldown and opt-in expiry are exercised with a guarded injected clock in isolated integration tests; this seam rejects injection outside ANI_SMS_TEST_ENV. The owner's timestamps were never advanced or rewritten. Browser returns use the real local clock. A full real-time 15/60-minute abandonment wait and seven-day wait were not performed, and are not implied by the counts above.

The owner browser order was created/completed with a guarded private WooCommerce CRUD helper for the marked test subscriber/product; it was not a payment-gateway transaction. Classic and Store API hooks plus CRUD/HPOS reconciliation are implemented; no separate block-checkout/payment-provider browser certification is claimed.

Live requests are intercepted and accepted does not mean delivered. Real account permissions, charges and delivery remain untested. The cart destination is the native cart URL with normal authentication, not a bearer cart-recovery or implicit-login token.

## Fixtures and repeatability

Source harnesses: cart.php, cart-concurrency.php, cart-live-boundary.php, cart-absent.php and cart-browser.cjs under tests/ani-sms. Integration fixtures require explicitly guarded disposable WordPress databases. The test clock is never exposed in HTTP parameters or production settings.

Owner UI touched only marked local product #29, demo page #30 and a separate test subscriber/order. Existing owner login sessions, consent, products and orders were preserved. Its final test subscriber withdrew global consent, cancelling cart intent. Temporary administrator sessions and the background HTTP/cron guard are removed after verification. Private screenshots may contain tokens and are not committed.

The earlier acceptance matrix was a plan, not prior evidence. Counts here are named-suite outcomes, not every possible WooCommerce/theme/browser combination or real provider certification.
