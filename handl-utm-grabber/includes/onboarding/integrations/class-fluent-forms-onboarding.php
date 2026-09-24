<?php
namespace Handl\UtmrabberFree\Onboarding;

if ( ! defined( 'ABSPATH' ) ) exit;

class Fluent_Forms_Onboarding extends Handl_Integration_Onboarding {

	public function get_slug() {
		return 'fluent-forms';
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
				'%' . $wpdb->esc_like( '[fluentform' ) . '%',
				'%' . $wpdb->esc_like( 'fluentfom/guten-block' ) . '%'
			)
		);

		$id_quoted    = preg_quote( $form_id, '/' );
		$shortcode_re = '/\[fluentform\b[^\]]*\bid=["\']?' . $id_quoted . '["\']?(?=[\s\/\]])/';
		$block_re     = '/fluentfom\/guten-block[^}]*"formId":"?' . $id_quoted . '"?(?=[,}])/';

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
