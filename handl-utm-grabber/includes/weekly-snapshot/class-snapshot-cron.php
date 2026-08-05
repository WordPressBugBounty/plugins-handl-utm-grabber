<?php
namespace Handl\UtmrabberFree\WeeklySnapshot;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Scheduling: one single event armed at the next send-day/hour in the site
 * timezone, re-armed after every run (WP fires overdue single events on the
 * next page load, so a missed Friday still goes out late rather than never).
 */
class Handl_Snapshot_Cron {

	const HOOK = 'handl_weekly_snapshot_send';

	/** @var Handl_Snapshot_Settings */
	private $settings;

	/** @param Handl_Snapshot_Settings|null $settings */
	public function __construct( $settings = null ) {
		$this->settings = $settings !== null ? $settings : new Handl_Snapshot_Settings();
	}

	/** @return int UTC timestamp of the next send-day at send-hour, site timezone, strictly future. */
	public function next_send_timestamp() {
		$settings = $this->settings->get();
		$now      = new \DateTimeImmutable( 'now', wp_timezone() );

		$candidate = $now->modify( 'this ' . $settings['send_day'] )->setTime( $settings['send_hour'], 0, 0 );
		if ( $candidate <= $now ) {
			$candidate = $candidate->modify( '+7 days' );
		}
		return $candidate->getTimestamp();
	}

	public function schedule() {
		$this->unschedule();
		wp_schedule_single_event( $this->next_send_timestamp(), self::HOOK );
	}

	public function unschedule() {
		wp_clear_scheduled_hook( self::HOOK );
	}

	/** Self-heal: single events can be lost (cron resets, migrations); re-arm quietly. */
	public function ensure_scheduled() {
		$settings = $this->settings->get();
		if ( $settings['enabled'] && ! wp_next_scheduled( self::HOOK ) ) {
			$this->schedule();
		}
	}
}
