# Discount alerts: promotional consent and exact native sales

Status: accepted by owner delegation. Version 1.4.0-rc1.

Use separate product intent plus existing versioned marketing consent; never treat OTP or stock requests as permission for promotions. Use permitted bulk/text sending line, mandatory unsubscribe and immutable local/live modes. Native catalog sale snapshots avoid claiming coupon/tax guarantees. Bounded durable fanout, subscriber-before-product locking and current ownership checks prevent duplicate or withdrawn sends. See ../DISCOUNT.md.
