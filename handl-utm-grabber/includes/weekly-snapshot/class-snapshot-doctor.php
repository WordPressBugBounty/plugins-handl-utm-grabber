<?php
namespace Handl\UtmrabberFree\WeeklySnapshot;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * The email's Tracking Doctor: scores findings from the weekly aggregate and
 * the same rule-based checks the Tracking Doctor page runs, sorts most severe
 * first, caps at 3. No green fix items — green exists only as the all-clear
 * state. Item severities: leak (red) > blind_spot (orange) > not_tracking (amber).
 */
class Handl_Snapshot_Doctor {

	const COLORS = array(
		'leak'         => '#DC2626',
		'blind_spot'   => '#EA580C',
		'not_tracking' => '#CA8A04',
		'neutral'      => '#6F7680',
	);

	const LEADS = array(
		'leak'         => 'DATA LEAK.',
		'blind_spot'   => 'BLIND SPOT.',
		'not_tracking' => 'NOT TRACKING.',
	);

	const UNTRACKED_LEAK_PCT = 25;

	/** @var Handl_Snapshot_Settings */
	private $settings;

	public function __construct( Handl_Snapshot_Settings $settings ) {
		$this->settings = $settings;
	}

	/**
	 * @param array $context {
	 *     totals: {conversions:int, tracked:int, untracked:int},
	 *     channels: array<string,int>,
	 *     woo: array|null {orders:int, attributed_orders:int},
	 *     forms_check: array,   // Forms_Check::run() result
	 *     caching_check: array, // Caching_Check::run() result
	 * }
	 * @return array{items:array,all_clear:bool}
	 */
	public function diagnose( array $context ) {
		$items = $context['totals']['conversions'] === 0
			? $this->zero_state_items( $context )
			: $this->normal_items( $context );

		usort( $items, function ( $a, $b ) {
			return $a['rank'] - $b['rank'];
		} );
		$items = array_slice( $items, 0, 3 );

		$all_clear = empty( $items );
		return array( 'items' => $items, 'all_clear' => $all_clear );
	}

	private function normal_items( array $context ) {
		$totals   = $context['totals'];
		$items    = array();
		$leak_pct = $totals['conversions'] > 0
			? (int) round( 100 * $totals['untracked'] / $totals['conversions'] )
			: 0;
		$leaking = $leak_pct > self::UNTRACKED_LEAK_PCT;

		if ( $leaking ) {
			$caching_warns = isset( $context['caching_check']['status'] )
				&& in_array( $context['caching_check']['status'], array( 'warn', 'fail' ), true );
			$cause         = $caching_warns
				? 'A caching plugin we detected may be stripping your tracking cookies, or the links are not tagged'
				: 'Usually the links pointing at your site are not tagged';

			$items[] = $this->item(
				'leak',
				0,
				sprintf( 'You are leaking %d%% of your conversions.', $leak_pct ),
				sprintf(
					'%d conversions arrived with no source attached, so you cannot tell what paid for them. %s. This is your single biggest leak.',
					$totals['untracked'],
					$cause
				),
				'Stop the leak',
				$this->settings->manage_url(),
				'30 seconds to diagnose'
			);
		}

		$woo = $context['woo'];
		if ( is_array( $woo ) && $woo['orders'] > 0 && $woo['attributed_orders'] === 0 ) {
			$items[] = $this->item(
				'blind_spot',
				10,
				sprintf(
					'None of your %d orders trace back to a campaign.',
					$woo['orders']
				),
				'Every order this week arrived with no source, so your store revenue is invisible to your marketing. Usually the checkout is cached or the tracking cookie never reaches it.',
				'See why',
				$this->settings->manage_url(),
				'your revenue is untraceable'
			);
		}

		if ( $context['channels']['paid'] > 0 ) {
			$items[] = $this->item(
				'blind_spot',
				11,
				'You cannot see your Meta, TikTok, Bing or LinkedIn clicks.',
				'Google Ads clicks are captured. Clicks from every other ad platform land untagged, so that spend looks like it did nothing.',
				'Capture those too',
				handl_v3_generate_links( 'weekly_snapshot_clickids', '', 'weekly_email' ),
				'if you run those platforms'
			);
		}

		$items = array_merge( $items, $this->unwired_form_items( $context['forms_check'] ) );

		return $items;
	}

	/**
	 * Zero-conversion week: the Doctor takes over the whole email. When it is
	 * broken, help — the last item always concedes it may genuinely have been
	 * a quiet week.
	 */
	private function zero_state_items( array $context ) {
		$items = array_slice( $this->unwired_form_items( $context['forms_check'], true ), 0, 1 );

		$caching = $context['caching_check'];
		if ( isset( $caching['status'] ) && in_array( $caching['status'], array( 'warn', 'fail' ), true ) ) {
			$items[] = array(
				'severity'     => 'blind_spot',
				'rank'         => 10,
				'lead'         => 'CACHING.',
				'color'        => self::COLORS['blind_spot'],
				'headline'     => 'Your cookie may be getting stripped.',
				'body'         => 'If your host or caching plugin caches aggressively, the tracking cookie never gets written and nothing is captured.',
				'action_label' => 'Check the caching report',
				'action_url'   => $this->settings->manage_url(),
				'note'         => '',
			);
		}

		$items[] = array(
			'severity'     => 'neutral',
			'rank'         => 90,
			'lead'         => '',
			'color'        => self::COLORS['neutral'],
			'headline'     => 'Or your site genuinely had no submissions this week.',
			'body'         => 'If that is the case, nothing is broken and your next Snapshot will pick right back up.',
			'action_label' => '',
			'action_url'   => '',
			'note'         => '',
		);

		return $items;
	}

	/** NOT TRACKING items from the same Forms_Check the Tracking Doctor page renders. */
	private function unwired_form_items( array $forms_check, $zero_state = false ) {
		$details      = isset( $forms_check['details'] ) && is_array( $forms_check['details'] ) ? $forms_check['details'] : array();
		$integrations = isset( $details['integrations'] ) && is_array( $details['integrations'] ) ? $details['integrations'] : array();
		$severity     = $zero_state ? 'leak' : 'not_tracking';
		$items        = array();

		if ( empty( $integrations ) ) {
			if ( $zero_state ) {
				$items[] = $this->item(
					$severity,
					0,
					'No forms are connected yet.',
					'No supported form plugin is wired to UTM Grabber, so submissions arrive with no source attached.',
					'Set up form tracking',
					admin_url( 'admin.php?page=handl-onboarding#/connect' ),
					'takes about a minute',
					'NOT TRACKING.'
				);
			}
			return $items;
		}

		foreach ( $integrations as $integration ) {
			if ( empty( $integration['forms'] ) ) {
				continue;
			}
			$unwired = 0;
			foreach ( $integration['forms'] as $form ) {
				if ( $form['status'] === 'none' ) {
					++$unwired;
				}
			}
			if ( $unwired === 0 ) {
				continue;
			}

			$all = $unwired === count( $integration['forms'] );
			$items[] = $this->item(
				$severity,
				20,
				$all
					? sprintf( '%s is installed but sending nothing.', $integration['label'] )
					: sprintf( '%d %s form(s) are sending nothing.', $unwired, $integration['label'] ),
				'Every submission those forms collect is invisible to your Snapshot until they are wired. One click fixes it.',
				'Connect it',
				admin_url( 'admin.php?page=handl-utm-grabber.php#/integrations' ),
				'1 click, free',
				$zero_state ? 'NOT TRACKING.' : null
			);
			break;   // one form item is enough; the Doctor page shows the rest
		}

		return $items;
	}

	private function item( $severity, $rank, $headline, $body, $action_label, $action_url, $note, $lead = null ) {
		return array(
			'severity'     => $severity,
			'rank'         => $rank,
			'lead'         => $lead !== null ? $lead : self::LEADS[ $severity ],
			'color'        => self::COLORS[ $severity ],
			'headline'     => $headline,
			'body'         => $body,
			'action_label' => $action_label,
			'action_url'   => $action_url,
			'note'         => $note,
		);
	}
}
