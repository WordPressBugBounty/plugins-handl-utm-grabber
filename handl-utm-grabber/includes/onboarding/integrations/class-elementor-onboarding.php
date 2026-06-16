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

	public function register_test_listener( callable $on_match ) {
		add_action( 'elementor_pro/forms/new_record', function ( $record, $handler ) use ( $on_match ) {
			if ( ! is_object( $record ) || ! method_exists( $record, 'get' ) ) {
				return;
			}

			// Rebuild the {post_id}:{widget_id} composite that get_forms() emits.
			$post_id   = isset( $_POST['post_id'] ) ? (int) $_POST['post_id'] : 0;
			$widget_id = (string) $record->get_form_settings( 'id' );
			if ( $widget_id === '' && isset( $_POST['form_id'] ) ) {
				$widget_id = sanitize_text_field( wp_unslash( $_POST['form_id'] ) );
			}
			if ( $post_id <= 0 || $widget_id === '' ) {
				return;
			}
			$form_id = $post_id . ':' . $widget_id;

			$tracked = handl_lite_tracking_params();
			$raw     = $record->get( 'fields' );
			$posted  = array();
			if ( is_array( $raw ) ) {
				foreach ( $raw as $id => $field ) {
					$key = (string) $id;
					if ( ! in_array( $key, $tracked, true ) ) {
						continue;
					}
					$val = isset( $field['value'] ) ? $field['value'] : '';
					if ( is_array( $val ) ) {
						$val = reset( $val );
					}
					if ( $val !== '' ) {
						$posted[ $key ] = (string) $val;
					}
				}
			}

			$on_match( $form_id, $posted );
		}, 10, 2 );
	}
}
