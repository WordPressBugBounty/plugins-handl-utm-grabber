<?php
namespace Handl\UtmrabberFree\Consent;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Every cookie this plugin sets, in one place. Feeds the shortcode, the GDPR
 * tab preview, the Policy Guide text and the WP Consent API registration, so
 * all disclosure surfaces stay consistent.
 *
 * Free has no predefined variables or Define Your Own rules, so the list is
 * the fixed generateUTMFields() set plus the banner's own cookies.
 */
class Handl_Cookie_Declaration {

	const SHORTCODE = 'handl_cookie_declaration';

	/** Free tracking cookies live 30 days (see CaptureUTMs and the JS filler). */
	const TRACKING_DAYS = 30;

	/**
	 * Recomputed on every call
	 *
	 * @return array<int, array{name: string, purpose: string, category: string, duration: string}>
	 */
	public static function get_cookies() {
		$rows     = array();
		$seen     = array();
		$duration = self::tracking_duration_text();
		$purposes = self::purposes();

		$add = function ( $name, $category, $dur, $purpose = null ) use ( &$rows, &$seen, $purposes ) {
			$name = (string) $name;
			if ( $name === '' || isset( $seen[ $name ] ) ) {
				return;
			}
			$seen[ $name ] = true;
			if ( $purpose === null ) {
				$purpose = isset( $purposes[ $name ] )
					? $purposes[ $name ]
					: 'Tracking parameter captured from the URL and attached to form submissions.';
			}
			$rows[] = array(
				'name'     => $name,
				'purpose'  => $purpose,
				'category' => $category,
				'duration' => $dur,
			);
		};

		foreach ( generateUTMFields() as $field ) {
			$add( $field, 'marketing', $duration );
		}

		if ( Handl_Consent_Settings::is_banner_enabled() ) {
			$days = (int) apply_filters( 'handl_consent_banner_cookie_days', 365 );
			$add( 'handl_consent_status', 'functional', $days . ' days', 'Stores the visitor\'s cookie consent choices for the HandL consent banner.' );
			$add( 'gdprConsent', 'functional', $days . ' days', 'Stores whether the visitor accepted tracking cookies.' );
		}

		return apply_filters( 'handl_cookie_declaration_cookies', $rows );
	}

	/** The site's privacy policy page, if one is set and not trashed. @return \WP_Post|null */
	public static function privacy_page() {
		$id   = (int) get_option( 'wp_page_for_privacy_policy' );
		$page = $id ? get_post( $id ) : null;
		return ( $page && $page->post_status !== 'trash' ) ? $page : null;
	}

	/** Whether the cookie list is already published on the privacy policy page. */
	public static function is_on_privacy_page() {
		$page = self::privacy_page();
		return $page ? has_shortcode( $page->post_content, self::SHORTCODE ) : false;
	}

	/** Tracking cookie lifetime. Free has no session mode or custom duration. */
	public static function tracking_duration_text() {
		return self::TRACKING_DAYS . ' days';
	}

	/**
	 * Plain table that inherits the theme's styles.
	 * Attribute: category="marketing|functional".
	 *
	 * @param array|string $atts
	 * @return string
	 */
	public static function render_shortcode( $atts = array() ) {
		$atts = shortcode_atts( array( 'category' => '' ), $atts, self::SHORTCODE );
		$rows = self::get_cookies();

		if ( $atts['category'] !== '' ) {
			$category = sanitize_key( $atts['category'] );
			$rows     = array_filter( $rows, function ( $row ) use ( $category ) {
				return $row['category'] === $category;
			} );
		}

		if ( empty( $rows ) ) {
			return '';
		}

		$html  = '<table class="handl-cookie-declaration">';
		$html .= '<thead><tr>'
			. '<th scope="col">Cookie</th>'
			. '<th scope="col">Purpose</th>'
			. '<th scope="col">Category</th>'
			. '<th scope="col">Duration</th>'
			. '</tr></thead><tbody>';

		foreach ( $rows as $row ) {
			$html .= '<tr>'
				. '<td>' . esc_html( $row['name'] ) . '</td>'
				. '<td>' . esc_html( $row['purpose'] ) . '</td>'
				. '<td>' . esc_html( ucfirst( $row['category'] ) ) . '</td>'
				. '<td>' . esc_html( $row['duration'] ) . '</td>'
				. '</tr>';
		}

		$html .= '</tbody></table>';

		return $html;
	}

	/** @return array<string, string> */
	private static function purposes() {
		return array(
			'utm_source'         => 'The advertising platform or website that sent the visitor, from the utm_source URL parameter.',
			'utm_medium'         => 'The marketing channel, such as email, cpc or social, from the utm_medium URL parameter.',
			'utm_term'           => 'The paid search keyword, from the utm_term URL parameter.',
			'utm_content'        => 'The specific ad or link variation the visitor clicked, from the utm_content URL parameter.',
			'utm_campaign'       => 'The marketing campaign name, from the utm_campaign URL parameter.',
			'gclid'              => 'Click ID added to the URL by Google Ads, used to attribute the visit to a Google ad.',
			'handl_original_ref' => 'The URL of the very first page that referred the visitor to this site.',
			'handl_landing_page' => 'The full URL of the first page the visitor landed on.',
			'handl_ip'           => 'The visitor\'s IP address, stored so it can be attached to form submissions.',
			'handl_ref'          => 'The URL of the most recent page that referred the visitor.',
			'handl_url'          => 'The URL of the page where the visitor submits a form (the conversion page).',
			'email'              => 'An email address passed in the URL, for example from a newsletter link, so it can prefill forms.',
			'username'           => 'A username passed in the URL so it can prefill forms.',
		);
	}
}
