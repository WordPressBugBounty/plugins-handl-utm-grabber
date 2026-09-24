<?php
namespace Handl\UtmrabberFree\Submissions;

if ( ! defined( 'ABSPATH' ) ) exit;

require_once dirname( dirname( __DIR__ ) ) . '/integrations/class-integration.php';
require_once dirname( dirname( __DIR__ ) ) . '/integrations/formidable/class-formidable-integration.php';

use Handl\UtmrabberFree\Integrations\Formidable_Integration;

class Formidable_Submission_Listener extends Handl_Submission_Listener {

	public function get_slug() {
		return 'formidable';
	}

	public function boot() {
		add_action( 'frm_after_create_entry', function ( $entry_id, $form_id, $args = array() ) {
			// Repeater rows are child entries on a child form; the parent entry carries the lead.
			if ( ! empty( $args['is_child'] ) ) {
				return;
			}

			$form   = \FrmForm::getOne( (int) $form_id );
			$posted = self::extract_from_entry( \FrmEntry::getOne( (int) $entry_id, true ) );

			$meta = array(
				'submission_id' => ! empty( $entry_id ) ? (string) $entry_id : null,
				'form_title'    => $form && isset( $form->name ) ? (string) $form->name : '',
			);

			$this->emit( (string) (int) $form_id, $posted, $meta );
		}, 10, 3 );
	}

	/**
	 * Tracked param => value ('' = mapped but blank); metas are keyed by field id.
	 *
	 * @param object $entry Formidable entry with `metas` loaded.
	 * @return array<string,string>
	 */
	public static function extract_from_entry( $entry ) {
		$posted = array();
		if ( ! is_object( $entry ) || empty( $entry->form_id ) ) {
			return $posted;
		}
		$metas = isset( $entry->metas ) && is_array( $entry->metas ) ? $entry->metas : array();

		foreach ( (array) \FrmField::get_all_for_form( (int) $entry->form_id ) as $field ) {
			$param = Formidable_Integration::param_for_field( $field );
			if ( $param === null || isset( $posted[ $param ] ) ) {
				continue;
			}
			$value            = isset( $metas[ $field->id ] ) ? $metas[ $field->id ] : '';
			$posted[ $param ] = is_scalar( $value ) ? (string) $value : '';
		}

		return $posted;
	}
}
