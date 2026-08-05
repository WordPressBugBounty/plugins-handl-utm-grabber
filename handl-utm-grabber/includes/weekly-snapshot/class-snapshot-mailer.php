<?php
namespace Handl\UtmrabberFree\WeeklySnapshot;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * The send pipeline: freeze one payload, compose subject/preheader from it,
 * render the body from it, then wp_mail from the user's own server. Subject
 * and body cannot disagree by construction — both render from the same
 * frozen payload.
 */
class Handl_Snapshot_Mailer {

	const REVIEW_MILESTONES   = array( 1000, 100, 10 );
	const REVIEW_CAP_DAYS     = 30;   // one review ask per 30 days, across surfaces
	const ZERO_RESEND_DAYS    = 28;   // repeat zero-weeks throttle to monthly...
	const ZERO_MAX_SENDS      = 3;    // ...then stop; the plugin page surfaces it instead

	/** @var Handl_Snapshot_Settings */
	private $settings;

	/** @var Handl_Snapshot_State */
	private $state;

	/** @var Handl_Snapshot_Rollup */
	private $rollup;

	/** @var Handl_Snapshot_Collector */
	private $collector;

	/** @var Handl_Snapshot_Composer */
	private $composer;

	/** @var Handl_Snapshot_Renderer */
	private $renderer;

	public function __construct() {
		$this->settings  = new Handl_Snapshot_Settings();
		$this->state     = new Handl_Snapshot_State();
		$this->rollup    = new Handl_Snapshot_Rollup();
		$this->collector = new Handl_Snapshot_Collector(
			$this->settings,
			$this->rollup,
			new Handl_Snapshot_Doctor( $this->settings )
		);
		$this->composer  = new Handl_Snapshot_Composer();
		$this->renderer  = new Handl_Snapshot_Renderer( $this->settings );
	}

	/** Cron callback: the real weekly send, with zero-week throttling and state bookkeeping. */
	public function send_weekly() {
		$settings = $this->settings->get();
		if ( ! $settings['enabled'] ) {
			return;
		}

		$payload = $this->collector->payload();
		$state   = $this->state->get();

		if ( $payload['data_state'] === 'zero' ) {
			if ( ! $this->zero_send_allowed( $state ) ) {
				$this->state->update( array( 'zero_streak' => (int) $state['zero_streak'] + 1 ) );
				return;
			}
		}

		$compose   = $this->composer->compose( $payload, $state['last_tiers'] );
		$milestone = $this->review_milestone( $payload, $state );

		$sent = $this->send( $payload, $compose, $settings['recipient'], array(
			'review_milestone' => $milestone,
		) );
		if ( ! $sent ) {
			return;
		}

		$changes = array(
			'last_sent_at' => gmdate( 'c' ),
			'last_error'   => '',
		);
		if ( $payload['data_state'] === 'zero' ) {
			$changes['zero_streak']       = (int) $state['zero_streak'] + 1;
			$changes['zero_sends']        = (int) $state['zero_sends'] + 1;
			$changes['last_zero_sent_at'] = gmdate( 'c' );
		} else {
			$changes['zero_streak']       = 0;
			$changes['zero_sends']        = 0;
			$changes['last_zero_sent_at'] = '';
		}
		if ( $milestone !== null ) {
			$changes['review_last_ask_at']    = gmdate( 'c' );
			$changes['review_last_milestone'] = $milestone;
		}
		$this->state->update( $changes );
		$this->state->push_tier( $compose['tier'] );
		$this->rollup->record_week( $payload['week']['end'], $payload['totals']['conversions'] );
	}

	/** @return array{sent:bool,message:string} Manual test send to the configured recipient. */
	public function send_test() {
		$settings = $this->settings->get();
		$payload  = $this->collector->payload();
		$compose  = $this->composer->compose( $payload, array() );

		$sent = $this->send( $payload, $compose, $settings['recipient'], array(
			'is_test'        => true,
			'subject_prefix' => '[Test] ',
		) );

		if ( $sent ) {
			// A stale failure alert outliving a working setup reads as "still broken".
			$this->state->update( array( 'last_error' => '' ) );
			return array(
				'sent'    => true,
				'message' => sprintf( 'Test Snapshot sent to %s.', $settings['recipient'] ),
			);
		}

		$state = $this->state->get();
		return array(
			'sent'    => false,
			'message' => $state['last_error'] !== ''
				? sprintf( 'Sending failed: %s', $state['last_error'] )
				: 'Sending failed. Your site may not be able to send email. An SMTP plugin usually fixes this.',
		);
	}

	/** Rendered HTML for the in-admin preview; same pipeline, nothing sent. */
	public function preview_html() {
		$payload = $this->collector->payload();
		$compose = $this->composer->compose( $payload, array() );
		return $this->renderer->render( $payload, $compose, array( 'is_test' => true ) );
	}

	private function send( array $payload, array $compose, $recipient, array $opts = array() ) {
		$html = $this->renderer->render( $payload, $compose, $opts );

		$subject = ( isset( $opts['subject_prefix'] ) ? $opts['subject_prefix'] : '' ) . $compose['subject'];
		$headers = array(
			'Content-Type: text/html; charset=UTF-8',
		);

		$result = $this->deliver( $recipient, $subject, $html, $headers );
		if ( $result !== true ) {
			$this->state->update( array(
				'last_error' => $result instanceof \WP_Error ? $result->get_error_message() : 'Send failed.',
			) );
			return false;
		}
		return true;
	}

	/**
	 * wp_mail from the user's own server
	 *
	 * @return true|\WP_Error True on accepted-for-delivery, WP_Error with the
	 *                        real failure message otherwise.
	 */
	private function deliver( $recipient, $subject, $html, array $headers ) {
		$error = null;

		$capture_error = function ( $wp_error ) use ( &$error ) {
			$error = $wp_error;
		};
		// From-NAME only. The default wordpress@{domain} address is at least
		// domain-aligned; forcing a prettier address is how you break SPF.
		$from_name = function () {
			return 'Weekly Snapshot';
		};

		add_filter( 'wp_mail_from_name', $from_name, 99 );
		add_action( 'wp_mail_failed', $capture_error );

		$sent = wp_mail( $recipient, $subject, $html, $headers );

		remove_action( 'wp_mail_failed', $capture_error );
		remove_filter( 'wp_mail_from_name', $from_name, 99 );

		if ( $sent ) {
			return true;
		}
		return $error instanceof \WP_Error
			? $error
			: new \WP_Error( 'send_failed', 'wp_mail returned false without an error.' );
	}

	/**
	 * First zero week emails immediately; repeats monthly; after 3 zero emails,
	 * stop until data returns. Gates on emails SENT (zero_sends), not weeks
	 * elapsed (zero_streak) — skipped weeks must not count toward the stop.
	 */
	private function zero_send_allowed( array $state ) {
		$sends = isset( $state['zero_sends'] ) ? (int) $state['zero_sends'] : 0;
		if ( $sends >= self::ZERO_MAX_SENDS ) {
			return false;
		}
		if ( $sends === 0 ) {
			return true;
		}
		$last = strtotime( (string) $state['last_zero_sent_at'] );
		return $last === false || $last <= time() - self::ZERO_RESEND_DAYS * DAY_IN_SECONDS;
	}

	/** @return int|null Milestone to celebrate, respecting the 30-day cap on review asks. */
	private function review_milestone( array $payload, array $state ) {
		// All-clear weeks carry the support card; never stack the review ask on it.
		if ( $payload['data_state'] !== 'normal' || $payload['doctor']['all_clear'] ) {
			return null;
		}

		$last_ask = strtotime( (string) $state['review_last_ask_at'] );
		if ( $last_ask !== false && $last_ask > time() - self::REVIEW_CAP_DAYS * DAY_IN_SECONDS ) {
			return null;
		}

		$last_milestone = isset( $state['review_last_milestone'] ) ? (int) $state['review_last_milestone'] : 0;
		foreach ( self::REVIEW_MILESTONES as $milestone ) {
			if ( $payload['leads_alltime'] >= $milestone && $milestone > $last_milestone ) {
				return $milestone;
			}
		}
		return null;
	}
}
