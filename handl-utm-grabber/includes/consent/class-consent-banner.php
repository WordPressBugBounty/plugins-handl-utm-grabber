<?php
namespace Handl\UtmrabberFree\Consent;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Enqueues the frontend banner (js/handl-preferences.js) and its config.
 * Renders whenever enabled — never auto-suppressed (see should_render).
 */
class Handl_Consent_Banner {

	const SCRIPT_HANDLE = 'handl-consent-banner';

	public function register() {
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue' ), 20 );
	}

	public function enqueue() {
		if ( ! $this->should_render() ) {
			return;
		}

		$plugin_file = dirname( __DIR__, 2 ) . '/handl-utm-grabber.php';
		$script_rel  = 'js/handl-preferences.js';

		wp_enqueue_script(
			self::SCRIPT_HANDLE,
			plugins_url( $script_rel, $plugin_file ),
			array(),
			HANDL_UTM_GRABBER_FREE_VERSION,
			true
		);
		wp_localize_script( self::SCRIPT_HANDLE, 'handlConsentBannerCfg', $this->client_config() );
	}

	// Enabled + not in the CT/Oxygen builder. NOT suppressed for other consent
	// plugins — the GDPR tab warns about the double-banner instead.
	public function should_render() {
		$render = Handl_Consent_Settings::is_banner_enabled() && ! defined( 'SHOW_CT_BUILDER' );
		return (bool) apply_filters( 'handl_consent_banner_should_render', $render );
	}

	/** Where the "Powered by UTM Grabber" link points. */
	public static function powered_by_url() {
		return handl_v3_generate_links( 'powered_by', '', 'consent_banner' );
	}

	/** @return array<string, mixed> Payload for wp_localize_script. */
	private function client_config() {
		$s = Handl_Consent_Settings::get_banner_settings();

		$policy_url = $s['policy_url'];
		if ( $policy_url === '' && function_exists( 'get_privacy_policy_url' ) ) {
			$policy_url = get_privacy_policy_url();
		}

		$categories = apply_filters( 'handl_consent_banner_categories', array(
			array(
				'id'          => 'functional',
				'label'       => 'Essential',
				'description' => 'Required for the site to work. Always on.',
				'locked'      => 1,
			),
			array(
				'id'          => 'statistics',
				'label'       => 'Statistics',
				'description' => 'Helps understand how visitors use the site.',
				'locked'      => 0,
			),
			array(
				'id'          => 'marketing',
				'label'       => 'Marketing',
				'description' => 'Marketing attribution, e.g. which campaign brought you here.',
				'locked'      => 0,
			),
		) );

		return array(
			'enabled'        => 1,
			// Bridge to wp_set_consent() only in manager mode with the API present.
			'wp_consent_api' => ( Handl_Consent_Settings::is_manager_mode() && function_exists( 'wp_has_consent' ) ) ? 1 : 0,
			'heading'        => $s['heading'],
			'message'        => $s['message'],
			'accept_label'   => $s['accept_label'],
			'deny_label'     => $s['deny_label'],
			'prefs_label'    => $s['prefs_label'],
			'save_label'     => $s['save_label'],
			'policy_url'     => $policy_url,
			'policy_label'   => $s['policy_label'],
			'position'       => $s['position'],
			'theme'          => $s['theme'],
			'colors'         => (object) $s['colors'],
			'radius'         => $s['radius'],
			'show_deny'      => $s['show_deny'],
			'show_prefs'     => $s['show_prefs'],
			'cookie_days'    => (int) apply_filters( 'handl_consent_banner_cookie_days', 365 ),
			'categories'     => $categories,
			// Free only: small attribution link under the buttons, switchable on the GDPR tab.
			'powered_by'     => $s['show_powered_by'] ? array(
				'label' => 'Powered by',
				'brand' => 'UTM Grabber',
				'url'   => self::powered_by_url(),
			) : 0,
		);
	}
}
