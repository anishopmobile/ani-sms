# ANI-SMS

Independent WordPress mobile authentication plugin, based on the finalized ANI-SMS-SPEC.md 2.1.

## Current checkpoint

Version **1.0.0-rc1** implements the 1.0 feature scope: independent core, optional authentication module, SMS.ir adapter, indexed identity/OTP/rate storage, secure settings, Persian responsive admin, accessible shortcode form, logs, privacy tools and explicit uninstall policy. WooCommerce, campaigns and other notifications are outside 1.0. This is a review candidate, not an assertion that a real SMS.ir account has been verified.

Requirements: WordPress 6.8+, PHP 8.3+, sodium, MySQL/MariaDB with InnoDB and connection-scoped GET_LOCK support, and HTTPS. WordPress users/usermeta tables must also use InnoDB. Multisite is deliberately unsupported. No WooCommerce, Elementor, CDN or frontend framework dependency.

## Install and configure

1. Install the ZIP produced by `python tools/package.py`, or copy only `wp-content/plugins/ani-sms/` into a staging site's plugins directory. The repository also contains an existing WordPress installation and database dump; neither belongs in the plugin ZIP and neither is required for installation.
2. Generate 32 cryptographically random bytes and encode as base64, for example `php -r "echo base64_encode(random_bytes(32)), PHP_EOL;"`. Define this value as `ANI_SMS_SECRET_KEY` in server configuration outside the database, or provide it as an environment variable. Never commit it. Sodium must be enabled. Rotation invalidates outstanding challenges and requires replacing a database-encrypted API key.
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
