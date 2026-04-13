<?php
/**
 * Plugin Name: Skales Connector
 * Plugin URI: https://skales.app/
 * Description: Connect your WordPress site to Skales Desktop AI Agent. Build pages, manage content, upload media, and automate WooCommerce — all from your desktop.
 * Version: 1.2.0
 * Author: Mario Simic
 * Author URI: https://mariosimic.at
 * License: MIT
 * Text Domain: skales-connector
 * Requires at least: 5.6
 * Requires PHP: 7.4
 */

if (!defined('ABSPATH')) exit;

define('SKALES_VERSION', '1.2.0');
define('SKALES_PLUGIN_DIR', plugin_dir_path(__FILE__));

// =============================================================================
// 1. TOKEN AUTHENTICATION
// =============================================================================

register_activation_hook(__FILE__, 'skales_activate');
function skales_activate() {
    // ── Deactivate old versions that used a different folder/slug ──
    // v1.0.0–v1.1.0 shipped as "skales-wordpress/skales-connector.php"
    // which WordPress treats as a separate plugin from "skales-connector/skales-connector.php".
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

    // ── Preserve existing token if upgrading (don't regenerate) ──
    $existing_hash = get_option('skales_api_token_hash');
    if (!$existing_hash) {
        $token = wp_generate_password(48, false);
        update_option('skales_api_token_hash', hash('sha256', $token));
        update_option('skales_api_token_display', $token);
        update_option('skales_connected', false);
    }
    update_option('skales_plugin_version', SKALES_VERSION);
}

// ── Runtime check: warn if old plugin copy is still active ──
add_action('admin_notices', function () {
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
            echo '<strong>Skales Connector:</strong> An older version of the Skales plugin ';
            echo '(<code>' . esc_html($old_slug) . '</code>) is still active. ';
            echo 'Please deactivate and delete it to avoid conflicts.';
            echo '</p></div>';
        }
    }
});

function skales_authenticate($request) {
    $auth = $request->get_header('Authorization');
    if (!$auth || strpos($auth, 'Bearer ') !== 0) {
        return new WP_Error('unauthorized', 'Missing or invalid token', ['status' => 401]);
    }

    $token = substr($auth, 7);
    $stored_hash = get_option('skales_api_token_hash');

    if (!$stored_hash || hash('sha256', $token) !== $stored_hash) {
        return new WP_Error('unauthorized', 'Invalid token', ['status' => 401]);
    }

    return true;
}

// =============================================================================
// 2. ADMIN PANEL
// =============================================================================

add_action('admin_menu', 'skales_admin_menu');
function skales_admin_menu() {
    add_menu_page(
        'Skales Connector',
        'Skales',
        'manage_options',
        'skales-connector',
        'skales_admin_page',
        'dashicons-networking',
        80
    );
}

function skales_admin_page() {
    $token_display = get_option('skales_api_token_display', '');
    $is_connected = get_option('skales_connected', false);
    $capabilities = skales_detect_plugins();

    echo '<div class="wrap">';
    echo '<h1>Skales Connector</h1>';

    if ($token_display) {
        echo '<div class="notice notice-warning"><p>';
        echo '<strong>Your API Token (copy this into Skales Desktop):</strong><br>';
        echo '<code style="font-size: 16px; padding: 8px; display: inline-block; margin: 8px 0; user-select: all;">' . esc_html($token_display) . '</code>';
        echo '<br><em>This token will only be shown once. Copy it now.</em>';
        echo '</p></div>';
        delete_option('skales_api_token_display');
    }

    if (isset($_POST['skales_regenerate_token']) && wp_verify_nonce($_POST['_wpnonce'], 'skales_regenerate')) {
        $new_token = wp_generate_password(48, false);
        update_option('skales_api_token_hash', hash('sha256', $new_token));
        echo '<div class="notice notice-success"><p>';
        echo '<strong>New Token:</strong> <code style="font-size: 16px; user-select: all;">' . esc_html($new_token) . '</code>';
        echo '<br>Update this in your Skales Desktop settings.';
        echo '</p></div>';
    }

    echo '<form method="post">';
    wp_nonce_field('skales_regenerate');
    echo '<p><button type="submit" name="skales_regenerate_token" class="button">Regenerate Token</button></p>';
    echo '</form>';

    echo '<h2>Connection Status</h2>';
    echo '<p>Status: ' . ($is_connected ? '&#10004; Connected' : '&#9203; Waiting for Skales Desktop') . '</p>';
    echo '<p>REST Endpoint: <code>' . esc_html(rest_url('skales/v1/')) . '</code></p>';

    echo '<h2>Detected Plugins</h2>';
    echo '<table class="widefat">';
    echo '<thead><tr><th>Plugin</th><th>Status</th><th>Capabilities</th></tr></thead>';
    echo '<tbody>';
    foreach ($capabilities['plugins'] as $plugin) {
        echo '<tr>';
        echo '<td>' . esc_html($plugin['name']) . '</td>';
        echo '<td>' . ($plugin['active'] ? '&#10004; Active' : '&#10060; Inactive') . '</td>';
        echo '<td>' . esc_html(implode(', ', $plugin['capabilities'])) . '</td>';
        echo '</tr>';
    }
    echo '</tbody></table>';
    echo '</div>';
}

// =============================================================================
// 3. PLUGIN DETECTION
// =============================================================================

function skales_detect_plugins() {
    if (!function_exists('is_plugin_active')) {
        include_once(ABSPATH . 'wp-admin/includes/plugin.php');
    }

    $plugins = [];

    $plugins[] = [
        'name' => 'Elementor',
        'slug' => 'elementor',
        'active' => is_plugin_active('elementor/elementor.php'),
        'capabilities' => is_plugin_active('elementor/elementor.php')
            ? ['page_builder', 'section_create', 'widget_insert', 'template_import', 'global_styles']
            : [],
    ];

    $plugins[] = [
        'name' => 'Elementor Pro',
        'slug' => 'elementor-pro',
        'active' => is_plugin_active('elementor-pro/elementor-pro.php'),
        'capabilities' => is_plugin_active('elementor-pro/elementor-pro.php')
            ? ['theme_builder', 'popup_builder', 'form_builder', 'motion_effects']
            : [],
    ];

    $plugins[] = [
        'name' => 'WooCommerce',
        'slug' => 'woocommerce',
        'active' => is_plugin_active('woocommerce/woocommerce.php'),
        'capabilities' => is_plugin_active('woocommerce/woocommerce.php')
            ? ['products', 'orders', 'categories', 'pricing', 'inventory', 'coupons', 'shipping']
            : [],
    ];

    $plugins[] = [
        'name' => 'RankMath SEO',
        'slug' => 'rankmath',
        'active' => is_plugin_active('seo-by-rank-math/rank-math.php'),
        'capabilities' => is_plugin_active('seo-by-rank-math/rank-math.php')
            ? ['seo_title', 'seo_description', 'focus_keyword', 'schema_markup', 'sitemap']
            : [],
    ];

    $plugins[] = [
        'name' => 'Yoast SEO',
        'slug' => 'yoast',
        'active' => is_plugin_active('wordpress-seo/wp-seo.php'),
        'capabilities' => is_plugin_active('wordpress-seo/wp-seo.php')
            ? ['seo_title', 'seo_description', 'focus_keyword', 'schema_markup', 'breadcrumbs']
            : [],
    ];

    $plugins[] = [
        'name' => 'WP Super Cache',
        'slug' => 'wp-super-cache',
        'active' => is_plugin_active('wp-super-cache/wp-cache.php'),
        'capabilities' => ['cache_clear'],
    ];

    $plugins[] = [
        'name' => 'W3 Total Cache',
        'slug' => 'w3-total-cache',
        'active' => is_plugin_active('w3-total-cache/w3-total-cache.php'),
        'capabilities' => ['cache_clear', 'minification'],
    ];

    $plugins[] = [
        'name' => 'LiteSpeed Cache',
        'slug' => 'litespeed-cache',
        'active' => is_plugin_active('litespeed-cache/litespeed-cache.php'),
        'capabilities' => ['cache_clear', 'cdn', 'image_optimization'],
    ];

    $plugins[] = [
        'name' => 'Contact Form 7',
        'slug' => 'cf7',
        'active' => is_plugin_active('contact-form-7/wp-contact-form-7.php'),
        'capabilities' => ['forms'],
    ];

    $plugins[] = [
        'name' => 'WPForms',
        'slug' => 'wpforms',
        'active' => is_plugin_active('wpforms-lite/wpforms.php') || is_plugin_active('wpforms/wpforms.php'),
        'capabilities' => ['forms'],
    ];

    return [
        'wordpress_version' => get_bloginfo('version'),
        'php_version' => phpversion(),
        'theme' => wp_get_theme()->get('Name'),
        'site_url' => get_site_url(),
        'plugins' => $plugins,
        'has_elementor' => is_plugin_active('elementor/elementor.php'),
        'has_woocommerce' => is_plugin_active('woocommerce/woocommerce.php'),
        'has_seo' => is_plugin_active('seo-by-rank-math/rank-math.php') || is_plugin_active('wordpress-seo/wp-seo.php'),
    ];
}

// =============================================================================
// 4. REST API ENDPOINTS
// =============================================================================

add_action('rest_api_init', 'skales_register_routes');
function skales_register_routes() {
    $namespace = 'skales/v1';

    // Connection
    register_rest_route($namespace, '/connect', [
        'methods' => 'GET',
        'callback' => 'skales_route_connect',
        'permission_callback' => 'skales_authenticate',
    ]);

    // Pages
    register_rest_route($namespace, '/pages', [
        'methods' => 'POST',
        'callback' => 'skales_route_create_page',
        'permission_callback' => 'skales_authenticate',
    ]);

    register_rest_route($namespace, '/pages/(?P<id>\d+)', [
        'methods' => 'PUT',
        'callback' => 'skales_route_update_page',
        'permission_callback' => 'skales_authenticate',
    ]);

    register_rest_route($namespace, '/pages', [
        'methods' => 'GET',
        'callback' => 'skales_route_list_pages',
        'permission_callback' => 'skales_authenticate',
    ]);

    // Posts
    register_rest_route($namespace, '/posts', [
        'methods' => 'POST',
        'callback' => 'skales_route_create_post',
        'permission_callback' => 'skales_authenticate',
    ]);

    register_rest_route($namespace, '/posts/(?P<id>\d+)', [
        'methods' => 'PUT',
        'callback' => 'skales_route_update_post',
        'permission_callback' => 'skales_authenticate',
    ]);

    // Media
    register_rest_route($namespace, '/media', [
        'methods' => 'POST',
        'callback' => 'skales_route_upload_media',
        'permission_callback' => 'skales_authenticate',
    ]);

    // Elementor
    register_rest_route($namespace, '/elementor/page', [
        'methods' => 'POST',
        'callback' => 'skales_route_elementor_create_page',
        'permission_callback' => 'skales_authenticate',
    ]);

    register_rest_route($namespace, '/elementor/page/(?P<id>\d+)', [
        'methods' => 'PUT',
        'callback' => 'skales_route_elementor_update_page',
        'permission_callback' => 'skales_authenticate',
    ]);

    // WooCommerce
    register_rest_route($namespace, '/woo/products', [
        'methods' => 'GET',
        'callback' => 'skales_route_woo_list_products',
        'permission_callback' => 'skales_authenticate',
    ]);

    register_rest_route($namespace, '/woo/products/bulk-price', [
        'methods' => 'PUT',
        'callback' => 'skales_route_woo_bulk_price',
        'permission_callback' => 'skales_authenticate',
    ]);

    // SEO
    register_rest_route($namespace, '/seo/(?P<id>\d+)', [
        'methods' => 'PUT',
        'callback' => 'skales_route_update_seo',
        'permission_callback' => 'skales_authenticate',
    ]);

    // Cache
    register_rest_route($namespace, '/cache/clear', [
        'methods' => 'POST',
        'callback' => 'skales_route_clear_cache',
        'permission_callback' => 'skales_authenticate',
    ]);
}

// --- Route Handlers ---

function skales_route_connect($request) {
    update_option('skales_connected', true);
    $caps = skales_detect_plugins();
    return rest_ensure_response([
        'ok' => true,
        'version' => SKALES_VERSION,
        'capabilities' => $caps,
    ]);
}

function skales_route_create_page($request) {
    $params = $request->get_json_params();
    $page_data = [
        'post_title'   => sanitize_text_field($params['title'] ?? 'Untitled'),
        'post_content' => $params['content'] ?? '',
        'post_status'  => sanitize_text_field($params['status'] ?? 'draft'),
        'post_type'    => 'page',
    ];

    if (!empty($params['template'])) {
        $page_data['page_template'] = sanitize_text_field($params['template']);
    }

    kses_remove_filters();
    $page_id = wp_insert_post($page_data, true);
    kses_init_filters();
    if (is_wp_error($page_id)) {
        return new WP_Error('create_failed', $page_id->get_error_message(), ['status' => 500]);
    }

    // Mark as Skales-created for full-width CSS injection
    update_post_meta($page_id, '_skales_page', '1');

    return rest_ensure_response([
        'ok' => true,
        'page_id' => $page_id,
        'url' => get_permalink($page_id),
        'edit_url' => admin_url("post.php?post={$page_id}&action=edit"),
    ]);
}

function skales_route_update_page($request) {
    $id = (int) $request['id'];
    $params = $request->get_json_params();

    $update_data = ['ID' => $id];
    if (isset($params['title'])) $update_data['post_title'] = sanitize_text_field($params['title']);
    if (isset($params['content'])) $update_data['post_content'] = $params['content'];
    if (isset($params['status'])) $update_data['post_status'] = sanitize_text_field($params['status']);

    kses_remove_filters();
    $result = wp_update_post($update_data, true);
    kses_init_filters();
    if (is_wp_error($result)) {
        return new WP_Error('update_failed', $result->get_error_message(), ['status' => 500]);
    }

    return rest_ensure_response(['ok' => true, 'page_id' => $id]);
}

function skales_route_list_pages($request) {
    $pages = get_posts([
        'post_type' => 'page',
        'numberposts' => 50,
        'post_status' => ['publish', 'draft', 'pending'],
    ]);

    $result = array_map(function($p) {
        return [
            'id' => $p->ID,
            'title' => $p->post_title,
            'status' => $p->post_status,
            'url' => get_permalink($p->ID),
            'modified' => $p->post_modified,
        ];
    }, $pages);

    return rest_ensure_response(['ok' => true, 'pages' => $result]);
}

function skales_route_create_post($request) {
    $params = $request->get_json_params();
    $post_data = [
        'post_title'   => sanitize_text_field($params['title'] ?? 'Untitled'),
        'post_content' => $params['content'] ?? '',
        'post_status'  => sanitize_text_field($params['status'] ?? 'draft'),
        'post_type'    => 'post',
    ];

    if (!empty($params['categories'])) {
        $post_data['post_category'] = array_map('intval', (array)$params['categories']);
    }

    kses_remove_filters();
    $post_id = wp_insert_post($post_data, true);
    kses_init_filters();
    if (is_wp_error($post_id)) {
        return new WP_Error('create_failed', $post_id->get_error_message(), ['status' => 500]);
    }

    // Mark as Skales-created for full-width CSS injection
    update_post_meta($post_id, '_skales_page', '1');

    return rest_ensure_response([
        'ok' => true,
        'post_id' => $post_id,
        'url' => get_permalink($post_id),
    ]);
}

function skales_route_update_post($request) {
    $id = (int) $request['id'];
    $params = $request->get_json_params();

    $update_data = ['ID' => $id];
    if (isset($params['title'])) $update_data['post_title'] = sanitize_text_field($params['title']);
    if (isset($params['content'])) $update_data['post_content'] = $params['content'];
    if (isset($params['status'])) $update_data['post_status'] = sanitize_text_field($params['status']);

    kses_remove_filters();
    $result = wp_update_post($update_data, true);
    kses_init_filters();
    if (is_wp_error($result)) {
        return new WP_Error('update_failed', $result->get_error_message(), ['status' => 500]);
    }

    return rest_ensure_response(['ok' => true, 'post_id' => $id]);
}

function skales_route_upload_media($request) {
    $params = $request->get_json_params();

    if (!empty($params['base64'])) {
        $data = base64_decode($params['base64']);
        $filename = sanitize_file_name($params['filename'] ?? 'upload.png');
        $upload = wp_upload_bits($filename, null, $data);

        if ($upload['error']) {
            return new WP_Error('upload_failed', $upload['error'], ['status' => 500]);
        }

        $filetype = wp_check_filetype($upload['file']);
        $attachment = [
            'post_mime_type' => $filetype['type'],
            'post_title'     => pathinfo($filename, PATHINFO_FILENAME),
            'post_status'    => 'inherit',
        ];

        $attach_id = wp_insert_attachment($attachment, $upload['file']);
        require_once(ABSPATH . 'wp-admin/includes/image.php');
        $metadata = wp_generate_attachment_metadata($attach_id, $upload['file']);
        wp_update_attachment_metadata($attach_id, $metadata);

        if (!empty($params['alt'])) {
            update_post_meta($attach_id, '_wp_attachment_image_alt', sanitize_text_field($params['alt']));
        }

        return rest_ensure_response([
            'ok' => true,
            'attachment_id' => $attach_id,
            'url' => wp_get_attachment_url($attach_id),
        ]);
    }

    return new WP_Error('no_data', 'No base64 data provided', ['status' => 400]);
}

// =============================================================================
// 5. ELEMENTOR PAGE BUILDER
// =============================================================================

function skales_route_elementor_create_page($request) {
    if (!is_plugin_active('elementor/elementor.php')) {
        return new WP_Error('no_elementor', 'Elementor is not installed or active', ['status' => 400]);
    }

    $params = $request->get_json_params();

    kses_remove_filters();
    $page_id = wp_insert_post([
        'post_title'   => sanitize_text_field($params['title'] ?? 'Skales Page'),
        'post_content' => '',
        'post_status'  => sanitize_text_field($params['status'] ?? 'draft'),
        'post_type'    => 'page',
    ], true);
    kses_init_filters();

    if (is_wp_error($page_id)) {
        return new WP_Error('create_failed', $page_id->get_error_message(), ['status' => 500]);
    }

    // Mark as Skales-created for full-width CSS injection
    update_post_meta($page_id, '_skales_page', '1');

    $elementor_data = skales_build_elementor_data($params['sections'] ?? []);

    // Detect installed Elementor version for compatibility
    $elementor_version = '3.16.0'; // safe fallback
    if (defined('ELEMENTOR_VERSION')) {
        $elementor_version = ELEMENTOR_VERSION;
    }

    update_post_meta($page_id, '_elementor_data', wp_slash(json_encode($elementor_data)));
    update_post_meta($page_id, '_elementor_edit_mode', 'builder');
    update_post_meta($page_id, '_elementor_template_type', 'wp-page');
    update_post_meta($page_id, '_elementor_version', $elementor_version);
    update_post_meta($page_id, '_elementor_css', '');

    // Page template MUST be elementor_canvas or elementor_header_footer.
    // 'default' wraps content in theme containers → blank page.
    update_post_meta($page_id, '_wp_page_template', 'elementor_canvas');

    // Page settings that Elementor reads when opening the editor
    update_post_meta($page_id, '_elementor_page_settings', [
        'hide_title' => 'yes',
        'template' => 'elementor_canvas',
    ]);

    // Force Elementor to regenerate CSS for this page
    if (class_exists('\Elementor\Plugin')) {
        \Elementor\Plugin::$instance->files_manager->clear_cache();
        // Also trigger per-post CSS regeneration
        $post_css = \Elementor\Core\Files\CSS\Post::create($page_id);
        if ($post_css) {
            $post_css->update();
        }
    }

    return rest_ensure_response([
        'ok' => true,
        'page_id' => $page_id,
        'url' => get_permalink($page_id),
        'edit_url' => admin_url("post.php?post={$page_id}&action=elementor"),
    ]);
}

function skales_route_elementor_update_page($request) {
    if (!is_plugin_active('elementor/elementor.php')) {
        return new WP_Error('no_elementor', 'Elementor is not installed', ['status' => 400]);
    }

    $page_id = (int) $request['id'];
    $params = $request->get_json_params();

    if (isset($params['sections'])) {
        $elementor_data = skales_build_elementor_data($params['sections']);
        $elementor_version = defined('ELEMENTOR_VERSION') ? ELEMENTOR_VERSION : '3.16.0';
        update_post_meta($page_id, '_elementor_data', wp_slash(json_encode($elementor_data)));
        update_post_meta($page_id, '_elementor_version', $elementor_version);

        if (class_exists('\Elementor\Plugin')) {
            \Elementor\Plugin::$instance->files_manager->clear_cache();
            $post_css = \Elementor\Core\Files\CSS\Post::create($page_id);
            if ($post_css) {
                $post_css->update();
            }
        }
    }

    if (isset($params['title'])) {
        wp_update_post(['ID' => $page_id, 'post_title' => sanitize_text_field($params['title'])]);
    }

    return rest_ensure_response(['ok' => true, 'page_id' => $page_id]);
}

function skales_build_elementor_data($sections) {
    // ── Flexbox Container format (Elementor 3.6+) ──────────────────────
    // Modern Elementor uses: container → widget (single col)
    //                    or: container → container(inner) → widget (multi-col)
    // The old section → column → widget format does NOT render when
    // Flexbox Container is set to "Standard" (the default since ~3.16).

    $containers = [];

    foreach ($sections as $section) {
        $num_columns = max(1, count($section['columns'] ?? []));

        // ── Build container settings (the outer wrapper) ──
        $container_settings = [
            'content_width' => 'full',
            'flex_direction' => ($num_columns > 1) ? 'row' : 'column',
            'flex_wrap' => 'wrap',
            'flex_gap' => ['size' => 0, 'unit' => 'px', 'column' => '0'],
        ];

        // Layout mapping: '1' = single, '1-1' = 2-col, '1-1-1' = 3-col etc.
        if (!empty($section['layout'])) {
            if ($section['layout'] === 'full_width' || $section['layout'] === 'boxed') {
                $container_settings['content_width'] = $section['layout'];
            }
            // Detect multi-column by dash pattern (e.g., '1-1', '1-1-1')
            if (strpos($section['layout'], '-') !== false) {
                $container_settings['flex_direction'] = 'row';
            }
        }

        // Background
        if (!empty($section['background'])) {
            if (strpos($section['background'], 'gradient') !== false) {
                $container_settings['background_background'] = 'gradient';
                $container_settings['background_color'] = '#0f172a';
                $container_settings['background_color_b'] = '#1e1b4b';
            } else {
                $container_settings['background_background'] = 'classic';
                $container_settings['background_color'] = $section['background'];
            }
        }

        // Background image
        if (!empty($section['background_image'])) {
            $container_settings['background_background'] = 'classic';
            $container_settings['background_image'] = [
                'url' => $section['background_image'],
                'id' => '',
            ];
            $container_settings['background_size'] = 'cover';
            $container_settings['background_position'] = 'center center';
        }

        // Padding
        if (!empty($section['padding'])) {
            $pad = $section['padding'];
            if (is_numeric($pad)) {
                $container_settings['padding'] = [
                    'top' => strval($pad), 'right' => strval($pad),
                    'bottom' => strval($pad), 'left' => strval($pad),
                    'unit' => 'px', 'isLinked' => true,
                ];
            } elseif (is_array($pad)) {
                $container_settings['padding'] = array_merge([
                    'top' => '0', 'right' => '0', 'bottom' => '0', 'left' => '0',
                    'unit' => 'px', 'isLinked' => false,
                ], $pad);
            }
        }

        // Merge any extra section-level settings
        if (!empty($section['settings']) && is_array($section['settings'])) {
            $container_settings = array_merge($container_settings, $section['settings']);
        }

        // ── Build child elements ──
        $children = [];

        if ($num_columns <= 1) {
            // Single column: widgets go directly inside the container
            $col = ($section['columns'] ?? [[]])[0] ?? [];
            foreach (($col['widgets'] ?? []) as $widget) {
                $children[] = skales_build_widget($widget);
            }
        } else {
            // Multi-column: each column becomes an inner container with flex_basis
            foreach (($section['columns'] ?? []) as $col) {
                $col_width = intval($col['width'] ?? round(100 / $num_columns));
                $col_widgets = [];

                foreach (($col['widgets'] ?? []) as $widget) {
                    $col_widgets[] = skales_build_widget($widget);
                }

                $inner_settings = [
                    'content_width' => 'full',
                    'flex_direction' => 'column',
                    'flex_basis' => ['size' => $col_width, 'unit' => '%'],
                    'width' => ['size' => $col_width, 'unit' => '%'],
                ];

                // Merge extra column-level settings
                if (!empty($col['settings']) && is_array($col['settings'])) {
                    $inner_settings = array_merge($inner_settings, $col['settings']);
                }

                $children[] = [
                    'id' => skales_generate_id(),
                    'elType' => 'container',
                    'isInner' => true,
                    'settings' => $inner_settings,
                    'elements' => $col_widgets,
                ];
            }
        }

        // If no children at all, add a placeholder so the container is visible
        if (empty($children)) {
            $children[] = skales_build_widget([
                'type' => 'text-editor',
                'settings' => ['editor' => '<p style="text-align:center;color:#64748b;">Empty section — edit in Elementor</p>'],
            ]);
        }

        $containers[] = [
            'id' => skales_generate_id(),
            'elType' => 'container',
            'isInner' => false,
            'settings' => $container_settings,
            'elements' => $children,
        ];
    }

    return $containers;
}

/**
 * Build a single Elementor widget element from a Skales widget descriptor.
 * Normalises common aliases (content→editor, text→title, url→image).
 */
function skales_build_widget($widget) {
    $widget_type = $widget['type'] ?? 'text-editor';
    $widget_settings = $widget['settings'] ?? [];

    // Ensure settings is always an associative array, never []
    if (empty($widget_settings) || $widget_settings === []) {
        $widget_settings = new \stdClass(); // encodes to {} in JSON
    }

    // text-editor: map content/text/html → editor
    if ($widget_type === 'text-editor' && empty($widget_settings['editor'])) {
        $text = $widget_settings['content'] ?? $widget_settings['text'] ?? $widget_settings['html'] ?? '';
        if ($text) {
            $widget_settings['editor'] = $text;
        }
    }

    // heading: map text/content → title
    if ($widget_type === 'heading' && empty($widget_settings['title'])) {
        $widget_settings['title'] = $widget_settings['text'] ?? $widget_settings['content'] ?? '';
    }

    // button: map label/content → text
    if ($widget_type === 'button' && empty($widget_settings['text'])) {
        $widget_settings['text'] = $widget_settings['label'] ?? $widget_settings['content'] ?? 'Click Here';
    }

    // image: map url → image structure
    if ($widget_type === 'image' && !empty($widget_settings['url']) && empty($widget_settings['image'])) {
        $widget_settings['image'] = [
            'url' => $widget_settings['url'],
            'id' => '',
        ];
    }

    return [
        'id' => skales_generate_id(),
        'elType' => 'widget',
        'widgetType' => $widget_type,
        'isInner' => false,
        'settings' => $widget_settings,
        'elements' => [],
    ];
}

function skales_generate_id() {
    return substr(md5(uniqid(mt_rand(), true)), 0, 8);
}

// =============================================================================
// 6. WOOCOMMERCE INTEGRATION
// =============================================================================

function skales_route_woo_list_products($request) {
    if (!class_exists('WooCommerce')) {
        return new WP_Error('no_woo', 'WooCommerce is not active', ['status' => 400]);
    }

    $category = sanitize_text_field($request->get_param('category') ?? '');
    $args = [
        'post_type' => 'product',
        'numberposts' => 50,
        'post_status' => 'publish',
    ];

    if ($category) {
        $args['tax_query'] = [[
            'taxonomy' => 'product_cat',
            'field' => 'slug',
            'terms' => $category,
        ]];
    }

    $products = get_posts($args);
    $result = array_map(function($p) {
        $product = wc_get_product($p->ID);
        return [
            'id' => $p->ID,
            'name' => $p->post_title,
            'price' => $product->get_price(),
            'regular_price' => $product->get_regular_price(),
            'sale_price' => $product->get_sale_price(),
            'stock_status' => $product->get_stock_status(),
            'stock_quantity' => $product->get_stock_quantity(),
            'categories' => wp_get_post_terms($p->ID, 'product_cat', ['fields' => 'names']),
            'url' => get_permalink($p->ID),
        ];
    }, $products);

    return rest_ensure_response(['ok' => true, 'products' => $result]);
}

function skales_route_woo_bulk_price($request) {
    if (!class_exists('WooCommerce')) {
        return new WP_Error('no_woo', 'WooCommerce is not active', ['status' => 400]);
    }

    $params = $request->get_json_params();
    $category = sanitize_text_field($params['category'] ?? '');
    $discount = floatval($params['discount_percent'] ?? 0);
    $enable_sale = (bool)($params['sale'] ?? true);

    if (!$category || $discount <= 0 || $discount > 90) {
        return new WP_Error('invalid_params', 'Provide category and discount_percent (1-90)', ['status' => 400]);
    }

    $products = get_posts([
        'post_type' => 'product',
        'numberposts' => -1,
        'post_status' => 'publish',
        'tax_query' => [[
            'taxonomy' => 'product_cat',
            'field' => 'slug',
            'terms' => $category,
        ]],
    ]);

    $updated = 0;
    foreach ($products as $p) {
        $product = wc_get_product($p->ID);
        $regular = floatval($product->get_regular_price());
        if ($regular <= 0) continue;

        if ($enable_sale) {
            $sale_price = round($regular * (1 - $discount / 100), 2);
            $product->set_sale_price($sale_price);
        } else {
            $product->set_sale_price('');
        }

        $product->save();
        $updated++;
    }

    wc_delete_product_transients();

    return rest_ensure_response([
        'ok' => true,
        'updated' => $updated,
        'category' => $category,
        'discount_percent' => $discount,
    ]);
}

// =============================================================================
// 7. SEO MANAGEMENT
// =============================================================================

function skales_route_update_seo($request) {
    $post_id = (int) $request['id'];
    $params = $request->get_json_params();

    if (is_plugin_active('seo-by-rank-math/rank-math.php')) {
        if (isset($params['seo_title'])) update_post_meta($post_id, 'rank_math_title', sanitize_text_field($params['seo_title']));
        if (isset($params['seo_description'])) update_post_meta($post_id, 'rank_math_description', sanitize_text_field($params['seo_description']));
        if (isset($params['focus_keyword'])) update_post_meta($post_id, 'rank_math_focus_keyword', sanitize_text_field($params['focus_keyword']));
    }

    if (is_plugin_active('wordpress-seo/wp-seo.php')) {
        if (isset($params['seo_title'])) update_post_meta($post_id, '_yoast_wpseo_title', sanitize_text_field($params['seo_title']));
        if (isset($params['seo_description'])) update_post_meta($post_id, '_yoast_wpseo_metadesc', sanitize_text_field($params['seo_description']));
        if (isset($params['focus_keyword'])) update_post_meta($post_id, '_yoast_wpseo_focuskw', sanitize_text_field($params['focus_keyword']));
    }

    return rest_ensure_response(['ok' => true, 'post_id' => $post_id]);
}

// =============================================================================
// 8. CACHE CLEARING
// =============================================================================

function skales_route_clear_cache($request) {
    $cleared = [];

    if (function_exists('wp_cache_clear_cache')) { wp_cache_clear_cache(); $cleared[] = 'wp-super-cache'; }
    if (function_exists('w3tc_flush_all')) { w3tc_flush_all(); $cleared[] = 'w3-total-cache'; }
    if (class_exists('LiteSpeed\Purge')) { do_action('litespeed_purge_all'); $cleared[] = 'litespeed-cache'; }
    if (function_exists('rocket_clean_domain')) { rocket_clean_domain(); $cleared[] = 'wp-rocket'; }

    wp_cache_flush();
    $cleared[] = 'wp-object-cache';

    return rest_ensure_response(['ok' => true, 'cleared' => $cleared]);
}

// =============================================================================
// 9. FORCE FULL-WIDTH FOR SKALES PAGES
// =============================================================================

/**
 * Detect whether the current page/post was created by Skales.
 * Checks: _skales_page meta OR content containing wp:html / inline <style>.
 */
function skales_is_skales_page($post_id = null) {
    if (!$post_id) $post_id = get_the_ID();
    if (!$post_id) return false;

    // Explicit meta flag (set by create endpoints)
    if (get_post_meta($post_id, '_skales_page', true)) return true;

    // Heuristic fallback for pages created before this update
    $content = get_post_field('post_content', $post_id);
    return (strpos($content, 'wp:html') !== false || strpos($content, '<style') !== false);
}

/**
 * Add 'skales-page' body class so our CSS selectors have the highest specificity.
 */
add_filter('body_class', 'skales_body_class');
function skales_body_class($classes) {
    if (is_singular() && skales_is_skales_page()) {
        $classes[] = 'skales-page';
    }
    return $classes;
}

/**
 * Inject full-width CSS overrides via wp_head.
 * Uses body.skales-page prefix for maximum specificity without relying
 * solely on !important. Covers Twenty Twenty-Four, Astra, GeneratePress,
 * Kadence, OceanWP, Elementor containers, and generic theme wrappers.
 */
add_action('wp_head', 'skales_fullwidth_css');
function skales_fullwidth_css() {
    if (!is_singular()) return;
    if (!skales_is_skales_page()) return;

    echo '<style id="skales-fullwidth-overrides">
        /* ── Reset theme width constraints ─────────────────────────── */
        body.skales-page .entry-content,
        body.skales-page .page-content,
        body.skales-page .post-content,
        body.skales-page .content-area,
        body.skales-page article .entry-content,
        body.skales-page .site-content,
        body.skales-page .site-main {
            max-width: 100% !important;
            width: 100% !important;
            padding-left: 0 !important;
            padding-right: 0 !important;
            margin-left: auto !important;
            margin-right: auto !important;
            box-sizing: border-box !important;
        }

        /* ── Astra ─────────────────────────────────────────────────── */
        body.skales-page .ast-container,
        body.skales-page .site-content .ast-container,
        body.skales-page .ast-separate-container .ast-article-single {
            max-width: 100% !important;
            padding-left: 0 !important;
            padding-right: 0 !important;
        }

        /* ── GeneratePress ─────────────────────────────────────────── */
        body.skales-page .inside-article,
        body.skales-page .site-content .content-area,
        body.skales-page .container.grid-container {
            max-width: 100% !important;
            width: 100% !important;
            padding-left: 0 !important;
            padding-right: 0 !important;
        }

        /* ── Twenty Twenty-Four / block themes ─────────────────────── */
        body.skales-page .wp-site-blocks,
        body.skales-page .wp-block-post-content,
        body.skales-page .is-layout-constrained > :where(:not(.alignleft):not(.alignright):not(.alignfull)),
        body.skales-page .wp-block-group.is-layout-constrained {
            max-width: 100% !important;
            padding-left: 0 !important;
            padding-right: 0 !important;
        }

        /* ── Kadence ───────────────────────────────────────────────── */
        body.skales-page .content-container.site-container,
        body.skales-page .entry-content-wrap {
            max-width: 100% !important;
            padding-left: 0 !important;
            padding-right: 0 !important;
        }

        /* ── OceanWP ───────────────────────────────────────────────── */
        body.skales-page .content-area .site-main,
        body.skales-page #content-wrap .container {
            max-width: 100% !important;
            width: 100% !important;
            padding-left: 0 !important;
            padding-right: 0 !important;
        }

        /* ── Elementor (legacy sections + modern containers) ───────── */
        body.skales-page .elementor-section.elementor-section-boxed > .elementor-container {
            max-width: 100% !important;
        }
        body.skales-page .e-con {
            max-width: 100% !important;
        }

        /* ── Prevent horizontal scrollbar from full-bleed children ── */
        body.skales-page {
            overflow-x: hidden !important;
        }
    </style>';
}

/**
 * Wrap Skales page content in a full-width container with inline styles
 * as a last-resort override for stubborn theme CSS.
 */
add_filter('the_content', 'skales_wrap_content_fullwidth', 999);
function skales_wrap_content_fullwidth($content) {
    if (!is_singular() || !is_main_query() || !in_the_loop()) return $content;
    if (!skales_is_skales_page()) return $content;

    return '<div class="skales-content-wrapper" style="max-width:100%!important;width:100%!important;padding:0!important;margin:0 auto!important;box-sizing:border-box!important;">'
        . $content
        . '</div>';
}