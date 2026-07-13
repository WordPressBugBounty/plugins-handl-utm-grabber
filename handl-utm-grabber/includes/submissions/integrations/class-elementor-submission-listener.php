<?php
namespace Handl\UtmrabberFree\Submissions;

if ( ! defined( 'ABSPATH' ) ) exit;

// Side-effect-free; owns field<->param identification.
require_once dirname( dirname( __DIR__ ) ) . '/integrations/class-integration.php';
require_once dirname( dirname( __DIR__ ) ) . '/integrations/elementor/class-elementor-integration.php';

use Handl\UtmrabberFree\Integrations\Elementor_Integration;

class Elementor_Submission_Listener extends Handl_Submission_Listener {

	public function get_slug() {
		return 'elementor';
	}

	public function boot() {
		add_action( 'elementor_pro/forms/new_record', function ( $record, $handler ) {
			if ( ! is_object( $record ) || ! method_exists( $record, 'get' ) ) {
				return;
			}

			// Rebuild the {post_id}:{widget_id} composite that get_forms() emits.
			$post_id   = isset( $_POST['post_id'] ) ? (int) $_POST['post_id'] : 0;
			$widget_id = (string) $record->get_form_settings( 'id' );
			if ( $widget_id === '' && isset( $_POST['form_id'] ) ) {
				$widget_id = sanitize_text_field( wp_unslash( $_POST['form_id'] ) );
			}
			if ( $post_id <= 0 || $widget_id === '' ) {
				return;
			}
			$form_id = $post_id . ':' . $widget_id;

			// Map via the field DEFINITIONS, not the record keys: record fields
			// are keyed by custom_id, which guide-style setups leave auto-generated
			// (or dynamic). A definition's (resolved) custom_id is exactly its
			// record-fields key, and `__dynamic__` survives resolution.
			$definitions = $record->get_form_settings( 'form_fields' );
			$values      = $record->get( 'fields' );
			$posted      = array();
			if ( is_array( $definitions ) && is_array( $values ) ) {
				foreach ( $definitions as $def ) {
					if ( ! is_array( $def ) ) {
						continue;
					}
					$param = Elementor_Integration::param_for_field( $def );
					if ( $param === null || isset( $posted[ $param ] ) ) {
						continue;
					}
					$cid = isset( $def['custom_id'] ) ? (string) $def['custom_id'] : '';
					$val = isset( $values[ $cid ]['value'] ) ? $values[ $cid ]['value'] : '';
					if ( is_array( $val ) ) {
						$val = reset( $val );
					}
					$posted[ $param ] = (string) $val;
				}
			}

			// Elementor never exposes the new submission id, but the save action
			// already ran — look the row up. Must never break the submit request.
			$sub_id  = null;
			$actions = $record->get_form_settings( 'submit_actions' );
			if ( is_array( $actions ) && in_array( 'save-to-database', $actions, true ) ) {
				try {
					global $wpdb;
					$sub_id = $wpdb->get_var( $wpdb->prepare(
						"SELECT id FROM {$wpdb->prefix}e_submissions WHERE post_id = %d AND element_id = %s ORDER BY id DESC LIMIT 1",
						// form_post_id, not post_id: template-hosted forms store under the template id.
						(int) $record->get_form_settings( 'form_post_id' ),
						$widget_id
					) );
				} catch ( \Throwable $t ) {
					$sub_id = null;
				} catch ( \Exception $e ) {
					$sub_id = null;
				}
			}

			$form_name = (string) $record->get_form_settings( 'form_name' );
			$meta      = array(
				'submission_id' => $sub_id ? (string) $sub_id : null,
				'form_title'    => $form_name,
			);

			$this->emit( $form_id, $posted, $meta );
		}, 10, 2 );
	}
}
