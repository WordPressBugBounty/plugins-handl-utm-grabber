<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Gravity Forms client-side layer of the hybrid fill (ported from pro's
 * handl_gf_client_side_prefill in premiums/gravity-forms.php).
 *
 * GF hidden fields render as `<input name="input_N" id="input_{formId}_{fieldId}">`
 * with the tracked param nowhere in the markup, so the generic selector pass
 * in js/handl-utm-grabber.js can never reach them. The server bakes the cookie
 * value in at render (`gform_field_value_{param}` in handl-utm-grabber.php);
 * this emits a per-field script that overwrites from cookies afterwards —
 * UNCONDITIONALLY, writing '' when the cookie is absent — so a cached page
 * baked with one visitor's values is never submitted by another.
 */
function handl_lite_gf_client_side_prefill( $form_string, $form ) {
	if ( empty( $form['fields'] ) || ! is_array( $form['fields'] ) ) {
		return $form_string;
	}

	$tracked = generateUTMFields();
	$script  = '';

	foreach ( $form['fields'] as $field ) {
		$input_name = isset( $field['inputName'] ) ? (string) $field['inputName'] : '';
		if ( ! in_array( $input_name, $tracked, true ) ) {
			continue;
		}

		$input_id = sprintf( 'input_%d_%d', (int) $field['formId'], (int) $field['id'] );

		// [id="…"] attribute selector reaches every element with that ID when
		// the same form renders more than once on a page.
		$script .= sprintf(
			'var c=Cookies.get("%1$s");jQuery(\'[id="%2$s"]\').val(c===undefined?"":decodeURIComponent(c).replace(/[%%]/g," "));',
			esc_js( $input_name ),
			esc_js( $input_id )
		);
	}

	if ( $script === '' ) {
		return $form_string;
	}

	return $form_string . sprintf(
		'<script>jQuery(function(){setTimeout(function(){%s},1000);});</script>',
		$script
	);
}
add_filter( 'gform_get_form_filter', 'handl_lite_gf_client_side_prefill', 10, 2 );
