# 0003 — Optional transactional order module

Date: 2026-11-07. Status: accepted under explicit owner authorization.

The owner authorized promoting the completed rc3 baseline to main once, then implementing WooCommerce order notifications on `codex/ani-sms-v1` without further main changes. Main was fast-forwarded from `bfe5df00f3359db6d34c129fd9ef90c9b38b6e51` to `790850fcc80afb7f25ab7669a7a5cb812993cf97`. The next candidate is 1.1.0-rc1; the historical 1.0 scope remains documented.

Use an optional CRUD/HPOS module, independent customer/admin switches, four transactional routes and five allowlisted variables. Durable per-order sequences distinguish repeated cycles from duplicate hooks. Coalesce only the first immediate transition into an unsent creation job. Snapshot-and-cancel is chosen over silently changing recipients/templates at dispatch. No identity links are inferred from billing data.

Use a generic encrypted queue with bounded workers and conservative terminal unknown results after interruption; the provider cannot offer exactly-once delivery. Local simulations have immutable mode tags, no provider request, and loopback-only previews. WooCommerce absence preserves settings and pauses jobs; generation rotation invalidates stale work. All API keys/server secrets/private WordPress configuration stay outside commits.
