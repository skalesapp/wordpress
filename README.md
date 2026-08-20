# Skales Connector for WordPress

Run your WordPress site from [Skales](https://skales.app). Posts, pages, media,
menus, widgets, settings, permalinks, comments and design, driven from the
desktop app on your own machine.

**Plugin v2.0.0** · WordPress 5.6+ · Tested up to WordPress 7.0 · PHP 7.4+ ·
GPLv2 or later

> "Research tomorrow's top news, write an SEO post, and put a fitting image on
> it" is one chain of calls against this plugin.

## What it covers

| Area | What the connector can do |
|---|---|
| **Posts and pages** | Create, read, update, trash, delete, schedule, set slug, excerpt, parent, template, author, sticky |
| **Categories and tags** | List, create, rename, reparent, delete, assign by id or by name (missing terms are created) |
| **Media** | Upload from base64 or a URL, list, edit alt text and captions, delete |
| **Featured images** | One call from an attachment id, a URL or raw bytes |
| **Gutenberg** | Serialize a block description into valid block markup, validate existing markup, reusable blocks |
| **Elementor** | Build and update pages in the Flexbox Container format |
| **Menus** | Create, fill, nest, order, assign to theme locations, delete |
| **Widgets** | List sidebars and widget types, add, configure, move, remove |
| **Customizer** | Theme mods, custom CSS, logo, site icon, title, tagline |
| **Block themes** | Global styles: colours, typography, layout |
| **Settings** | General, Writing, Reading and Discussion, through a strict allowlist |
| **Permalinks** | Structure, category base, tag base, with a rewrite flush |
| **Comments** | List by status, approve, hold, spam, trash, reply |
| **SEO** | RankMath and Yoast title, description, focus keyword, canonical |
| **WooCommerce** | List products, bulk price changes by category |
| **Plugins and themes** | Read only inventory with update status |
| **Caches** | WP Super Cache, W3 Total Cache, LiteSpeed, WP Rocket |

## Installation

1. Download `skales-connector.zip` from
   [Releases](https://github.com/skalesapp/wordpress/releases/latest)
2. WordPress Admin, Plugins, Add New, Upload Plugin, select the zip
3. Activate
4. Open the **Skales** menu in wp-admin and copy the API token
5. In Skales, Settings, Integrations, WordPress: paste the token and the site URL

Upgrading keeps your existing token, no reconnection needed.

## How authentication works

```
┌──────────────────┐      HTTPS + Bearer token       ┌──────────────────┐
│  Skales desktop  │ ──────────────────────────────► │  Your WordPress  │
│  (your machine)  │ ◄────────────────────────────── │  (your hosting)  │
└──────────────────┘            JSON REST            └──────────────────┘
```

One token is generated on activation. Only its SHA-256 hash is stored, and the
raw value is shown once. The token is linked to a real WordPress account, by
default the administrator who installed the plugin.

Every request is checked twice:

1. The Bearer token must match the stored hash (constant time comparison).
2. The linked account must hold the capability the endpoint needs. Settings,
   permalinks and design need `manage_options` or `edit_theme_options`; content
   needs `edit_posts` or `publish_posts`; media needs `upload_files`.

Because the request runs as that account, everything the connector writes gets a
real author, real revisions and a real entry in the comment log. Pointing the
connector at an editor account is a supported way to restrict it.

Plugin and theme endpoints are read only on purpose: installing code from a
remote call would turn a leaked token into remote code execution.

## REST endpoints

All endpoints live under `/wp-json/skales/v1/` and require
`Authorization: Bearer <token>`.

| Method | Endpoint | Capability |
|---|---|---|
| `GET` | `/connect` | token |
| `GET` `POST` | `/posts` | `edit_posts` / `publish_posts` |
| `GET` `PUT` `DELETE` | `/posts/{id}` | `edit_posts` / `delete_posts` |
| `GET` `POST` | `/pages` | `edit_pages` / `publish_pages` |
| `GET` `PUT` `DELETE` | `/pages/{id}` | `edit_pages` / `delete_pages` |
| `GET` | `/types` | `edit_posts` |
| `GET` `POST` | `/terms` | `edit_posts` / `manage_categories` |
| `PUT` `DELETE` | `/terms/{id}` | `manage_categories` |
| `GET` `POST` | `/comments` | `moderate_comments` |
| `PUT` `DELETE` | `/comments/{id}` | `moderate_comments` |
| `POST` | `/blocks/serialize` | `edit_posts` |
| `POST` | `/blocks/validate` | `edit_posts` |
| `GET` `POST` | `/blocks/reusable` | `edit_posts` / `publish_posts` |
| `GET` `POST` | `/media` | `upload_files` |
| `PUT` `DELETE` | `/media/{id}` | `upload_files` |
| `POST` | `/featured-image` | `upload_files` |
| `GET` `PUT` | `/settings` | `manage_options` |
| `GET` `PUT` | `/permalinks` | `manage_options` |
| `GET` | `/plugins` | `activate_plugins` (read only) |
| `GET` | `/themes` | `switch_themes` (read only) |
| `GET` | `/users` | `list_users` (read only) |
| `GET` | `/theme` | `edit_theme_options` |
| `GET` `PUT` | `/theme/mods` | `edit_theme_options` |
| `GET` `PUT` | `/theme/css` | `edit_theme_options` |
| `GET` `PUT` | `/theme/global-styles` | `edit_theme_options` |
| `GET` `PUT` | `/site-identity` | `edit_theme_options` |
| `GET` `POST` | `/menus` | `edit_theme_options` |
| `PUT` `DELETE` | `/menus/{id}` | `edit_theme_options` |
| `GET` `POST` | `/widgets` | `edit_theme_options` |
| `PUT` `DELETE` | `/widgets/{id}` | `edit_theme_options` |
| `POST` | `/elementor/page` | `publish_pages` |
| `PUT` | `/elementor/page/{id}` | `edit_pages` |
| `GET` `PUT` | `/seo/{id}` | `edit_posts` |
| `GET` | `/woo/products` | `edit_posts` |
| `PUT` | `/woo/products/bulk-price` | `manage_options` |
| `POST` | `/cache/clear` | `manage_options` |

## Abilities API

On WordPress 6.9 and later the plugin registers its main operations with the
core Abilities API (`skales/create-post`, `skales/update-post`,
`skales/list-content`, `skales/set-featured-image`, `skales/update-permalinks`,
`skales/update-design`). Anything that speaks Abilities, including the WordPress
MCP adapter, can then discover and use them.

Abilities run as the logged-in WordPress user and are checked with normal
capabilities. They never consult the Skales token, and the token never grants
access to abilities.

## Detected plugins

The connector adapts to Elementor and Elementor Pro, WooCommerce, RankMath SEO,
Yoast SEO, WP Super Cache, W3 Total Cache, LiteSpeed Cache, WP Rocket, Contact
Form 7 and WPForms. Capabilities are only announced for plugins that are
actually active.

## Privacy

The plugin contacts no external service on its own. It answers requests that
carry your token, and it fetches a remote file only when you explicitly ask it
to import an image by URL, through the WordPress safe HTTP API. There is no
tracking, no analytics and no telemetry.

## Repository layout

```
skales-connector.php      plugin header, activation, route registration
includes/auth.php         token check, account binding, capability callbacks
includes/helpers.php      payload normalisation, terms, block serialisation
includes/capabilities.php the /connect capability report
includes/routes-*.php     endpoints by area
includes/abilities.php    Abilities API registration
includes/admin.php        the Skales screen in wp-admin
includes/frontend.php     full width CSS for Skales built pages
build-zip.sh              builds the distributable zip from the tracked files
```

## Building a release zip

```bash
./build-zip.sh
```

The script copies only the files the plugin needs into `skales-connector/`,
checks that the version in the header, the constant and `readme.txt` agree, and
writes `dist/skales-connector.zip`.

## Requirements

- WordPress 5.6 or later
- PHP 7.4 or later
- Skales desktop app

## License

GPLv2 or later. See [LICENSE](LICENSE).

## Links

- [Skales](https://skales.app)
- [Documentation](https://docs.skales.app/#wordpress)
- [Issues](https://github.com/skalesapp/wordpress/issues)
