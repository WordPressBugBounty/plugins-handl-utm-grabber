<?php
namespace Handl\UtmrabberFree\Integrations;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Ninja Forms one-click hidden-field injector.
 *
 * NF stores each field as its own DB row, so fields are added one at a time.
 * Injected fields are the documented setup: hidden field, key = param,
 * `default` = the `{handl:{param}}` merge tag registered by external/ninja.php
 * — so the render path needs no changes.
 *
 * CATCH: `param_for_field()` must be used by ALL THREE paths (detect, add-skip,
 * remove). Docs-setup fields have an NF auto-generated key with the merge-tag
 * default; a key-only add-skip would duplicate them.
 */
class Ninja_Forms_Integration extends Handl_Integration {

	/**
	 * Which tracked param a field feeds: key === param, or the docs-setup
	 * vintage (auto-generated key, `{handl:param}` default).
	 *
	 * @param string $key     Field `key` setting.
	 * @param string $default Field `default` setting.
	 * @return string|null null if untracked.
	 */
	public static function param_for_field( $key, $default ) {
		$tracked = handl_lite_tracking_params();
		if ( in_array( (string) $key, $tracked, true ) ) {
			return (string) $key;
		}
		if ( preg_match( '/^\{handl:([A-Za-z0-9_]+)\}$/', (string) $default, $m )
			 && in_array( $m[1], $tracked, true ) ) {
			return $m[1];
		}
		return null;
	}

	public function get_slug() {
		return 'ninja-forms';
	}

	public function get_label() {
		return 'Ninja Forms';
	}

	public function is_active() {
		if ( ! function_exists( 'is_plugin_active' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		return is_plugin_active( 'ninja-forms/ninja-forms.php' )
			&& function_exists( 'Ninja_Forms' );
	}

	public function get_forms() {
		if ( ! $this->is_active() ) {
			return array();
		}

		$forms = \Ninja_Forms()->form()->get_forms();
		$out   = array();
		foreach ( $forms as $form ) {
			/** @var \NF_Database_Models_Form $form */
			$out[] = array(
				'id'    => (int) $form->get_id(),
				'title' => (string) $form->get_setting( 'title' ),
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

			if ( $action === 'add' ) {
				$results[] = $this->add_fields_to_form( $form_id, $param_keys );
			} else {
				$results[] = $this->remove_fields_from_form( $form_id );
			}

			// Field-model writes only touch `nf3_fields` / `nf3_field_meta`. NF
			// keeps a denormalized form snapshot (nf3_upgrades, and an
			// `nf_form_{id}` option fallback) that the builder, the public
			// renderer and the merge-tag pipeline read from. Without busting it,
			// any form previously published through the builder keeps showing
			// the old field set.
			if ( class_exists( '\WPN_Helper' ) ) {
				\WPN_Helper::delete_nf_cache( $form_id );
			}
		}

		return $results;
	}

	protected function detect_integrated_params( $form_id ) {
		if ( ! $this->is_active() ) {
			return null;
		}

		$fields = \Ninja_Forms()->form( (int) $form_id )->get_fields();
		if ( ! is_array( $fields ) && ! is_object( $fields ) ) {
			return null;
		}

		$present = array();

		foreach ( $fields as $field ) {
			$param = self::param_for_field( $field->get_setting( 'key' ), $field->get_setting( 'default' ) );
			if ( $param !== null && ! in_array( $param, $present, true ) ) {
				$present[] = $param;
			}
		}

		return $present;
	}

	/**
	 * Insert one hidden field per param the form doesn't already capture (either vintage).
	 *
	 * @param int   $form_id
	 * @param array $param_keys Tracked param names to add.
	 * @return array{form_id:int,ok:bool,message:string,added:int,skipped:int,removed:int}
	 */
	private function add_fields_to_form( $form_id, $param_keys ) {
		$existing_fields = \Ninja_Forms()->form( $form_id )->get_fields();

		if ( ! is_array( $existing_fields ) && ! is_object( $existing_fields ) ) {
			return array(
				'form_id' => $form_id,
				'ok'      => false,
				'message' => 'Form not found.',
			);
		}

		$existing_params = array();
		$max_order       = 0;
		foreach ( $existing_fields as $field ) {
			$param = self::param_for_field( $field->get_setting( 'key' ), $field->get_setting( 'default' ) );
			if ( $param !== null ) {
				$existing_params[] = $param;
			}
			$order = (int) $field->get_setting( 'order' );
			if ( $order > $max_order ) {
				$max_order = $order;
			}
		}

		$next_order = $max_order + 1;
		$added      = 0;
		$skipped    = 0;

		foreach ( $param_keys as $param ) {
			if ( in_array( $param, $existing_params, true ) ) {
				$skipped++;
				continue;
			}

			$new_field = \Ninja_Forms()->form( $form_id )->field()->get();
			$new_field->update_settings( array(
				'type'    => 'hidden',
				'key'     => $param,
				'label'   => sprintf( 'HandL ( %s )', $param ),
				'default' => '{handl:' . $param . '}',
				'order'   => $next_order,
			) )->save();

			$existing_params[] = $param;
			$next_order++;
			$added++;
		}

		$msg_parts = array();
		if ( $added > 0 )   { $msg_parts[] = sprintf( 'Added %d.', $added ); }
		if ( $skipped > 0 ) { $msg_parts[] = sprintf( 'Skipped %d (already present).', $skipped ); }
		if ( empty( $msg_parts ) ) {
			$msg_parts[] = 'No changes.';
		}

		return array(
			'form_id' => $form_id,
			'ok'      => true,
			'message' => implode( ' ', $msg_parts ),
			'added'   => $added,
			'skipped' => $skipped,
			'removed' => 0,
		);
	}

	/**
	 * Delete fields whose `default` is a managed merge tag. Narrower than
	 * detection on purpose: a field keyed `utm_source` with no managed default
	 * could be the user's own — only delete what we can prove we manage.
	 *
	 * @param int $form_id
	 * @return array{form_id:int,ok:bool,message:string,added:int,skipped:int,removed:int}
	 */
	private function remove_fields_from_form( $form_id ) {
		$fields = \Ninja_Forms()->form( $form_id )->get_fields();

		if ( ! is_array( $fields ) && ! is_object( $fields ) ) {
			return array(
				'form_id' => $form_id,
				'ok'      => false,
				'message' => 'Form not found.',
			);
		}

		$managed = array();
		foreach ( $this->get_tracked_params() as $param ) {
			$managed[] = '{handl:' . $param . '}';
		}

		$deleted = 0;
		foreach ( $fields as $field ) {
			$default = (string) $field->get_setting( 'default' );
			if ( in_array( $default, $managed, true ) ) {
				$field->delete();
				$deleted++;
			}
		}

		return array(
			'form_id' => $form_id,
			'ok'      => true,
			'message' => $deleted > 0
				? sprintf( 'Removed %d HandL field%s.', $deleted, $deleted === 1 ? '' : 's' )
				: 'No HandL fields to remove.',
			'added'   => 0,
			'skipped' => 0,
			'removed' => $deleted,
		);
	}
}
