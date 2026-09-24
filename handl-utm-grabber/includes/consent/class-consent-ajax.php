<?php
namespace Handl\UtmrabberFree\Consent;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * admin-ajax endpoints for the GDPR tab React app:
 * handl_consent_state (read) and handl_consent_save (write).
 */
class Handl_Consent_Ajax {

	const NONCE_ACTION = 'handl_consent_nonce';

	/** @var Handl_Consent_Manager */
	private $manager;

	/** @param Handl_Consent_Manager $manager */
	public function __construct( $manager ) {
		$this->manager = $manager;
	}

	public function register() {
		add_action( 'wp_ajax_handl_consent_state', array( $this, 'ajax_state' ) );
		add_action( 'wp_ajax_handl_consent_save', array( $this, 'ajax_save' ) );
		add_action( 'wp_ajax_handl_consent_declaration_insert', array( $this, 'ajax_declaration_insert' ) );
		add_filter( 'handl_react_admin_localize', array( $this, 'add_nonce' ) );
	}

	public function add_nonce( $data ) {
		if ( ! isset( $data['nonce'] ) || ! is_array( $data['nonce'] ) ) {
			$data['nonce'] = array();
		}
		$data['nonce']['consent_nonce'] = wp_create_nonce( self::NONCE_ACTION );
		return $data;
	}

	/** Everything the GDPR tab needs in one payload. */
	public function ajax_state() {
		$this->guard();

		$integrations = array();
		foreach ( getAllGDPRPlugins() as $file => $data ) {
			$integrations[] = array(
				'file'    => $file,
				'name'    => ! empty( $data['Name'] ) ? $data['Name'] : $file,
				'waiting' => getHandLGDPRPluginStatus( $file ),
			);
		}

		wp_send_json_success( array(
			'banner'                => array_merge(
				array( 'enabled' => Handl_Consent_Settings::is_banner_enabled() ),
				Handl_Consent_Settings::get_banner_settings()
			),
			'manager_mode'          => Handl_Consent_Settings::is_manager_mode(),
			'wp_consent_api_active' => function_exists( 'wp_has_consent' ),
			'privacy_policy_fallback' => function_exists( 'get_privacy_policy_url' ) ? get_privacy_policy_url() : '',
			'active_banner_plugins' => $this->manager->get_active_banner_plugin_names(),
			'powered_by_url'        => Handl_Consent_Banner::powered_by_url(),
			'integrations'          => $integrations,
			'premium_integrations'  => $this->premium_integrations(),
			'cookie_declaration'    => $this->declaration_state(),
		) );
	}

	/** Consent plugins with a direct premium integration, detected ones first. @return array<int, array{key: string, name: string, detected: bool}> */
	private function premium_integrations() {
		$rows = array();
		foreach ( $this->manager->get_premium_integrations() as $key => $row ) {
			$rows[] = array( 'key' => $key, 'name' => $row['name'], 'detected' => $row['detected'] );
		}
		return $rows;
	}

	/**
	 * Cookie Declaration card state, read from the privacy page's current
	 * content on every request. Nothing here is stored.
	 *
	 * @return array<string, mixed>
	 */
	private function declaration_state() {
		$page = Handl_Cookie_Declaration::privacy_page();

		return array(
			'cookies'          => Handl_Cookie_Declaration::get_cookies(),
			'shortcode'        => '[' . Handl_Cookie_Declaration::SHORTCODE . ']',
			'privacy_page_id'  => $page ? $page->ID : 0,
			'privacy_page_title'  => $page ? $page->post_title : '',
			'privacy_page_status' => $page ? $page->post_status : '',
			'privacy_page_edit_url' => $page ? get_edit_post_link( $page->ID, 'raw' ) : '',
			'already_inserted' => $page ? has_shortcode( $page->post_content, Handl_Cookie_Declaration::SHORTCODE ) : false,
			'builder_managed'  => $page ? (bool) get_post_meta( $page->ID, '_elementor_data', true ) : false,
		);
	}

	/**
	 * Appends a Cookies section to the privacy page, or creates one as a draft
	 * if the site has none. Idempotent, and never publishes anything.
	 */
	public function ajax_declaration_insert() {
		$this->guard();

		$page = Handl_Cookie_Declaration::privacy_page();

		if ( ! $page ) {
			$this->create_draft_privacy_page();
			return;
		}

		if ( ! current_user_can( 'edit_post', $page->ID ) ) {
			wp_send_json_error( array( 'message' => 'You do not have permission to edit the privacy policy page.' ) );
		}

		if ( has_shortcode( $page->post_content, Handl_Cookie_Declaration::SHORTCODE ) ) {
			wp_send_json_success( array( 'message' => 'Your cookie list is already on the privacy policy page.' ) );
		}

		if ( get_post_meta( $page->ID, '_elementor_data', true ) ) {
			wp_send_json_error( array(
				'message' => 'This page is built with a page builder, so the shortcode cannot be added automatically. Copy the shortcode below and paste it into the page in your builder instead.',
			) );
		}

		$result = wp_update_post( array(
			'ID'           => $page->ID,
			'post_content' => $page->post_content . "\n\n" . $this->declaration_section( has_blocks( $page->post_content ) ),
		), true );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => 'The privacy policy page could not be updated: ' . $result->get_error_message() ) );
		}

		wp_send_json_success( array( 'message' => 'Your cookie list was added to the privacy policy page.' ) );
	}

	/** Heading + shortcode, as block markup or classic HTML to match the page. */
	private function declaration_section( $as_blocks ) {
		$shortcode = '[' . Handl_Cookie_Declaration::SHORTCODE . ']';

		if ( $as_blocks ) {
			return "<!-- wp:heading -->\n<h2 class=\"wp-block-heading\">Cookies</h2>\n<!-- /wp:heading -->\n\n"
				. "<!-- wp:shortcode -->\n" . $shortcode . "\n<!-- /wp:shortcode -->";
		}

		return "<h2>Cookies</h2>\n\n" . $shortcode;
	}

	private function create_draft_privacy_page() {
		if ( ! current_user_can( 'edit_pages' ) ) {
			wp_send_json_error( array( 'message' => 'You do not have permission to create pages.' ) );
		}

		$default_content = '';
		if ( ! class_exists( 'WP_Privacy_Policy_Content' ) ) {
			$class_file = ABSPATH . 'wp-admin/includes/class-wp-privacy-policy-content.php';
			if ( file_exists( $class_file ) ) {
				require_once $class_file;
			}
		}
		if ( class_exists( 'WP_Privacy_Policy_Content' ) ) {
			$default_content = \WP_Privacy_Policy_Content::get_default_content( false, true );
		}

		$page_id = wp_insert_post( array(
			'post_title'   => 'Privacy Policy',
			'post_type'    => 'page',
			'post_status'  => 'draft',
			'post_content' => $default_content . "\n\n" . $this->declaration_section( true ),
		), true );

		if ( is_wp_error( $page_id ) ) {
			wp_send_json_error( array( 'message' => 'The draft privacy policy page could not be created: ' . $page_id->get_error_message() ) );
		}

		update_option( 'wp_page_for_privacy_policy', $page_id );

		wp_send_json_success( array(
			'message' => 'A draft privacy policy page was created with your cookie list. Review and publish it when ready.',
		) );
	}

	public function ajax_save() {
		$this->guard();

		$raw = isset( $_POST['settings'] ) ? json_decode( wp_unslash( $_POST['settings'] ), true ) : null;
		if ( ! is_array( $raw ) ) {
			wp_send_json_error( array( 'message' => 'Invalid settings payload.' ) );
		}

		if ( array_key_exists( 'enabled', $raw ) ) {
			Handl_Consent_Settings::save_banner_enabled( ! empty( $raw['enabled'] ) );
		}
		if ( array_key_exists( 'manager_mode', $raw ) ) {
			Handl_Consent_Settings::save_manager_mode( ! empty( $raw['manager_mode'] ) );
		}
		if ( isset( $raw['banner'] ) && is_array( $raw['banner'] ) ) {
			Handl_Consent_Settings::save_banner_settings( $raw['banner'] );
		}
		if ( isset( $raw['integrations'] ) && is_array( $raw['integrations'] ) ) {
			$this->save_integrations( $raw['integrations'] );
		}

		// Lets Tracking Doctor refresh its saved consent check instead of showing a stale warning.
		do_action( 'handl_consent_settings_saved' );

		wp_send_json_success( array(
			'message' => 'GDPR settings saved.',
		) );
	}

	/**
	 * Per-plugin "wait for consent" toggles. Only currently-known (active)
	 * plugins are accepted; the stored format ([file => '1']) is untouched for
	 * backwards compatibility with getHandLGDPRPluginStatus().
	 *
	 * @param array<string, mixed> $input file => waiting
	 */
	private function save_integrations( $input ) {
		$known = array_keys( getAllGDPRPlugins() );
		$saved = getHandLGDPRPlugins();

		foreach ( $input as $file => $waiting ) {
			if ( ! in_array( $file, $known, true ) ) {
				continue;
			}
			if ( ! empty( $waiting ) ) {
				$saved[ $file ] = '1';
			} else {
				unset( $saved[ $file ] );
			}
		}

		update_option( 'handl_gdpr_plugins', $saved );
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
