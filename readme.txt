=== Skales Connector ===
Contributors: skalesapp
Tags: ai, automation, elementor, woocommerce, desktop agent
Requires at least: 5.6
Tested up to: 6.8
Requires PHP: 7.4
Stable tag: 1.2.0
License: MIT

Connect your WordPress site to Skales Desktop AI Agent.

== Description ==

Skales Connector lets you manage your WordPress site from the
Skales desktop application. Build complete pages, manage WooCommerce
products, update SEO, upload media, and more — all through natural
language commands.

Features:
* Secure token-based authentication (SHA-256 hashed)
* Auto-detects installed plugins (Elementor, WooCommerce, RankMath, Yoast)
* Create and edit Elementor pages with full widget support
* Create plain HTML/CSS pages for non-Elementor sites
* WooCommerce bulk price updates by category
* SEO meta management (RankMath + Yoast)
* Cache clearing for all major cache plugins
* Media upload (images, videos, PDFs)

== Installation ==

1. Upload the `skales-connector` folder to `/wp-content/plugins/`
2. Activate the plugin through the 'Plugins' menu
3. Go to the Skales admin page and copy your API token
4. Enter the token in Skales Desktop > Settings > WordPress

**Upgrading from v1.0.0/v1.1.0:** If you see two Skales plugins listed,
deactivate and delete the old one (`skales-wordpress`). The new version
(`skales-connector`) will automatically deactivate old copies on activation
and preserve your existing API token.

== Changelog ==

= 1.2.0 =
* Fixed plugin version collision — old copies are auto-deactivated on activation
* Token is preserved across upgrades (no re-authentication needed)
* Admin notice warns if old plugin copy is still active
* Renamed plugin folder from `skales-wordpress` to `skales-connector`

= 1.1.0 =
* Session 3 WordPress integration improvements

= 1.0.0 =
* Initial release
