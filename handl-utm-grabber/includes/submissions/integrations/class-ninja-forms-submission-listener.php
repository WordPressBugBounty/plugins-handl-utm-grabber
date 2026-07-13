<?php
namespace Handl\UtmrabberFree\Submissions;

if ( ! defined( 'ABSPATH' ) ) exit;

// Side-effect-free knowledge class; owns field<->param identification (same
// lazy-load pattern as Handl_Insight_Provider::integration()).
require_once dirname( dirname( __DIR__ ) ) . '/integrations/class-integration.php';
require_once dirname( dirname( __DIR__ ) ) . '/integrations/ninja-forms/class-ninja-forms-integration.php';

use Handl\UtmrabberFree\Integrations\Ninja_Forms_Integration;

class Ninja_Forms_Submission_Listener extends Handl_Submission_Listener {

	public function get_slug() {
		return 'ninja-forms';
	}

	public function boot() {
		add_action( 'ninja_forms_after_submission', function ( $form_data ) {
			$form_id = '';
			if ( isset( $form_data['form_id'] ) ) {
				$form_id = (string) $form_data['form_id'];
			} elseif ( isset( $form_data['id'] ) ) {
				$form_id = (string) $form_data['id'];
			}

			$posted = array();
			if ( ! empty( $form_data['fields'] ) && is_array( $form_data['fields'] ) ) {
				foreach ( $form_data['fields'] as $field ) {
					// Entries are flattened with all field settings, so `default` is present.
					$param = Ninja_Forms_Integration::param_for_field(
						isset( $field['key'] ) ? $field['key'] : '',
						isset( $field['default'] ) ? $field['default'] : ''
					);
					if ( $param === null || isset( $posted[ $param ] ) ) {
						continue;
					}
					$val = isset( $field['value'] ) ? $field['value'] : '';
					if ( is_array( $val ) ) {
						$val = reset( $val );
					}
					$posted[ $param ] = (string) $val;
				}
			}

			$sub_id = null;
			if ( isset( $form_data['actions']['save']['sub_id'] ) ) {
				$sub_id = (string) $form_data['actions']['save']['sub_id'];
			}
			$meta = array(
				'submission_id' => $sub_id,
				'form_title'    => isset( $form_data['settings']['title'] ) ? (string) $form_data['settings']['title'] : '',
			);

			$this->emit( $form_id, $posted, $meta );
		}, 10, 1 );
	}

	/** Mapped tracked param => value from a stored NF submission; '' when the mapped field is blank. */
	public static function extract_from_sub( $nfSub ) {
		$posted = array();
		if ( ! function_exists( 'Ninja_Forms' ) || ! is_object( $nfSub ) || ! method_exists( $nfSub, 'get_form_id' ) ) {
			return $posted;
		}

		$fields = \Ninja_Forms()->form( $nfSub->get_form_id() )->get_fields();
		if ( ! is_array( $fields ) ) {
			return $posted;
		}

		foreach ( $fields as $field ) {
			if ( ! is_object( $field ) ) {
				continue;
			}
			$param = Ninja_Forms_Integration::param_for_field( $field->get_setting( 'key' ), $field->get_setting( 'default' ) );
			if ( $param === null || isset( $posted[ $param ] ) ) {
				continue;
			}
			// Lookup by field ID: legacy fields have auto-generated keys.
			$val = $nfSub->get_field_value( $field->get_id() );
			if ( is_array( $val ) ) {
				$val = reset( $val );
			}
			$posted[ $param ] = (string) $val;
		}

		return $posted;
	}
}
