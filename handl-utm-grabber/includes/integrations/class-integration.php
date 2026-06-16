<?php
namespace Handl\UtmrabberFree\Integrations;

if ( ! defined( 'ABSPATH' ) ) exit;

/** Form-plugin integration contract: list forms, add/remove HandL hidden fields. */
abstract class Handl_Integration {

	abstract public function get_slug();

	abstract public function get_label();

	abstract public function is_active();

	/** @return array<int, array{id: string, title: string}> Form ids are strings (Elementor: `{post_id}:{widget_id}`). */
	abstract public function get_forms();

	/**
	 * @param array  $form_ids
	 * @param array  $param_keys Ignored when $action is `remove`.
	 * @param string $action     `add` | `remove`
	 * @param array  $options    Integration-specific; e.g. CF7 `also_add_to_email`.
	 * @return array<int, array{form_id: string, ok: bool, message: string, added?: int, skipped?: int, removed?: int}>
	 */
	abstract public function apply( $form_ids, $param_keys, $action, $options = array() );

	public function get_tracked_params() {
		return handl_lite_tracking_params();
	}

	/**
	 * Per-form status. @param string|int $form_id
	 * @return array{form_id:string,status:string,integrated:string[],missing:string[]} status: complete|partial|none|not_found.
	 */
	public function get_form_status( $form_id ) {
		$present = $this->detect_integrated_params( $form_id );

		if ( $present === null ) {
			return array(
				'form_id'    => (string) $form_id,
				'status'     => 'not_found',
				'integrated' => array(),
				'missing'    => array(),
			);
		}

		$tracked    = array_map( 'strval', $this->get_tracked_params() );
		$integrated = array_values( array_intersect( $tracked, $present ) );
		$missing    = array_values( array_diff( $tracked, $integrated ) );

		if ( empty( $integrated ) ) {
			$status = 'none';
		} elseif ( empty( $missing ) ) {
			$status = 'complete';
		} else {
			$status = 'partial';
		}

		return array(
			'form_id'    => (string) $form_id,
			'status'     => $status,
			'integrated' => $integrated,
			'missing'    => $missing,
		);
	}

	/** Tracked params present on the form (current + legacy formats). @return string[]|null null if form not loadable. */
	abstract protected function detect_integrated_params( $form_id );

	public function to_array() {
		return array(
			'slug'           => $this->get_slug(),
			'label'          => $this->get_label(),
			'active'         => (bool) $this->is_active(),
			'tracked_params' => array_values( $this->get_tracked_params() ),
		);
	}
}
