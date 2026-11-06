# ADR 0002 — explicitly authorized loopback OTP testing

Status: accepted at the owner's request on 2026-11-07; this amends the normal no-code-response rule solely for local testing.

The owner needs to see and exercise the real form/OTP flow before acquiring an API key. A server-only `ANI_SMS_LOCAL_TEST=true` flag is accepted only with WordPress environment `local`, loopback home hostname and a loopback REMOTE_ADDR. Unknown/production/staging environments, nonlocal hostnames and remote peers fail closed. No dashboard, query string or forwarded header enables it. The existing private master key and browser/CSRF/account policy remain required.

The local transport makes no HTTP call and returns no provider message ID. OTP codes are keyed, challenge-specific deterministic six-digit values, allowing idempotent retries without saving plaintext. The normal keyed digest is persisted. Only the bound local issue response discloses its test code, and the frontend clearly labels it. Distinct key IDs stop cross-mode verification when mode/context changes. All real SMS transport sends are refused whenever the flag is requested, including conflicting live settings.

This is a local development transport, not another gateway, delivery channel, CAPTCHA or production bypass. Normal expiry, attempt caps, resend/IP/phone/site quotas, atomic consumption, identity uniqueness and privileged-account rules are unchanged. New registrations do create local WordPress subscriber accounts. Existing administrators should use an incognito window rather than testing account linking as an administrator. Turn off the test flag before real SMS testing; never deploy this local private configuration to a public site.
