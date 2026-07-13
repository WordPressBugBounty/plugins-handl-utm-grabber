<?php
namespace Handl\UtmrabberFree\Onboarding;

if ( ! defined( 'ABSPATH' ) ) exit;

/** Onboarding contract per form-plugin: locate the pages that host a given form. */
abstract class Handl_Integration_Onboarding {

	/** Must match the matching Handl_Integration slug. */
	abstract public function get_slug();

	/**
	 * @param string|int $form_id
	 * @return string[] URLs of published pages that embed this form.
	 */
	abstract public function find_host_pages( $form_id );

	/** Whether the live-test flow is wired (vs just listing forms). */
	public function supports_live_test() {
		return true;
	}
}
