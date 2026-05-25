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

	public function to_array() {
		return array(
			'slug'           => $this->get_slug(),
			'label'          => $this->get_label(),
			'active'         => (bool) $this->is_active(),
			'tracked_params' => array_values( $this->get_tracked_params() ),
		);
	}
}
