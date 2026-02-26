# CS-Cart JTL Connector

This add-on exposes a **real JTL-Connector endpoint** (RPC-style) for CS-Cart (Multi-Vendor aware).

It is **not** a WooCommerce "ghost shop" workaround and does not require extra middleware services.
It runs inside CS-Cart as a normal controller endpoint:

`/index.php?dispatch=jtl_connector.endpoint&company_id=<VENDOR_ID>`

## Requirements

- CS-Cart with **PHP >= 8.1**
- Composer (for runtime deps)

## Install

1. Copy this add-on folder to your CS-Cart installation:
   `app/addons/jtl_connector/`
2. In CS-Cart admin: Add-ons -> Manage add-ons -> **JTL Connector** -> enable.
3. Install PHP deps:

```bash
cd app/addons/jtl_connector/lib/jtl_connector_runtime
composer install --no-dev
```

4. Vendor onboarding:
   - Vendor panel -> Add-ons -> JTL Connector
   - Enable connector + generate token
   - Copy Endpoint URL + Token into JTL-Wawi (URL + sync password)

## Supported (v0.4)

- GlobalData pull
- Category pull (global taxonomy)
- Image push (product + category images)
- Product pull/push/delete (vendor catalog)
  - Variation products + variation combinations enabled
  - If CS-Cart "Product Variations" add-on is active: best-effort grouping into variation groups
- CustomerOrder pull (vendor orders)
- StatusChange push (orderStatus + paymentStatus mapping)

Additionally:
- Request audit log + basic per-vendor rate limiting
- Optional debug + verbose payload samples (off by default)
  - Captures **structured samples** per entity push (e.g. Product/Category/StatusChange)
  - Samples are **redacted** by default (tokens/emails/password-like fields)
  - Samples are visible in the admin backend (no DB access needed)
- Watchdog (alerts admin on repeated errors / prolonged idle)
  - Creates backend alerts + best-effort email
- Internal scheduler (traffic-based, best-effort)
  - Runs watchdog/pruner without cron when the shop gets traffic
- Token-protected cron URLs
  - Can be called by external schedulers (curl/cron-job.org/UptimeRobot etc.)

Use `/lib/jtl_connector_runtime/features.json` to toggle supported entities (e.g. enable Category push for a Wawi-led taxonomy).

## Notes

- This is intentionally lean and pragmatic.
- Variation sync is enabled. If "Product Variations" is active, grouping is attempted automatically.
- Image sync is implemented best-effort via CS-Cart's image subsystem; if your shop has a customized image pipeline,
  you may need to adjust the controller implementation.

## Watchdog

Watchdog updates its state on every endpoint request. For "idle" alerts (no sync for N minutes), you should run
the watchdog tick periodically (cron), e.g. every 10 minutes:

```bash
php app/addons/jtl_connector/cli/watchdog_tick.php
```

This is optional; repeated failures are still detected immediately at request time.

### No cron available?

- Enable **Internal scheduler (traffic-based)** in the add-on settings or from the JTL Connector admin page.
  It runs watchdog/pruner when the shop gets traffic (best-effort).
- Or call the **token-protected cron URLs** from an external scheduler:
  - `dispatch=jtl_connector.cron&task=watchdog_tick&token=...`
  - `dispatch=jtl_connector.cron&task=prune_logs&token=...`
  You can copy the URLs from the JTL Connector admin page.

## Log pruning (recommended)

To keep tables small in long-running installations, run the pruner daily:

```bash
php app/addons/jtl_connector/cli/prune_logs.php
```

Retention windows are controlled via add-on settings:
- `retain_request_log_days`
- `retain_debug_event_days`
- `retain_rate_limit_days`
- `retain_payload_samples_days`
- `retain_admin_alert_days`
- `retain_admin_alert_read_days`

Set a value to `0` to keep forever.
