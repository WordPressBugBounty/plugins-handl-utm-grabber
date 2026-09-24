<?php
if ( ! defined( 'ABSPATH' ) ) exit;

//Contact Form 7 Support
if ( ! function_exists( 'hug_wpcf7_submit' ) ) {
	function hug_wpcf7_submit( $instance, $result ) {
		if ( $zapier_url = get_option( 'hug_zapier_url' ) ) {
			$submission = WPCF7_Submission::get_instance();
			$data       = $submission->get_posted_data();
			SendDataToZapier( $zapier_url, $data );
		}
	}
	add_action( 'wpcf7_submit', 'hug_wpcf7_submit', 10, 2 );
}

//Ninja Form Support
if ( ! function_exists( 'hug_ninja_forms_after_submission' ) ) {
	function hug_ninja_forms_after_submission( $form_data ) {
		if ( $zapier_url = get_option( 'hug_zapier_url' ) ) {
			$data = array();
			foreach ( $form_data['fields_by_key'] as $field ) {
				if ( isset( $field['key'] ) ) {
					$data[ $field['key'] ] = $field['value'];
				}
			}
			SendDataToZapier( $zapier_url, $data );
		}
	}
	add_action( 'ninja_forms_after_submission', 'hug_ninja_forms_after_submission' );
}

//Gravity Form Support
if ( ! function_exists( 'hug_gform_after_submission' ) ) {
	function hug_gform_after_submission( $entry, $form ) {
		if ( $zapier_url = get_option( 'hug_zapier_url' ) ) {
			$data = array();
			foreach ( $form['fields'] as $field ) {
				$inputs = $field->get_entry_inputs();
				if ( is_array( $inputs ) ) {
					foreach ( $inputs as $input ) {
						$value          = rgar( $entry, (string) $input['id'] );
						$label          = isset( $input['adminLabel'] ) && $input['adminLabel'] != '' ? $input['adminLabel'] : 'input_' . $input['id'];
						$data[ $label ] = $value;
					}
				} else {
					$value          = rgar( $entry, (string) $field->id );
					$label          = isset( $field->adminLabel ) && $field->adminLabel != '' ? $field->adminLabel : 'input_' . $field->id;
					$data[ $label ] = $value;
				}
			}
			SendDataToZapier( $zapier_url, $data );
		}
	}
	add_action( 'gform_after_submission', 'hug_gform_after_submission', 10, 2 );
}

//Formidable Support
if ( ! function_exists( 'hug_frm_process_entry' ) ) {
	function hug_frm_process_entry( $params, $errors, $form, $other ) {
		if ( $zapier_url = get_option( 'hug_zapier_url' ) ) {
			$fields = FrmFieldsHelper::get_form_fields( $form->id, $errors );
			$data   = array();
			foreach ( $fields as $field ) {
				$data[ $field->field_key ] = isset( $_POST['item_meta'][ $field->id ] ) ? $_POST['item_meta'][ $field->id ] : '';
			}
			SendDataToZapier( $zapier_url, $data );
		}
	}
	add_action( 'frm_process_entry', 'hug_frm_process_entry', 10, 4 );
}

//WPForms Support
if ( ! function_exists( 'hug_wpforms_process_complete' ) ) {
	function hug_wpforms_process_complete( $fields, $entry, $form_data, $entry_id ) {
		if ( $zapier_url = get_option( 'hug_zapier_url' ) ) {
			$data = array();
			foreach ( (array) $fields as $field ) {
				if ( isset( $field['name'] ) ) {
					$data[ $field['name'] ] = isset( $field['value'] ) ? $field['value'] : '';
				}
			}
			$data['entry_id'] = $entry_id;
			SendDataToZapier( $zapier_url, $data );
		}
	}
	add_action( 'wpforms_process_complete', 'hug_wpforms_process_complete', 10, 4 );
}

//Fluent Forms Support
if ( ! function_exists( 'hug_fluentform_submission_inserted' ) ) {
	function hug_fluentform_submission_inserted( $insertId, $formData, $form ) {
		if ( $zapier_url = get_option( 'hug_zapier_url' ) ) {
			$data            = is_array( $formData ) ? $formData : array();
			$data['form_id'] = $insertId;
			SendDataToZapier( $zapier_url, $data );
		}
	}
	// Underscore hook fires on every Fluent Forms version; the slash hook only from 5.0.
	add_action( 'fluentform_submission_inserted', 'hug_fluentform_submission_inserted', 10, 3 );
}

if ( ! function_exists( 'SendDataToZapier' ) ) {
	function SendDataToZapier( $zapier_url, $data ) {
		if ( $zapier_url = get_option( 'hug_zapier_url' ) ) {
			$response = Requests::post( $zapier_url, array(), $data );
			add_option( 'hug_zapier_log', $response, '', 'yes' ) or update_option( 'hug_zapier_log', $response );
		}
	}
}
