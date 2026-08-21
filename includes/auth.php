<?php
/**
 * Token authentication and the capability model.
 *
 * The connector is reached with a Bearer token that the site owner generated in
 * wp-admin. Only the SHA-256 hash of that token is stored. A valid token binds
 * the request to one real WordPress account (the "owner"), and every endpoint
 * then runs a normal capability check against that account. The token therefore
 * never grants more than the linked user already has.
 *
 * @package Skales_Connector
 */

if (!defined('ABSPATH')) exit;

/**
 * The WordPress user the connector acts as.
 *
 * Set on activation to whoever installed the plugin. The stored account is used
 * exactly as it is, whatever its role: pointing the connector at an editor is a
 * legitimate way to fence it in, and quietly promoting it to an administrator
 * because the capability check would otherwise fail would defeat the whole
 * point of having capability checks.
 *
 * Only when nothing is stored, or the stored user no longer exists, do we fall
 * back to the first administrator, so a site upgrading from 1.x keeps working
 * without a manual step. Returns 0 when there is no administrator either, which
 * callers treat as an error.
 *
 * @return int
 */
function skales_owner_id() {
    $stored = (int) get_option('skales_owner_id', 0);
    if ($stored && get_user_by('id', $stored)) {
        return $stored;
    }

    $admins = get_users([
        'role'    => 'administrator',
        'number'  => 1,
        'orderby' => 'ID',
        'order'   => 'ASC',
        'fields'  => 'ID',
    ]);

    if (!empty($admins)) {
        $id = (int) $admins[0];
        update_option('skales_owner_id', $id);
        return $id;
    }

    return 0;
}

/**
 * The Bearer token that came with this request.
 *
 * WP_REST_Request is the first source. Apache in CGI or FastCGI mode drops the
 * Authorization header unless the site has the matching SetEnvIf rule, and then
 * hands it on as REDIRECT_HTTP_AUTHORIZATION instead, so the server variables
 * are read as a fallback. Without that a perfectly valid token looks missing
 * and every call fails with 401 on hosting the site owner cannot change.
 *
 * @param WP_REST_Request|null $request
 * @return string Empty string when no Bearer token is present.
 */
function skales_bearer_token($request = null) {
    $candidates = [];

    if ($request instanceof WP_REST_Request) {
        $candidates[] = (string) $request->get_header('Authorization');
    }

    foreach (['HTTP_AUTHORIZATION', 'REDIRECT_HTTP_AUTHORIZATION'] as $key) {
        if (!empty($_SERVER[$key])) {
            $candidates[] = (string) wp_unslash($_SERVER[$key]);
        }
    }

    if (function_exists('getallheaders')) {
        foreach ((array) getallheaders() as $name => $value) {
            if (strtolower((string) $name) === 'authorization') {
                $candidates[] = (string) $value;
            }
        }
    }

    foreach ($candidates as $auth) {
        $auth = trim($auth);
        if ($auth !== '' && stripos($auth, 'Bearer ') === 0) {
            return trim(substr($auth, 7));
        }
    }

    return '';
}

/**
 * Constant time comparison of a presented token against the stored hash.
 *
 * @param string $token
 * @return bool
 */
function skales_token_matches($token) {
    $stored_hash = get_option('skales_api_token_hash');
    if (!$stored_hash || $token === '') {
        return false;
    }
    return hash_equals($stored_hash, hash('sha256', $token));
}

/**
 * Validate the Bearer token, bind the request to the owner account, then check
 * an optional capability.
 *
 * @param WP_REST_Request $request
 * @param string|null     $capability Capability to require, or null for token only.
 * @return true|WP_Error
 */
function skales_gate($request, $capability = null) {
    $token = skales_bearer_token($request);
    if ($token === '') {
        return new WP_Error('unauthorized', 'Missing or invalid token', ['status' => 401]);
    }

    if (!skales_token_matches($token)) {
        return new WP_Error('unauthorized', 'Invalid token', ['status' => 401]);
    }

    $owner = skales_owner_id();
    if (!$owner) {
        return new WP_Error(
            'no_owner',
            'The Skales Connector is not linked to an administrator account. Open the Skales screen in wp-admin to relink it.',
            ['status' => 500]
        );
    }

    // From here on the request behaves like that user: capability checks are
    // real, and every post, revision, comment and upload gets a real author.
    wp_set_current_user($owner);

    if ($capability && !current_user_can($capability)) {
        return new WP_Error(
            'forbidden',
            sprintf('The linked WordPress account is not allowed to %s.', $capability),
            ['status' => 403]
        );
    }

    return true;
}

/**
 * Permission callback for endpoints that only need a valid token.
 * Kept as a named function because 1.x registered it by name.
 *
 * @param WP_REST_Request $request
 * @return true|WP_Error
 */
function skales_authenticate($request) {
    return skales_gate($request, null);
}

/**
 * Build a permission callback that also requires a capability.
 *
 * @param string $capability
 * @return callable
 */
function skales_cap($capability) {
    return function ($request) use ($capability) {
        return skales_gate($request, $capability);
    };
}

/**
 * The REST route the current request addresses, in either of the two forms
 * WordPress serves: the pretty /wp-json/ path or ?rest_route=.
 *
 * @return string
 */
function skales_current_rest_route() {
    if (isset($GLOBALS['wp']->query_vars['rest_route'])) {
        return (string) $GLOBALS['wp']->query_vars['rest_route'];
    }
    if (isset($_GET['rest_route'])) {
        return sanitize_text_field(wp_unslash($_GET['rest_route']));
    }
    if (!empty($_SERVER['REQUEST_URI'])) {
        // The path only. A query string that happens to mention the namespace
        // is not a request to it.
        $uri = sanitize_text_field(wp_unslash($_SERVER['REQUEST_URI']));
        return (string) strtok($uri, '?');
    }
    return '';
}

/**
 * Is this request addressed to the connector's own namespace?
 *
 * @return bool
 */
function skales_is_own_rest_request() {
    return strpos(skales_current_rest_route(), 'skales/v1') !== false;
}

/**
 * Let a valid token through a site wide "REST API for logged in users only"
 * rule.
 *
 * Security plugins and snippets answer rest_authentication_errors with an
 * error for every visitor without a login cookie. That verdict is reached
 * before any permission_callback runs, so the connector's token never gets
 * looked at and the site owner sees an unexplainable failure with a token that
 * is perfectly good.
 *
 * The error is cleared for one case only: a request to skales/v1 that already
 * carries a token matching the stored hash. Every other route, and every
 * request without that token, keeps whatever verdict the other rule reached.
 * Clearing the error is not an authentication either - the route's own
 * permission_callback still runs skales_gate() afterwards.
 *
 * @param WP_Error|true|null $result
 * @return WP_Error|true|null
 */
add_filter('rest_authentication_errors', 'skales_rest_authentication_errors', 999);
function skales_rest_authentication_errors($result) {
    if (!is_wp_error($result)) {
        return $result;
    }
    if (!skales_is_own_rest_request()) {
        return $result;
    }
    if (!skales_token_matches(skales_bearer_token())) {
        return $result;
    }
    return true;
}

/**
 * Skales builds complete HTML and CSS pages, so post content must survive
 * unchanged. Administrators on a single site already hold unfiltered_html, in
 * which case WordPress keeps the markup and nothing needs to be switched off.
 * Only where the linked account lacks that capability (multisite, or a locked
 * down role) do we lift the kses filters for the duration of one write, and
 * only while the site owner leaves that permission enabled.
 *
 * @return bool True when filters were lifted and must be restored.
 */
function skales_raw_html_begin() {
    if (current_user_can('unfiltered_html')) {
        return false;
    }
    if (!get_option('skales_allow_raw_html', 1)) {
        return false;
    }
    kses_remove_filters();
    return true;
}

/**
 * Restore the kses filters lifted by skales_raw_html_begin().
 *
 * @param bool $was_lifted
 * @return void
 */
function skales_raw_html_end($was_lifted) {
    if ($was_lifted) {
        kses_init_filters();
    }
}
