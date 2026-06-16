<?php
namespace Handl\UtmrabberFree\Health;

if ( ! defined( 'ABSPATH' ) ) exit;

require_once dirname( __DIR__ ) . '/integrations/class-integration.php';
require_once dirname( __DIR__ ) . '/integrations/gravity-forms/class-gravity-forms-integration.php';
require_once dirname( __DIR__ ) . '/integrations/contact-form-7/class-contact-form-7-integration.php';
require_once dirname( __DIR__ ) . '/integrations/ninja-forms/class-ninja-forms-integration.php';
require_once dirname( __DIR__ ) . '/integrations/elementor/class-elementor-integration.php';

use Handl\UtmrabberFree\Integrations\Gravity_Forms_Integration;
use Handl\UtmrabberFree\Integrations\Contact_Form_7_Integration;
use Handl\UtmrabberFree\Integrations\Ninja_Forms_Integration;
use Handl\UtmrabberFree\Integrations\Elementor_Integration;

// Registers Site Health tests; owns form integration instances for safe `direct` tests during cron, without relying on the admin-only integrations manager.
class Handl_Site_Health_Manager {

	/** @var array<string, \Handl\UtmrabberFree\Integrations\Handl_Integration>|null */
	private $integrations = null;

	public function register() {
		add_filter( 'site_status_tests', array( $this, 'add_tests' ) );
	}

	/** @return array<string, \Handl\UtmrabberFree\Integrations\Handl_Integration> */
	private function integrations() {
		if ( $this->integrations === null ) {
			$this->integrations = array(
				'contact-form-7' => new Contact_Form_7_Integration(),
				'gravity-forms'  => new Gravity_Forms_Integration(),
				'ninja-forms'    => new Ninja_Forms_Integration(),
				'elementor'      => new Elementor_Integration(),
			);
		}
		return $this->integrations;
	}

	public function add_tests( $tests ) {
		$tests['direct']['handl_version'] = array(
			'test' => array( $this, 'test_version' ),
		);
		$tests['direct']['handl_free_audit'] = array(
			'test' => array( $this, 'test_free_audit' ),
		);
		$tests['direct']['handl_caching_enabled'] = array(
			'test' => array( $this, 'test_caching' ),
		);

		$config = $this->form_tests_config();

		foreach ( $this->integrations() as $integration ) {
			$slug = $integration->get_slug();
			if ( ! isset( $config[ $slug ] ) || ! $integration->is_active() ) {
				continue;
			}
			$copy = $config[ $slug ];
			$tests['direct'][ $copy['test'] ] = array(
				'test' => function () use ( $integration, $copy ) {
					return $this->form_test( $integration, $copy );
				},
			);
		}

		return $tests;
	}

	public function test_version() {
		return array(
			'label'       => 'Upgrade your plugin to the premium version.',
			'status'      => 'recommended',
			'badge'       => array(
				'color' => 'blue',
				'label' => 'UTM',
			),
			'description' => 'To get the full benefit from your tracking experience. We highly recommend updating the plugin to the latest and premium version.',
			'actions'     => '<a href="' . handl_v3_generate_links( 'handl_version', '', 'health_check' ) . '" target="_blank"><b>Click here</b> <span aria-hidden="true" class="dashicons dashicons-external"></span></a> to learn more',
			'test'        => 'handl_version',
		);
	}

	public function test_free_audit() {
		return array(
			'label'       => 'Scan your site to make sure your campaign tracking works as expected',
			'status'      => 'recommended',
			'badge'       => array(
				'color' => 'blue',
				'label' => 'UTM',
			),
			'description' => 'Your site might get benefit from our marketing review',
			'actions'     => '<a href="https://handldigital.com/free-utm-audit/?utm_campaign=UTMAudit&utm_source=WordPress_FREE&utm_medium=health_check" target="_blank"><b>Take completely FREE audit </b> <span aria-hidden="true" class="dashicons dashicons-external"></span></a> to improve your tracking and boost up your revenue.',
			'test'        => 'handl_free_audit',
		);
	}

	public function test_caching() {
		if ( ! function_exists( 'is_plugin_active' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$cache_exist    = false;
		$recommendation = '';

		if ( function_exists( 'is_wpe' ) || function_exists( 'is_wpe_snapshot' ) ) {
			$cache_exist     = true;
			$recommendation .= "<li>- You are using WP Engine as your server provider: WP Engine uses server caching and it is known that it stripes query arguments and cookies.</li>";
		}

		if ( is_plugin_active( 'wp-rocket/wp-rocket.php' ) ) {
			$cache_exist     = true;
			$recommendation .= "<li>- You are using WP Rocket. WP Rocket does caching and it is known that it stripes query arguments and cookies.</li>";
		}

		if ( isset( $_ENV['PANTHEON_ENVIRONMENT'] ) ) {
			$recommendation .= "<li>- You are using Pantheon as your server provider: Pantheon uses server caching and it is known that it stripes query arguments and cookies.</li>";
		}

		$positive = "<p>We could not find and caching plugin installed. Hence you should be good collecting UTMs no problem.</p>";
		$negative = "<p>You might be occasionally missing UTMs or COOKIE parameters due to caching. Here is the list of things we could find that may adversely impacting the data collection.</p>
	    <ul>$recommendation</ul>
	";

		$positive_action = 'If you are having trouble collecting UTMs, or if you think you are missing some UTMs, <a href="https://wordpress.org/support/plugin/handl-utm-grabber/" target="_blank">  create a support ticket here <span aria-hidden="true" class="dashicons dashicons-external"></span></a>, we\'d be happy to take a look at it for you';
		$negative_action = $positive_action;

		return array(
			'label'       => 'You might be missing some UTMs due to server caching',
			'status'      => $cache_exist ? 'recommended' : 'good',
			'badge'       => array(
				'color' => $cache_exist ? 'red' : 'blue',
				'label' => 'UTM',
			),
			'description' => $cache_exist ? $negative : $positive,
			'actions'     => $cache_exist ? $negative_action : $positive_action,
			'test'        => 'handl_caching_enabled',
		);
	}

	/**
	 * Per-integration Site Health copy/docs links, keyed by integration slug.
	 *
	 * @return array<string, array>
	 */
	private function form_tests_config() {
		return array(
			'contact-form-7' => array(
				'label'           => 'Are your capturing/tracking UTMs properly in your Contact Form 7?',
				'positive'        => "<p>All of your Contact Form 7 set up properly. You are good to go!</p>",
				'negative_intro'  => "<p>Your Contact Form 7 forms are not capturing all the UTMs recommended. See the list of forms below having problems and resolve to make sure you do not miss any data</p>",
				'positive_action' => 'You want to up your game? <a href="https://docs.utmgrabber.com/books/101-getting-started-for-handl-utm-grabber-v3/page/native-wp-shortcodes?utm_campaign=utm_proper_cf7&utm_source=WordPress_FREE&utm_medium=health_check" target="_blank"> Click here to get the list of things you can track more <span aria-hidden="true" class="dashicons dashicons-external"></span></a>',
				'negative_action' => '<a href="https://docs.utmgrabber.com/books/contact-form-7-integration/page/contact-form-7-utm-tracking?utm_campaign=utm_proper_cf7&utm_source=WordPress_FREE&utm_medium=health_check" target="_blank"> Click here to learn the best practice of collecting UTM parameters in Contact Form 7 <span aria-hidden="true" class="dashicons dashicons-external"></span></a>',
				'test'            => 'handl_cf7_shortcodes_used',
			),
			'gravity-forms' => array(
				'label'           => 'Are your capturing/tracking UTMs properly in your Gravity Form?',
				'positive'        => "<p>All of your Gravity forms set up properly. You are good to go!</p>",
				'negative_intro'  => "<p>Your Gravity forms are not capturing all the UTMs recommended. See the list of forms below having problems and resolve to make sure you do not miss any data</p>",
				'positive_action' => 'You want to up your game? <a href="https://docs.utmgrabber.com/books/101-getting-started-for-handl-utm-grabber-v3/page/native-wp-shortcodes?utm_campaign=utm_proper_gf&utm_source=WordPress_FREE&utm_medium=health_check" target="_blank"> Click here to get the list of things you can track more <span aria-hidden="true" class="dashicons dashicons-external"></span></a>',
				'negative_action' => '<a href="https://docs.utmgrabber.com/books/gravity-forms-integration/page/gravity-forms-integration?utm_campaign=utm_proper_gf&utm_source=WordPress_FREE&utm_medium=health_check" target="_blank"> Click here to learn the best practice of collecting UTM parameters in Gravity Forms <span aria-hidden="true" class="dashicons dashicons-external"></span></a>',
				'test'            => 'handl_gf_shortcodes_used',
			),
			'ninja-forms' => array(
				'label'           => 'Are your capturing/tracking UTMs properly in your Ninja Form?',
				'positive'        => "<p>All of your Ninja Forms set up properly. You are good to go!</p>",
				'negative_intro'  => "<p>Your Ninja forms are not capturing all the UTMs recommended. See the list of forms below having problems and resolve to make sure you do not miss any data</p>",
				'positive_action' => 'You want to up your game? <a href="https://docs.utmgrabber.com/books/101-getting-started-for-handl-utm-grabber-v3/page/native-wp-shortcodes?utm_campaign=utm_proper_nf&utm_source=WordPress_FREE&utm_medium=health_check" target="_blank"> Click here to get the list of things you can track more <span aria-hidden="true" class="dashicons dashicons-external"></span></a>',
				'negative_action' => '<a href="https://docs.utmgrabber.com/books/ninja-forms-integration/page/ninja-forms-integration?utm_campaign=utm_proper_nf&utm_source=WordPress_FREE&utm_medium=health_check" target="_blank"> Click here to learn the best practice of collecting UTM parameters in Ninja Forms <span aria-hidden="true" class="dashicons dashicons-external"></span></a>',
				'test'            => 'handl_nf_shortcodes_used',
			),
			'elementor' => array(
				'label'           => 'Are your capturing/tracking UTMs properly in your Elementor forms?',
				'positive'        => "<p>All of your Elementor forms set up properly. You are good to go!</p>",
				'negative_intro'  => "<p>Your Elementor forms are not capturing all the UTMs recommended. See the list of forms below having problems and resolve to make sure you do not miss any data</p>",
				'positive_action' => 'You want to up your game? <a href="https://docs.utmgrabber.com/books/101-getting-started-for-handl-utm-grabber-v3/page/native-wp-shortcodes?utm_campaign=utm_proper_elementor&utm_source=WordPress_FREE&utm_medium=health_check" target="_blank"> Click here to get the list of things you can track more <span aria-hidden="true" class="dashicons dashicons-external"></span></a>',
				'negative_action' => '<a href="https://docs.utmgrabber.com/books/elementor-integration/page/native-elementor-form-support?utm_campaign=utm_proper_elementor&utm_source=WordPress_FREE&utm_medium=health_check" target="_blank"> Click here to learn the best practice of collecting UTM parameters in Elementor <span aria-hidden="true" class="dashicons dashicons-external"></span></a>',
				'test'            => 'handl_elementor_shortcodes_used',
			),
		);
	}

	/**
	 * Build a Site Health result for a form integration: flags any form that
	 * is missing one or more tracked params, using get_form_status().
	 *
	 * @param \Handl\UtmrabberFree\Integrations\Handl_Integration $integration
	 * @param array $copy
	 * @return array
	 */
	private function form_test( $integration, $copy ) {
		$problem_forms = array();

		foreach ( $integration->get_forms() as $form ) {
			$status = $integration->get_form_status( $form['id'] );
			if ( $status['status'] !== 'complete' && ! empty( $status['missing'] ) ) {
				$problem_forms[] = array(
					'title'      => isset( $form['title'] ) ? (string) $form['title'] : '',
					'missing'    => $status['missing'],
					'integrated' => $status['integrated'],
				);
			}
		}

		$bad = ! empty( $problem_forms );

		if ( ! $bad ) {
			return array(
				'label'       => $copy['label'],
				'status'      => 'good',
				'badge'       => array(
					'color' => 'blue',
					'label' => 'UTM',
				),
				'description' => $copy['positive'],
				'actions'     => $copy['positive_action'],
				'test'        => $copy['test'],
			);
		}

		$items = '';
		foreach ( $problem_forms as $pf ) {
			$captured = empty( $pf['integrated'] ) ? 'none yet' : $this->param_chips( $pf['integrated'] );
			$items   .= '<li><strong>' . esc_html( $pf['title'] ) . '</strong><br>'
				. '<span class="dashicons dashicons-warning" aria-hidden="true"></span> Missing: ' . $this->param_chips( $pf['missing'], true ) . '<br>'
				. '<span class="dashicons dashicons-yes" aria-hidden="true"></span> Captured: ' . $captured . '</li>';
		}

		$description = $copy['negative_intro']
			. '<ul>' . $items . '</ul>'
			. '<p>Use the one click setup to add the missing fields automatically, or follow the guide below to do it manually.</p>';

		$setup_url = admin_url( 'admin.php?page=handl-onboarding#/connect' );
		$setup_btn = '<a class="button button-primary" href="' . esc_url( $setup_url ) . '">One Click Setup</a>';

		return array(
			'label'       => $copy['label'],
			'status'      => 'recommended',
			'badge'       => array(
				'color' => 'red',
				'label' => 'UTM',
			),
			'description' => $description,
			'actions'     => '<span style="display:inline-flex;align-items:center;gap:12px;flex-wrap:wrap;">' . $setup_btn . $copy['negative_action'] . '</span>',
			'test'        => $copy['test'],
		);
	}

	/**
	 * Render param keys as inline <code> chips.
	 *
	 * @param string[] $params
	 * @param bool     $danger Tint the chips red (for missing params).
	 * @return string
	 */
	private function param_chips( $params, $danger = false ) {
		$style = $danger ? ' style="background:#fcf0f1;color:#d63638;padding:1px 6px;border-radius:3px;"' : '';
		$chips = array();
		foreach ( $params as $param ) {
			$chips[] = '<code' . $style . '>' . esc_html( $param ) . '</code>';
		}
		return implode( ' ', $chips );
	}
}
