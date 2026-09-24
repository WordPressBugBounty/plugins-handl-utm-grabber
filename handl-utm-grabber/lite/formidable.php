<?php
if ( ! defined( 'ABSPATH' ) ) exit;

require_once dirname( __DIR__ ) . '/includes/integrations/class-integration.php';
require_once dirname( __DIR__ ) . '/includes/integrations/formidable/class-formidable-integration.php';

use Handl\UtmrabberFree\Integrations\Formidable_Integration;

/**
 * Server-side fill for Formidable hidden fields keyed by a tracked param
 * (one-click or docs setup). Formidable inputs use `item_meta[id]` names, so
 * the front-end JS cannot reach them; the value comes from the cookie at render.
 *
 * @param mixed  $new_value
 * @param object $field
 * @param bool   $is_default
 * @return mixed
 */
function handl_frm_get_default_value( $new_value, $field, $is_default ) {
	$key = isset( $field->field_key ) ? Formidable_Integration::param_for_key( $field->field_key ) : null;
	if ( $key !== null && isset( $_COOKIE[ $key ] ) && $_COOKIE[ $key ] != '' ) {
		$new_value = handl_sanitize_tracking_value( $_COOKIE[ $key ] );
	}
	return $new_value;
}
add_filter( 'frm_get_default_value', 'handl_frm_get_default_value', 10, 3 );
