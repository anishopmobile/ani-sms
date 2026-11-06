# Implementation plan — ANI-SMS 1.0
Status: execution authorized; final specification 2.1 accepted.
Date: 2026-11-06

## Repository reconnaissance
Base main: bfe5df00f3359db6d34c129fd9ef90c9b38b6e51.
Existing repository contains a complete WordPress installation, WooCommerce, a database dump and the read-only Plugin-sms-ir reference. No AGENTS.md files were found in the complete Git tree. Existing files will remain untouched.
New plugin source: wp-content/plugins/ani-sms/. Tests: tests/ani-sms/. Documentation: docs/.
Packaging must explicitly include only the ani-sms plugin directory, never repository config, database dumps, third-party code or reference plugin.

## Decisions
- Minimum WordPress 6.8; minimum PHP 8.3 (available CLI 8.3.30 and current WordPress hosting recommendation). Target repository WordPress 7.1 separately from minimum; test both before release.
- Original code, GPL-2.0-or-later; preserve all existing license files.
- Lightweight explicit module registry with dependency checks; no external runtime container.
- Identity/challenge/rate storage will use indexed custom InnoDB tables and atomic transitions; no usermeta identity lookup or transient-only security.
- No OTP endpoints enabled until issuance, atomic verification, account policy and abuse protections are implemented together.
- Core scaffolding first; adapter and complete OTP in subsequent increments; no non-1.0 modules.
- HTTP adapter will use WordPress APIs, fixed SMS.ir host and no blind POST retries.
- API REST page remains inaccessible on recheck. Official company SDK gives initial evidence only. Operational contract verification is an open release prerequisite.
- CLI Git clone is slow/unavailable; connected GitHub API may publish coherent commits preserving the parent tree. Local staged files map exactly to remote paths; they are not a replacement project.

## Ordered milestones
1. Record plan and final requirements (this commit).
2. Bootstrap, lifecycle, registry, pure phone normalization, isolated smoke tests.
3. Database schema/migrations and atomic repositories.
4. SMS.ir settings, credential protection and mocked adapter tests.
5. Complete OTP/account flows, frontend and abuse/race tests.
6. Admin design, privacy tools, retention and uninstall.
7. Real WordPress integration, minimum/current matrix, accessibility/RTL review and installable ZIP.
Each milestone updates PROGRESS; unrun checks remain explicitly unrun.

## Source evidence
- https://wordpress.org/about/requirements/ (PHP 8.3+ recommendation, checked 2026-11-06).
- ANI-SMS-SPEC.md sections 3 and 6 for API evidence and limitations.
