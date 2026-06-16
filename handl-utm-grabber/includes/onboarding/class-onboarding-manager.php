<?php
namespace Handl\UtmrabberFree\Onboarding;

if ( ! defined( 'ABSPATH' ) ) exit;

use Handl\UtmrabberFree\Integrations\Handl_Integrations_Manager;

require_once __DIR__ . '/class-integration-onboarding.php';
require_once __DIR__ . '/integrations/class-gravity-forms-onboarding.php';
require_once __DIR__ . '/integrations/class-contact-form-7-onboarding.php';
require_once __DIR__ . '/integrations/class-ninja-forms-onboarding.php';
require_once __DIR__ . '/integrations/class-elementor-onboarding.php';

class Handl_Onboarding_Manager {

	const NONCE_ACTION     = 'handl_onboarding_nonce';
	const ACTIVE_TRANSIENT = 'handl_onboarding_active';
	const COMPLETED_OPTION = 'handl_onboarding_completed';
	const TOKEN_PREFIX     = 'first-test-';
	const SIGNUP_ENDPOINT  = 'https://api.utmgrabber.com/http/users/silent-register';
	const METADATA_ENDPOINT = 'https://api.utmgrabber.com/http/users/plugin-free/metadata';
	const SIGNUP_SOURCE    = 'plugin_free_onboarding';

	/** @var Handl_Integrations_Manager|null */
	private $integrations_manager;

	/** @var array<string, Handl_Integration_Onboarding> */
	private $onboarding_integrations = array();

	/**
	 * @param Handl_Integrations_Manager|null $integrations_manager
	 */
	public function __construct( $integrations_manager = null ) {
		$this->integrations_manager = $integrations_manager;
		$this->load_onboarding_integrations();
	}
	public function register_admin_hooks() {
		add_filter( 'handl_react_admin_localize', array( $this, 'add_nonce_to_localize' ) );

		add_action( 'wp_ajax_handl_onboarding_state',       array( $this, 'ajax_state' ) );
		add_action( 'wp_ajax_handl_onboarding_connect',     array( $this, 'ajax_connect' ) );
		add_action( 'wp_ajax_handl_onboarding_test_start',  array( $this, 'ajax_test_start' ) );
		add_action( 'wp_ajax_handl_onboarding_test_status', array( $this, 'ajax_test_status' ) );
		add_action( 'wp_ajax_handl_onboarding_test_cancel', array( $this, 'ajax_test_cancel' ) );
		add_action( 'wp_ajax_handl_onboarding_complete',    array( $this, 'ajax_complete' ) );
		add_action( 'wp_ajax_handl_onboarding_signup',      array( $this, 'ajax_signup' ) );
	}

	private function load_onboarding_integrations() {
		$classes = array(
			new Gravity_Forms_Onboarding(),
			new Contact_Form_7_Onboarding(),
			new Ninja_Forms_Onboarding(),
			new Elementor_Onboarding(),
		);
		foreach ( $classes as $cls ) {
			$this->onboarding_integrations[ $cls->get_slug() ] = $cls;
		}
	}

	public function register_test_listeners() {
		foreach ( $this->onboarding_integrations as $onboarding ) {
			$onboarding->register_test_listener( array( $this, 'maybe_capture' ) );
		}
		add_action( 'wp_footer', array( $this, 'maybe_render_test_banner' ) );
	}

	public function maybe_render_test_banner() {
		if ( is_admin() ) {
			return;
		}
		if ( ! isset( $_GET['handl_test'] ) || $_GET['handl_test'] !== '1' ) {
			return;
		}

		$campaign   = isset( $_GET['utm_campaign'] ) ? sanitize_text_field( wp_unslash( $_GET['utm_campaign'] ) ) : '';
		$back_url   = esc_url( admin_url( 'admin.php?page=handl-onboarding#/test' ) );
		$form_title = $this->resolve_test_form_title( $campaign );
		include __DIR__ . '/views/test-banner.php';
	}

	/** @param string $campaign utm_campaign value. @return string Tested form's title, or $fallback. */
	private function resolve_test_form_title( $campaign, $fallback = '' ) {
		if ( $campaign === '' || strpos( $campaign, self::TOKEN_PREFIX ) !== 0 ) {
			return $fallback;
		}
		$token = substr( $campaign, strlen( self::TOKEN_PREFIX ) );
		$state = $this->get_active();
		foreach ( $state['tests'] as $test ) {
			if ( isset( $test['token'] ) && $test['token'] === $token && ! empty( $test['title'] ) ) {
				return $test['title'];
			}
		}
		return $fallback;
	}

	public function add_nonce_to_localize( $data ) {
		if ( ! isset( $data['nonce'] ) || ! is_array( $data['nonce'] ) ) {
			$data['nonce'] = array();
		}
		$data['nonce']['onboarding_nonce'] = wp_create_nonce( self::NONCE_ACTION );
		return $data;
	}

	// Transient state shape: { selected_forms: { slug => string[] }, tests: { "slug::form_id" => FormTest } }

	/** @return array{selected_forms: array<string, string[]>, tests: array<string, array>} */
	private function get_active() {
		$raw = get_transient( self::ACTIVE_TRANSIENT );
		return array(
			'selected_forms' => ( is_array( $raw ) && isset( $raw['selected_forms'] ) && is_array( $raw['selected_forms'] ) ) ? $raw['selected_forms'] : array(),
			'tests'          => ( is_array( $raw ) && isset( $raw['tests'] ) && is_array( $raw['tests'] ) ) ? $raw['tests'] : array(),
		);
	}

	private function save_active( array $state ) {
		set_transient( self::ACTIVE_TRANSIENT, $state, 30 * MINUTE_IN_SECONDS );
	}

	private static function test_key( $slug, $form_id ) {
		return $slug . '::' . (string) $form_id;
	}

	/** Submission-listener callback: marks any waiting test matching token+form as captured. */
	public function maybe_capture( $form_id, array $posted ) {
		if ( ! isset( $posted['utm_campaign'] ) ) {
			return;
		}
		$campaign = $posted['utm_campaign'];
		if ( strpos( $campaign, self::TOKEN_PREFIX ) !== 0 ) {
			return;
		}

		$state = $this->get_active();
		foreach ( $state['tests'] as $key => $test ) {
			if ( $test['status'] === 'captured' ) {
				continue;
			}
			if ( (string) $test['form_id'] !== (string) $form_id ) {
				continue;
			}
			if ( ( self::TOKEN_PREFIX . $test['token'] ) !== $campaign ) {
				continue;
			}

			$test['status']       = 'captured';
			$test['captured']     = $posted;
			$test['captured_at']  = current_time( 'c' );
			$state['tests'][ $key ] = $test;
			$this->save_active( $state );
			return;
		}
	}

	private function authorize() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Unauthorized access. Admin privileges required.' ) );
			return false;
		}
		if ( ! check_ajax_referer( self::NONCE_ACTION, 'nonce', false ) ) {
			wp_send_json_error( array( 'message' => 'Invalid nonce.' ) );
			return false;
		}
		return true;
	}

	public function ajax_state() {
		if ( ! $this->authorize() ) {
			return;
		}

		$detected     = array();
		$integrations = $this->integrations_manager->get_integrations();

		foreach ( $integrations as $slug => $integration ) {
			if ( ! $integration->is_active() ) {
				continue;
			}
			$onboarding = isset( $this->onboarding_integrations[ $slug ] ) ? $this->onboarding_integrations[ $slug ] : null;
			$forms      = array_values( $integration->get_forms() );

			$forms_with_pages = array();
			foreach ( $forms as $form ) {
				$pages = $onboarding ? $onboarding->find_host_pages( $form['id'] ) : array();
				$forms_with_pages[] = array(
					'id'         => (string) $form['id'],
					'title'      => (string) $form['title'],
					'host_pages' => $pages,
				);
			}

			$detected[] = array(
				'slug'               => $slug,
				'label'              => $integration->get_label(),
				'forms'              => $forms_with_pages,
				'supports_live_test' => $onboarding ? $onboarding->supports_live_test() : false,
			);
		}

		$state = $this->get_active();

		wp_send_json_success( array(
			'detected_integrations' => $detected,
			'selected_forms'        => (object) $state['selected_forms'],
			'tests'                 => (object) $state['tests'],
			'completed'             => (bool) get_option( self::COMPLETED_OPTION, false ),
		) );
	}

	public function ajax_connect() {
		if ( ! $this->authorize() ) {
			return;
		}

		$raw = isset( $_POST['plugins'] ) ? wp_unslash( $_POST['plugins'] ) : '';
		$decoded = is_array( $raw ) ? $raw : json_decode( $raw, true );
		if ( ! is_array( $decoded ) || empty( $decoded ) ) {
			wp_send_json_error( array( 'message' => 'No plugins to connect.' ) );
			return;
		}

		$results        = array();
		$selected_forms = array();

		foreach ( $decoded as $entry ) {
			if ( ! is_array( $entry ) || empty( $entry['slug'] ) || empty( $entry['form_ids'] ) ) {
				continue;
			}
			$slug        = sanitize_key( $entry['slug'] );
			$integration = $this->integrations_manager->get_integration( $slug );
			if ( ! $integration || ! $integration->is_active() ) {
				$results[ $slug ] = array(
					'ok'    => false,
					'error' => 'Integration not active.',
				);
				continue;
			}

			$form_ids = array_values( array_filter( array_map( 'sanitize_text_field', (array) $entry['form_ids'] ), 'strlen' ) );
			if ( empty( $form_ids ) ) {
				continue;
			}

			$fields        = $integration->get_tracked_params();
			$apply_results = $integration->apply( $form_ids, $fields, 'add', array() );

			$results[ $slug ]        = array(
				'ok'      => true,
				'results' => $apply_results,
			);
			$selected_forms[ $slug ] = $form_ids;
		}

		// Persist the picks so a refresh on /test still has context.
		$state                    = $this->get_active();
		$state['selected_forms']  = $selected_forms;
		$this->save_active( $state );

		wp_send_json_success( array( 'results' => $results ) );
	}

	public function ajax_test_start() {
		if ( ! $this->authorize() ) {
			return;
		}

		$slug     = isset( $_POST['slug'] ) ? sanitize_key( wp_unslash( $_POST['slug'] ) ) : '';
		$form_id  = isset( $_POST['form_id'] ) ? sanitize_text_field( wp_unslash( $_POST['form_id'] ) ) : '';
		$host_url = isset( $_POST['host_url'] ) ? esc_url_raw( wp_unslash( $_POST['host_url'] ) ) : '';
		$title    = isset( $_POST['title'] ) ? sanitize_text_field( wp_unslash( $_POST['title'] ) ) : '';

		if ( $slug === '' || $form_id === '' || $host_url === '' ) {
			wp_send_json_error( array( 'message' => 'Missing parameters.' ) );
			return;
		}

		$onboarding = isset( $this->onboarding_integrations[ $slug ] ) ? $this->onboarding_integrations[ $slug ] : null;
		if ( ! $onboarding || ! $onboarding->supports_live_test() ) {
			wp_send_json_error( array( 'message' => 'Live test not supported for this integration yet.' ) );
			return;
		}

		$token = strtolower( wp_generate_password( 6, false, false ) );

		$test_url = add_query_arg( array(
			'utm_source'   => 'utmgrabber',
			'utm_medium'   => 'onboarding',
			'utm_campaign' => self::TOKEN_PREFIX . $token,
			'utm_content'  => 'welcome',
			'utm_term'     => 'lead-attribution',
			'handl_test'   => '1',
		), $host_url );

		$test = array(
			'token'        => $token,
			'integration'  => $slug,
			'form_id'      => (string) $form_id,
			'title'        => $title,
			'host_url'     => $host_url,
			'test_url'     => $test_url,
			'status'       => 'waiting',
			'started_at'   => current_time( 'c' ),
			'captured'     => null,
			'captured_at'  => null,
		);

		$state = $this->get_active();
		$state['tests'][ self::test_key( $slug, $form_id ) ] = $test;
		$this->save_active( $state );

		wp_send_json_success( array( 'test' => $test ) );
	}

	public function ajax_test_status() {
		if ( ! $this->authorize() ) {
			return;
		}
		$slug    = isset( $_POST['slug'] ) ? sanitize_key( wp_unslash( $_POST['slug'] ) ) : '';
		$form_id = isset( $_POST['form_id'] ) ? sanitize_text_field( wp_unslash( $_POST['form_id'] ) ) : '';
		if ( $slug === '' || $form_id === '' ) {
			wp_send_json_error( array( 'message' => 'Missing parameters.' ) );
			return;
		}
		$state = $this->get_active();
		$key   = self::test_key( $slug, $form_id );
		$test  = isset( $state['tests'][ $key ] ) ? $state['tests'][ $key ] : null;
		wp_send_json_success( array( 'test' => $test ) );
	}

	public function ajax_test_cancel() {
		if ( ! $this->authorize() ) {
			return;
		}
		$slug    = isset( $_POST['slug'] ) ? sanitize_key( wp_unslash( $_POST['slug'] ) ) : '';
		$form_id = isset( $_POST['form_id'] ) ? sanitize_text_field( wp_unslash( $_POST['form_id'] ) ) : '';
		if ( $slug === '' || $form_id === '' ) {
			wp_send_json_error( array( 'message' => 'Missing parameters.' ) );
			return;
		}
		$state = $this->get_active();
		$key   = self::test_key( $slug, $form_id );
		if ( isset( $state['tests'][ $key ] ) ) {
			unset( $state['tests'][ $key ] );
			$this->save_active( $state );
		}
		wp_send_json_success( array( 'test' => null ) );
	}

	public function ajax_complete() {
		if ( ! $this->authorize() ) {
			return;
		}
		update_option( self::COMPLETED_OPTION, true, true );
		wp_send_json_success( array( 'completed' => true ) );
	}

	/**
	 * Build the plugin metadata sent to /plugin-free/metadata after register.
	 *
	 * @return array{domain:string,plugin_version:string,form_plugins:string[],setup_completed_for:string[],test_skipped:bool}
	 */
	private function metadata_context() {
		$statuses = $this->integrations_manager ? $this->integrations_manager->get_all_statuses() : array();

		$form_plugins        = array();
		$setup_completed_for = array();
		foreach ( $statuses as $slug => $st ) {
			if ( empty( $st['active'] ) ) {
				continue;
			}
			$form_plugins[] = $slug;
			if ( isset( $st['status'] ) && $st['status'] === 'complete' ) {
				$setup_completed_for[] = $slug;
			}
		}

		// test_skipped is INFERRED: true when no live test ever reached "captured".
		// There is no explicit "skip" event today; revisit if we add real tracking.
		$has_captured = false;
		$state        = $this->get_active();
		foreach ( $state['tests'] as $test ) {
			if ( isset( $test['status'] ) && $test['status'] === 'captured' ) {
				$has_captured = true;
				break;
			}
		}

		$host = wp_parse_url( home_url(), PHP_URL_HOST );

		return array(
			'domain'              => is_string( $host ) ? $host : '',
			'plugin_version'      => defined( 'HANDL_UTM_GRABBER_FREE_VERSION' ) ? HANDL_UTM_GRABBER_FREE_VERSION : '',
			'form_plugins'        => array_values( $form_plugins ),
			'setup_completed_for' => array_values( $setup_completed_for ),
			'test_skipped'        => ! $has_captured,
		);
	}

	/** Proxy newsletter signup to api.utmgrabber.com so the nonce/auth pattern stays consistent. */
	public function ajax_signup() {
		if ( ! $this->authorize() ) {
			return;
		}

		$email      = isset( $_POST['email'] ) ? sanitize_email( wp_unslash( $_POST['email'] ) ) : '';
		$given_name = isset( $_POST['given_name'] ) ? sanitize_text_field( wp_unslash( $_POST['given_name'] ) ) : 'there';

		if ( $email === '' || ! is_email( $email ) ) {
			wp_send_json_error( array(
				'code'    => 'INVALID_BODY',
				'message' => 'A valid email address is required.',
			) );
			return;
		}

		$payload = array(
			'email'         => $email,
			'signup_source' => self::SIGNUP_SOURCE,
		);
		if ( $given_name !== '' ) {
			$payload['given_name'] = $given_name;
		}

		$res = wp_remote_post( self::SIGNUP_ENDPOINT, array(
			'timeout' => 10,
			'headers' => array( 'Content-Type' => 'application/json' ),
			'body'    => wp_json_encode( $payload ),
		) );

		if ( is_wp_error( $res ) ) {
			wp_send_json_error( array(
				'code'    => 'NETWORK_ERROR',
				'message' => $res->get_error_message(),
			) );
			return;
		}

		$status = (int) wp_remote_retrieve_response_code( $res );
		$body   = json_decode( wp_remote_retrieve_body( $res ), true );

		if ( $status >= 200 && $status < 300 && is_array( $body ) && isset( $body['userId'] ) ) {
			// Non block
			$meta = array_merge( array( 'email' => $email ), $this->metadata_context() );
			wp_remote_post( self::METADATA_ENDPOINT, array(
				'timeout'  => 8,
				'blocking' => false,
				'headers'  => array( 'Content-Type' => 'application/json' ),
				'body'     => wp_json_encode( $meta ),
			) );

			wp_send_json_success( array(
				'userId' => (string) $body['userId'],
				'isNew'  => ! empty( $body['isNew'] ),
			) );
			return;
		}

		wp_send_json_error( array(
			'code'    => is_array( $body ) && isset( $body['code'] ) ? (string) $body['code'] : 'UPSTREAM_ERROR',
			'message' => is_array( $body ) && isset( $body['message'] ) ? (string) $body['message'] : 'Signup failed.',
		) );
	}
}
