<?php
/**
 * Abilities API registration.
 *
 * WordPress 6.9 introduced the Abilities API and the MCP Adapter builds on it:
 * an ability registered here is discoverable by core, by the block editor's AI
 * surfaces and by any MCP client the site owner installs, without a second
 * implementation.
 *
 * The REST namespace in this plugin stays the primary wire for Skales itself.
 * It works from WordPress 5.6, it carries the token that identifies the desktop
 * app, and it does not depend on a core feature the site may not have yet.
 * Abilities are the shared vocabulary on top, so the same site is usable by
 * whatever else the owner points at it.
 *
 * Abilities run as the logged in user and are checked with normal capabilities.
 * They do not consult the Skales token at all.
 *
 * @package Skales_Connector
 */

if (!defined('ABSPATH')) exit;

add_action('wp_abilities_api_categories_init', 'skales_register_ability_categories');
function skales_register_ability_categories() {
    if (!function_exists('wp_register_ability_category')) return;

    wp_register_ability_category('skales-content', [
        'label'       => __('Skales content', 'skales-connector'),
        'description' => __('Create and change posts, pages and their images.', 'skales-connector'),
    ]);

    wp_register_ability_category('skales-site', [
        'label'       => __('Skales site', 'skales-connector'),
        'description' => __('Change site settings, permalinks and design.', 'skales-connector'),
    ]);
}

add_action('wp_abilities_api_init', 'skales_register_abilities');
function skales_register_abilities() {
    if (!function_exists('wp_register_ability')) return;

    wp_register_ability('skales/create-post', [
        'label'       => __('Create a post or page', 'skales-connector'),
        'description' => __('Creates a post or page with a title, content, status, categories and tags. Content may be HTML or Gutenberg block markup.', 'skales-connector'),
        'category'    => 'skales-content',
        'input_schema' => [
            'type' => 'object',
            'properties' => [
                'type'       => ['type' => 'string', 'enum' => ['post', 'page'], 'default' => 'post'],
                'title'      => ['type' => 'string'],
                'content'    => ['type' => 'string'],
                'status'     => ['type' => 'string', 'enum' => ['draft', 'publish', 'pending', 'private', 'future']],
                'excerpt'    => ['type' => 'string'],
                'categories' => ['type' => 'array', 'items' => ['type' => 'string']],
                'tags'       => ['type' => 'array', 'items' => ['type' => 'string']],
                'date'       => ['type' => 'string', 'description' => 'ISO 8601 date, a future date schedules the post'],
                // The fields skales_apply_post_extras() acts on. They were
                // always processed; leaving them out of the schema meant a
                // caller reading the schema could not know they exist.
                'slug'           => ['type' => 'string'],
                'parent'         => ['type' => 'integer'],
                'template'       => ['type' => 'string'],
                'featured_media' => ['type' => 'integer'],
                'sticky'         => ['type' => 'boolean'],
                'comment_status' => ['type' => 'string', 'enum' => ['open', 'closed']],
                'terms'          => ['type' => 'object', 'description' => 'Taxonomy slug to a list of term ids, slugs or names'],
                'meta'           => ['type' => 'object', 'description' => 'Custom fields, protected keys excluded'],
                'blocks'         => ['type' => 'array', 'items' => ['type' => 'object'], 'description' => 'Block descriptors, serialised instead of content when present'],
            ],
            'required' => ['title'],
        ],
        'output_schema' => [
            'type' => 'object',
            'properties' => [
                'id'  => ['type' => 'integer'],
                'url' => ['type' => 'string'],
            ],
        ],
        'execute_callback' => 'skales_ability_create_post',
        'permission_callback' => static function ($input = []) {
            $type = isset($input['type']) && $input['type'] === 'page' ? 'page' : 'post';
            return current_user_can($type === 'page' ? 'publish_pages' : 'publish_posts');
        },
        'meta' => [
            'annotations'  => ['readonly' => false, 'destructive' => false, 'idempotent' => false],
            'show_in_rest' => true,
        ],
    ]);

    wp_register_ability('skales/update-post', [
        'label'       => __('Update a post or page', 'skales-connector'),
        'description' => __('Changes the title, content, status, categories or tags of an existing post or page.', 'skales-connector'),
        'category'    => 'skales-content',
        'input_schema' => [
            'type' => 'object',
            'properties' => [
                'id'         => ['type' => 'integer'],
                'title'      => ['type' => 'string'],
                'content'    => ['type' => 'string'],
                'status'     => ['type' => 'string', 'enum' => ['draft', 'publish', 'pending', 'private', 'future']],
                'excerpt'    => ['type' => 'string'],
                'categories' => ['type' => 'array', 'items' => ['type' => 'string']],
                'tags'       => ['type' => 'array', 'items' => ['type' => 'string']],
                'slug'           => ['type' => 'string'],
                'parent'         => ['type' => 'integer'],
                'template'       => ['type' => 'string'],
                'featured_media' => ['type' => 'integer'],
                'sticky'         => ['type' => 'boolean'],
                'comment_status' => ['type' => 'string', 'enum' => ['open', 'closed']],
                'terms'          => ['type' => 'object', 'description' => 'Taxonomy slug to a list of term ids, slugs or names'],
                'meta'           => ['type' => 'object', 'description' => 'Custom fields, protected keys excluded'],
                'blocks'         => ['type' => 'array', 'items' => ['type' => 'object'], 'description' => 'Block descriptors, serialised instead of content when present'],
            ],
            'required' => ['id'],
        ],
        'output_schema' => [
            'type' => 'object',
            'properties' => ['id' => ['type' => 'integer'], 'url' => ['type' => 'string']],
        ],
        'execute_callback' => 'skales_ability_update_post',
        'permission_callback' => static function ($input = []) {
            $id = isset($input['id']) ? (int) $input['id'] : 0;
            return $id ? current_user_can('edit_post', $id) : false;
        },
        'meta' => [
            'annotations'  => ['readonly' => false, 'destructive' => true, 'idempotent' => true],
            'show_in_rest' => true,
        ],
    ]);

    wp_register_ability('skales/list-content', [
        'label'       => __('List posts and pages', 'skales-connector'),
        'description' => __('Returns posts or pages with their ids, titles, status and URLs so a follow up call can address one of them.', 'skales-connector'),
        'category'    => 'skales-content',
        'input_schema' => [
            'type' => 'object',
            'properties' => [
                'type'     => ['type' => 'string', 'default' => 'post'],
                'search'   => ['type' => 'string'],
                'per_page' => ['type' => 'integer', 'default' => 20, 'minimum' => 1, 'maximum' => 100],
            ],
        ],
        'output_schema' => ['type' => 'array', 'items' => ['type' => 'object']],
        'execute_callback' => 'skales_ability_list_content',
        'permission_callback' => static function () { return current_user_can('edit_posts'); },
        'meta' => [
            'annotations'  => ['readonly' => true, 'destructive' => false, 'idempotent' => true],
            'show_in_rest' => true,
        ],
    ]);

    wp_register_ability('skales/set-featured-image', [
        'label'       => __('Set a featured image', 'skales-connector'),
        'description' => __('Puts an image on a post. Accepts an existing attachment id or a URL, which is downloaded into the media library first.', 'skales-connector'),
        'category'    => 'skales-content',
        'input_schema' => [
            'type' => 'object',
            'properties' => [
                'post_id'       => ['type' => 'integer'],
                'attachment_id' => ['type' => 'integer'],
                'url'           => ['type' => 'string'],
                'alt'           => ['type' => 'string'],
            ],
            'required' => ['post_id'],
        ],
        'output_schema' => [
            'type' => 'object',
            'properties' => ['attachment_id' => ['type' => 'integer'], 'url' => ['type' => 'string']],
        ],
        'execute_callback' => 'skales_ability_set_featured_image',
        'permission_callback' => static function ($input = []) {
            $id = isset($input['post_id']) ? (int) $input['post_id'] : 0;
            return $id ? (current_user_can('edit_post', $id) && current_user_can('upload_files')) : false;
        },
        'meta' => [
            'annotations'  => ['readonly' => false, 'destructive' => true, 'idempotent' => true],
            'show_in_rest' => true,
        ],
    ]);

    wp_register_ability('skales/update-permalinks', [
        'label'       => __('Change the permalink structure', 'skales-connector'),
        'description' => __('Sets the site wide permalink structure and flushes the rewrite rules. WordPress has no core endpoint for this.', 'skales-connector'),
        'category'    => 'skales-site',
        'input_schema' => [
            'type' => 'object',
            'properties' => [
                'preset'    => ['type' => 'string', 'enum' => ['plain', 'day-name', 'month-name', 'numeric', 'post-name']],
                'structure' => ['type' => 'string'],
            ],
        ],
        'output_schema' => ['type' => 'object', 'properties' => ['structure' => ['type' => 'string']]],
        'execute_callback' => 'skales_ability_update_permalinks',
        'permission_callback' => static function () { return current_user_can('manage_options'); },
        'meta' => [
            'annotations'  => ['readonly' => false, 'destructive' => true, 'idempotent' => true],
            'show_in_rest' => true,
        ],
    ]);

    wp_register_ability('skales/update-design', [
        'label'       => __('Change theme mods or global styles', 'skales-connector'),
        'description' => __('Writes Customizer theme mods on a classic theme, or global styles on a block theme. WordPress has no core endpoint for the Customizer.', 'skales-connector'),
        'category'    => 'skales-site',
        'input_schema' => [
            'type' => 'object',
            'properties' => [
                'mods'   => ['type' => 'object'],
                'styles' => ['type' => 'object'],
                'css'    => ['type' => 'string'],
            ],
        ],
        'output_schema' => ['type' => 'object'],
        'execute_callback' => 'skales_ability_update_design',
        'permission_callback' => static function () { return current_user_can('edit_theme_options'); },
        'meta' => [
            'annotations'  => ['readonly' => false, 'destructive' => true, 'idempotent' => true],
            'show_in_rest' => true,
        ],
    ]);
}

// =============================================================================
// ABILITY CALLBACKS
// =============================================================================

function skales_ability_create_post($input = []) {
    $input = is_array($input) ? $input : [];
    $type  = (isset($input['type']) && $input['type'] === 'page') ? 'page' : 'post';

    $args   = skales_build_post_args($input, $type, false);
    $lifted = skales_raw_html_begin();
    $id     = wp_insert_post($args, true);
    skales_raw_html_end($lifted);

    if (is_wp_error($id)) return $id;

    update_post_meta($id, '_skales_page', '1');
    skales_apply_post_extras($id, $input);

    return ['id' => (int) $id, 'url' => get_permalink($id)];
}

function skales_ability_update_post($input = []) {
    $input = is_array($input) ? $input : [];
    $id    = isset($input['id']) ? (int) $input['id'] : 0;
    $post  = $id ? get_post($id) : null;

    if (!$post) {
        return new WP_Error('not_found', __('That post does not exist.', 'skales-connector'));
    }

    $args       = skales_build_post_args($input, $post->post_type, true);
    $args['ID'] = $id;

    $lifted = skales_raw_html_begin();
    $result = wp_update_post($args, true);
    skales_raw_html_end($lifted);

    if (is_wp_error($result)) return $result;

    skales_apply_post_extras($id, $input);

    return ['id' => $id, 'url' => get_permalink($id)];
}

function skales_ability_list_content($input = []) {
    $input = is_array($input) ? $input : [];
    $type  = sanitize_key($input['type'] ?? 'post');
    if (!post_type_exists($type)) $type = 'post';

    $args = [
        'post_type'   => $type,
        'numberposts' => max(1, min(100, (int) ($input['per_page'] ?? 20))),
        'post_status' => ['publish', 'draft', 'pending', 'private', 'future'],
    ];
    if (!empty($input['search'])) {
        $args['s'] = sanitize_text_field($input['search']);
    }

    return array_map(function ($p) {
        return skales_format_post($p, false);
    }, get_posts($args));
}

function skales_ability_set_featured_image($input = []) {
    $input   = is_array($input) ? $input : [];
    $post_id = (int) ($input['post_id'] ?? 0);
    $alt     = (string) ($input['alt'] ?? '');

    $attachment_id = (int) ($input['attachment_id'] ?? 0);
    if (!$attachment_id && !empty($input['url'])) {
        $stored = skales_store_media_url($input['url'], '', $alt);
        if (is_wp_error($stored)) return $stored;
        $attachment_id = $stored['attachment_id'];
    }

    if (!$attachment_id || get_post_type($attachment_id) !== 'attachment') {
        return new WP_Error('bad_attachment', __('Provide attachment_id or url.', 'skales-connector'));
    }

    set_post_thumbnail($post_id, $attachment_id);

    return ['attachment_id' => $attachment_id, 'url' => wp_get_attachment_url($attachment_id)];
}

function skales_ability_update_permalinks($input = []) {
    $input = is_array($input) ? $input : [];

    require_once ABSPATH . 'wp-admin/includes/misc.php';
    global $wp_rewrite;

    $structure = null;
    if (!empty($input['preset'])) {
        $presets = skales_permalink_presets();
        $preset  = sanitize_key($input['preset']);
        if (!array_key_exists($preset, $presets)) {
            return new WP_Error('bad_preset', __('Unknown permalink preset.', 'skales-connector'));
        }
        $structure = $presets[$preset];
    } elseif (isset($input['structure'])) {
        $structure = (string) $input['structure'];
    }

    if ($structure === null) {
        return new WP_Error('no_structure', __('Provide preset or structure.', 'skales-connector'));
    }

    $valid = skales_validate_permalink_structure($structure);
    if (is_wp_error($valid)) {
        return $valid;
    }
    if ($structure !== '' && strpos($structure, '/') !== 0) {
        $structure = '/' . $structure;
    }

    $wp_rewrite->set_permalink_structure($structure);
    $wp_rewrite->flush_rules(true);

    return ['structure' => get_option('permalink_structure')];
}

function skales_ability_update_design($input = []) {
    $input   = is_array($input) ? $input : [];
    $applied = [];

    if (!empty($input['mods']) && is_array($input['mods'])) {
        foreach ($input['mods'] as $key => $value) {
            $key = sanitize_key((string) $key);
            if ($key === '' || $key === 'nav_menu_locations') continue;
            if ($key === 'custom_logo') {
                $id = skales_resolve_attachment($value);
                if ($id) { set_theme_mod('custom_logo', $id); $applied['mods'][$key] = $id; }
                continue;
            }
            if (is_scalar($value)) {
                set_theme_mod($key, sanitize_text_field((string) $value));
                $applied['mods'][$key] = $value;
            }
        }
    }

    if (!empty($input['css'])) {
        // Same gate as PUT /theme/css: site wide CSS is edit_css in wp-admin.
        if (!current_user_can('edit_css')) {
            return new WP_Error('forbidden', __('This account may not write site wide CSS.', 'skales-connector'));
        }
        $result = wp_update_custom_css_post((string) $input['css']);
        if (is_wp_error($result)) return $result;
        $applied['css'] = strlen((string) $input['css']);
    }

    if (!empty($input['styles']) && is_array($input['styles'])) {
        $id = skales_global_styles_post_id();
        if (!$id) {
            return new WP_Error('no_global_styles', __('This theme does not use global styles.', 'skales-connector'));
        }
        $post    = get_post($id);
        $current = json_decode($post->post_content, true);
        if (!is_array($current)) $current = ['version' => 2, 'isGlobalStylesUserThemeJSON' => true];
        $current['styles'] = isset($current['styles']) && is_array($current['styles'])
            ? skales_deep_merge($current['styles'], $input['styles'])
            : $input['styles'];

        $current = skales_validate_global_styles($current);
        $encoded = wp_json_encode($current);
        if ($encoded === false) {
            return new WP_Error('encode_failed', __('Could not encode the global styles payload.', 'skales-connector'));
        }

        $result = wp_update_post(['ID' => $id, 'post_content' => wp_slash($encoded)], true);
        if (is_wp_error($result)) return $result;

        if (function_exists('wp_clean_theme_json_cache')) wp_clean_theme_json_cache();
        $applied['styles'] = true;
    }

    return $applied;
}
