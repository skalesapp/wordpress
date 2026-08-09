<?php
/**
 * Plugin Name: Skales Connector
 * Plugin URI: https://skales.app/
 * Description: Connect your WordPress site to the Skales desktop app. Manage posts, pages, media, menus, widgets, settings, permalinks, comments and design from your own machine. No third-party service involved.
 * Version: 2.0.0
 * Author: Mario Simic
 * Author URI: https://mariosimic.at
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: skales-connector
 * Requires at least: 5.6
 * Tested up to: 7.0
 * Requires PHP: 7.4
 */

if (!defined('ABSPATH')) exit;

define('SKALES_VERSION', '2.0.0');
define('SKALES_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('SKALES_PLUGIN_FILE', __FILE__);

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
        update_option('skales_api_token_display', $token);
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
        'ok'           => true,
        'version'      => SKALES_VERSION,
        'capabilities' => $caps,
    ]);
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
