# PaidRadar – WooCommerce Payment Recovery & Stuck Order Fix

Self-hosted WooCommerce plugin that finds orders stuck in **pending / on-hold / failed** whose payment actually **succeeded at the gateway** (missed webhook), re-queries the gateway API **read-only**, completes them correctly via `WC_Order::payment_complete()`, writes a full audit trail, and alerts the admin.

**v1 gateways:** Stripe + PayPal (PPCP).

> This is *payment recovery*, not dunning. It repairs the **order state** after a missed webhook — it never chases the customer and never touches your accounting. Every gateway call is read-only; an order is completed **only** on an unambiguous PAID result with a transaction id.

## Requirements

- PHP 7.4+
- WordPress 6.2+
- WooCommerce (active) — provides Action Scheduler
- The official WooCommerce **Stripe** and/or **PayPal Payments** gateway (their stored API keys are reused read-only)

## Dev quickstart

```bash
# Boot a local WordPress + WooCommerce with this plugin active (HPOS enabled)
npx @wordpress/env start

# Install PHP dev deps (PHPUnit, Brain Monkey, WPCS)
composer install

# Run the unit tests
composer test        # or: ./vendor/bin/phpunit

# Coding-standards lint
composer lint
```

The site is served at `http://localhost:8888` (admin at `/wp-admin`, user/pass `admin` / `password`). Configure the plugin under **WooCommerce → PaidRadar**.

## How it works

```
Action Scheduler daily job (or "Check now" button)
  → Order_Scanner: wc_get_orders(status in [pending, on-hold, failed],
      created > lookback_days, has transaction id, supported gateway)
  → Reconciler, per order (behind an idempotency lock):
       adapter->fetch_status(order)  (read-only Stripe/PayPal call)
         PAID + txn id + not-yet-paid  → payment_complete(txn) + audit 'recovered' + alert
         REFUNDED / DISPUTED           → audit 'drift' + alert  (never completed)
         UNPAID                        → audit 'confirmed_unpaid'
         UNKNOWN / error / rate-limit  → audit 'check_failed' (retried next run)
```

Conservative by default: only an unambiguous PAID with a transaction id ever completes an order.

## Where the audit trail lives

A dedicated table **`{$wpdb->prefix}paidradar_log`** (e.g. `wp_paidradar_log`), created on activation via `dbDelta`. Columns: `order_id, gateway, event, psp_status, status_before, status_after, amount, currency, psp_response (JSON snapshot), actor (cron|manual), created_at`. View and export it (CSV) under **WooCommerce → PaidRadar**.

## Project layout

```
paidradar.php                 Bootstrap: header, HPOS declaration, autoloader, activation, boot
uninstall.php                 Opt-in data teardown
includes/
  class-paidradar.php         Singleton container / hook wiring
  class-activator.php         dbDelta table + default options
  adapters/                   Status_Adapter interface, Payment_Status VO, Stripe/PayPal, registry
  recovery/                   Order_Scanner, Reconciler, Recovery_Lock
  audit/                      Audit_Log (insert/query/CSV), Audit_List_Table
  scheduler/                  Action Scheduler registration + manual dispatch
  admin/                      Admin page (status card, settings, "Check now"), notices
tests/                        PHPUnit (Brain Monkey) unit tests + bootstrap
```

## HPOS / safety notes

- Declares `custom_order_tables` (HPOS) and `cart_checkout_blocks` compatibility.
- Orders are read/written only through `wc_get_orders()` / the `WC_Order` API — never direct `wp_posts` / `postmeta` SQL.
- Order meta is saved via `$order->save()`, never inside `woocommerce_pre_payment_complete`.
- Gateway calls are strictly read-only; rate-limit (HTTP 429) triggers a soft backoff (skip + retry next run).
