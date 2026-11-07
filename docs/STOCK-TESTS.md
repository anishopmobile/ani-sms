# Stock alert validation — 1.3.0-rc1

Initial isolated WordPress 7.1 / PHP 8.3 / WooCommerce 11.2.2 results: **39 assertions passed**, HTTP count asserted zero. Covered exact product/variation, duplicate request/hooks, encrypted phone, explicit/verified account checks, in-stock/backorder/hidden exclusions, one-shot behavior, reversal/revision, cancellation after enqueue, sibling isolation, settings snapshot, interrupted worker/no retry, privacy, deletion, expiry, module disable/re-enable, mapping rejection, immutable request mode, withdrawn product and 50/51 bounded fanout continuation.

WordPress 6.8 / PHP 8.5 without WooCommerce: **6 dependency-absence assertions and 18 lifecycle/purge assertions passed**. Existing order suite 36 and campaign suite 42 also passed after queue integration. WPCS/PHPStan and final package/UI checks are repeated for the final candidate and recorded below.

The destructive fixtures require `ANI_SMS_TEST_WP`, `ANI_SMS_TEST_ENV=true`, local environment and a database name ending `_test`. Never run them on the owner's database. Outbound mail/HTTP are blocked in fixtures. Owner-site walkthroughs use marked local products and a separate OTP-created subscriber, never import/reset live store data or opt in the owner. No real SMS.ir delivery is claimed.

Expanded suite: 43 assertions passed including stale policy rejection and parent-managed variation stock/child continuation. Seven live-boundary assertions passed with HTTP mocked, checking local/unknown proof rejection, live mode, HTTPS and exact transactional template payload. Physical ZIP installation passed 6 checks; current candidate contains 58 allowlisted files.

Initial owner-site UI run: 29 assertions passed, covering local OTP, explicit/deduplicated requests, real WooCommerce editor quantity change, queued cancellation, exact sibling isolation, simulation, reversal, previews, mobile layout and nonce/guest guards. Added a two-client reversal/reconciliation race test: 4 assertions passed. Completion now uses latest-job matching under the product lock; reconciliation is bounded to 100 candidates/two seconds. Final public state labels are localized; the repeat UI run checks semantic data-state attributes.


## Final validation

Final owner-site repeat: **28/28 UI assertions passed**, zero JavaScript errors, on WordPress 7.1/PHP 8.3/WooCommerce 11.2.2 HPOS. The initial run's extra assertion verified the module's default-off state. The repeat verified localized public statuses after the product-lock correction. Screenshots of account forms and desktop/mobile admin previews were visually reviewed. Product #17 stock was changed through the real WooCommerce editor; exact-variation and rapid-reversal changes used a private marked-fixture-only WooCommerce CRUD helper. There were no clock overrides or owner-database reset fixtures.

Final PHP 8.5 package checks: installation 6, stock 43, two-client concurrency 4, live-boundary/transactional payload 7, WooCommerce order 36, marketing 42 assertions passed. OTP integration 61 and local OTP 30 also passed during this candidate. Minimum WordPress 6.8/PHP 8.5 without WooCommerce: dependency absence 6 and lifecycle/purge 18 passed. Pure 23, WPCS Core and PHPStan level 5 passed. These are named-suite results, not a claim that every platform permutation or real provider delivery was tested.

Final ZIP: **58 allowlisted files**, SHA256 `5ca810db0ed3566e385709d9f83066c85f0877c3d2bfee47b078cf182c0ca2c9`. No private configuration, API key, SQL dump, vendor/reference plugin, test harness or session/token file is packaged. No real SMS was sent. The temporary HTTP/automatic-cron guard and temporary administrator tokens are removed after verification. A private mail guard covers only marked test products. Main remains untouched.

Owner fixtures: products #17/#18, variable parent #19 and variations #20/#21; shortcode test page #22. After validation the marked product quantities are reset to zero for the owner's next manual test; completed/cancelled requests remain terminal. Original products, orders and the owner's administrator session/consent are preserved.
