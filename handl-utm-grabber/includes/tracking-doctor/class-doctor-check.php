<?php
namespace Handl\UtmrabberFree\TrackingDoctor;

if ( ! defined( 'ABSPATH' ) ) exit;

/** Health-check contract: one audit area producing a uniform pass|warn|fail|skip result. */
abstract class Handl_Doctor_Check {

	abstract public function get_id();

	abstract public function get_label();

	/** @return array{id:string,label:string,status:string,message:string,details:array,fix:array|null} */
	abstract public function run();

	/** AI scoping instruction: what this check's review may and may not discuss. */
	abstract public function scope_note();

	/** Site facts sent alongside this check's AI review. */
	public function ai_site_context() {
		return array(
			'domain'     => isset( $_SERVER['SERVER_NAME'] ) ? sanitize_text_field( wp_unslash( $_SERVER['SERVER_NAME'] ) ) : '',
			'wp_version' => get_bloginfo( 'version' ),
			'is_ssl'     => is_ssl(),
		);
	}

	/**
	 * @param string                      $status pass|warn|fail|skip
	 * @param array<string, string>|null  $fix    array{label,url,type: link|docs|premium}
	 */
	protected function build_check( $status, $message, $details = array(), $fix = null ) {
		return array(
			'id'      => $this->get_id(),
			'label'   => $this->get_label(),
			'status'  => $status,
			'message' => $message,
			'details' => $details,
			'fix'     => $fix,
		);
	}

	/** Free-tier tracked params with labels and grouping, for detail panels and AI context. */
	protected function param_catalog() {
		$meta = array(
			'utm_source'         => array( 'label' => 'UTM Source', 'group' => 'utm', 'description' => 'Traffic source (e.g. google, facebook, newsletter)' ),
			'utm_medium'         => array( 'label' => 'UTM Medium', 'group' => 'utm', 'description' => 'Marketing medium (e.g. cpc, email, social)' ),
			'utm_campaign'       => array( 'label' => 'UTM Campaign', 'group' => 'utm', 'description' => 'Campaign name for grouping performance' ),
			'utm_term'           => array( 'label' => 'UTM Term', 'group' => 'utm', 'description' => 'Paid keyword or ad term' ),
			'utm_content'        => array( 'label' => 'UTM Content', 'group' => 'utm', 'description' => 'Ad variation or link identifier' ),
			'gclid'              => array( 'label' => 'GCLID', 'group' => 'click_id', 'description' => 'Google Ads click ID for offline conversion matching' ),
			'handl_landing_page' => array( 'label' => 'Landing Page', 'group' => 'context', 'description' => 'First URL the visitor landed on' ),
			'handl_original_ref' => array( 'label' => 'Original Referrer', 'group' => 'context', 'description' => 'First external referrer in the session' ),
			'handl_ip'           => array( 'label' => 'Visitor IP', 'group' => 'context', 'description' => 'Visitor IP address at capture time' ),
		);

		$out = array();
		foreach ( handl_lite_tracking_params() as $key ) {
			$row   = isset( $meta[ $key ] ) ? $meta[ $key ] : array( 'label' => $key, 'group' => 'other', 'description' => '' );
			$out[] = array_merge( array( 'key' => $key ), $row );
		}
		return $out;
	}

	/** is_plugin_active() lives in an admin include that is not always loaded. */
	protected function ensure_plugin_functions() {
		if ( ! function_exists( 'is_plugin_active' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
	}
}
