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
        'post_title'   => wp_slash(sanitize_text_field($params['title'] ?? 'Skales Page')),
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
        wp_update_post(['ID' => $page_id, 'post_title' => wp_slash(sanitize_text_field($params['title']))]);
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
 * May this request store unfiltered markup in Elementor settings?
 *
 * The answer is the same one skales_raw_html_begin() gives for post content:
 * the linked account's own unfiltered_html capability first, then the switch on
 * the Skales screen. Elementor keeps the page in post meta rather than in
 * post_content, so kses never sees it and the decision has to be made here
 * instead of being left to a filter that does not run.
 *
 * @return bool
 */
function skales_elementor_allows_raw_html() {
    if (current_user_can('unfiltered_html')) {
        return true;
    }
    return (bool) get_option('skales_allow_raw_html', 1);
}

/**
 * Clean one settings value on its way into _elementor_data.
 *
 * Strings are filtered through wp_kses_post unless raw markup is allowed.
 * Anything that names a target - url, href, link - goes through esc_url_raw in
 * both cases, because a javascript: target is not markup the site owner asked
 * for by ticking the raw HTML box. Numbers, booleans and null are left alone:
 * Elementor stores sizes, flags and units as their own types and turning them
 * into strings changes how a widget renders.
 *
 * @param mixed  $value
 * @param string $key
 * @param bool   $allow_raw
 * @return mixed
 */
function skales_sanitize_elementor_value($value, $key, $allow_raw) {
    if (is_object($value)) {
        $value = get_object_vars($value);
    }

    if (is_array($value)) {
        $clean = [];
        foreach ($value as $child_key => $child) {
            $clean[$child_key] = skales_sanitize_elementor_value(
                $child,
                is_string($child_key) ? $child_key : $key,
                $allow_raw
            );
        }
        return $clean;
    }

    if (!is_string($value)) {
        return $value;
    }

    if (in_array($key, ['url', 'href'], true)) {
        return esc_url_raw($value);
    }

    return $allow_raw ? $value : wp_kses_post($value);
}

/**
 * Clean a whole settings map.
 *
 * @param mixed $settings
 * @param bool  $allow_raw
 * @return array
 */
function skales_sanitize_elementor_settings($settings, $allow_raw) {
    if (is_object($settings)) {
        $settings = get_object_vars($settings);
    }
    if (!is_array($settings)) {
        return [];
    }
    return skales_sanitize_elementor_value($settings, '', $allow_raw);
}

/**
 * A colour or gradient string as Elementor's colour controls accept it. The
 * value ends up in a generated stylesheet, where a stray brace or angle bracket
 * would leave the declaration it belongs to.
 *
 * @param mixed $value
 * @return string
 */
function skales_sanitize_elementor_color($value) {
    return trim(preg_replace('/[^A-Za-z0-9#(),.%\s\/_-]/', '', (string) $value));
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
    $allow_raw  = skales_elementor_allows_raw_html();

    foreach ((array) $sections as $section) {
        if (is_object($section)) {
            $section = get_object_vars($section);
        }
        if (!is_array($section)) {
            continue;
        }
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
            $background = skales_sanitize_elementor_color($section['background']);
            if (strpos($background, 'gradient') !== false) {
                $container_settings['background_background']  = 'gradient';
                $container_settings['background_color']       = '#0f172a';
                $container_settings['background_color_b']     = '#1e1b4b';
            } else {
                $container_settings['background_background'] = 'classic';
                $container_settings['background_color']      = $background;
            }
        }

        if (!empty($section['background_image'])) {
            $container_settings['background_background'] = 'classic';
            $container_settings['background_image']      = ['url' => esc_url_raw((string) $section['background_image']), 'id' => ''];
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

        if (!empty($section['settings'])) {
            $container_settings = array_merge(
                $container_settings,
                skales_sanitize_elementor_settings($section['settings'], $allow_raw)
            );
        }

        $children = [];

        if ($num_columns <= 1) {
            $col = ($section['columns'] ?? [[]])[0] ?? [];
            $col = is_object($col) ? get_object_vars($col) : (array) $col;
            foreach (($col['widgets'] ?? []) as $widget) {
                $children[] = skales_build_widget($widget, $allow_raw);
            }
        } else {
            foreach (($section['columns'] ?? []) as $col) {
                $col         = is_object($col) ? get_object_vars($col) : (array) $col;
                $col_width   = intval($col['width'] ?? round(100 / $num_columns));
                $col_widgets = [];

                foreach (($col['widgets'] ?? []) as $widget) {
                    $col_widgets[] = skales_build_widget($widget, $allow_raw);
                }

                $inner_settings = [
                    'content_width'  => 'full',
                    'flex_direction' => 'column',
                    'flex_basis'     => ['size' => $col_width, 'unit' => '%'],
                    'width'          => ['size' => $col_width, 'unit' => '%'],
                ];

                if (!empty($col['settings'])) {
                    $inner_settings = array_merge(
                        $inner_settings,
                        skales_sanitize_elementor_settings($col['settings'], $allow_raw)
                    );
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
            ], $allow_raw);
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
 * @param bool  $allow_raw Whether unfiltered markup may be stored.
 * @return array
 */
function skales_build_widget($widget, $allow_raw = null) {
    if (is_object($widget)) {
        $widget = get_object_vars($widget);
    }
    if (!is_array($widget)) {
        $widget = [];
    }
    if ($allow_raw === null) {
        $allow_raw = skales_elementor_allows_raw_html();
    }

    // The widget type names a registered Elementor widget, nothing else.
    $widget_type = preg_replace('/[^A-Za-z0-9_-]/', '', (string) ($widget['type'] ?? 'text-editor'));
    if ($widget_type === '') {
        $widget_type = 'text-editor';
    }

    $widget_settings = skales_sanitize_elementor_settings($widget['settings'] ?? [], $allow_raw);

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
        // Settings must encode as a JSON object, never as an empty array.
        'settings'   => empty($widget_settings) ? new \stdClass() : $widget_settings,
        'elements'   => [],
    ];
}

function skales_generate_id() {
    return substr(md5(uniqid(strval(mt_rand()), true)), 0, 8);
}
