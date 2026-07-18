<?php
namespace Handl\UtmrabberFree\TrackingDoctor;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Detection mirrors Handl_Site_Health_Manager::test_caching() — keep both in sync.
 */
class Caching_Check extends Handl_Doctor_Check {

	const CACHE_PLUGINS = array(
		'wp-rocket/wp-rocket.php'             => 'WP Rocket',
		'w3-total-cache/w3-total-cache.php'   => 'W3 Total Cache',
		'litespeed-cache/litespeed-cache.php' => 'LiteSpeed Cache',
		'wp-super-cache/wp-cache.php'         => 'WP Super Cache',
		'sg-cachepress/sg-cachepress.php'     => 'SiteGround Optimizer',
		'autoptimize/autoptimize.php'         => 'Autoptimize',
		'cloudflare/cloudflare.php'           => 'Cloudflare',
	);

	public function get_id() {
		return 'caching';
	}

	public function get_label() {
		return 'Caching & performance';
	}

	public function scope_note() {
		return 'ONLY review caching/CDN impact on UTM query strings and first-party cookies. Do NOT mention form integrations, hidden fields, or WooCommerce setup steps unrelated to caching.';
	}

	public function run() {
		$this->ensure_plugin_functions();

		$findings = array();

		if ( function_exists( 'is_wpe' ) || function_exists( 'is_wpe_snapshot' ) ) {
			$findings[] = 'WP Engine server caching (can strip query args and cookies)';
		}
		if ( isset( $_ENV['PANTHEON_ENVIRONMENT'] ) ) {
			$findings[] = 'Pantheon server caching';
		}
		foreach ( self::CACHE_PLUGINS as $file => $label ) {
			if ( is_plugin_active( $file ) ) {
				$findings[] = $label;
			}
		}

		if ( empty( $findings ) ) {
			return $this->build_check(
				'pass',
				'No known caching layer detected that commonly strips UTM parameters or cookies.',
				array( 'findings' => array() )
			);
		}

		return $this->build_check(
			'warn',
			'Caching may strip UTM query strings or first-party cookies: ' . implode( '; ', $findings ) . '.',
			array( 'findings' => $findings ),
			array(
				'label' => 'Fix this',
				'url'   => 'https://docs.utmgrabber.com/books/102-getting-started-with-handl-utm-grabber-v3',
				'type'  => 'docs',
			)
		);
	}
}
