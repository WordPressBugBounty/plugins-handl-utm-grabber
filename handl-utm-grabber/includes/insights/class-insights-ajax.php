<?php
namespace Handl\UtmrabberFree\Insights;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * admin-ajax endpoints: handl_lead_insights (list + refs, no AI), handl_lead_insight
 * (one card by ref, dashboard), and handl_analyze_posted (one card from a posted
 * payload, plugin-agnostic - used by the in-context entry surfaces).
 */
class Handl_Insights_Ajax {

	const NONCE_ACTION   = 'handl_insights_nonce';
	const MAX_PER_PLUGIN = 2;

	public function register() {
		add_action( 'wp_ajax_handl_lead_insights', array( $this, 'ajax_list' ) );
		add_action( 'wp_ajax_handl_lead_insight', array( $this, 'ajax_card' ) );
		add_action( 'wp_ajax_handl_analyze_posted', array( $this, 'ajax_analyze_posted' ) );
		add_filter( 'handl_react_admin_localize', array( $this, 'add_nonce' ) );
	}

	public function add_nonce( $data ) {
		if ( ! isset( $data['nonce'] ) || ! is_array( $data['nonce'] ) ) {
			$data['nonce'] = array();
		}
		$data['nonce']['insights_nonce'] = wp_create_nonce( self::NONCE_ACTION );
		return $data;
	}

	/** List recent leads with refs + meta (no analysis). Setup-nudge cards are inlined. */
	public function ajax_list() {
		$this->guard();

		$leads = array();
		foreach ( Handl_Insight_Provider::recent_records( self::MAX_PER_PLUGIN ) as $record ) {
			$slug    = isset( $record['integration'] ) ? (string) $record['integration'] : '';
			$form_id = isset( $record['form_id'] ) ? (string) $record['form_id'] : '';
			$plugin  = self::plugin_label( $slug );

			$see_why = null;
			if ( ! empty( $record['see_why_url'] ) ) {
				$see_why = array(
					'label'    => self::see_why_label( $slug, ! empty( $record['submission_id'] ) ),
					'url'      => $record['see_why_url'],
					'variant'  => 'secondary',
					'external' => false,
				);
			}

			// A form missing core UTM fields shows a setup card with no AI call needed.
			$card = Handl_Insight_Provider::core_fields_missing( $slug, $form_id )
				? self::card_vm( self::setup_card() )
				: null;

			$leads[] = array(
				'ref'     => isset( $record['ref'] ) ? (string) $record['ref'] : '',
				'plugin'  => $plugin,
				'form'    => ! empty( $record['form_title'] ) ? (string) $record['form_title'] : $plugin,
				'ago'     => self::ago( $record ),
				'see_why' => $see_why,
				'card'    => $card,
			);
		}

		wp_send_json_success( array(
			'ai_enabled'     => Handl_AI_Analyzer::is_enabled(),
			'ai_available'   => Handl_AI_Analyzer::is_available(),
			'connectors_url' => admin_url( 'options-connectors.php' ),
			'settings_url'   => admin_url( 'admin.php?page=handl-utm-grabber.php#/handl-options' ),
			'leads'          => $leads,
		) );
	}

	/** Analyze a single lead (by ref) and return its card. At most one AI call. */
	public function ajax_card() {
		$this->guard();

		$ref  = isset( $_POST['ref'] ) ? sanitize_text_field( wp_unslash( $_POST['ref'] ) ) : '';
		$card = $ref !== '' ? Handl_Insight_Provider::card_for_ref( $ref ) : null;

		wp_send_json_success( array( 'card' => $card ? self::card_vm( $card ) : null ) );
	}

	/** Plugin-agnostic: analyze a posted payload (allowlisted + sanitized) into one card. */
	public function ajax_analyze_posted() {
		$this->guard();

		$raw     = isset( $_POST['posted'] ) ? wp_unslash( $_POST['posted'] ) : '';
		$decoded = is_string( $raw ) ? json_decode( $raw, true ) : ( is_array( $raw ) ? $raw : array() );
		$decoded = is_array( $decoded ) ? $decoded : array();

		$posted = array();
		foreach ( handl_lite_tracking_params() as $key ) {
			if ( isset( $decoded[ $key ] ) ) {
				$posted[ $key ] = sanitize_text_field( (string) $decoded[ $key ] );
			}
		}

		$card = Handl_Insight_Provider::card_for_posted( $posted );
		wp_send_json_success( array( 'card' => $card ? self::card_vm( $card ) : null ) );
	}

	private function guard() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Unauthorized access. Admin privileges required.' ) );
		}
		if ( ! check_ajax_referer( self::NONCE_ACTION, 'nonce', false ) ) {
			wp_send_json_error( array( 'message' => 'Invalid nonce.' ) );
		}
	}

	/** Normalize an engine/AI/setup card into the client's card view model. */
	private static function card_vm( $card ) {
		$vm = array(
			'severity' => isset( $card['severity'] ) ? $card['severity'] : 'info',
			'message'  => isset( $card['message'] ) ? $card['message'] : '',
			'action'   => isset( $card['action'] ) ? $card['action'] : '',
			'cta'      => null,
		);

		if ( ! empty( $card['cta_url'] ) && ! empty( $card['cta_label'] ) ) {
			$type      = isset( $card['cta_type'] ) ? $card['cta_type'] : 'doc';
			$vm['cta'] = array(
				'label'    => $card['cta_label'],
				'url'      => $card['cta_url'],
				'variant'  => ( 'upgrade' === $type ) ? 'upgrade' : 'primary',
				'external' => ( 'setup' !== $type ),
			);
		}

		return $vm;
	}

	private static function plugin_label( $slug ) {
		$labels = array(
			'gravity-forms'  => 'Gravity Forms',
			'ninja-forms'    => 'Ninja Forms',
			'contact-form-7' => 'Contact Form 7',
			'elementor'      => 'Elementor',
		);
		return isset( $labels[ $slug ] ) ? $labels[ $slug ] : ucwords( str_replace( '-', ' ', (string) $slug ) );
	}

	private static function see_why_label( $slug, $has_submission = false ) {
		if ( 'gravity-forms' === $slug ) {
			return 'View entry';
		}
		if ( 'ninja-forms' === $slug ) {
			return 'View submissions';
		}
		if ( 'contact-form-7' === $slug ) {
			return 'View form';
		}
		// Deep-links to the submission when its id was captured; list page otherwise.
		if ( 'elementor' === $slug ) {
			return $has_submission ? 'View submission' : 'View submissions';
		}
		return 'View';
	}

	private static function setup_card() {
		return array(
			'severity'  => 'warning',
			'message'   => "This form isn't capturing all core UTM fields.",
			'action'    => 'Finish setup so every lead carries full source and campaign data.',
			'cta_type'  => 'setup',
			'cta_label' => 'Set up tracking',
			'cta_url'   => admin_url( 'admin.php?page=handl-onboarding#/connect' ),
		);
	}

	private static function ago( array $lead ) {
		if ( empty( $lead['created_at'] ) ) {
			return '';
		}
		$ts = strtotime( $lead['created_at'] );
		if ( ! $ts ) {
			return '';
		}
		return sprintf( '%s ago', human_time_diff( $ts, time() ) );
	}
}
