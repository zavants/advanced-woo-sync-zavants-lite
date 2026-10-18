=== Advanced Woo Sync Lite ===
Contributors: zavants
Tags: woocommerce, stock, inventory, sync, multistore
Requires at least: 5.8
Tested up to: 6.6
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Real-time stock synchronization between two WooCommerce stores, by product category.

== Description ==

Advanced Woo Sync Lite keeps the stock of one product category in sync between two WooCommerce stores, in real time, matching products by SKU.

* Connect two WooCommerce stores (Central / Remote roles) with the WooCommerce REST API.
* Real-time stock sync (quantity, stock status, backorders) for the products of one category.
* Up to 50 products (a variable product and its variations count as one).
* Simple and variable products, background processing with Action Scheduler and automatic retries.
* Loop protection and stock logs.

== Installation ==

1. Install and activate the plugin in both stores.
2. In each store, create a WooCommerce REST API key with Read/Write permissions (WooCommerce > Settings > Advanced > REST API).
3. In Woo Sync Lite > Settings > Connection Options, set the role and the other store's URL and keys, then test the connection.
4. In the Stock Synchronization tab, enable stock sync and select the category to sync.

== Frequently Asked Questions ==

= What happens with the 51st product? =

Its stock changes are not synced and a notice is shown in the admin. Free the slots from the Stock Synchronization tab.

== Changelog ==

= 1.0.0 =
* First Lite release.
