# Integration tests — Phase 0 repro-harness

Integration tests run against a real `@wordpress/env` site with WooCommerce +
the Stripe/PayPal sandbox gateways. They reproduce the actual stuck-order state
and assert PaidRadar recovers it (and does **not** recover unpaid/refunded orders).

## Repro-harness (reproduce a real "paid but stuck" order)

1. `npx @wordpress/env start` (WooCommerce active; HPOS on).
2. Install the WooCommerce Stripe and/or PayPal Payments gateway; enter **sandbox** keys.
3. Create a test product and check out with a sandbox card / account.
4. **Block the confirmation webhook** so the payment succeeds but the order stays `pending`:
   - disable the webhook endpoint in the Stripe/PayPal dashboard, **or**
   - deny the webhook URL at the web server (e.g. `.htaccess` deny on the Stripe webhook route).
5. Confirm the order is stuck in `pending payment` while the gateway shows the charge as captured.

## Assertions

- **Recovery:** run a scan (`WooCommerce → PaidRadar → Check now`) → the stuck order
  moves to `processing`/`completed`, and an audit row `recovered` is written.
- **Counter-proof:** an unpaid order and a refunded order are **not** completed
  (audit rows `confirmed_unpaid` / `drift`).

Drive this flow with the `verify` skill (observe the real behaviour, not just assertions).
