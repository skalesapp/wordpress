<?php
/**
 * The Skales screen in wp-admin.
 *
 * @package Skales_Connector
 */

if (!defined('ABSPATH')) exit;

add_action('admin_menu', 'skales_admin_menu');
function skales_admin_menu() {
    add_menu_page(
        __('Skales Connector', 'skales-connector'),
        'Skales',
        'manage_options',
        'skales-connector',
        'skales_admin_page',
        'dashicons-networking',
        80
    );
}

/**
 * Handle the form posts before anything is rendered, so a redirect or a notice
 * is not emitted in the middle of the page.
 *
 * @return array{notice:string,token:string}
 */
function skales_admin_handle_post() {
    $result = ['notice' => '', 'token' => ''];

    if (empty($_POST) || !current_user_can('manage_options')) {
        return $result;
    }

    if (isset($_POST['skales_regenerate_token'])) {
        check_admin_referer('skales_admin');
        $new_token = wp_generate_password(48, false);
        update_option('skales_api_token_hash', hash('sha256', $new_token));
        update_option('skales_connected', false);
        $result['token']  = $new_token;
        $result['notice'] = __('A new token was generated. The old one stopped working immediately.', 'skales-connector');
        return $result;
    }

    if (isset($_POST['skales_relink'])) {
        check_admin_referer('skales_admin');
        update_option('skales_owner_id', get_current_user_id());
        $result['notice'] = __('The connector now acts as your account.', 'skales-connector');
        return $result;
    }

    if (isset($_POST['skales_save_options'])) {
        check_admin_referer('skales_admin');
        update_option('skales_allow_raw_html', isset($_POST['skales_allow_raw_html']) ? 1 : 0);
        $result['notice'] = __('Settings saved.', 'skales-connector');
        return $result;
    }

    return $result;
}

function skales_admin_page() {
    if (!current_user_can('manage_options')) {
        wp_die(esc_html__('You do not have permission to view this page.', 'skales-connector'));
    }

    $posted        = skales_admin_handle_post();
    $token_display = get_option('skales_api_token_display', '');
    $is_connected  = get_option('skales_connected', false);
    $capabilities  = skales_detect_plugins();
    $owner_id      = skales_owner_id();
    $owner         = $owner_id ? get_user_by('id', $owner_id) : null;

    echo '<div class="wrap">';
    echo '<h1>' . esc_html__('Skales Connector', 'skales-connector') . '</h1>';

    if ($posted['notice']) {
        echo '<div class="notice notice-success"><p>' . esc_html($posted['notice']) . '</p></div>';
    }

    $show_token = $posted['token'] ?: $token_display;
    if ($show_token) {
        echo '<div class="notice notice-warning"><p>';
        echo '<strong>' . esc_html__('Your API token (paste this into Skales):', 'skales-connector') . '</strong><br>';
        echo '<code style="font-size:16px;padding:8px;display:inline-block;margin:8px 0;user-select:all;">' . esc_html($show_token) . '</code>';
        echo '<br><em>' . esc_html__('This token is shown once. Copy it now.', 'skales-connector') . '</em>';
        echo '</p></div>';
        if ($token_display) {
            delete_option('skales_api_token_display');
        }
    }

    echo '<h2>' . esc_html__('Connection', 'skales-connector') . '</h2>';
    echo '<table class="widefat" style="max-width:820px"><tbody>';
    echo '<tr><td style="width:220px"><strong>' . esc_html__('Status', 'skales-connector') . '</strong></td><td>'
        . ($is_connected
            ? esc_html__('Connected', 'skales-connector')
            : esc_html__('Waiting for Skales', 'skales-connector'))
        . '</td></tr>';
    echo '<tr><td><strong>' . esc_html__('REST endpoint', 'skales-connector') . '</strong></td><td><code>'
        . esc_html(rest_url('skales/v1/')) . '</code></td></tr>';
    echo '<tr><td><strong>' . esc_html__('Acting as', 'skales-connector') . '</strong></td><td>'
        . ($owner
            ? esc_html($owner->display_name . ' (' . $owner->user_login . ')')
            : '<span style="color:#b32d2e">' . esc_html__('No administrator linked. Use the button below.', 'skales-connector') . '</span>')
        . '</td></tr>';
    echo '<tr><td><strong>' . esc_html__('Plugin version', 'skales-connector') . '</strong></td><td>'
        . esc_html(SKALES_VERSION) . '</td></tr>';
    echo '</tbody></table>';

    echo '<p class="description" style="max-width:820px">'
        . esc_html__('Every request from Skales is checked against the linked account. The connector can never do more in your site than that user is allowed to do, and each change is recorded under that name in the revision history.', 'skales-connector')
        . '</p>';

    echo '<form method="post">';
    wp_nonce_field('skales_admin');
    echo '<p>';
    echo '<button type="submit" name="skales_regenerate_token" class="button">' . esc_html__('Regenerate token', 'skales-connector') . '</button> ';
    echo '<button type="submit" name="skales_relink" class="button">' . esc_html__('Link to my account', 'skales-connector') . '</button>';
    echo '</p>';

    echo '<h2>' . esc_html__('Permissions', 'skales-connector') . '</h2>';
    echo '<label><input type="checkbox" name="skales_allow_raw_html" value="1" '
        . checked(get_option('skales_allow_raw_html', 1), 1, false) . '> '
        . esc_html__('Allow Skales to save unfiltered HTML and CSS in page content', 'skales-connector')
        . '</label>';
    echo '<p class="description" style="max-width:820px">'
        . esc_html__('Skales builds complete pages, which means raw markup and inline styles. Administrators on a single site may already save unfiltered HTML, so this option only matters on multisite or for restricted roles. Turn it off to force every page through the normal WordPress filter.', 'skales-connector')
        . '</p>';
    echo '<p><button type="submit" name="skales_save_options" class="button button-primary">' . esc_html__('Save', 'skales-connector') . '</button></p>';
    echo '</form>';

    echo '<h2>' . esc_html__('Detected plugins', 'skales-connector') . '</h2>';
    echo '<table class="widefat" style="max-width:820px">';
    echo '<thead><tr><th>' . esc_html__('Plugin', 'skales-connector') . '</th><th>' . esc_html__('Status', 'skales-connector') . '</th><th>' . esc_html__('Capabilities', 'skales-connector') . '</th></tr></thead>';
    echo '<tbody>';
    foreach ($capabilities['plugins'] as $plugin) {
        echo '<tr>';
        echo '<td>' . esc_html($plugin['name']) . '</td>';
        echo '<td>' . ($plugin['active']
            ? esc_html__('Active', 'skales-connector')
            : esc_html__('Inactive', 'skales-connector')) . '</td>';
        echo '<td>' . esc_html(implode(', ', $plugin['capabilities'])) . '</td>';
        echo '</tr>';
    }
    echo '</tbody></table>';

    echo '<h2>' . esc_html__('Privacy', 'skales-connector') . '</h2>';
    echo '<p style="max-width:820px">'
        . esc_html__('This plugin contacts no external service. It only answers requests that arrive from your own Skales installation with your token. Nothing is sent anywhere on its own, and there is no tracking, analytics or telemetry.', 'skales-connector')
        . '</p>';

    echo '</div>';
}
