<?php
namespace Handl\UtmrabberFree\Submissions;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Contract for per-plugin submission listeners. Each implementation hooks its
 * form plugin's native submit action, normalizes the tracked params, and emits
 * a single `handl_utm_submission` WordPress action that any feature can consume.
 */
abstract class Handl_Submission_Listener {

	/** Must match the corresponding Handl_Integration slug. */
	abstract public function get_slug();

	/** Hook the native submit action exactly once. Called during load. */
	abstract public function boot();

	/**
	 * Emit a normalized submission as a WordPress action.
	 *
	 * @param string|int $form_id
	 * @param array      $posted Map of mapped tracked param => value; '' = mapped but unpopulated, absent key = no field.
	 * @param array      $meta   array{ submission_id?: string|int|null, form_title?: string }
	 */
	protected function emit( $form_id, array $posted, array $meta = array() ) {
		$event = array(
			'integration'   => $this->get_slug(),
			'form_id'       => (string) $form_id,
			'posted'        => $posted,
			'submission_id' => isset( $meta['submission_id'] ) ? $meta['submission_id'] : null,
			'form_title'    => isset( $meta['form_title'] ) ? (string) $meta['form_title'] : '',
		);

		/**
		 * Fires on every form submission that carried tracked params.
		 *
		 * @param array $event array{ integration:string, form_id:string, posted:array,
		 *                     submission_id:string|null, form_title:string }
		 */
		do_action( 'handl_utm_submission', $event );
	}
}
