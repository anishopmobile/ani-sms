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


## Optional order phase installed — 1.1.0-rc1

The development checkout now includes active WooCommerce and the optional order module for local simulation. Open `http://localhost/ani-sms/wp-admin/admin.php?page=ani-sms&tab=woocommerce` for recipient settings, four template mappings and masked queued-job previews. Local template IDs remain 0; no API key is needed. Test orders #14/#15 were created via CRUD and edited through the HPOS order screen. Use the local Process eligible jobs action after five seconds. Configuration backup is outside the document root/Git. Core auth remains enabled and the existing login page is unchanged. See WOOCOMMERCE.md before configuring approved live templates and controlled real recipients; the current dummy test destinations must not be used for paid testing.


## Independent marketing phase installed — 1.2.0-rc1

Open `http://localhost/ani-sms/wp-admin/admin.php?page=ani-sms&tab=marketing` for pending contacts and campaigns. The marked test subscription page is `http://localhost/ani-sms/?page_id=16`. Use a private window, sign in through the existing OTP page and then explicitly subscribe on page 16. A standard administrator without a verified mobile is not offered an opt-in form. This does not alter the owner's consent or login session.

Final schema-4 UI fixture group: `local-458505`; campaigns #8/#9/#10/#11 demonstrate cancelled scheduled work, simulated sending, opt-out after enqueue and module disable, respectively. All are local-mode records; the final test contact is suppressed. Existing earlier marked test groups were not promoted to live consent. The distributed module defaults off; it is enabled only in this owner's local settings for further tests. The temporary background-HTTP guard is removed and temporary admin tokens revoked after testing. Real marketing needs a public HTTPS unsubscribe URL, permitted line, live-verified phone/consent and privately configured API; local proof/consent is excluded even after flipping server flags.


## Stock module installed — 1.3.0-rc1

Management: `http://localhost/ani-sms/wp-admin/admin.php?page=ani-sms&tab=stock`. Demo: `http://localhost/ani-sms/?page_id=22`, containing simple products #17/#18 and variable parent #19 (blue #20/red #21). Only marked local fixtures were mutated. They are left out of stock for further manual requests. Sign in via local OTP in a private window, check the separate one-shot request box, then change the exact product's stock and process jobs after five seconds. The module is enabled only in the owner's local options; distributed defaults stay off. Template ID is 0 for simulation, with Product/Link/Site mappings. Public forms show localized waiting/queued/simulated/cancelled outcomes.

Temporary test administrator sessions and HTTP/automatic-cron isolation are removed after validation; the original owner session is preserved. Test-product stock email suppression remains private/local. No existing product/order was changed, and no credentials are committed. Stock requests never grant marketing consent or reserve inventory. Live sending still requires a valid transactional template/key, live server switch, HTTPS and a live-verified phone; test requests do not become live messages.


## Discount module — 1.4.0-rc1

Management: `http://localhost/ani-sms/wp-admin/admin.php?page=ani-sms&tab=discount`. Marked demo: `http://localhost/ani-sms/?page_id=28`, with simple #23/#24 and parent #25 (blue #26/red #27). Use a private window and OTP page #6, explicitly subscribe on page #16, then separately request an alert on page #28. The module is enabled only in this owner’s local settings; distribution defaults off. Sending line is blank for simulation. Set a lower Woo sale price and process eligible jobs after five seconds to see a local preview with catalog prices, percentage and unsubscribe link. No price or stock is reserved.

Final owner UI: 28 assertions passed with zero browser errors. Demo sale prices are cleared for another manual request. Temporary admin sessions and background HTTP/cron isolation were removed; existing owner sessions and consent remain unchanged. Private mail guards affect only marked fixtures.


## Cart reminders - 1.5.0-rc1

Management: http://localhost/ani-sms/wp-admin/admin.php?page=ani-sms&tab=cart
Demo form: http://localhost/ani-sms/?page_id=30
Marked local cart product: #29; native cart page #10. Use a private window, sign in at OTP page #6, explicitly opt in to marketing on page #16, then separately enable cart reminders on page #30. Add product #29 to the native cart. The owner-local delay is 15 minutes, line blank; the distributed default is 60 minutes and module off. Stop storefront activity for the full delay, then process eligible jobs from admin to inspect a simulated preview. Visiting the storefront resets inactivity and cancels queued work. Native cart/checkout prices and stock are not reserved.

The completed UI fixture subscriber has withdrawn consent. Existing owner sessions/consent are untouched. Temporary test sessions and the HTTP/cron isolation are removed after checks. No real API configuration is required for the explicit guarded local mode.
