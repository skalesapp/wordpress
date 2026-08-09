<?php
/**
 * SEO meta for RankMath and Yoast, plus the site wide bits a content manager
 * touches (search engine visibility, canonical, robots).
 *
 * @package Skales_Connector
 */

if (!defined('ABSPATH')) exit;

function skales_register_seo_routes($ns) {
    register_rest_route($ns, '/seo/(?P<id>\d+)', [
        [
            'methods'             => 'GET',
            'callback'            => 'skales_route_get_seo',
            'permission_callback' => skales_cap('edit_posts'),
        ],
        [
            'methods'             => 'PUT, PATCH, POST',
            'callback'            => 'skales_route_update_seo',
            'permission_callback' => skales_cap('edit_posts'),
        ],
    ]);
}

/**
 * Which SEO plugin is answering, and under which meta keys.
 *
 * @return array<string, array<string, string>>
 */
function skales_seo_targets() {
    skales_require_plugin_api();
    $targets = [];

    if (is_plugin_active('seo-by-rank-math/rank-math.php')) {
        $targets['rankmath'] = [
            'seo_title'       => 'rank_math_title',
            'seo_description' => 'rank_math_description',
            'focus_keyword'   => 'rank_math_focus_keyword',
            'canonical'       => 'rank_math_canonical_url',
        ];
    }

    if (is_plugin_active('wordpress-seo/wp-seo.php')) {
        $targets['yoast'] = [
            'seo_title'       => '_yoast_wpseo_title',
            'seo_description' => '_yoast_wpseo_metadesc',
            'focus_keyword'   => '_yoast_wpseo_focuskw',
            'canonical'       => '_yoast_wpseo_canonical',
        ];
    }

    return $targets;
}

function skales_route_get_seo($request) {
    $post_id = (int) $request['id'];
    if (!get_post($post_id)) {
        return new WP_Error('not_found', 'Post not found', ['status' => 404]);
    }

    $out = [];
    foreach (skales_seo_targets() as $plugin => $keys) {
        $values = [];
        foreach ($keys as $field => $meta_key) {
            $values[$field] = get_post_meta($post_id, $meta_key, true);
        }
        $out[$plugin] = $values;
    }

    return rest_ensure_response([
        'ok'      => true,
        'post_id' => $post_id,
        'seo'     => $out,
        'plugins' => array_keys($out),
        'site_indexable' => (bool) get_option('blog_public'),
    ]);
}

function skales_route_update_seo($request) {
    $post_id = (int) $request['id'];
    if (!get_post($post_id)) {
        return new WP_Error('not_found', 'Post not found', ['status' => 404]);
    }
    if (!current_user_can('edit_post', $post_id)) {
        return new WP_Error('forbidden', 'Not allowed to edit this item', ['status' => 403]);
    }

    $params  = (array) $request->get_json_params();
    $targets = skales_seo_targets();
    $written = [];

    foreach ($targets as $plugin => $keys) {
        foreach ($keys as $field => $meta_key) {
            if (!isset($params[$field])) continue;

            $value = $field === 'canonical'
                ? esc_url_raw($params[$field])
                : sanitize_text_field($params[$field]);

            update_post_meta($post_id, $meta_key, $value);
            $written[$plugin][$field] = $value;
        }
    }

    return rest_ensure_response([
        'ok'      => true,
        'post_id' => $post_id,
        'written' => $written,
        // Saying so beats silently doing nothing when neither plugin is active.
        'note'    => empty($targets) ? 'No supported SEO plugin is active, nothing was written.' : '',
    ]);
}
