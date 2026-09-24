<?php
namespace Handl\UtmrabberFree\Integrations;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Fluent Forms one-click hidden-field injector.
 *
 * Injected fields are the documented setup: hidden field, name = param, value =
 * `{cookie.param}` (Fluent Forms' own smart code, parsed at render), so the
 * render path needs no changes. HandL's JS also fills `input[name=param]`.
 */
class Fluent_Forms_Integration extends Handl_Integration {

	/**
	 * @param array $field Field definition from the form's `fields`.
	 * @return string|null Tracked param behind the field's `{cookie.param}` value.
	 */
	public static function param_for_field( array $field ) {
		$value = isset( $field['attributes']['value'] ) && is_scalar( $field['attributes']['value'] ) ? (string) $field['attributes']['value'] : '';
		if ( ! preg_match( '/^\{cookie\.([^}]+)\}$/', $value, $m ) ) {
			return null;
		}
		return in_array( $m[1], handl_lite_tracking_params(), true ) ? $m[1] : null;
	}

	public function get_slug() {
		return 'fluent-forms';
	}

	public function get_label() {
		return 'Fluent Forms';
	}

	public function is_active() {
		if ( ! function_exists( 'is_plugin_active' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		return is_plugin_active( 'fluentform/fluentform.php' ) && class_exists( '\FluentForm\App\Models\Form' );
	}

	/** @return \FluentForm\App\Models\Form|null */
	public static function load_form( $form_id ) {
		$form = \FluentForm\App\Models\Form::find( (int) $form_id );
		return $form ? $form : null;
	}

	/**
	 * Fields flattened one level: container columns hold fields too.
	 *
	 * @param array $data Decoded `form_fields`.
	 * @return array[]
	 */
	public static function fields_of( array $data ) {
		$out = array();
		foreach ( self::top_fields( $data ) as $field ) {
			if ( isset( $field['element'] ) && $field['element'] === 'container' ) {
				foreach ( (array) ( isset( $field['columns'] ) ? $field['columns'] : array() ) as $column ) {
					foreach ( (array) ( isset( $column['fields'] ) ? $column['fields'] : array() ) as $nested ) {
						if ( is_array( $nested ) ) {
							$out[] = $nested;
						}
					}
				}
				continue;
			}
			$out[] = $field;
		}
		return $out;
	}

	/**
	 * The save path edits the decoded object tree, not an array copy: an empty
	 * `{}` (submit button styles on template forms) would come back as `[]` and
	 * the editor then drops any style set on it.
	 *
	 * @param object $node
	 * @return array
	 */
	private static function as_array( $node ) {
		$arr = json_decode( wp_json_encode( $node ), true );
		return is_array( $arr ) ? $arr : array();
	}

	/**
	 * @param mixed $value `fields` / `columns` collection from the tree; a sparse PHP array encodes as an object.
	 * @return array
	 */
	private static function as_list( $value ) {
		if ( is_object( $value ) ) {
			$value = get_object_vars( $value );
		}
		return is_array( $value ) ? array_values( $value ) : array();
	}

	/** @param mixed $field Field object from the tree. @return bool */
	private static function is_managed( $field ) {
		return is_object( $field ) && self::param_for_field( self::as_array( $field ) ) !== null;
	}

	/** @return array[] */
	private static function top_fields( array $data ) {
		if ( empty( $data['fields'] ) || ! is_array( $data['fields'] ) ) {
			return array();
		}
		return array_values( array_filter( $data['fields'], 'is_array' ) );
	}

	public function get_forms() {
		if ( ! $this->is_active() ) {
			return array();
		}

		$forms = \FluentForm\App\Models\Form::where( 'status', 'published' )->orderBy( 'title', 'ASC' )->get();
		$out   = array();
		foreach ( $forms as $form ) {
			$out[] = array(
				'id'    => (string) (int) $form->id,
				'title' => (string) $form->title,
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
			$form    = self::load_form( $form_id );
			$tree    = $form ? json_decode( (string) $form->form_fields ) : null;
			if ( ! is_object( $tree ) ) {
				$results[] = array(
					'form_id' => (string) $form_id,
					'ok'      => false,
					'message' => 'Form not found.',
				);
				continue;
			}

			$added   = 0;
			$skipped = 0;
			$removed = 0;
			if ( $action === 'add' ) {
				list( $tree, $added, $skipped ) = $this->add_fields( $tree, $param_keys );
			} else {
				list( $tree, $removed ) = $this->remove_fields( $tree );
			}

			$ok = (bool) $form->fill( array(
				'form_fields' => wp_json_encode( $tree ),
				'updated_at'  => current_time( 'mysql' ),
			) )->save();

			$results[] = array(
				'form_id' => (string) $form_id,
				'ok'      => $ok,
				'message' => $ok ? 'Updated.' : 'Update failed.',
				'added'   => $added,
				'skipped' => $skipped,
				'removed' => $removed,
			);
		}

		return $results;
	}

	protected function detect_integrated_params( $form_id ) {
		if ( ! $this->is_active() ) {
			return null;
		}

		$form = self::load_form( $form_id );
		$data = $form ? json_decode( (string) $form->form_fields, true ) : null;
		if ( ! is_array( $data ) ) {
			return null;
		}

		$present = array();
		foreach ( self::fields_of( $data ) as $field ) {
			$param = self::param_for_field( $field );
			if ( $param !== null && ! in_array( $param, $present, true ) ) {
				$present[] = $param;
			}
		}
		return $present;
	}

	/**
	 * @param object $tree       Decoded `form_fields`.
	 * @param array  $param_keys Tracked param names to add.
	 * @return array{0: object, 1: int, 2: int} [ $tree, $added, $skipped ]
	 */
	private function add_fields( $tree, $param_keys ) {
		$existing = array();
		$names    = array();
		foreach ( self::fields_of( self::as_array( $tree ) ) as $field ) {
			$param = self::param_for_field( $field );
			if ( $param !== null ) {
				$existing[] = $param;
			}
			if ( isset( $field['attributes']['name'] ) ) {
				$names[] = (string) $field['attributes']['name'];
			}
		}

		$fields  = self::as_list( isset( $tree->fields ) ? $tree->fields : array() );
		$added   = 0;
		$skipped = 0;

		foreach ( $param_keys as $param ) {
			if ( in_array( $param, $existing, true ) ) {
				$skipped++;
				continue;
			}

			// Fluent Forms rejects duplicate names; a user field may already own this one.
			$name = in_array( $param, $names, true ) ? 'handl_' . $param : $param;

			$fields[] = json_decode( wp_json_encode( array(
				'index'          => 0,
				'element'        => 'input_hidden',
				'attributes'     => array(
					'type'  => 'hidden',
					'name'  => $name,
					'value' => '{cookie.' . $param . '}',
				),
				'settings'       => array(
					'admin_field_label' => ucwords( str_replace( '_', ' ', trim( $param, '_' ) ) ),
				),
				'editor_options' => array(
					'title'      => 'Hidden Field',
					'icon_class' => 'ff-edit-hidden-field',
					'template'   => 'inputHidden',
				),
				'uniqElKey'      => 'el_' . (int) round( microtime( true ) * 1000 ) . ( count( $fields ) + 1 ),
			) ) );
			$existing[] = $param;
			$names[]    = $name;
			$added++;
		}

		$tree->fields = $fields;

		return array( $tree, $added, $skipped );
	}

	/**
	 * @param object $tree Decoded `form_fields`.
	 * @return array{0: object, 1: int} [ $tree, $removed ]
	 */
	private function remove_fields( $tree ) {
		$removed = 0;
		$keep    = array();

		foreach ( self::as_list( isset( $tree->fields ) ? $tree->fields : array() ) as $field ) {
			if ( ! is_object( $field ) ) {
				$keep[] = $field;
				continue;
			}
			if ( isset( $field->element ) && $field->element === 'container' && ! empty( $field->columns ) ) {
				foreach ( self::as_list( $field->columns ) as $column ) {
					if ( ! is_object( $column ) || ! isset( $column->fields ) ) {
						continue;
					}
					$column_fields = array();
					foreach ( self::as_list( $column->fields ) as $nested ) {
						if ( self::is_managed( $nested ) ) {
							$removed++;
							continue;
						}
						$column_fields[] = $nested;
					}
					$column->fields = $column_fields;
				}
				$keep[] = $field;
				continue;
			}
			if ( self::is_managed( $field ) ) {
				$removed++;
				continue;
			}
			$keep[] = $field;
		}

		$tree->fields = $keep;

		return array( $tree, $removed );
	}
}
