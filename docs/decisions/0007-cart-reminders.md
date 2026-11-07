# Cart reminders: account opt-in and durable episode limits

Status: contract accepted under explicit owner delegation; implementation pending.

Only verified logged-in owners with current marketing consent and separate cart opt-in are eligible. Default inactivity is 60 minutes, one attempt per episode and a durable seven-day account cooldown. Returning, submitting an order, emptying the cart or withdrawing consent invalidates pending work. A native authenticated cart link avoids bearer recovery/login tokens and automatic cart replacement. Use marketing-line bulk messages with mandatory unsubscribe and immutable local/live proof. See ../CART.md and ../CART-TESTS.md. No tests or implementation are claimed by this ADR.
