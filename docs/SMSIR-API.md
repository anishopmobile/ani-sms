# SMS.ir adapter contract and verification record

Checked on 2026-11-06. No real API key was supplied or used; all automated sending tests were intercepted before network transport.

## Accessible primary evidence

- Official PHP package, additionally reviewed on 2026-11-07: https://github.com/IPeCompany/smsir-php . `src/SmsIrServiceProvider.php` supplies `https://api.sms.ir/v1/`; `src/SmsIrService.php::verifySend()` uses POST `send/verify` with `mobile`, `templateId`, `parameters`. Its README demonstrates `name`/`value` code parameters and identifies the panel's Developers section for API-key creation. This corroborates the existing adapter's base and request schema without requiring the REST landing page to load.
- The PHP package's Composer metadata requires Guzzle. Its Facade/ServiceProvider are Laravel integration classes. ANI-SMS keeps its original WordPress HTTP adapter rather than embedding a second HTTP stack or Laravel facade. This is an implementation choice, not a claim that the official PHP package cannot be used in plain PHP. The initial specification explicitly disallowed copying third-party plugin code.

- Official REST landing page: https://sms.ir/rest-api/ — repeated fetches timed out. Its full current contents and error-code/limit tables were not reviewed.
- Official company TypeScript source: https://github.com/IPeCompany/SmsPanelV2.TypeScript/blob/main/src/send/sendVerifyCode.ts — POST `/v1/send/verify/`, lower-camel-case body `mobile`, `templateId`, `parameters`; response data `messageId` and `cost`.
- Parameter model: https://github.com/IPeCompany/SmsPanelV2.TypeScript/blob/main/src/utils/models/parameters.ts — name/value pairs.
- Response envelope: https://github.com/IPeCompany/SmsPanelV2.TypeScript/blob/main/src/utils/models/responseModel.ts — numeric status, message and data.
- HTTP headers: https://github.com/IPeCompany/SmsPanelV2.TypeScript/blob/main/src/utils/methods/request.ts — X-API-KEY, JSON Content-Type, JSON Accept.
- Credit: https://github.com/IPeCompany/SmsPanelV2.TypeScript/blob/main/src/settings/getCredit.ts — GET `/v1/credit`, numeric data at the actual request type (the extra GetCreditResponse interface differs; the plugin uses the numeric response type and fails closed otherwise).
- Earlier company Node SDK: https://github.com/IPeCompany/SmsPanelV2.nodejs/blob/master/index.js — corroborates routes/header but uses different body casing. The plugin follows the newer typed source rather than assuming case-insensitive requests.
- Repository Plugin-sms-ir is a read-only functional reference, not an authority for private endpoints, template signatures, safe logging or OTP storage.

## Implemented subset

The host is fixed at `https://api.sms.ir`. Canonical Iranian mobile numbers are converted to `09xxxxxxxxx` only in the adapter. Verify sends one recipient, one approved template ID and one allowlisted configured code parameter. The OTP remains a six-character string, including leading zeros. Templates must be created/approved in the provider panel; the plugin has no template CRUD endpoint or signature copied from the reference.

Only HTTP 2xx + integer application status 1 + a positive integer `data.messageId` is accepted for sending. Unknown or missing fields are not treated as success. Other application statuses are normalized as rejection without returning raw provider text. A connection check accepts numeric `data`; its success does not validate a template or count delivery. Credit units are not invented as SMS counts. No raw response or key appears in diagnostics.

No bulk sending, delivery polling, message-status mapping, template administration or webhook exists in 1.0. Capability flags for text and delivery are false. There is no unverified endpoint fallback. Timeouts and potentially accepted POST errors remain unknown; the adapter makes no automatic retry and claims no exactly-once guarantee over the network.

## Remaining external release prerequisite

The sources establish the implemented shape, not account-specific availability or a successful real transaction. Before production release, an operator must verify the current status-1 acceptance semantics, account credit unit and limits, active approved template and parameter spelling, and one explicitly requested paid test to a controlled number. `ANI_SMS_LIVE_ENABLED` defaults off until that operational review. A live smoke test was intentionally not run without credentials and explicit real-message test configuration. The candidate is not labeled operationally certified.

## 1.2 text campaigns

`send_text()` uses the official PHP SDK bulkSend contract at fixed POST /v1/send/bulk with lineNumber, messageText, one-item mobiles and sendDateTime:null. Acceptance requires one positive integer data.messageIds item. HTTP ambiguity stays unknown without retry. Text capability is now implemented, solely for explicitly approved consented campaigns; earlier OTP-only scope statements describe the 1.0 baseline. See MARKETING.md.
