<?php
namespace Handl\UtmrabberFree\Onboarding;

if ( ! defined( 'ABSPATH' ) ) exit;

class Formidable_Onboarding extends Handl_Integration_Onboarding {

	public function get_slug() {
		return 'formidable';
	}

	public function find_host_pages( $form_id ) {
		$form_id = (string) (int) $form_id;
		if ( $form_id === '0' ) {
			return array();
		}

		global $wpdb;

		// Broad prefilter
		$candidates = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT ID FROM {$wpdb->posts}
				WHERE post_status = 'publish'
				AND post_type IN ('page','post')
				AND ( post_content LIKE %s OR post_content LIKE %s )",
				'%' . $wpdb->esc_like( '[formidable' ) . '%',
				'%' . $wpdb->esc_like( 'formidable/simple-form' ) . '%'
			)
		);

		$id_quoted    = preg_quote( $form_id, '/' );
		$shortcode_re = '/\[formidable\b[^\]]*\bid=["\']?' . $id_quoted . '["\']?(?=[\s\/\]])/';
		$block_re     = '/formidable\/simple-form[^}]*"formId":"?' . $id_quoted . '"?(?=[,}])/';

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
}
