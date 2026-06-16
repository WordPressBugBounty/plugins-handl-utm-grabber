<?php
namespace Handl\UtmrabberFree\Onboarding;

if ( ! defined( 'ABSPATH' ) ) exit;

class Ninja_Forms_Onboarding extends Handl_Integration_Onboarding {

	public function get_slug() {
		return 'ninja-forms';
	}

	public function find_host_pages( $form_id ) {
		$form_id = (string) (int) $form_id;
		if ( $form_id === '0' ) {
			return array();
		}

		global $wpdb;

		// Broad prefilter, then precise matching in PHP: NF shortcodes are often
		// unquoted (`[ninja_form id=1]`), so a plain LIKE on the id would match
		// `id=12` when looking for `id=1`.
		$candidates = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT ID FROM {$wpdb->posts}
				WHERE post_status = 'publish'
				AND post_type IN ('page','post')
				AND ( post_content LIKE %s OR post_content LIKE %s )",
				'%' . $wpdb->esc_like( '[ninja_form' ) . '%',
				'%' . $wpdb->esc_like( 'ninja-forms/form' ) . '%'
			)
		);

		$id_quoted     = preg_quote( $form_id, '/' );
		$shortcode_re  = '/\[ninja_form\b[^\]]*\bid=["\']?' . $id_quoted . '["\']?(?=[\s\/\]])/';
		$block_re      = '/ninja-forms\/form[^}]*"formID":"?' . $id_quoted . '"?(?=[,}])/';

		$urls = array();
		foreach ( $candidates as $id ) {
			$content = (string) get_post_field( 'post_content', (int) $id );
			if ( preg_match( $shortcode_re, $content ) || preg_match( $block_re, $content ) ) {
				$url = get_permalink( (int) $id );
				if ( $url ) {
					$urls[] = $url;
				}
			}
		}

		return array_values( array_unique( $urls ) );
	}

	public function register_test_listener( callable $on_match ) {
		add_action( 'ninja_forms_after_submission', function ( $form_data ) use ( $on_match ) {
			$form_id = '';
			if ( isset( $form_data['form_id'] ) ) {
				$form_id = (string) $form_data['form_id'];
			} elseif ( isset( $form_data['id'] ) ) {
				$form_id = (string) $form_data['id'];
			}

			$tracked = handl_lite_tracking_params();
			$posted  = array();
			if ( ! empty( $form_data['fields'] ) && is_array( $form_data['fields'] ) ) {
				foreach ( $form_data['fields'] as $field ) {
					$key = isset( $field['key'] ) ? (string) $field['key'] : '';
					if ( $key === '' || ! in_array( $key, $tracked, true ) ) {
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
		}, 10, 1 );
	}
}
