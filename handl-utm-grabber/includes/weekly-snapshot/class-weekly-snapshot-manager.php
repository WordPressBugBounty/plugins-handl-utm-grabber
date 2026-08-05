<?php
namespace Handl\UtmrabberFree\WeeklySnapshot;

if ( ! defined( 'ABSPATH' ) ) exit;

require_once __DIR__ . '/class-snapshot-settings.php';
require_once __DIR__ . '/class-snapshot-state.php';
require_once __DIR__ . '/class-snapshot-activation.php';
require_once __DIR__ . '/class-snapshot-rollup.php';
require_once __DIR__ . '/class-snapshot-doctor.php';
require_once __DIR__ . '/class-snapshot-collector.php';
require_once __DIR__ . '/class-snapshot-composer.php';
require_once __DIR__ . '/class-snapshot-renderer.php';
require_once __DIR__ . '/class-snapshot-mailer.php';
require_once __DIR__ . '/class-snapshot-cron.php';
require_once __DIR__ . '/class-snapshot-ajax.php';
require_once __DIR__ . '/class-snapshot-optin-form.php';
require_once __DIR__ . '/class-snapshot-dashboard-widget.php';
require_once __DIR__ . '/class-snapshot-notices.php';

/**
 * Weekly Snapshot: an opt-in weekly email digest of the site's own
 * attribution data. Must boot unconditionally — the rollup listens to
 * front-end submissions and the send runs in WP-Cron, where is_admin()
 * is false.
 */
class Handl_Weekly_Snapshot_Manager {

	/** @var Handl_Snapshot_Rollup */
	private $rollup;

	public function __construct() {
		$this->rollup = new Handl_Snapshot_Rollup();
	}

	public function register() {
		add_action( 'handl_utm_submission', array( $this->rollup, 'record' ) );
		add_action( Handl_Snapshot_Cron::HOOK, array( $this, 'handle_scheduled_send' ) );

		if ( is_admin() ) {
			// admin-ajax runs with is_admin() true, so the nopriv email endpoints register here too.
			( new Handl_Snapshot_Ajax() )->register();
			( new Handl_Snapshot_Dashboard_Widget() )->register();
			if ( class_exists( '\Handl\UtmrabberFree\Notices\Handl_Notice_Manager' ) ) {
				( new Handl_Snapshot_Notices() )->register();
			}
			add_action( 'admin_init', array( $this, 'ensure_scheduled' ) );
		}
	}

	/** Cron callback: send, then re-arm next week's event while still enabled. */
	public function handle_scheduled_send() {
		( new Handl_Snapshot_Mailer() )->send_weekly();

		$settings = ( new Handl_Snapshot_Settings() )->get();
		if ( $settings['enabled'] ) {
			( new Handl_Snapshot_Cron() )->schedule();
		}
	}

	/** Self-heal a lost schedule whenever an admin is around. */
	public function ensure_scheduled() {
		( new Handl_Snapshot_Cron() )->ensure_scheduled();
	}
}
