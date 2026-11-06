# Initial architecture

The source root is `wp-content/plugins/ani-sms`. The bootstrap loads a namespace-only autoloader and registers lifecycle hooks. `Core/Plugin` is the composition root. `Core/Module` defines optional features, and `ModuleRegistry` boots enabled modules only when dependencies are available. The core currently registers no feature modules or public routes.

`Support/Phone` is pure PHP: Iran-only canonical mobile normalization. Stored identity will use this canonical representation, not a billing phone field.

The registry is configured once per request and refuses duplicate or late registration. It is not a user-facing activation API. A future privileged settings action will persist enabled IDs; the next request will build and boot that configuration. Mid-request unregistering is intentionally unsupported.

Planned, not yet implemented: indexed InnoDB identities/challenges/rate limits, provider contract and SMS.ir HTTP adapter, normalized message states, secret protection, OTP module and guest binding, public shortcode, admin settings, privacy/retention and migrations. The full contracts are in ANI-SMS-SPEC.md.
