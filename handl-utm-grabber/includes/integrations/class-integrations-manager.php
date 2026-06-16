<?php
namespace Handl\UtmrabberFree\Integrations;

if ( ! defined( 'ABSPATH' ) ) exit;

require_once __DIR__ . '/class-integration.php';
require_once __DIR__ . '/gravity-forms/class-gravity-forms-integration.php';
require_once __DIR__ . '/contact-form-7/class-contact-form-7-integration.php';
require_once __DIR__ . '/ninja-forms/class-ninja-forms-integration.php';
require_once __DIR__ . '/elementor/class-elementor-integration.php';

/** Registers integrations, AJAX handlers, and the React admin nonce. */
class Handl_Integrations_Manager {

	const NONCE_ACTION = 'handl_integrations_nonce';

	/** @var array<string, Handl_Integration> */
	private $integrations = array();

	public function __construct() {
		$this->load_integrations();

		add_action( 'wp_ajax_handl_integrations_list',      array( $this, 'ajax_list' ) );
		add_action( 'wp_ajax_handl_integrations_get_forms', array( $this, 'ajax_get_forms' ) );
		add_action( 'wp_ajax_handl_integrations_apply',     array( $this, 'ajax_apply' ) );

		add_filter( 'handl_react_admin_localize', array( $this, 'add_nonce_to_localize' ) );
	}

	private function load_integrations() {
		$this->register( new Gravity_Forms_Integration() );
		$this->register( new Contact_Form_7_Integration() );
		$this->register( new Ninja_Forms_Integration() );
		$this->register( new Elementor_Integration() );
	}

	private function register( Handl_Integration $integration ) {
		$this->integrations[ $integration->get_slug() ] = $integration;
	}

	/** @return array<string, Handl_Integration> */
	public function get_integrations() {
		return $this->integrations;
	}

	/** @return Handl_Integration|null */
	public function get_integration( $slug ) {
		return isset( $this->integrations[ $slug ] ) ? $this->integrations[ $slug ] : null;
	}

	/**
	 * Roll up per-form status for one integration. @param string $slug
	 * @return array{slug:string,active:bool,status:string,total_forms:int,integrated_forms:int,forms:array} status: complete (all forms complete)|partial|none.
	 */
	public function get_integration_status( $slug ) {
		$integration = $this->get_integration( $slug );

		if ( ! $integration || ! $integration->is_active() ) {
			return array(
				'slug'             => $integration ? $integration->get_slug() : (string) $slug,
				'active'           => false,
				'status'           => 'none',
				'total_forms'      => 0,
				'integrated_forms' => 0,
				'forms'            => array(),
			);
		}

		$forms            = array_values( $integration->get_forms() );
		$form_statuses    = array();
		$integrated_forms = 0;
		$complete_forms   = 0;

		foreach ( $forms as $form ) {
			$status          = $integration->get_form_status( $form['id'] );
			$status['title'] = isset( $form['title'] ) ? (string) $form['title'] : '';
			$form_statuses[] = $status;

			if ( $status['status'] === 'complete' ) {
				$complete_forms++;
				$integrated_forms++;
			} elseif ( $status['status'] === 'partial' ) {
				$integrated_forms++;
			}
		}

		$total = count( $form_statuses );
		if ( $integrated_forms === 0 ) {
			$overall = 'none';
		} elseif ( $total > 0 && $complete_forms === $total ) {
			$overall = 'complete';
		} else {
			$overall = 'partial';
		}

		return array(
			'slug'             => $integration->get_slug(),
			'active'           => true,
			'status'           => $overall,
			'total_forms'      => $total,
			'integrated_forms' => $integrated_forms,
			'forms'            => $form_statuses,
		);
	}

	/** @return array<string, array> Status roll-up keyed by integration slug. */
	public function get_all_statuses() {
		$out = array();
		foreach ( $this->integrations as $slug => $integration ) {
			$out[ $slug ] = $this->get_integration_status( $slug );
		}
		return $out;
	}

	/**
	 * @param array $data
	 * @return array
	 */
	public function add_nonce_to_localize( $data ) {
		if ( ! isset( $data['nonce'] ) || ! is_array( $data['nonce'] ) ) {
			$data['nonce'] = array();
		}
		$data['nonce']['integrations_nonce'] = wp_create_nonce( self::NONCE_ACTION );
		return $data;
	}

	/**
	 * Common gate for every AJAX handler.
	 *
	 * @return Handl_Integration|null Integration matching the posted slug, or null
	 *                                if access was denied / slug missing (response
	 *                                already sent).
	 */
	private function authorize_and_resolve() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Unauthorized access. Admin privileges required.' ) );
			return null;
		}

		if ( ! check_ajax_referer( self::NONCE_ACTION, 'nonce', false ) ) {
			wp_send_json_error( array( 'message' => 'Invalid nonce.' ) );
			return null;
		}

		$slug = isset( $_POST['slug'] ) ? sanitize_key( wp_unslash( $_POST['slug'] ) ) : '';
		if ( $slug === '' ) {
			return null;
		}

		if ( ! isset( $this->integrations[ $slug ] ) ) {
			wp_send_json_error( array( 'message' => 'Unknown integration.' ) );
			return null;
		}

		return $this->integrations[ $slug ];
	}

	public function ajax_list() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Unauthorized access. Admin privileges required.' ) );
			return;
		}

		if ( ! check_ajax_referer( self::NONCE_ACTION, 'nonce', false ) ) {
			wp_send_json_error( array( 'message' => 'Invalid nonce.' ) );
			return;
		}

		$out = array();
		foreach ( $this->integrations as $integration ) {
			$out[] = $integration->to_array();
		}

		wp_send_json_success( array( 'integrations' => $out ) );
	}

	public function ajax_get_forms() {
		$integration = $this->authorize_and_resolve();
		if ( ! $integration ) {
			return;
		}

		if ( ! $integration->is_active() ) {
			wp_send_json_error( array( 'message' => 'Integration is not active on this site.' ) );
			return;
		}

		wp_send_json_success( array( 'forms' => array_values( $integration->get_forms() ) ) );
	}

	public function ajax_apply() {
		$integration = $this->authorize_and_resolve();
		if ( ! $integration ) {
			return;
		}

		if ( ! $integration->is_active() ) {
			wp_send_json_error( array( 'message' => 'Integration is not active on this site.' ) );
			return;
		}

		$action = isset( $_POST['action_type'] ) ? sanitize_key( wp_unslash( $_POST['action_type'] ) ) : 'add';
		if ( ! in_array( $action, array( 'add', 'remove' ), true ) ) {
			wp_send_json_error( array( 'message' => 'Invalid action.' ) );
			return;
		}

		$form_ids = $this->decode_array_param( 'form_ids' );
		$fields   = $this->decode_array_param( 'fields' );

		if ( empty( $form_ids ) ) {
			wp_send_json_error( array( 'message' => 'No forms selected.' ) );
			return;
		}

		if ( $action === 'add' ) {
			$allowed = $integration->get_tracked_params();
			$fields  = array_values( array_intersect( $fields, $allowed ) );
			if ( empty( $fields ) ) {
				wp_send_json_error( array( 'message' => 'No valid fields selected.' ) );
				return;
			}
		}

		$options = $this->decode_assoc_param( 'options' );

		$results = $integration->apply( $form_ids, $fields, $action, $options );

		wp_send_json_success( array( 'results' => $results ) );
	}

	/**
	 * Decode a JSON associative array param from React (e.g. CF7's `also_add_to_email`).
	 *
	 * @param string $key
	 * @return array
	 */
	/** Decode a JSON assoc-array POST param (e.g. CF7's `also_add_to_email`). @return array */
	private function decode_assoc_param( $key ) {
		if ( ! isset( $_POST[ $key ] ) ) {
			return array();
		}
		$raw = wp_unslash( $_POST[ $key ] );
		if ( is_array( $raw ) ) {
			return $raw;
		}
		$decoded = json_decode( $raw, true );
		return is_array( $decoded ) ? $decoded : array();
	}

	/**
	 * Decode a JSON array param from React input, handling slashes and sanitizing.
	 * @param string $key
	 * @return array
	 */
	private function decode_array_param( $key ) {
		if ( ! isset( $_POST[ $key ] ) ) {
			return array();
		}

		$raw = wp_unslash( $_POST[ $key ] );

		if ( is_array( $raw ) ) {
			return array_values( array_filter( array_map( 'sanitize_text_field', $raw ), 'strlen' ) );
		}

		$decoded = json_decode( $raw, true );
		if ( ! is_array( $decoded ) ) {
			return array();
		}
		return array_values( array_filter( array_map( 'sanitize_text_field', $decoded ), 'strlen' ) );
	}
}
