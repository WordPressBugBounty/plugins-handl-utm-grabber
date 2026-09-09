<?php

namespace Handl\UtmrabberFree\MCP;

if (!defined('ABSPATH')) {
    exit;
}

use WP_REST_Request;
use WP_Error;

class Signature_Verifier
{
    const OPTION_KEY = 'handl_mcp_credentials_free';
    const TIMESTAMP_TOLERANCE = 300;
    const NONCE_TTL = 300;

    /**
     * Verify a signed MCP request. Intended for use as a WP REST permission_callback.
     *
     * @param WP_REST_Request $request
     * @return true|WP_Error
     */
    public static function verify(WP_REST_Request $request)
    {
        $site_id   = $request->get_header('X-UTMG-Site-ID');
        $timestamp = $request->get_header('X-UTMG-Timestamp');
        $nonce     = $request->get_header('X-UTMG-Nonce');
        $signature = $request->get_header('X-UTMG-Signature');

        if (empty($site_id) || empty($timestamp) || empty($nonce) || empty($signature)) {
            return new WP_Error(
                'missing_headers',
                'Missing required authentication headers.',
                array('status' => 401)
            );
        }

        $credentials = get_option(self::OPTION_KEY);

        if (empty($credentials) || !is_array($credentials) || empty($credentials['site_secret'])) {
            return new WP_Error(
                'mcp_not_configured',
                'MCP credentials are not configured on this site.',
                array('status' => 403)
            );
        }

        if (!hash_equals($credentials['site_id'], $site_id)) {
            return new WP_Error(
                'site_id_mismatch',
                'Site ID does not match.',
                array('status' => 403)
            );
        }

        if (abs(time() - intval($timestamp)) > self::TIMESTAMP_TOLERANCE) {
            return new WP_Error(
                'request_expired',
                'Request timestamp is too old or too far in the future.',
                array('status' => 401)
            );
        }

        $nonce_key = 'utmg_nonce_' . hash('sha256', $nonce);
        if (get_transient($nonce_key)) {
            return new WP_Error(
                'nonce_reused',
                'Request nonce has already been used.',
                array('status' => 401)
            );
        }
        set_transient($nonce_key, true, self::NONCE_TTL);

        $method    = strtoupper($request->get_method());
        $path      = $request->get_route();
        $body      = $request->get_body();
        $body_hash = hash('sha256', $body ?: '');

        //! Do not change this
        $signing_string = implode("\n", [
            $method,
            $path,
            $site_id,
            $timestamp,
            $nonce,
            $body_hash,
        ]);

        $expected = hash_hmac('sha256', $signing_string, $credentials['site_secret']);

        if (!hash_equals($expected, $signature)) {
            return new WP_Error(
                'invalid_signature',
                'Request signature verification failed.',
                array('status' => 401)
            );
        }

        return true;
    }
}
