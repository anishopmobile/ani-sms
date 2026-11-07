# Back-in-stock alerts — 1.3.0-rc1

Explicitly authorized extension of spec section 10. Optional module `stock`, default off, requires WooCommerce; core login and other modules remain independent. Uses WooCommerce product CRUD and stock/product-save hooks, never direct product/order posts/meta queries.

## Request and event contract

- A signed-in user with an ANI-SMS verified phone selects one published public **simple product or exact variation** that is `outofstock`, checks a separate explicit checkbox and submits an account nonce. No phone, user ID or role is accepted from the client. Policy/source/version (`stock-v1`/account form), UTC timestamps, product/user, immutable mode and encrypted phone snapshot are recorded. No marketing consent is created or consulted; this is one requested transactional availability alert.
- Variable parents, backorders, hidden/unpublished/password-protected products and unsupported product types cannot be subscribed. Product/variation identity is never inferred from a parent or sibling. The native form lists up to 100 exact out-of-stock variations in an explicitly labelled separate selector; it does not silently follow another variation swatch. An exact variation shortcode supports larger catalogs.
- One active request per product/user/mode, valid for 30 days. Repeated submissions are idempotent. A later explicit request during a new outage can reuse the record with a fresh revision. Accepted/simulated/failed/unknown results never re-arm automatically.
- Trigger: the exact target becomes public, `instock`, purchasable, and not on backorder. Duplicate WooCommerce hooks cannot create duplicate jobs. A reversal before send cancels the old job and re-arms the unsent request with a fresh revision. Hidden/deleted products cancel work. A stock alert does not reserve units or guarantee availability at purchase time.
- Each invocation fans out at most 50 requests. Continuation hooks process the remainder; parent stock changes schedule child scans in batches of 50. Hourly recovery rotates over 20 requested products so missed hooks/disabled WooCommerce do not permanently strand later products. Requests still expire independently of cron.

## Dispatch and cancellation

The shared queue claims jobs with leases and no blind retries for ambiguous POST outcomes. Jobs are keyed by request/revision. Immediately before dispatch, check module/dependency, exact product availability, request state/revision/policy/mode/expiry, current phone mapping, live phone-proof mode, template settings and rendered parameters. Changed settings/product metadata/identity cancel the old snapshot. Product-specific named locks serialize request, stock capture, dispatch and cancellation. Already in-flight sends cannot be recalled.

User cancellation after enqueue clears the pending job and phone snapshot. Module disable/deactivation cancels pending requests irreversibly; re-enable does not revive them. WooCommerce absence preserves settings/requests and pauses jobs until dependency recovery or expiry. Privacy export/erasure and user deletion remain registered without the stock module/dependency. Deleting a product removes its request/job records through WooCommerce hooks; periodic validation catches missing/nonpublic products.

## UI and configuration

Enable **Back-in-stock alerts** under Modules, preserving the other module checkboxes. Configure a separate approved transactional SMS.ir template and explicit mappings under Back-in-stock alerts. Allowed variables: `product_name`, `product_url`, `site_name`. Unknown/duplicate names are rejected. Provider parameter values are bounded to 200 characters. Overlong product permalinks use a core short product URL, preserving variation attributes where they fit; if attributes alone exceed the limit, the link opens the parent product and the message name still identifies the exact requested variation. URLs are never truncated into broken links.

The classic WooCommerce product summary receives the form automatically. Block product templates can use a shortcode block:

```
[ani_sms_stock product_id="123"]
[ani_sms_stock]
```

The second form lists the current account's requests with pagination and cancel actions. Public forms and admin previews are responsive, Persian/English and capability/nonce protected. Page/CDN caches must exclude signed-in account/product request forms and admin-post responses. Native swatch, third-party product-editor and stock-integration adapters require separate compatibility tests.

## No-API local testing

Existing private flags: local environment, loopback hostname/peer, `ANI_SMS_LOCAL_TEST=true`; the loopback HTTP exception may remain enabled. Template 0 is allowed, no API key needed. Sign in via local OTP in a private window, request an out-of-stock test product, change its quantity/status through WooCommerce, wait five seconds and click **Process eligible jobs**. Result is `simulated`, with a local-only parameter preview. The request phone snapshot is cleared after its terminal outcome.

Local requests and proofs never become live messages. Live requests require a live-verified phone and HTTPS; a live template/key/server switch is still required for sending. Real template permission and delivery have not been verified because no API key is available.

## Data and operations

Schema 5 adds `stock_requests` with unique product/user/mode, policy/source, encrypted phone, revision, latest job key and indexed expiry. Shared jobs hold request revision/settings/parameters, not raw phone. Jobs expire within 24 hours or the earlier request deadline; request metadata expires after 30 days, terminal phone snapshots are cleared immediately, masked logs retain 30 days. Bounded cleanup depends on working cron; use system cron every minute for timely production dispatch. Local simulation should use a loopback admin process action. Uninstall preserves data unless explicit plugin purge is enabled; users/products/orders are not removed.

Primary references: [WooCommerce stock hooks](https://github.com/woocommerce/woocommerce/blob/trunk/plugins/woocommerce/includes/data-stores/class-wc-product-data-store-cpt.php), [stock CRUD functions](https://github.com/woocommerce/woocommerce/blob/trunk/plugins/woocommerce/includes/wc-stock-functions.php), [variation API](https://woocommerce.github.io/code-reference/classes/WC-Product-Variation.html). Runtime continues to use the original WordPress HTTP SMS.ir adapter.
