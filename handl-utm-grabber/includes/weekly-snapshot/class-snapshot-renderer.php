<?php
namespace Handl\UtmrabberFree\WeeklySnapshot;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Renders the Weekly Snapshot HTML from the frozen payload. Table layout,
 * inline styles, images from img/email/ on the user's own site. Sections are
 * fluid: each renders only when its data exists. The mockup's CSS-blur premium
 * tease is not email-safe, so locked panels render as clean locked cards.
 */
class Handl_Snapshot_Renderer {

	const BLUE   = '#0160BF';
	const INK    = '#141414';
	const BODY   = '#4E4E4E';
	const MUTED  = '#6F7680';
	const BORDER = '#E5E7EB';
	const GREEN  = '#16A34A';
	const AMBER  = '#F59E0B';
	const RED    = '#DC2626';

	const CHANNEL_COLORS = array(
		'paid'     => '#0160BF',
		'organic'  => '#0E9AA0',
		'social'   => '#7C3AED',
		'referral' => '#DB2777',
		'direct'   => '#9AA3AF',
	);

	const FONT       = "'Helvetica Neue',Helvetica,Arial,sans-serif";
	const FONT_NUM   = "'Outfit','Helvetica Neue',Helvetica,Arial,sans-serif";
	const FONT_SERIF = "Georgia,'Times New Roman',serif";

	/** @var Handl_Snapshot_Settings */
	private $settings;

	public function __construct( Handl_Snapshot_Settings $settings ) {
		$this->settings = $settings;
	}

	/**
	 * @param array $payload Handl_Snapshot_Collector::payload().
	 * @param array $compose Handl_Snapshot_Composer::compose().
	 * @param array $opts    { is_test?: bool, review_milestone?: int|null }
	 */
	public function render( array $payload, array $compose, array $opts = array() ) {
		$is_zero = $payload['data_state'] === 'zero';

		$body  = $is_zero ? $this->zero_sections( $payload ) : $this->normal_sections( $payload, $opts );
		$title = 'The Weekly Snapshot';

		return '<!DOCTYPE html>'
			. '<html lang="en"><head>'
			. '<meta charset="UTF-8">'
			. '<meta name="viewport" content="width=device-width, initial-scale=1.0">'
			. '<meta name="x-apple-disable-message-reformatting">'
			. '<meta name="color-scheme" content="light">'
			. '<title>' . esc_html( $title ) . '</title>'
			. '<link href="https://fonts.googleapis.com/css2?family=Outfit:wght@600;700;800&display=swap" rel="stylesheet">'
			// Progressive: clients that honor head styles stack the tiles on phones; others keep the desktop layout.
			. '<style>@media only screen and (max-width: 480px) {'
			. ' .nl-card { padding: 28px 18px !important; }'
			// box-sizing matters: these are padded TDs, and width:100% + padding
			// in content-box overflows the viewport and crops the whole email.
			. ' .tile { display: block !important; width: 100% !important; margin-bottom: 8px !important; box-sizing: border-box !important; }'
			. ' .two-col { display: block !important; width: 100% !important; border-right: 0 !important; box-sizing: border-box !important; }'
			. ' .gtile { display: block !important; width: 100% !important; box-sizing: border-box !important; }'
			// Card rows (demo, review) stack: ALL cells in the row must go block —
			// a lone block td gets wrapped in an anonymous cell and keeps its slot.
			. ' .stack { display: block !important; width: 100% !important; box-sizing: border-box !important; }'
			. ' .rv-ico { display: none !important; }'
			. ' .rv-cta, .dm-cta { display: block !important; width: 100% !important; box-sizing: border-box !important; text-align: left !important; padding: 12px 0 0 0 !important; }'
			. '}</style>'
			. '</head>'
			. '<body style="margin:0; padding:0; background-color:#F7F8FA; font-family:' . self::FONT . '; color:' . self::INK . '; line-height:1.55; -webkit-text-size-adjust:100%;">'
			. '<div style="display:none; max-height:0; overflow:hidden; opacity:0;">' . esc_html( $compose['preheader'] ) . '</div>'
			. '<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="background-color:#F7F8FA;">'
			. '<tr><td align="center" style="padding:26px 16px;">'
			. ( ! empty( $opts['is_test'] ) ? $this->test_banner() : '' )
			. '<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="max-width:600px; background-color:#FFFFFF; border-radius:8px; border-top:4px solid ' . self::BLUE . ';">'
			. '<tr><td class="nl-card" style="padding:36px;">'
			. '<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%">'
			. $body
			. '</table>'
			. '</td></tr></table>'
			. '</td></tr></table>'
			. '</body></html>';
	}

	// ---------------------------------------------------------------- states

	private function normal_sections( array $payload, array $opts ) {
		$woo        = $payload['woocommerce'];
		$has_woo    = is_array( $woo ) && $woo['orders'] > 0;   // data sections need orders
		$is_store   = is_array( $woo );                          // premium pitch follows identity, not a quiet week
		$sections   = array( $this->masthead( $payload ) );

		$sections[] = $this->hero_tiles( $payload, $has_woo );

		if ( $payload['totals']['untracked'] > 0 ) {
			$sections[] = $this->divider();
			$sections[] = $this->blind_spot_bar( $payload );
		}

		if ( ! empty( $payload['channels'] ) ) {
			$sections[] = $this->divider();
			$sections[] = $this->channel_mix( $payload );
		}

		$forms_pages = $this->forms_pages_module( $payload );
		if ( $forms_pages !== '' ) {
			$sections[] = $this->divider();
			$sections[] = $forms_pages;
		}

		if ( $has_woo ) {
			$sections[] = $this->woo_section( $woo );
		}

		if ( ! empty( $payload['campaigns'] ) ) {
			$sections[] = $this->campaigns_section( $payload );
		}

		$sections[] = $this->divider();
		$sections[] = $this->doctor_section( $payload );

		if ( $payload['doctor']['all_clear'] ) {
			$sections[] = $this->support_card(
				'Nothing to fix this week',
				'Want a second set of eyes on your setup?',
				'Your tracking is healthy, so this is the week to go further. A UTM Grabber developer will audit your whole setup, tighten your campaign tagging and make sure nothing slips through as you scale. One-time $299.'
			);
		}

		$sections[] = $this->locked_panels( $is_store );
		$sections[] = $this->upsell_grid( $is_store );
		$sections[] = $this->demo_card();

		if ( ! empty( $opts['review_milestone'] ) ) {
			$sections[] = $this->review_card( (int) $opts['review_milestone'] );
		}

		$sections[] = $this->footer( $payload );

		return implode( '', $sections );
	}

	/** Zero-capture week: the Doctor takes over; help, don't sell. No upgrade grid. */
	private function zero_sections( array $payload ) {
		$greeting = $payload['greeting_name'] !== ''
			? 'Hey ' . esc_html( $payload['greeting_name'] ) . ','
			: 'Hey there,';

		return $this->masthead( $payload )
			. $this->row(
				'<p style="margin:26px 0 18px 0; font-size:17px;">' . $greeting . '</p>'
				. '<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="border:2px dashed #D7DBE0; border-radius:10px; background:#FAFBFC;">'
				. '<tr><td align="center" style="padding:30px 24px;">'
				. '<p style="margin:0; font-family:' . self::FONT_SERIF . '; font-size:44px; font-weight:700; color:#9AA3AF; line-height:1;">0</p>'
				. '<p style="margin:8px 0 0 0; font-size:14px; color:' . self::BODY . ';">conversions captured this week on <strong>' . esc_html( $payload['site']['domain'] ) . '</strong></p>'
				. '</td></tr></table>'
				. '<p style="margin:22px 0 0 0; font-size:15.5px; line-height:1.6;"><strong>Your tracking may be broken.</strong> A week with zero captures is almost always a setup problem, not a quiet week. The Tracking Doctor found the likely causes. None take more than a minute to check.</p>'
			)
			. $this->row( '<div style="height:24px; font-size:0; line-height:0;">&nbsp;</div>' . $this->doctor_table( $payload ) )
			. $this->row(
				'<p style="margin:26px 0 0 0;">' . $this->button( 'Open the Tracking Doctor', $payload['site']['manage_url'] ) . '</p>'
				. '<p style="margin:14px 0 0 0; font-size:13px; color:' . self::MUTED . ';">We will keep watching. Your next Snapshot lands next week.</p>'
			)
			. $this->support_card(
				'Rather have us do it',
				'Let our team set it up for you',
				'Not in the mood to troubleshoot? A UTM Grabber developer will get on your site, wire your forms, clear the caching issue and confirm tracking is live. One-time $299. You send us access, we hand it back working.'
			)
			. $this->footer( $payload );
	}

	// -------------------------------------------------------------- sections

	private function masthead( array $payload ) {
		return $this->row(
			'<img src="' . esc_url( $this->img( 'logo.png' ) ) . '" alt="UTM Grabber" width="150" style="display:block; height:auto; margin-bottom:20px;">'
			. '<p style="margin:0; font-family:' . self::FONT_SERIF . '; font-size:32px; font-weight:700; letter-spacing:-0.5px; line-height:1.1; color:' . self::INK . ';">The Weekly Snapshot<span style="color:' . self::BLUE . ';">.</span></p>'
			. '<p style="margin:5px 0 0 0; font-size:13px; color:' . self::BODY . ';">Your traffic, read for you. Every week.</p>'
			. '<p style="margin:14px 0 0 0; font-size:12px; font-weight:700; letter-spacing:1px; text-transform:uppercase; color:' . self::MUTED . ';">' . esc_html( $payload['site']['domain'] ) . ' &middot;&nbsp;' . esc_html( $payload['week']['label'] ) . '</p>',
			'padding-bottom:22px; border-bottom:1px solid ' . self::BORDER . ';'
		);
	}

	private function hero_tiles( array $payload, $has_woo ) {
		$totals = $payload['totals'];
		$tiles  = array();

		// Below 80% tracked the number itself turns amber, below 50% red — a low
		// share should read as "something is wrong", not as a neutral stat.
		$tracked_color = self::BLUE;
		if ( $totals['tracked_pct'] < 50 ) {
			$tracked_color = self::RED;
		} elseif ( $totals['tracked_pct'] < 80 ) {
			$tracked_color = self::AMBER;
		}

		$tiles[] = $this->tile(
			$this->format_number( $totals['conversions'] ),
			$has_woo ? 'leads &amp; orders' : 'leads',
			$this->trend_chip( $totals['trend_pct'], '%' )
		);
		$tiles[] = $this->tile(
			$this->format_number( $totals['tracked_pct'] ) . '<span style="font-size:26px; font-weight:600; color:' . self::MUTED . ';">%</span>',
			'tracked',
			$this->trend_chip( $totals['tracked_pts'], ' pts' ),
			strlen( (string) $totals['tracked_pct'] ) + 1,
			$tracked_color
		);

		if ( $has_woo ) {
			$woo     = $payload['woocommerce'];
			$revenue = $this->money( $woo['currency'], $woo['total_value'] );
			$tiles[] = $this->tile( esc_html( $revenue ), 'revenue', '', $this->char_count( $revenue ) );
		} elseif ( ! empty( $payload['forms']['rows'] ) ) {
			$top     = $payload['forms']['rows'][0];
			$tiles[] = $this->tile(
				$this->format_number( $top['count'] ),
				'top form',
				'<span style="color:' . self::MUTED . ';">' . esc_html( $this->truncate( $top['title'], 22 ) ) . '</span>'
			);
		}

		$width = count( $tiles ) === 2 ? '50%' : '33.3%';
		$cells = array();
		foreach ( $tiles as $i => $tile ) {
			$pad     = $i === 0 ? 'padding-right:6px;' : ( $i === count( $tiles ) - 1 ? 'padding-left:6px;' : 'padding:0 3px;' );
			$cells[] = '<td class="tile" width="' . $width . '" valign="top" style="' . $pad . '">' . $tile . '</td>';
		}

		return $this->row(
			'<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%"><tr>' . implode( '', $cells ) . '</tr></table>'
			. '<p style="margin:11px 0 0 0; font-size:11.5px; color:' . self::MUTED . ';">Compared with last week.'
			. ( $totals['best_day'] !== '' ? ' Best day: ' . esc_html( $totals['best_day'] ) . '.' : '' )
			. '</p>'
			. ( $tracked_color !== self::BLUE
				? '<p style="margin:9px 0 0 0; font-size:13px; font-weight:700; color:' . $tracked_color . ';">Only ' . (int) $totals['tracked_pct'] . '% of your conversions have a source attached. The Tracking Doctor has the fix below.</p>'
				: '' ),
			'padding:30px 0 0 0;'
		);
	}

	private function blind_spot_bar( array $payload ) {
		$totals  = $payload['totals'];
		$tracked = max( 1, min( 99, $totals['tracked_pct'] ) );
		$blind   = 100 - $tracked;

		$has_leak = false;
		foreach ( $payload['doctor']['items'] as $item ) {
			if ( $item['severity'] === 'leak' ) {
				$has_leak = true;
				break;
			}
		}
		$sentence = $has_leak
			? 'That is money you cannot trace back, and it is the first thing the Tracking Doctor fixes below.'
			: 'That is money you cannot trace back to a campaign.';

		// Labels print the same clamped values as the widths, so an extreme
		// week never reads "100% tracked" beside "N arrived with no source".
		return $this->row(
			$this->label( 'Your blind spot' )
			. '<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%"><tr style="height:44px;">'
			. '<td width="' . max( 14, $tracked - 1 ) . '%" style="background-color:' . self::GREEN . '; color:#ffffff; font-size:15px; font-weight:700; text-align:center; border-radius:8px 0 0 8px;">' . $tracked . '%&nbsp;tracked</td>'
			. '<td width="1%" style="background-color:#ffffff;">&nbsp;</td>'
			. '<td width="' . max( 12, $blind ) . '%" style="background-color:' . self::AMBER . '; color:#ffffff; font-size:15px; font-weight:700; text-align:center; border-radius:0 8px 8px 0;">' . $blind . '%&nbsp;blind</td>'
			. '</tr></table>'
			. '<p style="margin:14px 0 0 0; font-size:15.5px; line-height:1.6;"><strong>' . (int) $totals['untracked'] . ' conversion' . ( $totals['untracked'] === 1 ? '' : 's' ) . ' arrived with no source.</strong> ' . $sentence . '</p>'
		);
	}

	private function channel_mix( array $payload ) {
		$rows = $payload['channels'];

		$segments = array();
		foreach ( $rows as $i => $row ) {
			$radius = '';
			if ( $i === 0 ) {
				$radius = 'border-radius:6px 0 0 6px;';
			}
			if ( $i === count( $rows ) - 1 ) {
				$radius .= 'border-radius:' . ( count( $rows ) === 1 ? '6px' : '0 6px 6px 0' ) . ';';
			}
			$segments[] = '<td width="' . max( 2, $row['pct'] - 1 ) . '%" style="background:' . self::CHANNEL_COLORS[ $row['channel'] ] . '; ' . $radius . '">&nbsp;</td>';
			if ( $i !== count( $rows ) - 1 ) {
				$segments[] = '<td width="1%" style="background:#fff;">&nbsp;</td>';
			}
		}

		$legend = array();
		foreach ( $rows as $row ) {
			$legend[] = '<td style="padding:5px 0; font-size:14px;">'
				. '<span style="color:' . self::CHANNEL_COLORS[ $row['channel'] ] . '; font-weight:700;">&#9632;</span> '
				. esc_html( $row['label'] ) . ' <strong>' . (int) $row['pct'] . '%</strong> '
				. $this->delta_chip( $row['delta_pts'] )
				. '</td>';
		}
		$legend_rows = '';
		foreach ( array_chunk( $legend, 3 ) as $chunk ) {
			while ( count( $chunk ) < 3 ) {
				$chunk[] = '<td style="padding:5px 0;"></td>';
			}
			$legend_rows .= '<tr>' . implode( '', $chunk ) . '</tr>';
		}

		$ai_note = '';
		if ( $payload['ai_referral'] > 0 ) {
			$ai_note = '<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="margin-top:14px; background:#FDF2F8; border-radius:8px;">'
				. '<tr><td style="padding:14px 16px; font-size:14px; line-height:1.6;">'
				. '<strong style="color:#DB2777;">' . (int) $payload['ai_referral'] . ' of those referrals were AI chatbots.</strong> ChatGPT, Claude and Perplexity are sending you real leads. Most tools cannot see that channel at all.'
				. '</td></tr></table>';
		}

		return $this->row(
			$this->label( 'Where your leads came from' )
			. '<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%"><tr style="height:30px; font-size:0;">' . implode( '', $segments ) . '</tr></table>'
			. '<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="margin-top:12px;">' . $legend_rows . '</table>'
			. $ai_note
		);
	}

	private function forms_pages_module( array $payload ) {
		$forms = $payload['forms'];
		$pages = $payload['landing_pages'];

		$columns = array();
		if ( $forms['total'] > 0 ) {
			$rows = '';
			foreach ( $forms['rows'] as $row ) {
				$rows .= '<tr><td style="padding:4px 0;">' . esc_html( $this->truncate( $row['title'], 28 ) ) . '</td>'
					. '<td align="right" style="padding:4px 0; color:' . self::INK . ';"><strong>' . (int) $row['count'] . '</strong></td></tr>';
			}
			$columns[] = $this->module_column(
				'Form conversions',
				$forms['total'],
				'across ' . $forms['form_count'] . ' form' . ( $forms['form_count'] === 1 ? '' : 's' ),
				$rows
			);
		}
		if ( ! empty( $pages ) ) {
			$page_total = 0;
			foreach ( $pages as $row ) {
				$page_total += $row['count'];
			}
			$rows = '';
			foreach ( $pages as $row ) {
				$rows .= '<tr><td style="padding:4px 0;">' . esc_html( $this->truncate( $row['label'], 28 ) ) . '</td>'
					. '<td align="right" style="padding:4px 0; color:' . self::INK . ';"><strong>' . (int) $row['count'] . '</strong></td></tr>';
			}
			$columns[] = $this->module_column(
				'Top landing pages',
				$page_total,
				'across ' . count( $pages ) . ' page' . ( count( $pages ) === 1 ? '' : 's' ),
				$rows
			);
		}

		if ( empty( $columns ) ) {
			return '';
		}

		if ( count( $columns ) === 1 ) {
			$cells = '<td valign="top" width="100%" style="padding:18px 20px;">' . $columns[0] . '</td>';
		} else {
			$cells = '<td class="two-col" valign="top" width="50%" style="padding:18px 20px; border-right:1px solid #F0F1F4;">' . $columns[0] . '</td>'
				. '<td class="two-col" valign="top" width="50%" style="padding:18px 20px;">' . $columns[1] . '</td>';
		}

		return $this->row(
			'<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="border:1px solid ' . self::BORDER . '; border-radius:10px;"><tr>' . $cells . '</tr></table>',
			'padding-bottom:22px;'
		);
	}

	private function woo_section( array $woo ) {
		$currency = $woo['currency'];
		$leak     = $woo['total_value'] - $woo['attributed_value'];

		$sentence = $woo['attributed_pct'] > 0
			? '<strong style="color:' . self::INK . ';">' . (int) $woo['attributed_pct'] . '% of your revenue traces back to a campaign.</strong>'
				. ( $leak > 0.005 ? ' The other ' . esc_html( $this->money( $currency, $leak ) ) . ' came in with no source.' : '' )
			: '<strong style="color:' . self::INK . ';">None of this week\'s revenue traces back to a campaign.</strong>';
		$sentence .= ' Average order ' . esc_html( $this->money( $currency, $woo['average_order'] ) ) . '.';
		if ( ! empty( $woo['capped'] ) ) {
			$sentence .= ' Based on the ' . (int) Handl_Snapshot_Collector::MAX_WOO_ORDERS . ' most recent orders.';
		}

		$products = '';
		if ( ! empty( $woo['products'] ) ) {
			$rows = '';
			foreach ( $woo['products'] as $name => $product ) {
				$rows .= '<tr>'
					. '<td style="padding:5px 0; border-top:1px solid #F0F1F4;">' . esc_html( $this->truncate( $name, 34 ) ) . '</td>'
					. '<td align="right" style="padding:5px 0; border-top:1px solid #F0F1F4; color:' . self::MUTED . ';">' . (int) $product['orders'] . ' order' . ( $product['orders'] === 1 ? '' : 's' ) . '</td>'
					. '<td align="right" style="padding:5px 0; border-top:1px solid #F0F1F4; color:' . self::INK . ';"><strong>' . esc_html( $this->money( $currency, $product['revenue'] ) ) . '</strong></td>'
					. '</tr>';
			}
			$products = '<tr><td style="padding:0 18px 16px 18px;">'
				. '<p style="margin:0 0 8px 0; font-size:11px; font-weight:700; letter-spacing:.6px; text-transform:uppercase; color:' . self::MUTED . ';">Top products</p>'
				. '<table role="presentation" width="100%" style="font-size:13.5px; color:' . self::BODY . ';">' . $rows . '</table>'
				. '</td></tr>';
		}

		return $this->row(
			'<table role="presentation" width="100%" style="border:1px solid ' . self::BORDER . '; border-radius:10px;">'
			. '<tr><td style="padding:16px 18px 12px 18px; border-bottom:1px solid #F0F1F4;"><p style="margin:0; font-size:13px; font-weight:700; color:' . self::INK . ';">WooCommerce</p></td></tr>'
			. '<tr><td style="padding:16px 18px;">'
			. '<table role="presentation" width="100%"><tr>'
			. $this->mini_stat( $this->format_number( $woo['orders'] ), 'orders', 'padding-right:6px;' )
			. $this->mini_stat( esc_html( $this->money( $currency, $woo['total_value'] ) ), 'total order value', 'padding:0 3px;' )
			. $this->mini_stat( esc_html( $this->money( $currency, $woo['attributed_value'] ) ), 'attributed to a source', 'padding-left:6px;' )
			. '</tr></table>'
			. '<p style="margin:14px 0 0 0; font-size:13px; color:' . self::MUTED . ';">' . $sentence . '</p>'
			. '</td></tr>'
			. $products
			. '</table>',
			'padding-bottom:22px;'
		);
	}

	private function campaigns_section( array $payload ) {
		$campaigns = $payload['campaigns'];
		$top_count = max( 1, $campaigns[0]['count'] );
		$colors    = array( '#0160BF', '#5B9BE0', '#9CC4EE' );

		$rows = '';
		foreach ( $campaigns as $i => $campaign ) {
			$width = max( 6, (int) round( 100 * $campaign['count'] / $top_count ) );
			$rows .= '<tr>'
				. '<td width="32%" valign="middle" style="padding:10px 0; font-size:14.5px;">' . esc_html( $this->truncate( $campaign['name'], 18 ) ) . ' ' . $this->delta_chip( $campaign['delta'] ) . '</td>'
				. '<td width="55%" valign="middle" style="padding:10px 0;">'
				. '<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%"><tr>'
				. '<td width="' . $width . '%" style="background-color:' . $colors[ min( $i, 2 ) ] . '; height:24px; border-radius:5px; font-size:0; line-height:24px;">&nbsp;</td>'
				. '<td width="' . ( 100 - $width ) . '%" style="font-size:0; line-height:24px;">&nbsp;</td>'
				. '</tr></table>'
				. '</td>'
				. '<td width="13%" align="right" valign="middle" style="padding:10px 0; font-size:16px; font-weight:700;">' . (int) $campaign['count'] . '</td>'
				. '</tr>';
		}

		$standout  = '';
		$tracked   = $payload['totals']['tracked'];
		$runner_up = count( $campaigns ) > 1 ? $campaigns[1]['count'] : 0;
		if ( $tracked > 0 && $campaigns[0]['count'] > $runner_up ) {
			$share = (int) round( 100 * $campaigns[0]['count'] / $tracked );
			if ( $share >= 40 ) {
				$standout = '<p style="margin:16px 0 0 0; font-size:14.5px; line-height:1.6;"><strong>'
					. esc_html( $campaigns[0]['name'] ) . ' drove ' . $share . '% of everything you tracked this week'
					. ( $runner_up > 0 ? ',</strong> well clear of your next best campaign.' : '.</strong>' )
					. '</p>';
			}
		}

		return $this->row(
			$this->label( 'Top campaigns' )
			. '<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%">' . $rows . '</table>'
			. $standout
		);
	}

	private function doctor_section( array $payload ) {
		return $this->row( $this->doctor_table( $payload ), 'padding-bottom:34px;' );
	}

	private function doctor_table( array $payload ) {
		$doctor = $payload['doctor'];

		if ( $doctor['all_clear'] ) {
			return '<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="border:2px solid ' . self::GREEN . '; border-radius:10px;">'
				. '<tr><td style="background:' . self::INK . '; padding:15px 22px; border-radius:8px 8px 0 0;">'
				. $this->doctor_header( 'All clear', self::GREEN )
				. '</td></tr>'
				. '<tr><td style="background:' . self::GREEN . '; color:#ffffff; padding:20px 22px; font-size:15px; font-weight:700; border-radius:0 0 8px 8px;">&#10003; Nothing to fix this week. Your tracking is healthy, and everything is being captured with a source.</td></tr>'
				. '</table>';
		}

		$items  = $doctor['items'];
		$border = $items[0]['color'];
		$count  = count( $items );

		$rows = '';
		foreach ( $items as $i => $item ) {
			$ordinal = str_pad( (string) ( $i + 1 ), 2, '0', STR_PAD_LEFT );
			$lead    = $item['lead'] !== ''
				? '<span style="color:' . $item['color'] . ';">' . esc_html( $item['lead'] ) . '</span> '
				: '';
			$action = '';
			if ( $item['action_label'] !== '' ) {
				$action = ' <a href="' . esc_url( $item['action_url'] ) . '" style="color:' . $item['color'] . '; font-weight:700; text-decoration:none;">' . esc_html( $item['action_label'] ) . '&nbsp;&rarr;</a>';
				if ( $item['note'] !== '' ) {
					$action .= ' <span style="color:' . self::MUTED . '; font-size:12.5px;">' . esc_html( $item['note'] ) . '</span>';
				}
			}

			$rows .= '<tr><td style="padding:18px 22px;' . ( $i === $count - 1 ? ' border-radius:0 0 8px 8px;' : ' border-bottom:1px solid #F0F1F4;' ) . '">'
				. '<table role="presentation" width="100%"><tr>'
				. '<td valign="top" width="46" style="font-family:' . self::FONT_NUM . '; font-size:30px; font-weight:800; letter-spacing:-1.5px; color:' . $item['color'] . '; line-height:1;">' . $ordinal . '</td>'
				. '<td valign="top">'
				. '<p style="margin:0 0 4px 0; font-size:16px; font-weight:800; line-height:1.3;">' . $lead . esc_html( $item['headline'] ) . '</p>'
				. '<p style="margin:0; font-size:13.5px; color:' . self::BODY . '; line-height:1.55;">' . esc_html( $item['body'] ) . $action . '</p>'
				. '</td></tr></table>'
				. '</td></tr>';
		}

		$badge = $payload['data_state'] === 'zero'
			? 'Check this now'
			: $count . ' thing' . ( $count === 1 ? '' : 's' ) . ' to fix now';

		return '<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="border:2px solid ' . $border . '; border-radius:10px;">'
			. '<tr><td style="background:' . self::INK . '; padding:15px 22px; border-radius:8px 8px 0 0;">'
			. $this->doctor_header( $badge, '#F87171' )
			. '</td></tr>'
			. $rows
			. '</table>';
	}

	private function doctor_header( $badge, $badge_color ) {
		return '<table role="presentation" width="100%"><tr>'
			. '<td><p style="margin:0; font-size:18px; font-weight:700; color:#fff; letter-spacing:-.2px;">Tracking Doctor</p></td>'
			. '<td align="right"><p style="margin:0; font-size:11px; font-weight:700; letter-spacing:1px; text-transform:uppercase; color:' . $badge_color . ';">' . esc_html( $badge ) . '</p></td>'
			. '</tr></table>';
	}

	/**
	 * Blurred Premium teasers. CSS blur is stripped by mail clients, so the
	 * blur is baked into pre-rendered PNGs of the sample tables; pill and
	 * caption stay live HTML beneath the image.
	 */
	private function locked_panels( $is_store ) {
		$first_touch = array(
			'label' => 'First touch vs last touch',
			'img'   => 'blur-firsttouch.png',
			'alt'   => 'Blurred preview of first-touch vs last-touch attribution',
			'pill'  => 'Unlock first-touch attribution',
			'body'  => 'See the ad that first found each lead, not just the one that closed them. The two are rarely the same.',
			'utm'   => 'weekly_snapshot_firsttouch',
		);
		$keywords    = array(
			'label' => 'Which search terms brought leads',
			'img'   => 'blur-keywords.png',
			'alt'   => 'Blurred preview of keyword-level lead tracking',
			'pill'  => 'Unlock keyword tracking',
			'body'  => 'Tie every lead to the exact search term that brought it, so you know which keywords to double down on.',
			'utm'   => 'weekly_snapshot_keywords',
		);

		$panels = array();
		if ( $is_store ) {
			// "Coming to Premium" — no surface may imply ad-account ROAS works today.
			$panels[] = array(
				'label' => 'Spend, revenue &amp; ROAS per channel',
				'img'   => 'blur-roas.png',
				'alt'   => 'Blurred preview of spend, revenue and ROAS per channel',
				'pill'  => 'Coming to Premium',
				'body'  => 'Link your ad accounts and see true ROAS per channel: which ads pay for themselves, and which quietly don\'t.',
				'utm'   => 'weekly_snapshot_roas',
			);
		}
		$panels[] = $first_touch;
		$panels[] = $keywords;

		$html = '';
		foreach ( $panels as $panel ) {
			$url   = esc_url( handl_v3_generate_links( $panel['utm'], '', 'weekly_email' ) );
			$html .= $this->row(
				$this->label( $panel['label'] . ' &nbsp;<span style="color:#9AA3AF;"><img src="' . esc_url( $this->img( 'lock-gray.png' ) ) . '" width="11" height="11" alt="" style="display:inline-block; width:11px; height:11px; border:0;"> Premium</span>' )
				. '<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="border:1px solid ' . self::BORDER . '; border-radius:8px; background:#ffffff;">'
				. '<tr><td style="padding:0; font-size:0; line-height:0;">'
				. '<a href="' . $url . '" style="display:block;"><img src="' . esc_url( $this->img( $panel['img'] ) ) . '" width="528" alt="' . esc_attr( $panel['alt'] ) . '" style="display:block; width:100%; height:auto; border:0; border-radius:8px 8px 0 0;"></a>'
				. '</td></tr>'
				. '<tr><td align="center" style="padding:4px 20px 20px 20px; border-top:1px solid #F0F1F4;">'
				. '<a href="' . $url . '" style="display:inline-block; margin-top:14px; background:' . self::INK . '; color:#fff; font-size:12px; font-weight:700; letter-spacing:.3px; padding:9px 16px; border-radius:999px; text-decoration:none;"><img src="' . esc_url( $this->img( 'lock-white.png' ) ) . '" width="13" height="13" alt="" style="display:inline-block; width:13px; height:13px; border:0; vertical-align:-2px;">&nbsp; ' . esc_html( $panel['pill'] ) . '</a>'
				. '<p style="margin:11px 18px 0; font-size:13px; color:#3f4653; line-height:1.5;">' . $panel['body'] . '</p>'
				. '</td></tr></table>',
				'padding-bottom:28px;'
			);
		}
		return $html;
	}

	private function upsell_grid( $has_woo ) {
		$owned = array(
			array( 'chart-pie-2-green.png', 'Channel mix' ),
			array( 'mail-green.png', 'This Snapshot' ),
			array( 'stethoscope-green.png', 'Tracking Doctor' ),
		);
		$locked = array(
			$has_woo
				? array( 'coin-gray.png', 'Revenue &amp; ROAS' )
				: array( 'coin-gray.png', 'Phone &amp; email clicks' ),
			array( 'sparkles-gray.png', 'AI insights' ),
			array( 'message-circle-gray.png', 'Ask in plain English' ),
			array( 'target-arrow-gray.png', 'Bing, TikTok &amp; LinkedIn' ),
			array( 'arrows-exchange-gray.png', 'First vs last touch' ),
			array( 'report-analytics-gray.png', 'Live report, any date' ),
		);

		$tiles = array();
		foreach ( $owned as $tile ) {
			$tiles[] = $this->grid_tile( $tile[0], $tile[1], true );
		}
		foreach ( $locked as $tile ) {
			$tiles[] = $this->grid_tile( $tile[0], $tile[1], false );
		}

		$grid = '';
		foreach ( array_chunk( $tiles, 3 ) as $chunk_index => $chunk ) {
			$cells = '';
			foreach ( $chunk as $i => $tile ) {
				$pad    = $i === 0 ? '0 5px 10px 0' : ( $i === 2 ? '0 0 10px 5px' : '0 3px 10px 3px' );
				$cells .= '<td class="gtile" width="33.3%" valign="top" style="padding:' . $pad . ';">' . $tile . '</td>';
			}
			$grid .= '<tr>' . $cells . '</tr>';
		}

		return $this->row(
			'<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="background-color:' . self::BLUE . '; border-radius:10px;"><tr><td style="padding:26px;">'
			. '<p style="margin:0 0 6px 0; font-size:11px; font-weight:700; letter-spacing:1.2px; text-transform:uppercase; color:#D6E6FA;">You are seeing the free snapshot</p>'
			. '<p style="margin:0 0 14px 0; font-size:22px; font-weight:700; line-height:1.25; color:#FFFFFF;">You have unlocked 3 of 9 reports</p>'
			. '<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="margin-bottom:6px;"><tr style="height:12px;">'
			. '<td width="33%" style="background:#8FC1F0; border-radius:6px 0 0 6px; font-size:0;">&nbsp;</td>'
			. '<td width="67%" style="background:#0A4C93; border-radius:0 6px 6px 0; font-size:0;">&nbsp;</td>'
			. '</tr></table>'
			. '<p style="margin:0 0 20px 0; font-size:12px; color:#B9D6F5;">The three in green are yours. The six in white are Premium.</p>'
			. '<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%">' . $grid . '</table>'
			. '<p style="margin:22px 0 0 0;"><a href="' . esc_url( handl_v3_generate_links( 'weekly_snapshot_unlock', '', 'weekly_email' ) ) . '" style="display:inline-block; background-color:#FFFFFF; color:' . self::BLUE . '; padding:14px 30px; border-radius:6px; text-decoration:none; font-weight:700; font-size:15px; line-height:20px;">Unlock all 9 reports&nbsp;&rarr;</a></p>'
			. '<p style="margin:12px 0 0 0; font-size:12px; color:#B9D6F5;">Plans from $299 a year.</p>'
			. '</td></tr></table>',
			'padding-bottom:34px;'
		);
	}

	private function grid_tile( $icon, $label, $owned ) {
		$bg     = $owned ? '#EAF6EF' : '#ffffff';
		$status = $owned
			? '<p style="margin:5px 0 0 0; font-size:10px; color:#00A862; font-weight:700;"><img src="' . esc_url( $this->img( 'circle-check-green.png' ) ) . '" width="12" height="12" alt="" style="display:inline-block; width:12px; height:12px; border:0; vertical-align:-2px;"> You have this</p>'
			: '<p style="margin:5px 0 0 0; font-size:10px; color:#9AA3AF;"><img src="' . esc_url( $this->img( 'lock-gray.png' ) ) . '" width="11" height="11" alt="" style="display:inline-block; width:11px; height:11px; border:0; vertical-align:-2px;"> Premium</p>';

		return '<table role="presentation" width="100%" style="background:' . $bg . '; border-radius:8px;"><tr><td align="center" style="padding:14px 6px;">'
			. '<p style="margin:0; font-size:0; line-height:1;"><img src="' . esc_url( $this->img( $icon ) ) . '" width="22" height="22" alt="" style="display:inline-block; width:22px; height:22px; border:0;"></p>'
			. '<p style="margin:7px 0 0 0; font-size:11.5px; font-weight:700; color:' . self::INK . '; line-height:1.3;">' . $label . '</p>'
			. $status
			. '</td></tr></table>';
	}

	private function demo_card() {
		$url = add_query_arg(
			array(
				'utm_source'   => 'WordPress_FREE',
				'utm_medium'   => 'weekly_email',
				'utm_campaign' => 'weekly_snapshot_demo',
			),
			'https://utmgrabber.com/book-a-demo/'
		);

		return $this->row(
			'<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="background-color:#F2F4F7; border-radius:8px;"><tr><td style="padding:20px 22px;">'
			. '<table role="presentation" width="100%"><tr>'
			. '<td class="stack" valign="middle">'
			. '<p style="margin:0 0 3px 0; font-size:16px; font-weight:700;">Want help making sense of this report?</p>'
			. '<p style="margin:0; font-size:14px; color:' . self::BODY . '; line-height:1.5;">Book a 15-minute call and we will walk through your numbers with you.</p>'
			. '</td>'
			. '<td class="dm-cta" valign="middle" align="right" width="150" style="padding-left:12px;">'
			. '<a href="' . esc_url( $url ) . '" style="display:inline-block; background-color:' . self::BLUE . '; color:#fff; padding:12px 20px; border-radius:6px; text-decoration:none; font-weight:700; font-size:14px; line-height:18px; white-space:nowrap;">Book a demo&nbsp;&rarr;</a>'
			. '</td>'
			. '</tr></table>'
			. '</td></tr></table>',
			'padding-bottom:22px;'
		);
	}

	private function review_card( $milestone ) {
		return $this->row(
			'<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="border:1px solid #F1D9A6; background-color:#FFFBF2; border-radius:8px;"><tr><td style="padding:18px 22px;">'
			. '<table role="presentation" width="100%"><tr>'
			. '<td class="rv-ico" valign="middle" width="34" style="padding-right:12px;"><img src="' . esc_url( $this->img( 'star-amber.png' ) ) . '" width="26" height="26" alt="" style="display:block; width:26px; height:26px; border:0;"></td>'
			. '<td class="stack" valign="middle">'
			. '<p style="margin:0 0 3px 0; font-size:15px; font-weight:700;">You have tracked your ' . esc_html( $this->ordinal( $milestone ) ) . ' lead with UTM&nbsp;Grabber.</p>'
			. '<p style="margin:0; font-size:13.5px; color:#6B5A2E; line-height:1.5;">The plugin is free because reviews keep it visible. A 30-second review on WordPress.org means a lot.</p>'
			. '</td>'
			. '<td class="rv-cta" valign="middle" align="right" width="150" style="padding-left:12px;">'
			. '<a href="https://wordpress.org/support/plugin/handl-utm-grabber/reviews/#new-post" style="display:inline-block; background-color:' . self::AMBER . '; color:#ffffff; padding:11px 16px; border-radius:6px; text-decoration:none; font-weight:700; font-size:13.5px; line-height:16px;">Leave a review&nbsp;&rarr;</a>'
			. '</td>'
			. '</tr></table>'
			. '</td></tr></table>',
			'padding-bottom:22px;'
		);
	}

	private function support_card( $eyebrow, $heading, $body ) {
		$url = add_query_arg(
			array(
				'utm_source'   => 'WordPress_FREE',
				'utm_medium'   => 'weekly_email',
				'utm_campaign' => 'weekly_snapshot_support',
			),
			'https://utmgrabber.com/support/'
		);

		return $this->row(
			'<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="border:1px solid ' . self::BORDER . '; background:#F7F9FC; border-radius:10px;"><tr><td style="padding:22px 24px;">'
			. '<p style="margin:0 0 6px 0; font-size:11px; font-weight:700; letter-spacing:1.2px; text-transform:uppercase; color:' . self::BLUE . ';">' . esc_html( $eyebrow ) . '</p>'
			. '<p style="margin:0 0 8px 0; font-size:18px; font-weight:700; line-height:1.3;">' . esc_html( $heading ) . '</p>'
			. '<p style="margin:0 0 16px 0; font-size:14.5px; color:' . self::BODY . '; line-height:1.6;">' . esc_html( $body ) . '</p>'
			. '<a href="' . esc_url( $url ) . '" style="display:inline-block; background:' . self::INK . '; color:#fff; padding:12px 22px; border-radius:6px; text-decoration:none; font-weight:700; font-size:14.5px;">Get UTM Grabber support&nbsp;&rarr;</a>'
			. '</td></tr></table>',
			'padding:24px 0 22px 0;'
		);
	}

	/** No signature, no unsubscribe link: a plugin notification, managed in plugin settings. */
	private function footer( array $payload ) {
		return $this->row(
			'<p style="margin:0 0 10px 0; font-size:13px; color:' . self::MUTED . '; line-height:1.6;">Generated by the UTM&nbsp;Grabber plugin on <strong style="color:' . self::BODY . ';">' . esc_html( $payload['site']['domain'] ) . '</strong> and sent from your own site.</p>'
			. '<p style="margin:0; font-size:13px;"><a href="' . esc_url( $payload['site']['manage_url'] ) . '" style="color:' . self::BLUE . '; font-weight:700; text-decoration:none;">Manage your Snapshot in plugin settings&nbsp;&rarr;</a></p>',
			'border-top:1px solid ' . self::BORDER . '; padding-top:22px;'
		);
	}

	private function test_banner() {
		return '<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="max-width:600px;">'
			. '<tr><td style="background:' . self::BLUE . '; color:#fff; border-radius:8px; padding:12px 16px; font-size:12.5px; line-height:1.5;"><strong>TEST EMAIL.</strong> This is what your Weekly Snapshot looks like with your site\'s current data.</td></tr>'
			. '<tr><td style="height:14px; line-height:14px; font-size:0;">&nbsp;</td></tr>'
			. '</table>';
	}

	// --------------------------------------------------------------- helpers

	private function row( $content, $style = '' ) {
		return '<tr><td style="' . $style . '">' . $content . '</td></tr>';
	}

	private function divider() {
		return $this->row( '<div style="border-top:1px solid #EAECEF; font-size:0; line-height:0;">&nbsp;</div>', 'padding:28px 0;' );
	}

	private function label( $text ) {
		return '<p style="margin:0 0 12px 0; font-size:12px; font-weight:700; letter-spacing:1.2px; text-transform:uppercase; color:' . self::BLUE . ';">' . $text . '</p>';
	}

	private function button( $label, $url ) {
		return '<a href="' . esc_url( $url ) . '" style="display:inline-block; background-color:' . self::BLUE . '; color:#fff; padding:14px 28px; border-radius:6px; text-decoration:none; font-weight:700; font-size:15px;">' . esc_html( $label ) . '&nbsp;&rarr;</a>';
	}

	/**
	 * Hero stat tile. Font steps down by character count so a seven-figure
	 * value never overflows: <=6 chars 46px, 7 chars 38px, 8 chars 31px,
	 * >=9 chars 25px. Tiles size independently. The number is brand blue
	 * unless the caller passes a health color (the tracked-% thresholds).
	 */
	private function tile( $number_html, $sublabel, $trend_html, $char_count = null, $number_color = self::BLUE ) {
		if ( $char_count === null ) {
			$char_count = $this->char_count( wp_strip_all_tags( $number_html ) );
		}
		$size = 46;
		if ( $char_count >= 9 ) {
			$size = 25;
		} elseif ( $char_count === 8 ) {
			$size = 31;
		} elseif ( $char_count === 7 ) {
			$size = 38;
		}

		return '<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="background-color:#F2F4F7; border-radius:10px;"><tr><td style="padding:22px 20px;">'
			. '<p style="margin:0; font-family:' . self::FONT_NUM . '; font-size:' . $size . 'px; font-weight:700; letter-spacing:-1.6px; line-height:1; color:' . $number_color . ';">' . $number_html . '</p>'
			. '<p style="margin:10px 0 0 0; font-size:13px; font-weight:700; color:' . self::INK . ';">' . $sublabel . '</p>'
			. ( $trend_html !== '' ? '<p style="margin:5px 0 0 0; font-size:12px; font-weight:700;">' . $trend_html . '</p>' : '' )
			. '</td></tr></table>';
	}

	private function mini_stat( $number_html, $label, $pad ) {
		return '<td valign="top" width="33.3%" style="' . $pad . '">'
			. '<p style="margin:0; font-family:' . self::FONT_NUM . '; font-size:28px; font-weight:700; letter-spacing:-1px; line-height:1; color:' . self::BLUE . ';">' . $number_html . '</p>'
			. '<p style="margin:6px 0 0 0; font-size:12px; color:' . self::BODY . ';">' . esc_html( $label ) . '</p>'
			. '</td>';
	}

	private function module_column( $title, $big_number, $sublabel, $rows ) {
		return '<p style="margin:0 0 12px 0; font-size:13px; font-weight:700; color:' . self::INK . ';">' . esc_html( $title ) . '</p>'
			. '<p style="margin:0 0 3px 0; font-family:' . self::FONT_NUM . '; font-size:34px; font-weight:700; letter-spacing:-1.1px; line-height:1; color:' . self::BLUE . ';">' . $this->format_number( $big_number ) . '</p>'
			. '<p style="margin:0 0 12px 0; font-size:11px; color:' . self::MUTED . ';">' . esc_html( $sublabel ) . '</p>'
			. '<table role="presentation" width="100%" style="font-size:13.5px; color:' . self::BODY . ';">' . $rows . '</table>';
	}

	private function trend_chip( $value, $unit ) {
		if ( $value === null ) {
			return '<span style="color:' . self::MUTED . ';">-</span>';
		}
		if ( $value > 0 ) {
			return '<span style="color:' . self::GREEN . ';">&#9650; ' . (int) $value . $unit . '</span>';
		}
		if ( $value < 0 ) {
			return '<span style="color:' . self::RED . ';">&#9660; ' . abs( (int) $value ) . $unit . '</span>';
		}
		return '<span style="color:' . self::MUTED . ';">steady</span>';
	}

	private function delta_chip( $delta ) {
		if ( $delta === null || (int) $delta === 0 ) {
			return '<span style="color:' . self::MUTED . '; font-size:11px; font-weight:700;">-</span>';
		}
		return $delta > 0
			? '<span style="color:' . self::GREEN . '; font-size:11px; font-weight:700;">&#9650;' . (int) $delta . '</span>'
			: '<span style="color:' . self::RED . '; font-size:11px; font-weight:700;">&#9660;' . abs( (int) $delta ) . '</span>';
	}

	private function format_number( $number ) {
		return number_format_i18n( (int) $number );
	}

	private function money( $symbol, $amount ) {
		return $symbol . number_format_i18n( (int) round( (float) $amount ) );
	}

	/** Digits plus $, commas and % all count toward the tile-size rule. */
	private function char_count( $text ) {
		return function_exists( 'mb_strlen' ) ? mb_strlen( $text ) : strlen( $text );
	}

	private function truncate( $text, $length ) {
		$text = (string) $text;
		if ( $this->char_count( $text ) <= $length ) {
			return $text;
		}
		$cut = function_exists( 'mb_substr' ) ? mb_substr( $text, 0, $length - 1 ) : substr( $text, 0, $length - 1 );
		return $cut . '…';
	}

	private function ordinal( $number ) {
		$number = (int) $number;
		if ( $number % 100 >= 11 && $number % 100 <= 13 ) {
			return number_format_i18n( $number ) . 'th';
		}
		$suffixes = array( 'th', 'st', 'nd', 'rd', 'th', 'th', 'th', 'th', 'th', 'th' );
		return number_format_i18n( $number ) . $suffixes[ $number % 10 ];
	}

	private function img( $file ) {
		return plugins_url( 'img/email/' . $file, dirname( dirname( __DIR__ ) ) . '/handl-utm-grabber.php' );
	}
}
