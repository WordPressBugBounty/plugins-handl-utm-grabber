<?php
namespace Handl\UtmrabberFree\TrackingDoctor;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * admin-ajax endpoints: handl_tracking_doctor_scan (full audit),
 * handl_tracking_doctor_last_scan (saved scan), handl_tracking_doctor_recheck
 * (one check), and handl_tracking_doctor_review (AI review of one check).
 */
class Handl_Tracking_Doctor_Ajax {

	const NONCE_ACTION = 'handl_tracking_doctor_nonce';

	/** @var Handl_Tracking_Doctor_Provider */
	private $provider;

	/** @param \Handl\UtmrabberFree\Integrations\Handl_Integrations_Manager|null $integrations_manager */
	public function __construct( $integrations_manager = null ) {
		$this->provider = new Handl_Tracking_Doctor_Provider( new Handl_Tracking_Doctor_Audit( $integrations_manager ) );
	}

	public function register() {
		add_action( 'wp_ajax_handl_tracking_doctor_scan', array( $this, 'ajax_scan' ) );
		add_action( 'wp_ajax_handl_tracking_doctor_last_scan', array( $this, 'ajax_last_scan' ) );
		add_action( 'wp_ajax_handl_tracking_doctor_recheck', array( $this, 'ajax_recheck' ) );
		add_action( 'wp_ajax_handl_tracking_doctor_review', array( $this, 'ajax_review' ) );
		add_filter( 'handl_react_admin_localize', array( $this, 'add_nonce' ) );
	}

	public function add_nonce( $data ) {
		if ( ! isset( $data['nonce'] ) || ! is_array( $data['nonce'] ) ) {
			$data['nonce'] = array();
		}
		$data['nonce']['tracking_doctor_nonce'] = wp_create_nonce( self::NONCE_ACTION );
		return $data;
	}

	public function ajax_scan() {
		$this->guard();

		wp_send_json_success( $this->provider->run_scan() );
	}

	public function ajax_last_scan() {
		$this->guard();

		wp_send_json_success( array( 'scan' => $this->provider->last_scan() ) );
	}

	public function ajax_recheck() {
		$this->guard();

		$check_id = isset( $_POST['check_id'] ) ? sanitize_key( wp_unslash( $_POST['check_id'] ) ) : '';
		if ( $check_id === '' ) {
			wp_send_json_error( array( 'message' => 'Check id is required.' ) );
		}

		$scan = $this->provider->recheck( $check_id );
		if ( is_wp_error( $scan ) ) {
			wp_send_json_error( array( 'message' => $scan->get_error_message() ) );
		}

		wp_send_json_success( $scan );
	}

	public function ajax_review() {
		$this->guard();

		$category = isset( $_POST['category'] ) ? sanitize_key( wp_unslash( $_POST['category'] ) ) : '';
		if ( $category === '' ) {
			wp_send_json_error( array( 'message' => 'Category is required.' ) );
		}

		$result = $this->provider->review( $category );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		wp_send_json_success( $result );
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
