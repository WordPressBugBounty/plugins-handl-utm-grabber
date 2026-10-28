<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Contact Form 7 front-end fill (server-side layer of the hybrid fill).
 *
 * Two pieces:
 *
 * 1. `handl_lite_cf7_fill_hidden_tag()` bakes the cookie value into the
 *    `[hidden {param}_cf7 …]` tags injected by the one-click integration
 *    (includes/integrations/contact-form-7/), so the value is present in the
 *    initial HTML — works with JS disabled, and mail templates always see a
 *    value. The front-end JS then overwrites from cookies (including with an
 *    empty string), which corrects stale values on cached pages.
 *
 * 2. `handl_lite_cf7_register_pro_tags()` registers the `{param}_cf7` form-tag
 *    types that pro's premiums/contact-form-7.php registers, for the lite
 *    params only. A pro→free downgraded site whose forms carry
 *    `[utm_source_cf7 my_field]` keeps capturing instead of rendering dead
 *    literal text. Pro-only params (fbclid_cf7, custom params, …) stay
 *    unregistered — that's the free/pro boundary. No tag-generator UI is
 *    added; free keeps its own "UTM Fields (HandL)" generator.
 */

/**
 * CF7 field name for a tracked param. CF7 rejects names that do not start with
 * a letter, so params starting with `_` get a `handl` prefix: `_fbc` → `handl_fbc_cf7`.
 */
function handl_lite_cf7_field_name( $param ) {
	$param = (string) $param;
	return ( strpos( $param, '_' ) === 0 ? 'handl' . $param : $param ) . '_cf7';
}

/** Tracked param for a CF7 field name, or '' when it is not one of ours. */
function handl_lite_cf7_param_of( $field ) {
	static $map = null;
	if ( $map === null ) {
		$map = array();
		foreach ( handl_lite_tracking_params() as $param ) {
			$map[ handl_lite_cf7_field_name( $param ) ] = $param;
		}
	}
	return isset( $map[ $field ] ) ? $map[ $field ] : '';
}

/**
 * Server-side fill for injected `[hidden {param}_cf7 …]` tags. CF7 passes each
 * scanned tag through this filter while it is still an array; the hidden
 * handler then renders `reset( $tag->values )`. The `{param}_cf7` custom tag
 * types registered below are untouched — different basetype, own handler.
 */
function handl_lite_cf7_fill_hidden_tag( $scanned_tag ) {
	if ( ! is_array( $scanned_tag ) ) {
		return $scanned_tag;
	}

	$type = isset( $scanned_tag['type'] ) ? rtrim( (string) $scanned_tag['type'], '*' ) : '';
	if ( $type !== 'hidden' ) {
		return $scanned_tag;
	}

	$name = isset( $scanned_tag['name'] ) ? (string) $scanned_tag['name'] : '';
	if ( substr( $name, -4 ) !== '_cf7' ) {
		return $scanned_tag;
	}

	$param = handl_lite_cf7_param_of( $name );
	if ( $param === '' ) {
		return $scanned_tag;
	}

	// Respect an explicit static value typed into the tag.
	$current = isset( $scanned_tag['values'] ) ? array_filter( (array) $scanned_tag['values'], 'strlen' ) : array();
	if ( ! empty( $current ) ) {
		return $scanned_tag;
	}

	if ( isset( $_COOKIE[ $param ] ) && $_COOKIE[ $param ] !== '' ) {
		$scanned_tag['values'] = array( handl_sanitize_tracking_value( $_COOKIE[ $param ] ) );
	}

	return $scanned_tag;
}
add_filter( 'wpcf7_form_tag', 'handl_lite_cf7_fill_hidden_tag' );

/**
 * Render a `[{param}_cf7 name …]` tag as a hidden input filled from the
 * param's cookie (modeled on pro's HandLContactForm7Tag handler). The param is
 * derived from the tag type, so one handler serves every registered type.
 */
function handl_lite_cf7_pro_tag_handler( $tag ) {
	$param = handl_lite_cf7_param_of( (string) $tag->type );

	$class = wpcf7_form_controls_class( $tag->type );

	$atts             = array();
	$atts['class']    = $tag->get_class_option( $class );
	$atts['id']       = $tag->get_id_option();
	$atts['name']     = $tag->name;
	$atts['tabindex'] = $tag->get_option( 'tabindex', 'signed_int', true );

	$value = isset( $tag->values[0] ) ? $tag->values[0] : '';

	if ( $value === '' && isset( $_COOKIE[ $param ] ) && $_COOKIE[ $param ] !== '' ) {
		$value = handl_sanitize_tracking_value( $_COOKIE[ $param ] );
	}

	$atts['type']  = 'hidden';
	$atts['value'] = $value;

	return sprintf( '<input %s />', wpcf7_format_atts( $atts ) );
}

function handl_lite_cf7_register_pro_tags() {
	// Pro registers these itself; don't double-register if it's active.
	if ( class_exists( 'HandLContactForm7Tag' ) ) {
		return;
	}

	foreach ( handl_lite_tracking_params() as $param ) {
		wpcf7_add_form_tag(
			handl_lite_cf7_field_name( $param ),
			'handl_lite_cf7_pro_tag_handler',
			array( 'name-attr' => true )
		);
	}
}
add_action( 'wpcf7_init', 'handl_lite_cf7_register_pro_tags', 9 );
