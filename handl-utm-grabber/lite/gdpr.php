<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Server-side gate for the HandL consent banner.
 *
 * While the banner is enabled (`show_gdpr_notice`), nothing is captured until
 * the visitor's decision lands in the legacy `gdprConsent` cookie (1 = allow).
 * The banner itself lives in includes/consent/ and writes that cookie.
 *
 * Named handl_gdpr_consented rather than premium's gdpr_consented: premium
 * declares its version unwrapped, so sharing the name would fatal when both
 * plugins are in-process.
 */

if ( ! function_exists( 'handl_gdpr_consented' ) ) {
	function handl_gdpr_consented( $good2go ) {
		if ( get_option( 'show_gdpr_notice' ) == '1' && $good2go['good2go'] === 1 ) {
			if ( isset( $_COOKIE['gdprConsent'] ) ) {
				$good2go['good2go'] = (int) $_COOKIE['gdprConsent'];
			} else {
				$good2go['good2go'] = 0;
			}
		}
		return $good2go;
	}
	add_filter( 'is_ok_to_capture_utms', 'handl_gdpr_consented', 10, 1 );
}
