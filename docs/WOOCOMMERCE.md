# WooCommerce order notifications — 1.1.0-rc1

This optional phase was explicitly authorized after the 1.0 rc3 baseline. Core authentication still works without WooCommerce. The module and both recipient groups default off. Requires WordPress 6.8+, PHP 8.3+, WooCommerce (tested 11.2.2) and the existing ANI-SMS server secret.

## Configure and test locally

1. Keep the private server configuration: `WP_ENVIRONMENT_TYPE=local`, `ANI_SMS_LOCAL_TEST=true`, loopback site hostname and loopback peer. The existing secret stays outside Git. No API key is needed.
2. Activate WooCommerce, then ANI-SMS → Modules → Enable WooCommerce order notifications. Preserve the authentication checkbox if needed.
3. Open ANI-SMS → Order notifications. Enable customer and/or administrator recipients separately. For administrator notifications enter up to five Iranian mobiles. Choose order creation and destination statuses.
4. Configure four independent routes: creation/customer, creation/admin, status/customer, status/admin. Template ID **0 is allowed only for local simulation**. Each mapping line is `ExactPanelParameter=variable`; allowlisted variables are `order_number`, `status`, `customer_name`, `total`, `site_name`. These are display values, stripped of HTML/control characters and bounded to 200 characters. A blank customer name becomes `-`. No PHP/eval, arbitrary placeholders or marketing content.
5. Create a test order with a valid Iranian billing mobile; change its status. Wait at least five seconds, then click **Process eligible jobs**. The latest 20 jobs show masked recipients and a local-only parameter preview. `simulated` explicitly means **no SMS was sent**.
6. For live use, first approve separate transactional templates in SMS.ir, replace every enabled route's template ID and exact parameter names, configure the API key privately, disable local mode and enable the server live switch. Existing local jobs can never become live sends. Create a fresh test order after changing settings. Accepted provider results do not prove delivery.

## Events, deduplication and cancellation

- Uses WooCommerce CRUD plus `woocommerce_new_order`, before/after order-save and status-change hooks. No order queries against posts/postmeta; HPOS compatibility is declared. Drafts/refunds are excluded. Existing orders are baselined; enabling the module does not replay historical orders.
- A durable per-order generation and monotonically increasing sequence identify actual persisted transitions. Duplicate hooks at the same status do nothing. A→B→A→B creates independent events, including after queue cleanup. Each event/route/normalized recipient gets a unique HMAC job key; template choice is frozen by its configuration fingerprint.
- The first immediate status transition within 30 seconds merges into a still-queued creation job for that recipient. The creation template wins and its status/parameters become current. An already dispatched creation job is not merged. Later transitions create new events. Superseded unsent status snapshots are cancelled, even if an order later cycles back to the same status.
- Before dispatch, recheck the module, dependency, generation, sequence, current order status, route configuration and customer billing phone. A mismatch cancels the old snapshot; it never silently redirects a queued message. Phone-only edits do not create a status event. Billing phones never create or change an authentication identity.
- Disabling the module cancels its queued jobs and rotates its generation. Deactivation cancels queued work; WooCommerce absence pauses work while preserving settings. Dependency availability changes rotate the generation so old snapshots cannot resume as fresh events.

## Queue and operations

Two InnoDB tables extend schema version 2: generic encrypted `jobs` and durable `event_states`. Capture uses a per-order MySQL lock and transaction. Worker uses a shared lock, conditional claims and 60-second leases; each invocation handles at most 10 jobs / 20 seconds, with a 10-second HTTP timeout per request. A worker interrupted after claiming a job produces terminal `unknown`; no automatic POST retry can duplicate an ambiguous acceptance. Failed/unknown jobs require investigation and a new legitimate event; there is no blind resend button.

WP-Cron supplies a recovery event hourly plus five-second/one-minute single events while jobs are pending. Scheduling is best effort; use a system cron every minute for production. CLI cron lacks a loopback peer by default and deliberately pauses local simulations; use the authenticated local admin action for previews. Server flag, environment, hostname and peer checks cannot be bypassed with forwarded headers.

Queued encrypted snapshots and local previews expire after 24 hours and are removed by bounded hourly cleanup. Live terminal snapshots are cleared immediately; simulated snapshots remain only for local review until expiry. Durable event state contains order IDs/statuses, no phone/name/body. Deleting an order removes its event state and jobs. Privacy export/erasure works by keyed billing-email hash even without WooCommerce; deleting a user removes their jobs. Aggregate masked logs retain 30 days. Existing provider calls already in flight cannot be recalled. Key rotation makes old encrypted snapshots unreadable; they become unknown and still expire. Backups need separate retention.

## Source references

- [WooCommerce HPOS compatibility recipe book](https://developer.woocommerce.com/docs/features/high-performance-order-storage/recipe-book/)
- [WooCommerce order class](https://woocommerce.github.io/code-reference/classes/WC-Order.html)
- [Official SMS.ir PHP SDK](https://github.com/IPeCompany/smsir-php) — verification endpoint and parameter contract. Production continues to use WordPress HTTP API, without bundling the SDK.

See `WOOCOMMERCE-TESTS.md` for observed validation and limitations. This is a review candidate; real provider delivery remains untested until the owner obtains an API key and approved templates.
