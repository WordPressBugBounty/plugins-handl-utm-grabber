<?php
namespace Handl\UtmrabberFree\Submissions;

if ( ! defined( 'ABSPATH' ) ) exit;

class Contact_Form_7_Submission_Listener extends Handl_Submission_Listener {

	public function get_slug() {
		return 'contact-form-7';
	}

	public function boot() {
		add_action( 'wpcf7_before_send_mail', function ( $contact_form ) {
			if ( ! class_exists( '\WPCF7_Submission' ) ) {
				return;
			}
			$submission = \WPCF7_Submission::get_instance();
			if ( ! $submission ) {
				return;
			}

			$data   = $submission->get_posted_data();
			$posted = array();
			foreach ( handl_lite_tracking_params() as $param ) {
				$key = $param . '_cf7';
				if ( ! isset( $data[ $key ] ) ) {
					continue;
				}
				$val = is_array( $data[ $key ] ) ? reset( $data[ $key ] ) : $data[ $key ];
				$posted[ $param ] = (string) $val;
			}

			// CF7 has no native persistent submission id.
			$meta = array(
				'submission_id' => null,
				'form_title'    => method_exists( $contact_form, 'title' ) ? (string) $contact_form->title() : '',
			);

			$this->emit( (string) $contact_form->id(), $posted, $meta );
		}, 10, 1 );
	}
}
