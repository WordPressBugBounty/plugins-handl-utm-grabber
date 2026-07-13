<?php
namespace Handl\UtmrabberFree\Insights;

if ( ! defined( 'ABSPATH' ) ) exit;

/** Gravity Forms entry-detail meta box: attribution summary (instant) + async insight. */
class Gravity_Forms_Entry_Card extends Handl_Entry_Card_Surface {

	public function get_slug() {
		return 'gravity-forms';
	}

	public function boot() {
		add_filter( 'gform_entry_detail_meta_boxes', array( $this, 'register_meta_box' ), 10, 3 );
	}

	/** Native meta box: minimizable + toggleable via Screen Options for free. */
	public function register_meta_box( $meta_boxes, $entry, $form ) {
		$posted = $this->extract_posted( $form, $entry );
		if ( empty( $posted ) ) {
			return $meta_boxes;
		}

		wp_enqueue_script(
			'handl-insight-card',
			plugins_url( 'js/insight-card.js', __FILE__ ),
			array(),
			defined( 'HANDL_UTM_GRABBER_FREE_VERSION' ) ? HANDL_UTM_GRABBER_FREE_VERSION : false,
			true
		);
		wp_localize_script( 'handl-insight-card', 'handlInsightCard', array(
			'ajax_url' => admin_url( 'admin-ajax.php' ),
			'nonce'    => wp_create_nonce( Handl_Insights_Ajax::NONCE_ACTION ),
			'posted'   => $posted,
		) );

		$meta_boxes['handl_insight'] = array(
			'title'    => 'HandL UTM Grabber',
			'callback' => array( $this, 'render' ),
			'context'  => 'side',
		);

		return $meta_boxes;
	}

	/** Meta box body; GF supplies array{ entry, form, mode }. */
	public function render( $args ) {
		$posted = $this->extract_posted( $args['form'], $args['entry'] );

		self::styles();
		?>
		<div class="handl-entry-card">
			<dl class="handl-entry-attr">
				<?php foreach ( self::param_labels() as $key => $label ) : ?>
					<?php if ( ! empty( $posted[ $key ] ) ) : ?>
						<dt><?php echo esc_html( $label ); ?></dt>
						<dd><?php echo esc_html( $posted[ $key ] ); ?></dd>
					<?php endif; ?>
				<?php endforeach; ?>
			</dl>
			<div class="handl-entry-insight" aria-live="polite">
				<p class="handl-skel-label">Analyzing this lead&#8217;s attribution: source, campaign, and tracking gaps&hellip;</p>
				<div class="handl-skel-line handl-skel-line--msg"></div>
				<div class="handl-skel-line handl-skel-line--action"></div>
			</div>
		</div>
		<?php
	}

	private function extract_posted( $form, $entry ) {
		return \Handl\UtmrabberFree\Submissions\Gravity_Forms_Submission_Listener::extract_from_entry( $form, $entry );
	}

	private static function styles() {
		static $done = false;
		if ( $done ) {
			return;
		}
		$done = true;
		?>
		<style>
			.handl-entry-card .handl-entry-attr{margin:0 0 12px;font-size:12px;}
			.handl-entry-card .handl-entry-attr dt{color:#646970;text-transform:uppercase;letter-spacing:.03em;font-size:10px;margin-top:6px;}
			.handl-entry-card .handl-entry-attr dd{margin:0 0 2px;color:#1d2327;word-break:break-word;}
			.handl-entry-insight{border-left:4px solid #646970;background:#f6f7f7;border-radius:6px;padding:10px 12px;}
			.handl-entry-insight.handl-sev-warning{border-left-color:#d63638;background:#fcf0f1;}
			.handl-entry-insight.handl-sev-success{border-left-color:#00a32a;background:#edfaef;}
			.handl-entry-insight.handl-sev-info{border-left-color:#2271b1;background:#f0f6fc;}
			.handl-entry-insight .handl-entry-msg{font-weight:600;color:#1d2327;line-height:1.4;}
			.handl-entry-insight .handl-entry-action{color:#50575e;margin-top:3px;line-height:1.4;}
			.handl-entry-insight .handl-entry-links{margin-top:10px;}
			.handl-entry-insight .handl-entry-btns{display:flex;flex-wrap:wrap;gap:8px;align-items:center; height:auto;}
			.handl-entry-insight .handl-entry-btns .button{min-height:30px;height:auto;}
			.handl-btn-upgrade.button-primary{background:#8047b5;border-color:#6c33a3;}
			.handl-skel-label{font-size:12px;color:#646970;margin:0 0 8px;}
			.handl-skel-line{height:12px;border-radius:4px;margin-bottom:8px;background:linear-gradient(90deg,#e7e8ea 25%,#f2f3f4 37%,#e7e8ea 63%);background-size:400% 100%;animation:handl-skel 1.4s ease infinite;}
			.handl-skel-line--msg{width:85%;}
			.handl-skel-line--action{width:65%;margin-bottom:0;}
			@keyframes handl-skel{0%{background-position:100% 50%;}100%{background-position:0 50%;}}
		</style>
		<?php
	}
}
