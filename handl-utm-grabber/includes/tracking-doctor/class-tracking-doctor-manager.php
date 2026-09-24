<?php
namespace Handl\UtmrabberFree\TrackingDoctor;

if ( ! defined( 'ABSPATH' ) ) exit;

require_once __DIR__ . '/class-doctor-check.php';
require_once __DIR__ . '/checks/class-forms-check.php';
require_once __DIR__ . '/checks/class-woocommerce-check.php';
require_once __DIR__ . '/checks/class-consent-check.php';
require_once __DIR__ . '/checks/class-caching-check.php';
require_once __DIR__ . '/checks/class-cookies-check.php';
require_once __DIR__ . '/class-tracking-doctor-audit.php';
require_once __DIR__ . '/class-tracking-doctor-ai.php';
require_once __DIR__ . '/class-tracking-doctor-provider.php';
require_once __DIR__ . '/class-tracking-doctor-ajax.php';

/** Tracking Doctor module: one-click audit + AI reviews behind the React settings page. */
class Handl_Tracking_Doctor_Manager {

	/** @var \Handl\UtmrabberFree\Integrations\Handl_Integrations_Manager|null */
	private $integrations_manager;

	/** @param \Handl\UtmrabberFree\Integrations\Handl_Integrations_Manager|null $integrations_manager */
	public function __construct( $integrations_manager = null ) {
		$this->integrations_manager = $integrations_manager;
	}

	public function register() {
		( new Handl_Tracking_Doctor_Ajax( $this->integrations_manager ) )->register();
		add_action( 'handl_consent_settings_saved', array( $this, 'recheck_consent' ) );
	}

	/** Saving GDPR settings changes the consent verdict; merge a fresh check into the saved scan. */
	public function recheck_consent() {
		$provider = new Handl_Tracking_Doctor_Provider( new Handl_Tracking_Doctor_Audit( $this->integrations_manager ) );
		if ( $provider->last_scan() !== null ) {
			$provider->recheck( 'consent' );
		}
	}
}
