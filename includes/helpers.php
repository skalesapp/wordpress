<?php
/**
 * Shared helpers: payload normalisation, term resolution, response shaping.
 *
 * @package Skales_Connector
 */

if (!defined('ABSPATH')) exit;

/**
 * Make sure wp-admin plugin helpers are available. REST requests do not load
 * wp-admin includes by default, and several endpoints call is_plugin_active()
 * or get_plugins().
 *
 * @return void
 */
function skales_require_plugin_api() {
    if (!function_exists('is_plugin_active') || !function_exists('get_plugins')) {
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
    }
}

/**
 * Post statuses the connector accepts. Anything else falls back to draft, so a
 * typo can never publish something by accident.
 *
 * @param mixed  $status
 * @param string $fallback
 * @return string
 */
function skales_sanitize_status($status, $fallback = 'draft') {
    $allowed = ['draft', 'publish', 'pending', 'private', 'future'];
    $status  = sanitize_key((string) $status);
    return in_array($status, $allowed, true) ? $status : $fallback;
}

/**
 * Resolve a list of terms given as IDs, slugs or names into term IDs, creating
 * missing ones when the account may do so. "Write a post about X and file it
 * under News" should not fail because the category does not exist yet.
 *
 * @param array  $terms
 * @param string $taxonomy
 * @param bool   $create
 * @return int[]
 */
function skales_resolve_terms($terms, $taxonomy, $create = true) {
    $ids = [];
    foreach ((array) $terms as $term) {
        if (is_numeric($term)) {
            $existing = get_term((int) $term, $taxonomy);
            if ($existing && !is_wp_error($existing)) {
                $ids[] = (int) $existing->term_id;
            }
            continue;
        }

        $name     = sanitize_text_field((string) $term);
        if ($name === '') continue;

        $existing = get_term_by('name', $name, $taxonomy);
        if (!$existing) {
            $existing = get_term_by('slug', sanitize_title($name), $taxonomy);
        }

        if ($existing) {
            $ids[] = (int) $existing->term_id;
            continue;
        }

        if ($create && current_user_can('manage_categories')) {
            $new = wp_insert_term($name, $taxonomy);
            if (!is_wp_error($new)) {
                $ids[] = (int) $new['term_id'];
            }
        }
    }
    return array_values(array_unique($ids));
}

/**
 * Meta keys the connector refuses to write. Protected keys (leading underscore)
 * belong to WordPress and to other plugins; letting a remote request set them
 * would be a privilege escalation path through serialised option data.
 * The SEO endpoints write their own known keys through their own route.
 *
 * @param string $key
 * @return bool
 */
function skales_meta_key_allowed($key) {
    if ($key === '' || strpos($key, '_') === 0) return false;
    if (!preg_match('/^[A-Za-z0-9_\-]+$/', $key)) return false;
    return true;
}

/**
 * Turn a request payload into wp_insert_post/wp_update_post arguments.
 * Only keys present in the payload are touched, so a partial update stays
 * partial.
 *
 * @param array  $params
 * @param string $post_type
 * @param bool   $is_update
 * @return array
 */
function skales_build_post_args($params, $post_type, $is_update = false) {
    $args = [];

    if (isset($params['title'])) {
        $args['post_title'] = sanitize_text_field($params['title']);
    } elseif (!$is_update) {
        $args['post_title'] = 'Untitled';
    }

    if (isset($params['content'])) {
        $args['post_content'] = $params['content'];
    } elseif (!$is_update) {
        $args['post_content'] = '';
    }

    // Gutenberg block descriptors win over raw content when both are supplied.
    if (!empty($params['blocks']) && is_array($params['blocks'])) {
        $args['post_content'] = skales_serialize_block_list($params['blocks']);
    }

    if (isset($params['status'])) {
        $args['post_status'] = skales_sanitize_status($params['status']);
    } elseif (!$is_update) {
        $args['post_status'] = 'draft';
    }

    if (!$is_update) {
        $args['post_type'] = $post_type;
    }

    if (isset($params['excerpt']))     $args['post_excerpt']   = wp_kses_post($params['excerpt']);
    if (isset($params['slug']))        $args['post_name']      = sanitize_title($params['slug']);
    if (isset($params['parent']))      $args['post_parent']    = (int) $params['parent'];
    if (isset($params['menu_order']))  $args['menu_order']     = (int) $params['menu_order'];
    if (isset($params['password']))    $args['post_password']  = (string) $params['password'];

    if (isset($params['comment_status'])) {
        $args['comment_status'] = $params['comment_status'] === 'open' ? 'open' : 'closed';
    }
    if (isset($params['ping_status'])) {
        $args['ping_status'] = $params['ping_status'] === 'open' ? 'open' : 'closed';
    }

    // Scheduling. A future date implies post_status future unless the caller
    // asked for something else explicitly.
    if (!empty($params['date'])) {
        $ts = strtotime((string) $params['date']);
        if ($ts) {
            $args['post_date']     = gmdate('Y-m-d H:i:s', $ts + (int) (get_option('gmt_offset') * HOUR_IN_SECONDS));
            $args['post_date_gmt'] = gmdate('Y-m-d H:i:s', $ts);
            if ($ts > time() && empty($params['status'])) {
                $args['post_status'] = 'future';
            }
        }
    }

    if (isset($params['author'])) {
        $author = (int) $params['author'];
        if ($author && get_user_by('id', $author) && current_user_can('edit_others_posts')) {
            $args['post_author'] = $author;
        }
    }

    return $args;
}

/**
 * Apply the payload parts that are not columns on the posts table: terms,
 * featured image, template, sticky flag, meta.
 *
 * @param int   $post_id
 * @param array $params
 * @return void
 */
function skales_apply_post_extras($post_id, $params) {
    if (!empty($params['template'])) {
        update_post_meta($post_id, '_wp_page_template', sanitize_text_field($params['template']));
    }

    if (isset($params['categories'])) {
        $ids = skales_resolve_terms($params['categories'], 'category');
        wp_set_post_terms($post_id, $ids, 'category', false);
    }

    if (isset($params['tags'])) {
        $ids = skales_resolve_terms($params['tags'], 'post_tag');
        wp_set_post_terms($post_id, $ids, 'post_tag', false);
    }

    if (isset($params['terms']) && is_array($params['terms'])) {
        foreach ($params['terms'] as $taxonomy => $terms) {
            $taxonomy = sanitize_key($taxonomy);
            if (!taxonomy_exists($taxonomy)) continue;
            wp_set_post_terms($post_id, skales_resolve_terms($terms, $taxonomy), $taxonomy, false);
        }
    }

    if (isset($params['featured_media'])) {
        $attachment = (int) $params['featured_media'];
        if ($attachment > 0 && get_post_type($attachment) === 'attachment') {
            set_post_thumbnail($post_id, $attachment);
        } elseif ($attachment === 0) {
            delete_post_thumbnail($post_id);
        }
    }

    if (isset($params['sticky'])) {
        if ($params['sticky']) { stick_post($post_id); } else { unstick_post($post_id); }
    }

    if (!empty($params['meta']) && is_array($params['meta'])) {
        foreach ($params['meta'] as $key => $value) {
            $key = (string) $key;
            if (!skales_meta_key_allowed($key)) continue;
            update_post_meta($post_id, $key, is_scalar($value) ? sanitize_text_field((string) $value) : $value);
        }
    }
}

/**
 * The shape every endpoint returns for a post, page or any other post type.
 *
 * @param int|WP_Post $post
 * @param bool        $with_content
 * @return array
 */
function skales_format_post($post, $with_content = false) {
    $post = get_post($post);
    if (!$post) return [];

    $thumb_id = (int) get_post_thumbnail_id($post->ID);

    $out = [
        'id'          => (int) $post->ID,
        'type'        => $post->post_type,
        'title'       => $post->post_title,
        'slug'        => $post->post_name,
        'status'      => $post->post_status,
        'url'         => get_permalink($post->ID),
        'edit_url'    => admin_url("post.php?post={$post->ID}&action=edit"),
        'author'      => (int) $post->post_author,
        'author_name' => get_the_author_meta('display_name', $post->post_author),
        'parent'      => (int) $post->post_parent,
        'menu_order'  => (int) $post->menu_order,
        'excerpt'     => $post->post_excerpt,
        'date'        => $post->post_date,
        'date_gmt'    => $post->post_date_gmt,
        'modified'    => $post->post_modified,
        'template'    => get_page_template_slug($post->ID),
        'featured_media' => $thumb_id,
        'featured_media_url' => $thumb_id ? wp_get_attachment_url($thumb_id) : '',
        'comment_status' => $post->comment_status,
    ];

    if ($post->post_type === 'post') {
        $out['categories'] = wp_get_post_terms($post->ID, 'category', ['fields' => 'names']);
        $out['tags']       = wp_get_post_terms($post->ID, 'post_tag', ['fields' => 'names']);
        $out['sticky']     = is_sticky($post->ID);
    }

    if ($with_content) {
        $out['content'] = $post->post_content;
    }

    return $out;
}

/**
 * Serialize a list of Gutenberg block descriptors into block markup.
 *
 * Descriptor shape mirrors what parse_blocks() returns, with friendlier names:
 *   { "name": "core/paragraph", "attrs": {...}, "html": "<p>Hi</p>",
 *     "innerBlocks": [ ... ] }
 *
 * Building the array and handing it to core's serialize_blocks() is what keeps
 * the output valid: the block delimiters, the attribute JSON and the inner
 * content offsets all come from WordPress itself, not from string glue.
 *
 * @param array $blocks
 * @return string
 */
function skales_serialize_block_list($blocks) {
    $prepared = skales_prepare_blocks($blocks);
    if (empty($prepared)) return '';
    return serialize_blocks($prepared);
}

/**
 * Normalise Skales block descriptors into the structure serialize_blocks()
 * expects.
 *
 * @param array $blocks
 * @return array
 */
function skales_prepare_blocks($blocks) {
    $out = [];

    foreach ((array) $blocks as $block) {
        if (!is_array($block)) continue;

        $name = isset($block['name']) ? (string) $block['name'] : (isset($block['blockName']) ? (string) $block['blockName'] : '');
        $name = trim($name);
        if ($name === '') continue;
        if (strpos($name, '/') === false) {
            $name = 'core/' . $name;
        }
        if (!preg_match('#^[a-z0-9-]+/[a-z0-9-]+$#', $name)) continue;

        $attrs = [];
        if (!empty($block['attrs']) && is_array($block['attrs'])) {
            $attrs = $block['attrs'];
        } elseif (!empty($block['attributes']) && is_array($block['attributes'])) {
            $attrs = $block['attributes'];
        }

        $html = '';
        foreach (['html', 'innerHTML', 'content'] as $key) {
            if (isset($block[$key]) && is_string($block[$key])) {
                $html = $block[$key];
                break;
            }
        }

        $inner = [];
        if (!empty($block['innerBlocks']) && is_array($block['innerBlocks'])) {
            $inner = skales_prepare_blocks($block['innerBlocks']);
        } elseif (!empty($block['blocks']) && is_array($block['blocks'])) {
            $inner = skales_prepare_blocks($block['blocks']);
        }

        // innerContent interleaves literal chunks (strings) with null markers
        // for each inner block. With no inner blocks it is simply the markup.
        if (empty($inner)) {
            $inner_content = $html === '' ? [] : [$html];
        } else {
            $inner_content = [];
            if ($html !== '') {
                // Split the wrapper on the first empty marker if the caller
                // supplied one, otherwise wrap children after the markup.
                $inner_content[] = $html;
            }
            foreach ($inner as $unused) {
                $inner_content[] = null;
            }
        }

        $out[] = [
            'blockName'    => $name,
            'attrs'        => $attrs,
            'innerBlocks'  => $inner,
            'innerHTML'    => $html,
            'innerContent' => $inner_content,
        ];
    }

    return $out;
}
