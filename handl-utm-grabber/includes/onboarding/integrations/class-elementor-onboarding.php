<?php
namespace Handl\UtmrabberFree\Onboarding;

if ( ! defined( 'ABSPATH' ) ) exit;

class Elementor_Onboarding extends Handl_Integration_Onboarding {

	public function get_slug() {
		return 'elementor';
	}

	public function find_host_pages( $form_id ) {
		$parts   = explode( ':', (string) $form_id, 2 );
		$post_id = isset( $parts[0] ) ? (int) $parts[0] : 0;
		if ( $post_id <= 0 ) {
			return array();
		}

		$post = get_post( $post_id );
		if ( ! $post || $post->post_status !== 'publish' ) {
			return array();
		}

		// Skip elementor_library/template post types: no reliable public front-end URL.
		if ( ! in_array( $post->post_type, array( 'post', 'page' ), true ) ) {
			return array();
		}

		$url = get_permalink( $post_id );
		return $url ? array( $url ) : array();
	}
}
