<?php
namespace Handl\UtmrabberFree\Integrations;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Formidable Forms one-click hidden-field injector.
 *
 * Injected fields are the documented setup: hidden field, field key = param;
 * lite/formidable.php fills the value from the cookie at render. Formidable
 * keeps keys unique across all forms (`utm_source2`) and lower-cases them, so
 * fields are matched by key prefix, case-insensitive.
 */
class Formidable_Integration extends Handl_Integration {

	/**
	 * @param string $key Field key.
	 * @return string|null Longest tracked param the key starts with (case-insensitive).
	 */
	public static function param_for_key( $key ) {
		$key  = (string) $key;
		$best = null;
		foreach ( handl_lite_tracking_params() as $param ) {
			if ( strncasecmp( $key, $param, strlen( $param ) ) === 0 && ( $best === null || strlen( $param ) > strlen( $best ) ) ) {
				$best = $param;
			}
		}
		return $best;
	}

	/**
	 * @param object $field Formidable field row.
	 * @return string|null
	 */
	public static function param_for_field( $field ) {
		return is_object( $field ) && isset( $field->field_key ) ? self::param_for_key( $field->field_key ) : null;
	}

	public function get_slug() {
		return 'formidable';
	}

	public function get_label() {
		return 'Formidable Forms';
	}

	public function is_active() {
		if ( ! function_exists( 'is_plugin_active' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		return is_plugin_active( 'formidable/formidable.php' ) && class_exists( 'FrmField' );
	}

	public function get_forms() {
		if ( ! $this->is_active() ) {
			return array();
		}

		$out = array();
		foreach ( (array) \FrmForm::get_published_forms() as $form ) {
			$out[] = array(
				'id'    => (string) (int) $form->id,
				'title' => (string) $form->name,
			);
		}
		return $out;
	}

	public function apply( $form_ids, $param_keys, $action, $options = array() ) {
		if ( ! $this->is_active() ) {
			return array();
		}

		$results = array();

		foreach ( $form_ids as $form_id ) {
			$form_id = (int) $form_id;
			if ( ! \FrmForm::getOne( $form_id ) ) {
				$results[] = array(
					'form_id' => (string) $form_id,
					'ok'      => false,
					'message' => 'Form not found.',
				);
				continue;
			}

			if ( $action === 'add' ) {
				$results[] = $this->add_fields( $form_id, $param_keys );
			} else {
				$results[] = $this->remove_fields( $form_id );
			}
		}

		return $results;
	}

	protected function detect_integrated_params( $form_id ) {
		if ( ! $this->is_active() || ! \FrmForm::getOne( (int) $form_id ) ) {
			return null;
		}

		$present = array();
		foreach ( (array) \FrmField::get_all_for_form( (int) $form_id ) as $field ) {
			$param = self::param_for_field( $field );
			if ( $param !== null && ! in_array( $param, $present, true ) ) {
				$present[] = $param;
			}
		}
		return $present;
	}

	/**
	 * @param int   $form_id
	 * @param array $param_keys Tracked param names to add.
	 * @return array{form_id:string,ok:bool,message:string,added:int,skipped:int,removed:int}
	 */
	private function add_fields( $form_id, $param_keys ) {
		$existing = array();
		foreach ( (array) \FrmField::get_all_for_form( $form_id ) as $field ) {
			$param = self::param_for_field( $field );
			if ( $param !== null ) {
				$existing[] = $param;
			}
		}

		$added   = 0;
		$skipped = 0;
		$failed  = 0;

		foreach ( $param_keys as $param ) {
			if ( in_array( $param, $existing, true ) ) {
				$skipped++;
				continue;
			}

			$values              = \FrmFieldsHelper::setup_new_vars( 'hidden', $form_id );
			$values['field_key'] = $param;
			$values['name']      = ucwords( str_replace( '_', ' ', trim( $param, '_' ) ) );

			if ( \FrmField::create( $values ) ) {
				$existing[] = $param;
				$added++;
			} else {
				$failed++;
			}
		}

		if ( $added > 0 ) {
			self::keep_submit_last( $form_id );
		}

		return array(
			'form_id' => (string) $form_id,
			'ok'      => $failed === 0,
			'message' => $failed === 0 ? 'Updated.' : sprintf( 'Could not add %d field%s.', $failed, $failed === 1 ? '' : 's' ),
			'added'   => $added,
			'skipped' => $skipped,
			'removed' => 0,
		);
	}

	/** The builder re-orders the submit field on every add through a POST value; do the same here. */
	private static function keep_submit_last( $form_id ) {
		$fields = (array) \FrmField::get_all_for_form( $form_id );
		$max    = 0;
		foreach ( $fields as $field ) {
			$max = max( $max, (int) $field->field_order );
		}
		foreach ( $fields as $field ) {
			if ( isset( $field->type ) && $field->type === 'submit' && (int) $field->field_order < $max ) {
				\FrmField::update( $field->id, array( 'field_order' => ++$max ) );
			}
		}
	}

	/**
	 * Hidden fields keyed by a tracked param, any vintage. Narrower than detection:
	 * a visible field keyed `utm_source` is the user's own.
	 *
	 * @param int $form_id
	 * @return array{form_id:string,ok:bool,message:string,added:int,skipped:int,removed:int}
	 */
	private function remove_fields( $form_id ) {
		$removed = 0;
		foreach ( (array) \FrmField::get_all_for_form( $form_id ) as $field ) {
			if ( isset( $field->type ) && $field->type === 'hidden' && self::param_for_field( $field ) !== null ) {
				\FrmField::destroy( $field->id );
				$removed++;
			}
		}

		return array(
			'form_id' => (string) $form_id,
			'ok'      => true,
			'message' => $removed > 0
				? sprintf( 'Removed %d HandL field%s.', $removed, $removed === 1 ? '' : 's' )
				: 'No HandL fields to remove.',
			'added'   => 0,
			'skipped' => 0,
			'removed' => $removed,
		);
	}
}
