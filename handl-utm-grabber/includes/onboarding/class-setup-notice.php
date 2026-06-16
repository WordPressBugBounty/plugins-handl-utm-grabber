<?php
namespace Handl\UtmrabberFree\Onboarding;

if ( ! defined( 'ABSPATH' ) ) exit;

use Handl\UtmrabberFree\Integrations\Handl_Integrations_Manager;

/**
 * Admin notice nudging users to finish form setup, driven by live integration
 * state. Variants: connect (nothing integrated), finish (partial), tour (all
 * done but onboarding incomplete). Dismiss snoozes per variant; self-clears once
 * forms are connected.
 */
class Handl_Setup_Notice {

	const NONCE        = 'handl_setup_notice';
	const SNOOZE_META  = 'handl_setup_notice_snoozed_until'; // { variant_key: expiry_ts }
	const SNOOZE_DAYS  = 7;
	const DISMISS_DAYS = 60;

	const ONBOARDING_PAGE  = 'handl-onboarding';
	const COMPLETED_OPTION = 'handl_onboarding_completed';
	const REDIRECT_OPTION  = 'handl_onboarding_redirect';

	/** @var Handl_Integrations_Manager|null */
	private $integrations_manager;

	/** @param Handl_Integrations_Manager|null $integrations_manager */
	public function __construct( $integrations_manager = null ) {
		$this->integrations_manager = $integrations_manager;
	}

	public function register() {
		// add_action( 'admin_init', array( $this, 'maybe_handle_test_reset' ) );
		add_action( 'admin_notices', array( $this, 'render' ) );
		add_action( 'wp_ajax_handl_dismiss_setup_notice', array( $this, 'ajax_dismiss' ) );
		add_action( 'admin_footer', array( $this, 'render_dismiss_script' ) );
	}

	/**
	 * Pick the variant to show based on live integration status.
	 *
	 * @return array{key:string,plugins:string[],heading:string,body:string,cta_label:string,cta_hash:string}|null
	 */
	private function resolve_variant() {
		if ( ! $this->integrations_manager ) {
			return null;
		}

		$statuses = $this->integrations_manager->get_all_statuses();

		$qualifying    = array();
		$incomplete    = array();
		$all_none      = true;
		$all_complete  = true;

		foreach ( $statuses as $slug => $status ) {
			// Only consider active plugins that have forms.
			if ( empty( $status['active'] ) || empty( $status['total_forms'] ) ) {
				continue;
			}
			$qualifying[] = $slug;

			$state = isset( $status['status'] ) ? $status['status'] : 'none';
			if ( $state !== 'none' ) {
				$all_none = false;
			}
			if ( $state !== 'complete' ) {
				$all_complete = false;
				$incomplete[] = $slug;
			}
		}

		if ( empty( $qualifying ) ) {
			return null;
		}

		if ( $all_complete ) {
			if ( get_option( self::COMPLETED_OPTION, false ) ) {
				return null;
			}
			return array(
				'key'       => 'tour',
				'plugins'   => array(),
				'heading'   => 'Your forms are connected. Now learn to read the data.',
				'body'      => 'Get the free 7-day attribution crash course: one tactic per email on spotting winning campaigns, cutting wasted budget, and reporting it in plain English. Same emails we send paying customers. Plain text, no fluff, unsubscribe in one click.',
				'cta_label' => 'Get the free course',
				'cta_hash'  => '/learn',
			);
		}

		if ( $all_none ) {
			return array(
				'key'       => 'connect',
				'plugins'   => $qualifying,
				'heading'   => "You're one step away from knowing where every lead comes from.",
				'body'      => 'UTM Grabber is tracking visitors, but submissions from your ' . $this->plugin_list( $qualifying ) . ' forms are not capturing that data yet.',
				'cta_label' => 'Set up in 15 seconds',
				'cta_hash'  => '/connect',
			);
		}

		return array(
			'key'       => 'finish',
			'plugins'   => $incomplete,
			'heading'   => 'A few of your forms are still missing attribution.',
			'body'      => 'Great start. Submissions from your ' . $this->plugin_list( $incomplete ) . ' forms still are not capturing campaign data.',
			'cta_label' => 'Finish setup in 15 seconds',
			'cta_hash'  => '/connect',
		);
	}

	private function should_show( $variant ) {
		if ( ! $variant ) {
			return false;
		}

		// Don't double up while a fresh activation is redirecting to the wizard.
		if ( get_option( self::REDIRECT_OPTION, false ) ) {
			return false;
		}

		$snoozes = $this->get_snoozes();
		$until   = isset( $snoozes[ $variant['key'] ] ) ? (int) $snoozes[ $variant['key'] ] : 0;
		if ( $until && time() < $until ) {
			return false;
		}

		return true;
	}

	public function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		// Skip when already inside the onboarding wizard.
		if ( isset( $_GET['page'] ) && $_GET['page'] === self::ONBOARDING_PAGE ) {
			return;
		}

		$variant = $this->resolve_variant();
		if ( ! $this->should_show( $variant ) ) {
			return;
		}

		$cta_url = admin_url( 'admin.php?page=' . self::ONBOARDING_PAGE ) . '#' . $variant['cta_hash'];
		$nonce   = wp_create_nonce( self::NONCE );
		?>
		<div class="notice notice-info is-dismissible handl-setup-notice" data-key="<?php echo esc_attr( $variant['key'] ); ?>" data-nonce="<?php echo esc_attr( $nonce ); ?>">
			<p style="margin: 0.5em 0 0.2em; font-size: 14px;">
				<strong><?php echo esc_html( $variant['heading'] ); ?></strong>
			</p>
			<p style="margin: 0 0 0.6em;">
				<?php echo wp_kses_post( $variant['body'] ); ?>
			</p>
			<p style="margin: 0 0 0.6em;">
				<a href="<?php echo esc_url( $cta_url ); ?>" class="button button-primary"><?php echo esc_html( $variant['cta_label'] ); ?></a>
				<a href="#" class="handl-setup-notice-action" data-action="snooze" style="margin-left: 10px; text-decoration: none;">Maybe later</a>
				<a href="#" class="handl-setup-notice-action" data-action="dismiss" style="margin-left: 10px; color: #646970; text-decoration: none;">Dismiss</a>
			</p>
		</div>
		<?php
	}

	public function render_dismiss_script() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		?>
		<script>
		(function($) {
			function send(action, key, nonce) {
				$.post(ajaxurl, {
					action: 'handl_dismiss_setup_notice',
					notice_action: action,
					key: key,
					nonce: nonce
				});
			}
			$(document).on('click', '.handl-setup-notice .handl-setup-notice-action', function(e) {
				e.preventDefault();
				var $n = $(this).closest('.handl-setup-notice');
				send($(this).data('action'), $n.data('key'), $n.data('nonce'));
				$n.fadeOut(200, function() { $(this).remove(); });
			});
			// Native "x" = snooze.
			$(document).on('click', '.handl-setup-notice .notice-dismiss', function() {
				var $n = $(this).closest('.handl-setup-notice');
				send('snooze', $n.data('key'), $n.data('nonce'));
			});
		})(jQuery);
		</script>
		<?php
	}

	public function ajax_dismiss() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Unauthorized' ), 403 );
		}
		if ( ! check_ajax_referer( self::NONCE, 'nonce', false ) ) {
			wp_send_json_error( array( 'message' => 'Invalid nonce' ), 400 );
		}

		$action = isset( $_POST['notice_action'] ) ? sanitize_key( wp_unslash( $_POST['notice_action'] ) ) : '';
		$key    = isset( $_POST['key'] ) ? sanitize_key( wp_unslash( $_POST['key'] ) ) : '';
		if ( $key === '' ) {
			wp_send_json_error( array( 'message' => 'Missing variant key' ), 400 );
		}

		$days            = ( $action === 'dismiss' ) ? self::DISMISS_DAYS : self::SNOOZE_DAYS;
		$snoozes         = $this->get_snoozes();
		$snoozes[ $key ] = time() + ( $days * DAY_IN_SECONDS );
		update_user_meta( get_current_user_id(), self::SNOOZE_META, $snoozes );

		wp_send_json_success( array( 'key' => $key, 'snoozed_days' => $days ) );
	}

	/** @return array<string, int> Map of variant key to expiry timestamp. */
	private function get_snoozes() {
		$snoozes = get_user_meta( get_current_user_id(), self::SNOOZE_META, true );
		return is_array( $snoozes ) ? $snoozes : array();
	}

	/** Grammatically join plugin labels (each bolded): "X", "X and Y", "X, Y, and Z". @param string[] $slugs @return string HTML */
	private function plugin_list( $slugs ) {
		$labels = array();
		foreach ( $slugs as $slug ) {
			$integration = $this->integrations_manager ? $this->integrations_manager->get_integration( $slug ) : null;
			$label       = $integration ? $integration->get_label() : $slug;
			$labels[]    = '<strong>' . esc_html( $label ) . '</strong>';
		}

		$count = count( $labels );
		if ( $count === 0 ) {
			return 'your';
		}
		if ( $count === 1 ) {
			return $labels[0];
		}
		if ( $count === 2 ) {
			return $labels[0] . ' and ' . $labels[1];
		}

		$last = array_pop( $labels );
		return implode( ', ', $labels ) . ', and ' . $last;
	}

	/*
	 * Dev-only reset helper
	public static function reset_state( $user_id = null, $include_onboarding = false ) {
		$user_id = $user_id ? (int) $user_id : get_current_user_id();
		if ( $user_id ) {
			delete_user_meta( $user_id, self::SNOOZE_META );
		}
		if ( $include_onboarding ) {
			delete_option( self::COMPLETED_OPTION );
		}
	}

	public function maybe_handle_test_reset() {
		if ( ! isset( $_GET['handl_reset_setup_notice'] ) || ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$include_onboarding = ( $_GET['handl_reset_setup_notice'] === 'onboarding' );
		self::reset_state( null, $include_onboarding );
	}
	*/
}
