<?php
/**
 * Media routes: upload by base64 or URL, list, edit, delete, featured image.
 *
 * @package Skales_Connector
 */

if (!defined('ABSPATH')) exit;

function skales_register_media_routes($ns) {
    register_rest_route($ns, '/media', [
        [
            'methods'             => 'GET',
            'callback'            => 'skales_route_list_media',
            'permission_callback' => skales_cap('upload_files'),
        ],
        [
            'methods'             => 'POST',
            'callback'            => 'skales_route_upload_media',
            'permission_callback' => skales_cap('upload_files'),
        ],
    ]);

    register_rest_route($ns, '/media/(?P<id>\d+)', [
        [
            'methods'             => 'PUT, PATCH',
            'callback'            => 'skales_route_update_media',
            'permission_callback' => skales_cap('upload_files'),
        ],
        [
            'methods'             => 'DELETE',
            'callback'            => 'skales_route_delete_media',
            'permission_callback' => skales_cap('upload_files'),
        ],
    ]);

    // One call for "put a fitting image on this post": takes an existing
    // attachment, a remote URL or raw bytes, and leaves the post with a
    // featured image either way.
    register_rest_route($ns, '/featured-image', [
        'methods'             => 'POST',
        'callback'            => 'skales_route_set_featured_image',
        'permission_callback' => skales_cap('upload_files'),
    ]);
}

/**
 * File extensions that must never reach the uploads folder, whatever the bytes
 * claim to be.
 *
 * @return string[]
 */
function skales_blocked_extensions() {
    return ['php', 'phtml', 'phar', 'php3', 'php4', 'php5', 'php7', 'php8',
            'pl', 'py', 'cgi', 'sh', 'exe', 'bat', 'cmd', 'com',
            'vbs', 'ps1', 'js', 'mjs', 'jsp', 'asp', 'aspx', 'htaccess', 'htm', 'html',
            'svg', 'svgz'];
}

/**
 * MIME types the connector accepts.
 *
 * SVG is deliberately absent: an SVG can carry script, and WordPress core does
 * not allow SVG uploads either. Sites that have knowingly enabled SVG (with a
 * sanitizer) can add it back through the skales_allowed_mimes filter.
 *
 * @return string[]
 */
function skales_allowed_mimes() {
    $mimes = [
        'image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/avif',
        'application/pdf',
        'video/mp4', 'video/webm', 'video/quicktime',
        'audio/mpeg', 'audio/ogg', 'audio/wav', 'audio/mp4', 'audio/flac',
    ];
    /**
     * Filter the MIME types the Skales Connector will store.
     *
     * @param string[] $mimes
     */
    return apply_filters('skales_allowed_mimes', $mimes);
}

/**
 * Validate decoded bytes and store them in the media library.
 *
 * @param string $data     Raw file bytes.
 * @param string $filename Desired file name.
 * @param string $alt      Optional alt text.
 * @return array|WP_Error
 */
function skales_store_media_bytes($data, $filename, $alt = '') {
    $filename  = sanitize_file_name($filename ?: 'upload.png');
    $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

    // The same ceiling a browser upload hits. Without it a payload larger than
    // the site can store is decoded, written to a temp file and only then
    // refused, or fills the disk on a host that has no other limit.
    $limit = function_exists('wp_max_upload_size') ? (int) wp_max_upload_size() : 0;
    $size  = strlen($data);
    if ($size === 0) {
        return new WP_Error('empty_file', 'The file has no content', ['status' => 400]);
    }
    if ($limit > 0 && $size > $limit) {
        return new WP_Error(
            'file_too_large',
            sprintf('The file is %s and this site accepts at most %s.', size_format($size), size_format($limit)),
            ['status' => 400]
        );
    }

    if (in_array($extension, skales_blocked_extensions(), true)) {
        return new WP_Error('invalid_file_type', 'Disallowed file extension: ' . $extension, ['status' => 400]);
    }

    // Write the bytes to a temp file so wp_check_filetype_and_ext() can sniff
    // the real type with finfo instead of trusting the name.
    // wp_tempnam() lives in wp-admin/includes/file.php, which a REST request
    // does not load on its own.
    require_once ABSPATH . 'wp-admin/includes/file.php';
    $tmp_path = wp_tempnam($filename);
    if (!$tmp_path) {
        return new WP_Error('upload_tempfile_failed', 'Could not create temp file for validation', ['status' => 500]);
    }
    file_put_contents($tmp_path, $data);

    $check = wp_check_filetype_and_ext($tmp_path, $filename);
    @unlink($tmp_path);

    $type = !empty($check['type']) ? $check['type'] : '';
    if (!$type || !in_array($type, skales_allowed_mimes(), true)) {
        return new WP_Error('invalid_file_type', 'File MIME type not allowed: ' . ($type ?: 'unknown'), ['status' => 400]);
    }

    // The bytes decide the extension. A PNG called "photo.jpg" is stored as
    // "photo.png", the same correction core applies to a browser upload,
    // otherwise the library ends up with files whose name lies about them.
    if (!empty($check['proper_filename'])) {
        $filename  = $check['proper_filename'];
        $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        if (in_array($extension, skales_blocked_extensions(), true)) {
            return new WP_Error('invalid_file_type', 'Disallowed file extension: ' . $extension, ['status' => 400]);
        }
    }

    $upload = wp_upload_bits($filename, null, $data);
    if (!empty($upload['error'])) {
        return new WP_Error('upload_failed', $upload['error'], ['status' => 500]);
    }

    $filetype = wp_check_filetype($upload['file']);
    $attach_id = wp_insert_attachment([
        'post_mime_type' => $filetype['type'],
        'post_title'     => pathinfo($filename, PATHINFO_FILENAME),
        'post_status'    => 'inherit',
    ], $upload['file']);

    if (is_wp_error($attach_id) || !$attach_id) {
        return new WP_Error('attachment_failed', 'Could not create the attachment', ['status' => 500]);
    }

    require_once ABSPATH . 'wp-admin/includes/image.php';
    wp_update_attachment_metadata($attach_id, wp_generate_attachment_metadata($attach_id, $upload['file']));

    if ($alt !== '') {
        update_post_meta($attach_id, '_wp_attachment_image_alt', sanitize_text_field($alt));
    }

    return [
        'attachment_id' => (int) $attach_id,
        'url'           => wp_get_attachment_url($attach_id),
        'mime'          => $filetype['type'],
    ];
}

/**
 * Fetch a remote file and store it. The URL always comes from the site owner's
 * own Skales session; wp_safe_remote_get() still refuses private and loopback
 * addresses, which keeps the endpoint from being used to probe the network the
 * site sits in.
 *
 * @param string $url
 * @param string $filename
 * @param string $alt
 * @return array|WP_Error
 */
function skales_store_media_url($url, $filename = '', $alt = '') {
    $url = esc_url_raw(trim($url));
    if (!$url || !preg_match('#^https?://#i', $url)) {
        return new WP_Error('bad_url', 'Only http and https URLs can be fetched', ['status' => 400]);
    }
    if (!wp_http_validate_url($url)) {
        return new WP_Error('bad_url', 'That URL is not reachable from this site', ['status' => 400]);
    }

    $response = wp_safe_remote_get($url, [
        'timeout'    => 30,
        'user-agent' => 'SkalesConnector/' . SKALES_VERSION . '; ' . home_url('/'),
    ]);

    if (is_wp_error($response)) {
        return new WP_Error('fetch_failed', $response->get_error_message(), ['status' => 400]);
    }

    $code = (int) wp_remote_retrieve_response_code($response);
    if ($code < 200 || $code >= 300) {
        return new WP_Error('fetch_failed', 'Remote server answered with HTTP ' . $code, ['status' => 400]);
    }

    $body = wp_remote_retrieve_body($response);
    if ($body === '') {
        return new WP_Error('fetch_empty', 'Remote file was empty', ['status' => 400]);
    }

    if ($filename === '') {
        $path     = wp_parse_url($url, PHP_URL_PATH);
        $filename = $path ? basename($path) : '';
        if ($filename === '' || strpos($filename, '.') === false) {
            $mime      = wp_remote_retrieve_header($response, 'content-type');
            $mime      = is_array($mime) ? reset($mime) : (string) $mime;
            $mime      = trim(explode(';', $mime)[0]);
            $extension = skales_extension_for_mime($mime);
            $filename  = 'skales-image-' . gmdate('YmdHis') . ($extension ? '.' . $extension : '.jpg');
        }
    }

    return skales_store_media_bytes($body, $filename, $alt);
}

/**
 * Map a MIME type back to the extension WordPress uses for it.
 *
 * @param string $mime
 * @return string
 */
function skales_extension_for_mime($mime) {
    foreach (wp_get_mime_types() as $extensions => $type) {
        if ($type === $mime) {
            $parts = explode('|', $extensions);
            return $parts[0];
        }
    }
    return '';
}

// =============================================================================
// ROUTES
// =============================================================================

function skales_route_upload_media($request) {
    $params = (array) $request->get_json_params();
    $alt    = (string) ($params['alt'] ?? '');

    if (!empty($params['url'])) {
        $result = skales_store_media_url($params['url'], sanitize_file_name($params['filename'] ?? ''), $alt);
    } elseif (!empty($params['base64'])) {
        $data = base64_decode($params['base64'], true);
        if ($data === false) {
            return new WP_Error('bad_base64', 'base64 payload could not be decoded', ['status' => 400]);
        }
        $result = skales_store_media_bytes($data, (string) ($params['filename'] ?? 'upload.png'), $alt);
    } else {
        return new WP_Error('no_data', 'Provide either base64 or url', ['status' => 400]);
    }

    if (is_wp_error($result)) return $result;

    if (!empty($params['title'])) {
        wp_update_post(['ID' => $result['attachment_id'], 'post_title' => sanitize_text_field($params['title'])]);
    }

    // 1.x returned exactly ok / attachment_id / url.
    return rest_ensure_response([
        'ok'            => true,
        'attachment_id' => $result['attachment_id'],
        'url'           => $result['url'],
        'mime'          => $result['mime'],
    ]);
}

function skales_route_list_media($request) {
    $per_page = max(1, min(100, (int) ($request->get_param('per_page') ?: 50)));
    $page     = max(1, (int) ($request->get_param('page') ?: 1));

    $args = [
        'post_type'      => 'attachment',
        'post_status'    => 'inherit',
        'numberposts'    => $per_page,
        'offset'         => ($page - 1) * $per_page,
    ];

    $mime = $request->get_param('mime_type');
    if ($mime) $args['post_mime_type'] = sanitize_text_field($mime);

    $search = $request->get_param('search');
    if ($search) $args['s'] = sanitize_text_field($search);

    $items = get_posts($args);

    return rest_ensure_response([
        'ok'    => true,
        'media' => array_map(function ($m) {
            $meta = wp_get_attachment_metadata($m->ID);
            return [
                'id'       => (int) $m->ID,
                'title'    => $m->post_title,
                'url'      => wp_get_attachment_url($m->ID),
                'mime'     => $m->post_mime_type,
                'alt'      => get_post_meta($m->ID, '_wp_attachment_image_alt', true),
                'caption'  => $m->post_excerpt,
                'width'    => isset($meta['width']) ? (int) $meta['width'] : null,
                'height'   => isset($meta['height']) ? (int) $meta['height'] : null,
                'date'     => $m->post_date,
            ];
        }, $items),
    ]);
}

function skales_route_update_media($request) {
    $id = (int) $request['id'];
    if (get_post_type($id) !== 'attachment') {
        return new WP_Error('not_found', 'Attachment not found', ['status' => 404]);
    }
    if (!current_user_can('edit_post', $id)) {
        return new WP_Error('forbidden', 'Not allowed to edit this attachment', ['status' => 403]);
    }

    $params = (array) $request->get_json_params();
    $update = ['ID' => $id];

    if (isset($params['title']))       $update['post_title']   = wp_slash(sanitize_text_field($params['title']));
    if (isset($params['caption']))     $update['post_excerpt'] = wp_slash(wp_kses_post($params['caption']));
    if (isset($params['description'])) $update['post_content'] = wp_slash(wp_kses_post($params['description']));

    if (count($update) > 1) {
        wp_update_post($update);
    }

    if (isset($params['alt'])) {
        update_post_meta($id, '_wp_attachment_image_alt', sanitize_text_field($params['alt']));
    }

    return rest_ensure_response(['ok' => true, 'attachment_id' => $id]);
}

function skales_route_delete_media($request) {
    $id = (int) $request['id'];
    if (get_post_type($id) !== 'attachment') {
        return new WP_Error('not_found', 'Attachment not found', ['status' => 404]);
    }
    if (!current_user_can('delete_post', $id)) {
        return new WP_Error('forbidden', 'Not allowed to delete this attachment', ['status' => 403]);
    }

    // Attachments have no trash by default, so this is always permanent.
    if (!wp_delete_attachment($id, true)) {
        return new WP_Error('delete_failed', 'Could not delete the attachment', ['status' => 500]);
    }

    return rest_ensure_response(['ok' => true, 'id' => $id, 'deleted' => 'permanent']);
}

function skales_route_set_featured_image($request) {
    $params  = (array) $request->get_json_params();
    $post_id = (int) ($params['post_id'] ?? $params['page_id'] ?? 0);

    if (!$post_id || !get_post($post_id)) {
        return new WP_Error('bad_post', 'A valid post_id is required', ['status' => 400]);
    }
    if (!current_user_can('edit_post', $post_id)) {
        return new WP_Error('forbidden', 'Not allowed to edit this item', ['status' => 403]);
    }

    $attachment_id = (int) ($params['attachment_id'] ?? 0);
    $alt           = (string) ($params['alt'] ?? '');

    if (!$attachment_id) {
        if (!empty($params['url'])) {
            $result = skales_store_media_url($params['url'], sanitize_file_name($params['filename'] ?? ''), $alt);
        } elseif (!empty($params['base64'])) {
            $data = base64_decode($params['base64'], true);
            if ($data === false) {
                return new WP_Error('bad_base64', 'base64 payload could not be decoded', ['status' => 400]);
            }
            $result = skales_store_media_bytes($data, (string) ($params['filename'] ?? 'featured.png'), $alt);
        } else {
            return new WP_Error('no_image', 'Provide attachment_id, url or base64', ['status' => 400]);
        }

        if (is_wp_error($result)) return $result;
        $attachment_id = $result['attachment_id'];
    }

    if (get_post_type($attachment_id) !== 'attachment') {
        return new WP_Error('bad_attachment', 'That attachment does not exist', ['status' => 400]);
    }

    if (!set_post_thumbnail($post_id, $attachment_id)) {
        return new WP_Error('set_failed', 'Could not set the featured image', ['status' => 500]);
    }

    if ($alt !== '') {
        update_post_meta($attachment_id, '_wp_attachment_image_alt', sanitize_text_field($alt));
    }

    return rest_ensure_response([
        'ok'            => true,
        'post_id'       => $post_id,
        'attachment_id' => (int) $attachment_id,
        'url'           => wp_get_attachment_url($attachment_id),
    ]);
}
