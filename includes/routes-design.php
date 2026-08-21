<?php
/**
 * Design routes: theme mods and the Customizer surface, global styles for
 * block themes, custom CSS, site identity, menus and widgets.
 *
 * None of this has a core REST endpoint. The Customizer stores theme mods, the
 * permalink screen writes options, and widgets live in a pair of option rows,
 * so the connector has to reach them itself. This file is the part of the
 * plugin that a content manager actually needs and that WordPress does not
 * hand out.
 *
 * @package Skales_Connector
 */

if (!defined('ABSPATH')) exit;

function skales_register_design_routes($ns) {
    register_rest_route($ns, '/theme', [
        'methods'             => 'GET',
        'callback'            => 'skales_route_get_theme',
        'permission_callback' => skales_cap('edit_theme_options'),
    ]);

    register_rest_route($ns, '/theme/mods', [
        [
            'methods'             => 'GET',
            'callback'            => 'skales_route_get_theme_mods',
            'permission_callback' => skales_cap('edit_theme_options'),
        ],
        [
            'methods'             => 'PUT, PATCH, POST',
            'callback'            => 'skales_route_update_theme_mods',
            'permission_callback' => skales_cap('edit_theme_options'),
        ],
    ]);

    register_rest_route($ns, '/theme/css', [
        [
            'methods'             => 'GET',
            'callback'            => 'skales_route_get_custom_css',
            'permission_callback' => skales_cap('edit_theme_options'),
        ],
        [
            'methods'             => 'PUT, PATCH, POST',
            'callback'            => 'skales_route_update_custom_css',
            // Site wide CSS is gated at edit_css in wp-admin, which resolves to
            // unfiltered_html. edit_theme_options is the weaker right that opens
            // the Customizer, and wp_update_custom_css_post() checks nothing on
            // its own, so asking for it here would let an account write CSS it
            // could not write in the Customizer.
            'permission_callback' => skales_cap('edit_css'),
        ],
    ]);

    register_rest_route($ns, '/theme/global-styles', [
        [
            'methods'             => 'GET',
            'callback'            => 'skales_route_get_global_styles',
            'permission_callback' => skales_cap('edit_theme_options'),
        ],
        [
            'methods'             => 'PUT, PATCH, POST',
            'callback'            => 'skales_route_update_global_styles',
            'permission_callback' => skales_cap('edit_theme_options'),
        ],
    ]);

    register_rest_route($ns, '/site-identity', [
        [
            'methods'             => 'GET',
            'callback'            => 'skales_route_get_site_identity',
            'permission_callback' => skales_cap('edit_theme_options'),
        ],
        [
            'methods'             => 'PUT, PATCH, POST',
            'callback'            => 'skales_route_update_site_identity',
            'permission_callback' => skales_cap('edit_theme_options'),
        ],
    ]);

    // ── Menus ───────────────────────────────────────────────────────────
    register_rest_route($ns, '/menus', [
        [
            'methods'             => 'GET',
            'callback'            => 'skales_route_list_menus',
            'permission_callback' => skales_cap('edit_theme_options'),
        ],
        [
            'methods'             => 'POST',
            'callback'            => 'skales_route_create_menu',
            'permission_callback' => skales_cap('edit_theme_options'),
        ],
    ]);

    register_rest_route($ns, '/menus/(?P<id>\d+)', [
        [
            'methods'             => 'PUT, PATCH',
            'callback'            => 'skales_route_update_menu',
            'permission_callback' => skales_cap('edit_theme_options'),
        ],
        [
            'methods'             => 'DELETE',
            'callback'            => 'skales_route_delete_menu',
            'permission_callback' => skales_cap('edit_theme_options'),
        ],
    ]);

    // ── Widgets ─────────────────────────────────────────────────────────
    register_rest_route($ns, '/widgets', [
        [
            'methods'             => 'GET',
            'callback'            => 'skales_route_list_widgets',
            'permission_callback' => skales_cap('edit_theme_options'),
        ],
        [
            'methods'             => 'POST',
            'callback'            => 'skales_route_create_widget',
            'permission_callback' => skales_cap('edit_theme_options'),
        ],
    ]);

    register_rest_route($ns, '/widgets/(?P<id>[\w\-]+)', [
        [
            'methods'             => 'PUT, PATCH',
            'callback'            => 'skales_route_update_widget',
            'permission_callback' => skales_cap('edit_theme_options'),
        ],
        [
            'methods'             => 'DELETE',
            'callback'            => 'skales_route_delete_widget',
            'permission_callback' => skales_cap('edit_theme_options'),
        ],
    ]);
}

// =============================================================================
// THEME AND CUSTOMIZER
// =============================================================================

function skales_route_get_theme($request) {
    $theme       = wp_get_theme();
    $is_block    = function_exists('wp_is_block_theme') ? wp_is_block_theme() : false;

    return rest_ensure_response([
        'ok' => true,
        'theme' => [
            'name'        => $theme->get('Name'),
            'stylesheet'  => $theme->get_stylesheet(),
            'template'    => $theme->get_template(),
            'version'     => $theme->get('Version'),
            'block_theme' => $is_block,
            'supports'    => [
                'custom-logo'       => (bool) get_theme_support('custom-logo'),
                'custom-background' => (bool) get_theme_support('custom-background'),
                'custom-header'     => (bool) get_theme_support('custom-header'),
                'editor-color-palette' => (bool) get_theme_support('editor-color-palette'),
            ],
        ],
        'mods'            => get_theme_mods() ?: [],
        'menu_locations'  => get_registered_nav_menus(),
        'assigned_menus'  => get_nav_menu_locations(),
        'sidebars'        => skales_sidebar_index(),
        // On a block theme the Customizer is mostly gone: colours, typography
        // and layout live in global styles instead. Say which lever works here
        // so the caller does not write a mod that renders nothing.
        'design_surface'  => $is_block ? 'global-styles' : 'theme-mods',
    ]);
}

function skales_route_get_theme_mods($request) {
    return rest_ensure_response([
        'ok'   => true,
        'mods' => get_theme_mods() ?: [],
        'theme' => get_stylesheet(),
    ]);
}

function skales_route_update_theme_mods($request) {
    $params = (array) $request->get_json_params();
    $mods   = isset($params['mods']) && is_array($params['mods']) ? $params['mods'] : $params;

    $updated = [];
    $refused = [];

    foreach ($mods as $key => $value) {
        $key = sanitize_key((string) $key);
        if ($key === '') continue;

        if ($value === null) {
            remove_theme_mod($key);
            $updated[$key] = null;
            continue;
        }

        // custom_logo is an attachment id, not a URL. Accept either and store
        // the id, because a URL there silently produces no logo at all.
        if ($key === 'custom_logo') {
            $id = skales_resolve_attachment($value);
            if (!$id) { $refused[$key] = 'not an attachment'; continue; }
            set_theme_mod('custom_logo', $id);
            $updated[$key] = $id;
            continue;
        }

        if (in_array($key, ['background_color', 'header_textcolor'], true)) {
            $color = sanitize_hex_color_no_hash((string) $value);
            if ($color === null) { $refused[$key] = 'not a hex colour'; continue; }
            set_theme_mod($key, $color);
            $updated[$key] = $color;
            continue;
        }

        if ($key === 'nav_menu_locations') {
            $refused[$key] = 'use PUT /menus/{id} with "locations" instead';
            continue;
        }

        // custom_css_post_id decides which post wp_get_custom_css() reads, so
        // writing it as a plain mod would point the site's stylesheet at
        // arbitrary content. The CSS itself has its own route.
        if ($key === 'custom_css_post_id') {
            $refused[$key] = 'use PUT /theme/css instead';
            continue;
        }

        if (is_scalar($value)) {
            set_theme_mod($key, sanitize_text_field((string) $value));
            $updated[$key] = $value;
        } elseif (is_array($value)) {
            set_theme_mod($key, map_deep($value, 'sanitize_text_field'));
            $updated[$key] = $value;
        } else {
            $refused[$key] = 'unsupported value type';
        }
    }

    return rest_ensure_response(['ok' => true, 'updated' => $updated, 'refused' => $refused]);
}

/**
 * Accept an attachment id or a URL that points into this site's media library.
 *
 * @param mixed $value
 * @return int 0 when it cannot be resolved.
 */
function skales_resolve_attachment($value) {
    if (is_numeric($value)) {
        $id = (int) $value;
        return get_post_type($id) === 'attachment' ? $id : 0;
    }
    if (is_string($value) && $value !== '') {
        $id = attachment_url_to_postid($value);
        return $id ? (int) $id : 0;
    }
    return 0;
}

function skales_route_get_custom_css($request) {
    return rest_ensure_response([
        'ok'    => true,
        'css'   => wp_get_custom_css(),
        'theme' => get_stylesheet(),
    ]);
}

function skales_route_update_custom_css($request) {
    $params = (array) $request->get_json_params();
    $css    = (string) ($params['css'] ?? '');

    if (isset($params['append']) && $params['append']) {
        $css = rtrim(wp_get_custom_css()) . "\n" . $css;
    }

    // wp_update_custom_css_post() runs the same validation the Customizer uses
    // and stores the CSS as a revisioned post, so the site owner can roll back.
    $result = wp_update_custom_css_post($css);
    if (is_wp_error($result)) {
        return new WP_Error('css_failed', $result->get_error_message(), ['status' => 400]);
    }

    return rest_ensure_response(['ok' => true, 'bytes' => strlen($css)]);
}

// =============================================================================
// GLOBAL STYLES (block themes)
// =============================================================================

function skales_global_styles_post_id() {
    if (!class_exists('WP_Theme_JSON_Resolver')) return 0;
    if (method_exists('WP_Theme_JSON_Resolver', 'get_user_global_styles_post_id')) {
        return (int) WP_Theme_JSON_Resolver::get_user_global_styles_post_id();
    }
    return 0;
}

function skales_route_get_global_styles($request) {
    $id = skales_global_styles_post_id();
    if (!$id) {
        return new WP_Error('no_global_styles', 'This WordPress or theme does not use global styles', ['status' => 400]);
    }

    $post = get_post($id);
    $data = json_decode($post->post_content, true);

    return rest_ensure_response([
        'ok'       => true,
        'id'       => $id,
        'styles'   => isset($data['styles']) ? $data['styles'] : [],
        'settings' => isset($data['settings']) ? $data['settings'] : [],
        'raw'      => $data ?: [],
    ]);
}

function skales_route_update_global_styles($request) {
    $id = skales_global_styles_post_id();
    if (!$id) {
        return new WP_Error('no_global_styles', 'This WordPress or theme does not use global styles', ['status' => 400]);
    }

    $params  = (array) $request->get_json_params();
    $post    = get_post($id);
    $current = json_decode($post->post_content, true);
    if (!is_array($current)) {
        $current = ['version' => 2, 'isGlobalStylesUserThemeJSON' => true];
    }

    $merge = (bool) ($params['merge'] ?? true);

    foreach (['styles', 'settings'] as $section) {
        if (!isset($params[$section]) || !is_array($params[$section])) continue;
        if ($merge && isset($current[$section]) && is_array($current[$section])) {
            $current[$section] = skales_deep_merge($current[$section], $params[$section]);
        } else {
            $current[$section] = $params[$section];
        }
    }

    $current = skales_validate_global_styles($current);

    $encoded = wp_json_encode($current);
    if ($encoded === false) {
        return new WP_Error('encode_failed', 'Could not encode the global styles payload', ['status' => 400]);
    }

    $result = wp_update_post(['ID' => $id, 'post_content' => wp_slash($encoded)], true);
    if (is_wp_error($result)) {
        return new WP_Error('update_failed', $result->get_error_message(), ['status' => 500]);
    }

    // Stylesheets for the front end are cached per theme.
    if (function_exists('wp_clean_theme_json_cache')) {
        wp_clean_theme_json_cache();
    }

    return rest_ensure_response(['ok' => true, 'id' => $id, 'styles' => $current]);
}

/**
 * Put a global styles payload through the theme.json machinery before it is
 * stored.
 *
 * Until now the merged JSON went to the database exactly as it arrived, and the
 * only thing standing between it and the front end was that
 * WP_Theme_JSON_Resolver strips insecure properties when it reads. Relying on
 * the reader means anything that reads the post differently gets the raw
 * payload. WP_Theme_JSON's own sanitiser drops keys and block names that do not
 * exist, and where the account may not write CSS the insecure properties (the
 * `css` escape hatch among them) are removed here rather than later.
 *
 * @param array $config
 * @return array
 */
function skales_validate_global_styles($config) {
    $config['isGlobalStylesUserThemeJSON'] = true;
    if (empty($config['version'])) {
        $config['version'] = class_exists('WP_Theme_JSON') && defined('WP_Theme_JSON::LATEST_SCHEMA')
            ? WP_Theme_JSON::LATEST_SCHEMA
            : 2;
    }

    if (!class_exists('WP_Theme_JSON')) {
        return $config;
    }

    if (!current_user_can('edit_css') && method_exists('WP_Theme_JSON', 'remove_insecure_properties')) {
        // The second argument was added later; a version that does not take it
        // simply ignores it.
        $filtered = WP_Theme_JSON::remove_insecure_properties($config, 'custom');
        if (is_array($filtered)) {
            $config = $filtered;
        }
    }

    $theme_json = new WP_Theme_JSON($config, 'custom');
    $sanitized  = $theme_json->get_raw_data();
    if (is_array($sanitized) && !empty($sanitized)) {
        $config = $sanitized;
    }

    $config['isGlobalStylesUserThemeJSON'] = true;

    return $config;
}

/**
 * Recursive array merge where later values win and nested maps are merged
 * rather than replaced.
 *
 * @param array $base
 * @param array $patch
 * @return array
 */
function skales_deep_merge($base, $patch) {
    foreach ($patch as $key => $value) {
        if (is_array($value) && isset($base[$key]) && is_array($base[$key])) {
            $base[$key] = skales_deep_merge($base[$key], $value);
        } else {
            $base[$key] = $value;
        }
    }
    return $base;
}

// =============================================================================
// SITE IDENTITY
// =============================================================================

function skales_route_get_site_identity($request) {
    $logo_id = (int) get_theme_mod('custom_logo');
    $icon_id = (int) get_option('site_icon');

    return rest_ensure_response([
        'ok'          => true,
        'title'       => get_option('blogname'),
        'tagline'     => get_option('blogdescription'),
        'logo'        => $logo_id ? ['id' => $logo_id, 'url' => wp_get_attachment_url($logo_id)] : null,
        'site_icon'   => $icon_id ? ['id' => $icon_id, 'url' => wp_get_attachment_url($icon_id)] : null,
    ]);
}

function skales_route_update_site_identity($request) {
    $params  = (array) $request->get_json_params();
    $updated = [];

    if (isset($params['title'])) {
        update_option('blogname', sanitize_text_field($params['title']));
        $updated['title'] = get_option('blogname');
    }
    if (isset($params['tagline'])) {
        update_option('blogdescription', sanitize_text_field($params['tagline']));
        $updated['tagline'] = get_option('blogdescription');
    }
    if (isset($params['logo'])) {
        if ($params['logo'] === null || $params['logo'] === 0 || $params['logo'] === '') {
            remove_theme_mod('custom_logo');
            $updated['logo'] = null;
        } else {
            $id = skales_resolve_attachment($params['logo']);
            if (!$id) return new WP_Error('bad_logo', 'logo must be an attachment id or a media library URL', ['status' => 400]);
            set_theme_mod('custom_logo', $id);
            $updated['logo'] = $id;
        }
    }
    if (isset($params['site_icon'])) {
        if ($params['site_icon'] === null || $params['site_icon'] === 0 || $params['site_icon'] === '') {
            delete_option('site_icon');
            $updated['site_icon'] = null;
        } else {
            $id = skales_resolve_attachment($params['site_icon']);
            if (!$id) return new WP_Error('bad_icon', 'site_icon must be an attachment id or a media library URL', ['status' => 400]);
            update_option('site_icon', $id);
            $updated['site_icon'] = $id;
        }
    }

    return rest_ensure_response(['ok' => true, 'updated' => $updated]);
}

// =============================================================================
// MENUS
// =============================================================================

function skales_format_menu($menu) {
    $items     = wp_get_nav_menu_items($menu->term_id) ?: [];
    $locations = array_keys(get_nav_menu_locations(), $menu->term_id, true);

    return [
        'id'        => (int) $menu->term_id,
        'name'      => $menu->name,
        'slug'      => $menu->slug,
        'count'     => (int) $menu->count,
        'locations' => array_values($locations),
        'items'     => array_map(function ($item) {
            return [
                'id'        => (int) $item->ID,
                'title'     => $item->title,
                'url'       => $item->url,
                'type'      => $item->type,
                'object'    => $item->object,
                'object_id' => (int) $item->object_id,
                'parent'    => (int) $item->menu_item_parent,
                'order'     => (int) $item->menu_order,
                'target'    => $item->target,
                'classes'   => array_values(array_filter((array) $item->classes)),
            ];
        }, $items),
    ];
}

function skales_route_list_menus($request) {
    $menus = wp_get_nav_menus();

    return rest_ensure_response([
        'ok'        => true,
        'menus'     => array_map('skales_format_menu', $menus),
        'locations' => get_registered_nav_menus(),
        'assigned'  => get_nav_menu_locations(),
    ]);
}

function skales_route_create_menu($request) {
    $params = (array) $request->get_json_params();
    $name   = sanitize_text_field($params['name'] ?? '');
    if ($name === '') {
        return new WP_Error('missing_name', 'A menu name is required', ['status' => 400]);
    }

    $menu_id = wp_create_nav_menu($name);
    if (is_wp_error($menu_id)) {
        return new WP_Error('menu_failed', $menu_id->get_error_message(), ['status' => 400]);
    }

    if (!empty($params['items'])) {
        $error = skales_replace_menu_items((int) $menu_id, $params['items']);
        if (is_wp_error($error)) return $error;
    }
    if (isset($params['locations'])) {
        skales_assign_menu_locations((int) $menu_id, (array) $params['locations']);
    }

    return rest_ensure_response(['ok' => true, 'menu_id' => (int) $menu_id, 'menu' => skales_format_menu(wp_get_nav_menu_object($menu_id))]);
}

function skales_route_update_menu($request) {
    $menu_id = (int) $request['id'];
    $menu    = wp_get_nav_menu_object($menu_id);
    if (!$menu) {
        return new WP_Error('not_found', 'Menu not found', ['status' => 404]);
    }

    $params = (array) $request->get_json_params();

    if (isset($params['name'])) {
        wp_update_nav_menu_object($menu_id, ['menu-name' => sanitize_text_field($params['name'])]);
    }
    if (isset($params['items'])) {
        $error = skales_replace_menu_items($menu_id, (array) $params['items']);
        if (is_wp_error($error)) return $error;
    }
    if (isset($params['locations'])) {
        skales_assign_menu_locations($menu_id, (array) $params['locations']);
    }

    return rest_ensure_response(['ok' => true, 'menu_id' => $menu_id, 'menu' => skales_format_menu(wp_get_nav_menu_object($menu_id))]);
}

function skales_route_delete_menu($request) {
    $menu_id = (int) $request['id'];
    if (!wp_get_nav_menu_object($menu_id)) {
        return new WP_Error('not_found', 'Menu not found', ['status' => 404]);
    }
    $result = wp_delete_nav_menu($menu_id);
    if (is_wp_error($result) || !$result) {
        return new WP_Error('delete_failed', 'Could not delete the menu', ['status' => 500]);
    }
    return rest_ensure_response(['ok' => true, 'id' => $menu_id]);
}

/**
 * Replace every item in a menu with the supplied list.
 *
 * A whole list beats item level CRUD here: menu order and parent references
 * only make sense together, and one round trip that always leaves a coherent
 * menu is easier to drive than five that can leave it half rebuilt.
 *
 * @param int   $menu_id
 * @param array $items
 * @return true|WP_Error
 */
function skales_replace_menu_items($menu_id, $items) {
    $existing = wp_get_nav_menu_items($menu_id) ?: [];
    foreach ($existing as $item) {
        wp_delete_post($item->ID, true);
    }

    // Client side keys let a child point at its parent before the parent has a
    // database id.
    $key_to_id = [];
    $position  = 0;

    foreach ((array) $items as $item) {
        if (!is_array($item)) continue;
        $position++;

        $type   = sanitize_key($item['type'] ?? 'custom');
        $args   = [
            'menu-item-title'     => sanitize_text_field($item['title'] ?? ''),
            'menu-item-status'    => 'publish',
            'menu-item-position'  => isset($item['order']) ? (int) $item['order'] : $position,
            'menu-item-target'    => (isset($item['target']) && $item['target'] === '_blank') ? '_blank' : '',
            'menu-item-classes'   => isset($item['classes']) ? implode(' ', array_map('sanitize_html_class', (array) $item['classes'])) : '',
            'menu-item-attr-title'=> sanitize_text_field($item['attr_title'] ?? ''),
            'menu-item-description' => sanitize_text_field($item['description'] ?? ''),
        ];

        if ($type === 'page' || $type === 'post' || $type === 'post_type') {
            $object_id = (int) ($item['object_id'] ?? 0);
            if (!$object_id || !get_post($object_id)) {
                return new WP_Error('bad_menu_item', 'Menu item of type ' . $type . ' needs an existing object_id', ['status' => 400]);
            }
            $args['menu-item-type']      = 'post_type';
            $args['menu-item-object']    = get_post_type($object_id);
            $args['menu-item-object-id'] = $object_id;
            if ($args['menu-item-title'] === '') {
                $args['menu-item-title'] = get_the_title($object_id);
            }
        } elseif ($type === 'taxonomy' || $type === 'category' || $type === 'tag') {
            $object_id = (int) ($item['object_id'] ?? 0);
            $term      = $object_id ? get_term($object_id) : null;
            if (!$term || is_wp_error($term)) {
                return new WP_Error('bad_menu_item', 'Menu item of type ' . $type . ' needs an existing term object_id', ['status' => 400]);
            }
            $args['menu-item-type']      = 'taxonomy';
            $args['menu-item-object']    = $term->taxonomy;
            $args['menu-item-object-id'] = $object_id;
            if ($args['menu-item-title'] === '') {
                $args['menu-item-title'] = $term->name;
            }
        } else {
            $url = esc_url_raw($item['url'] ?? '');
            if ($url === '') {
                return new WP_Error('bad_menu_item', 'A custom menu item needs a url', ['status' => 400]);
            }
            $args['menu-item-type'] = 'custom';
            $args['menu-item-url']  = $url;
        }

        if (isset($item['parent'])) {
            $parent = $item['parent'];
            if (isset($key_to_id[(string) $parent])) {
                $args['menu-item-parent-id'] = $key_to_id[(string) $parent];
            } elseif (is_numeric($parent)) {
                $args['menu-item-parent-id'] = (int) $parent;
            }
        }

        $item_id = wp_update_nav_menu_item($menu_id, 0, $args);
        if (is_wp_error($item_id)) {
            return new WP_Error('menu_item_failed', $item_id->get_error_message(), ['status' => 400]);
        }

        if (isset($item['key'])) {
            $key_to_id[(string) $item['key']] = (int) $item_id;
        }
    }

    return true;
}

/**
 * Point one or more registered theme locations at a menu.
 *
 * @param int   $menu_id
 * @param array $locations
 * @return void
 */
function skales_assign_menu_locations($menu_id, $locations) {
    $registered = get_registered_nav_menus();
    $current    = get_nav_menu_locations();

    // Drop this menu from any location it currently holds, then set the ones
    // that were asked for, so the payload is the full truth.
    foreach ($current as $location => $assigned) {
        if ((int) $assigned === (int) $menu_id) {
            unset($current[$location]);
        }
    }

    foreach ($locations as $location) {
        $location = sanitize_key((string) $location);
        if (isset($registered[$location])) {
            $current[$location] = (int) $menu_id;
        }
    }

    set_theme_mod('nav_menu_locations', $current);
}

// =============================================================================
// WIDGETS
// =============================================================================

/**
 * Every registered sidebar with the widgets it currently holds.
 *
 * @return array
 */
function skales_sidebar_index() {
    global $wp_registered_sidebars;

    $sidebars_widgets = wp_get_sidebars_widgets();
    $out = [];

    foreach ((array) $wp_registered_sidebars as $id => $sidebar) {
        $out[] = [
            'id'          => $id,
            'name'        => $sidebar['name'],
            'description' => $sidebar['description'],
            'widgets'     => array_map('skales_format_widget', array_values((array) ($sidebars_widgets[$id] ?? []))),
        ];
    }

    // Widgets parked out of sight still exist and can be moved back, so the
    // inventory has to show them.
    $out[] = [
        'id'          => 'wp_inactive_widgets',
        'name'        => __('Inactive widgets', 'skales-connector'),
        'description' => __('Widgets that are stored but not displayed.', 'skales-connector'),
        'widgets'     => array_map('skales_format_widget', array_values((array) ($sidebars_widgets['wp_inactive_widgets'] ?? []))),
    ];

    return $out;
}

/**
 * Split a widget instance id ("text-3") into its base and index.
 *
 * @param string $widget_id
 * @return array{0:string,1:int}|null
 */
function skales_split_widget_id($widget_id) {
    if (!preg_match('/^(.+)-(\d+)$/', (string) $widget_id, $m)) return null;
    return [$m[1], (int) $m[2]];
}

function skales_format_widget($widget_id) {
    $parts = skales_split_widget_id($widget_id);
    if (!$parts) {
        return ['id' => $widget_id, 'id_base' => $widget_id, 'settings' => []];
    }
    list($base, $index) = $parts;

    $instances = get_option('widget_' . $base, []);
    $settings  = isset($instances[$index]) && is_array($instances[$index]) ? $instances[$index] : [];

    return [
        'id'       => $widget_id,
        'id_base'  => $base,
        'number'   => $index,
        'settings' => $settings,
    ];
}

function skales_route_list_widgets($request) {
    global $wp_widget_factory;

    $types = [];
    if ($wp_widget_factory) {
        foreach ($wp_widget_factory->widgets as $widget) {
            $types[] = [
                'id_base'     => $widget->id_base,
                'name'        => $widget->name,
                'description' => isset($widget->widget_options['description']) ? $widget->widget_options['description'] : '',
            ];
        }
    }

    return rest_ensure_response([
        'ok'           => true,
        'sidebars'     => skales_sidebar_index(),
        'widget_types' => $types,
        'block_theme'  => function_exists('wp_is_block_theme') ? wp_is_block_theme() : false,
    ]);
}

/**
 * Is this a sidebar a widget may live in? wp_inactive_widgets is where
 * WordPress parks widgets that are not on display; it is a valid destination
 * but it is not in the registered sidebar list.
 *
 * @param string $sidebar_id
 * @return bool
 */
function skales_sidebar_exists($sidebar_id) {
    global $wp_registered_sidebars;
    if ($sidebar_id === 'wp_inactive_widgets') return true;
    return isset($wp_registered_sidebars[$sidebar_id]);
}

function skales_route_create_widget($request) {
    global $wp_widget_factory;

    $params  = (array) $request->get_json_params();
    $base    = sanitize_key($params['id_base'] ?? '');
    $sidebar = sanitize_key($params['sidebar'] ?? '');

    if ($base === '' || skales_widget_class_for_base($base) === '') {
        return new WP_Error('bad_widget', 'Unknown widget id_base: ' . $base, ['status' => 400]);
    }
    if (!skales_sidebar_exists($sidebar)) {
        return new WP_Error('bad_sidebar', 'Unknown sidebar: ' . $sidebar, ['status' => 400]);
    }

    $instances = get_option('widget_' . $base, []);
    if (!is_array($instances)) $instances = [];

    $next = 1;
    foreach (array_keys($instances) as $key) {
        if (is_numeric($key) && (int) $key >= $next) $next = (int) $key + 1;
    }

    $instances[$next]  = skales_sanitize_widget_settings($params['settings'] ?? []);
    $instances['_multiwidget'] = 1;
    update_option('widget_' . $base, $instances);

    $widget_id        = $base . '-' . $next;
    $sidebars_widgets = wp_get_sidebars_widgets();
    if (!isset($sidebars_widgets[$sidebar]) || !is_array($sidebars_widgets[$sidebar])) {
        $sidebars_widgets[$sidebar] = [];
    }

    $position = isset($params['position']) ? max(0, (int) $params['position']) : count($sidebars_widgets[$sidebar]);
    array_splice($sidebars_widgets[$sidebar], $position, 0, [$widget_id]);
    wp_set_sidebars_widgets($sidebars_widgets);

    return rest_ensure_response(['ok' => true, 'widget_id' => $widget_id, 'widget' => skales_format_widget($widget_id)]);
}

function skales_route_update_widget($request) {
    $widget_id = (string) $request['id'];
    $parts     = skales_split_widget_id($widget_id);
    if (!$parts) {
        return new WP_Error('bad_widget', 'Malformed widget id', ['status' => 400]);
    }
    list($base, $index) = $parts;

    // The option row is derived from the id in the URL, so the base has to name
    // a registered widget before it is used to address one - the same check the
    // create path already makes.
    if (skales_widget_class_for_base($base) === '') {
        return new WP_Error('bad_widget', 'Unknown widget id_base: ' . $base, ['status' => 400]);
    }

    $params    = (array) $request->get_json_params();
    $instances = get_option('widget_' . $base, []);
    if (!is_array($instances) || !isset($instances[$index])) {
        return new WP_Error('not_found', 'Widget not found', ['status' => 404]);
    }

    if (isset($params['settings'])) {
        $instances[$index] = array_merge(
            (array) $instances[$index],
            skales_sanitize_widget_settings($params['settings'])
        );
        update_option('widget_' . $base, $instances);
    }

    if (isset($params['sidebar']) || isset($params['position'])) {
        $sidebars = wp_get_sidebars_widgets();

        $target = isset($params['sidebar']) ? sanitize_key($params['sidebar']) : null;
        if ($target !== null && !skales_sidebar_exists($target)) {
            return new WP_Error('bad_sidebar', 'Unknown sidebar: ' . $target, ['status' => 400]);
        }

        $from = null;
        foreach ($sidebars as $sidebar_id => $widgets) {
            if (!is_array($widgets)) continue;
            $pos = array_search($widget_id, $widgets, true);
            if ($pos !== false) {
                $from = $sidebar_id;
                array_splice($sidebars[$sidebar_id], $pos, 1);
                break;
            }
        }

        $target = $target ?? $from ?? 'wp_inactive_widgets';
        if (!isset($sidebars[$target]) || !is_array($sidebars[$target])) $sidebars[$target] = [];

        $position = isset($params['position']) ? max(0, (int) $params['position']) : count($sidebars[$target]);
        array_splice($sidebars[$target], $position, 0, [$widget_id]);
        wp_set_sidebars_widgets($sidebars);
    }

    return rest_ensure_response(['ok' => true, 'widget_id' => $widget_id, 'widget' => skales_format_widget($widget_id)]);
}

function skales_route_delete_widget($request) {
    $widget_id = (string) $request['id'];
    $parts     = skales_split_widget_id($widget_id);
    if (!$parts) {
        return new WP_Error('bad_widget', 'Malformed widget id', ['status' => 400]);
    }
    list($base, $index) = $parts;

    if (skales_widget_class_for_base($base) === '') {
        return new WP_Error('bad_widget', 'Unknown widget id_base: ' . $base, ['status' => 400]);
    }

    $instances = get_option('widget_' . $base, []);
    if (is_array($instances) && isset($instances[$index])) {
        unset($instances[$index]);
        update_option('widget_' . $base, $instances);
    }

    $sidebars = wp_get_sidebars_widgets();
    foreach ($sidebars as $sidebar_id => $widgets) {
        if (!is_array($widgets)) continue;
        $pos = array_search($widget_id, $widgets, true);
        if ($pos !== false) {
            array_splice($sidebars[$sidebar_id], $pos, 1);
        }
    }
    wp_set_sidebars_widgets($sidebars);

    return rest_ensure_response(['ok' => true, 'id' => $widget_id]);
}

/**
 * Find the registered widget class for an id_base.
 *
 * @param string $base
 * @return string Empty string when nothing matches.
 */
function skales_widget_class_for_base($base) {
    global $wp_widget_factory;
    if (!$wp_widget_factory) return '';
    foreach ($wp_widget_factory->widgets as $class => $widget) {
        if ($widget->id_base === $base) return $class;
    }
    return '';
}

/**
 * Widget settings are stored serialised and rendered on the front end, so the
 * values are cleaned before they are written. Widgets that legitimately carry
 * markup (text, custom_html, block) keep it, filtered through wp_kses_post
 * unless the linked account may post unfiltered HTML.
 *
 * @param mixed $settings
 * @return array
 */
function skales_sanitize_widget_settings($settings) {
    if (!is_array($settings)) return [];

    $html_keys = ['text', 'content'];
    $clean     = [];

    foreach ($settings as $key => $value) {
        $key = sanitize_key((string) $key);
        if ($key === '') continue;

        if (is_array($value)) {
            $clean[$key] = map_deep($value, 'sanitize_text_field');
            continue;
        }
        if (is_bool($value) || is_int($value) || is_float($value)) {
            $clean[$key] = $value;
            continue;
        }

        $value = (string) $value;
        if (in_array($key, $html_keys, true)) {
            $clean[$key] = current_user_can('unfiltered_html') ? $value : wp_kses_post($value);
        } else {
            $clean[$key] = sanitize_text_field($value);
        }
    }

    return $clean;
}
