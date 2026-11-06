=== ANI-SMS ===
Contributors: anishopmobile
Tags: sms, authentication, otp, login
Requires at least: 6.8
Tested up to: 7.1
Requires PHP: 8.3
Stable tag: 1.0.0-rc3
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Independent SMS.ir mobile login and registration with Persian responsive settings and secure one-time codes.

== Description ==

Review candidate for the finalized ANI-SMS 1.0 scope. Requires PHP sodium, MySQL InnoDB/GET_LOCK, HTTPS and an SMS.ir account with an approved verification template. No WooCommerce, Elementor, CDN or extra provider dependency.

Sending is disabled until ANI_SMS_LIVE_ENABLED is explicitly enabled in server configuration. Configure ANI_SMS_SECRET_KEY outside the database before storing credentials. Never place secrets in the repository.

SMS.ir receives destination phone numbers and template parameters when sending a requested code. Its service terms and privacy policy apply: https://sms.ir/ . This plugin does not contact SMS.ir on activation. Connection checks and sending occur only from the documented configured actions; normal OTP issuance requires a submitted form.

This is not a confirmation of real-account delivery. Read the repository README and docs/SMSIR-API.md before operational use. No real SMS was sent during automated development tests.

For local development only, ANI_SMS_LOCAL_TEST=true plus WP_ENVIRONMENT_TYPE=local, a loopback site hostname and a loopback connection allow the OTP form to work without an API/template. A private server secret is still required. Codes appear on the local form, real SMS is blocked, and ordinary OTP/account protections remain active. Use a private browser window; registrations create real local subscriber accounts. Disable this flag before real-provider tests or public deployment.

== Installation ==

1. Upload the ani-sms directory or generated ZIP to WordPress.
2. Configure the server secret key, API key and approved SMS.ir template.
3. Publish/select a privacy policy page, configure HTTPS and cache exclusions.
4. Enable the authentication module and add [ani_sms_auth] to a page.
5. New registration requires both plugin and WordPress registration switches. Privileged users use standard WordPress login.

== Privacy and uninstall ==

No plaintext OTP or message body is logged. Diagnostic phone numbers are masked. WordPress privacy exporters/erasers support linked accounts; phone-only requests require verified administrator handling. Hourly cleanup depends on working site cron.

Deactivation preserves permanent data and invalidates challenges. Uninstall preserves data unless the explicit purge option is enabled. Purge never deletes users or orders. Server-config secrets and backups require separate handling.

== Changelog ==

= 1.0.0-rc3 =
* Correct expired/locked confirmation UI and provide clear, bound-challenge error messages.
* Explicitly guarded loopback OTP simulation with on-form test codes and no SMS calls.
* Independent core, module/provider registries, SMS.ir adapter and shared sending boundary.
* Atomic OTP lifecycle, phone identity, basic abuse controls and standard WordPress sessions.
* Persian responsive admin, standalone accessible form, safe diagnostics and privacy lifecycle.
* Mocked integration/race/browser checks; real-account validation remains external.
