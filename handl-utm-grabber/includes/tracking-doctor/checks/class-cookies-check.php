<?php
namespace Handl\UtmrabberFree\TrackingDoctor;

if ( ! defined( 'ABSPATH' ) ) exit;

/** HandL cookie configuration: HTTPS/Secure, append-all-links, and the fixed free-tier facts. */
class Cookies_Check extends Handl_Doctor_Check {

	// Must match the setcookie() call in handl-utm-grabber.php.
	const COOKIE_SAMESITE = 'Lax';
	const COOKIE_DAYS     = 30;

	public function get_id() {
		return 'cookies';
	}

	public function get_label() {
		return 'Cookie settings';
	}

	public function scope_note() {
		return 'ONLY review HandL cookie configuration (HTTPS/Secure, SameSite, duration, append-all-links). Do NOT mention HttpOnly — HandL intentionally defaults it off so JavaScript form integrations can read attribution values; that is expected and not a finding. Do not mention individual form hidden fields.';
	}

	public function run() {
		$append_all = get_option( 'hug_append_all' ) == 1;
		// Visitor cookies are set on the front end, so judge HTTPS by the site's
		// home URL rather than is_ssl() (which reflects this admin-ajax request).
		$secure = ( wp_parse_url( home_url(), PHP_URL_SCHEME ) === 'https' );

		$details = array(
			'append_all'     => $append_all,
			'secure'         => $secure,
			'samesite'       => self::COOKIE_SAMESITE,
			'duration'       => self::COOKIE_DAYS . ' days (free default)',
			'cookie_catalog' => $this->cookie_catalog(),
		);

		if ( ! $secure ) {
			return $this->build_check(
				'warn',
				'Site is not served over HTTPS; attribution cookies may not be marked Secure.',
				$details,
				array(
					'label' => 'Fix this',
					'url'   => admin_url( 'admin.php?page=handl-utm-grabber.php#/handl-options' ),
					'type'  => 'link',
				)
			);
		}

		return $this->build_check(
			'pass',
			'Cookie configuration looks compatible with HandL first-party attribution.',
			$details
		);
	}

	private function cookie_catalog() {
		$out = array();
		foreach ( $this->param_catalog() as $row ) {
			$out[] = array(
				'key'     => $row['key'],
				'label'   => $row['label'],
				'capture' => sprintf( 'First-party cookie on landing (%d-day default)', self::COOKIE_DAYS ),
			);
		}
		return $out;
	}
}
