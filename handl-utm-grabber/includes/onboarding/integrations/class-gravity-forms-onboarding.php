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
}
