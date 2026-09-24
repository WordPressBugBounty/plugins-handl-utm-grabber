<?php
namespace Handl\UtmrabberFree\TrackingDoctor;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Consent-plugin detection.
 */
class Consent_Check extends Handl_Doctor_Check {

	/** `plugins` = candidate basenames; `class`/`constant` = markers premium itself detects by. */
	const CONSENT_PLUGINS = array(
		'cookiebot'          => array( 'label' => 'Cookiebot', 'plugins' => array( 'cookiebot/cookiebot.php' ) ),
		'complianz'          => array( 'label' => 'Complianz', 'plugins' => array( 'complianz-gdpr/complianz-gpdr.php', 'complianz-gdpr-premium/complianz-gpdr-premium.php' ) ),
		'cookieyes'          => array( 'label' => 'CookieYes / Cookie Law Info', 'plugins' => array( 'cookie-law-info/cookie-law-info.php' ) ),
		'cookie_notice'      => array( 'label' => 'Cookie Notice', 'plugins' => array( 'cookie-notice/cookie-notice.php' ) ),
		'cookie_information' => array( 'label' => 'Cookie Information', 'plugins' => array( 'cookie-information-consent-solution/cookie-information.php' ) ),
		'onetrust'           => array( 'label' => 'CookiePro / OneTrust', 'plugins' => array( 'cookiepro/class-cookiepro.php' ) ),
		'moove_gdpr'         => array( 'label' => 'GDPR Cookie Compliance (Moove)', 'plugins' => array( 'gdpr-cookie-compliance/moove-gdpr.php' ) ),
		'real_cookie_banner' => array( 'label' => 'Real Cookie Banner', 'plugins' => array( 'real-cookie-banner/index.php', 'real-cookie-banner-pro/index.php' ) ),
		'borlabs'            => array( 'label' => 'Borlabs Cookie', 'plugins' => array( 'borlabs-cookie/borlabs-cookie.php' ), 'constant' => 'BORLABS_COOKIE_VERSION' ),
		'ultimate_gdpr'      => array( 'label' => 'CT Ultimate GDPR & CCPA', 'plugins' => array( 'ct-ultimate-gdpr/ct-ultimate-gdpr.php' ), 'class' => 'CT_Ultimate_GDPR' ),
		'wpconsent'          => array( 'label' => 'WPConsent', 'plugins' => array( 'wpconsent-cookies-banner-privacy-suite/wpconsent.php' ), 'constant' => 'WPCONSENT_VERSION' ),
		'civic_cookie'       => array( 'label' => 'Civic Cookie Control', 'plugins' => array( 'cookie-control/cookie-control.php' ) ),
		'wp_consent_api'     => array( 'label' => 'WP Consent API', 'plugins' => array( 'wp-consent-api/wp-consent-api.php' ) ),
	);

	public function get_id() {
		return 'consent';
	}

	public function get_label() {
		return 'Cookie consent / GDPR';
	}

	public function scope_note() {
		return 'ONLY review cookie-consent plugin compatibility with HandL. The free plugin ships its own consent banner and can wait for marketing consent through the WP Consent API integration (both under Settings > GDPR); direct integrations with individual consent plugins are a premium HandL feature. When a consent plugin is detected, explain the risk: attribution may be lost or captured before consent. If the consent plugin supports the WP Consent API, recommend enabling HandL\'s free WP Consent API integration; otherwise recommend the premium upgrade. Do NOT invent free-plugin settings to change. Do NOT mention form hidden fields, form plugins, or WooCommerce order meta.';
	}

	public function run() {
		$this->ensure_plugin_functions();

		if ( class_exists( '\Handl\UtmrabberFree\Consent\Handl_Consent_Settings' )
			&& \Handl\UtmrabberFree\Consent\Handl_Consent_Settings::is_banner_enabled() ) {
			// Same double-banner situation the GDPR tab warns about: safe, but visitors see two prompts.
			$other_banners = self::present_banner_plugin_labels();
			if ( ! empty( $other_banners ) ) {
				return $this->build_check(
					'warn',
					sprintf(
						'Two consent banners are active. %s shows its own consent banner and the HandL banner is enabled too, so visitors will see both. Either disable the HandL banner and wait for consent through WP Consent API, or handle consent with HandL alone.',
						implode( ', ', $other_banners )
					),
					array( 'handl_banner_enabled' => true, 'other_banners' => $other_banners ),
					array(
						'label' => 'Review consent settings',
						'url'   => admin_url( 'admin.php?page=handl-utm-grabber.php#/gdpr' ),
						'type'  => 'link',
					)
				);
			}

			return $this->build_check(
				'pass',
				'The HandL consent banner is enabled. Attribution cookies are only written after visitors accept.',
				array( 'handl_banner_enabled' => true )
			);
		}

		$detected = array();
		foreach ( self::CONSENT_PLUGINS as $key => $meta ) {
			if ( $this->is_consent_plugin_present( $meta ) ) {
				$detected[] = array(
					'key'   => $key,
					'label' => $meta['label'],
				);
			}
		}

		$wp_consent_api_active  = $this->is_consent_plugin_present( self::CONSENT_PLUGINS['wp_consent_api'] );
		$wp_consent_api_enabled = function_exists( 'getHandLGDPRPluginStatus' )
			&& getHandLGDPRPluginStatus( 'wp-consent-api/wp-consent-api.php' );
		$consent_type_supplied  = function_exists( 'wp_get_consent_type' ) && wp_get_consent_type();

		if ( $wp_consent_api_active && $wp_consent_api_enabled && $consent_type_supplied ) {
			return $this->build_check(
				'pass',
				'WP Consent API protection is enabled. HandL waits for marketing consent before capturing attribution.',
				array( 'wp_consent_api_enabled' => true )
			);
		}

		if ( $wp_consent_api_active && $wp_consent_api_enabled ) {
			return $this->build_check(
				'warn',
				'WP Consent API integration is enabled, but no consent manager is supplying a consent type. HandL is currently tracking normally.',
				array( 'wp_consent_api_enabled' => true )
			);
		}

		if ( $wp_consent_api_active ) {
			return $this->build_check(
				'warn',
				'WP Consent API is active but HandL is not using it yet. Enable the free integration so HandL waits for marketing consent.',
				array( 'detected' => $detected ),
				array(
					'label' => 'Enable WP Consent API',
					'url'   => admin_url( 'admin.php?page=handl-utm-grabber.php#/gdpr' ),
					'type'  => 'link',
				)
			);
		}

		if ( empty( $detected ) ) {
			return $this->build_check(
				'pass',
				'No major cookie-consent plugin detected. HandL first-party cookies should persist normally.'
			);
		}

		$labels = implode( ', ', wp_list_pluck( $detected, 'label' ) );

		foreach ( $detected as $plugin ) {
			if ( 'wpconsent' === $plugin['key'] ) {
				return $this->build_check(
					'warn',
					'WPConsent detected. Install and activate WP Consent API, then enable HandL\'s free integration so tracking waits for marketing consent.',
					array( 'detected' => $detected ),
					array(
						'label' => 'Set up consent integration',
						'url'   => admin_url( 'admin.php?page=handl-utm-grabber.php#/gdpr' ),
						'type'  => 'link',
					)
				);
			}
		}

		return $this->build_check(
			'warn',
			sprintf(
				'%s detected. A consent banner can withhold HandL\'s attribution cookies until visitors accept.',
				$labels
			),
			array( 'detected' => $detected ),
			array(
				'label' => 'Unlock consent integration',
				'url'   => handl_v3_generate_links( 'tracking_doctor_consent', '', 'tracking_doctor' ),
				'type'  => 'premium',
			)
		);
	}

	/**
	 * Labels of the banner-rendering consent plugins present on the site
	 * (WP Consent API is headless and never listed). Shared with the consent
	 * module's double-banner warning.
	 *
	 * @return string[]
	 */
	public static function present_banner_plugin_labels() {
		if ( ! function_exists( 'is_plugin_active' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		return array_values( self::present_banner_plugins() );
	}

	/** Every banner plugin in the table as key => label (WP Consent API is headless). @return array<string, string> */
	public static function supported_banner_plugins() {
		$all = array();
		foreach ( self::CONSENT_PLUGINS as $key => $meta ) {
			if ( $key !== 'wp_consent_api' ) {
				$all[ $key ] = $meta['label'];
			}
		}
		return $all;
	}

	/** Detected banner plugins as key => label. @return array<string, string> */
	public static function present_banner_plugins() {
		if ( ! function_exists( 'is_plugin_active' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$found = array();
		foreach ( self::CONSENT_PLUGINS as $key => $meta ) {
			if ( $key !== 'wp_consent_api' && self::is_present( $meta ) ) {
				$found[ $key ] = $meta['label'];
			}
		}
		return $found;
	}

	/** @param array{plugins:string[],class?:string,constant?:string} $meta */
	private function is_consent_plugin_present( $meta ) {
		return self::is_present( $meta );
	}

	/** @param array{plugins:string[],class?:string,constant?:string} $meta */
	private static function is_present( $meta ) {
		foreach ( $meta['plugins'] as $basename ) {
			if ( is_plugin_active( $basename ) ) {
				return true;
			}
		}
		if ( isset( $meta['constant'] ) && defined( $meta['constant'] ) ) {
			return true;
		}
		if ( isset( $meta['class'] ) && class_exists( $meta['class'] ) ) {
			return true;
		}
		return false;
	}
}
