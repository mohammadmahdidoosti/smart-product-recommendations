# Smart Product Recommendations for WooCommerce

Product recommendations for large WooCommerce stores (built and tested for ~27,000 products), with a Persian/RTL admin.

- **Similar products**: weighted overlap of categories, brand, tags, attributes and title words (IDF) plus price closeness, pre-computed in the background.
- **Frequently bought together**: from WooCommerce Analytics order tables (HPOS compatible), shown as a bundle with live total and "add all to cart".
- **Recently viewed, for you, trending, cart and thank-you page** placements.
- Blocks load with one AJAX request per page, so page caches keep working.
- Admin: setup steps, live preview with real products, per-product match inspector, results dashboard, shortcode builder.
- Shortcode `[spr_recommendations]`, widget, REST API (`/wp-json/spr/v1/recommendations`), WP-CLI (`wp spr rebuild`).

## Install

Zip the folder (the zip must contain `smart-product-recommendations/` at its root) and upload it in **Plugins → Add New → Upload Plugin**, or copy the folder to `wp-content/plugins/`.

See `readme.txt` for details.
