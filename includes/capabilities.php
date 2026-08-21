<?php
/**
 * Capability report: what this site can do, sent to Skales on /connect.
 *
 * @package Skales_Connector
 */

if (!defined('ABSPATH')) exit;

/**
 * Describe one detected plugin. Capabilities are only reported when the plugin
 * is actually active, otherwise the desktop side plans work the site cannot do.
 *
 * @param string   $name
 * @param string   $slug
 * @param bool     $active
 * @param string[] $capabilities
 * @return array
 */
function skales_plugin_entry($name, $slug, $active, $capabilities) {
    return [
        'name'         => $name,
        'slug'         => $slug,
        'active'       => (bool) $active,
        'capabilities' => $active ? $capabilities : [],
    ];
}

function skales_detect_plugins() {
    skales_require_plugin_api();

    $has_elementor     = is_plugin_active('elementor/elementor.php');
    $has_elementor_pro = is_plugin_active('elementor-pro/elementor-pro.php');
    $has_woo           = is_plugin_active('woocommerce/woocommerce.php');
    $has_rankmath      = is_plugin_active('seo-by-rank-math/rank-math.php');
    $has_yoast         = is_plugin_active('wordpress-seo/wp-seo.php');

    $plugins = [
        skales_plugin_entry('Elementor', 'elementor', $has_elementor,
            ['page_builder', 'section_create', 'widget_insert', 'template_import', 'global_styles']),
        skales_plugin_entry('Elementor Pro', 'elementor-pro', $has_elementor_pro,
            ['theme_builder', 'popup_builder', 'form_builder', 'motion_effects']),
        skales_plugin_entry('WooCommerce', 'woocommerce', $has_woo,
            ['products', 'orders', 'categories', 'pricing', 'inventory', 'coupons', 'shipping']),
        skales_plugin_entry('RankMath SEO', 'rankmath', $has_rankmath,
            ['seo_title', 'seo_description', 'focus_keyword', 'schema_markup', 'sitemap']),
        skales_plugin_entry('Yoast SEO', 'yoast', $has_yoast,
            ['seo_title', 'seo_description', 'focus_keyword', 'schema_markup', 'breadcrumbs']),
        skales_plugin_entry('WP Super Cache', 'wp-super-cache',
            is_plugin_active('wp-super-cache/wp-cache.php'), ['cache_clear']),
        skales_plugin_entry('W3 Total Cache', 'w3-total-cache',
            is_plugin_active('w3-total-cache/w3-total-cache.php'), ['cache_clear', 'minification']),
        skales_plugin_entry('LiteSpeed Cache', 'litespeed-cache',
            is_plugin_active('litespeed-cache/litespeed-cache.php'), ['cache_clear', 'cdn', 'image_optimization']),
        skales_plugin_entry('WP Rocket', 'wp-rocket',
            is_plugin_active('wp-rocket/wp-rocket.php') || function_exists('rocket_clean_domain'), ['cache_clear']),
        skales_plugin_entry('Contact Form 7', 'cf7',
            is_plugin_active('contact-form-7/wp-contact-form-7.php'), ['forms']),
        skales_plugin_entry('WPForms', 'wpforms',
            is_plugin_active('wpforms-lite/wpforms.php') || is_plugin_active('wpforms/wpforms.php'), ['forms']),
    ];

    $theme      = wp_get_theme();
    $owner      = skales_owner_id();
    $owner_user = $owner ? get_user_by('id', $owner) : null;

    return [
        'wordpress_version' => get_bloginfo('version'),
        'php_version'       => phpversion(),
        'theme'             => $theme->get('Name'),
        'site_url'          => get_site_url(),
        'plugins'           => $plugins,
        'has_elementor'     => $has_elementor,
        'has_woocommerce'   => $has_woo,
        'has_seo'           => $has_rankmath || $has_yoast,

        // Everything below is new in 2.0.0. Older Skales builds ignore unknown
        // keys, so the payload stays backward compatible.
        'connector_version' => SKALES_VERSION,
        'api_level'         => SKALES_API_LEVEL,
        'requires_desktop'  => SKALES_MIN_DESKTOP,
        'allow_raw_html'    => (bool) get_option('skales_allow_raw_html', 1),
        'is_block_theme'    => function_exists('wp_is_block_theme') ? wp_is_block_theme() : false,
        'is_multisite'      => is_multisite(),
        'linked_user'       => $owner_user ? [
            'id'    => (int) $owner_user->ID,
            'login' => $owner_user->user_login,
            'name'  => $owner_user->display_name,
            'roles' => array_values($owner_user->roles),
            // What the connector may actually do on this site. Skales can use
            // this to stop offering work the account would be refused.
            // One entry per capability an endpoint of this plugin asks for.
            // A capability that is missing here cannot be checked before the
            // call, so the caller learns of the refusal as a bare 403 from a
            // route it had already decided to use.
            'can'   => [
                'edit_posts'         => user_can($owner_user, 'edit_posts'),
                'publish_posts'      => user_can($owner_user, 'publish_posts'),
                'delete_posts'       => user_can($owner_user, 'delete_posts'),
                'edit_pages'         => user_can($owner_user, 'edit_pages'),
                'publish_pages'      => user_can($owner_user, 'publish_pages'),
                'delete_pages'       => user_can($owner_user, 'delete_pages'),
                'upload_files'       => user_can($owner_user, 'upload_files'),
                'manage_categories'  => user_can($owner_user, 'manage_categories'),
                'moderate_comments'  => user_can($owner_user, 'moderate_comments'),
                'edit_theme_options' => user_can($owner_user, 'edit_theme_options'),
                'edit_css'           => user_can($owner_user, 'edit_css'),
                'unfiltered_html'    => user_can($owner_user, 'unfiltered_html'),
                'activate_plugins'   => user_can($owner_user, 'activate_plugins'),
                'switch_themes'      => user_can($owner_user, 'switch_themes'),
                'list_users'         => user_can($owner_user, 'list_users'),
                'manage_options'     => user_can($owner_user, 'manage_options'),
            ],
        ] : null,
        'permalink_structure' => get_option('permalink_structure'),
        'features' => [
            'posts', 'pages', 'post_delete', 'terms', 'comments', 'menus',
            'widgets', 'settings', 'permalinks', 'theme_mods', 'custom_css',
            'site_identity', 'media_library', 'media_from_url', 'featured_image',
            'gutenberg_blocks', 'reusable_blocks', 'plugins_read', 'themes_read',
            'users_read', 'cache_clear',
        ],
        'abilities_api' => function_exists('wp_register_ability'),
    ];
}
