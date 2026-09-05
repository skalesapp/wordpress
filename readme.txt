=== Skales Connector ===
Contributors: skalesapp
Tags: ai, content management, elementor, woocommerce, automation
Requires at least: 5.6
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 2.2.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Run your site from Skales on your computer or your phone: posts, pages, media, menus, widgets, settings, permalinks, comments and design. No third-party service.

== Description ==

Skales Connector opens a REST namespace on your site that Skales talks to, from
the desktop app on your computer or from the app on your phone. Skales runs on
your own device, so the connection is between that device and your server, and
nothing is sent to anyone else.

Connecting works with any Skales desktop from v10.0.3 on and with Skales on a
phone from 2.6.0 on. The full set of endpoints listed below needs **Skales
Desktop 12.7.2 or newer** or **Skales Mobile 2.7.3 or newer**; older builds
connect and work, they simply know fewer of them.

The plugin covers the work a content manager does:

* Posts and pages: create, read, update, trash, delete, schedule
* Categories and tags: create, rename, reparent, delete, assign
* Media: upload, replace alt text and captions, delete, set a featured image
* Gutenberg: build valid block markup from a structured description
* Elementor: build and update pages in the Flexbox Container format
* Menus: create, fill, order, nest and assign to theme locations
* Widgets: add, configure, move between sidebars, remove
* Customizer: theme mods, custom CSS, logo, site icon, title and tagline
* Block themes: global styles (colours, typography, layout)
* Settings: the General, Writing, Reading and Discussion screens
* Permalinks: structure, category base, tag base, with a rewrite flush
* Comments: approve, hold, spam, trash, reply
* SEO: RankMath and Yoast meta, including the canonical URL
* WooCommerce: list products, bulk price changes by category
* Caches: clear WP Super Cache, W3 Total Cache, LiteSpeed and WP Rocket

= You say it in the chat =

There is no second WordPress screen to learn. Once the plugin is installed and
the site is connected, you work on the site in the same conversation you use for
everything else:

* "Write a post about our summer opening hours and publish it."
* "Build me a landing page for the new course in Elementor, three sections, hero with the mountain photo."
* "Put last week's photos into the media library and use the widest one as the featured image."
* "Make the site headline font larger and the accent colour the green from our logo."
* "Add the new course to the main menu, right after Courses."
* "List my drafts." / "Update the pricing page." / "Clean up the tags."
* "Approve the comments that are waiting and reply to the one asking about parking."

Reading your site happens without asking. Publishing, updating, deleting and
anything that touches settings, permalinks or design asks you first.

= How permissions work =

The plugin generates one token on activation and stores only its SHA-256 hash.
The token is linked to a real WordPress account, by default the administrator
who installed the plugin. Every request is checked twice: the token must match,
and the linked account must hold the capability the endpoint needs. Pointing the
connector at an editor account is a supported way to fence it in; it will then be
refused on settings, permalinks and design.

Every change is attributed to that account, so revisions, authorship and the
comment log read exactly as they would if a person had done the work in wp-admin.

= Plugin and theme management is read only =

Skales can see which plugins and themes are installed, and which have updates,
but it cannot install, activate or update them. Installing code from a remote
call would turn a leaked token into remote code execution, so that click stays
with a human in wp-admin.

= Abilities API =

On WordPress 6.9 and later the plugin also registers its main operations with
the core Abilities API, so other AI tooling on the site, including the WordPress
MCP adapter, can discover and use them. Abilities run as the logged-in user and
are checked with normal capabilities; they do not use the Skales token.

== Installation ==

1. Upload the `skales-connector` folder to `/wp-content/plugins/`, or install the
   zip through Plugins, Add New, Upload Plugin
2. Activate the plugin
3. Open the Skales screen in wp-admin and copy the API token
4. In Skales, open Settings, Integrations, WordPress, and paste the token and
   your site URL

Upgrading from 1.x keeps your existing token and needs no reconnection.

**Upgrading from v1.0.0 or v1.1.0:** if you see two Skales plugins listed,
deactivate and delete the old one (`skales-wordpress`). The current version
deactivates old copies on activation and preserves your token.

== Frequently Asked Questions ==

= Does this plugin send my content anywhere? =

No. It contacts no external service. It only answers requests that arrive with
your token, and those come from your own Skales installation. There is no
tracking, no analytics and no telemetry.

= What happens if my token leaks? =

Open the Skales screen and press Regenerate token. The old token stops working
immediately. A leaked token can never exceed the capabilities of the linked
WordPress account, so linking the connector to an editor limits the blast radius.

= Can Skales download an image from the web into my library? =

Yes, when you ask it to. Remote fetches go through the WordPress safe HTTP API,
which refuses private and loopback addresses, and the bytes are validated
against an allowlist of media types before anything is stored.

= Does it work with block themes? =

Yes. On a block theme the connector reports `global-styles` as the design
surface and writes global styles; on a classic theme it writes theme mods.

== Screenshots ==

1. The Skales screen in wp-admin: connection status, the WordPress account the
   connector acts as, and the plugin version
2. The permissions section, where unfiltered HTML can be turned off
3. Detected plugins and the capabilities each one contributes
4. Desktop control: a job running in the Skales window on your own computer,
   the tool step it took visible in the chat, and the published post beside it
   in wp-admin with its featured image and its SEO meta set
5. Mobile control: the Skales app on a phone running the same job against the
   site directly. The site address and the token live on the phone, and no
   computer has to be running anywhere.
6. Schedules: a recurring job that writes and publishes on its own, here every
   morning at seven

== Changelog ==

= 2.2.0 =
* The handshake judges the phone as well as the computer. Skales on a phone
  names itself with a platform prefix (`mobile-2.9.26`), and the connector
  answered "unknown" to that because it only knew how to read a desktop
  number. Each platform is now compared against its own minimum, `/connect`
  reports `requires_mobile` next to `requires_desktop`, and `client` names the
  platform it judged. A client that names no platform is still read as the
  desktop, so nothing changes for existing installations
* Abilities carry the `public` flag WordPress 7.1 introduced, so one
  registration is exposed on every channel a client may use. `show_in_rest`
  stays in place for 6.9 and 7.0
* Tested up to WordPress 7.1
* The description says what has been true since Skales Mobile 2.6.0: the phone
  talks to the site itself, with no paired computer in between
* Route set checked against Skales Desktop 12.9.26 and Skales Mobile 2.9.26:
  every call either side makes has its endpoint here, nothing new is needed

= 2.1.0 =
* Security: Elementor pages built through `/elementor/page` now pass their
  widget, section and column settings through the same HTML filter as every
  other write path. They previously went into the page unfiltered, which
  ignored both the account's `unfiltered_html` capability and the "Allow
  unfiltered HTML" switch on the Skales screen. Link targets are refused when
  they are not http, https or a site relative address, whatever the switch says
* Security: site wide custom CSS now requires `edit_css`, the capability
  WordPress itself asks for, instead of the weaker `edit_theme_options`
* Security: global styles are validated through WordPress' own theme.json
  handling before they are stored, instead of being written raw and cleaned up
  by whoever reads them
* Security: reading a single post or page now checks that the linked account
  may see that particular item, the way updating and deleting already did
* The generated token is no longer kept in plain text in the options table
  indefinitely. It is held for one hour so it can be copied once, and the
  screen says how to get a new one when that hour has passed
* The handshake answers the version question: `/connect` returns
  `connector_version`, an `api_level` that names the route set, and the oldest
  desktop that can drive it. Every response carries the version as a header
* A valid token is no longer refused by a "REST API for logged in users only"
  hardening rule. The rule stays in force for every other namespace and for
  every request without a matching token
* The Authorization header is also read from the server variables Apache leaves
  behind in CGI and FastCGI mode, where the header would otherwise disappear
  and a good token would look missing
* Fixed: an Elementor heading, button or image widget sent without settings
  ended the request with a PHP fatal error
* Fixed: backslashes in page content, excerpts, titles and custom fields were
  swallowed, which broke CSS escapes and regular expressions in generated pages
* Fixed: `/pages/{id}` accepted a post id and `/posts/{id}` accepted a page id
* Widget update and delete now verify that the widget type in the URL is
  registered, the way creating one already did
* `default_role` can no longer be set to a role that administers the site
* Uploads are refused before anything is written when they exceed what the site
  accepts, and `svg` joins `svgz` on the blocked extension list
* `custom_css_post_id` is refused as a theme mod; the CSS has its own endpoint
* The permalink structure is validated by one rule for the REST route and the
  ability, instead of two that had drifted apart
* Compatibility verified with Skales Desktop 12.7.2 through 12.8.4

= 2.0.0 =
* Content manager release. The connector now covers menus, widgets, the
  Customizer, global styles for block themes, site settings, the permalink
  structure, comment moderation, categories and tags, and media management,
  in addition to the posts, pages, Elementor, SEO and WooCommerce endpoints
  that were already there
* Authentication is now bound to a WordPress account. The token still gates
  every request, and on top of that each endpoint runs the capability check
  that wp-admin would run. Linking the connector to a lower privileged account
  now genuinely restricts it
* Fixed: uploading media returned a fatal error on every call. The MIME
  validation added in 1.2.x used a function that WordPress does not load during
  a REST request, so `/media` answered HTTP 500 on every upload
* Fixed: posts and pages created by the connector had no author. They now carry
  the linked account, which restores authorship, revision history and author
  schema
* Fixed: cache plugins were reported as capable even when they were not
  installed
* Uploads whose extension disagrees with their contents are renamed to the real
  type instead of being stored under a misleading name. SVG is no longer in the
  accepted list
* Featured images can be set in one call from an attachment, a URL or raw bytes
* Posts can be scheduled by passing a future date
* Gutenberg block markup can be generated from a structured description and
  validated
* Registers its operations with the core Abilities API on WordPress 6.9+
* Plugin and theme endpoints are read only by design
* Licence moved to GPLv2 or later
* Verified against WordPress 7.0.3, PHP 8.3, Elementor 4.2.2 and Yoast SEO

= 1.3.1 =
* Rebuilt the distribution zip with proper packaging: plugin folder structure, readme.txt included, no system clutter (plugin metadata now shows correctly after install)
* Compatibility verified with Skales Desktop through v11.2.7 "Reliance"
* No functional changes to the plugin code

= 1.3.0 =
* Added WP Rocket to /connect capabilities payload (previously only cleared, not reported)
* Compatibility verified with Skales Desktop v10.0.3 through v10.1.0 "Design"
* Plugin header: added Tested up to and License URI fields
* Documentation refresh
* No connector-side changes required for Skales Desktop v10.1.0 alignment

= 1.2.1 =
* Minor internal fixes
* Version constant aligned with plugin header

= 1.2.0 =
* Fixed plugin version collision, old copies are auto-deactivated on activation
* Token is preserved across upgrades (no re-authentication needed)
* Admin notice warns if an old plugin copy is still active
* Renamed plugin folder from `skales-wordpress` to `skales-connector`

= 1.1.0 =
* Session 3 WordPress integration improvements

= 1.0.0 =
* Initial release

== Upgrade Notice ==

= 2.2.0 =
Maintenance release. The handshake now recognises Skales on a phone, abilities
carry the WordPress 7.1 public flag, tested up to 7.1. No change to any route,
your token is kept.

= 2.1.0 =
Security release. Elementor pages built through the connector were stored
without HTML filtering, which bypassed the unfiltered HTML setting; custom CSS
and global styles are now gated and validated the way WordPress gates them.
Recommended for every site that has Elementor installed. Your token is kept.

= 2.0.0 =
Media upload was returning a fatal error in every 1.x version and is fixed here.
Requests are now capability checked against a linked WordPress account, and new
content carries a real author. Your token is preserved.
