# 0004 — Independent consent and campaign module

Date: 2026-11-07. Status: accepted under the owner's instruction to implement the next documented module without technical approval questions.

Implement spec section 10 / phase six as 1.2.0-rc1, exclusively on `codex/ani-sms-v1`. Main stays at the previously authorized rc3 baseline. WooCommerce remains optional.

Choose verified-account explicit opt-in, pending-only manual import, one local group tag, versioned evidence and a keyed durable suppression record. This avoids treating OTP, purchases or imported spreadsheets as marketing consent. Random-token opt-out uses confirmation POST and survives module disable. A fresh explicit opt-in rotates the token/revision.

Initial operational bounds: 50 contacts/import, 200 recipients/campaign, seven-day schedule horizon, 1000-character draft, 1500-character rendered provider payload, one recipient per SMS.ir bulk request. Previews bind the approved audience; worker checks current consent before every recipient. Pause uses a separate job state to avoid blocking order notifications. Queue ownership remains in core; marketing never reads WooCommerce internals.

Free-form marketing uses a permitted sending line via the official bulk contract, not verification templates. Local simulations require the same strict loopback guards, immutable mode and zero HTTP. Key changes fail closed for suppression. Privacy retains minimal suppression with explicit disclosure; CSV/Excel/export and future trigger modules remain out of scope.

Schema 4 records local/live phone-proof and consent modes. Test-mode consent is never a live audience. Legacy phone proofs are unknown until a successful OTP stamps their mode; live opt-in requires a live proof and HTTPS. This closes the transition from a local test installation to real sending without changing login eligibility.
