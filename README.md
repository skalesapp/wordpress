# Skales Connector for WordPress

Connect your WordPress site to [Skales](https://skales.app) Desktop AI Agent. Manage pages, posts, media, WooCommerce, SEO and more through natural language.

> "Create a landing page for my product" — and Skales builds it. Full HTML/CSS, responsive, production-ready.

## What it does

Skales Connector turns your WordPress site into an AI-controllable workspace. Install the plugin, connect with a token, and manage everything from your desktop.

| Capability | How it works |
|---|---|
| **Pages & Posts** | Create, edit, delete pages and blog posts with full HTML/CSS/JS support |
| **Elementor** | Build pages with Flexbox Container format — sections, widgets, responsive design |
| **WooCommerce** | List products, bulk-update prices by category, manage inventory |
| **SEO** | Update RankMath and Yoast meta (title, description, focus keyword) |
| **Media** | Upload images, videos, PDFs via base64 to your media library |
| **Cache** | Clear WP Super Cache, W3 Total Cache, LiteSpeed, WP Rocket with one command |
| **Plugin Detection** | Auto-detects installed plugins and reports capabilities to Skales |

## Installation

1. Download `skales-connector.zip` from [Releases](https://github.com/skalesapp/wordpress/releases/latest)
2. WordPress Admin → Plugins → Add New → Upload Plugin → Select the zip
3. Activate the plugin
4. Go to the **Skales** menu in your WordPress admin panel
5. Copy the API token (shown once on activation)
6. In Skales Desktop → Settings → Integrations → WordPress: paste the token and your site URL

Upgrading? Your existing token is preserved — no need to reconnect.

## How it works

```
┌─────────────────┐         HTTPS + Bearer Token        ┌──────────────────┐
│  Skales Desktop  │ ──────────────────────────────────→ │  Your WordPress  │
│  (your machine)  │ ←────────────────────────────────── │  (any hosting)   │
└─────────────────┘         JSON REST API                └──────────────────┘
```

Skales Desktop connects to your WordPress site over HTTPS using the REST API. Authentication is via a Bearer token generated on activation and stored as a SHA-256 hash in your database. The raw token is shown once and never stored.

All communication happens from your desktop to your server. No data leaves your WordPress site to any third party.

## REST API Endpoints

All endpoints require `Authorization: Bearer <token>` header.

| Method | Endpoint | Description |
|---|---|---|
| `GET` | `/wp-json/skales/v1/connect` | Test connection, get site capabilities |
| `GET` | `/wp-json/skales/v1/pages` | List all pages |
| `POST` | `/wp-json/skales/v1/pages` | Create a page |
| `PUT` | `/wp-json/skales/v1/pages/{id}` | Update a page |
| `POST` | `/wp-json/skales/v1/posts` | Create a post |
| `PUT` | `/wp-json/skales/v1/posts/{id}` | Update a post |
| `POST` | `/wp-json/skales/v1/media` | Upload media (base64) |
| `POST` | `/wp-json/skales/v1/elementor/page` | Create Elementor page |
| `PUT` | `/wp-json/skales/v1/elementor/page/{id}` | Update Elementor page |
| `GET` | `/wp-json/skales/v1/woo/products` | List WooCommerce products |
| `PUT` | `/wp-json/skales/v1/woo/products/bulk-price` | Bulk price update |
| `PUT` | `/wp-json/skales/v1/seo/{id}` | Update SEO meta |
| `POST` | `/wp-json/skales/v1/cache/clear` | Clear all caches |

## Detected Plugins

The connector auto-detects and adapts to:

- **Elementor / Elementor Pro** — Page building with Flexbox Containers
- **WooCommerce** — Product and order management
- **RankMath SEO** — Meta title, description, focus keyword
- **Yoast SEO** — Meta title, description, focus keyword
- **WP Super Cache / W3 Total Cache / LiteSpeed / WP Rocket** — Cache clearing

## Security

- Token stored as SHA-256 hash (raw token never persisted after activation)
- All endpoints require valid Bearer token
- HTML sanitization disabled only for authenticated Skales API calls
- Old plugin versions auto-deactivated on upgrade to prevent conflicts
- No data sent to external services
- No tracking, no analytics, no telemetry

## Requirements

- WordPress 5.6+
- PHP 7.4+
- Skales Desktop (latest version recommended)

## License

MIT — use it however you want.

## Links

- [Skales Desktop](https://skales.app)
- [Documentation](https://skales.app/wordpress)
- [Report Issues](https://github.com/skalesapp/wordpress/issues)
