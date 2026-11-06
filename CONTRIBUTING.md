# Contributing

Use the existing repository and `codex/ani-sms-v1` branch. Preserve existing WordPress files and reference material; do not modify main, create a PR or publish a release without a separate instruction. Never commit credentials, config, database exports, runtime fixtures, node_modules, vendor or generated ZIPs.

Production source is only `wp-content/plugins/ani-sms/`. New code is original GPL-2.0-or-later. Dependencies under Composer/npm are development tools, excluded from the installable artifact. Use WordPress APIs, prepared values, escaped output, capability/nonce checks and the shared storage/provider/sender boundaries. Module code must not bypass the OTP/account policy or own the core lifecycle.

Run `composer install`, `npm ci`, `composer test`, `composer style`, `composer analyse`, then the guarded integration/concurrency/lifecycle/browser suites described in docs/TESTING.md. WPCS Core is enforced, with the filename rule excluded for the namespace loader; generated catalogs are checked for syntax and translation coverage separately. Do not suppress genuine analysis defects with a blanket baseline. Rebuild translations and package after changes, and record actual tests and limitations in PROGRESS.

Documentation must distinguish implemented, deferred and operationally unverified behavior. Test transports must remain mocked by default. A real SMS test requires a controlled recipient, real credentials outside the repository and a deliberate paid-test action; it is never a CI/default-test side effect.
