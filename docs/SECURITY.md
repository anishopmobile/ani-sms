# Security and privacy

## Local test exception (1.0.0-rc2)

The owner explicitly authorized a local-only walkthrough. With `ANI_SMS_LOCAL_TEST=true`, environment `local`, a loopback home URL and a loopback peer, the bound issue response includes a challenge-specific test code and the form labels it as simulated. Plaintext is not stored/logged. All normal account/CSRF/rate/expiry/one-time rules still apply and actual subscriber accounts can be created locally. Test/live key IDs differ, so switching context/mode invalidates verification across the boundary. A requested test flag also blocks the real SMS transport even when live settings conflict. No request parameter, database option or forwarded header enables it; nonlocal contexts fail closed. This exception must be disabled before public deployment or real-provider tests.

## Boundaries

OTP authenticates possession of a phone, not resistance to SIM swaps or carrier compromise. Sensitive administrators/managers cannot use OTP. Normal WordPress authentication is preserved; unknown authentication filters or pluggable replacements fail closed. `wp_authenticate_user` policy checks and core `authenticate`/spam checks still apply before standard WordPress cookies are created. No reusable custom token replaces WordPress sessions.

## Secrets

`ANI_SMS_SECRET_KEY` must be 32 random bytes encoded as base64 and held outside the database. It keys domain-separated HMACs and sodium authenticated encryption of the database API key. API configuration from `ANI_SMS_API_KEY` overrides the DB value. Browser forms never contain the stored credential, even as a password value. A shared DB+server backup or a compromised PHP host defeats encryption; protect backups/configuration separately. Key rotation invalidates challenges and encrypted credentials. Corrupt/missing keys fail closed.

OTP plaintext exists only while preparing the outgoing HTTP request. It is never stored or returned by the plugin. Code digests include challenge ID and purpose. The SDK is not loaded; the original adapter uses a fixed HTTPS origin, TLS verification, no redirects, a 10-second timeout and 64 KiB response limit. No raw provider response escapes the adapter. External debugging/HTTP-inspection plugins must not record sensitive requests; ANI-SMS cannot control another plugin's logging hooks.

## Request controls

Auth bootstrap is an uncached same-origin POST that issues an HttpOnly SameSite=Strict host-only browser cookie expiring within 15 minutes. An HMAC-backed CSRF value is returned separately. Issue/verify requests require the cookie, CSRF header and exact Origin. Logged-in linking additionally requires a WordPress nonce and password reauthentication at issuance. HTTPS is mandatory outside an explicitly configured loopback-only test environment. CORS is not opened.

The browser binding, purpose and actor cannot be changed between issuance and verification. A phone lock covers issuance and the entire bounded provider call; late results cannot supersede a newer generation. Request IDs are unique per binding/payload and retained with the short-lived challenge. Verification uses a row lock and a shared user/identity transaction. Unique phone and user constraints prevent duplicate links. Unsuccessful attempts commit increments; successful consumption clears the digest. Created-user caches are cleared after commit/rollback. Third-party hooks with external effects cannot be rolled back by MySQL; audit integrations before enabling them.

Phone/IP/site quotas use atomic, first-use-anchored fixed windows. Per-phone verification quotas persist across challenge replacement. A general endpoint-IP quota also covers malformed/bootstrap traffic. REMOTE_ADDR is authoritative; configure proxy trust at the web server. Limits are basic controls, not an advanced velocity-check product. All syntactically valid issuance requests use the same provider path to reduce account timing enumeration, even when login is ultimately refused. Rate limits and account SMS costs must be sized for the site.

## State and failures

Only valid successful API acceptance activates an eligible OTP. `accepted` is not `delivered`. A transport/5xx/429/redirect/malformed-success POST can be ambiguous and becomes `unknown`, never blindly retried. No alternate provider, bulk fallback or delivery channel exists. An unsuccessful resend preserves the previously active challenge; an accepted replacement invalidates it. Challenge timestamps are checked during verification independently of cleanup. Deactivation/module disable invalidates outstanding challenges.

## Administration and data

Administrative mutations verify manage_options and a WordPress nonce; verified-user subscription forms additionally require same-origin POST and an account nonce. Public opt-out requires a random bearer token plus explicit confirmation POST and is intentionally available without account login. All public input is narrowly typed. SQL values are prepared and custom table names come from fixed internal allowlists. SQL error suppression is scoped to ANI-SMS data operations and restored immediately; it is not a global WordPress logging switch. Output is escaped at rendering. Redirect destinations are server-defined home URLs and client-checked for same origin.

Identity records hold canonical phone, WP user ID and verification time. Challenges hold the short-lived phone/binding/purpose/actor/digest metadata. Rate keys are HMAC hashes; logs contain only correlation, purpose/state/error category, masked phone, provider message ID and UTC time. Logs retain no message bodies. Approved campaign drafts are encrypted operational data with a 30-day retention limit; per-recipient queue snapshots expire 24 hours after scheduling. Cron cleanup is bounded and indexed; missed cron or extreme backlog can extend physical retention, so operators must monitor it. Default windows and purge behavior are in README.

Privacy tools map verified request email to a WordPress user, then export or remove the identity/challenges. No email is fabricated for phone-only users. An authenticated administrator must verify a phone-only claimant separately before using `Privacy::remove_user($id)`; that method is an internal service, not a public endpoint. Never infer marketing consent from authentication. Multisite and account merging are unsupported.

## Explicitly absent

No reCAPTCHA, advanced velocity check, scheduled registration restriction, extra provider, channel fallback, Woodmart adapter, marketing or WooCommerce module is shipped in 1.0. None of these exclusions relax the baseline protections above.


## 1.1/1.2 extensions

Order billing phones cannot create identities. Marketing requires explicit versioned consent on a verified identity, with local/live proof and consent modes checked independently from campaign/job modes. Legacy/local proof cannot grant live consent. Imports preserve suppression; changing groups rotates the recipient revision. Campaign approval binds the previewed audience, and dispatch rechecks consent/campaign state under recipient/campaign locks. Unknown POST results are terminal. Key-ID mismatch blocks keyed suppression operations until reviewed migration; never delete this guard to restore sending.

Phone/token fields and campaign drafts are encrypted, token lookup and suppression use domain-separated HMAC, and opt-out pages prevent referrer/cache leakage. Privacy erasure deliberately retains a minimal suppression fingerprint, disclosed in policy content. No CSV/Excel export is exposed. Temporary browser sessions, local API settings and test tokens are excluded from Git and package output.


## Stock alerts

Only an explicit versioned request from a signed-in verified identity authorizes a one-shot alert for an exact published simple product/variation. Local/live proof and request modes are immutable; no phone/user/role is accepted from the client. Dispatch rechecks inventory, public visibility, latest request/job revision, phone mapping, expiry and template snapshot. Cancellation/reconciliation use the product lock; stale jobs cannot complete fresh revisions. Stock consent is separate from marketing and grants no reservation. Product/user deletion, erasure and module disable cancel pending work; phone snapshots clear at terminal outcomes. Unknown sends are not retried.
