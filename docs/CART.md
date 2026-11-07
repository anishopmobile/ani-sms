# Abandoned-cart reminders — implementation contract

Status: **planned; implementation and execution blocked by local runner availability**. This document is a contract, not a release or test result. Owner delegation authorizes the decisions below. Current shipped candidate remains 1.4.0-rc1.

## Audience and permission

Optional, default-off module requiring WooCommerce and the marketing consent module. Track only signed-in accounts owning an OTP-verified phone, current versioned marketing consent, and a separate explicit cart-reminder opt-in. Login, checkout billing details, purchases and contact imports never grant permission. Guests and unverified accounts are excluded; no guest fingerprinting or billing-phone identity linking.

Expose opt-in/withdrawal in an accessible account/cart form with an unchecked checkbox, privacy link, nonce, same-origin validation and rate limit. Cart opt-in expires in 30 days. Global opt-out and erasure remain effective when WooCommerce or this module is off. Re-consenting must not revive old work.

## Episode and timing

An episode begins when an opted-in account has a nonempty supported WooCommerce cart. Default inactivity delay: 60 minutes; configurable 15 minutes–7 days. Activity is an authenticated foreground visit or cart mutation for this account. Activity cancels any unsent reminder, updates the cart fingerprint and starts a fresh inactivity window. Background cron, admin screens and provider callbacks do not count as activity.

Only native simple/exact variation items are supported initially. Hash canonical product/variation IDs, selected variation attributes and quantities; never store raw session cookies, checkout fields or payment details. Do not promise historical prices, tax, coupons or inventory. Re-read persistent cart and native product eligibility before dispatch. Unsupported/missing persistent cart data must suppress sending.

One reminder per abandoned episode, at most one attempted reminder per account in seven days. Persist the episode generation, revision and cooldown across session changes, cart edits and empty/refill cycles. Accepted, simulated, failed or unknown attempts consume the attempt allowance; an ambiguous result is never automatically retried. Unsent cancellations do not consume an allowance.

A cart record expires after seven days without foreground activity. Queue snapshots expire within 24 hours and no later than the episode expiry. Record metadata is retained no longer than 30 days under scheduled cleanup. Delivery depends on working cron; an exact delivery time is not guaranteed.

## Return, purchase and withdrawal

Returning before dispatch invalidates the queued revision and schedules no immediate send. A changed cart restarts the inactivity window. An empty cart cancels the episode. An already-simulated/accepted episode cannot repeat solely because a page reload or cron runs again; the durable cooldown also applies.

Any submitted non-draft order for the same customer after episode creation cancels that episode, even while awaiting payment. Cancelling/refunding that order does not resurrect it. Capture classic and Store API checkout through supported WooCommerce hooks and CRUD, including HPOS. Reconcile via a bounded customer-order query before dispatch, so a missed callback cannot send a stale reminder. Do not query order post/meta tables directly.

Recheck account ownership, live/local proof, consent state/version/revision, opt-in expiry, cart generation, recent activity, order existence, product eligibility and settings immediately before sending. Cancellation and dispatch must share ordered locks (subscriber, then account cart); conditional transitions and unique job keys prevent duplicate workers. Never enqueue a stale revision after a concurrent withdrawal.

## Transport and destination

Use the existing SMS.ir permitted marketing-line text/bulk provider, never an OTP verification template. Every message includes the subscriber's opaque unsubscribe link. Allow only site_name, item_count, cart_url and unsubscribe_url; append unsubscribe when omitted, reject HTML/unknown variables, expand once and enforce size bounds.

The cart destination is the site's native cart URL. It does not sign anyone in, carry personal data, restore a cart automatically or mutate state on GET. The recipient signs into the same account and uses WooCommerce's existing cart. A cross-device snapshot recovery token is outside this first contract.

Local mode requires the existing explicit server flag, local environment and loopback safeguards. It records simulated outcomes and protected administrator previews without SMS HTTP or API configuration. Local phone proof, opt-in and jobs never become live consent or sends. Real sending remains gated by the server live switch, private API key, HTTPS, live phone proof and live consent.

## Integration and definition of done

Add independent cart storage with indexed account/mode uniqueness, episode/revision, last activity, consent binding, opt-in expiry, job key and durable last-attempt/cooldown. Use shared encrypted queue, provider, masked logs and module registry. Implement current-state reconciliation after worker interruption. Bound cron scans and continue without starving eligible accounts.

Provide Persian responsive administration, a separate module toggle, sending-line/message/delay settings, diagnostics and local-only previews. Preserve entered settings after validation errors and preserve all other module states. Integrate privacy export/erasure, account deletion, module disable, deactivation and opt-in uninstall purge. Missing dependencies pause safely and preserve settings; withdrawal still cancels work.

Complete docs/CART-TESTS.md, static checks, physical ZIP activation, minimum-version and existing-module regressions before calling this module complete or bumping its released version. Publish changes only on codex/ani-sms-v1; do not change main.
