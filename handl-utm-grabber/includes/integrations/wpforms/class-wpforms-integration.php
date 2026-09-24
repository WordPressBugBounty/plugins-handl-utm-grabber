<?php
namespace Handl\UtmrabberFree\Integrations;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * WPForms one-click hidden-field injector (Pro only: Lite has no Hidden Field).
 * Injected fields default to the `{handl_param}` smart tag that lite/wpforms.php
 * registers and resolves from the cookie at render.
 */
class WPForms_Integration extends Handl_Integration {

	/**
	 * WPForms >= 1.6.7 parses `{[a-z0-9_]+}` smart tags only, so every tag name
	 * goes through this.
	 *
	 * @param string $param
	 * @return string
	 */
	public static function tag_for_param( $param ) {
		return 'handl_' . preg_replace( '/[^a-z0-9_]+/', '_', strtolower( (string) $param ) );
	}

	/** @return array<string,string> tag => param; the pre-1.6.7 exact spelling is kept so existing fields still resolve. */
	private static function tag_map() {
		$map = array();
		foreach ( handl_lite_tracking_params() as $param ) {
			$map[ 'handl_' . $param ]            = $param;
			$map[ self::tag_for_param( $param ) ] = $param;
		}
		return $map;
	}

	/**
	 * @param array $field Field definition from the form's `fields`.
	 * @return string|null Tracked param behind the field's smart-tag default.
	 */
	public static function param_for_field( array $field ) {
		$default = isset( $field['default_value'] ) ? (string) $field['default_value'] : '';
		if ( ! preg_match( '/^\{([^}]+)\}$/', $default, $m ) ) {
			return null;
		}
		$map = self::tag_map();
		return isset( $map[ $m[1] ] ) ? $map[ $m[1] ] : null;
	}

	public function get_slug() {
		return 'wpforms';
	}

	public function get_label() {
		return 'WPForms';
	}

	public function is_active() {
		if ( ! function_exists( 'is_plugin_active' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		return is_plugin_active( 'wpforms/wpforms.php' ) && function_exists( 'wpforms' );
	}

	/** @return array|null Decoded form data; null if not a WPForms form. */
	public static function load_form( $form_id ) {
		$data = wpforms()->form->get( (int) $form_id, array( 'content_only' => true ) );
		return is_array( $data ) ? $data : null;
	}

	public function get_forms() {
		if ( ! $this->is_active() ) {
			return array();
		}

		$posts = wpforms()->form->get( '', array( 'orderby' => 'title', 'order' => 'ASC' ) );
		$out   = array();
		foreach ( (array) $posts as $post ) {
			$out[] = array(
				'id'    => (string) (int) $post->ID,
				'title' => (string) $post->post_title,
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
			$data    = self::load_form( $form_id );
			if ( ! $data ) {
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
				list( $data, $added, $skipped ) = $this->add_fields( $data, $param_keys );
			} else {
				list( $data, $removed ) = $this->remove_fields( $data );
			}

			$saved = wpforms()->form->update( $form_id, $data );
			$ok    = ! empty( $saved ) && ! is_wp_error( $saved );

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

		$data = self::load_form( $form_id );
		if ( ! $data ) {
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

	/** @return array<int|string, array> */
	private static function fields_of( array $data ) {
		if ( empty( $data['fields'] ) || ! is_array( $data['fields'] ) ) {
			return array();
		}
		return array_filter( $data['fields'], 'is_array' );
	}

	/**
	 * @param array $data       Decoded form data.
	 * @param array $param_keys Tracked param names to add.
	 * @return array{0: array, 1: int, 2: int} [ $data, $added, $skipped ]
	 */
	private function add_fields( array $data, $param_keys ) {
		$fields   = self::fields_of( $data );
		$existing = array();
		foreach ( $fields as $field ) {
			$param = self::param_for_field( $field );
			if ( $param !== null ) {
				$existing[] = $param;
			}
		}

		// WPForms' next_field_id() rule: the stored counter, unless a field id outran it.
		$next = isset( $data['field_id'] ) ? (int) $data['field_id'] : 0;
		if ( ! empty( $fields ) ) {
			$next = max( $next, (int) max( array_keys( $fields ) ) + 1 );
		}

		$added   = 0;
		$skipped = 0;

		foreach ( $param_keys as $param ) {
			if ( in_array( $param, $existing, true ) ) {
				$skipped++;
				continue;
			}

			$fields[ $next ] = array(
				'id'            => (string) $next,
				'type'          => 'hidden',
				'label'         => ucwords( str_replace( '_', ' ', trim( $param, '_' ) ) ),
				'label_disable' => '1',
				'default_value' => '{' . self::tag_for_param( $param ) . '}',
				'css'           => $param,
			);
			$existing[] = $param;
			$next++;
			$added++;
		}

		$data['fields']   = $fields;
		$data['field_id'] = $next;

		return array( $data, $added, $skipped );
	}

	/**
	 * @param array $data Decoded form data.
	 * @return array{0: array, 1: int} [ $data, $removed ]
	 */
	private function remove_fields( array $data ) {
		$managed = array();
		foreach ( array_keys( self::tag_map() ) as $tag ) {
			$managed[] = '{' . $tag . '}';
		}

		$fields  = self::fields_of( $data );
		$removed = 0;
		foreach ( $fields as $id => $field ) {
			$default = isset( $field['default_value'] ) ? (string) $field['default_value'] : '';
			if ( in_array( $default, $managed, true ) ) {
				unset( $fields[ $id ] );
				$removed++;
			}
		}

		$data['fields'] = $fields;

		return array( $data, $removed );
	}
}
