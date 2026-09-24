<?php
namespace Handl\UtmrabberFree\Consent;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * The banner's ACTIVE role: in manager mode it becomes the site's consent
 * manager — declares the `optin` type and pushes decisions via wp_set_consent()
 * (client side) so every consent-aware plugin obeys it.
 *
 * The PASSIVE role (waiting for someone else's consent) and UTM-cookie
 * registration live in lite/wp-consent-api.php, untouched. Here we only
 * register the banner's own cookies (metadata for consent tools' tables).
 */
class Handl_WP_Consent_Api_Bridge {

	/** @var Handl_Consent_Banner */
	private $banner;

	/** @param Handl_Consent_Banner $banner */
	public function __construct( $banner ) {
		$this->banner = $banner;
	}

	public function register() {
		add_filter( 'wp_get_consent_type', array( $this, 'declare_consent_type' ), 20 );
		add_action( 'plugins_loaded', array( $this, 'register_banner_cookies' ), 20 );
	}

	/**
	 * In manager mode the HandL banner is the consent manager, so consent is
	 * opt-in: nothing is allowed until the visitor says so.
	 *
	 * @param string|false $type Consent type declared so far.
	 * @return string|false
	 */
	public function declare_consent_type( $type ) {
		if ( Handl_Consent_Settings::is_manager_mode() && $this->banner->should_render() ) {
			return 'optin';
		}
		return $type;
	}

	/** Cookie-registry metadata for the banner's own cookies. */
	public function register_banner_cookies() {
		if ( ! function_exists( 'wp_add_cookie_info' ) ) {
			return;
		}

		wp_add_cookie_info(
			'handl_consent_status',
			'HandL UTM Grabber',
			'functional',
			'365 days',
			'Stores the visitor\'s cookie consent choices for the HandL consent banner.'
		);
		wp_add_cookie_info(
			'gdprConsent',
			'HandL UTM Grabber',
			'functional',
			'365 days',
			'Legacy flag storing whether the visitor accepted tracking cookies.'
		);
	}
}
