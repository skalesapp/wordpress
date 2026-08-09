<?php
/**
 * Elementor page building.
 *
 * @package Skales_Connector
 */

if (!defined('ABSPATH')) exit;

function skales_register_elementor_routes($ns) {
    register_rest_route($ns, '/elementor/page', [
        'methods'             => 'POST',
        'callback'            => 'skales_route_elementor_create_page',
        'permission_callback' => skales_cap('publish_pages'),
    ]);

    register_rest_route($ns, '/elementor/page/(?P<id>\d+)', [
        'methods'             => 'PUT, PATCH',
        'callback'            => 'skales_route_elementor_update_page',
        'permission_callback' => skales_cap('edit_pages'),
    ]);
}

function skales_route_elementor_create_page($request) {
    skales_require_plugin_api();
    if (!is_plugin_active('elementor/elementor.php')) {
        return new WP_Error('no_elementor', 'Elementor is not installed or active', ['status' => 400]);
    }

    $params = (array) $request->get_json_params();

    $lifted  = skales_raw_html_begin();
    $page_id = wp_insert_post([
        'post_title'   => sanitize_text_field($params['title'] ?? 'Skales Page'),
        'post_content' => '',
        'post_status'  => skales_sanitize_status($params['status'] ?? 'draft'),
        'post_type'    => 'page',
    ], true);
    skales_raw_html_end($lifted);

    if (is_wp_error($page_id)) {
        return new WP_Error('create_failed', $page_id->get_error_message(), ['status' => 500]);
    }

    update_post_meta($page_id, '_skales_page', '1');
    skales_apply_post_extras($page_id, $params);

    $elementor_data = skales_build_elementor_data($params['sections'] ?? []);

    $elementor_version = defined('ELEMENTOR_VERSION') ? ELEMENTOR_VERSION : '3.16.0';

    update_post_meta($page_id, '_elementor_data', wp_slash(wp_json_encode($elementor_data)));
    update_post_meta($page_id, '_elementor_edit_mode', 'builder');
    update_post_meta($page_id, '_elementor_template_type', 'wp-page');
    update_post_meta($page_id, '_elementor_version', $elementor_version);
    update_post_meta($page_id, '_elementor_css', '');

    // The template must be elementor_canvas or elementor_header_footer.
    // 'default' wraps the content in theme containers, which renders blank.
    if (empty($params['template'])) {
        update_post_meta($page_id, '_wp_page_template', 'elementor_canvas');
    }

    update_post_meta($page_id, '_elementor_page_settings', [
        'hide_title' => 'yes',
        'template'   => 'elementor_canvas',
    ]);

    skales_elementor_regenerate_css($page_id);

    return rest_ensure_response([
        'ok'       => true,
        'page_id'  => $page_id,
        'url'      => get_permalink($page_id),
        'edit_url' => admin_url("post.php?post={$page_id}&action=elementor"),
    ]);
}

function skales_route_elementor_update_page($request) {
    skales_require_plugin_api();
    if (!is_plugin_active('elementor/elementor.php')) {
        return new WP_Error('no_elementor', 'Elementor is not installed', ['status' => 400]);
    }

    $page_id = (int) $request['id'];
    if (!get_post($page_id)) {
        return new WP_Error('not_found', 'Page not found', ['status' => 404]);
    }
    if (!current_user_can('edit_post', $page_id)) {
        return new WP_Error('forbidden', 'Not allowed to edit this page', ['status' => 403]);
    }

    $params = (array) $request->get_json_params();

    if (isset($params['sections'])) {
        $elementor_data    = skales_build_elementor_data($params['sections']);
        $elementor_version = defined('ELEMENTOR_VERSION') ? ELEMENTOR_VERSION : '3.16.0';
        update_post_meta($page_id, '_elementor_data', wp_slash(wp_json_encode($elementor_data)));
        update_post_meta($page_id, '_elementor_version', $elementor_version);
        skales_elementor_regenerate_css($page_id);
    }

    if (isset($params['title'])) {
        wp_update_post(['ID' => $page_id, 'post_title' => sanitize_text_field($params['title'])]);
    }
    if (isset($params['status'])) {
        wp_update_post(['ID' => $page_id, 'post_status' => skales_sanitize_status($params['status'])]);
    }

    skales_apply_post_extras($page_id, $params);

    return rest_ensure_response(['ok' => true, 'page_id' => $page_id]);
}

/**
 * Force Elementor to rebuild the CSS file for one page.
 *
 * @param int $page_id
 * @return void
 */
function skales_elementor_regenerate_css($page_id) {
    if (!class_exists('\Elementor\Plugin')) return;

    \Elementor\Plugin::$instance->files_manager->clear_cache();
    if (class_exists('\Elementor\Core\Files\CSS\Post')) {
        $post_css = \Elementor\Core\Files\CSS\Post::create($page_id);
        if ($post_css) {
            $post_css->update();
        }
    }
}

/**
 * Build Elementor's data structure from Skales section descriptors.
 *
 * Flexbox Container format (Elementor 3.6 and later):
 *   container -> widget                      (single column)
 *   container -> container(inner) -> widget  (multi column)
 * The old section/column/widget format does not render once Flexbox Container
 * is set to "Standard", which has been the default since about 3.16.
 *
 * @param array $sections
 * @return array
 */
function skales_build_elementor_data($sections) {
    $containers = [];

    foreach ((array) $sections as $section) {
        $num_columns = max(1, count($section['columns'] ?? []));

        $container_settings = [
            'content_width'  => 'full',
            'flex_direction' => ($num_columns > 1) ? 'row' : 'column',
            'flex_wrap'      => 'wrap',
            'flex_gap'       => ['size' => 0, 'unit' => 'px', 'column' => '0'],
        ];

        // Layout mapping: '1' single, '1-1' two columns, '1-1-1' three, and so on.
        if (!empty($section['layout'])) {
            if ($section['layout'] === 'full_width' || $section['layout'] === 'boxed') {
                $container_settings['content_width'] = $section['layout'];
            }
            if (strpos($section['layout'], '-') !== false) {
                $container_settings['flex_direction'] = 'row';
            }
        }

        if (!empty($section['background'])) {
            if (strpos($section['background'], 'gradient') !== false) {
                $container_settings['background_background']  = 'gradient';
                $container_settings['background_color']       = '#0f172a';
                $container_settings['background_color_b']     = '#1e1b4b';
            } else {
                $container_settings['background_background'] = 'classic';
                $container_settings['background_color']      = $section['background'];
            }
        }

        if (!empty($section['background_image'])) {
            $container_settings['background_background'] = 'classic';
            $container_settings['background_image']      = ['url' => $section['background_image'], 'id' => ''];
            $container_settings['background_size']       = 'cover';
            $container_settings['background_position']   = 'center center';
        }

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

        if (!empty($section['settings']) && is_array($section['settings'])) {
            $container_settings = array_merge($container_settings, $section['settings']);
        }

        $children = [];

        if ($num_columns <= 1) {
            $col = ($section['columns'] ?? [[]])[0] ?? [];
            foreach (($col['widgets'] ?? []) as $widget) {
                $children[] = skales_build_widget($widget);
            }
        } else {
            foreach (($section['columns'] ?? []) as $col) {
                $col_width   = intval($col['width'] ?? round(100 / $num_columns));
                $col_widgets = [];

                foreach (($col['widgets'] ?? []) as $widget) {
                    $col_widgets[] = skales_build_widget($widget);
                }

                $inner_settings = [
                    'content_width'  => 'full',
                    'flex_direction' => 'column',
                    'flex_basis'     => ['size' => $col_width, 'unit' => '%'],
                    'width'          => ['size' => $col_width, 'unit' => '%'],
                ];

                if (!empty($col['settings']) && is_array($col['settings'])) {
                    $inner_settings = array_merge($inner_settings, $col['settings']);
                }

                $children[] = [
                    'id'       => skales_generate_id(),
                    'elType'   => 'container',
                    'isInner'  => true,
                    'settings' => $inner_settings,
                    'elements' => $col_widgets,
                ];
            }
        }

        if (empty($children)) {
            $children[] = skales_build_widget([
                'type'     => 'text-editor',
                'settings' => ['editor' => '<p style="text-align:center;color:#64748b;">Empty section, edit in Elementor</p>'],
            ]);
        }

        $containers[] = [
            'id'       => skales_generate_id(),
            'elType'   => 'container',
            'isInner'  => false,
            'settings' => $container_settings,
            'elements' => $children,
        ];
    }

    return $containers;
}

/**
 * Build a single Elementor widget element from a Skales widget descriptor.
 * Normalises the common aliases (content to editor, text to title, url to image).
 *
 * @param array $widget
 * @return array
 */
function skales_build_widget($widget) {
    $widget_type     = $widget['type'] ?? 'text-editor';
    $widget_settings = $widget['settings'] ?? [];

    // Settings must encode as a JSON object, never as an empty array.
    if (empty($widget_settings) || $widget_settings === []) {
        $widget_settings = new \stdClass();
    }

    if ($widget_type === 'text-editor' && empty($widget_settings['editor'])) {
        $text = $widget_settings['content'] ?? $widget_settings['text'] ?? $widget_settings['html'] ?? '';
        if ($text) {
            $widget_settings['editor'] = $text;
        }
    }

    if ($widget_type === 'heading' && empty($widget_settings['title'])) {
        $widget_settings['title'] = $widget_settings['text'] ?? $widget_settings['content'] ?? '';
    }

    if ($widget_type === 'button' && empty($widget_settings['text'])) {
        $widget_settings['text'] = $widget_settings['label'] ?? $widget_settings['content'] ?? 'Click Here';
    }

    if ($widget_type === 'image' && !empty($widget_settings['url']) && empty($widget_settings['image'])) {
        $widget_settings['image'] = ['url' => $widget_settings['url'], 'id' => ''];
    }

    return [
        'id'         => skales_generate_id(),
        'elType'     => 'widget',
        'widgetType' => $widget_type,
        'isInner'    => false,
        'settings'   => $widget_settings,
        'elements'   => [],
    ];
}

function skales_generate_id() {
    return substr(md5(uniqid(strval(mt_rand()), true)), 0, 8);
}
