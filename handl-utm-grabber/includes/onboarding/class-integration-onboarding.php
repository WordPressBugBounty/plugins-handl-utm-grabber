<?php
namespace Handl\UtmrabberFree\Onboarding;

if ( ! defined( 'ABSPATH' ) ) exit;

/** Onboarding contract per form-plugin: locate host pages + listen for a tagged submission. */
abstract class Handl_Integration_Onboarding {

	/** Must match the matching Handl_Integration slug. */
	abstract public function get_slug();

	/**
	 * @param string|int $form_id
	 * @return string[] URLs of published pages that embed this form.
	 */
	abstract public function find_host_pages( $form_id );

	/** @param callable $on_match function( string $form_id, array $posted ): void */
	abstract public function register_test_listener( callable $on_match );

	/** Whether the live-test flow is wired (vs just listing forms). */
	public function supports_live_test() {
		return true;
	}
}
