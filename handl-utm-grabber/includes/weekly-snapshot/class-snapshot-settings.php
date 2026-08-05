<?php
namespace Handl\UtmrabberFree\WeeklySnapshot;

if ( ! defined( 'ABSPATH' ) ) exit;

/** Snapshot settings and sending-path detection. Stateless. */
class Handl_Snapshot_Settings {

	const OPTION = 'handl_weekly_snapshot_settings';

	const SEND_DAYS = array( 'monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday' );

	/** Known SMTP plugins; any of these active means mail leaves via an authenticated path. */
	const SMTP_PLUGINS = array(
		'wp-mail-smtp/wp_mail_smtp.php',
		'easy-wp-smtp/easy-wp-smtp.php',
		'post-smtp/postman-smtp.php',
		'fluent-smtp/fluent-smtp.php',
		'smtp-mailer/main.php',
		'wp-smtp/wp-smtp.php',
	);

	/** @return array{enabled:bool,recipient:string,send_day:string,send_hour:int,site_label:string} */
	public function get() {
		$saved = get_option( self::OPTION, array() );
		if ( ! is_array( $saved ) ) {
			$saved = array();
		}
		return $this->sanitize( array_merge( $this->defaults(), $saved ) );
	}

	/** Merge, sanitize, and persist a partial update; returns the new settings. */
	public function update( array $changes ) {
		$settings = $this->sanitize( array_merge( $this->get(), $changes ) );
		update_option( self::OPTION, $settings, false );
		return $settings;
	}

	public function defaults() {
		return array(
			'enabled'    => false,
			'recipient'  => (string) get_option( 'admin_email' ),
			'send_day'   => 'friday',
			'send_hour'  => 9,
			'site_label' => '',
		);
	}

	private function sanitize( array $settings ) {
		return array(
			'enabled'    => ! empty( $settings['enabled'] ),
			'recipient'  => is_email( $settings['recipient'] ) ? $settings['recipient'] : (string) get_option( 'admin_email' ),
			'send_day'   => in_array( $settings['send_day'], self::SEND_DAYS, true ) ? $settings['send_day'] : 'friday',
			'send_hour'  => min( 23, max( 0, (int) $settings['send_hour'] ) ),
			'site_label' => sanitize_text_field( (string) $settings['site_label'] ),
		);
	}

	/** @return string smtp_plugin|php_mail How wp_mail most likely leaves this site. */
	public function sending_path() {
		if ( ! function_exists( 'is_plugin_active' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		foreach ( self::SMTP_PLUGINS as $basename ) {
			if ( is_plugin_active( $basename ) ) {
				return 'smtp_plugin';
			}
		}
		return 'php_mail';
	}

	/** The one management surface the email links to: the HandL Options page. */
	public function manage_url() {
		return admin_url( 'admin.php?page=handl-utm-grabber.php#/handl-options' );
	}

	/** Subject prefix: the admin-set label, else the bare domain, capped at 18 chars. */
	public function subject_prefix() {
		$settings = $this->get();
		$prefix   = $settings['site_label'] !== '' ? $settings['site_label'] : $this->domain();

		$length = function_exists( 'mb_strlen' ) ? mb_strlen( $prefix ) : strlen( $prefix );
		if ( $length > 18 ) {
			$prefix = ( function_exists( 'mb_substr' ) ? mb_substr( $prefix, 0, 15 ) : substr( $prefix, 0, 15 ) ) . '…';
		}
		return $prefix;
	}

	public function domain() {
		$host = wp_parse_url( home_url(), PHP_URL_HOST );
		return is_string( $host ) ? preg_replace( '/^www\./', '', $host ) : '';
	}
}
