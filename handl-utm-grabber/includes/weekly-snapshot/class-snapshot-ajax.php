<?php
namespace Handl\UtmrabberFree\WeeklySnapshot;

if ( ! defined( 'ABSPATH' ) ) exit;

use Handl\UtmrabberFree\Onboarding\Handl_Onboarding_Manager;

/** Admin endpoints for the Snapshot settings card: settings, save, test, preview. */
class Handl_Snapshot_Ajax {

	const NONCE_ACTION = 'handl_weekly_snapshot_nonce';

	/** @var Handl_Snapshot_Settings */
	private $settings;

	/** @var Handl_Snapshot_State */
	private $state;

	/** @var Handl_Snapshot_Cron */
	private $cron;

	public function __construct() {
		$this->settings = new Handl_Snapshot_Settings();
		$this->state    = new Handl_Snapshot_State();
		$this->cron     = new Handl_Snapshot_Cron( $this->settings );
	}

	public function register() {
		add_action( 'wp_ajax_handl_weekly_snapshot_settings', array( $this, 'ajax_settings' ) );
		add_action( 'wp_ajax_handl_weekly_snapshot_save', array( $this, 'ajax_save' ) );
		add_action( 'wp_ajax_handl_weekly_snapshot_test', array( $this, 'ajax_test' ) );
		add_action( 'wp_ajax_handl_weekly_snapshot_preview', array( $this, 'ajax_preview' ) );
		add_action( 'wp_ajax_handl_snapshot_notice_optin', array( $this, 'ajax_notice_optin' ) );

		add_filter( 'handl_react_admin_localize', array( $this, 'add_nonce' ) );
	}

	public function add_nonce( $data ) {
		if ( ! isset( $data['nonce'] ) || ! is_array( $data['nonce'] ) ) {
			$data['nonce'] = array();
		}
		$data['nonce']['weekly_snapshot_nonce'] = wp_create_nonce( self::NONCE_ACTION );
		return $data;
	}

	public function ajax_settings() {
		$this->guard();
		wp_send_json_success( $this->settings_envelope() );
	}

	public function ajax_save() {
		$this->guard();

		$changes = array();
		if ( isset( $_POST['enabled'] ) ) {
			$changes['enabled'] = sanitize_text_field( wp_unslash( $_POST['enabled'] ) ) === '1';
		}
		if ( isset( $_POST['recipient'] ) ) {
			$recipient = sanitize_email( wp_unslash( $_POST['recipient'] ) );
			if ( ! is_email( $recipient ) ) {
				wp_send_json_error( array( 'message' => 'Enter a valid email address.' ) );
			}
			$changes['recipient'] = $recipient;
		}
		if ( isset( $_POST['send_day'] ) ) {
			$changes['send_day'] = sanitize_key( wp_unslash( $_POST['send_day'] ) );
		}
		if ( isset( $_POST['send_hour'] ) ) {
			$changes['send_hour'] = (int) $_POST['send_hour'];
		}
		if ( isset( $_POST['site_label'] ) ) {
			$changes['site_label'] = sanitize_text_field( wp_unslash( $_POST['site_label'] ) );
		}

		$old      = $this->settings->get();
		$settings = $this->settings->update( $changes );

		// Every enable is also a news-list
		if ( ! $old['enabled'] && $settings['enabled'] ) {
			Handl_Onboarding_Manager::enroll( $settings['recipient'] );
		}

		if ( ! $settings['enabled'] ) {
			$this->cron->unschedule();
		} else {
			$timing_changed = ! $old['enabled']
				|| $old['send_day'] !== $settings['send_day']
				|| $old['send_hour'] !== $settings['send_hour'];
			// Rescheduling untouched timing would push an overdue-but-unfired event a full week.
			if ( $timing_changed || ! wp_next_scheduled( Handl_Snapshot_Cron::HOOK ) ) {
				$this->cron->schedule();
			}
		}

		wp_send_json_success( $this->settings_envelope() );
	}

	public function ajax_test() {
		$this->guard();
		$result = ( new Handl_Snapshot_Mailer() )->send_test();
		if ( ! $result['sent'] ) {
			wp_send_json_error( array( 'message' => $result['message'] ) );
		}
		wp_send_json_success( array( 'message' => $result['message'] ) );
	}

	public function ajax_preview() {
		$this->guard();
		wp_send_json_success( array( 'html' => ( new Handl_Snapshot_Mailer() )->preview_html() ) );
	}

	/** One-field opt-in for the admin-notice and dashboard-widget forms. */
	public function ajax_notice_optin() {
		$this->guard();

		$email = isset( $_POST['email'] ) ? sanitize_email( wp_unslash( $_POST['email'] ) ) : '';
		if ( ! is_email( $email ) ) {
			wp_send_json_error( array( 'message' => 'Enter a valid email address.' ) );
		}

		$saved = ( new Handl_Snapshot_Activation( $this->settings ) )->activate( $email );
		Handl_Onboarding_Manager::enroll( $email );

		// Land the click in the shared notice state so group caps stay provable.
		$surface = isset( $_POST['surface'] ) ? sanitize_text_field( wp_unslash( $_POST['surface'] ) ) : '';
		if ( $surface !== '' ) {
			do_action( 'handl_notice_event', $surface, 'click' );
		}

		wp_send_json_success( array(
			'message' => 'You are in. Your first Snapshot arrives ' . ucfirst( $saved['send_day'] ) . '.',
		) );
	}

	/** @return array The client envelope: settings + everything the card displays. */
	private function settings_envelope() {
		$settings = $this->settings->get();
		$state    = $this->state->get();

		$next_send = '';
		if ( $settings['enabled'] ) {
			$next_send = wp_date( 'l, M j \a\t g:i a', $this->cron->next_send_timestamp() );
		}

		return array(
			'settings'     => $settings,
			'sending_path' => $this->settings->sending_path(),
			'next_send_at' => $next_send,
			'state'        => array(
				'last_sent_at' => $state['last_sent_at'],
				'last_error'   => $state['last_error'],
				'zero_streak'  => (int) $state['zero_streak'],
				'zero_sends'   => (int) $state['zero_sends'],
			),
		);
	}

	private function guard() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Unauthorized access. Admin privileges required.' ) );
		}
		if ( ! check_ajax_referer( self::NONCE_ACTION, 'nonce', false ) ) {
			wp_send_json_error( array( 'message' => 'Invalid nonce.' ) );
		}
	}
}
