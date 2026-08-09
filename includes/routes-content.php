<?php
/**
 * Content routes: pages, posts, arbitrary post types, terms, comments, blocks.
 *
 * @package Skales_Connector
 */

if (!defined('ABSPATH')) exit;

function skales_register_content_routes($ns) {

    // ── Pages ───────────────────────────────────────────────────────────
    register_rest_route($ns, '/pages', [
        [
            'methods'             => 'GET',
            'callback'            => 'skales_route_list_pages',
            'permission_callback' => skales_cap('edit_pages'),
        ],
        [
            'methods'             => 'POST',
            'callback'            => 'skales_route_create_page',
            'permission_callback' => skales_cap('publish_pages'),
        ],
    ]);

    register_rest_route($ns, '/pages/(?P<id>\d+)', [
        [
            'methods'             => 'GET',
            'callback'            => 'skales_route_get_page',
            'permission_callback' => skales_cap('edit_pages'),
        ],
        [
            'methods'             => 'PUT, PATCH',
            'callback'            => 'skales_route_update_page',
            'permission_callback' => skales_cap('edit_pages'),
        ],
        [
            'methods'             => 'DELETE',
            'callback'            => 'skales_route_delete_page',
            'permission_callback' => skales_cap('delete_pages'),
        ],
    ]);

    // ── Posts ───────────────────────────────────────────────────────────
    register_rest_route($ns, '/posts', [
        [
            'methods'             => 'GET',
            'callback'            => 'skales_route_list_posts',
            'permission_callback' => skales_cap('edit_posts'),
        ],
        [
            'methods'             => 'POST',
            'callback'            => 'skales_route_create_post',
            'permission_callback' => skales_cap('publish_posts'),
        ],
    ]);

    register_rest_route($ns, '/posts/(?P<id>\d+)', [
        [
            'methods'             => 'GET',
            'callback'            => 'skales_route_get_post',
            'permission_callback' => skales_cap('edit_posts'),
        ],
        [
            'methods'             => 'PUT, PATCH',
            'callback'            => 'skales_route_update_post',
            'permission_callback' => skales_cap('edit_posts'),
        ],
        [
            'methods'             => 'DELETE',
            'callback'            => 'skales_route_delete_post',
            'permission_callback' => skales_cap('delete_posts'),
        ],
    ]);

    // ── Post types (so custom types are reachable too) ───────────────────
    register_rest_route($ns, '/types', [
        'methods'             => 'GET',
        'callback'            => 'skales_route_list_types',
        'permission_callback' => skales_cap('edit_posts'),
    ]);

    // ── Terms ───────────────────────────────────────────────────────────
    register_rest_route($ns, '/terms', [
        [
            'methods'             => 'GET',
            'callback'            => 'skales_route_list_terms',
            'permission_callback' => skales_cap('edit_posts'),
        ],
        [
            'methods'             => 'POST',
            'callback'            => 'skales_route_create_term',
            'permission_callback' => skales_cap('manage_categories'),
        ],
    ]);

    register_rest_route($ns, '/terms/(?P<id>\d+)', [
        [
            'methods'             => 'PUT, PATCH',
            'callback'            => 'skales_route_update_term',
            'permission_callback' => skales_cap('manage_categories'),
        ],
        [
            'methods'             => 'DELETE',
            'callback'            => 'skales_route_delete_term',
            'permission_callback' => skales_cap('manage_categories'),
        ],
    ]);

    // ── Comments ────────────────────────────────────────────────────────
    register_rest_route($ns, '/comments', [
        [
            'methods'             => 'GET',
            'callback'            => 'skales_route_list_comments',
            'permission_callback' => skales_cap('moderate_comments'),
        ],
        [
            'methods'             => 'POST',
            'callback'            => 'skales_route_create_comment',
            'permission_callback' => skales_cap('moderate_comments'),
        ],
    ]);

    register_rest_route($ns, '/comments/(?P<id>\d+)', [
        [
            'methods'             => 'PUT, PATCH',
            'callback'            => 'skales_route_update_comment',
            'permission_callback' => skales_cap('moderate_comments'),
        ],
        [
            'methods'             => 'DELETE',
            'callback'            => 'skales_route_delete_comment',
            'permission_callback' => skales_cap('moderate_comments'),
        ],
    ]);

    // ── Gutenberg ───────────────────────────────────────────────────────
    register_rest_route($ns, '/blocks/serialize', [
        'methods'             => 'POST',
        'callback'            => 'skales_route_serialize_blocks',
        'permission_callback' => skales_cap('edit_posts'),
    ]);

    register_rest_route($ns, '/blocks/validate', [
        'methods'             => 'POST',
        'callback'            => 'skales_route_validate_blocks',
        'permission_callback' => skales_cap('edit_posts'),
    ]);

    register_rest_route($ns, '/blocks/reusable', [
        [
            'methods'             => 'GET',
            'callback'            => 'skales_route_list_reusable_blocks',
            'permission_callback' => skales_cap('edit_posts'),
        ],
        [
            'methods'             => 'POST',
            'callback'            => 'skales_route_create_reusable_block',
            'permission_callback' => skales_cap('publish_posts'),
        ],
    ]);
}

// =============================================================================
// PAGES
// =============================================================================

function skales_route_create_page($request) {
    return skales_create_content($request, 'page');
}

function skales_route_update_page($request) {
    return skales_update_content($request, 'page');
}

function skales_route_delete_page($request) {
    return skales_delete_content($request, 'page');
}

function skales_route_get_page($request) {
    $post = get_post((int) $request['id']);
    if (!$post || $post->post_type !== 'page') {
        return new WP_Error('not_found', 'Page not found', ['status' => 404]);
    }
    return rest_ensure_response(['ok' => true, 'page' => skales_format_post($post, true)]);
}

function skales_route_list_pages($request) {
    $query = skales_content_query($request, 'page');
    $pages = get_posts($query);

    // The 1.x shape (id, title, status, url, modified) is a strict subset of
    // skales_format_post(), so older desktop builds keep reading the same keys.
    $result = array_map(function ($p) {
        return skales_format_post($p, false);
    }, $pages);

    return rest_ensure_response([
        'ok'    => true,
        'pages' => $result,
        'total' => skales_content_total($query),
    ]);
}

// =============================================================================
// POSTS
// =============================================================================

function skales_route_create_post($request) {
    return skales_create_content($request, 'post');
}

function skales_route_update_post($request) {
    return skales_update_content($request, 'post');
}

function skales_route_delete_post($request) {
    return skales_delete_content($request, 'post');
}

function skales_route_get_post($request) {
    $post = get_post((int) $request['id']);
    if (!$post) {
        return new WP_Error('not_found', 'Post not found', ['status' => 404]);
    }
    return rest_ensure_response(['ok' => true, 'post' => skales_format_post($post, true)]);
}

function skales_route_list_posts($request) {
    $type  = sanitize_key($request->get_param('type') ?: 'post');
    if (!post_type_exists($type)) {
        return new WP_Error('bad_type', 'Unknown post type: ' . $type, ['status' => 400]);
    }
    $query = skales_content_query($request, $type);
    $posts = get_posts($query);

    return rest_ensure_response([
        'ok'    => true,
        'posts' => array_map(function ($p) { return skales_format_post($p, false); }, $posts),
        'total' => skales_content_total($query),
    ]);
}

function skales_route_list_types($request) {
    $types = get_post_types(['show_ui' => true], 'objects');
    $out   = [];
    foreach ($types as $type) {
        $out[] = [
            'slug'       => $type->name,
            'label'      => $type->labels->name,
            'hierarchical' => (bool) $type->hierarchical,
            'taxonomies' => get_object_taxonomies($type->name),
            'supports'   => array_keys(array_filter(get_all_post_type_supports($type->name))),
        ];
    }
    return rest_ensure_response(['ok' => true, 'types' => $out]);
}

// =============================================================================
// SHARED CONTENT HANDLERS
// =============================================================================

function skales_content_query($request, $post_type) {
    $per_page = (int) ($request->get_param('per_page') ?: 50);
    $per_page = max(1, min(100, $per_page));
    $page     = max(1, (int) ($request->get_param('page') ?: 1));

    $statuses = $request->get_param('status');
    if ($statuses) {
        $statuses = array_map('skales_sanitize_status', (array) $statuses);
    } else {
        $statuses = ['publish', 'draft', 'pending', 'private', 'future'];
    }

    $query = [
        'post_type'   => $post_type,
        'numberposts' => $per_page,
        'offset'      => ($page - 1) * $per_page,
        'post_status' => $statuses,
        'orderby'     => sanitize_key($request->get_param('orderby') ?: 'date'),
        'order'       => strtoupper($request->get_param('order') ?: 'DESC') === 'ASC' ? 'ASC' : 'DESC',
    ];

    $search = $request->get_param('search');
    if ($search) $query['s'] = sanitize_text_field($search);

    $author = (int) $request->get_param('author');
    if ($author) $query['author'] = $author;

    $category = $request->get_param('category');
    if ($category) {
        $query['tax_query'] = [[
            'taxonomy' => 'category',
            'field'    => is_numeric($category) ? 'term_id' : 'slug',
            'terms'    => is_numeric($category) ? (int) $category : sanitize_title($category),
        ]];
    }

    $tag = $request->get_param('tag');
    if ($tag) {
        $query['tax_query'][] = [
            'taxonomy' => 'post_tag',
            'field'    => is_numeric($tag) ? 'term_id' : 'slug',
            'terms'    => is_numeric($tag) ? (int) $tag : sanitize_title($tag),
        ];
    }

    return $query;
}

/**
 * Total matching rows, so a client can page without guessing.
 *
 * @param array $query
 * @return int
 */
function skales_content_total($query) {
    $counting = $query;
    unset($counting['numberposts'], $counting['offset']);
    $counting['fields']         = 'ids';
    $counting['posts_per_page'] = -1;
    $counting['numberposts']    = -1;
    return count(get_posts($counting));
}

function skales_create_content($request, $post_type) {
    $params = (array) $request->get_json_params();
    $args   = skales_build_post_args($params, $post_type, false);

    $lifted = skales_raw_html_begin();
    $id     = wp_insert_post($args, true);
    skales_raw_html_end($lifted);

    if (is_wp_error($id)) {
        return new WP_Error('create_failed', $id->get_error_message(), ['status' => 500]);
    }

    // Marks the page for the full width CSS the front end injects.
    update_post_meta($id, '_skales_page', '1');
    skales_apply_post_extras($id, $params);

    $response = [
        'ok'       => true,
        'url'      => get_permalink($id),
        'edit_url' => admin_url("post.php?post={$id}&action=edit"),
        'item'     => skales_format_post($id, false),
    ];
    // 1.x response keys, kept so existing Skales builds do not break.
    $response[$post_type === 'page' ? 'page_id' : 'post_id'] = $id;

    return rest_ensure_response($response);
}

function skales_update_content($request, $post_type) {
    $id   = (int) $request['id'];
    $post = get_post($id);
    if (!$post) {
        return new WP_Error('not_found', 'Content not found', ['status' => 404]);
    }
    if (!current_user_can('edit_post', $id)) {
        return new WP_Error('forbidden', 'Not allowed to edit this item', ['status' => 403]);
    }

    $params = (array) $request->get_json_params();
    $args   = skales_build_post_args($params, $post_type, true);
    $args['ID'] = $id;

    $lifted = skales_raw_html_begin();
    $result = wp_update_post($args, true);
    skales_raw_html_end($lifted);

    if (is_wp_error($result)) {
        return new WP_Error('update_failed', $result->get_error_message(), ['status' => 500]);
    }

    skales_apply_post_extras($id, $params);

    $response = ['ok' => true, 'item' => skales_format_post($id, false)];
    $response[$post_type === 'page' ? 'page_id' : 'post_id'] = $id;

    return rest_ensure_response($response);
}

function skales_delete_content($request, $post_type) {
    $id   = (int) $request['id'];
    $post = get_post($id);
    if (!$post) {
        return new WP_Error('not_found', 'Content not found', ['status' => 404]);
    }
    if (!current_user_can('delete_post', $id)) {
        return new WP_Error('forbidden', 'Not allowed to delete this item', ['status' => 403]);
    }

    // Default is the trash, the same as pressing Delete in wp-admin. Permanent
    // deletion has to be asked for explicitly.
    $force = (bool) $request->get_param('force');

    $result = $force ? wp_delete_post($id, true) : wp_trash_post($id);
    if (!$result) {
        return new WP_Error('delete_failed', 'WordPress refused to delete this item', ['status' => 500]);
    }

    return rest_ensure_response([
        'ok'      => true,
        'id'      => $id,
        'deleted' => $force ? 'permanent' : 'trashed',
    ]);
}

// =============================================================================
// TERMS
// =============================================================================

function skales_route_list_terms($request) {
    $taxonomy = sanitize_key($request->get_param('taxonomy') ?: 'category');
    if (!taxonomy_exists($taxonomy)) {
        return new WP_Error('bad_taxonomy', 'Unknown taxonomy: ' . $taxonomy, ['status' => 400]);
    }

    $terms = get_terms([
        'taxonomy'   => $taxonomy,
        'hide_empty' => false,
        'number'     => max(1, min(200, (int) ($request->get_param('per_page') ?: 100))),
        'search'     => sanitize_text_field($request->get_param('search') ?: ''),
    ]);

    if (is_wp_error($terms)) {
        return new WP_Error('term_query_failed', $terms->get_error_message(), ['status' => 500]);
    }

    $out = array_map(function ($t) {
        return [
            'id'          => (int) $t->term_id,
            'name'        => $t->name,
            'slug'        => $t->slug,
            'parent'      => (int) $t->parent,
            'count'       => (int) $t->count,
            'description' => $t->description,
            'taxonomy'    => $t->taxonomy,
            'url'         => get_term_link($t),
        ];
    }, $terms);

    return rest_ensure_response(['ok' => true, 'terms' => $out, 'taxonomy' => $taxonomy]);
}

function skales_route_create_term($request) {
    $params   = (array) $request->get_json_params();
    $taxonomy = sanitize_key($params['taxonomy'] ?? 'category');
    if (!taxonomy_exists($taxonomy)) {
        return new WP_Error('bad_taxonomy', 'Unknown taxonomy: ' . $taxonomy, ['status' => 400]);
    }

    $name = sanitize_text_field($params['name'] ?? '');
    if ($name === '') {
        return new WP_Error('missing_name', 'A term name is required', ['status' => 400]);
    }

    $result = wp_insert_term($name, $taxonomy, [
        'slug'        => isset($params['slug']) ? sanitize_title($params['slug']) : '',
        'parent'      => isset($params['parent']) ? (int) $params['parent'] : 0,
        'description' => isset($params['description']) ? wp_kses_post($params['description']) : '',
    ]);

    if (is_wp_error($result)) {
        return new WP_Error('term_create_failed', $result->get_error_message(), ['status' => 400]);
    }

    $term = get_term((int) $result['term_id'], $taxonomy);
    return rest_ensure_response([
        'ok'      => true,
        'term_id' => (int) $result['term_id'],
        'term'    => ['id' => (int) $term->term_id, 'name' => $term->name, 'slug' => $term->slug],
    ]);
}

function skales_route_update_term($request) {
    $params   = (array) $request->get_json_params();
    $id       = (int) $request['id'];
    $taxonomy = sanitize_key($params['taxonomy'] ?? '');

    if (!$taxonomy) {
        $term     = get_term($id);
        $taxonomy = ($term && !is_wp_error($term)) ? $term->taxonomy : '';
    }
    if (!$taxonomy || !taxonomy_exists($taxonomy)) {
        return new WP_Error('bad_taxonomy', 'Unknown or missing taxonomy', ['status' => 400]);
    }

    $args = [];
    if (isset($params['name']))        $args['name']        = sanitize_text_field($params['name']);
    if (isset($params['slug']))        $args['slug']        = sanitize_title($params['slug']);
    if (isset($params['parent']))      $args['parent']      = (int) $params['parent'];
    if (isset($params['description'])) $args['description'] = wp_kses_post($params['description']);

    $result = wp_update_term($id, $taxonomy, $args);
    if (is_wp_error($result)) {
        return new WP_Error('term_update_failed', $result->get_error_message(), ['status' => 400]);
    }

    return rest_ensure_response(['ok' => true, 'term_id' => $id]);
}

function skales_route_delete_term($request) {
    $id       = (int) $request['id'];
    $taxonomy = sanitize_key($request->get_param('taxonomy') ?: '');
    if (!$taxonomy) {
        $term     = get_term($id);
        $taxonomy = ($term && !is_wp_error($term)) ? $term->taxonomy : '';
    }
    if (!$taxonomy || !taxonomy_exists($taxonomy)) {
        return new WP_Error('bad_taxonomy', 'Unknown or missing taxonomy', ['status' => 400]);
    }

    $result = wp_delete_term($id, $taxonomy);
    if (is_wp_error($result) || $result === false) {
        return new WP_Error('term_delete_failed', 'Could not delete term', ['status' => 400]);
    }

    return rest_ensure_response(['ok' => true, 'id' => $id]);
}

// =============================================================================
// COMMENTS
// =============================================================================

function skales_route_list_comments($request) {
    $status = sanitize_key($request->get_param('status') ?: 'all');
    $map    = ['all' => 'all', 'hold' => 'hold', 'pending' => 'hold', 'approve' => 'approve', 'spam' => 'spam', 'trash' => 'trash'];
    $status = $map[$status] ?? 'all';

    $args = [
        'status' => $status,
        'number' => max(1, min(100, (int) ($request->get_param('per_page') ?: 50))),
        'offset' => max(0, ((int) ($request->get_param('page') ?: 1) - 1)) * max(1, min(100, (int) ($request->get_param('per_page') ?: 50))),
    ];

    $post_id = (int) $request->get_param('post');
    if ($post_id) $args['post_id'] = $post_id;

    $search = $request->get_param('search');
    if ($search) $args['search'] = sanitize_text_field($search);

    $comments = get_comments($args);

    $out = array_map(function ($c) {
        return [
            'id'           => (int) $c->comment_ID,
            'post'         => (int) $c->comment_post_ID,
            'post_title'   => get_the_title($c->comment_post_ID),
            'author_name'  => $c->comment_author,
            'author_email' => $c->comment_author_email,
            'author_url'   => $c->comment_author_url,
            'date'         => $c->comment_date,
            'content'      => $c->comment_content,
            'approved'     => $c->comment_approved,
            'parent'       => (int) $c->comment_parent,
            'link'         => get_comment_link($c),
        ];
    }, $comments);

    return rest_ensure_response([
        'ok'       => true,
        'comments' => $out,
        'counts'   => (array) wp_count_comments($post_id ?: 0),
    ]);
}

function skales_route_create_comment($request) {
    $params  = (array) $request->get_json_params();
    $post_id = (int) ($params['post'] ?? 0);
    if (!$post_id || !get_post($post_id)) {
        return new WP_Error('bad_post', 'A valid post id is required', ['status' => 400]);
    }

    $user = wp_get_current_user();

    $comment_id = wp_insert_comment([
        'comment_post_ID'      => $post_id,
        'comment_content'      => wp_kses_post($params['content'] ?? ''),
        'comment_parent'       => (int) ($params['parent'] ?? 0),
        'comment_author'       => sanitize_text_field($params['author_name'] ?? $user->display_name),
        'comment_author_email' => sanitize_email($params['author_email'] ?? $user->user_email),
        'comment_author_url'   => esc_url_raw($params['author_url'] ?? ''),
        'user_id'              => $user->ID,
        'comment_approved'     => 1,
    ]);

    if (!$comment_id) {
        return new WP_Error('comment_failed', 'Could not create the comment', ['status' => 500]);
    }

    return rest_ensure_response(['ok' => true, 'comment_id' => (int) $comment_id]);
}

function skales_route_update_comment($request) {
    $id     = (int) $request['id'];
    $params = (array) $request->get_json_params();

    if (!get_comment($id)) {
        return new WP_Error('not_found', 'Comment not found', ['status' => 404]);
    }

    if (isset($params['status'])) {
        $status  = sanitize_key($params['status']);
        $allowed = ['approve', 'hold', 'spam', 'unspam', 'trash', 'untrash'];
        if (!in_array($status, $allowed, true)) {
            return new WP_Error('bad_status', 'status must be one of: ' . implode(', ', $allowed), ['status' => 400]);
        }
        wp_set_comment_status($id, $status);
    }

    if (isset($params['content'])) {
        wp_update_comment([
            'comment_ID'      => $id,
            'comment_content' => wp_kses_post($params['content']),
        ]);
    }

    return rest_ensure_response(['ok' => true, 'comment_id' => $id]);
}

function skales_route_delete_comment($request) {
    $id    = (int) $request['id'];
    $force = (bool) $request->get_param('force');

    if (!get_comment($id)) {
        return new WP_Error('not_found', 'Comment not found', ['status' => 404]);
    }

    $result = wp_delete_comment($id, $force);
    if (!$result) {
        return new WP_Error('delete_failed', 'Could not delete the comment', ['status' => 500]);
    }

    return rest_ensure_response(['ok' => true, 'id' => $id, 'deleted' => $force ? 'permanent' : 'trashed']);
}

// =============================================================================
// GUTENBERG
// =============================================================================

function skales_route_serialize_blocks($request) {
    $params = (array) $request->get_json_params();
    $blocks = $params['blocks'] ?? [];
    if (!is_array($blocks) || empty($blocks)) {
        return new WP_Error('no_blocks', 'Provide a non empty blocks array', ['status' => 400]);
    }

    $markup = skales_serialize_block_list($blocks);

    return rest_ensure_response([
        'ok'      => true,
        'content' => $markup,
        'blocks'  => count(parse_blocks($markup)),
    ]);
}

function skales_route_validate_blocks($request) {
    $params  = (array) $request->get_json_params();
    $content = (string) ($params['content'] ?? '');

    if ($content === '') {
        return new WP_Error('no_content', 'Provide block markup in "content"', ['status' => 400]);
    }

    $parsed   = parse_blocks($content);
    $problems = [];
    $names    = [];

    $walk = function ($blocks, $path) use (&$walk, &$problems, &$names) {
        foreach ($blocks as $i => $block) {
            $here = $path . '/' . $i;
            $name = $block['blockName'];
            if ($name === null) {
                // Classic (non block) HTML between blocks. Legal, but worth
                // reporting: it will not be editable as a block.
                if (trim($block['innerHTML']) !== '') {
                    $problems[] = ['path' => $here, 'issue' => 'freeform_html', 'detail' => 'Content outside any block'];
                }
                continue;
            }
            $names[] = $name;
            if (!WP_Block_Type_Registry::get_instance()->is_registered($name)) {
                $problems[] = ['path' => $here, 'issue' => 'unknown_block', 'detail' => $name];
            }
            if (!empty($block['innerBlocks'])) {
                $walk($block['innerBlocks'], $here);
            }
        }
    };
    $walk($parsed, '');

    return rest_ensure_response([
        'ok'       => true,
        'valid'    => empty($problems),
        'blocks'   => array_values(array_unique($names)),
        'problems' => $problems,
    ]);
}

function skales_route_list_reusable_blocks($request) {
    $blocks = get_posts([
        'post_type'   => 'wp_block',
        'numberposts' => 100,
        'post_status' => ['publish', 'draft'],
    ]);

    return rest_ensure_response([
        'ok'     => true,
        'blocks' => array_map(function ($b) {
            return ['id' => (int) $b->ID, 'title' => $b->post_title, 'content' => $b->post_content];
        }, $blocks),
    ]);
}

function skales_route_create_reusable_block($request) {
    $params  = (array) $request->get_json_params();
    $content = !empty($params['blocks'])
        ? skales_serialize_block_list($params['blocks'])
        : (string) ($params['content'] ?? '');

    $lifted = skales_raw_html_begin();
    $id     = wp_insert_post([
        'post_type'    => 'wp_block',
        'post_title'   => sanitize_text_field($params['title'] ?? 'Skales Block'),
        'post_content' => $content,
        'post_status'  => 'publish',
    ], true);
    skales_raw_html_end($lifted);

    if (is_wp_error($id)) {
        return new WP_Error('create_failed', $id->get_error_message(), ['status' => 500]);
    }

    return rest_ensure_response(['ok' => true, 'block_id' => $id]);
}
