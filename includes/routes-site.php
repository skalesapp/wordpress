<?php
/**
 * Site routes: settings, permalinks, and the read only inventories
 * (plugins, themes, users).
 *
 * @package Skales_Connector
 */

if (!defined('ABSPATH')) exit;

function skales_register_site_routes($ns) {
    register_rest_route($ns, '/settings', [
        [
            'methods'             => 'GET',
            'callback'            => 'skales_route_get_settings',
            'permission_callback' => skales_cap('manage_options'),
        ],
        [
            'methods'             => 'PUT, PATCH, POST',
            'callback'            => 'skales_route_update_settings',
            'permission_callback' => skales_cap('manage_options'),
        ],
    ]);

    register_rest_route($ns, '/permalinks', [
        [
            'methods'             => 'GET',
            'callback'            => 'skales_route_get_permalinks',
            'permission_callback' => skales_cap('manage_options'),
        ],
        [
            'methods'             => 'PUT, PATCH, POST',
            'callback'            => 'skales_route_update_permalinks',
            'permission_callback' => skales_cap('manage_options'),
        ],
    ]);

    // Read only on purpose. Installing or activating code from a remote call
    // would turn a leaked token into remote code execution, and the plugin
    // directory guidelines forbid running externally supplied code. Skales can
    // see what is installed and tell the user what to install; the click stays
    // with the human in wp-admin.
    register_rest_route($ns, '/plugins', [
        'methods'             => 'GET',
        'callback'            => 'skales_route_list_plugins',
        'permission_callback' => skales_cap('activate_plugins'),
    ]);

    register_rest_route($ns, '/themes', [
        'methods'             => 'GET',
        'callback'            => 'skales_route_list_themes',
        'permission_callback' => skales_cap('switch_themes'),
    ]);

    register_rest_route($ns, '/users', [
        'methods'             => 'GET',
        'callback'            => 'skales_route_list_users',
        'permission_callback' => skales_cap('list_users'),
    ]);
}

/**
 * The options the connector may read and write, each with the sanitizer that
 * applies. Anything outside this map is refused, so a payload can never reach
 * an unrelated option row.
 *
 * @return array<string, string>
 */
function skales_settings_map() {
    return [
        // General
        'blogname'                  => 'text',
        'blogdescription'           => 'text',
        'timezone_string'           => 'timezone',
        'gmt_offset'                => 'float',
        'date_format'               => 'text',
        'time_format'               => 'text',
        'start_of_week'             => 'int',
        'blog_public'               => 'int',
        'users_can_register'        => 'bool',
        'default_role'              => 'role',
        'site_icon'                 => 'attachment',
        // Writing
        'default_category'          => 'int',
        'default_post_format'       => 'key',
        // Reading
        'show_on_front'             => 'front',
        'page_on_front'             => 'int',
        'page_for_posts'            => 'int',
        'posts_per_page'            => 'int',
        'posts_per_rss'             => 'int',
        'rss_use_excerpt'           => 'int',
        // Discussion
        'default_comment_status'    => 'openclosed',
        'default_ping_status'       => 'openclosed',
        'comments_notify'           => 'bool',
        'moderation_notify'         => 'bool',
        'comment_moderation'        => 'bool',
        'comment_registration'      => 'bool',
        'require_name_email'        => 'bool',
        'close_comments_for_old_posts' => 'bool',
        'close_comments_days_old'   => 'int',
        'thread_comments'           => 'bool',
        'thread_comments_depth'     => 'int',
        'page_comments'             => 'bool',
        'comments_per_page'         => 'int',
    ];
}

/**
 * @param mixed  $value
 * @param string $type
 * @return mixed|null Null means "refuse this value".
 */
function skales_sanitize_setting($value, $type) {
    switch ($type) {
        case 'text':
            return sanitize_text_field((string) $value);
        case 'key':
            return sanitize_key((string) $value);
        case 'int':
            return (int) $value;
        case 'float':
            return (float) $value;
        case 'bool':
            return $value ? 1 : 0;
        case 'openclosed':
            return $value === 'open' ? 'open' : 'closed';
        case 'front':
            return $value === 'page' ? 'page' : 'posts';
        case 'timezone':
            $tz = (string) $value;
            return in_array($tz, timezone_identifiers_list(), true) ? $tz : null;
        case 'role':
            $role = sanitize_key((string) $value);
            return get_role($role) ? $role : null;
        case 'attachment':
            $id = (int) $value;
            return ($id === 0 || get_post_type($id) === 'attachment') ? $id : null;
    }
    return null;
}

function skales_route_get_settings($request) {
    $out = [];
    foreach (skales_settings_map() as $option => $type) {
        $out[$option] = get_option($option);
    }

    return rest_ensure_response([
        'ok'       => true,
        'settings' => $out,
        'writable' => array_keys(skales_settings_map()),
        'site'     => [
            'url'        => get_site_url(),
            'home'       => get_home_url(),
            'admin_email' => get_option('admin_email'), // read only on purpose
            'language'   => get_locale(),
            'wp_version' => get_bloginfo('version'),
        ],
    ]);
}

function skales_route_update_settings($request) {
    $params = (array) $request->get_json_params();
    if (isset($params['settings']) && is_array($params['settings'])) {
        $params = $params['settings'];
    }

    $map     = skales_settings_map();
    $updated = [];
    $refused = [];

    foreach ($params as $option => $value) {
        $option = (string) $option;
        if (!isset($map[$option])) {
            $refused[$option] = 'not a writable setting';
            continue;
        }

        $clean = skales_sanitize_setting($value, $map[$option]);
        if ($clean === null) {
            $refused[$option] = 'invalid value';
            continue;
        }

        // A static front page needs a page assigned, otherwise the site shows
        // an empty front. Refuse the combination instead of breaking the site.
        if ($option === 'show_on_front' && $clean === 'page') {
            $target = isset($params['page_on_front']) ? (int) $params['page_on_front'] : (int) get_option('page_on_front');
            if (!$target || get_post_type($target) !== 'page') {
                $refused[$option] = 'set page_on_front to an existing page first';
                continue;
            }
        }

        update_option($option, $clean);
        $updated[$option] = $clean;
    }

    if (isset($updated['timezone_string'])) {
        update_option('gmt_offset', '');
    }

    return rest_ensure_response([
        'ok'      => true,
        'updated' => $updated,
        'refused' => $refused,
    ]);
}

// =============================================================================
// PERMALINKS
// =============================================================================

function skales_permalink_presets() {
    return [
        'plain'      => '',
        'day-name'   => '/%year%/%monthnum%/%day%/%postname%/',
        'month-name' => '/%year%/%monthnum%/%postname%/',
        'numeric'    => '/archives/%post_id%',
        'post-name'  => '/%postname%/',
    ];
}

function skales_route_get_permalinks($request) {
    // got_url_rewrite() lives in wp-admin/includes/misc.php, which a REST
    // request does not load on its own.
    require_once ABSPATH . 'wp-admin/includes/misc.php';
    global $wp_rewrite;

    return rest_ensure_response([
        'ok'            => true,
        'structure'     => get_option('permalink_structure'),
        'category_base' => get_option('category_base'),
        'tag_base'      => get_option('tag_base'),
        'presets'       => skales_permalink_presets(),
        'available_tags' => $wp_rewrite ? $wp_rewrite->rewritecode : [],
        'using_index'   => !got_url_rewrite(),
    ]);
}

function skales_route_update_permalinks($request) {
    $params = (array) $request->get_json_params();

    require_once ABSPATH . 'wp-admin/includes/misc.php';
    global $wp_rewrite;

    $changed = [];

    if (isset($params['preset'])) {
        $presets = skales_permalink_presets();
        $preset  = sanitize_key($params['preset']);
        if (!array_key_exists($preset, $presets)) {
            return new WP_Error('bad_preset', 'Unknown preset. Use one of: ' . implode(', ', array_keys($presets)), ['status' => 400]);
        }
        $params['structure'] = $presets[$preset];
    }

    if (isset($params['structure'])) {
        $structure = (string) $params['structure'];

        if ($structure !== '') {
            // Only the characters a permalink structure can legally contain.
            if (!preg_match('#^/?[A-Za-z0-9%/_\-\.]*$#', $structure)) {
                return new WP_Error('bad_structure', 'The permalink structure contains characters WordPress does not allow', ['status' => 400]);
            }
            if (strpos($structure, '..') !== false) {
                return new WP_Error('bad_structure', 'The permalink structure may not contain ".."', ['status' => 400]);
            }
            // A structure with no unique tag makes every post resolve to the
            // same URL. WordPress warns about this in wp-admin; we refuse it.
            $unique = ['%postname%', '%post_id%', '%pagename%'];
            $has_unique = false;
            foreach ($unique as $tag) {
                if (strpos($structure, $tag) !== false) { $has_unique = true; break; }
            }
            if (!$has_unique) {
                return new WP_Error('bad_structure', 'The structure needs %postname% or %post_id% so URLs stay unique', ['status' => 400]);
            }
            if (strpos($structure, '/') !== 0) {
                $structure = '/' . $structure;
            }
        }

        $wp_rewrite->set_permalink_structure($structure);
        $changed['structure'] = $structure;
    }

    if (isset($params['category_base'])) {
        $base = trim(sanitize_title_with_dashes((string) $params['category_base']));
        update_option('category_base', $base);
        $changed['category_base'] = $base;
    }

    if (isset($params['tag_base'])) {
        $base = trim(sanitize_title_with_dashes((string) $params['tag_base']));
        update_option('tag_base', $base);
        $changed['tag_base'] = $base;
    }

    // Hard flush so the rules and, where the server allows it, the .htaccess
    // file match the new structure immediately.
    $wp_rewrite->flush_rules(true);

    return rest_ensure_response([
        'ok'        => true,
        'changed'   => $changed,
        'structure' => get_option('permalink_structure'),
        'sample'    => skales_sample_permalink(),
    ]);
}

/**
 * A real permalink from the site, so the caller can see the effect instead of
 * trusting the structure string.
 *
 * @return string
 */
function skales_sample_permalink() {
    $posts = get_posts(['numberposts' => 1, 'post_status' => 'publish', 'fields' => 'ids']);
    if (empty($posts)) return home_url('/');
    return get_permalink($posts[0]);
}

// =============================================================================
// READ ONLY INVENTORIES
// =============================================================================

function skales_route_list_plugins($request) {
    skales_require_plugin_api();

    $all     = get_plugins();
    $updates = get_site_transient('update_plugins');
    $out     = [];

    foreach ($all as $file => $data) {
        $out[] = [
            'file'          => $file,
            'slug'          => dirname($file) !== '.' ? dirname($file) : basename($file, '.php'),
            'name'          => $data['Name'],
            'version'       => $data['Version'],
            'author'        => wp_strip_all_tags($data['Author']),
            'active'        => is_plugin_active($file),
            'update_available' => isset($updates->response[$file]),
            'new_version'   => isset($updates->response[$file]) ? $updates->response[$file]->new_version : null,
        ];
    }

    return rest_ensure_response([
        'ok'        => true,
        'plugins'   => $out,
        'read_only' => true,
        'note'      => 'Installing, activating and updating plugins stays in wp-admin by design.',
    ]);
}

function skales_route_list_themes($request) {
    $themes  = wp_get_themes();
    $active  = wp_get_theme();
    $updates = get_site_transient('update_themes');
    $out     = [];

    foreach ($themes as $slug => $theme) {
        $out[] = [
            'slug'        => $slug,
            'name'        => $theme->get('Name'),
            'version'     => $theme->get('Version'),
            'author'      => wp_strip_all_tags($theme->get('Author')),
            'active'      => $slug === $active->get_stylesheet(),
            'parent'      => $theme->parent() ? $theme->parent()->get_stylesheet() : null,
            'block_theme' => method_exists($theme, 'is_block_theme') ? $theme->is_block_theme() : false,
            'update_available' => isset($updates->response[$slug]),
        ];
    }

    return rest_ensure_response(['ok' => true, 'themes' => $out, 'read_only' => true]);
}

function skales_route_list_users($request) {
    $users = get_users([
        'number'  => max(1, min(100, (int) ($request->get_param('per_page') ?: 50))),
        'orderby' => 'ID',
    ]);

    return rest_ensure_response([
        'ok'    => true,
        'users' => array_map(function ($u) {
            return [
                'id'    => (int) $u->ID,
                'login' => $u->user_login,
                'name'  => $u->display_name,
                'slug'  => $u->user_nicename,
                'roles' => array_values($u->roles),
            ];
        }, $users),
        'read_only' => true,
    ]);
}
