<?php
namespace Handl\UtmrabberFree\Insights;

if ( ! defined( 'ABSPATH' ) ) exit;

/** Reads recorded leads and analyzes them. Single source of truth; stateless. */
class Handl_Insight_Provider {

	/** Stable per-lead token; also seeds the AI cache key. */
	public static function ref_for( array $record ) {
		$posted = isset( $record['posted'] ) && is_array( $record['posted'] ) ? $record['posted'] : array();
		return self::cache_key( $posted );
	}

	/**
	 * Canonical payload hash: value-stringified and key-sorted, so the same lead
	 * hashes identically no matter which surface built the payload (the live
	 * listener and the stored-submission extractors emit keys in different orders).
	 */
	private static function cache_key( array $posted ) {
		$posted = array_map( 'strval', $posted );
		ksort( $posted );
		return md5( Handl_AI_Analyzer::PROMPT_VERSION . wp_json_encode( $posted ) );
	}

	/**
	 * Recent leads (newest-first, capped per plugin) WITHOUT analysis - cheap, no AI.
	 * Each record gains `ref` (for the per-lead detail fetch) and `see_why_url`.
	 */
	public static function recent_records( $per_plugin = null ) {
		$flat = array();
		foreach ( Handl_Lead_Recorder::get_buckets() as $records ) {
			if ( ! is_array( $records ) ) {
				continue;
			}
			if ( $per_plugin !== null ) {
				$records = array_slice( $records, 0, (int) $per_plugin );
			}
			foreach ( $records as $record ) {
				if ( is_array( $record ) ) {
					$flat[] = $record;
				}
			}
		}

		usort( $flat, function ( $a, $b ) {
			$ta = isset( $a['created_at'] ) ? strtotime( $a['created_at'] ) : 0;
			$tb = isset( $b['created_at'] ) ? strtotime( $b['created_at'] ) : 0;
			return $tb - $ta;
		} );

		return array_map( function ( $record ) {
			$record['ref']         = self::ref_for( $record );
			$record['see_why_url'] = self::see_why_url( $record );
			return $record;
		}, $flat );
	}

	/** Analyzed top card for a raw payload, or null. Runs at most one AI call. */
	public static function card_for_posted( array $posted ) {
		$cards = self::cards_for( $posted );
		return ! empty( $cards ) ? $cards[0] : null;
	}

	/**
	 * Top card without ever calling the AI: cached AI result if present, else the
	 * manual engine. For surfaces that render inside another plugin's request path.
	 */
	public static function card_for_posted_cached( array $posted ) {
		if ( empty( $posted ) ) {
			return null;
		}
		$cached = null;
		if ( Handl_AI_Analyzer::is_available() ) {
			$cached = get_transient( 'handl_ai_' . self::cache_key( $posted ) );
		}
		$cards = is_array( $cached ) ? $cached : Handl_Insight_Engine::analyze( $posted );

		return ! empty( $cards ) ? $cards[0] : null;
	}

	/** Analyzed top card for the lead matching $ref, or null. Runs at most one AI call. */
	public static function card_for_ref( $ref ) {
		foreach ( Handl_Lead_Recorder::get_buckets() as $records ) {
			if ( ! is_array( $records ) ) {
				continue;
			}
			foreach ( $records as $record ) {
				if ( ! is_array( $record ) || self::ref_for( $record ) !== $ref ) {
					continue;
				}
				$posted = isset( $record['posted'] ) && is_array( $record['posted'] ) ? $record['posted'] : array();
				return self::card_for_posted( $posted );
			}
		}
		return null;
	}

	/** Cards for a payload: cached AI when a connector is set, else the manual engine. */
	private static function cards_for( array $posted ) {
		if ( empty( $posted ) || ! Handl_AI_Analyzer::is_available() ) {
			return Handl_Insight_Engine::analyze( $posted );
		}
		$key    = 'handl_ai_' . self::cache_key( $posted );
		$cached = get_transient( $key );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		$ai = Handl_AI_Analyzer::analyze( $posted );
		if ( is_array( $ai ) && ! empty( $ai ) ) {
			set_transient( $key, $ai, WEEK_IN_SECONDS );
			return $ai;
		}

		return Handl_Insight_Engine::analyze( $posted );
	}

	/** Deep link for a lead: GF/Elementor entry / NF submissions / CF7 form editor; '' otherwise. */
	private static function see_why_url( array $record ) {
		$slug    = isset( $record['integration'] ) ? (string) $record['integration'] : '';
		$form_id = isset( $record['form_id'] ) ? (string) $record['form_id'] : '';
		$sub_id  = isset( $record['submission_id'] ) ? $record['submission_id'] : null;

		if ( $slug === 'gravity-forms' && $sub_id ) {
			return admin_url( sprintf( 'admin.php?page=gf_entries&view=entry&id=%s&lid=%s', rawurlencode( $form_id ), rawurlencode( (string) $sub_id ) ) );
		}
		if ( $slug === 'ninja-forms' && $form_id !== '' ) {
			return admin_url( 'admin.php?page=nf-submissions&form_id=' . rawurlencode( $form_id ) );
		}
		// Elementor Pro's submissions SPA routes `/` and `/:id` via the hash.
		if ( $slug === 'elementor' ) {
			$url = admin_url( 'admin.php?page=e-form-submissions' );
			return $sub_id ? $url . '#/' . rawurlencode( (string) $sub_id ) : $url;
		}
		// CF7 has no native submission view; link to the form editor instead.
		if ( $slug === 'contact-form-7' && $form_id !== '' ) {
			return admin_url( 'admin.php?page=wpcf7&post=' . rawurlencode( $form_id ) . '&action=edit' );
		}

		return '';
	}

	/** Core UTM params whose absence from a form is worth a "set up tracking" nudge. */
	private static $core_params = array( 'utm_source', 'utm_medium', 'utm_campaign' );

	/** @var array Cached integration instances by slug. */
	private static $integration_cache = array();

	/** @var array Cached results by "slug|form_id". */
	private static $missing_cache = array();

	/**
	 * Whether a lead's form lacks a CORE UTM field (source/medium/campaign) via get_form_status().
	 * False if unknown/inactive. Sole integrations-layer dependency.
	 */
	public static function core_fields_missing( $slug, $form_id ) {
		$slug    = (string) $slug;
		$form_id = (string) $form_id;
		$key     = $slug . '|' . $form_id;
		if ( array_key_exists( $key, self::$missing_cache ) ) {
			return self::$missing_cache[ $key ];
		}

		$integration = self::integration( $slug );
		if ( ! $integration || ! $integration->is_active() ) {
			return self::$missing_cache[ $key ] = false;
		}

		$status  = $integration->get_form_status( $form_id );
		$missing = isset( $status['missing'] ) && is_array( $status['missing'] ) ? $status['missing'] : array();
		$result  = count( array_intersect( self::$core_params, $missing ) ) > 0;

		return self::$missing_cache[ $key ] = $result;
	}

	/** Lazily require + instantiate the slug's integration (side-effect-free classes). */
	private static function integration( $slug ) {
		if ( array_key_exists( $slug, self::$integration_cache ) ) {
			return self::$integration_cache[ $slug ];
		}

		$base = dirname( __DIR__ ) . '/integrations';
		$map  = array(
			'gravity-forms'  => array( $base . '/gravity-forms/class-gravity-forms-integration.php',  '\\Handl\\UtmrabberFree\\Integrations\\Gravity_Forms_Integration' ),
			'contact-form-7' => array( $base . '/contact-form-7/class-contact-form-7-integration.php', '\\Handl\\UtmrabberFree\\Integrations\\Contact_Form_7_Integration' ),
			'ninja-forms'    => array( $base . '/ninja-forms/class-ninja-forms-integration.php',       '\\Handl\\UtmrabberFree\\Integrations\\Ninja_Forms_Integration' ),
			'elementor'      => array( $base . '/elementor/class-elementor-integration.php',           '\\Handl\\UtmrabberFree\\Integrations\\Elementor_Integration' ),
		);

		if ( ! isset( $map[ $slug ] ) ) {
			return self::$integration_cache[ $slug ] = null;
		}

		require_once $base . '/class-integration.php';
		require_once $map[ $slug ][0];
		$class = $map[ $slug ][1];

		return self::$integration_cache[ $slug ] = new $class();
	}
}
