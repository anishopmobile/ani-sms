# 0005 — One-shot exact-product availability requests

Date: 2026-11-07. Status: accepted under the owner's explicit request, technical decisions delegated.

Implement back-in-stock as 1.3.0-rc1 on `codex/ani-sms-v1`; main stays unchanged. Choose an explicit verified-account product/variation request rather than treating purchases, newsletter membership or a billing phone as authorization. Requests expire in 30 days and send once; no marketing permission or stock reservation is inferred.

Use WooCommerce CRUD/hooks, exact variation IDs, a separate labelled variation selector and a shortcode fallback for block templates. Initial UI lists up to 100 child choices; fanout/parent recovery batches are 50, rotating recovery is 20 products. Requests/jobs preserve local/live modes. Definitive outcomes and unknowns are terminal; only unsent inventory reversals re-arm with a fresh revision. Dispatch revalidates settings, identity and current inventory, and never retries ambiguous sends automatically.

Product-specific locks and latest-job keys prevent stale cancelled jobs from completing a newer request. Marketing is an independent module. Reuse transactional template transport and the generic durable queue; no new SDK, direct order queries, campaign or provider dependency is introduced.
