<?php
namespace Handl\UtmrabberFree\Notices;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Central registry for dismissible admin notices, ported from the premium
 * plugin's Notice_Manager with the same public method names so the
 * two can converge into a shared package later.
 *
 * Keep this class feature-agnostic: triggers, copy, and endpoints belong to
 * the modules that register notices.
 *
 * Per-user state lives in one meta map:
 *   handl_notice_state: { id: { impressions, last_shown_at, dismissed_count,
 *                               dismissed_at, clicked_at } }
 * Timestamps are GMT ISO strings; every cap is provable from the raw meta.
 */
class Handl_Notice_Manager {
	const AJAX_ACTION = 'handl_managed_notice_dismiss';
	const META_KEY    = 'handl_notice_state';

	/** Cross-surface bridge: do_action( 'handl_notice_event', $id, $event ). */
	const EVENT_ACTION = 'handl_notice_event';

	const EVENTS = array( 'impression', 'dismissal', 'click' );

	/** @var array<string, array> */
	private $notices = array();

	/** @var array<string, array{max_dismissals:int, cooldown_days:int}> */
	private $groups = array();

	/** @var self|null */
	private static $instance = null;

	private function __construct() {
		add_action( 'admin_notices', array( $this, 'render' ) );
		add_action( 'wp_ajax_' . self::AJAX_ACTION, array( $this, 'ajax_dismiss' ) );
		add_action( self::EVENT_ACTION, array( $this, 'record_event' ), 10, 2 );
	}

	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Register a notice. Args (premium's set, then the free extensions):
	 *
	 * - title, message, type, actions, capability, show_when, dismiss_on_action
	 *   as in premium. actions entries: { label, url, primary?, target?,
	 *   button? } — button:false renders a plain link.
	 * - priority        lower renders first; ONE eligible notice per page load.
	 * - max_impressions lifetime render cap; 0 = unlimited.
	 * - cooldown_days   minimum days between two renders.
	 * - schedule_days   day offsets from epoch() (e.g. [3,10,21]); one
	 *   impression per slot, in order, retires after the last. Overrides
	 *   cooldown_days. epoch: callable returning a Y-m-d site-local date.
	 * - max_dismissals  per-notice; default 1 keeps premium's
	 *   dismiss-once-retire-forever behavior.
	 * - revive_after_days  0 (default) = retirement is permanent. N = a
	 *   notice retired by its impression or dismissal cap becomes eligible
	 *   again after N days with no events, with fresh budgets. The reset is
	 *   written to the entry as revived_at + revive_count, so the ledger
	 *   stays provable. Ignored for schedule_days notices: the schedule is
	 *   the lifetime.
	 * - group           shared budget pool (see register_group).
	 * - render_callback prints the ENTIRE notice markup itself; called with
	 *   ($id). Must include the handl-managed-notice container + data
	 *   attributes if it wants the shared dismiss/click handlers.
	 * - renderless      state-only group member (a non-notice surface);
	 *   never renders.
	 * - track           false = the manager never writes state for it
	 *   (the promo bridge: its lifecycle belongs to the promo system).
	 *
	 * Without revive_after_days, retirement is permanent and per-user:
	 * counters never reset or decay. The only other resets are blunt:
	 * delete the user's META_KEY meta, or register under a new id (the id
	 * is the ledger key).
	 */
	public function register( $id, $args ) {
		$this->notices[ $id ] = wp_parse_args( $args, array(
			'title'             => '',
			'message'           => '',
			'type'              => 'info',
			'actions'           => array(),
			'capability'        => 'manage_options',
			'show_when'         => null,
			'dismiss_on_action' => true,
			'priority'          => 100,
			'max_impressions'   => 0,
			'cooldown_days'     => 0,
			'schedule_days'     => null,
			'epoch'             => null,
			'max_dismissals'    => 1,
			'revive_after_days' => 0,
			'group'             => null,
			'render_callback'   => null,
			'renderless'        => false,
			'track'             => true,
		) );
		$this->notices[ $id ]['seq'] = count( $this->notices );
	}

	public function register_group( $key, $args = array() ) {
		$this->groups[ $key ] = wp_parse_args( $args, array(
			'max_dismissals' => 0,   // 0 = no shared dismissal budget
			'cooldown_days'  => 0,   // minimum days between renders of ANY member
		) );
	}

	// =========================================================================
	// State
	// =========================================================================

	/** @return array<string, array> The current user's full state map. */
	private function state_map() {
		$state = get_user_meta( get_current_user_id(), self::META_KEY, true );
		return is_array( $state ) ? $state : array();
	}

	private function entry( $id ) {
		$state = $this->state_map();
		return isset( $state[ $id ] ) && is_array( $state[ $id ] ) ? $state[ $id ] : array();
	}

	/**
	 * Record an impression, dismissal, or click. Public (and mirrored on the
	 * handl_notice_event action) so non-notice surfaces — the dashboard
	 * widget, React pages — can land events in the same budgets.
	 */
	public function record_event( $id, $event ) {
		if ( ! isset( $this->notices[ $id ] ) || ! in_array( $event, self::EVENTS, true )
			|| ! $this->notices[ $id ]['track'] || ! get_current_user_id() ) {
			return;
		}

		$state = $this->state_map();
		$entry = isset( $state[ $id ] ) && is_array( $state[ $id ] ) ? $state[ $id ] : array();
		$now   = gmdate( 'c' );

		if ( $event === 'impression' ) {
			$entry['impressions']   = ( isset( $entry['impressions'] ) ? (int) $entry['impressions'] : 0 ) + 1;
			$entry['last_shown_at'] = $now;
		} elseif ( $event === 'dismissal' ) {
			$entry['dismissed_count'] = ( isset( $entry['dismissed_count'] ) ? (int) $entry['dismissed_count'] : 0 ) + 1;
			$entry['dismissed_at']    = $now;
		} else {
			$entry['clicked_at'] = $now;
			// Engaging is a better exit than the ×: retire the notice too.
			if ( $this->notices[ $id ]['dismiss_on_action'] ) {
				$entry['dismissed_count'] = ( isset( $entry['dismissed_count'] ) ? (int) $entry['dismissed_count'] : 0 ) + 1;
				$entry['dismissed_at']    = $now;
			}
		}

		$state[ $id ] = $entry;
		update_user_meta( get_current_user_id(), self::META_KEY, $state );
	}

	public function dismiss( $id ) {
		$this->record_event( $id, 'dismissal' );
	}

	public function is_dismissed( $id ) {
		if ( ! isset( $this->notices[ $id ] ) ) {
			return false;
		}
		$entry     = $this->entry( $id );
		$dismissed = isset( $entry['dismissed_count'] ) ? (int) $entry['dismissed_count'] : 0;
		return $dismissed >= (int) $this->notices[ $id ]['max_dismissals'];
	}

	/** @return array{dismissals:int, last_shown_at:string} Aggregated across members, renderless included. */
	public function group_state( $key ) {
		$state      = $this->state_map();
		$dismissals = 0;
		$last_shown = '';
		foreach ( $this->notices as $id => $notice ) {
			if ( $notice['group'] !== $key || ! isset( $state[ $id ] ) ) {
				continue;
			}
			$dismissals += isset( $state[ $id ]['dismissed_count'] ) ? (int) $state[ $id ]['dismissed_count'] : 0;
			$shown       = isset( $state[ $id ]['last_shown_at'] ) ? (string) $state[ $id ]['last_shown_at'] : '';
			if ( $shown !== '' && ( $last_shown === '' || strtotime( $shown ) > strtotime( $last_shown ) ) ) {
				$last_shown = $shown;
			}
		}
		return array( 'dismissals' => $dismissals, 'last_shown_at' => $last_shown );
	}

	public function group_capped( $key ) {
		if ( ! isset( $this->groups[ $key ] ) ) {
			return false;
		}
		$max = (int) $this->groups[ $key ]['max_dismissals'];
		return $max > 0 && $this->group_state( $key )['dismissals'] >= $max;
	}

	// =========================================================================
	// Eligibility
	// =========================================================================

	public function is_eligible( $id ) {
		if ( ! isset( $this->notices[ $id ] ) ) {
			return false;
		}
		$notice = $this->notices[ $id ];

		if ( $notice['renderless'] || ! current_user_can( $notice['capability'] ) ) {
			return false;
		}

		if ( $notice['track'] ) {
			$entry       = $this->maybe_revive( $id, $notice );
			$impressions = isset( $entry['impressions'] ) ? (int) $entry['impressions'] : 0;
			$dismissed   = isset( $entry['dismissed_count'] ) ? (int) $entry['dismissed_count'] : 0;
			$last_shown  = isset( $entry['last_shown_at'] ) ? (string) $entry['last_shown_at'] : '';

			if ( $dismissed >= (int) $notice['max_dismissals'] ) {
				return false;
			}
			if ( (int) $notice['max_impressions'] > 0 && $impressions >= (int) $notice['max_impressions'] ) {
				return false;
			}

			if ( is_array( $notice['schedule_days'] ) ) {
				if ( ! $this->schedule_slot_open( $notice, $impressions ) ) {
					return false;
				}
			} elseif ( (int) $notice['cooldown_days'] > 0 && $last_shown !== ''
				&& ( time() - strtotime( $last_shown ) ) < (int) $notice['cooldown_days'] * DAY_IN_SECONDS ) {
				return false;
			}

			if ( $notice['group'] !== null && isset( $this->groups[ $notice['group'] ] ) ) {
				if ( $this->group_capped( $notice['group'] ) ) {
					return false;
				}
				$cooldown = (int) $this->groups[ $notice['group'] ]['cooldown_days'];
				$shown    = $this->group_state( $notice['group'] )['last_shown_at'];
				if ( $cooldown > 0 && $shown !== '' && ( time() - strtotime( $shown ) ) < $cooldown * DAY_IN_SECONDS ) {
					return false;
				}
			}
		}

		if ( is_callable( $notice['show_when'] ) && ! call_user_func( $notice['show_when'] ) ) {
			return false;
		}
		return true;
	}

	/**
	 * Lazily reset a retired notice once its revive_after_days of silence
	 * have passed. The old entry is replaced by { revived_at, revive_count },
	 * so the revival itself stays visible in the ledger.
	 */
	private function maybe_revive( $id, array $notice ) {
		$entry = $this->entry( $id );

		$days = (int) $notice['revive_after_days'];
		if ( $days <= 0 || is_array( $notice['schedule_days'] ) ) {
			return $entry;
		}

		$impressions = isset( $entry['impressions'] ) ? (int) $entry['impressions'] : 0;
		$dismissed   = isset( $entry['dismissed_count'] ) ? (int) $entry['dismissed_count'] : 0;
		$retired     = $dismissed >= (int) $notice['max_dismissals']
			|| ( (int) $notice['max_impressions'] > 0 && $impressions >= (int) $notice['max_impressions'] );
		if ( ! $retired ) {
			return $entry;
		}

		$latest = 0;
		foreach ( array( 'last_shown_at', 'dismissed_at', 'clicked_at' ) as $key ) {
			if ( ! empty( $entry[ $key ] ) ) {
				$latest = max( $latest, (int) strtotime( (string) $entry[ $key ] ) );
			}
		}
		if ( $latest === 0 || ( time() - $latest ) < $days * DAY_IN_SECONDS ) {
			return $entry;
		}

		$entry = array(
			'revived_at'   => gmdate( 'c' ),
			'revive_count' => ( isset( $entry['revive_count'] ) ? (int) $entry['revive_count'] : 0 ) + 1,
		);
		$state        = $this->state_map();
		$state[ $id ] = $entry;
		update_user_meta( get_current_user_id(), self::META_KEY, $state );
		return $entry;
	}

	/** Impressions double as the slot pointer: slot N fires once, on/after epoch + schedule_days[N]. */
	private function schedule_slot_open( array $notice, $impressions ) {
		$slots = array_map( 'intval', $notice['schedule_days'] );
		sort( $slots );
		if ( $impressions >= count( $slots ) ) {
			return false;
		}
		$epoch = is_callable( $notice['epoch'] ) ? (string) call_user_func( $notice['epoch'] ) : '';
		if ( $epoch === '' ) {
			return false;
		}
		$age = (int) floor( ( strtotime( wp_date( 'Y-m-d' ) . ' 12:00:00' ) - strtotime( $epoch . ' 12:00:00' ) ) / DAY_IN_SECONDS );
		return $age >= $slots[ $impressions ];
	}

	// =========================================================================
	// Rendering
	// =========================================================================

	/**
	 * Only WP Dashboard, the Plugins screen,
	 * and the plugin's own pages — never the post editor, never another
	 * plugin's settings, and never inside the onboarding wizard itself.
	 */
	private function allowed_screen() {
		if ( isset( $_GET['page'] ) ) {
			$page = sanitize_text_field( wp_unslash( $_GET['page'] ) );
			return $page !== 'handl-onboarding' && strpos( $page, 'handl-' ) === 0;
		}
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		return $screen && in_array( $screen->id, array( 'dashboard', 'plugins' ), true );
	}

	public function render() {
		if ( ! $this->allowed_screen() ) {
			return;
		}

		$eligible = array();
		foreach ( $this->notices as $id => $notice ) {
			if ( $this->is_eligible( $id ) ) {
				$eligible[] = $id;
			}
		}
		if ( empty( $eligible ) ) {
			return;
		}

		$notices = $this->notices;
		usort( $eligible, function ( $a, $b ) use ( $notices ) {
			if ( $notices[ $a ]['priority'] !== $notices[ $b ]['priority'] ) {
				return $notices[ $a ]['priority'] - $notices[ $b ]['priority'];
			}
			return $notices[ $a ]['seq'] - $notices[ $b ]['seq'];
		} );

		$id     = $eligible[0];
		$notice = $this->notices[ $id ];

		if ( is_callable( $notice['render_callback'] ) ) {
			call_user_func( $notice['render_callback'], $id );
		} else {
			$this->print_notice( $id, $notice );
		}

		if ( $notice['track'] ) {
			$this->record_event( $id, 'impression' );
		}
		$this->print_dismiss_script();
	}

	private function print_notice( $id, array $notice ) {
		printf(
			'<div class="notice notice-%s is-dismissible handl-managed-notice" data-handl-notice-id="%s" data-handl-dismiss-on-action="%d">',
			esc_attr( $notice['type'] ),
			esc_attr( $id ),
			$notice['dismiss_on_action'] ? 1 : 0
		);
		if ( $notice['title'] !== '' ) {
			printf( '<p style="margin:0.5em 0 0.2em;font-size:14px;"><strong>%s</strong></p>', wp_kses_post( $notice['title'] ) );
		}
		if ( $notice['message'] !== '' ) {
			printf( '<p style="margin:0 0 0.6em;">%s</p>', wp_kses_post( $notice['message'] ) );
		}
		if ( ! empty( $notice['actions'] ) ) {
			echo '<p style="margin:0 0 0.6em;">';
			foreach ( $notice['actions'] as $action ) {
				$is_button = ! isset( $action['button'] ) || $action['button'];
				$classes   = 'handl-notice-action' . ( $is_button ? ' button' : '' ) . ( ! empty( $action['primary'] ) ? ' button-primary' : '' );
				printf(
					'<a href="%s" class="%s"%s style="margin-right:12px;%s">%s</a>',
					esc_url( $action['url'] ),
					esc_attr( $classes ),
					! empty( $action['target'] ) ? ' target="' . esc_attr( $action['target'] ) . '" rel="noopener noreferrer"' : '',
					$is_button ? '' : 'text-decoration:none;',
					esc_html( $action['label'] )
				);
			}
			echo '</p>';
		}
		echo '</div>';
	}

	private function print_dismiss_script() {
		$nonce = wp_create_nonce( self::AJAX_ACTION );
		?>
		<script>
		(function($) {
			var record = function (noticeId, event) {
				var data = {
					action: '<?php echo esc_js( self::AJAX_ACTION ); ?>',
					notice_id: noticeId,
					notice_event: event,
					_wpnonce: '<?php echo esc_js( $nonce ); ?>'
				};
				// sendBeacon survives the navigation a CTA click starts.
				if (navigator.sendBeacon) {
					var form = new FormData();
					Object.keys(data).forEach(function (key) { form.append(key, data[key]); });
					navigator.sendBeacon(ajaxurl, form);
				} else {
					$.post(ajaxurl, data);
				}
			};

			$(document).on('click', '.handl-managed-notice > .notice-dismiss', function () {
				record($(this).closest('.handl-managed-notice').data('handl-notice-id'), 'dismissal');
			});

			$(document).on('click', '.handl-managed-notice .handl-notice-action', function () {
				var $notice = $(this).closest('.handl-managed-notice');
				record($notice.data('handl-notice-id'), 'click');
				if ($notice.data('handl-dismiss-on-action')) {
					$notice.fadeOut(200);
				}
			});
		})(jQuery);
		</script>
		<?php
	}

	public function ajax_dismiss() {
		check_ajax_referer( self::AJAX_ACTION );

		$id    = isset( $_POST['notice_id'] ) ? sanitize_text_field( wp_unslash( $_POST['notice_id'] ) ) : '';
		$event = isset( $_POST['notice_event'] ) ? sanitize_key( wp_unslash( $_POST['notice_event'] ) ) : 'dismissal';
		if ( ! isset( $this->notices[ $id ] ) ) {
			wp_send_json_error( array( 'message' => 'Unknown notice.' ) );
		}
		if ( ! in_array( $event, array( 'dismissal', 'click' ), true ) ) {
			$event = 'dismissal';
		}

		$this->record_event( $id, $event );
		wp_send_json_success();
	}
}
