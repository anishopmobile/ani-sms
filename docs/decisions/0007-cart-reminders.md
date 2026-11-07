# Cart reminders: account intent and durable episode limits

Status: implemented candidate 1.5.0-rc1 under explicit owner delegation.

Only verified logged-in owners with current marketing consent and separate cart opt-in are eligible. Default inactivity is 60 minutes (15-8640 configurable), one attempt per episode and a durable seven-day account cooldown. Returning, submitting an order, emptying the cart or withdrawing consent invalidates pending work. A native authenticated cart link avoids bearer login/recovery tokens and automatic cart replacement.

Use permitted marketing-line bulk with mandatory unsubscribe and immutable local/live proof. Indexed schema 7, bounded encrypted queue snapshots, ordered subscriber/account locks, conditional consent-revision binding and completion reconciliation prevent stale or duplicate work. Unknown attempts consume cooldown. See ../CART.md and ../CART-TESTS.md for exact executed evidence and limits.

The former MXC default-runner error was bypassed using the host command shell. No production setting or sandbox safety control was disabled.
