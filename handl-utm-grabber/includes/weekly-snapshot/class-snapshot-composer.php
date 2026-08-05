<?php
namespace Handl\UtmrabberFree\WeeklySnapshot;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Subject, preheader and hook selection, all read from the frozen payload.
 * The hook is scored over fixed tiers (record week > big swing up >
 * recoverable untracked > channel breakout > campaign standout > swing down >
 * steady > zero); a tier used in either of the last 2 sends is skipped so the
 * subject never reads the same two weeks running. The from-name ("Weekly
 * Snapshot") carries the ritual — the subject spends no characters on it.
 */
class Handl_Snapshot_Composer {

	/** Above this length the short hook variant is used, so the prefix always survives. */
	const SUBJECT_BUDGET = 45;

	/**
	 * @param array    $payload      Handl_Snapshot_Collector::payload().
	 * @param string[] $recent_tiers Tiers used in recent sends, oldest first.
	 * @return array{subject:string,preheader:string,tier:string}
	 */
	public function compose( array $payload, array $recent_tiers = array() ) {
		$candidates = $this->candidates( $payload );
		$blocked    = array_slice( $recent_tiers, -2 );

		$chosen = null;
		foreach ( $candidates as $candidate ) {
			$always_ok = in_array( $candidate['tier'], array( 'steady', 'zero' ), true );
			if ( $always_ok || ! in_array( $candidate['tier'], $blocked, true ) ) {
				$chosen = $candidate;
				break;
			}
		}
		if ( $chosen === null ) {
			$chosen = end( $candidates );
		}

		$prefix = $payload['site']['prefix'];
		$hook   = $chosen['hook'];
		if ( $this->length( $prefix . ': ' . $hook ) > self::SUBJECT_BUDGET ) {
			$hook = $chosen['short'];
		}

		return array(
			'subject'   => $prefix . ': ' . $hook,
			'hook'      => $hook,
			'preheader' => $this->preheader( $payload, $candidates, $chosen['tier'] ),
			'tier'      => $chosen['tier'],
		);
	}

	/** Eligible tiers for this payload, best first. steady/zero always terminate the list. */
	private function candidates( array $payload ) {
		$totals = $payload['totals'];
		$total  = $totals['conversions'];
		$out    = array();

		if ( $total === 0 ) {
			// Never put "0 leads" in a subject line.
			return array( array(
				'tier'  => 'zero',
				'hook'  => 'nothing captured this week (let\'s fix it)',
				'short' => 'nothing captured (let\'s fix it)',
				'fact'  => 'A zero week is almost always a setup problem, not a quiet week.',
			) );
		}

		$history = $payload['week_history'];
		if ( count( $history ) >= 3 && $total > max( $history ) ) {
			$out[] = array(
				'tier'  => 'record',
				'hook'  => sprintf( 'your best week yet: %d conversions', $total ),
				'short' => 'your best week yet',
				'fact'  => sprintf( '%d conversions, your best week on record.', $total ),
			);
		}

		if ( $totals['trend_pct'] !== null && $totals['trend_pct'] >= 25 && $total >= 5 ) {
			$out[] = array(
				'tier'  => 'swing_up',
				'hook'  => sprintf( '%d conversions, up %d%%', $total, $totals['trend_pct'] ),
				'short' => sprintf( 'up %d%% this week', $totals['trend_pct'] ),
				'fact'  => sprintf( 'Conversions are up %d%% on last week.', $totals['trend_pct'] ),
			);
		}

		$untracked_pct = $total > 0 ? (int) round( 100 * $totals['untracked'] / $total ) : 0;
		if ( $untracked_pct >= 25 ) {
			$out[] = array(
				'tier'  => 'untracked',
				'hook'  => sprintf( '%d conversions came in blind', $totals['untracked'] ),
				'short' => sprintf( '%d untracked conversions', $totals['untracked'] ),
				'fact'  => sprintf( '%d%% of this week\'s conversions arrived with no source.', $untracked_pct ),
			);
		}

		foreach ( $payload['channels'] as $row ) {
			if ( $row['delta_pts'] !== null && $row['delta_pts'] >= 15 && $total >= 5 ) {
				$out[] = array(
					'tier'  => 'channel_breakout',
					'hook'  => sprintf( '%s jumped %d points', strtolower( $row['label'] ), $row['delta_pts'] ),
					'short' => sprintf( '%s is surging', strtolower( $row['label'] ) ),
					'fact'  => sprintf( '%s took %d%% of your leads, up %d points.', $row['label'], $row['pct'], $row['delta_pts'] ),
				);
				break;
			}
		}

		if ( ! empty( $payload['campaigns'] ) && $totals['tracked'] >= 5 ) {
			$top       = $payload['campaigns'][0];
			$runner_up = isset( $payload['campaigns'][1] ) ? $payload['campaigns'][1]['count'] : 0;
			$share     = (int) round( 100 * $top['count'] / $totals['tracked'] );
			// Same dominance rule as the body's standout sentence, so the subject never outruns it.
			if ( $share >= 40 && $top['count'] > $runner_up ) {
				$out[] = array(
					'tier'  => 'campaign_standout',
					'hook'  => sprintf( '"%s" drove %d%% of your leads', $top['name'], $share ),
					'short' => sprintf( 'one campaign drove %d%%', $share ),
					'fact'  => sprintf( '"%s" drove %d%% of everything you tracked.', $top['name'], $share ),
				);
			}
		}

		if ( $totals['trend_pct'] !== null && $totals['trend_pct'] <= -25 ) {
			$out[] = array(
				'tier'  => 'swing_down',
				'hook'  => sprintf( '%d conversions, down %d%%', $total, abs( $totals['trend_pct'] ) ),
				'short' => sprintf( 'down %d%% this week', abs( $totals['trend_pct'] ) ),
				'fact'  => sprintf( 'Conversions are down %d%% on last week.', abs( $totals['trend_pct'] ) ),
			);
		}

		$out[] = array(
			'tier'  => 'steady',
			'hook'  => sprintf( '%d conversion%s this week', $total, $total === 1 ? '' : 's' ),
			'short' => 'your week in numbers',
			'fact'  => sprintf( '%d conversions this week, %d%% with a source attached.', $total, $totals['tracked_pct'] ),
		);

		return $out;
	}

	/** Second-most-notable fact plus the one action — never a restated subject. */
	private function preheader( array $payload, array $candidates, $chosen_tier ) {
		$fact = '';
		foreach ( $candidates as $candidate ) {
			if ( $candidate['tier'] !== $chosen_tier ) {
				$fact = $candidate['fact'];
				break;
			}
		}
		if ( $fact === '' && ! empty( $payload['channels'] ) ) {
			$top  = $payload['channels'][0];
			$fact = sprintf( '%s led with %d%% of your conversions.', $top['label'], $top['pct'] );
		}

		$action = $payload['doctor']['all_clear']
			? 'Your tracking is healthy.'
			: 'The Tracking Doctor has this week\'s fix.';

		return trim( $fact . ' ' . $action );
	}

	private function length( $text ) {
		return function_exists( 'mb_strlen' ) ? mb_strlen( $text ) : strlen( $text );
	}
}
