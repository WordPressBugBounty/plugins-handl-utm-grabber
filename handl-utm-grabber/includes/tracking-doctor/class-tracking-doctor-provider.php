<?php
namespace Handl\UtmrabberFree\TrackingDoctor;

if ( ! defined( 'ABSPATH' ) ) exit;

/** Scan lifecycle: run/persist/merge scans, staleness, and cached AI reviews. Stateless. */
class Handl_Tracking_Doctor_Provider {

	const LAST_SCAN_OPTION = 'handl_tracking_doctor_last_scan';
	const STALE_AFTER_DAYS = 30;

	/** @var Handl_Tracking_Doctor_Audit */
	private $audit;

	public function __construct( Handl_Tracking_Doctor_Audit $audit ) {
		$this->audit = $audit;
	}

	/** Full audit, persisted as the last scan. */
	public function run_scan() {
		$payload = array_merge(
			$this->audit->run(),
			array(
				'ai'             => Handl_Tracking_Doctor_AI::availability(),
				'scanned_at'     => gmdate( 'c' ),
				'plugin_version' => defined( 'HANDL_UTM_GRABBER_FREE_VERSION' ) ? HANDL_UTM_GRABBER_FREE_VERSION : '',
				'is_stale'       => false,
			)
		);

		$this->save( $payload );
		return $payload;
	}

	/** Saved scan with fresh AI availability and staleness, or null when none/unusable. */
	public function last_scan() {
		$saved = get_option( self::LAST_SCAN_OPTION, null );
		if ( ! is_array( $saved ) || empty( $saved['checks'] ) || ! is_array( $saved['checks'] ) ) {
			return null;
		}

		// AI connector status can change without re-running the audit.
		$saved['ai']       = Handl_Tracking_Doctor_AI::availability();
		$saved['is_stale'] = $this->is_scan_stale( isset( $saved['scanned_at'] ) ? (string) $saved['scanned_at'] : '' );

		return $saved;
	}

	/** Re-run one check and merge it into the saved scan. @return array|\WP_Error */
	public function recheck( $check_id ) {
		$new_check = $this->audit->run_check( $check_id );
		if ( is_wp_error( $new_check ) ) {
			return $new_check;
		}

		$saved = $this->last_scan();
		if ( $saved === null ) {
			return $this->run_scan();
		}

		$found = false;
		foreach ( $saved['checks'] as $index => $check ) {
			if ( isset( $check['id'] ) && (string) $check['id'] === $new_check['id'] ) {
				// Drop the old AI review — it described the old audit context.
				// Re-reviewing an unchanged setup is served from the transient cache.
				$saved['checks'][ $index ] = $new_check;
				$found                     = true;
				break;
			}
		}
		if ( ! $found ) {
			$saved['checks'][] = $new_check;
		}

		return $this->refresh_and_save( $saved );
	}

	/**
	 * AI review for one check, cached by audit-context hash; result is attached
	 * to the saved scan. @return array{review:array,scan:array}|\WP_Error
	 */
	public function review( $category ) {
		$ai = Handl_Tracking_Doctor_AI::availability();
		if ( ! $ai['available'] ) {
			return new \WP_Error( 'ai_unavailable', 'Connect an AI provider in WordPress settings to run AI reviews.' );
		}

		$context = $this->audit->context_for_category( $category );
		if ( is_wp_error( $context ) ) {
			return $context;
		}

		// Persisting a skip would hide the card.
		if ( isset( $context['check']['status'] ) && $context['check']['status'] === 'skip' ) {
			return new \WP_Error( 'not_applicable', 'This check does not apply to the current site setup.' );
		}

		$review = $this->cached_review( $category, $context );
		if ( $review === null ) {
			return new \WP_Error( 'ai_error', 'Check with AI failed. Please try again.' );
		}

		return array(
			'review' => $review,
			'scan'   => $this->apply_review_to_scan( $category, $review, $context['check'] ),
		);
	}

	/** Same transient scheme as Handl_Insight_Provider: unchanged audit data never re-bills. */
	private function cached_review( $category, array $context ) {
		$key    = 'handl_ai_' . $this->cache_key( $context );
		$cached = get_transient( $key );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		$review = Handl_Tracking_Doctor_AI::review( $category, $context );
		if ( is_array( $review ) ) {
			set_transient( $key, $review, WEEK_IN_SECONDS );
		}
		return $review;
	}

	/** Canonical context hash: key-sorted at every depth so identical audits hash identically. */
	private function cache_key( array $context ) {
		$context = $this->ksort_deep( $context );
		return md5( Handl_Tracking_Doctor_AI::PROMPT_VERSION . wp_json_encode( $context ) );
	}

	private function ksort_deep( array $value ) {
		ksort( $value );
		foreach ( $value as $key => $item ) {
			if ( is_array( $item ) ) {
				$value[ $key ] = $this->ksort_deep( $item );
			}
		}
		return $value;
	}

	/** Attach the review to the fresh check it audited; only a critical finding downgrades a pass. */
	private function apply_review_to_scan( $category, array $review, array $fresh_check ) {
		$saved = $this->last_scan();
		if ( $saved === null ) {
			$saved = $this->run_scan();
		}

		$severity = $this->worst_severity( $review['findings'] );

		$fresh_check['ai_review'] = array_merge( $review, array( 'reviewed_at' => gmdate( 'c' ) ) );

		if ( isset( $fresh_check['status'] ) && $fresh_check['status'] === 'pass' && $review['summary'] !== '' && $severity === 'critical' ) {
			$fresh_check['status']  = 'fail';
			$fresh_check['message'] = $review['summary'];
		}

		$found = false;
		foreach ( $saved['checks'] as $index => $check ) {
			if ( isset( $check['id'] ) && (string) $check['id'] === (string) $category ) {
				$saved['checks'][ $index ] = $fresh_check;
				$found                     = true;
				break;
			}
		}
		if ( ! $found ) {
			$saved['checks'][] = $fresh_check;
		}

		return $this->refresh_and_save( $saved );
	}

	/** Recompute rollups + freshness on a mutated scan, persist, and return it. */
	private function refresh_and_save( array $saved ) {
		$saved['summary']        = Handl_Tracking_Doctor_Audit::summarize_checks( $saved['checks'] );
		$saved['ai']             = Handl_Tracking_Doctor_AI::availability();
		$saved['scanned_at']     = gmdate( 'c' );
		$saved['is_stale']       = false;
		$saved['plugin_version'] = defined( 'HANDL_UTM_GRABBER_FREE_VERSION' ) ? HANDL_UTM_GRABBER_FREE_VERSION : '';

		$this->save( $saved );
		return $saved;
	}

	private function save( array $payload ) {
		update_option( self::LAST_SCAN_OPTION, $payload, false );
	}

	/** @return string good|warning|critical|none */
	private function worst_severity( array $findings ) {
		$worst = 'none';
		foreach ( $findings as $finding ) {
			if ( ! is_array( $finding ) || empty( $finding['severity'] ) ) {
				continue;
			}
			if ( $finding['severity'] === 'critical' ) {
				return 'critical';
			}
			if ( $finding['severity'] === 'warning' ) {
				$worst = 'warning';
			} elseif ( $finding['severity'] === 'good' && $worst === 'none' ) {
				$worst = 'good';
			}
		}
		return $worst;
	}

	private function is_scan_stale( $scanned_at ) {
		if ( $scanned_at === '' ) {
			return false;
		}
		$timestamp = strtotime( $scanned_at );
		if ( false === $timestamp ) {
			return false;
		}
		return $timestamp <= time() - ( self::STALE_AFTER_DAYS * DAY_IN_SECONDS );
	}
}
