<?php
namespace Handl\UtmrabberFree\Integrations;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Gravity Forms one-click hidden-field injector.
 *
 * Detection keys on `inputName = {param}` — every vintage sets it identically,
 * so already-configured sites read `complete` and re-apply is a no-op. Labels
 * (`{param} (HandL)` / `HandL ( {param} )`) are only a fallback.
 */
class Gravity_Forms_Integration extends Handl_Integration {

	public function get_slug() {
		return 'gravity-forms';
	}

	public function get_label() {
		return 'Gravity Forms';
	}

	public function is_active() {
		if ( ! function_exists( 'is_plugin_active' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		return is_plugin_active( 'gravityforms/gravityforms.php' ) && class_exists( 'GFAPI' );
	}

	public function get_forms() {
		if ( ! $this->is_active() ) {
			return array();
		}

		$forms = \GFAPI::get_forms();
		$out   = array();
		foreach ( $forms as $form ) {
			$out[] = array(
				'id'    => (string) (int) $form['id'],
				'title' => (string) $form['title'],
			);
		}
		return $out;
	}

	public function apply( $form_ids, $param_keys, $action, $options = array() ) {
		if ( ! $this->is_active() ) {
			return array();
		}

		$results       = array();
		$updated_forms = array();
		$counts        = array();

		foreach ( $form_ids as $form_id ) {
			$form_id = (int) $form_id;
			$form    = \GFAPI::get_form( $form_id );
			if ( ! $form ) {
				$results[] = array(
					'form_id' => (string) $form_id,
					'ok'      => false,
					'message' => 'Form not found.',
				);
				continue;
			}

			if ( $action === 'add' ) {
				list( $form, $added, $skipped ) = $this->add_fields_to_form( $form, $param_keys );
				$counts[] = array( 'added' => $added, 'skipped' => $skipped, 'removed' => 0 );
			} else {
				list( $form, $removed ) = $this->remove_fields_from_form( $form );
				$counts[] = array( 'added' => 0, 'skipped' => 0, 'removed' => $removed );
			}
			$updated_forms[] = $form;
		}

		if ( empty( $updated_forms ) ) {
			return $results;
		}

		$update_results = \GFAPI::update_forms( $updated_forms );

		foreach ( $updated_forms as $i => $form ) {
			$ok = isset( $update_results[ $i ] ) ? $update_results[ $i ] === true : true;
			$results[] = array(
				'form_id' => (string) (int) $form['id'],
				'ok'      => (bool) $ok,
				'message' => $ok ? 'Updated.' : 'Update failed.',
				'added'   => $counts[ $i ]['added'],
				'skipped' => $counts[ $i ]['skipped'],
				'removed' => $counts[ $i ]['removed'],
			);
		}

		return $results;
	}

	protected function detect_integrated_params( $form_id ) {
		if ( ! $this->is_active() ) {
			return null;
		}

		$form = \GFAPI::get_form( (int) $form_id );
		if ( ! $form || ! isset( $form['fields'] ) || ! is_array( $form['fields'] ) ) {
			return null;
		}

		$tracked = array_map( 'strval', $this->get_tracked_params() );
		$present = array();

		foreach ( $form['fields'] as $field ) {
			$input_name = isset( $field->inputName ) ? (string) $field->inputName : ( isset( $field['inputName'] ) ? (string) $field['inputName'] : '' );
			$label      = isset( $field->label ) ? (string) $field->label : ( isset( $field['label'] ) ? (string) $field['label'] : '' );

			foreach ( $tracked as $param ) {
				if ( in_array( $param, $present, true ) ) {
					continue;
				}
				// Primary: inputName === param (identical across vintages).
				if ( $input_name === $param ) {
					$present[] = $param;
					continue;
				}
				// Fallback: a HandL-marked label naming the param (both label vintages).
				if ( strpos( $label, 'HandL' ) !== false && strpos( $label, $param ) !== false ) {
					$present[] = $param;
				}
			}
		}

		return array_values( array_unique( $present ) );
	}

	/**
	 * Append hidden fields for any param not already present (skip keyed on
	 * `inputName`, same as detection — no duplicates on legacy setups).
	 *
	 * @param array $form       GF form array.
	 * @param array $param_keys Tracked param names to add.
	 * @return array{0: array, 1: int, 2: int} [ $form, $added, $skipped ]
	 */
	private function add_fields_to_form( $form, $param_keys ) {
		$existing_input_names = array();
		$max_id               = 0;
		foreach ( $form['fields'] as $field ) {
			$max_id = max( $max_id, (int) $field['id'] );
			if ( isset( $field['inputName'] ) && $field['inputName'] !== '' ) {
				$existing_input_names[] = $field['inputName'];
			}
		}

		$template = array(
			'adminLabel'           => '',
			'isRequired'           => false,
			'size'                 => 'medium',
			'errorMessage'         => '',
			'visibility'           => 'visible',
			'inputs'               => null,
			'description'          => '',
			'allowsPrepopulate'    => true,
			'inputMask'            => false,
			'inputMaskValue'       => '',
			'inputMaskIsCustom'    => false,
			'maxLength'            => '',
			'inputType'            => '',
			'labelPlacement'       => '',
			'descriptionPlacement' => '',
			'subLabelPlacement'    => '',
			'placeholder'          => '',
			'cssClass'             => '',
			'noDuplicates'         => false,
			'defaultValue'         => '',
			'choices'              => '',
			'conditionalLogic'     => '',
			'productField'         => '',
			'multipleFiles'        => false,
			'maxFiles'             => '',
			'calculationFormula'   => '',
			'calculationRounding'  => '',
			'enableCalculation'    => '',
			'disableQuantity'      => false,
			'displayAllCategories' => false,
			'useRichTextEditor'    => false,
			'pageNumber'           => 1,
			'fields'               => '',
			'displayOnly'          => '',
		);

		$added   = 0;
		$skipped = 0;

		foreach ( $param_keys as $param ) {
			if ( in_array( $param, $existing_input_names, true ) ) {
				$skipped++;
				continue;
			}

			$field = new \GF_Field_Hidden();
			foreach ( $template as $k => $v ) {
				$field->{$k} = $v;
			}
			$max_id++;
			$field->formId    = (int) $form['id'];
			$field->id        = $max_id;
			$field->label     = sprintf( 'HandL ( %s )', $param );
			$field->inputName = $param;

			$form['fields'][]       = $field;
			$existing_input_names[] = $param;
			$added++;
		}

		return array( $form, $added, $skipped );
	}

	/**
	 * Drop every field we're responsible for, any vintage: `inputName` is a
	 * tracked param (docs-guide setups can have ANY label, so label-matching
	 * alone strands them), or the label contains "HandL" (old fields may have
	 * no inputName).
	 *
	 * @param array $form GF form array.
	 * @return array{0: array, 1: int} [ $form, $removed ]
	 */
	private function remove_fields_from_form( $form ) {
		$tracked        = array_map( 'strval', $this->get_tracked_params() );
		$before         = count( $form['fields'] );
		$form['fields'] = array_values(
			array_filter(
				$form['fields'],
				function ( $field ) use ( $tracked ) {
					$label      = isset( $field->label ) ? $field->label : ( isset( $field['label'] ) ? $field['label'] : '' );
					$input_name = isset( $field->inputName ) ? $field->inputName : ( isset( $field['inputName'] ) ? $field['inputName'] : '' );

					$is_ours = ( (string) $input_name !== '' && in_array( (string) $input_name, $tracked, true ) )
						|| strpos( (string) $label, 'HandL' ) !== false;

					return ! $is_ours;
				}
			)
		);
		return array( $form, $before - count( $form['fields'] ) );
	}
}
