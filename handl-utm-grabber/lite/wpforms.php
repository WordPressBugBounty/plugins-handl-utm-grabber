<?php
if ( ! defined( 'ABSPATH' ) ) exit;

require_once dirname( __DIR__ ) . '/includes/integrations/class-integration.php';
require_once dirname( __DIR__ ) . '/includes/integrations/wpforms/class-wpforms-integration.php';

use Handl\UtmrabberFree\Integrations\WPForms_Integration;

/**
 * Registers a `{handl_param}` smart tag per tracked param.
 *
 * @param array $tags
 * @return array
 */
function handl_wpf_register_smarttag( $tags ) {
	foreach ( handl_lite_tracking_params() as $field ) {
		$tags[ WPForms_Integration::tag_for_param( $field ) ] = $field;
	}
	return $tags;
}
add_filter( 'wpforms_smart_tags', 'handl_wpf_register_smarttag' );

/**
 * Resolves the tag from the cookie at render. Pre-1.6.7 WPForms hands us the
 * exact spelling (`handl_` . param); keep resolving it.
 *
 * @param string $content
 * @param string $tag
 * @return string
 */
function handl_wpf_process_smarttag( $content, $tag ) {
	foreach ( handl_lite_tracking_params() as $field ) {
		if ( WPForms_Integration::tag_for_param( $field ) === $tag || 'handl_' . $field === $tag ) {
			$cookie_field = isset( $_COOKIE[ $field ] ) ? handl_sanitize_tracking_value( $_COOKIE[ $field ] ) : '';
			return str_replace( '{' . $tag . '}', $cookie_field, $content );
		}
	}
	return $content;
}
add_filter( 'wpforms_smart_tag_process', 'handl_wpf_process_smarttag', 10, 2 );
