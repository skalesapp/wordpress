<?php
/**
 * Plugin Name: Skales Connector
 * Plugin URI: https://skales.app/
 * Description: Connect your WordPress site to Skales on your computer or your phone. Manage posts, pages, media, menus, widgets, settings, permalinks, comments and design from your own device. No third-party service involved.
 * Version: 2.2.0
 * Author: Mario Simic
 * Author URI: https://mariosimic.at
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: skales-connector
 * Requires at least: 5.6
 * Tested up to: 7.1
 * Requires PHP: 7.4
 */

if (!defined('ABSPATH')) exit;

define('SKALES_VERSION', '2.2.0');
define('SKALES_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('SKALES_PLUGIN_FILE', __FILE__);

// The route set, as one number that only ever grows. A version string tells a
// client which release it is talking to; this tells it which endpoints exist,
// which is the question a client actually has. 1 is every 1.x plugin (posts,
// pages, media, Elementor, SEO, WooCommerce), 2 is the content manager set
// added in 2.0.0 (menus, widgets, settings, permalinks, terms, comments,
// blocks, design, featured image). A 1.x plugin sends neither key, and a
// missing api_level therefore means "1".
define('SKALES_API_LEVEL', 2);

// The oldest Skales build on each platform that can drive the current route
// set. Older builds connect and work, they simply know fewer endpoints. The
// two count differently (the desktop is at 12.x while the phone is at 2.x), so
// each platform is judged against its own number, never against the other's.
define('SKALES_MIN_DESKTOP', '12.7.2');
define('SKALES_MIN_MOBILE', '2.7.3');

// How long the freshly generated token stays readable in the database. It is
// meant to be copied once, right after activation; keeping it in plain text
// beyond that gains nobody anything and every other plugin on the site can
// read the options table.
define('SKALES_TOKEN_DISPLAY_TTL', HOUR_IN_SECONDS);

require_once SKALES_PLUGIN_DIR . 'includes/auth.php';
require_once SKALES_PLUGIN_DIR . 'includes/helpers.php';
require_once SKALES_PLUGIN_DIR . 'includes/capabilities.php';
require_once SKALES_PLUGIN_DIR . 'includes/routes-content.php';
require_once SKALES_PLUGIN_DIR . 'includes/routes-media.php';
require_once SKALES_PLUGIN_DIR . 'includes/routes-site.php';
require_once SKALES_PLUGIN_DIR . 'includes/routes-design.php';
require_once SKALES_PLUGIN_DIR . 'includes/routes-elementor.php';
require_once SKALES_PLUGIN_DIR . 'includes/routes-commerce.php';
require_once SKALES_PLUGIN_DIR . 'includes/routes-seo.php';
require_once SKALES_PLUGIN_DIR . 'includes/abilities.php';
require_once SKALES_PLUGIN_DIR . 'includes/frontend.php';
require_once SKALES_PLUGIN_DIR . 'includes/admin.php';

// =============================================================================
// ACTIVATION
// =============================================================================

register_activation_hook(__FILE__, 'skales_activate');
function skales_activate() {
    // Deactivate old versions that used a different folder/slug.
    // v1.0.0 to v1.1.0 shipped as "skales-wordpress/skales-connector.php", which
    // WordPress treats as a separate plugin from "skales-connector/skales-connector.php".
    $old_slugs = [
        'skales-wordpress/skales-connector.php',
        'skales-wordpress/skales-wordpress.php',
        'skales-connector-old/skales-connector.php',
    ];
    if (!function_exists('is_plugin_active')) {
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
    }
    foreach ($old_slugs as $old_slug) {
        if (is_plugin_active($old_slug)) {
            deactivate_plugins($old_slug);
        }
    }

    // Preserve an existing token across upgrades (do not regenerate).
    $existing_hash = get_option('skales_api_token_hash');
    if (!$existing_hash) {
        $token = wp_generate_password(48, false);
        update_option('skales_api_token_hash', hash('sha256', $token));
        skales_hold_token_for_display($token);
        update_option('skales_connected', false);
    }

    // Bind the connector to the account that installed it. Every write the
    // connector performs is attributed to and capability-checked against this
    // user, so remote requests can never exceed what that account may do.
    $current = get_current_user_id();
    if ($current && user_can($current, 'manage_options')) {
        update_option('skales_owner_id', $current);
    } else {
        skales_owner_id(); // resolves and stores the first administrator
    }

    update_option('skales_plugin_version', SKALES_VERSION);
    // Permalink endpoints add no rewrite rules of their own, but a fresh
    // activation should leave the rule cache consistent.
    flush_rewrite_rules(false);
}

/**
 * Park a freshly generated token where the admin screen can show it once.
 *
 * A transient, not an option: the value expires on its own, so a site whose
 * owner never opens the Skales screen does not keep a working token in plain
 * text in wp_options for the rest of its life.
 *
 * @param string $token
 * @return void
 */
function skales_hold_token_for_display($token) {
    set_transient('skales_api_token_display', $token, SKALES_TOKEN_DISPLAY_TTL);
}

/**
 * The token waiting to be shown once, if there is one.
 *
 * @return string
 */
function skales_token_for_display() {
    $token = get_transient('skales_api_token_display');
    return is_string($token) ? $token : '';
}

/**
 * @return void
 */
function skales_forget_token_display() {
    delete_transient('skales_api_token_display');
    delete_option('skales_api_token_display');
}

// An update that arrives through the WordPress updater never fires the
// activation hook, so the version bookkeeping and any data migration happen on
// the first admin request after the new files are in place.
add_action('admin_init', 'skales_maybe_upgrade');
function skales_maybe_upgrade() {
    $stored = (string) get_option('skales_plugin_version', '');
    if ($stored === SKALES_VERSION) {
        return;
    }

    // Up to 2.0.0 the token was parked in an autoloaded option and only removed
    // when someone opened the admin screen. Move whatever is still there into
    // the expiring transient, so it can still be copied once and disappears on
    // its own afterwards.
    $legacy = get_option('skales_api_token_display', '');
    if (is_string($legacy) && $legacy !== '') {
        skales_hold_token_for_display($legacy);
    }
    delete_option('skales_api_token_display');

    update_option('skales_plugin_version', SKALES_VERSION);
}

register_deactivation_hook(__FILE__, 'skales_deactivate');
function skales_deactivate() {
    update_option('skales_connected', false);
    flush_rewrite_rules(false);
}

// Runtime check: warn if an old plugin copy is still active.
add_action('admin_notices', function () {
    if (!current_user_can('activate_plugins')) return;
    if (!function_exists('is_plugin_active')) {
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
    }
    $old_slugs = [
        'skales-wordpress/skales-connector.php',
        'skales-wordpress/skales-wordpress.php',
    ];
    foreach ($old_slugs as $old_slug) {
        if (is_plugin_active($old_slug)) {
            echo '<div class="notice notice-warning is-dismissible"><p>';
            echo '<strong>' . esc_html__('Skales Connector:', 'skales-connector') . '</strong> ';
            printf(
                /* translators: %s: plugin file path of the outdated copy */
                esc_html__('An older version of the Skales plugin (%s) is still active. Please deactivate and delete it to avoid conflicts.', 'skales-connector'),
                '<code>' . esc_html($old_slug) . '</code>'
            );
            echo '</p></div>';
        }
    }
});

// =============================================================================
// ROUTE REGISTRATION
// =============================================================================

add_action('rest_api_init', 'skales_register_routes');
function skales_register_routes() {
    $ns = 'skales/v1';

    skales_register_content_routes($ns);
    skales_register_media_routes($ns);
    skales_register_site_routes($ns);
    skales_register_design_routes($ns);
    skales_register_elementor_routes($ns);
    skales_register_commerce_routes($ns);
    skales_register_seo_routes($ns);

    // Connection handshake. Read-only, but it flips the "connected" flag, so it
    // requires the same administrator binding as everything else.
    register_rest_route($ns, '/connect', [
        'methods'             => 'GET',
        'callback'            => 'skales_route_connect',
        'permission_callback' => 'skales_authenticate',
    ]);

    // Cache clearing.
    register_rest_route($ns, '/cache/clear', [
        'methods'             => 'POST',
        'callback'            => 'skales_route_clear_cache',
        'permission_callback' => skales_cap('manage_options'),
    ]);
}

function skales_route_connect($request) {
    update_option('skales_connected', true);
    $caps = skales_detect_plugins();

    return rest_ensure_response([
        'ok'      => true,
        'version' => SKALES_VERSION, // 1.x key, unchanged

        // The handshake answers the two version questions in one place, so a
        // client does not have to dig through the capability report or guess
        // from a failing call which half of the pair is behind.
        'connector_version' => SKALES_VERSION,
        'api_level'         => SKALES_API_LEVEL,
        'requires_desktop'  => SKALES_MIN_DESKTOP,
        'requires_mobile'   => SKALES_MIN_MOBILE,
        'client'            => skales_client_report($request),

        'capabilities' => $caps,
    ]);
}

/**
 * The oldest build per platform, keyed the way clients name themselves.
 *
 * @return array<string,string>
 */
function skales_client_minimums() {
    return [
        'desktop' => SKALES_MIN_DESKTOP,
        'mobile'  => SKALES_MIN_MOBILE,
    ];
}

/**
 * What the plugin can tell about the Skales build on the other end.
 *
 * A client names itself with a `client_version` parameter or an
 * X-Skales-Client-Version header. The desktop sends a bare number (`12.9.26`);
 * the phone sends its number behind a platform prefix (`mobile-2.9.26`),
 * because the two count differently and a bare `2.9.26` would compare below
 * every desktop minimum. A `client_platform` parameter or an
 * X-Skales-Client-Platform header may also name the platform outright.
 *
 * Each platform is judged against its own minimum. A client that does not name
 * itself, or names a platform this plugin does not know, gets "unknown", never
 * "outdated": an outdated counterpart is named as such instead of quietly
 * missing a third of the endpoints, but nothing is ever refused on this basis.
 *
 * @param WP_REST_Request $request
 * @return array
 */
function skales_client_report($request) {
    $version  = '';
    $platform = '';
    if ($request instanceof WP_REST_Request) {
        $version  = (string) ($request->get_param('client_version') ?: $request->get_header('X-Skales-Client-Version'));
        $platform = (string) ($request->get_param('client_platform') ?: $request->get_header('X-Skales-Client-Platform'));
    }
    $version  = trim(sanitize_text_field($version));
    $platform = sanitize_key($platform);

    // `mobile-2.9.26`: the platform rides in front of the number.
    if (preg_match('/^([a-z]+)-(\d.*)$/i', $version, $m)) {
        if ($platform === '') {
            $platform = strtolower($m[1]);
        }
        $version = $m[2];
    }
    // A bare number has always meant the desktop.
    if ($platform === '' && $version !== '') {
        $platform = 'desktop';
    }

    $minimums = skales_client_minimums();
    $minimum  = isset($minimums[$platform]) ? $minimums[$platform] : null;

    $report = [
        'platform' => $platform !== '' ? $platform : null,
        'version'  => $version !== '' ? $version : null,
        // An anonymous client is answered the way every release before 2.2.0
        // answered it; a named platform gets its own number, or none.
        'minimum'  => $platform === '' ? SKALES_MIN_DESKTOP : $minimum,
        'outdated' => null,
    ];

    if ($version === '' || !$minimum || !preg_match('/^(\d+(?:\.\d+)*)/', $version, $v)) {
        return $report;
    }

    $report['outdated'] = version_compare($v[1], $minimum, '<');
    if ($report['outdated']) {
        $report['notice'] = sprintf(
            'This Skales %1$s build (%2$s) is older than %3$s and cannot drive every endpoint the connector offers.',
            $platform,
            $v[1],
            $minimum
        );
    }

    return $report;
}

// Every answer from this namespace carries the plugin version, so a client can
// tell an outdated connector from a missing one without a second request. A
// site running 1.x sends no such header, and the absence is the answer.
add_filter('rest_post_dispatch', 'skales_rest_version_header', 10, 3);
function skales_rest_version_header($response, $server, $request) {
    if ($request instanceof WP_REST_Request
        && strpos((string) $request->get_route(), '/skales/v1') === 0
        && $response instanceof WP_REST_Response) {
        $response->header('X-Skales-Connector-Version', SKALES_VERSION);
        $response->header('X-Skales-Api-Level', (string) SKALES_API_LEVEL);
    }
    return $response;
}

// =============================================================================
// CACHE CLEARING
// =============================================================================

function skales_route_clear_cache($request) {
    $cleared = [];

    if (function_exists('wp_cache_clear_cache')) { wp_cache_clear_cache(); $cleared[] = 'wp-super-cache'; }
    if (function_exists('w3tc_flush_all'))       { w3tc_flush_all();       $cleared[] = 'w3-total-cache'; }
    if (class_exists('LiteSpeed\Purge'))         { do_action('litespeed_purge_all'); $cleared[] = 'litespeed-cache'; }
    if (function_exists('rocket_clean_domain'))  { rocket_clean_domain();  $cleared[] = 'wp-rocket'; }

    wp_cache_flush();
    $cleared[] = 'wp-object-cache';

    return rest_ensure_response(['ok' => true, 'cleared' => $cleared]);
}
