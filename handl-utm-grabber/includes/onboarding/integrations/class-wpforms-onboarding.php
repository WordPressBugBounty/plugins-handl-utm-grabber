<?php
namespace Handl\UtmrabberFree\Onboarding;

if ( ! defined( 'ABSPATH' ) ) exit;

class WPForms_Onboarding extends Handl_Integration_Onboarding {

	public function get_slug() {
		return 'wpforms';
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
				'%' . $wpdb->esc_like( '[wpforms' ) . '%',
				'%' . $wpdb->esc_like( 'wpforms/form-selector' ) . '%'
			)
		);

		$id_quoted    = preg_quote( $form_id, '/' );
		$shortcode_re = '/\[wpforms\b[^\]]*\bid=["\']?' . $id_quoted . '["\']?(?=[\s\/\]])/';
		$block_re     = '/wpforms\/form-selector[^}]*"formId":"?' . $id_quoted . '"?(?=[,}])/';

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
