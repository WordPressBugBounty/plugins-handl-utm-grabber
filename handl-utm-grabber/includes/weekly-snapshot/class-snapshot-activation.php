<?php
namespace Handl\UtmrabberFree\WeeklySnapshot;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * The shared enable action: settings and scheduling always change together,
 * so a surface that only knows an email address (the notice and widget
 * opt-ins) can never enable without arming the cron.
 *
 * No remote registration here. Enrolling the address with
 * UTM Grabber's API stays the onboarding wizard signup's job.
 */
class Handl_Snapshot_Activation {

	/** @var Handl_Snapshot_Settings */
	private $settings;

	/** @var Handl_Snapshot_Cron */
	private $cron;

	/** @param Handl_Snapshot_Settings|null $settings */
	public function __construct( $settings = null ) {
		$this->settings = $settings !== null ? $settings : new Handl_Snapshot_Settings();
		$this->cron     = new Handl_Snapshot_Cron( $this->settings );
	}

	/**
	 * Enable the Snapshot for $email: settings, schedule.
	 *
	 * @param string $email Recipient; falls back to admin_email when invalid.
	 * @param array  $extra Optional additional settings changes (send_day, …).
	 * @return array The saved settings.
	 */
	public function activate( $email, array $extra = array() ) {
		$settings = $this->settings->update( array_merge( $extra, array(
			'enabled'   => true,
			'recipient' => $email,
		) ) );

		if ( ! wp_next_scheduled( Handl_Snapshot_Cron::HOOK ) ) {
			$this->cron->schedule();
		}

		return $settings;
	}

	/** Disable + unschedule. */
	public function deactivate() {
		$settings = $this->settings->update( array( 'enabled' => false ) );
		$this->cron->unschedule();
		return $settings;
	}
}
