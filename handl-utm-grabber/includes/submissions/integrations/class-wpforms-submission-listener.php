<?php
namespace Handl\UtmrabberFree\Submissions;

if ( ! defined( 'ABSPATH' ) ) exit;

require_once dirname( dirname( __DIR__ ) ) . '/integrations/class-integration.php';
require_once dirname( dirname( __DIR__ ) ) . '/integrations/wpforms/class-wpforms-integration.php';

use Handl\UtmrabberFree\Integrations\WPForms_Integration;

class WPForms_Submission_Listener extends Handl_Submission_Listener {

	public function get_slug() {
		return 'wpforms';
	}

	public function boot() {
		add_action( 'wpforms_process_complete', function ( $fields, $entry, $form_data, $entry_id ) {
			$posted = self::extract_from_fields( $form_data, $fields );

			$meta = array(
				'submission_id' => ! empty( $entry_id ) ? (string) $entry_id : null,
				'form_title'    => isset( $form_data['settings']['form_title'] ) ? (string) $form_data['settings']['form_title'] : '',
			);

			$this->emit( isset( $form_data['id'] ) ? (string) $form_data['id'] : '', $posted, $meta );
		}, 10, 4 );
	}

	/**
	 * Tracked param => value ('' = mapped but blank); definitions and values are both keyed by field id.
	 *
	 * @param array $form_data Decoded form definition.
	 * @param array $fields    Submitted field values keyed by field id.
	 * @return array<string,string>
	 */
	public static function extract_from_fields( $form_data, $fields ) {
		$posted = array();
		if ( empty( $form_data['fields'] ) || ! is_array( $form_data['fields'] ) ) {
			return $posted;
		}

		foreach ( $form_data['fields'] as $id => $def ) {
			$param = is_array( $def ) ? WPForms_Integration::param_for_field( $def ) : null;
			if ( $param === null || isset( $posted[ $param ] ) ) {
				continue;
			}
			$posted[ $param ] = isset( $fields[ $id ]['value'] ) ? (string) $fields[ $id ]['value'] : '';
		}

		return $posted;
	}
}
