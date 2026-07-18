<?php
namespace Handl\UtmrabberFree\TrackingDoctor;

if ( ! defined( 'ABSPATH' ) ) exit;

require_once dirname( __DIR__ ) . '/insights/class-ai-analyzer.php';

use Handl\UtmrabberFree\Insights\Handl_AI_Analyzer;

/**
 * Deep per-check reviews on the WP 7.0 AI Client */
class Handl_Tracking_Doctor_AI {

	/** Bump when the prompt or schema changes to invalidate cached reviews. */
	const PROMPT_VERSION = 'v1';

	const MAX_FIELD_LEN = 500;
	const MAX_FINDINGS  = 6;
	const MAX_SOLUTIONS = 4;
	const MAX_STEPS     = 8;

	public static function is_enabled() {
		return Handl_AI_Analyzer::is_enabled();
	}

	public static function is_available() {
		return Handl_AI_Analyzer::is_available();
	}

	/** Availability block for the client; connectors_url matches the insights UI. */
	public static function availability() {
		return array(
			'enabled'        => self::is_enabled(),
			'available'      => self::is_available(),
			'connectors_url' => admin_url( 'options-connectors.php' ),
		);
	}

	/**
	 * Review one check's audit context; null on any failure (caller reports a generic error).
	 *
	 * @param string $category_id
	 * @param array  $context From Handl_Tracking_Doctor_Audit::context_for_category().
	 * @return array{category:string,summary:string,findings:array,solutions:array}|null
	 */
	public static function review( $category_id, array $context ) {
		if ( ! self::is_available() ) {
			return null;
		}

		try {
			$json = wp_ai_client_prompt( self::user_prompt( $context ) )
				->using_system_instruction( self::system_instruction() )
				->as_json_response( self::schema() )
				->generate_text();
		} catch ( \Throwable $e ) {
			return null;
		}

		if ( is_wp_error( $json ) || ! is_string( $json ) || $json === '' ) {
			return null;
		}

		$decoded = json_decode( $json, true );
		if ( ! is_array( $decoded ) ) {
			return null;
		}

		return array(
			'category'  => (string) $category_id,
			'summary'   => self::clean( isset( $decoded['summary'] ) ? $decoded['summary'] : '' ),
			'findings'  => self::normalize_findings( $decoded ),
			'solutions' => self::normalize_solutions( $decoded ),
		);
	}

	/** Scope note first: it is the only per-category steering the model gets. */
	private static function user_prompt( array $context ) {
		$scope = isset( $context['scope'] ) ? (string) $context['scope'] : '';

		return $scope . "\n\nAudit JSON:\n" . wp_json_encode( $context, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
	}

	private static function system_instruction() {
		return implode(
			"\n",
			array(
				'You are the HandL UTM Grabber Tracking Doctor - an expert on WordPress UTM attribution, first-party cookies, form integrations, WooCommerce order meta, GDPR cookie consent, caching pitfalls, and click IDs (gclid, fbclid, msclkid).',
				'HandL UTM Grabber captures utm_source, utm_medium, utm_campaign, utm_term, utm_content, and gclid on first visit, stores them in first-party cookies for 30 days (free), and passes them into forms, WooCommerce orders, and CRM workflows.',
				'Always answer from the HandL product perspective and recommend HandL-native fixes first.',
				'Reference https://docs.utmgrabber.com when citing setup steps. Use only data from the audit JSON; never invent data that is not present.',
				'CRITICAL SCOPE RULE: review ONLY the category named in the audit JSON and follow the scope instructions in the prompt. Every finding and solution must belong to that category; never cross-report issues from other health checks.',
				'Report problems. Include at most one brief positive confirmation; keep each finding to 2-3 sentences and never enumerate per-parameter or per-field results — the UI already shows the raw data to the user.',
				'Return ONLY valid JSON matching the provided schema. "summary" is a 2-3 sentence executive summary for the site owner.',
			)
		);
	}

	/**
	 * Strict providers need additionalProperties:false, all keys required, and an
	 * object root (same constraints as Handl_AI_Analyzer::schema()).
	 */
	private static function schema() {
		return array(
			'type'                 => 'object',
			'additionalProperties' => false,
			'properties'           => array(
				'summary'   => array(
					'type'        => 'string',
					'description' => '2-3 sentence executive summary for the site owner.',
				),
				'findings'  => array(
					'type'     => 'array',
					'maxItems' => self::MAX_FINDINGS,
					'items'    => array(
						'type'                 => 'object',
						'additionalProperties' => false,
						'properties'           => array(
							'severity' => array( 'type' => 'string', 'enum' => array( 'good', 'warning', 'critical' ) ),
							'title'    => array( 'type' => 'string', 'description' => 'Short headline for the finding.' ),
							'detail'   => array( 'type' => 'string', 'description' => '2-3 plain sentences; no per-parameter or per-field listings.' ),
						),
						'required'             => array( 'severity', 'title', 'detail' ),
					),
				),
				'solutions' => array(
					'type'     => 'array',
					'maxItems' => self::MAX_SOLUTIONS,
					'items'    => array(
						'type'                 => 'object',
						'additionalProperties' => false,
						'properties'           => array(
							'title'    => array( 'type' => 'string' ),
							'steps'    => array(
								'type'     => 'array',
								'maxItems' => self::MAX_STEPS,
								'items'    => array( 'type' => 'string', 'description' => 'One short imperative sentence.' ),
							),
							'docs_url' => array( 'type' => 'string' ),
							'priority' => array( 'type' => 'string', 'enum' => array( 'high', 'medium', 'low' ) ),
						),
						'required'             => array( 'title', 'steps', 'docs_url', 'priority' ),
					),
				),
			),
			'required'             => array( 'summary', 'findings', 'solutions' ),
		);
	}

	/** @return array<int, array{severity:string,title:string,detail:string}> */
	private static function normalize_findings( array $decoded ) {
		$out = array();
		if ( empty( $decoded['findings'] ) || ! is_array( $decoded['findings'] ) ) {
			return $out;
		}

		foreach ( $decoded['findings'] as $item ) {
			if ( ! is_array( $item ) || empty( $item['title'] ) ) {
				continue;
			}

			$severity = isset( $item['severity'] ) ? (string) $item['severity'] : '';
			$out[]    = array(
				'severity' => in_array( $severity, array( 'good', 'warning', 'critical' ), true ) ? $severity : 'warning',
				'title'    => self::clean( $item['title'] ),
				'detail'   => self::clean( isset( $item['detail'] ) ? $item['detail'] : '' ),
			);

			if ( count( $out ) >= self::MAX_FINDINGS ) {
				break;
			}
		}
		return $out;
	}

	/** @return array<int, array{title:string,steps:string[],docs_url:string,priority:string}> */
	private static function normalize_solutions( array $decoded ) {
		$out = array();
		if ( empty( $decoded['solutions'] ) || ! is_array( $decoded['solutions'] ) ) {
			return $out;
		}

		foreach ( $decoded['solutions'] as $item ) {
			if ( ! is_array( $item ) || empty( $item['title'] ) ) {
				continue;
			}

			$steps = array();
			if ( ! empty( $item['steps'] ) && is_array( $item['steps'] ) ) {
				foreach ( $item['steps'] as $step ) {
					$steps[] = self::clean( $step );
					if ( count( $steps ) >= self::MAX_STEPS ) {
						break;
					}
				}
			}

			$priority = isset( $item['priority'] ) ? (string) $item['priority'] : '';
			$out[]    = array(
				'title'    => self::clean( $item['title'] ),
				'steps'    => $steps,
				'docs_url' => isset( $item['docs_url'] ) ? esc_url_raw( (string) $item['docs_url'] ) : '',
				'priority' => in_array( $priority, array( 'high', 'medium', 'low' ), true ) ? $priority : 'medium',
			);

			if ( count( $out ) >= self::MAX_SOLUTIONS ) {
				break;
			}
		}
		return $out;
	}

	/** Sanitize + length-cap a model-provided string, truncating at a sentence boundary. */
	private static function clean( $value ) {
		$value = wp_strip_all_tags( (string) $value );
		$value = trim( preg_replace( '/\s+/', ' ', $value ) );

		if ( self::str_len( $value ) <= self::MAX_FIELD_LEN ) {
			return $value;
		}

		$cut = self::str_cut( $value, self::MAX_FIELD_LEN );

		// Prefer ending on a complete sentence; otherwise a whole word + ellipsis.
		if ( preg_match( '/^.*[.!?](?=\s|$)/su', $cut, $m ) ) {
			return rtrim( $m[0] );
		}
		if ( preg_match( '/^.*\s/su', $cut, $m ) ) {
			return rtrim( $m[0] ) . '…';
		}
		return $cut . '…';
	}

	private static function str_len( $value ) {
		return function_exists( 'mb_strlen' ) ? mb_strlen( $value ) : strlen( $value );
	}

	private static function str_cut( $value, $length ) {
		return function_exists( 'mb_substr' ) ? mb_substr( $value, 0, $length ) : substr( $value, 0, $length );
	}
}
