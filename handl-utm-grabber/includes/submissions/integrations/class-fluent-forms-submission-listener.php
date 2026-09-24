<?php
namespace Handl\UtmrabberFree\Submissions;

if ( ! defined( 'ABSPATH' ) ) exit;

require_once dirname( dirname( __DIR__ ) ) . '/integrations/class-integration.php';
require_once dirname( dirname( __DIR__ ) ) . '/integrations/fluent-forms/class-fluent-forms-integration.php';

use Handl\UtmrabberFree\Integrations\Fluent_Forms_Integration;

class Fluent_Forms_Submission_Listener extends Handl_Submission_Listener {

	public function get_slug() {
		return 'fluent-forms';
	}

	public function boot() {
		// Underscore hook fires on every Fluent Forms version; the slash hook only from 5.0.
		add_action( 'fluentform_submission_inserted', function ( $insert_id, $form_data, $form ) {
			$posted = self::extract_from_response( $form, $form_data );

			$meta = array(
				'submission_id' => ! empty( $insert_id ) ? (string) $insert_id : null,
				'form_title'    => isset( $form->title ) ? (string) $form->title : '',
			);

			$this->emit( isset( $form->id ) ? (string) $form->id : '', $posted, $meta );
		}, 10, 3 );
	}

	/**
	 * Tracked param => value ('' = mapped but blank); the response is keyed by field name.
	 *
	 * @param object $form     Fluent Forms form row (`form_fields` JSON).
	 * @param array  $response Submitted values keyed by field name.
	 * @return array<string,string>
	 */
	public static function extract_from_response( $form, $response ) {
		$posted = array();
		$data   = is_object( $form ) && isset( $form->form_fields ) ? json_decode( (string) $form->form_fields, true ) : null;
		if ( ! is_array( $data ) || ! is_array( $response ) ) {
			return $posted;
		}

		foreach ( Fluent_Forms_Integration::fields_of( $data ) as $field ) {
			$param = Fluent_Forms_Integration::param_for_field( $field );
			if ( $param === null || isset( $posted[ $param ] ) ) {
				continue;
			}
			$name             = isset( $field['attributes']['name'] ) ? (string) $field['attributes']['name'] : '';
			$posted[ $param ] = isset( $response[ $name ] ) && is_scalar( $response[ $name ] ) ? (string) $response[ $name ] : '';
		}

		return $posted;
	}
}
