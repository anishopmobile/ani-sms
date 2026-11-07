# Stock alert validation — 1.3.0-rc1

Initial isolated WordPress 7.1 / PHP 8.3 / WooCommerce 11.2.2 results: **39 assertions passed**, HTTP count asserted zero. Covered exact product/variation, duplicate request/hooks, encrypted phone, explicit/verified account checks, in-stock/backorder/hidden exclusions, one-shot behavior, reversal/revision, cancellation after enqueue, sibling isolation, settings snapshot, interrupted worker/no retry, privacy, deletion, expiry, module disable/re-enable, mapping rejection, immutable request mode, withdrawn product and 50/51 bounded fanout continuation.

WordPress 6.8 / PHP 8.5 without WooCommerce: **6 dependency-absence assertions and 18 lifecycle/purge assertions passed**. Existing order suite 36 and campaign suite 42 also passed after queue integration. WPCS/PHPStan and final package/UI checks are repeated for the final candidate and recorded below.

The destructive fixtures require `ANI_SMS_TEST_WP`, `ANI_SMS_TEST_ENV=true`, local environment and a database name ending `_test`. Never run them on the owner's database. Outbound mail/HTTP are blocked in fixtures. Owner-site walkthroughs use marked local products and a separate OTP-created subscriber, never import/reset live store data or opt in the owner. No real SMS.ir delivery is claimed.

Expanded suite: 43 assertions passed including stale policy rejection and parent-managed variation stock/child continuation. Seven live-boundary assertions passed with HTTP mocked, checking local/unknown proof rejection, live mode, HTTPS and exact transactional template payload. Physical ZIP installation passed 6 checks; current candidate contains 58 allowlisted files.
