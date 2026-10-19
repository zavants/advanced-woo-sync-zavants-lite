# Advanced Woo Sync Lite

**Keep the stock of two WooCommerce stores in sync, in real time, without spreadsheets or manual updates.**

Sell the same products in two stores and never oversell again. When a product's stock changes in one store, Advanced Woo Sync Lite updates it in the other one within seconds, matching products by SKU.

![WordPress](https://img.shields.io/badge/WordPress-5.8%2B-21759b?logo=wordpress&logoColor=white)
![WooCommerce](https://img.shields.io/badge/WooCommerce-6.0%2B-96588a?logo=woocommerce&logoColor=white)
![PHP](https://img.shields.io/badge/PHP-7.4%2B-777bb4?logo=php&logoColor=white)
![HPOS](https://img.shields.io/badge/HPOS-compatible-2ea44f)
![License](https://img.shields.io/badge/license-GPLv2%2B-blue)

---

## Why Advanced Woo Sync Lite

Running two WooCommerce stores usually means updating stock twice, or finding out too late that a product sold out in one store while it was still for sale in the other. Advanced Woo Sync Lite removes that work:

- **Real-time stock.** Every sale, refund, manual edit or bulk edit is pushed to the other store automatically.
- **No duplicated work.** Manage your inventory in one place and let the plugin keep the other store up to date.
- **Fewer cancelled orders.** Customers only see what you can really ship.
- **Set up in minutes.** Uses the WooCommerce REST API you already have. No external service and no monthly fee.

## Features

| | |
|---|---|
| 🔄 **Real-time stock sync** | Quantity, stock status (in stock, out of stock, on backorder) and backorder settings are replicated in seconds. |
| 🗂️ **Sync by category** | Choose one product category and keep up to **50 products** in sync. A variable product and all its variations count as one product. |
| 🔗 **SKU matching** | Products are matched by SKU between stores, so IDs and names can be different in each store. |
| 🧩 **Simple and variable products** | Variations are matched one by one through their own SKU. |
| ⚙️ **Background processing** | Changes are queued with Action Scheduler, so saving a product or completing a checkout never waits for the other store. |
| 🔁 **Automatic retries** | If the other store is temporarily unreachable, the update is retried automatically. |
| 🛡️ **Loop protection** | Changes received from the other store are never sent back, so the two stores never bounce updates between each other. |
| 🏬 **Central / Remote roles** | Define which store is the central one and which is the remote one, and test the connection from the settings page. |
| 📋 **Stock logs** | Every sync is logged with its result, with search, filters and three log levels (simple, normal, detailed). |
| ⚡ **HPOS compatible** | Works with WooCommerce High-Performance Order Storage. |

## How it works

```
   Store A                                    Store B
 ┌──────────────┐   stock changes (SKU)   ┌──────────────┐
 │ WooCommerce  │ ──────────────────────▶ │ WooCommerce  │
 │ + Woo Sync   │      REST API, in       │ + Woo Sync   │
 │   Lite       │      the background     │   Lite       │
 └──────────────┘                         └──────────────┘
```

1. A product in the selected category changes its stock in Store A.
2. The change is queued in the background a few seconds later, so bursts of changes are sent as one update.
3. The plugin finds the product with the same SKU in Store B and updates its stock.
4. The result is written to the stock log.

## Getting started

1. **Install** the plugin in both stores: copy this folder to `wp-content/plugins/` (or upload the zip from *Plugins → Add New → Upload Plugin*) and activate it.
2. **Create API keys** in each store with *Read/Write* permissions: *WooCommerce → Settings → Advanced → REST API*.
3. **Connect the stores** in *Woo Sync Lite → Settings → Connection Options*: choose the store role, enter the other store's URL and keys, save and click *Test Connection*.
4. **Turn on stock sync** in the *Stock Synchronization* tab and select the category to sync.

That's it. From now on, stock changes in that category reach the other store automatically.

## Requirements

- WordPress 5.8 or later
- WooCommerce 6.0 or later
- PHP 7.4 or later
- Both stores reachable over HTTPS, with the plugin installed and active in each one
- Products identified by the same SKU in both stores

## FAQ

**Do I need to install the plugin in both stores?**
Yes. Each store needs the plugin active and a WooCommerce REST API key for the other store.

**What happens when the 50-product limit is reached?**
Products beyond the limit are not synced and a notice appears in the WordPress admin. You can free the product slots from the *Stock Synchronization* tab at any time.

**Does it work with variable products?**
Yes. Each variation is matched by its own SKU, and the variable product with all its variations uses a single product slot.

**What if the other store is offline for a moment?**
The update is retried automatically, and every attempt is recorded in the stock logs.

## License

GPL v2 or later. See [LICENSE](LICENSE).

Developed by [Zavants](https://zavants.com).
