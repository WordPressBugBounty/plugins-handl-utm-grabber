<?php
namespace Handl\UtmrabberFree\WeeklySnapshot;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Builds the frozen weekly payload: one object, computed once, that subject,
 * preheader and body all render from — so the numbers can never disagree.
 * Blends the form rollup with WooCommerce order meta (both windows are the
 * rolling 7 days ending today, site timezone).
 */
class Handl_Snapshot_Collector {

	const WINDOW_DAYS    = 7;
	const MAX_WOO_ORDERS = 300;   // per-window query cap; large stores stay cheap
	const LOW_DATA_MAX   = 4;

	/** @var Handl_Snapshot_Settings */
	private $settings;

	/** @var Handl_Snapshot_Rollup */
	private $rollup;

	/** @var Handl_Snapshot_Doctor */
	private $doctor;

	public function __construct( Handl_Snapshot_Settings $settings, Handl_Snapshot_Rollup $rollup, Handl_Snapshot_Doctor $doctor ) {
		$this->settings = $settings;
		$this->rollup   = $rollup;
		$this->doctor   = $doctor;
	}

	public function payload() {
		// Both windows end YESTERDAY so every bucket is a complete day: a 9am
		// send never under-reports "this week" against a full previous week.
		$end_date   = wp_date( 'Y-m-d', time() - DAY_IN_SECONDS );
		$start_date = wp_date( 'Y-m-d', strtotime( $end_date . ' 12:00:00' ) - ( self::WINDOW_DAYS - 1 ) * DAY_IN_SECONDS );

		$cur  = $this->rollup->aggregate( $end_date, self::WINDOW_DAYS );
		$prev = $this->rollup->aggregate(
			wp_date( 'Y-m-d', strtotime( $end_date . ' 12:00:00' ) - self::WINDOW_DAYS * DAY_IN_SECONDS ),
			self::WINDOW_DAYS
		);

		$woo      = $this->woo_summary( $start_date, $end_date );
		$woo_prev = $woo !== null
			? $this->woo_summary(
				wp_date( 'Y-m-d', strtotime( $start_date . ' 12:00:00' ) - self::WINDOW_DAYS * DAY_IN_SECONDS ),
				wp_date( 'Y-m-d', strtotime( $end_date . ' 12:00:00' ) - self::WINDOW_DAYS * DAY_IN_SECONDS )
			)
			: null;

		// Orders join every roll-up the forms feed: totals, channels, pages, campaigns.
		if ( $woo !== null ) {
			foreach ( Handl_Snapshot_Rollup::CHANNELS as $channel ) {
				$cur['channels'][ $channel ] += $woo['channels'][ $channel ];
			}
			$cur['ai_referral'] += $woo['ai_referral'];
			foreach ( array( 'landing_pages', 'campaigns', 'daily' ) as $list ) {
				foreach ( $woo[ $list ] as $key => $count ) {
					$cur[ $list ][ $key ] = ( isset( $cur[ $list ][ $key ] ) ? $cur[ $list ][ $key ] : 0 ) + $count;
				}
			}
		}

		$conversions = $cur['total'] + ( $woo !== null ? $woo['orders'] : 0 );
		$tracked     = $cur['tracked'] + ( $woo !== null ? $woo['attributed_orders'] : 0 );
		$prev_total  = $prev['total'] + ( $woo_prev !== null ? $woo_prev['orders'] : 0 );
		$prev_track  = $prev['tracked'] + ( $woo_prev !== null ? $woo_prev['attributed_orders'] : 0 );

		$tracked_pct      = $conversions > 0 ? (int) round( 100 * $tracked / $conversions ) : 0;
		$prev_tracked_pct = $prev_total > 0 ? (int) round( 100 * $prev_track / $prev_total ) : null;

		$checks = $this->run_checks();

		$totals = array(
			'conversions' => $conversions,
			'tracked'     => $tracked,
			'untracked'   => $conversions - $tracked,
			'tracked_pct' => $tracked_pct,
			'trend_pct'   => $prev_total > 0 ? (int) round( 100 * ( $conversions - $prev_total ) / $prev_total ) : null,
			'tracked_pts' => $prev_tracked_pct !== null && $conversions > 0 ? $tracked_pct - $prev_tracked_pct : null,
			'best_day'    => $this->best_day_label( $cur['daily'] ),
		);

		$doctor = $this->doctor->diagnose( array(
			'totals'        => $totals,
			'channels'      => $cur['channels'],
			'woo'           => $woo,
			'forms_check'   => $checks['forms'],
			'caching_check' => $checks['caching'],
		) );

		$data_state = 'normal';
		if ( $conversions === 0 ) {
			$data_state = 'zero';
		} elseif ( $conversions <= self::LOW_DATA_MAX ) {
			$data_state = 'low';
		}

		return array(
			'site'          => array(
				'domain'     => $this->settings->domain(),
				'prefix'     => $this->settings->subject_prefix(),
				'manage_url' => $this->settings->manage_url(),
			),
			'week'          => array(
				'start' => $start_date,
				'end'   => $end_date,
				'label' => $this->week_label( $start_date, $end_date ),
			),
			'totals'        => $totals,
			'channels'      => $this->channel_rows( $cur['channels'], $prev['channels'], $woo_prev ),
			'ai_referral'   => $cur['ai_referral'],
			'forms'         => $this->forms_section( $cur['forms'], $checks['forms'] ),
			'landing_pages' => $this->top_rows( $cur['landing_pages'], 4 ),
			'campaigns'     => $this->campaign_rows( $cur['campaigns'], $prev['campaigns'], $woo_prev ),
			'woocommerce'   => $woo,
			'doctor'        => $doctor,
			'data_state'    => $data_state,
			'greeting_name' => $this->greeting_name(),
			'leads_alltime' => $this->rollup->leads_alltime(),
			'week_history'  => $this->rollup->week_history(),
			'generated_at'  => gmdate( 'c' ),
		);
	}

	/** Forms_Check and Caching_Check, run headless — the same rules the Doctor page reads. */
	private function run_checks() {
		$base = dirname( __DIR__ ) . '/tracking-doctor';
		require_once $base . '/class-doctor-check.php';
		require_once $base . '/checks/class-forms-check.php';
		require_once $base . '/checks/class-caching-check.php';

		return array(
			'forms'   => ( new \Handl\UtmrabberFree\TrackingDoctor\Forms_Check( null ) )->run(),
			'caching' => ( new \Handl\UtmrabberFree\TrackingDoctor\Caching_Check() )->run(),
		);
	}

	/** @return array|null Null when WooCommerce is inactive. */
	private function woo_summary( $start_date, $end_date ) {
		if ( ! function_exists( 'wc_get_orders' ) ) {
			return null;
		}

		$timezone = wp_timezone();
		$start    = new \DateTimeImmutable( $start_date . ' 00:00:00', $timezone );
		$end      = new \DateTimeImmutable( $end_date . ' 23:59:59', $timezone );

		$orders = wc_get_orders( array(
			'limit'        => self::MAX_WOO_ORDERS,
			'status'       => array( 'wc-completed', 'wc-processing', 'wc-on-hold' ),
			'date_created' => $start->getTimestamp() . '...' . $end->getTimestamp(),
			'orderby'      => 'date',
			'order'        => 'DESC',
		) );
		if ( ! is_array( $orders ) ) {
			$orders = array();
		}

		$summary = array(
			'orders'            => 0,
			'total_value'       => 0.0,
			'attributed_value'  => 0.0,
			'attributed_orders' => 0,
			'channels'          => array_fill_keys( Handl_Snapshot_Rollup::CHANNELS, 0 ),
			'ai_referral'       => 0,
			'landing_pages'     => array(),
			'campaigns'         => array(),
			'daily'             => array(),
			'products'          => array(),
			'capped'            => count( $orders ) >= self::MAX_WOO_ORDERS,
			'currency'          => function_exists( 'get_woocommerce_currency_symbol' )
				? html_entity_decode( get_woocommerce_currency_symbol(), ENT_QUOTES, 'UTF-8' )
				: '$',
		);

		$meta_keys = array( 'utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content', 'gclid', 'handl_original_ref', 'handl_landing_page' );

		foreach ( $orders as $order ) {
			$posted = array();
			foreach ( $meta_keys as $key ) {
				$posted[ $key ] = (string) $order->get_meta( $key, true );
			}
			$analysis = Handl_Snapshot_Rollup::classify( $posted );
			$total    = (float) $order->get_total();

			++$summary['orders'];
			$summary['total_value'] += $total;
			if ( $analysis['tracked'] ) {
				++$summary['attributed_orders'];
				$summary['attributed_value'] += $total;
			}
			++$summary['channels'][ $analysis['channel'] ];
			if ( $analysis['ai'] ) {
				++$summary['ai_referral'];
			}

			$campaign = trim( $posted['utm_campaign'] );
			if ( $campaign !== '' ) {
				$summary['campaigns'][ $campaign ] = ( isset( $summary['campaigns'][ $campaign ] ) ? $summary['campaigns'][ $campaign ] : 0 ) + 1;
			}
			$path = $posted['handl_landing_page'] !== '' ? wp_parse_url( $posted['handl_landing_page'], PHP_URL_PATH ) : null;
			if ( is_string( $path ) && $path !== '' ) {
				$path                              = '/' . ltrim( $path, '/' );
				$summary['landing_pages'][ $path ] = ( isset( $summary['landing_pages'][ $path ] ) ? $summary['landing_pages'][ $path ] : 0 ) + 1;
			}

			$created = $order->get_date_created();
			if ( $created ) {
				$date                       = wp_date( 'Y-m-d', $created->getTimestamp() );
				$summary['daily'][ $date ]  = ( isset( $summary['daily'][ $date ] ) ? $summary['daily'][ $date ] : 0 ) + 1;
			}

			foreach ( $order->get_items() as $item ) {
				$name = $item->get_name();
				if ( $name === '' ) {
					continue;
				}
				if ( ! isset( $summary['products'][ $name ] ) ) {
					$summary['products'][ $name ] = array( 'orders' => 0, 'revenue' => 0.0 );
				}
				++$summary['products'][ $name ]['orders'];
				$summary['products'][ $name ]['revenue'] += (float) $item->get_total();
			}
		}

		$summary['average_order'] = $summary['orders'] > 0 ? $summary['total_value'] / $summary['orders'] : 0.0;
		$summary['attributed_pct'] = $summary['total_value'] > 0
			? (int) round( 100 * $summary['attributed_value'] / $summary['total_value'] )
			: 0;

		uasort( $summary['products'], function ( $a, $b ) {
			if ( $a['revenue'] === $b['revenue'] ) {
				return 0;
			}
			return $b['revenue'] > $a['revenue'] ? 1 : -1;
		} );
		$summary['products'] = array_slice( $summary['products'], 0, 4, true );

		return $summary;
	}

	/** @return array[] {channel, label, count, pct, delta_pts|null} ordered by share, zero slices dropped. */
	private function channel_rows( array $channels, array $prev_channels, $woo_prev ) {
		$labels = array(
			'paid'     => 'Paid ads',
			'organic'  => 'Organic',
			'social'   => 'Social',
			'referral' => 'Referral',
			'direct'   => 'Direct',
		);

		if ( is_array( $woo_prev ) ) {
			foreach ( Handl_Snapshot_Rollup::CHANNELS as $channel ) {
				$prev_channels[ $channel ] += $woo_prev['channels'][ $channel ];
			}
		}

		$total      = array_sum( $channels );
		$prev_total = array_sum( $prev_channels );
		$rows       = array();

		foreach ( $labels as $channel => $label ) {
			$count = (int) $channels[ $channel ];
			if ( $count === 0 ) {
				continue;
			}
			$pct      = (int) round( 100 * $count / $total );
			$prev_pct = $prev_total > 0 ? (int) round( 100 * $prev_channels[ $channel ] / $prev_total ) : null;
			$rows[]   = array(
				'channel'   => $channel,
				'label'     => $label,
				'count'     => $count,
				'pct'       => $pct,
				'delta_pts' => $prev_pct !== null ? $pct - $prev_pct : null,
			);
		}

		usort( $rows, function ( $a, $b ) {
			return $b['count'] - $a['count'];
		} );
		return $rows;
	}

	/** @return array{total:int,form_count:int,rows:array} rows = top forms across all plugins. */
	private function forms_section( array $forms, array $forms_check ) {
		$labels = array();
		if ( isset( $forms_check['details']['scanned_plugins'] ) ) {
			foreach ( $forms_check['details']['scanned_plugins'] as $plugin ) {
				$labels[ $plugin['slug'] ] = $plugin['label'];
			}
		}

		$total      = 0;
		$form_count = 0;
		$rows       = array();
		foreach ( $forms as $slug => $bucket ) {
			$total += (int) $bucket['total'];
			$label  = isset( $labels[ $slug ] ) ? $labels[ $slug ] : ucwords( str_replace( '-', ' ', $slug ) );
			foreach ( $bucket['forms'] as $form_id => $form ) {
				++$form_count;
				$rows[] = array(
					'title' => $form['title'] !== '' ? $form['title'] : $label . ' #' . $form_id,
					'count' => (int) $form['count'],
				);
			}
		}

		usort( $rows, function ( $a, $b ) {
			return $b['count'] - $a['count'];
		} );

		return array(
			'total'      => $total,
			'form_count' => $form_count,
			'rows'       => array_slice( $rows, 0, 5 ),
		);
	}

	/** @return array[] {name, count, delta|null} top campaigns with week-over-week movement. */
	private function campaign_rows( array $campaigns, array $prev_campaigns, $woo_prev ) {
		if ( is_array( $woo_prev ) ) {
			foreach ( $woo_prev['campaigns'] as $name => $count ) {
				$prev_campaigns[ $name ] = ( isset( $prev_campaigns[ $name ] ) ? $prev_campaigns[ $name ] : 0 ) + $count;
			}
		}

		arsort( $campaigns );
		$rows = array();
		foreach ( array_slice( $campaigns, 0, 3, true ) as $name => $count ) {
			$rows[] = array(
				'name'  => (string) $name,
				'count' => (int) $count,
				'delta' => isset( $prev_campaigns[ $name ] ) ? (int) $count - (int) $prev_campaigns[ $name ] : null,
			);
		}
		return $rows;
	}

	/** @return array[] {label, count} */
	private function top_rows( array $map, $limit ) {
		arsort( $map );
		$rows = array();
		foreach ( array_slice( $map, 0, $limit, true ) as $label => $count ) {
			$rows[] = array(
				'label' => (string) $label,
				'count' => (int) $count,
			);
		}
		return $rows;
	}

	private function best_day_label( array $daily ) {
		if ( empty( $daily ) ) {
			return '';
		}
		arsort( $daily );
		reset( $daily );
		// Keys are already site-local dates; format without a second tz conversion.
		return gmdate( 'l', strtotime( (string) key( $daily ) ) );
	}

	private function week_label( $start_date, $end_date ) {
		$start_ts = strtotime( $start_date . ' 12:00:00' );
		$end_ts   = strtotime( $end_date . ' 12:00:00' );
		return sprintf(
			'Week of %s-%s, %s',
			date_i18n( 'M j', $start_ts ),
			date_i18n( 'M j', $end_ts ),
			date_i18n( 'Y', $end_ts )
		);
	}

	private function greeting_name() {
		$settings = $this->settings->get();
		$user     = get_user_by( 'email', $settings['recipient'] );
		if ( $user && $user->first_name !== '' ) {
			return $user->first_name;
		}
		return '';
	}
}
