<?php
namespace Handl\UtmrabberFree\Onboarding;

if ( ! defined( 'ABSPATH' ) ) exit;

class Gravity_Forms_Onboarding extends Handl_Integration_Onboarding {

	public function get_slug() {
		return 'gravity-forms';
	}
	public function find_host_pages( $form_id ) {
		$form_id = (string) (int) $form_id;

		global $wpdb;

		$esc = $wpdb->esc_like( $form_id );
		$block_tag      = '%' . $wpdb->esc_like( 'wp:gravityforms/form' ) . '%';
		$block_quoted   = '%"formId":"' . $esc . '"%';
		$block_bare_int = '%"formId":' . $esc . '%';

		$post_ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT ID FROM {$wpdb->posts}
				WHERE post_status = 'publish'
				AND post_type IN ('page','post')
				AND (
					post_content LIKE %s
					OR post_content LIKE %s
					OR post_content LIKE %s
					OR ( post_content LIKE %s AND ( post_content LIKE %s OR post_content LIKE %s ) )
				)",
				'%[gravityform%id="' . $esc . '"%',
				"%[gravityform%id='" . $esc . "'%",
				'%[gravityform%id=' . $esc . '%',
				$block_tag,
				$block_quoted,
				$block_bare_int
			)
		);

		$urls = array();
		foreach ( $post_ids as $id ) {
			$url = get_permalink( (int) $id );
			if ( $url ) {
				$urls[] = $url;
			}
		}

		return array_values( array_unique( $urls ) );
	}

	public function register_test_listener( callable $on_match ) {
		add_action( 'gform_after_submission', function ( $entry, $form ) use ( $on_match ) {
			$tracked = handl_lite_tracking_params();
			$posted  = array();
			foreach ( $tracked as $param ) {
				foreach ( $form['fields'] as $field ) {
					$input_name = is_object( $field ) ? ( isset( $field->inputName ) ? $field->inputName : '' ) : ( isset( $field['inputName'] ) ? $field['inputName'] : '' );
					$field_id   = is_object( $field ) ? ( isset( $field->id ) ? $field->id : null ) : ( isset( $field['id'] ) ? $field['id'] : null );
					if ( $input_name === $param && $field_id !== null ) {
						$val = \rgar( $entry, (string) $field_id );
						if ( $val !== '' ) {
							$posted[ $param ] = (string) $val;
						}
						break;
					}
				}
			}

			$on_match( (string) $form['id'], $posted );
		}, 10, 2 );
	}
}
