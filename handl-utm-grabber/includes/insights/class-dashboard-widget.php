<?php
namespace Handl\UtmrabberFree\Insights;

if ( ! defined( 'ABSPATH' ) ) exit;

/** Dashboard widget: a React mount point. Data/analysis come from the ajax endpoint. */
class Handl_Dashboard_Widget {

	const WIDGET_ID = 'handl-lead-insights';

	public static function register() {
		// The ajax endpoints require manage_options; don't show a widget that can't load.
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		wp_add_dashboard_widget(
			self::WIDGET_ID,
			'HandL UTM Grabber: Recent Lead Insights',
			array( __CLASS__, 'render' ),
			null,
			null,
			'normal', // context
			'high'    // priority (above core widgets)
		);
	}

	public static function render() {
		echo '<div id="handl-react-root" style="display:contents;"><div id="handl_lead_insights"></div></div>';
		echo '<noscript>Enable JavaScript to view your recent lead insights.</noscript>';
	}
}
