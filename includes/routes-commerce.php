<?php
/**
 * WooCommerce routes.
 *
 * @package Skales_Connector
 */

if (!defined('ABSPATH')) exit;

function skales_register_commerce_routes($ns) {
    register_rest_route($ns, '/woo/products', [
        'methods'             => 'GET',
        'callback'            => 'skales_route_woo_list_products',
        'permission_callback' => skales_cap('edit_posts'),
    ]);

    register_rest_route($ns, '/woo/products/bulk-price', [
        'methods'             => 'PUT, PATCH, POST',
        'callback'            => 'skales_route_woo_bulk_price',
        'permission_callback' => skales_cap('manage_options'),
    ]);
}

function skales_route_woo_list_products($request) {
    if (!class_exists('WooCommerce')) {
        return new WP_Error('no_woo', 'WooCommerce is not active', ['status' => 400]);
    }

    $category = sanitize_text_field($request->get_param('category') ?? '');
    $args = [
        'post_type'   => 'product',
        'numberposts' => max(1, min(100, (int) ($request->get_param('per_page') ?: 50))),
        'post_status' => 'publish',
    ];

    if ($category) {
        $args['tax_query'] = [[
            'taxonomy' => 'product_cat',
            'field'    => 'slug',
            'terms'    => $category,
        ]];
    }

    $products = get_posts($args);
    $result   = [];

    foreach ($products as $p) {
        $product = wc_get_product($p->ID);
        if (!$product) continue;

        $result[] = [
            'id'             => $p->ID,
            'name'           => $p->post_title,
            'price'          => $product->get_price(),
            'regular_price'  => $product->get_regular_price(),
            'sale_price'     => $product->get_sale_price(),
            'stock_status'   => $product->get_stock_status(),
            'stock_quantity' => $product->get_stock_quantity(),
            'categories'     => wp_get_post_terms($p->ID, 'product_cat', ['fields' => 'names']),
            'url'            => get_permalink($p->ID),
        ];
    }

    return rest_ensure_response(['ok' => true, 'products' => $result]);
}

function skales_route_woo_bulk_price($request) {
    if (!class_exists('WooCommerce')) {
        return new WP_Error('no_woo', 'WooCommerce is not active', ['status' => 400]);
    }

    $params      = (array) $request->get_json_params();
    $category    = sanitize_text_field($params['category'] ?? '');
    $discount    = floatval($params['discount_percent'] ?? 0);
    $enable_sale = (bool) ($params['sale'] ?? true);

    if (!$category || $discount <= 0 || $discount > 90) {
        return new WP_Error('invalid_params', 'Provide category and discount_percent (1-90)', ['status' => 400]);
    }

    $products = get_posts([
        'post_type'   => 'product',
        'numberposts' => -1,
        'post_status' => 'publish',
        'tax_query'   => [[
            'taxonomy' => 'product_cat',
            'field'    => 'slug',
            'terms'    => $category,
        ]],
    ]);

    $updated = 0;
    foreach ($products as $p) {
        $product = wc_get_product($p->ID);
        if (!$product) continue;

        $regular = floatval($product->get_regular_price());
        if ($regular <= 0) continue;

        if ($enable_sale) {
            $product->set_sale_price(round($regular * (1 - $discount / 100), 2));
        } else {
            $product->set_sale_price('');
        }

        $product->save();
        $updated++;
    }

    wc_delete_product_transients();

    return rest_ensure_response([
        'ok'               => true,
        'updated'          => $updated,
        'category'         => $category,
        'discount_percent' => $discount,
    ]);
}
