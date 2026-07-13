<?php
namespace Handl\UtmrabberFree\Submissions;

if ( ! defined( 'ABSPATH' ) ) exit;

class Gravity_Forms_Submission_Listener extends Handl_Submission_Listener {

	public function get_slug() {
		return 'gravity-forms';
	}

	public function boot() {
		add_action( 'gform_after_submission', function ( $entry, $form ) {
			$posted = self::extract_from_entry( $form, $entry );

			$meta = array(
				'submission_id' => isset( $entry['id'] ) ? (string) $entry['id'] : null,
				'form_title'    => isset( $form['title'] ) ? (string) $form['title'] : '',
			);

			$this->emit( (string) $form['id'], $posted, $meta );
		}, 10, 2 );
	}

	/** Mapped tracked param => value from a GF entry; '' when the mapped field is blank. */
	public static function extract_from_entry( $form, $entry ) {
		$posted = array();
		if ( empty( $form['fields'] ) || ! is_array( $form['fields'] ) ) {
			return $posted;
		}
		foreach ( handl_lite_tracking_params() as $param ) {
			foreach ( $form['fields'] as $field ) {
				$input_name = is_object( $field ) ? ( isset( $field->inputName ) ? $field->inputName : '' ) : ( isset( $field['inputName'] ) ? $field['inputName'] : '' );
				$field_id   = is_object( $field ) ? ( isset( $field->id ) ? $field->id : null ) : ( isset( $field['id'] ) ? $field['id'] : null );
				if ( $input_name === $param && $field_id !== null ) {
					$posted[ $param ] = (string) \rgar( $entry, (string) $field_id );
					break;
				}
			}
		}
		return $posted;
	}
}
