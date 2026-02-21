# CS-Cart JTL Connector

This add-on exposes a **real JTL-Connector endpoint** (RPC-style) for CS-Cart Multi-Vendor.

It is **not** a WooCommerce "ghost shop" workaround and does not require extra middleware services.
It runs inside CS-Cart as a normal controller endpoint:

`/index.php?dispatch=jtl_connector.endpoint&company_id=<VENDOR_ID>`

## Requirements

- CS-Cart with PHP >= 8.1
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

## Supported (v0.1)

- GlobalData pull
- Category pull (global taxonomy)
- Product pull/push/delete (vendor catalog)
- CustomerOrder pull (vendor orders)
- StatusChange push (basic status update)

Use /lib/jtl_connector_runtime/features.json to toggle supported entities.

## Notes

- This is an MVP to get a compliant connector working.
- Product variations, images, advanced pricing, warehouses etc. are not implemented yet.
