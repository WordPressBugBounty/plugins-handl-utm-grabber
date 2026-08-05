<?php
namespace Handl\UtmrabberFree\WeeklySnapshot;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * WP-dashboard widget: the site's real weekly numbers first, the email ask
 * second. Deliberately outside the notice manager's slot —
 * it is the always-present, passive fallback that keeps carrying the opt-in
 * after the notice group caps out, so it never dismisses and never counts
 * impressions.
 */
class Handl_Snapshot_Dashboard_Widget {

	const WIDGET_ID   = 'handl_snapshot_widget';
	const WINDOW_DAYS = 7;

	/** @var Handl_Snapshot_Settings */
	private $settings;

	/** @var Handl_Snapshot_Rollup */
	private $rollup;

	public function __construct() {
		$this->settings = new Handl_Snapshot_Settings();
		$this->rollup   = new Handl_Snapshot_Rollup();
	}

	public function register() {
		add_action( 'wp_dashboard_setup', array( $this, 'add_widget' ) );
	}

	public function add_widget() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		wp_add_dashboard_widget( self::WIDGET_ID, 'UTM Grabber · this week', array( $this, 'render' ) );
	}

	public function render() {
		$settings = $this->settings->get();
		$day      = ucfirst( $settings['send_day'] );

		// Same window as the email: 7 COMPLETE days ending yesterday, so the
		// widget and the Snapshot can never disagree.
		$window = $this->rollup->aggregate( wp_date( 'Y-m-d', time() - DAY_IN_SECONDS ), self::WINDOW_DAYS );
		$total  = (int) $window['total'];

		if ( $total > 0 ) {
			$tracked_pct = (int) round( 100 * (int) $window['tracked'] / $total );
			?>
			<div style="display:flex;gap:24px;margin:4px 0 10px;">
				<div>
					<span style="font-size:26px;font-weight:600;line-height:1.2;"><?php echo esc_html( number_format_i18n( $total ) ); ?></span>
					<span style="display:block;color:#646970;">leads</span>
				</div>
				<div>
					<span style="font-size:26px;font-weight:600;line-height:1.2;"><?php echo esc_html( $tracked_pct ); ?>%</span>
					<span style="display:block;color:#646970;">tracked</span>
				</div>
			</div>
			<?php
		} else {
			?>
			<p style="margin:4px 0 10px;color:#646970;">No leads recorded yet this week.</p>
			<?php
		}

		if ( $settings['enabled'] ) {
			$hour   = (int) $settings['send_hour'];
			$twelve = $hour % 12 === 0 ? 12 : $hour % 12;
			?>
			<p style="margin:0 0 0.4em;">Your Snapshot arrives <?php echo esc_html( $day . ' at ' . $twelve . ':00 ' . ( $hour < 12 ? 'am' : 'pm' ) ); ?>.</p>
			<p style="margin:0;"><a href="<?php echo esc_url( $this->settings->manage_url() ); ?>">Open the Tracking Doctor</a></p>
			<?php
			return;
		}
		?>
		<p style="margin:0 0 0.4em;"><strong>Email me this every <?php echo esc_html( $day ); ?></strong></p>
		<?php
		Handl_Snapshot_Optin_Form::markup( 'surface:dashboard-widget' );
	}
}
