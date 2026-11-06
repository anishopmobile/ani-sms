# Local installation verification — 2026-11-07

## Subsequent local-test update — 1.0.0-rc2

At the owner's later request, the private local configuration now explicitly enables ANI_SMS_LOCAL_TEST and declares environment local. The actual site URL/peer are loopback, so the form is available without an API or template while ANI_SMS_LIVE_ENABLED remains false. The original no-API fail-closed installation record below describes the earlier RC1 state.

The owner site's browser walkthrough passed 12 checks: visible local-mode/code notices, six-digit code display, wrong-code rejection, registration, core WordPress cookie, retained verified identity, cooldown across a new browser binding and successful login to the same account after waiting the real cooldown. It used a fresh private browser context and created only its own local subscriber; the owner's administrator session/account was not changed. No real SMS or API credential was used. Test accounts and challenges are ordinary local test data, not gateway delivery evidence. Open the login page in an incognito/private window to try it yourself.

At the owner's explicit request, the existing WordPress installation and its existing database were used, rather than another mock-only site. The supplied WordPress login was used to verify the real admin UI; the credential is not stored in this report or repository.

## Installed state

- The originally clean local checkout was switched from main to tracking `origin/codex/ani-sms-v1`. Main was not committed or modified.
- The branch's `wp-content/plugins/ani-sms/` is installed and active as 1.0.0-rc1.
- Existing database connection and InnoDB schema activation succeeded. The database dump was not reimported or overwritten.
- A fresh cryptographic secret is held in a private configuration file outside the web document root and Git checkout. The local wp-config references it; that local configuration change is deliberately not committed or pushed. Before-change configuration/options backups are also outside the web root.
- Live sending is explicitly false. No API key or approved template ID was supplied. No dummy credential was substituted into the owner's site, no SMS transport mock was installed there, and no real SMS was sent.
- The authentication module is selected but correctly remains unavailable until credentials/template/live configuration are complete.
- Plugin and WordPress registration settings are enabled for the requested local test setup. A clearly labeled test privacy page is configured when no published privacy page already exists. It must be reviewed before public deployment.

## Verified in a real browser

WordPress admin sign-in, ANI-SMS admin rendering, four honest statistic cards, empty/non-echoed API-key input, selected authentication module, and a public shortcode page all passed. The unconfigured shortcode renders an unavailable message with standard WordPress login instead of an unsafe partially working OTP form. No API request was made with a real credential.

Local admin connection page: http://localhost/ani-sms/wp-admin/admin.php?page=ani-sms&tab=connection

Local login page: http://localhost/ani-sms/?page_id=6

## Sources and outstanding test

The official `IPeCompany/smsir-php` base URI and verify payload were additionally cross-checked against ANI-SMS; see SMSIR-API.md. The WordPress reference Plugin-sms-ir was already inspected for functional behavior. Native WordPress HTTP remains the runtime adapter; third-party plugin classes/assets are not copied.

The owner clarified that no SMS.ir API key has been obtained yet and that only the base setup is required now. Real delivery/login/registration through SMS.ir is therefore not reported as passed. Later, configure a real API key, approved template ID and exact parameter through the local admin UI, then explicitly enable live sending in the private local configuration and use a controlled paid-test recipient. The existing loopback-only HTTP exception applies solely to this localhost setup; public deployment requires HTTPS.
