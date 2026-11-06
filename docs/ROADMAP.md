# Roadmap

## 1.0 feature scope — implemented for release-candidate review

- Independent core/lifecycle, module and provider registries, migrations and shared sending service.
- SMS.ir verification-template adapter, encrypted credentials, no-send connection diagnostics and explicit paid test action.
- Canonical Iranian mobile identities, atomic OTP consumption/registration, quotas, guest binding, purpose/actor controls and standard WordPress sessions.
- Existing-user linking after password reauthentication; linked identities remain read-only.
- Persian/English translation-ready responsive admin and standalone shortcode form.
- Masked metadata logs, bounded retention cleanup, WordPress privacy tools and preserving/explicit-purge uninstall.
- Guarded unit, integration, race, lifecycle, static-analysis and browser/accessibility checks and allowlisted package builder.

## Operational release gate — outstanding external verification

Real SMS.ir account/template contract and a deliberate paid test using credentials outside the repository. No real message has been sent during this work. Review candidate installation and acceptance by the owner do not imply permission to merge main or publish a GitHub release.

## Later product phases — not implemented and not authorized by the 1.0 scope

Optional WooCommerce transactional notifications; consent/subscribers/campaigns and their queue; back-in-stock, discounts, abandoned carts, newsletter and explicitly selected form integrations. Each needs its documented trigger/recipient/consent/cancellation contract. No placeholder pages or mandatory WooCommerce/Elementor dependency are included.

Other providers/channels, fallback, CAPTCHA, advanced velocity checks, registration time windows and Woodmart-specific integrations remain excluded until a separate instruction.
