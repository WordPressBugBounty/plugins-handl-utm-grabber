<?php
namespace Handl\UtmrabberFree\Integrations;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Ninja Forms one-click hidden-field injector.
 *
 * Unlike Gravity Forms (one big fields[] array on the form) and Contact Form 7
 * (a single text blob), Ninja Forms stores each field as its own DB row and
 * exposes a per-field model API:
 *
 *   $field = Ninja_Forms()->form( $form_id )->field()->get();
 *   $field->update_settings([...])->save();
 *
 * So we add fields one at a time. Each injected field uses the documented
 * UTM Grabber NF setup (per docs.utmgrabber.com): a `hidden` field whose key
 * is the param name (e.g. `utm_source`) and whose `default` is the existing
 * `{handl:utm_source}` merge tag registered by `external/ninja.php`. NF
 * resolves the merge tag server-side at form render.
 *
 *
 * Identification: Add skips existing field `key`; Remove deletes fields with `default` matching "{handl:{param}}" for our tracked params only.
 */
class Ninja_Forms_Integration extends Handl_Integration {

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

			// Field model writes only touch `nf3_fields` / `nf3_field_meta`. NF
			// keeps a denormalized form snapshot in `nf3_upgrades` (and a
			// `nf_form_{id}` option fallback) that the THREE builder, public
			// renderer, and merge-tag pipeline read from. Without busting it,
			// any form that's been published through the builder before will
			// keep showing the old field set after our edits.
			if ( class_exists( '\WPN_Helper' ) ) {
				\WPN_Helper::delete_nf_cache( $form_id );
			}
		}

		return $results;
	}

	/**
	 * Insert one hidden field per requested param, skipping any that already
	 * exist on the form
	 *
	 * @param int   $form_id
	 * @param array $param_keys
	 * @return array{form_id:int,ok:bool,message:string}
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

		$existing_keys = array();
		$max_order     = 0;
		foreach ( $existing_fields as $field ) {
			$key   = (string) $field->get_setting( 'key' );
			$order = (int) $field->get_setting( 'order' );
			if ( $key !== '' ) {
				$existing_keys[] = $key;
			}
			if ( $order > $max_order ) {
				$max_order = $order;
			}
		}

		$next_order = $max_order + 1;
		$added      = 0;
		$skipped    = 0;

		foreach ( $param_keys as $param ) {
			if ( in_array( $param, $existing_keys, true ) ) {
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

			$existing_keys[] = $param;
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
	 * Delete fields whose `default` matches one of our managed merge tags.
	 *
	 * @param int $form_id
	 * @return array{form_id:int,ok:bool,message:string}
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
