<?php
/**
 * Front end: force full width for pages Skales built.
 *
 * @package Skales_Connector
 */

if (!defined('ABSPATH')) exit;

/**
 * Detect whether the current page or post was created by Skales.
 * Checks the _skales_page meta first, then falls back to a content heuristic
 * for pages that predate the meta flag.
 *
 * @param int|null $post_id
 * @return bool
 */
function skales_is_skales_page($post_id = null) {
    if (!$post_id) $post_id = get_the_ID();
    if (!$post_id) return false;

    if (get_post_meta($post_id, '_skales_page', true)) return true;

    $content = get_post_field('post_content', $post_id);
    return (strpos($content, 'wp:html') !== false || strpos($content, '<style') !== false);
}

/**
 * Add the skales-page body class so the overrides below have high specificity.
 */
add_filter('body_class', 'skales_body_class');
function skales_body_class($classes) {
    if (is_singular() && skales_is_skales_page()) {
        $classes[] = 'skales-page';
    }
    return $classes;
}

/**
 * Inject the full width overrides. Covers Twenty Twenty-Four and later block
 * themes, Astra, GeneratePress, Kadence, OceanWP, Elementor containers and the
 * generic theme wrappers.
 */
add_action('wp_head', 'skales_fullwidth_css');
function skales_fullwidth_css() {
    if (!is_singular()) return;
    if (!skales_is_skales_page()) return;

    echo '<style id="skales-fullwidth-overrides">
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

        body.skales-page .ast-container,
        body.skales-page .site-content .ast-container,
        body.skales-page .ast-separate-container .ast-article-single {
            max-width: 100% !important;
            padding-left: 0 !important;
            padding-right: 0 !important;
        }

        body.skales-page .inside-article,
        body.skales-page .site-content .content-area,
        body.skales-page .container.grid-container {
            max-width: 100% !important;
            width: 100% !important;
            padding-left: 0 !important;
            padding-right: 0 !important;
        }

        body.skales-page .wp-site-blocks,
        body.skales-page .wp-block-post-content,
        body.skales-page .is-layout-constrained > :where(:not(.alignleft):not(.alignright):not(.alignfull)),
        body.skales-page .wp-block-group.is-layout-constrained {
            max-width: 100% !important;
            padding-left: 0 !important;
            padding-right: 0 !important;
        }

        body.skales-page .content-container.site-container,
        body.skales-page .entry-content-wrap {
            max-width: 100% !important;
            padding-left: 0 !important;
            padding-right: 0 !important;
        }

        body.skales-page .content-area .site-main,
        body.skales-page #content-wrap .container {
            max-width: 100% !important;
            width: 100% !important;
            padding-left: 0 !important;
            padding-right: 0 !important;
        }

        body.skales-page .elementor-section.elementor-section-boxed > .elementor-container {
            max-width: 100% !important;
        }
        body.skales-page .e-con {
            max-width: 100% !important;
        }

        body.skales-page {
            overflow-x: hidden !important;
        }
    </style>';
}

/**
 * Wrap Skales page content in a full width container as a last resort override
 * for stubborn theme CSS.
 */
add_filter('the_content', 'skales_wrap_content_fullwidth', 999);
function skales_wrap_content_fullwidth($content) {
    if (!is_singular() || !is_main_query() || !in_the_loop()) return $content;
    if (!skales_is_skales_page()) return $content;

    return '<div class="skales-content-wrapper" style="max-width:100%!important;width:100%!important;padding:0!important;margin:0 auto!important;box-sizing:border-box!important;">'
        . $content
        . '</div>';
}
