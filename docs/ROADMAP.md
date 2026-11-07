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

## Later product phases — still deferred

Discounts, abandoned carts, newsletter and explicitly selected form integrations. Each needs its documented trigger/recipient/consent/cancellation contract. No placeholder pages or mandatory WooCommerce/Elementor dependency are included.

Other providers/channels, fallback, CAPTCHA, advanced velocity checks, registration time windows and Woodmart-specific integrations remain excluded until a separate instruction.

## Authorized 1.1 order phase

Optional WooCommerce transactional notifications and their generic durable queue are implemented in 1.1.0-rc1. See WOOCOMMERCE.md and decision 0003. Real SMS.ir delivery remains an external release gate.

## Authorized 1.2 marketing phase

Subscribers, explicit consent, suppression and campaigns are implemented as an independent optional module. See MARKETING.md and decision 0004. Excel/CSV, inbound SMS and trigger-specific modules remain deferred. Real line permission and delivery are still unverified.

## Authorized 1.3 stock phase

One-shot exact-product/variation availability alerts are implemented. See STOCK.md and ADR 0005. Discount, abandoned-cart, standalone newsletter and form integrations remain deferred. Live provider/template verification remains outstanding.
