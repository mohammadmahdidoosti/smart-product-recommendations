=== Smart Product Recommendations for WooCommerce ===
Requires at least: 6.2
Requires PHP: 7.4
WC requires at least: 7.0
Stable tag: 1.1.0
License: GPLv2 or later

Fast product recommendations for large WooCommerce stores (tested with 27,000 products).

== Features ==

* Similar products — weighted overlap of categories, brand, tags, attributes and title words (IDF), plus price closeness. Persian/Arabic text normalization built in.
* Frequently bought together — from WooCommerce Analytics order tables (HPOS compatible).
* Recently viewed, personalized "For you", Trending (most viewed this week, then best sellers), Cart and Thank-you page recommendations.
* All heavy work is pre-computed in the background (Action Scheduler / WP-Cron), resumable, in batches.
* Blocks are loaded with one AJAX request per page, so page-cache plugins and CDNs keep working.
* Analytics: impressions, clicks, CTR, attributed orders and revenue per placement.
* Shortcode, classic widget, REST API (JSON), WP-CLI.
* Bundle layout for "bought together": live total, untick items, add all to cart in one click.
* Three card styles (cards, minimal, compact rows), brand accent color, corner radius; layouts adapt to the space they get (container queries), so they also work in sidebars.
* Admin with setup steps, live preview using real store products (desktop / mobile), per-product match inspector, results dashboard and shortcode builder. Settings save without reloading.
* RTL ready, Persian translation included, Vazirmatn font bundled (no Google Fonts).

== Installation ==

1. Upload the folder to /wp-content/plugins/ (or upload the zip in Plugins → Add New).
2. Activate. The first index starts automatically.
3. Go to WooCommerce → Recommendations.
4. For co-purchase data, WooCommerce Analytics must be enabled and historical data imported
   (Analytics → Settings → Import historical data).
5. Large catalog, first run: `wp spr rebuild` is the fastest way to build the index.

== Shortcode ==

[spr_recommendations source="similar|bought|viewed|for_you|trending|cart" limit="8" title="" product_id="" layout="grid|carousel" columns="4"]

== REST ==

GET /wp-json/spr/v1/recommendations?source=similar&product_id=123&limit=8
GET /wp-json/spr/v1/recommendations?source=for_you&viewed=12,45,78

== WP-CLI ==

wp spr rebuild     Build the full index synchronously
wp spr status      Index state
wp spr product 123 Recompute and print one product's recommendations
