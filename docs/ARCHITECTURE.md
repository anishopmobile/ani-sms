# ANI-SMS architecture — 1.3 candidate

Source root: `wp-content/plugins/ani-sms`. The bootstrap registers lifecycle hooks and boots at init priority 5 through a namespace-only loader. `Core/Plugin` is the composition root. Infrastructure has no dependency on WooCommerce, Elementor or a theme.

## Boundaries

- `Core`: lifecycle, settings, module contract/registry and provider registry. The module registry refuses duplicate/late registration and loads enabled modules only when prerequisites exist. Privileged settings persist the next request's state; disabling invalidates live challenges immediately.
- `Infrastructure`: trusted table names, checked SQL/transactions, connection-scoped named locks, idempotent dbDelta schema migration, bounded cleanup, redacted logging and shared Sender. SQL error suppression is temporary around private-data operations and restored after each operation.
- `Provider`: interface, normalized Result and original SMS.ir HTTP adapter. Result includes state, error category, message ID, provider/operation, correlation and retryability. Template, account-connection and single-recipient text/bulk capabilities are advertised. No raw provider payload crosses this boundary.
- `Security`: server-only master key, domain-separated HMAC, sodium credential encryption and atomic phone/IP/site quotas.
- `Auth`: account policy, OTP state machine, signed guest binding, narrow same-origin AJAX endpoint, shortcode renderer and browser script. No reusable plugin session replaces WordPress cookies.
- `Admin` and `Privacy`: scoped admin pages, capability/nonce-protected actions, masked logs, personal-data exporter/eraser and deletion hooks. The core registers an informative shortcode fallback when authentication is disabled/unavailable.
- `Support/Phone`: pure Iran-only canonical mobile normalization. Billing/user metadata never defines verified identity.

## Storage

All custom tables use the current `$wpdb->prefix` and InnoDB. `identities` has unique phone and user IDs; `challenges` has unique challenge/request IDs and indexed phone/timestamp/expiry; `rates` stores HMAC-keyed bounded-window counters; `logs` stores only allowlisted metadata. Schema changes are versioned and serialized with a schema lock. Core users/usermeta engine checks prevent pretending that nontransactional user creation is atomic.

## Issue and verify

Uncached same-origin bootstrap mints a signed 15-minute browser cookie and CSRF token. An issue request validates phone/request/purpose/actor, takes a per-phone named lock, checks cooldown and independent quotas, and persists pending_send before the bounded SMS.ir call. Same request/binding/payload returns the existing challenge without another send. All valid numbers follow the same provider path; eligibility is applied before activation. Active codes are replaced only on accepted sends; unknown/failed sends do not erase a previous active code.

Verify checks browser/purpose/actor and independent quotas, takes the phone lock and locks the challenge row in a transaction. Wrong attempts commit increments and lock at the configured threshold. Correct eligible verification consumes the challenge and resolves/creates the unique WordPress user+identity in that transaction. Core user caches are cleared after either result. External effects from third-party WordPress hooks cannot be rolled back by the database and require compatibility review.

Only after verification does the endpoint apply WordPress authentication policy and issue core session cookies. Privileged accounts and unreviewed authentication handlers fail closed. Account linking additionally requires an existing authenticated session, WordPress nonce and password reauthentication. Linked identities are read-only in 1.0.

## Lifecycle and packaging

Expiration checks are synchronous; cleanup is hourly and depends on healthy site cron only for physical deletion. Deactivation clears the scheduled hook and invalidates live challenges but preserves permanent data. Uninstall defaults to preservation; explicit purge removes only ANI-SMS data.

No bulk jobs exist in 1.0, so there is no unneeded queue dependency. Future queue/event contracts and delivery polling remain in the specification/roadmap. The package builder includes only plugin files, source, assets, translations and license; the existing WordPress installation, config, database dump, reference plugin and dev tools are excluded.


## Optional phase extensions

Schema 4 adds the shared encrypted queue/event-state tables, subscribers, versioned consent evidence, campaigns and durable recipient snapshots. Identity proof and consent modes separate local simulations from live marketing. Successful OTP completion stamps proof mode in the same transaction as consumption; old identities remain unknown until reverified. Core queue has generic handlers, conditional claims, leases and deferred work; it has no WooCommerce/marketing business dependency. Paused campaign jobs are excluded from runnable selection.

WooCommerce uses only its CRUD and hooks with HPOS compatibility; marketing uses core identity proof and its own consent state. Billing phones and imports never grant consent. The marketing opt-out route and privacy hooks remain available while the module is disabled; subscriber assets/opt-in and campaign dispatch do not. See WOOCOMMERCE.md, MARKETING.md and ADRs 0003/0004 for event/retention/control policies.


## Stock extension

Schema 5 adds stock_requests, unique by exact product/user/mode, with policy/source, encrypted phone and latest-job identity. Stock uses WooCommerce CRUD and its own one-shot request state, not marketing subscribers. Queue completion and reconciliation take the product lock and match the latest job, preventing stale cancellation from overwriting a new revision. Recovery is bounded/rotating; classic product hooks and explicit shortcodes support the public UI. See STOCK.md and ADR 0005.
