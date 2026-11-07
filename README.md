# ANI-SMS

Independent WordPress SMS infrastructure with optional authentication, WooCommerce order notifications and explicit-consent campaigns. Based on ANI-SMS-SPEC.md 2.1 and its authorized phase addenda.

## Current checkpoint

Version **1.3.0-rc1** includes the completed authentication baseline, optional WooCommerce order notifications, an independent subscribers/consent/campaign module, and optional exact-product back-in-stock alerts. Each module and recipient route is opt-in. Campaign consent is separate from login/purchases, and local consent/phone proofs never enter a live audience. The candidate is installed and tested on the owner's development branch; no real SMS.ir delivery has been verified. See the module-specific setup and evidence links below.

### Local-only OTP walkthrough (1.0.0-rc2)

To test without an API key, explicitly define `ANI_SMS_LOCAL_TEST` as boolean `true` and set `WP_ENVIRONMENT_TYPE` to `local` in private server configuration. The site URL must use localhost/127.0.0.1/::1 and the actual request peer must be loopback; a forwarded header, admin setting or URL parameter cannot enable this mode. For loopback HTTP, also set `ANI_SMS_ALLOW_LOCAL_HTTP=true`. The normal server secret remains required. `ANI_SMS_LIVE_ENABLED` may stay false and no API key/template is needed.

The form clearly marks local testing and shows the six-digit test code after submission. Use a private/incognito window so your administrator session is preserved. Test registration creates a real local subscriber account; privileged-account restrictions, CSRF, cooldown, expiration, attempts and one-time consumption still apply. Codes are derived per challenge, not a universal fixed password, and plaintext is not persisted or logged. Idempotent retries reproduce the same test code. Test/live challenge keys are distinct, so a test code is invalid when the local context/mode is disabled. Real SMS sending is blocked whenever the local-test flag is requested, even if a live key is also configured. Disable the flag before real-provider tests or deployment.

Requirements: WordPress 6.8+, PHP 8.3+, sodium, MySQL/MariaDB with InnoDB and connection-scoped GET_LOCK support, and HTTPS. WordPress users/usermeta tables must also use InnoDB. Multisite is deliberately unsupported. No WooCommerce, Elementor, CDN or frontend framework dependency.

## Install and configure

1. Install the ZIP produced by `python tools/package.py`, or copy only `wp-content/plugins/ani-sms/` into a staging site's plugins directory. The repository also contains an existing WordPress installation and database dump; neither belongs in the plugin ZIP and neither is required for installation.
2. Generate 32 cryptographically random bytes and encode as base64, for example `php -r "echo base64_encode(random_bytes(32)), PHP_EOL;"`. Define this value as `ANI_SMS_SECRET_KEY` in server configuration outside the database, or provide it as an environment variable. Never commit it. Sodium must be enabled. Rotation invalidates outstanding challenges and requires replacing a database-encrypted API key; marketing also fails closed until its keyed suppression data has a reviewed migration.
3. Activate ANI-SMS. In the ANI-SMS menu configure the SMS.ir API key, an approved OTP template ID and its exact code parameter name. A blank API field keeps the stored key; deletion is explicit. `ANI_SMS_API_KEY` in server configuration/environment takes precedence over the encrypted database credential. Its value is never returned to the browser.
4. Use **Check connection** to query account credit without sending SMS. API acceptance does not establish delivery or template validity. Create/approve templates in the SMS.ir panel; this plugin only maintains local mappings.
5. Review `docs/SMSIR-API.md`. Set the server-only boolean constant `ANI_SMS_LIVE_ENABLED` to `true` only for the intended staging/production account after its contract has been checked. The default is disabled. A paid test send additionally requires an explicit checkbox and recipient. Tests in this repository never send real SMS.
6. Publish and select a privacy policy page in WordPress. Enable the authentication module. Add `[ani_sms_auth]` to a page. Exclude the page and authentication AJAX responses from all page/CDN caching. Use the same origin for the public site and admin AJAX endpoint.
7. New registration defaults off and requires both the ANI-SMS registration switch and the WordPress registration setting. New users receive only the subscriber role, a random username/password and no fabricated email address.

Existing verified numbers log into their mapped nonprivileged account. Existing WordPress users can sign in normally, then link an unassigned number using their password and an OTP. Billing numbers or email addresses are never automatically imported as identities. An already-linked number cannot be changed through this release's UI. Conflicts require verified administrative handling, never automatic merging.

Privileged accounts use the standard WordPress login. Unknown third-party `authenticate` handlers or a replacement `wp_authenticate` cause mobile authentication to fail closed until a separate compatibility adapter is reviewed. Standard WordPress login remains available.

## Security, limits and privacy

Six-digit codes default to a 120-second lifetime, 60-second resend cooldown and five attempts. Limits apply independently to phone, IP and site. Limits are server-side fixed windows starting on first use, with atomic updates; creating another browser/session does not reset them. A request ID is bound to browser and payload for retry safety. All syntactically valid requests follow the same provider path to avoid an account-enumeration timing oracle; an SMS can therefore incur cost even if account policy ultimately refuses login. Such a code cannot authorize the refused account.

Challenge digests are keyed and purpose/browser/actor-bound. Successful verification and identity registration share an InnoDB transaction under a phone lock. Unknown send results are not automatically retried. No SMS message body, OTP value, authentication payload or API header is logged. Numbers are masked in diagnostics; the identity/challenge tables necessarily store the destination for their defined lifetime.

Only REMOTE_ADDR is used for IP limits. Configure a trusted reverse proxy in the web server so it sets the client address correctly; arbitrary forwarded headers are not trusted by the plugin. HTTPS is mandatory. `ANI_SMS_ALLOW_LOCAL_HTTP` exists only for localhost/127.0.0.1 disposable tests and must not be used for deployment.

Cleanup is scheduled hourly and deletes expired challenges, rate metadata after its retention window and message metadata at 30 days in bounded indexed batches. Run WordPress cron at least every five minutes using a system scheduler on low-traffic sites. Retention depends on cron availability and load; delayed cleanup is visible in Overview. Authentication expiration itself never depends on cron.

WordPress Privacy Tools export/remove mapped identity data for email-associated users. For users without email, an administrator can invoke the documented `Privacy::remove_user()` workflow only after verifying identity. Erasing a link does not delete the WordPress account. Logs retain only masked aggregate metadata until cleanup; rate keys are keyed hashes. No marketing consent is inferred or collected.

Deactivation invalidates pending/active challenges, stops the plugin's scheduled hook and preserves permanent data. Uninstall preserves data by default. The explicit Help-page purge option removes only ANI-SMS tables/options/credential/cache; it never deletes WordPress users or orders. Secrets in server configuration and backups require separate handling.

## Review and operational limitations

The official REST landing page timed out during research. The implemented request casing and response model were checked against the SMS.ir company's accessible TypeScript sources; see `docs/SMSIR-API.md`. Account-specific template approval, billing units/limits, real delivery and live credentials were not exercised. A real-account smoke test is still a release prerequisite; no live SMS was sent during development.

There is no bulk queue or delivery polling in 1.0 because only short-lived OTP/test messages are sent synchronously. The adapter explicitly advertises text/delivery capabilities as unsupported. Accepted is never displayed as delivered. Other providers, delivery channels, CAPTCHA and theme-specific integrations are intentionally absent.

## Development checks

Run `composer install` and `npm ci` for development tools, then `composer test`, `composer style` and `composer analyse`. Integration, race, lifecycle and Playwright/axe instructions are in `docs/TESTING.md`. Tests refuse to run against a WordPress configuration lacking the explicit local/test database guard. No development dependency is required in the production ZIP.

Existing WordPress files and `Plugin-sms-ir/` are preserved. Only the new plugin directory is included by the package builder. Original ANI-SMS code is licensed GPL-2.0-or-later; existing upstream files retain their licenses.

## 1.1.0-rc1: optional order notifications

The explicitly authorized WooCommerce phase adds creation/status events, independent recipient routes, a durable encrypted queue and loopback-only simulated previews. Core authentication remains independent. See [configuration and event policy](docs/WOOCOMMERCE.md), [validation](docs/WOOCOMMERCE-TESTS.md), and [decision 0003](docs/decisions/0003-order-notifications.md). Main contains only the rc3 baseline; the new module is on `codex/ani-sms-v1`.

## 1.2.0-rc1: explicit consent and campaigns

Optional independent subscribers/campaign module with pending-only imports, verified-account opt-in, opaque opt-out, preview-bound approval, scheduling, pause/cancel and local simulation. See [contract and setup](docs/MARKETING.md) and [test evidence](docs/MARKETING-TESTS.md). `[ani_sms_marketing]` renders the separate account subscription form. Marketing uses a permitted sending line rather than OTP templates; no real SMS was sent.

## 1.3.0-rc1: back-in-stock alerts

One explicit 30-day request per exact product/variation and verified account, without marketing consent inference. Supports cancellation, inventory reversal, bounded queue fanout and guarded local simulation. [Contract/setup](docs/STOCK.md), [test evidence](docs/STOCK-TESTS.md). Block templates/account pages can use `[ani_sms_stock]` or an exact product shortcode.
