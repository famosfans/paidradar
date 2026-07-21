=== OrderMend – WooCommerce Payment Recovery & Stuck Order Fix ===
Contributors: famosmedia
Tags: woocommerce, payment recovery, stuck pending order, stripe, paypal
Requires at least: 6.2
Tested up to: 6.6
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Recover paid-but-stuck WooCommerce orders. When a webhook is missed, OrderMend re-queries Stripe & PayPal, completes the order correctly, and logs it.

== Description ==

**The gateway charged the customer — but your WooCommerce order is still stuck on "pending payment".** This happens when the confirmation webhook never arrives (firewall, timeout, HTTP 204, live/test key mix-up, a crash right after checkout). The money is captured, the order never fulfils, and you only notice at month-end.

**OrderMend** fixes exactly this. It scans your WooCommerce orders that are stuck in *pending*, *on-hold* or *failed*, re-queries the payment gateway's API (read-only), and — only when the gateway **unambiguously** reports the payment as captured — completes the order the correct way via WooCommerce's own `payment_complete()` flow (stock, status and emails all fire correctly). Every action is written to a full **payment audit trail** and the admin is alerted.

This is **payment recovery**, not dunning: it does not chase the customer for a payment that already succeeded, and it does not touch your accounting. It repairs the *order state* after a **missed webhook**.

= What it does =

* Finds WooCommerce orders **stuck in pending / on-hold / failed** that carry a gateway transaction id.
* Re-queries **Stripe** (PaymentIntent) and **PayPal** (Orders API v2) — **read-only**, no money is ever moved.
* Completes only **unambiguously paid** orders via `payment_complete()` (never a blind status change).
* Never completes **refunded** or **disputed** payments — it flags the drift and alerts you instead.
* Writes a **lasting payment audit trail** (gateway response snapshot, before/after status, actor, timestamp).
* Runs a **daily automatic scan** (Action Scheduler) plus a manual **"Check now"** button.
* **Idempotent** and **rate-limited** — never double-completes an order, backs off on gateway rate limits.
* **HPOS-compatible** and Cart/Checkout-Blocks compatible.

= Why OrderMend is different =

Reconciliation plugins push *correct* orders into your accounting. Dunning plugins email customers to *retry* a payment. Auto-complete plugins only finish orders WooCommerce already knows are paid. **None of them recover an order that was actually paid at the gateway but got stuck because the webhook was missed.** OrderMend does.

= Supported gateways (v1) =

* Stripe (official WooCommerce Stripe gateway)
* PayPal (WooCommerce PayPal Payments / PPCP)

= Privacy =

Self-hosted. OrderMend uses the API keys already stored by your Stripe / PayPal plugin to make read-only status calls. No data leaves your store for any third-party OrderMend service — there is none.

== Installation ==

1. Upload the `ordermend` folder to `/wp-content/plugins/` or install via the Plugins screen.
2. Activate **OrderMend** through the *Plugins* screen. WooCommerce must be active.
3. Go to **WooCommerce → OrderMend**.
4. Confirm your gateways are enabled, set the look-back window and alert e-mail.
5. Click **Check now** for an immediate scan, or let the daily scan run automatically.

== Frequently Asked Questions ==

= Why is my WooCommerce order stuck on pending after the customer paid? =

Almost always a missed webhook/IPN: the gateway charged the card and tried to notify WooCommerce, but the notification never arrived or was rejected (a security plugin or firewall blocked the endpoint, a timeout, an HTTP 204, or a live/test key mismatch). The payment succeeded; WooCommerce just never heard about it. OrderMend asks the gateway directly and repairs the order.

= Will OrderMend ever complete an order that was not actually paid? =

No. It is conservative by default: an order is only completed when the gateway reports the payment as unambiguously captured **and** a transaction id is present. Unpaid, refunded, disputed or unknown results are logged and (where relevant) alerted, never completed.

= Does it move money, issue refunds or capture payments? =

No. Every gateway call is strictly read-only. OrderMend only reads the payment status; it never captures, refunds or voids.

= Which gateways are supported? =

Stripe and PayPal (PPCP) in this free version. More gateways are planned.

= Is it compatible with High-Performance Order Storage (HPOS)? =

Yes. OrderMend declares HPOS and Cart/Checkout-Blocks compatibility and only uses the official `wc_get_orders()` / `WC_Order` API — never direct database queries against orders.

= Where is the audit trail stored? =

In a dedicated table, `wp_ordermend_log` (prefix may differ). You can view it under **WooCommerce → OrderMend** and export it as CSV.

= Does it chase customers to pay again? =

No. That is dunning / failed-order-rescue, and it is the wrong tool when the customer already paid. OrderMend handles the *already-paid-but-stuck* case.

== Screenshots ==

1. OrderMend dashboard: last run, total recovered, and the "Check now" button.
2. The payment audit trail with per-order events and status transitions.
3. Settings: look-back window, enabled gateways and alert e-mail.

== Changelog ==

= 1.0.0 =
* Initial release: Stripe + PayPal read-only status recovery, conservative `payment_complete()` reconciliation, audit trail, daily Action Scheduler scan, manual "Check now", admin alerts, HPOS compatibility.

== Upgrade Notice ==

= 1.0.0 =
Initial release.
