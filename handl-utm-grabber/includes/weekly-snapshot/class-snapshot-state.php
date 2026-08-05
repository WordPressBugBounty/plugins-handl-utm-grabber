<?php
namespace Handl\UtmrabberFree\WeeklySnapshot;

if ( ! defined( 'ABSPATH' ) ) exit;

/** Runtime send state (not user settings): tier memory, zero-week throttle, opens, errors. */
class Handl_Snapshot_State {

	const OPTION = 'handl_weekly_snapshot_state';

	/**
	 * @return array{
	 *   last_sent_at:string, last_tiers:string[], zero_streak:int,
	 *   last_zero_sent_at:string, review_last_ask_at:string, last_error:string
	 * }
	 */
	public function get() {
		$state = get_option( self::OPTION, array() );
		if ( ! is_array( $state ) ) {
			$state = array();
		}
		return array_merge(
			array(
				'last_sent_at'          => '',
				'last_tiers'            => array(),
				'zero_streak'           => 0,
				'zero_sends'            => 0,
				'last_zero_sent_at'     => '',
				'review_last_ask_at'    => '',
				'review_last_milestone' => 0,
				'last_error'            => '',
			),
			$state
		);
	}

	public function update( array $changes ) {
		$state = array_merge( $this->get(), $changes );
		update_option( self::OPTION, $state, false );
		return $state;
	}

	/** Remember a sent subject tier; only the last 3 matter for rotation. */
	public function push_tier( $tier ) {
		$state   = $this->get();
		$tiers   = $state['last_tiers'];
		$tiers[] = (string) $tier;
		$this->update( array( 'last_tiers' => array_slice( $tiers, -3 ) ) );
	}
}
