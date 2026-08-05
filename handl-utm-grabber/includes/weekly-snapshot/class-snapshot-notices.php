<?php
namespace Handl\UtmrabberFree\WeeklySnapshot;

if ( ! defined( 'ABSPATH' ) ) exit;

use Handl\UtmrabberFree\Notices\Handl_Notice_Manager;

/**
 * The Snapshot opt-in notice group, most specific ask first, registered into
 * the shared Handl_Notice_Manager. Every member requires the Snapshot
 * disabled, renders the shared email form (Handl_Snapshot_Optin_Form), and
 * posts to handl_snapshot_notice_optin: Snapshot enable only, never the
 * course signup the wizard and Doctor banner do.
 *
 * Group budget: 2 dismissals total, then only the passive dashboard widget
 * (a renderless member here) keeps carrying the ask.
 */
class Handl_Snapshot_Notices {

	/** Blind-spot notice: needs a real week and enough volume to quote a %. */
	const BLINDSPOT_MIN_AGE_DAYS = 8;
	const BLINDSPOT_MIN_LEADS    = 5;
	const BLINDSPOT_MIN_PCT      = 25;

	/** First-capture celebration goes stale fast; never show it for an old win. */
	const FIRST_CAPTURE_FRESH_DAYS = 14;

	/** @var Handl_Snapshot_Settings */
	private $settings;

	/** @var Handl_Snapshot_Rollup */
	private $rollup;

	/** @var array|null Request-cached last-complete-week aggregate. */
	private $week = null;

	public function __construct() {
		$this->settings = new Handl_Snapshot_Settings();
		$this->rollup   = new Handl_Snapshot_Rollup();
	}

	public function register() {
		$manager = Handl_Notice_Manager::get_instance();
		$manager->register_group( 'snapshot-optin', array( 'max_dismissals' => 2 ) );

		$manager->register( 'snap-first-capture', array(
			'dismiss_on_action' => false,   // the form is the action; opting in must not spend the group's dismissal budget
			'group'           => 'snapshot-optin',
			'priority'        => 30,
			'cooldown_days'   => 3,
			'max_impressions' => 2,
			'show_when'       => function () {
				return ! $this->settings->get()['enabled']
					&& $this->rollup->leads_alltime() >= 1
					&& $this->first_capture_is_fresh();
			},
			'render_callback' => function ( $id ) {
				$this->print_optin( $id, 'success',
					'Your first lead just came in, fully tracked.',
					'See where every lead comes from in a free weekly email. Nothing to log into.'
				);
			},
		) );

		$manager->register( 'snap-blindspot', array(
			'dismiss_on_action' => false,   // the form is the action; opting in must not spend the group's dismissal budget
			'group'           => 'snapshot-optin',
			'priority'        => 32,
			'cooldown_days'   => 14,
			'max_impressions' => 3,
			'show_when'       => function () {
				if ( $this->settings->get()['enabled'] || $this->rollup->first_seen_age_days() < self::BLINDSPOT_MIN_AGE_DAYS ) {
					return false;
				}
				$week = $this->last_week();
				return $week['total'] >= self::BLINDSPOT_MIN_LEADS && $week['untracked_pct'] > self::BLINDSPOT_MIN_PCT;
			},
			'render_callback' => function ( $id ) {
				$week = $this->last_week();
				$this->print_optin( $id, 'info',
					(int) $week['untracked_pct'] . '% of your leads came in with no source last week',
					'Your free Weekly Snapshot shows exactly where the gap is, every ' . esc_html( $this->send_day_label() ) . '.'
				);
			},
		) );

		$manager->register( 'snap-value', array(
			'dismiss_on_action' => false,   // the form is the action; opting in must not spend the group's dismissal budget
			'group'             => 'snapshot-optin',
			'priority'          => 34,
			'cooldown_days'     => 7,
			'max_impressions'   => 4,
			'revive_after_days' => 90,   // the evergreen ask returns quarterly instead of growing a bigger budget
			'show_when'       => function () {
				return ! $this->settings->get()['enabled'] && $this->rollup->first_seen_age_days() >= 2;
			},
			'render_callback' => function ( $id ) {
				$this->print_optin( $id, 'info',
					'See which campaigns bring you leads, every ' . esc_html( $this->send_day_label() ),
					'Your Weekly Snapshot is free and reads your own numbers. We will send it to the email below.'
				);
			},
		) );

		// The widget carries the same ask passively; renderless membership makes
		// its opt-in click visible in the group state without slot participation.
		$manager->register( 'surface:dashboard-widget', array(
			'dismiss_on_action' => false,
			'group'      => 'snapshot-optin',
			'renderless' => true,
		) );
	}

	private function first_capture_is_fresh() {
		$first = $this->rollup->earliest_tracked_day();
		if ( $first === '' ) {
			return false;
		}
		$age = (int) floor( ( strtotime( wp_date( 'Y-m-d' ) . ' 12:00:00' ) - strtotime( $first . ' 12:00:00' ) ) / DAY_IN_SECONDS );
		return $age <= self::FIRST_CAPTURE_FRESH_DAYS;
	}

	/** Same window as the email: 7 complete days ending yesterday. */
	private function last_week() {
		if ( $this->week === null ) {
			$window = $this->rollup->aggregate( wp_date( 'Y-m-d', time() - DAY_IN_SECONDS ), 7 );
			$total  = (int) $window['total'];
			$this->week = array(
				'total'         => $total,
				'untracked_pct' => $total > 0 ? (int) round( 100 * ( $total - (int) $window['tracked'] ) / $total ) : 0,
			);
		}
		return $this->week;
	}

	private function send_day_label() {
		return ucfirst( $this->settings->get()['send_day'] );
	}

	/** Opt-in notice: title + body + the shared email form. The form is the action, so no dismiss-on-action. */
	private function print_optin( $id, $type, $title, $message ) {
		printf(
			'<div class="notice notice-%s is-dismissible handl-managed-notice" data-handl-notice-id="%s" data-handl-dismiss-on-action="0">',
			esc_attr( $type ),
			esc_attr( $id )
		);
		printf( '<p style="margin:0.5em 0 0.2em;font-size:14px;"><strong>%s</strong></p>', wp_kses_post( $title ) );
		printf( '<p style="margin:0 0 0.6em;">%s</p>', wp_kses_post( $message ) );
		Handl_Snapshot_Optin_Form::markup( $id );
		echo '</div>';
	}
}
