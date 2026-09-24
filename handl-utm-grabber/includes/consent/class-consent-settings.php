<?php
namespace Handl\UtmrabberFree\Consent;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Option access + sanitization for the HandL consent banner.
 *
 * Two switches, three options:
 *  - `show_gdpr_notice`          legacy on/off for the banner (unchanged semantics).
 *  - `handl_consent_manager_mode` "act as a consent tool": banner decisions are
 *                                 broadcast through the WP Consent API.
 *  - `handl_consent_banner`      appearance/content settings array.
 *
 * `show_powered_by` (default on) controls the "Powered by UTM Grabber" link.
 */
class Handl_Consent_Settings {

	const BANNER_OPTION       = 'handl_consent_banner';
	const MANAGER_MODE_OPTION = 'handl_consent_manager_mode';
	const ENABLED_OPTION      = 'show_gdpr_notice';

	const POSITIONS = array( 'bar', 'corner-left', 'corner-right', 'modal' );
	const THEMES    = array( 'light', 'dark' );
	const COLOR_KEYS = array( 'bg', 'text', 'border', 'btn_bg', 'btn_text', 'accent_bg', 'accent_text' );

	/** @return array<string, mixed> */
	public static function defaults() {
		return array(
			'heading'         => 'We value your privacy',
			'message'         => 'We use cookies to understand how visitors find and use this site, and to improve your experience. You can change your choice at any time.',
			'accept_label'    => 'Accept',
			'deny_label'      => 'Deny',
			'prefs_label'     => 'Preferences',
			'save_label'      => 'Save my choices',
			'policy_url'      => '',
			'policy_label'    => 'Privacy Policy',
			'position'        => 'bar',
			'theme'           => 'light',
			'colors'          => array(),
			'radius'          => 8,
			'show_deny'       => 1,
			'show_prefs'      => 1,
			'show_powered_by' => 1,
		);
	}

	/** Resolved banner settings (saved over defaults). @return array<string, mixed> */
	public static function get_banner_settings() {
		$saved    = get_option( self::BANNER_OPTION );
		$settings = wp_parse_args( is_array( $saved ) ? $saved : array(), self::defaults() );
		return apply_filters( 'handl_consent_banner_settings', $settings );
	}

	/** The legacy "Enable GDPR" switch — same '1'/'' semantics it has always had. */
	public static function is_banner_enabled() {
		return get_option( self::ENABLED_OPTION ) == '1';
	}

	/** "Act as a consent tool": broadcast banner decisions via the WP Consent API. */
	public static function is_manager_mode() {
		return get_option( self::MANAGER_MODE_OPTION ) == '1';
	}

	public static function save_banner_enabled( $enabled ) {
		update_option( self::ENABLED_OPTION, $enabled ? '1' : '' );
	}

	public static function save_manager_mode( $enabled ) {
		update_option( self::MANAGER_MODE_OPTION, $enabled ? '1' : '' );
	}

	/**
	 * Sanitize a raw banner-settings payload (from the GDPR tab) and persist it.
	 *
	 * @param array $input Untrusted array.
	 */
	public static function save_banner_settings( $input ) {
		update_option( self::BANNER_OPTION, self::sanitize( $input ) );
	}

	/** @param array $input @return array<string, mixed> */
	public static function sanitize( $input ) {
		$defaults = self::defaults();
		$clean    = array();

		foreach ( array( 'heading', 'accept_label', 'deny_label', 'prefs_label', 'save_label', 'policy_label' ) as $key ) {
			$clean[ $key ] = isset( $input[ $key ] ) ? sanitize_text_field( $input[ $key ] ) : $defaults[ $key ];
		}

		$clean['message']    = isset( $input['message'] ) ? sanitize_textarea_field( $input['message'] ) : $defaults['message'];
		$clean['policy_url'] = isset( $input['policy_url'] ) ? esc_url_raw( $input['policy_url'] ) : '';

		$clean['position'] = in_array( $input['position'] ?? '', self::POSITIONS, true ) ? $input['position'] : $defaults['position'];
		$clean['theme']    = in_array( $input['theme'] ?? '', self::THEMES, true ) ? $input['theme'] : $defaults['theme'];

		$clean['radius']     = isset( $input['radius'] ) ? min( 32, absint( $input['radius'] ) ) : $defaults['radius'];
		$clean['show_deny']  = ! empty( $input['show_deny'] ) ? 1 : 0;
		$clean['show_prefs'] = ! empty( $input['show_prefs'] ) ? 1 : 0;

		// Missing key (older payload) keeps the default rather than hiding the link.
		$clean['show_powered_by'] = isset( $input['show_powered_by'] )
			? ( ! empty( $input['show_powered_by'] ) ? 1 : 0 )
			: $defaults['show_powered_by'];

		$clean['colors'] = array();
		if ( isset( $input['colors'] ) && is_array( $input['colors'] ) ) {
			foreach ( self::COLOR_KEYS as $key ) {
				if ( ! empty( $input['colors'][ $key ] ) && preg_match( '/^#([0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/', $input['colors'][ $key ] ) ) {
					$clean['colors'][ $key ] = strtolower( $input['colors'][ $key ] );
				}
			}
		}

		return $clean;
	}
}
