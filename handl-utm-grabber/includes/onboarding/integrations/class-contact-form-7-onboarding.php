<?php
namespace Handl\UtmrabberFree\Onboarding;

if ( ! defined( 'ABSPATH' ) ) exit;

class Contact_Form_7_Onboarding extends Handl_Integration_Onboarding {

	public function get_slug() {
		return 'contact-form-7';
	}

	public function find_host_pages( $form_id ) {
		$form_id = (string) (int) $form_id;

		// CF7 5.8+ can use a SHA-1 hash or numeric id in shortcode.
		$hash = '';
		if ( class_exists( '\WPCF7_ContactForm' ) ) {
			$cf = \WPCF7_ContactForm::get_instance( (int) $form_id );
			if ( $cf && method_exists( $cf, 'hash' ) ) {
				$hash = (string) $cf->hash();
			}
		}

		global $wpdb;

		$tag      = $wpdb->esc_like( '[contact-form-7' );
		$id_num   = $wpdb->esc_like( $form_id );
		$patterns = array(
			'%' . $tag . '%' . 'id="' . $id_num . '"' . '%',
			'%' . $tag . '%' . "id='" . $id_num . "'" . '%',
		);
		if ( $hash !== '' ) {
			$id_hash    = $wpdb->esc_like( $hash );
			$patterns[] = '%' . $tag . '%' . 'id="' . $id_hash . '"' . '%';
			$patterns[] = '%' . $tag . '%' . "id='" . $id_hash . "'" . '%';
		}

		$where_likes = implode( ' OR ', array_fill( 0, count( $patterns ), 'post_content LIKE %s' ) );

		$post_ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT ID FROM {$wpdb->posts}
				WHERE post_status = 'publish'
				AND post_type IN ('page','post')
				AND ( {$where_likes} )",
				$patterns
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
		add_action( 'wpcf7_before_send_mail', function ( $contact_form ) use ( $on_match ) {
			if ( ! class_exists( '\WPCF7_Submission' ) ) {
				return;
			}
			$submission = \WPCF7_Submission::get_instance();
			if ( ! $submission ) {
				return;
			}

			$data   = $submission->get_posted_data();
			$posted = array();
			foreach ( handl_lite_tracking_params() as $param ) {
				$key = $param . '_cf7';
				if ( ! isset( $data[ $key ] ) ) {
					continue;
				}
				$val = is_array( $data[ $key ] ) ? reset( $data[ $key ] ) : $data[ $key ];
				if ( $val !== '' ) {
					$posted[ $param ] = (string) $val;
				}
			}

			$on_match( (string) $contact_form->id(), $posted );
		}, 10, 1 );
	}
}
