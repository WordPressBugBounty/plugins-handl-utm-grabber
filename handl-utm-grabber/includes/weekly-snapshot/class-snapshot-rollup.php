<?php
namespace Handl\UtmrabberFree\WeeklySnapshot;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Aggregate-only store for the weekly digest, fed by the `handl_utm_submission`
 * action. Keeps per-day counters (site timezone) — never full entries — so the
 * weekly email is one option read on sites of any size. The existing
 * handl_recent_leads ring buffer (5 per plugin) cannot answer weekly counts.
 */
class Handl_Snapshot_Rollup {

	const OPTION         = 'handl_weekly_snapshot_stats';
	const RETENTION_DAYS = 16;   // current week + comparison week + timezone slop
	const MAX_LIST_KEYS  = 40;   // per-day cap for landing page / campaign maps
	const MAX_WEEKS      = 52;   // weekly send history, for record-week detection

	const CHANNELS = array( 'paid', 'organic', 'social', 'referral', 'direct' );

	/** Same list as Handl_Insight_Engine::$paid_media so "paid" means one thing everywhere. */
	const PAID_MEDIA = array( 'cpc', 'ppc', 'paid', 'paidsearch', 'cpm', 'display' );

	/** Bare labels match any TLD ((^|.)label.); dotted entries match the exact host or a subdomain. */
	const SEARCH_HOSTS = array( 'google', 'bing', 'duckduckgo', 'yahoo', 'ecosia', 'baidu', 'yandex' );
	const SOCIAL_HOSTS = array( 'facebook.com', 'instagram.com', 'twitter.com', 'x.com', 't.co', 'linkedin.com', 'tiktok.com', 'pinterest.com', 'youtube.com', 'youtu.be', 'reddit.com', 'threads.net' );
	const AI_HOSTS     = array( 'chat.openai.com', 'chatgpt.com', 'claude.ai', 'perplexity.ai', 'gemini.google.com', 'copilot.microsoft.com', 'you.com', 'poe.com', 'phind.com' );

	const MAX_KEY_LENGTH = 100;   // campaign names / paths are visitor-controlled; cap stored key size

	/**
	 * handl_utm_submission consumer. Ignores events with an empty posted map —
	 * no mapped fields means the form is not wired, which is the Doctor's job
	 * to report, not a countable conversion.
	 *
	 * @param array $event { integration, form_id, posted, submission_id, form_title }
	 */
	public function record( $event ) {
		if ( ! is_array( $event ) || empty( $event['posted'] ) || ! is_array( $event['posted'] ) ) {
			return;
		}
		$slug = isset( $event['integration'] ) ? (string) $event['integration'] : '';
		if ( $slug === '' ) {
			return;
		}

		$posted   = array_map( 'strval', $event['posted'] );
		$analysis = self::classify( $posted );

		$stats = $this->stats();
		$today = wp_date( 'Y-m-d' );

		$day = isset( $stats['days'][ $today ] ) ? $stats['days'][ $today ] : $this->empty_day();

		++$day['total'];
		if ( $analysis['tracked'] ) {
			++$day['tracked'];
			$stats['leads_alltime'] = (int) $stats['leads_alltime'] + 1;
		}
		++$day['channels'][ $analysis['channel'] ];
		if ( $analysis['ai'] ) {
			++$day['ai_referral'];
		}

		$form_id = isset( $event['form_id'] ) ? (string) $event['form_id'] : '';
		if ( ! isset( $day['forms'][ $slug ] ) ) {
			$day['forms'][ $slug ] = array( 'total' => 0, 'tracked' => 0, 'forms' => array() );
		}
		++$day['forms'][ $slug ]['total'];
		if ( $analysis['tracked'] ) {
			++$day['forms'][ $slug ]['tracked'];
		}
		if ( $form_id !== '' ) {
			if ( ! isset( $day['forms'][ $slug ]['forms'][ $form_id ] ) ) {
				$day['forms'][ $slug ]['forms'][ $form_id ] = array(
					'title' => isset( $event['form_title'] ) ? (string) $event['form_title'] : '',
					'count' => 0,
				);
			}
			++$day['forms'][ $slug ]['forms'][ $form_id ]['count'];
		}

		$path = $this->landing_path( isset( $posted['handl_landing_page'] ) ? $posted['handl_landing_page'] : '' );
		if ( $path !== '' ) {
			$day['landing_pages'] = $this->bump_capped( $day['landing_pages'], $path );
		}
		$campaign = isset( $posted['utm_campaign'] ) ? trim( $posted['utm_campaign'] ) : '';
		if ( $campaign !== '' ) {
			$day['campaigns'] = $this->bump_capped( $day['campaigns'], $campaign );
		}

		$stats['days'][ $today ] = $day;
		$this->save( $this->prune( $stats ) );
	}

	/**
	 * Channel per Haktan's canonical five. AI chat referrers are not a sixth
	 * slice: they count inside referral and are surfaced via the `ai` flag.
	 * Tagged-but-unpaid mediums (email, newsletter, …) land in referral so
	 * campaign traffic is never mistaken for direct.
	 *
	 * @return array{channel:string,ai:bool,tracked:bool}
	 */
	public static function classify( array $posted ) {
		$get = function ( $key ) use ( $posted ) {
			return isset( $posted[ $key ] ) ? trim( (string) $posted[ $key ] ) : '';
		};

		$medium   = strtolower( $get( 'utm_medium' ) );
		$source   = strtolower( $get( 'utm_source' ) );
		$referrer = $get( 'handl_original_ref' );
		$has_utm  = $get( 'utm_source' ) !== '' || $get( 'utm_medium' ) !== '' || $get( 'utm_campaign' ) !== ''
			|| $get( 'utm_term' ) !== '' || $get( 'utm_content' ) !== '';
		$tracked  = $has_utm || $get( 'gclid' ) !== '';

		$ref_host = '';
		if ( $referrer !== '' ) {
			$host     = wp_parse_url( $referrer, PHP_URL_HOST );
			$ref_host = is_string( $host ) ? strtolower( $host ) : '';
			$own      = wp_parse_url( home_url(), PHP_URL_HOST );
			if ( is_string( $own ) && $ref_host === strtolower( $own ) ) {
				$ref_host = '';   // own-domain referrer is not an acquisition source
			}
		}

		$is_ai = $ref_host !== '' && self::host_matches( $ref_host, self::AI_HOSTS );

		$social_sources = array( 'facebook', 'instagram', 'twitter', 'x', 'linkedin', 'tiktok', 'pinterest', 'youtube', 'reddit' );

		if ( $get( 'gclid' ) !== '' || in_array( $medium, self::PAID_MEDIA, true ) ) {
			return array( 'channel' => 'paid', 'ai' => false, 'tracked' => true );
		}
		// Before organic: gemini.google.com must not be swallowed by the google search rule.
		if ( $is_ai ) {
			return array( 'channel' => 'referral', 'ai' => true, 'tracked' => $tracked );
		}
		if ( $medium === 'social' || in_array( $source, $social_sources, true )
			|| ( $ref_host !== '' && self::host_matches( $ref_host, self::SOCIAL_HOSTS ) ) ) {
			return array( 'channel' => 'social', 'ai' => false, 'tracked' => $tracked );
		}
		if ( $medium === 'organic' || ( $ref_host !== '' && self::host_matches( $ref_host, self::SEARCH_HOSTS ) ) ) {
			return array( 'channel' => 'organic', 'ai' => false, 'tracked' => $tracked );
		}
		if ( $ref_host !== '' || $has_utm ) {
			return array( 'channel' => 'referral', 'ai' => false, 'tracked' => $tracked );
		}
		return array( 'channel' => 'direct', 'ai' => false, 'tracked' => false );
	}

	/**
	 * Sum the day buckets for consecutive dates ending on $end_date inclusive.
	 *
	 * @param string $end_date Y-m-d in site timezone.
	 * @param int    $days     Window length.
	 * @return array The empty_day() shape plus `daily` (date => count).
	 */
	public function aggregate( $end_date, $days ) {
		$stats          = $this->stats();
		$total          = $this->empty_day();
		$total['daily'] = array();

		$cursor = strtotime( $end_date . ' 12:00:00' );
		for ( $i = 0; $i < $days; $i++ ) {
			$date = gmdate( 'Y-m-d', $cursor - $i * DAY_IN_SECONDS );
			if ( ! isset( $stats['days'][ $date ] ) ) {
				continue;
			}
			$day = $stats['days'][ $date ];

			$total['daily'][ $date ] = (int) $day['total'];

			$total['total']       += (int) $day['total'];
			$total['tracked']     += (int) $day['tracked'];
			$total['ai_referral'] += (int) $day['ai_referral'];
			foreach ( self::CHANNELS as $channel ) {
				$total['channels'][ $channel ] += (int) $day['channels'][ $channel ];
			}
			foreach ( array( 'landing_pages', 'campaigns' ) as $list ) {
				foreach ( $day[ $list ] as $key => $count ) {
					$total[ $list ][ $key ] = ( isset( $total[ $list ][ $key ] ) ? $total[ $list ][ $key ] : 0 ) + (int) $count;
				}
			}
			foreach ( $day['forms'] as $slug => $bucket ) {
				if ( ! isset( $total['forms'][ $slug ] ) ) {
					$total['forms'][ $slug ] = array( 'total' => 0, 'tracked' => 0, 'forms' => array() );
				}
				$total['forms'][ $slug ]['total']   += (int) $bucket['total'];
				$total['forms'][ $slug ]['tracked'] += (int) $bucket['tracked'];
				foreach ( $bucket['forms'] as $form_id => $form ) {
					if ( ! isset( $total['forms'][ $slug ]['forms'][ $form_id ] ) ) {
						$total['forms'][ $slug ]['forms'][ $form_id ] = array( 'title' => $form['title'], 'count' => 0 );
					}
					$total['forms'][ $slug ]['forms'][ $form_id ]['count'] += (int) $form['count'];
				}
			}
		}

		return $total;
	}

	public function leads_alltime() {
		$stats = $this->stats();
		return (int) $stats['leads_alltime'];
	}

	/**
	 * The rollup's own birthday (site-timezone Y-m-d), stamped the first time
	 * the option is touched. The honest epoch for "watching for {days} days"
	 * copy: install-date options predate the rollup, so day counts against
	 * them would pair a long lifetime with a short observation window.
	 */
	public function first_seen() {
		$stats = $this->stats();
		return (string) $stats['first_seen'];
	}

	/** @return int Whole days since first_seen (site timezone); -1 when no epoch exists. */
	public function first_seen_age_days() {
		$epoch = $this->first_seen();
		if ( $epoch === '' ) {
			return -1;
		}
		return (int) floor( ( strtotime( wp_date( 'Y-m-d' ) . ' 12:00:00' ) - strtotime( $epoch . ' 12:00:00' ) ) / DAY_IN_SECONDS );
	}

	/** @return string Y-m-d of the oldest retained day bucket with a tracked lead, or ''. */
	public function earliest_tracked_day() {
		$stats = $this->stats();
		$dates = array_keys( $stats['days'] );
		sort( $dates );
		foreach ( $dates as $date ) {
			if ( ! empty( $stats['days'][ $date ]['tracked'] ) ) {
				return $date;
			}
		}
		return '';
	}

	/** @return int[] Past weekly conversion totals recorded at send time, oldest first. */
	public function week_history() {
		$stats = $this->stats();
		return array_map( 'intval', array_values( $stats['weeks'] ) );
	}

	/** Called by the mailer after each real send so record weeks are detectable. */
	public function record_week( $send_date, $conversions ) {
		$stats = $this->stats();
		$stats['weeks'][ (string) $send_date ] = (int) $conversions;
		if ( count( $stats['weeks'] ) > self::MAX_WEEKS ) {
			$stats['weeks'] = array_slice( $stats['weeks'], -self::MAX_WEEKS, null, true );
		}
		$this->save( $stats );
	}

	private function stats() {
		$stats = get_option( self::OPTION, array() );
		if ( ! is_array( $stats ) ) {
			$stats = array();
		}
		$stats = array_merge(
			array( 'days' => array(), 'weeks' => array(), 'leads_alltime' => 0, 'first_seen' => '' ),
			$stats
		);
		// One-time epoch stamp; pre-existing installs get it on their first touch after upgrade.
		if ( $stats['first_seen'] === '' ) {
			$stats['first_seen'] = wp_date( 'Y-m-d' );
			$this->save( $stats );
		}
		return $stats;
	}

	private function save( array $stats ) {
		update_option( self::OPTION, $stats, false );
	}

	private function empty_day() {
		return array(
			'total'         => 0,
			'tracked'       => 0,
			'channels'      => array_fill_keys( self::CHANNELS, 0 ),
			'ai_referral'   => 0,
			'forms'         => array(),
			'landing_pages' => array(),
			'campaigns'     => array(),
		);
	}

	private function prune( array $stats ) {
		$cutoff = wp_date( 'Y-m-d', time() - self::RETENTION_DAYS * DAY_IN_SECONDS );
		foreach ( array_keys( $stats['days'] ) as $date ) {
			if ( $date < $cutoff ) {
				unset( $stats['days'][ $date ] );
			}
		}
		return $stats;
	}

	/** Increment a counter map; when full, only existing keys keep counting. */
	private function bump_capped( array $map, $key ) {
		$key = function_exists( 'mb_substr' ) ? mb_substr( $key, 0, self::MAX_KEY_LENGTH ) : substr( $key, 0, self::MAX_KEY_LENGTH );
		if ( ! isset( $map[ $key ] ) && count( $map ) >= self::MAX_LIST_KEYS ) {
			return $map;
		}
		$map[ $key ] = ( isset( $map[ $key ] ) ? $map[ $key ] : 0 ) + 1;
		return $map;
	}

	private function landing_path( $url ) {
		$url = trim( (string) $url );
		if ( $url === '' ) {
			return '';
		}
		$path = wp_parse_url( $url, PHP_URL_PATH );
		if ( ! is_string( $path ) || $path === '' ) {
			return '/';
		}
		return '/' . ltrim( $path, '/' );
	}

	private static function host_matches( $host, array $needles ) {
		foreach ( $needles as $needle ) {
			if ( strpos( $needle, '.' ) !== false ) {
				if ( $host === $needle || substr( $host, -strlen( '.' . $needle ) ) === '.' . $needle ) {
					return true;
				}
			} elseif ( preg_match( '/(^|\.)' . preg_quote( $needle, '/' ) . '\./', $host ) ) {
				return true;
			}
		}
		return false;
	}
}
