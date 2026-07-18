<?php
namespace Handl\UtmrabberFree\TrackingDoctor;

if ( ! defined( 'ABSPATH' ) ) exit;

/** Check registry: the only place that knows which health checks exist. */
class Handl_Tracking_Doctor_Audit {

	/** @var array<string, Handl_Doctor_Check> Ordered as rendered on the page. */
	private $checks;

	/** @param \Handl\UtmrabberFree\Integrations\Handl_Integrations_Manager|null $integrations_manager */
	public function __construct( $integrations_manager = null ) {
		$this->checks = array(
			'forms'       => new Forms_Check( $integrations_manager ),
			'woocommerce' => new WooCommerce_Check(),
			'consent'     => new Consent_Check(),
			'caching'     => new Caching_Check(),
			'cookies'     => new Cookies_Check(),
		);
	}

	/** @return string[] */
	public function check_ids() {
		return array_keys( $this->checks );
	}

	/** @return array{summary:array,checks:array} */
	public function run() {
		$checks = array();
		foreach ( $this->checks as $check ) {
			$checks[] = $check->run();
		}

		return array(
			'summary' => self::summarize_checks( $checks ),
			'checks'  => $checks,
		);
	}

	/** Re-run a single check by id. @return array|\WP_Error */
	public function run_check( $check_id ) {
		if ( ! isset( $this->checks[ $check_id ] ) ) {
			return new \WP_Error( 'invalid_check', 'Unknown health check.' );
		}
		return $this->checks[ $check_id ]->run();
	}

	/** Context blob for one check's AI review; runs only that check. @return array|\WP_Error */
	public function context_for_category( $category_id ) {
		if ( ! isset( $this->checks[ $category_id ] ) ) {
			return new \WP_Error( 'invalid_category', 'Unknown review category.' );
		}

		$check = $this->checks[ $category_id ];

		return array(
			'category'       => $category_id,
			'category_label' => $check->get_label(),
			'scope'          => $check->scope_note(),
			'site'           => $check->ai_site_context(),
			'check'          => $check->run(),
			'plugin'         => array(
				'name'    => 'HandL UTM Grabber',
				'version' => defined( 'HANDL_UTM_GRABBER_FREE_VERSION' ) ? HANDL_UTM_GRABBER_FREE_VERSION : '',
				'docs'    => 'https://docs.utmgrabber.com',
			),
		);
	}

	/** @return array{passing:int,failing:int,total:int} skip checks are excluded. */
	public static function summarize_checks( $checks ) {
		$passing = 0;
		$failing = 0;
		foreach ( $checks as $check ) {
			if ( ! isset( $check['status'] ) ) {
				continue;
			}
			if ( $check['status'] === 'pass' ) {
				++$passing;
			} elseif ( in_array( $check['status'], array( 'warn', 'fail' ), true ) ) {
				++$failing;
			}
		}

		return array(
			'passing' => $passing,
			'failing' => $failing,
			'total'   => $passing + $failing,
		);
	}
}
