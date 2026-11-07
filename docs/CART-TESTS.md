# Cart reminder acceptance matrix

Status: **NOT RUN**. No cart implementation, migration, local deployment or SMS send has been completed in this checkpoint. Existing 1.4.0-rc1 test results do not apply to this module.

## Required local no-API cases

| ID | Setup/action | Required assertion |
|---|---|---|
| CART-01 | Guest, OTP-only account, pending import, no cart opt-in | No tracking or job; explicit confirmations remain unchecked |
| CART-02 | Verified owner + current marketing consent + explicit cart opt-in | Nonempty supported cart starts one durable episode |
| CART-03 | Before/after actual inactivity deadline | No early job; one eligible job after deadline |
| CART-04 | Return to cart before dispatch | Old job cancelled; no simulated send; next due time moves forward |
| CART-05 | Change quantity, add/remove item, empty/refill | Stale revision cannot send; empty episode cancelled; cooldown retained |
| CART-06 | Classic checkout creates pending/paid order | Episode cancelled even before payment; payment/thank-you hooks do not duplicate |
| CART-07 | Store API/block checkout, HPOS and classic order storage | Same cancellation; no dependency on order post/meta tables |
| CART-08 | Order callback missed, then worker reconciliation | Customer order after episode creation prevents send |
| CART-09 | Cancel/refund a submitted order | Old reminder does not revive |
| CART-10 | Global withdrawal after enqueue; cart-specific opt-out | Pending work cancelled; changing modules or re-consenting does not revive it |
| CART-11 | Cron/manual processing/page reload repeated | At most one job/outcome for the same generation |
| CART-12 | Two independent MySQL/PHP clients dispatch same episode | At most one attempt; no deadlock-induced duplicate send |
| CART-13 | Concurrent return/withdrawal/order versus dispatch | Current revision/locks prevent stale queued sends; document in-flight boundary |
| CART-14 | Simulated/accepted/failed/unknown outcome; worker crash/lease | Durable seven-day attempt cooldown; no automatic replay |
| CART-15 | Cart edit, session replacement, empty/refill during cooldown | Cannot bypass per-account attempt limit |
| CART-16 | Changed phone owner, local/live flags or consent revision | Old account consent and local proof cannot authorize sending |
| CART-17 | Expiry, deleted/hidden/unavailable item, unavailable persistent cart | Suppress stale reminder and clear pending sensitive data |
| CART-18 | More eligible records than one scan batch | Bounded continuation makes progress without starvation/duplicates |
| CART-19 | WooCommerce absent or feature off | Settings retained; no sends; opt-out and erasure still operate |
| CART-20 | Local processing with HTTP counter | simulated only; zero SMS.ir HTTP; preview resolves count/cart/opt-out |
| CART-21 | Mocked live bulk boundary | Correct permitted-line payload, required unsubscribe and normalized errors |
| CART-22 | Admin/public CSRF, unauthorized account, open redirect, XSS | Denied or escaped; no keys, phones or bearer tokens in public logs |
| CART-23 | Persian desktop/mobile/keyboard UI | Usable opt-in/withdrawal, retained invalid inputs, no page overflow |
| CART-24 | Privacy, account deletion, cleanup, deactivate/uninstall | No stale work; default data preservation; explicit purge only plugin data |
| CART-25 | Native cart link GET in logged-out/wrong account browser | No implicit login or cart mutation; no personal data in link |
| CART-26 | Final package and regressions | Install ZIP; lint/WPCS/PHPStan; OTP/order/marketing/stock/discount suites pass |

Use disposable explicitly guarded test databases for integration/lifecycle fixtures. Owner-site UI tests may mutate only marked cart fixtures, use separate test subscribers, block external requests and revoke temporary administrator sessions. Do not accelerate the owner's timestamps or reset the owner's database. Pure timing tests may inject a clock only in an isolated test seam and must be labelled accordingly; include one real-time boundary check.

## Current environment blocker

The local process runner failed even for a minimal echo command:
`MXC launcher: enumerate MXC volume D:\\: The parameter is incorrect. (os error 87)`.
Alternative PowerShell execution stalled and the Node kernel initialization timed out. Therefore local WordPress, PHP tools, browser checks and database tests have not been verified for this module. Restore the desktop runner, inspect the checkout and pending processes, then implement and execute this matrix.
