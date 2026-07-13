<?php
namespace Handl\UtmrabberFree\Insights;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Rule-based insight engine. Pure PHP, no external calls. Turns a normalized
 * attribution payload into plain-English insight cards.
 *
 * Card shape:
 *   array{
 *     id: string, severity: 'warning'|'success'|'info', priority: int,
 *     message: string, action: string,
 *     cta_label?: string, cta_url?: string, cta_type?: 'upgrade'|'doc'
 *   }
 * Lower priority number = more important (cards[0] is the top card shown per lead).
 */
class Handl_Insight_Engine {

	/** Media considered paid. */
	private static $paid_media = array( 'cpc', 'ppc', 'paid', 'paidsearch', 'cpm', 'display' );

	/**
	 * @param array $posted Map of tracked param => value.
	 * @return array<int, array> Cards sorted by priority (most important first).
	 */
	public static function analyze( array $posted ) {
		$get = function ( $key ) use ( $posted ) {
			return isset( $posted[ $key ] ) ? trim( (string) $posted[ $key ] ) : '';
		};

		$source   = $get( 'utm_source' );
		$medium   = strtolower( $get( 'utm_medium' ) );
		$campaign = $get( 'utm_campaign' );
		$gclid    = $get( 'gclid' );
		$referrer = $get( 'handl_original_ref' );

		$has_utm   = ( $source !== '' || $medium !== '' || $campaign !== '' || $get( 'utm_term' ) !== '' || $get( 'utm_content' ) !== '' );
		$has_gclid = ( $gclid !== '' );
		$has_ref   = ( $referrer !== '' );

		$cards         = array();
		$google_ads_id = false;

		// Problem: nothing captured at all.
		if ( ! $has_utm && ! $has_gclid && ! $has_ref ) {
			$cards[] = array(
				'id'       => 'no_attribution',
				'severity' => 'info',
				'priority' => 10,
				'message'  => 'No campaign or source data on this lead.',
				'action'   => 'Looks like direct or untagged traffic.',
			);
		}

		// Problem: click ID present but no campaign.
		if ( $has_gclid && $campaign === '' ) {
			$cards[] = array(
				'id'        => 'clickid_no_campaign',
				'severity'  => 'warning',
				'priority'  => 15,
				'message'   => 'Google click ID captured, but the campaign is missing.',
				'action'    => 'The gclid arrived without utm_campaign, so this lead cannot be tied to a campaign.',
				'cta_type'  => 'upgrade',
				'cta_label' => 'Get full click-ID tracking in V3',
				'cta_url'   => self::upgrade_url( 'clickid_no_campaign' ),
			);
		}

		// Positive: Google Ads (gclid with a campaign, or google + paid medium).
		$is_google = ( stripos( $source, 'google' ) !== false );
		if ( ( $has_gclid && $campaign !== '' ) || ( $is_google && in_array( $medium, self::$paid_media, true ) ) ) {
			$google_ads_id = true;
			$camp_txt      = $campaign !== '' ? sprintf( ' - campaign "%s"', $campaign ) : '';
			$cards[]       = array(
				'id'        => 'google_ads',
				'severity'  => 'success',
				'priority'  => 20,
				'message'   => sprintf( 'Google Ads lead%s.', $camp_txt ),
				'action'    => 'Push this campaign into your CRM so sales sees the true source.',
				'cta_type'  => 'doc',
				'cta_label' => 'See how to send leads to your CRM',
				'cta_url'   => self::docs_url( 'zapier-integration', 'google_ads' ),
			);
		}

		// Positive: other paid traffic (skip if already flagged as Google Ads).
		if ( ! $google_ads_id && in_array( $medium, self::$paid_media, true ) ) {
			$src_txt = $source !== '' ? ucfirst( $source ) : 'a paid channel';
			$cards[] = array(
				'id'        => 'paid_traffic',
				'severity'  => 'success',
				'priority'  => 25,
				'message'   => sprintf( 'Paid lead from %s (%s).', $src_txt, $medium ),
				'action'    => 'Send this source to your CRM to measure ad ROI.',
				'cta_type'  => 'doc',
				'cta_label' => 'Connect your CRM with Zapier',
				'cta_url'   => self::docs_url( 'zapier-integration', 'paid_traffic' ),
			);
		}

		// Upgrade opportunity: looks organic/referral (no UTMs, no gclid, but a referrer).
		if ( ! $has_utm && ! $has_gclid && $has_ref ) {
			$cards[] = array(
				'id'        => 'organic_referral',
				'severity'  => 'info',
				'priority'  => 40,
				'message'   => 'Organic or referral lead - no campaign tags.',
				'action'    => 'Get first/last-touch attribution, referrer detail, and AI reports.',
				'cta_type'  => 'upgrade',
				'cta_label' => 'Unlock full attribution in V3',
				'cta_url'   => self::upgrade_url( 'organic_referral' ),
			);
		}

		// Fallback: tagged lead no other rule matched (e.g. email/newsletter)
		if ( empty( $cards ) && $has_utm ) {
			$src_txt  = $source !== '' ? ucfirst( $source ) : 'a tagged campaign';
			$med_txt  = $medium !== '' ? sprintf( ' (%s)', $medium ) : '';
			$camp_txt = $campaign !== '' ? sprintf( ' - campaign "%s"', $campaign ) : '';
			$cards[]  = array(
				'id'        => 'tagged_lead',
				'severity'  => 'success',
				'priority'  => 50,
				'message'   => sprintf( 'Lead from %s%s%s.', $src_txt, $med_txt, $camp_txt ),
				'action'    => 'Campaign data captured. Send it to your CRM so sales sees the source.',
				'cta_type'  => 'doc',
				'cta_label' => 'Connect your CRM with Zapier',
				'cta_url'   => self::docs_url( 'zapier-integration', 'tagged_lead' ),
			);
		}

		usort( $cards, function ( $a, $b ) {
			return $a['priority'] - $b['priority'];
		} );

		return $cards;
	}

	/**
	 * Build a CTA (type + default label + url) for a given kind. Shared so the AI
	 * analyzer attaches the same links without duplicating URL logic.
	 *
	 * @param string $kind    'crm' | 'upgrade'
	 * @param string $context Tracking context slug for the link.
	 * @return array{cta_type:string, cta_label:string, cta_url:string}
	 */
	public static function cta( $kind, $context ) {
		if ( 'crm' === $kind ) {
			return array(
				'cta_type'  => 'doc',
				'cta_label' => 'See how to send leads to your CRM',
				'cta_url'   => self::docs_url( 'zapier-integration', $context ),
			);
		}

		return array(
			'cta_type'  => 'upgrade',
			'cta_label' => 'Unlock full attribution in V3',
			'cta_url'   => self::upgrade_url( $context ),
		);
	}

	/** @return string V3 upgrade URL (uses the plugin's link builder when available). */
	private static function upgrade_url( $context ) {
		if ( function_exists( 'handl_v3_generate_links' ) ) {
			return handl_v3_generate_links( 'HandL_Insight_' . $context, 'WordPress_FREE', 'insight_card' );
		}
		return defined( 'HANDL_UTM_V3_LINK' ) ? HANDL_UTM_V3_LINK : 'https://utmgrabber.com';
	}

	/** @return string docs.utmgrabber.com URL for a book/path, tagged with utm params. */
	private static function docs_url( $path, $context ) {
		$args = array(
			'utm_source'   => 'WordPress_FREE',
			'utm_medium'   => 'insight_card',
			'utm_campaign' => $context,
		);
		return add_query_arg( $args, 'https://docs.utmgrabber.com/books/' . $path );
	}
}
