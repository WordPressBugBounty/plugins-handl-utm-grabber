<?php
namespace Handl\UtmrabberFree\Onboarding;

if ( ! defined( 'ABSPATH' ) ) exit;

use Handl\UtmrabberFree\Integrations\Handl_Integrations_Manager;
use Handl\UtmrabberFree\Notices\Handl_Notice_Manager;
use Handl\UtmrabberFree\WeeklySnapshot\Handl_Snapshot_Rollup;
use Handl\UtmrabberFree\WeeklySnapshot\Handl_Snapshot_Settings;

/**
 * The onboarding notice group, registered into the shared
 * Handl_Notice_Manager: get people who skipped or abandoned the wizard back
 * into finishing it. This module owns its notices; the notices module only
 * renders and keeps state.
 *
 * The rollup dependency is deliberate: its `first_seen` date is the honest
 * epoch for "{days} days" copy and the 45-day stop, and `leads_alltime` is
 * the first-capture stop.
 *
 * The retired setup notice left orphaned per-user snooze meta behind
 * (handl_setup_notice_snoozed_until).
 */
class Handl_Onboarding_Notices {

	const ONBOARDING_PAGE = 'handl-onboarding';
	const REDIRECT_OPTION = 'handl_onboarding_redirect';

	/** Deck global stop: onboarding notices end 45 days after the epoch. */
	const MAX_AGE_DAYS = 45;

	/** @var Handl_Integrations_Manager|null */
	private $integrations;

	/** @var Handl_Snapshot_Rollup */
	private $rollup;

	/** @var Handl_Snapshot_Settings */
	private $snapshot_settings;

	/** @var array|null Request-cached integration summary. */
	private $integration_state = null;

	/** @param Handl_Integrations_Manager|null $integrations */
	public function __construct( $integrations = null ) {
		$this->integrations      = $integrations;
		$this->rollup            = new Handl_Snapshot_Rollup();
		$this->snapshot_settings = new Handl_Snapshot_Settings();
	}

	public function register() {
		$manager = Handl_Notice_Manager::get_instance();
		$manager->register_group( 'onboarding', array( 'max_dismissals' => 3 ) );

		$wizard  = admin_url( 'admin.php?page=' . self::ONBOARDING_PAGE );
		$connect = $wizard . '#/connect';
		$welcome = $wizard . '#/welcome';

		$manager->register( 'onb-form-specific', array(
			'group'           => 'onboarding',
			'priority'        => 10,
			'cooldown_days'   => 7,
			'max_impressions' => 3,
			'show_when'       => function () {
				$state = $this->integration_state();
				return $this->onboarding_open() && $state['all_none'] && count( $state['qualifying'] ) === 1;
			},
			'render_callback' => function ( $id ) use ( $connect ) {
				$state  = $this->integration_state();
				$plugin = $this->integration_label( $state['qualifying'][0] );
				$this->print_standard( $id, array(
					'title'   => 'Put the campaign name on every ' . esc_html( $plugin ) . ' entry',
					'message' => 'We found <strong>' . esc_html( $plugin ) . '</strong> on your site. Connect it once and new entries record the source they came from.',
					'actions' => array(
						array( 'label' => 'Connect ' . $plugin, 'url' => $connect, 'primary' => true ),
						array( 'label' => 'I use something else', 'url' => $connect, 'button' => false ),
					),
				) );
			},
		) );

		// The legacy finish variant: some forms connected, some still untracked.
		$manager->register( 'onb-finish', array(
			'group'           => 'onboarding',
			'priority'        => 11,
			'cooldown_days'   => 7,
			'max_impressions' => 3,
			'show_when'       => function () {
				$state = $this->integration_state();
				return $this->onboarding_open() && ! empty( $state['qualifying'] )
					&& ! $state['all_none'] && ! $state['all_complete'];
			},
			'render_callback' => function ( $id ) use ( $connect ) {
				$state = $this->integration_state();
				$this->print_standard( $id, array(
					'title'   => 'A few of your forms are still missing attribution.',
					'message' => 'Great start. Submissions from your ' . $this->plugin_list( $state['incomplete'] ) . ' forms still are not capturing campaign data.',
					'actions' => array(
						array( 'label' => 'Finish setup in 15 seconds', 'url' => $connect, 'primary' => true ),
					),
				) );
			},
		) );

		$manager->register( 'onb-connect', array(
			'group'           => 'onboarding',
			'priority'        => 12,
			'cooldown_days'   => 7,
			'max_impressions' => 3,
			'title'           => 'Get the campaign name on every form entry',
			'message'         => 'Your forms are live but not connected yet, so new entries still arrive with no source attached. Setup takes about 90 seconds.',
			'actions'         => array(
				array( 'label' => 'Connect my forms', 'url' => $connect, 'primary' => true ),
			),
			'show_when'       => function () {
				$state = $this->integration_state();
				// Exactly one detected plugin belongs to onb-form-specific.
				return $this->onboarding_open() && $state['all_none'] && count( $state['qualifying'] ) >= 2;
			},
		) );

		$manager->register( 'onb-no-data', array(
			'group'           => 'onboarding',
			'priority'        => 14,
			'schedule_days'   => array( 3, 10, 21 ),
			'epoch'           => function () {
				return $this->rollup->first_seen();
			},
			'show_when'       => function () {
				// onboarding_open() already requires zero tracked leads.
				return $this->onboarding_open();
			},
			'render_callback' => function ( $id ) use ( $welcome ) {
				$this->print_standard( $id, array(
					'title'   => 'Start seeing where your leads come from',
					'message' => 'UTM Grabber has been watching for ' . (int) $this->rollup->first_seen_age_days() . ' days and has not recorded a lead source yet. One short setup fixes that.',
					'actions' => array(
						array( 'label' => 'Finish setup', 'url' => $welcome, 'primary' => true ),
					),
				) );
			},
		) );

		$manager->register( 'onb-email-carrot', array(
			'group'           => 'onboarding',
			'priority'        => 16,
			'cooldown_days'   => 6,
			'max_impressions' => 3,
			'show_when'       => function () {
				// Thursday or Friday only: the promised email is imminent.
				return $this->onboarding_open()
					&& ! $this->snapshot_settings->get()['enabled']
					&& in_array( (int) wp_date( 'N' ), array( 4, 5 ), true )
					&& $this->rollup->first_seen_age_days() >= 2;
			},
			'render_callback' => function ( $id ) use ( $welcome ) {
				$day = ucfirst( $this->snapshot_settings->get()['send_day'] );
				$this->print_standard( $id, array(
					'title'   => 'Get your traffic sources emailed to you every ' . esc_html( $day ),
					'message' => 'The Weekly Snapshot shows which campaigns brought your leads that week. It is free, and finishing setup turns it on.',
					'actions' => array(
						array( 'label' => 'Turn it on', 'url' => $welcome, 'primary' => true ),
					),
				) );
			},
		) );
	}

	/**
	 * Global stops shared by every onboarding notice: wizard
	 * completed, first tracked lead recorded, 45 days since the epoch, or a
	 * fresh activation mid-redirect. (The 3-dismissal stop is the group cap.)
	 */
	private function onboarding_open() {
		if ( get_option( Handl_Onboarding_Manager::COMPLETED_OPTION, false ) || get_option( self::REDIRECT_OPTION, false ) ) {
			return false;
		}
		if ( $this->rollup->leads_alltime() >= 1 ) {
			return false;
		}
		$age = $this->rollup->first_seen_age_days();
		return $age >= 0 && $age <= self::MAX_AGE_DAYS;
	}

	/** @return array{qualifying:string[], incomplete:string[], all_none:bool, all_complete:bool} */
	private function integration_state() {
		if ( $this->integration_state !== null ) {
			return $this->integration_state;
		}

		$qualifying   = array();
		$incomplete   = array();
		$all_none     = true;
		$all_complete = true;

		$statuses = $this->integrations ? $this->integrations->get_all_statuses() : array();
		foreach ( $statuses as $slug => $status ) {
			// Only active plugins that actually have forms.
			if ( empty( $status['active'] ) || empty( $status['total_forms'] ) ) {
				continue;
			}
			$qualifying[] = $slug;

			$state = isset( $status['status'] ) ? $status['status'] : 'none';
			if ( $state !== 'none' ) {
				$all_none = false;
			}
			if ( $state !== 'complete' ) {
				$all_complete = false;
				$incomplete[] = $slug;
			}
		}

		$this->integration_state = array(
			'qualifying'   => $qualifying,
			'incomplete'   => $incomplete,
			'all_none'     => $all_none,
			'all_complete' => $all_complete,
		);
		return $this->integration_state;
	}

	private function integration_label( $slug ) {
		$integration = $this->integrations ? $this->integrations->get_integration( $slug ) : null;
		return $integration ? $integration->get_label() : $slug;
	}

	/** Grammatically join plugin labels (each bolded): "X", "X and Y", "X, Y, and Z". @param string[] $slugs @return string HTML */
	private function plugin_list( $slugs ) {
		$labels = array();
		foreach ( $slugs as $slug ) {
			$labels[] = '<strong>' . esc_html( $this->integration_label( $slug ) ) . '</strong>';
		}

		$count = count( $labels );
		if ( $count === 0 ) {
			return 'your';
		}
		if ( $count === 1 ) {
			return $labels[0];
		}
		if ( $count === 2 ) {
			return $labels[0] . ' and ' . $labels[1];
		}

		$last = array_pop( $labels );
		return implode( ', ', $labels ) . ', and ' . $last;
	}

	/** Standard title/message/actions markup for callbacks that need live merge tags. */
	private function print_standard( $id, array $args ) {
		printf(
			'<div class="notice notice-%s is-dismissible handl-managed-notice" data-handl-notice-id="%s" data-handl-dismiss-on-action="1">',
			esc_attr( isset( $args['type'] ) ? $args['type'] : 'info' ),
			esc_attr( $id )
		);
		printf( '<p style="margin:0.5em 0 0.2em;font-size:14px;"><strong>%s</strong></p>', wp_kses_post( $args['title'] ) );
		printf( '<p style="margin:0 0 0.6em;">%s</p>', wp_kses_post( $args['message'] ) );
		echo '<p style="margin:0 0 0.6em;">';
		foreach ( $args['actions'] as $action ) {
			$is_button = ! isset( $action['button'] ) || $action['button'];
			$classes   = 'handl-notice-action' . ( $is_button ? ' button' : '' ) . ( ! empty( $action['primary'] ) ? ' button-primary' : '' );
			printf(
				'<a href="%s" class="%s" style="margin-right:12px;%s">%s</a>',
				esc_url( $action['url'] ),
				esc_attr( $classes ),
				$is_button ? '' : 'text-decoration:none;',
				esc_html( $action['label'] )
			);
		}
		echo '</p></div>';
	}
}
