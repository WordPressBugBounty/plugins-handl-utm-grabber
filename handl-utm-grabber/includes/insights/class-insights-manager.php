<?php
namespace Handl\UtmrabberFree\Insights;

if ( ! defined( 'ABSPATH' ) ) exit;

require_once __DIR__ . '/class-lead-recorder.php';
require_once __DIR__ . '/class-insight-engine.php';
require_once __DIR__ . '/class-ai-analyzer.php';
require_once __DIR__ . '/class-insight-provider.php';
require_once __DIR__ . '/class-insights-ajax.php';
require_once __DIR__ . '/class-dashboard-widget.php';
require_once __DIR__ . '/surfaces/class-entry-cards-manager.php';

/** Insights module: recorder subscription, ajax endpoints, dashboard widget, and entry-detail cards. */
class Handl_Insights_Manager {

	public function register() {
		$recorder = new Handl_Lead_Recorder();
		add_action( 'handl_utm_submission', array( $recorder, 'record' ) );

		( new Handl_Insights_Ajax() )->register();
		( new Handl_Entry_Cards_Manager() )->boot();

		add_action( 'wp_dashboard_setup', array( Handl_Dashboard_Widget::class, 'register' ) );
	}
}
