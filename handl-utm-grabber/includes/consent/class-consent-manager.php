<?php
namespace Handl\UtmrabberFree\Consent;

if ( ! defined( 'ABSPATH' ) ) exit;

use Handl\UtmrabberFree\Notices\Handl_Notice_Manager;

require_once __DIR__ . '/class-consent-settings.php';
require_once __DIR__ . '/class-consent-banner.php';
require_once __DIR__ . '/class-wp-consent-api-bridge.php';
require_once __DIR__ . '/class-consent-ajax.php';
require_once __DIR__ . '/class-cookie-declaration.php';

/**
 * Orchestrates the HandL consent banner feature
 *
 *  - Handl_Consent_Banner            frontend banner, original standalone behavior
 *  - Handl_WP_Consent_Api_Bridge     "act as a consent tool" (manager mode)
 *  - Handl_Consent_Ajax              GDPR tab endpoints
 *
 * The passive WP Consent API role (waiting for someone else's consent) and the
 * server-side gate remain in lite/wp-consent-api.php and lite/gdpr.php.
 */
class Handl_Consent_Manager {

	const WP_CONSENT_API_PLUGIN = 'wp-consent-api/wp-consent-api.php';

	/** @var Handl_Consent_Banner */
	private $banner;

	public function __construct() {
		$this->banner = new Handl_Consent_Banner();
	}

	public function register_frontend_hooks() {
		$this->banner->register();
		( new Handl_WP_Consent_Api_Bridge( $this->banner ) )->register();
		add_shortcode( Handl_Cookie_Declaration::SHORTCODE, array( Handl_Cookie_Declaration::class, 'render_shortcode' ) );
	}

	public function register_admin_hooks() {
		( new Handl_Consent_Ajax( $this ) )->register();
		add_action( 'admin_init', array( $this, 'register_privacy_policy_guide' ) );
		$this->register_launch_notice();
	}

	/**
	 * One-time nudge, shown only to sites with neither privacy surface set up,
	 * so anyone who sees it has something to act on.
	 */
	private function register_launch_notice() {
		if ( ! class_exists( '\Handl\UtmrabberFree\Notices\Handl_Notice_Manager' ) ) {
			return;
		}

		Handl_Notice_Manager::get_instance()->register( 'privacy_tools_launch', array(
			'title'   => 'Privacy tools are now built in',
			'message' => 'Publish your cookie list to your privacy policy for US visitors, or run a consent banner for EU visitors. Both take a click on the GDPR tab.',
			'actions' => array(
				array(
					'label'   => 'Set this up',
					'url'     => admin_url( 'admin.php?page=handl-utm-grabber.php#/gdpr' ),
					'primary' => true,
				),
			),
			'show_when' => function () {
				return ! Handl_Consent_Settings::is_banner_enabled() && ! Handl_Cookie_Declaration::is_on_privacy_page();
			},
		) );
	}

	/** Suggested privacy-policy text under Settings > Privacy > Policy Guide. */
	public function register_privacy_policy_guide() {
		if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) {
			return;
		}

		$content  = '<p>' . esc_html( 'This website uses HandL UTM Grabber to remember how visitors arrive, for example which ad, campaign or referring site brought them here. It stores that information in cookies so it can be attached to form submissions for marketing attribution. The cookies this plugin sets are listed below.' ) . '</p>';
		$content .= '<p>[' . Handl_Cookie_Declaration::SHORTCODE . ']</p>';

		wp_add_privacy_policy_content( 'HandL UTM Grabber', wp_kses_post( $content ) );
	}

	/** Detected banner plugins as key => label. @return array<string, string> */
	public function get_premium_integration_candidates() {
		$this->load_consent_check();
		if ( ! class_exists( '\Handl\UtmrabberFree\TrackingDoctor\Consent_Check' ) ) {
			return array();
		}
		return \Handl\UtmrabberFree\TrackingDoctor\Consent_Check::present_banner_plugins();
	}

	/**
	 * Every banner plugin with a direct premium integration, detected ones
	 * first, as key => array{name: string, detected: bool}.
	 *
	 * @return array<string, array{name: string, detected: bool}>
	 */
	public function get_premium_integrations() {
		$this->load_consent_check();
		if ( ! class_exists( '\Handl\UtmrabberFree\TrackingDoctor\Consent_Check' ) ) {
			return array();
		}
		$present = \Handl\UtmrabberFree\TrackingDoctor\Consent_Check::present_banner_plugins();
		$rows    = array();
		foreach ( \Handl\UtmrabberFree\TrackingDoctor\Consent_Check::supported_banner_plugins() as $key => $label ) {
			$rows[ $key ] = array( 'name' => $label, 'detected' => isset( $present[ $key ] ) );
		}
		uasort( $rows, function ( $a, $b ) {
			return (int) $b['detected'] - (int) $a['detected'];
		} );
		return $rows;
	}

	/** The detection table lives in Tracking Doctor, which admin-ajax may not have booted. */
	private function load_consent_check() {
		$doctor = dirname( __DIR__ ) . '/tracking-doctor/';
		if ( ! class_exists( '\Handl\UtmrabberFree\TrackingDoctor\Consent_Check' ) && file_exists( $doctor . 'checks/class-consent-check.php' ) ) {
			require_once $doctor . 'class-doctor-check.php';
			require_once $doctor . 'checks/class-consent-check.php';
		}
	}

	/** Active banner-rendering consent plugins, for the double-banner warning. @return string[] */
	public function get_active_banner_plugin_names() {
		if ( ! function_exists( 'is_plugin_active' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		// Reuse the integration filter; drop the headless WP Consent API (never a banner).
		$plugins = apply_filters( 'handl_gdpr_add_plugin_support', array() );
		$plugins = array_filter( (array) $plugins, function ( $plugin ) {
			return $plugin !== self::WP_CONSENT_API_PLUGIN;
		} );

		$names = array();
		foreach ( array_unique( $plugins ) as $file ) {
			$data    = get_plugin_data( WP_PLUGIN_DIR . '/' . $file, false, false );
			$names[] = ! empty( $data['Name'] ) ? $data['Name'] : $file;
		}

		// Free registers no direct consent-plugin integrations, so lean on the
		// banner plugins Tracking Doctor knows how to detect (basename, constant or class).
		$names = array_merge( $names, array_values( $this->get_premium_integration_candidates() ) );

		return array_values( array_unique( $names ) );
	}
}
