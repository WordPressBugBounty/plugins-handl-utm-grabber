<?php
namespace Handl\UtmrabberFree\Insights;

if ( ! defined( 'ABSPATH' ) ) exit;

/** Fluent Forms entry sidebar widget (Vue entries app, FF >= 5). HTML but no scripts,
 *  so the insight is cache-or-manual, never a fresh AI call. */
class Fluent_Forms_Entry_Card extends Handl_Entry_Card_Surface {

	public function get_slug() {
		return 'fluent-forms';
	}

	public function boot() {
		add_filter( 'fluentform/submissions_widgets', array( $this, 'add_widget' ), 10, 3 );
	}

	/**
	 * @param array  $widgets    Widgets already registered ({title, content} each).
	 * @param array  $resources  Entry resources (unused).
	 * @param object $submission Submission row with its `form` loaded.
	 * @return array
	 */
	public function add_widget( $widgets, $resources, $submission ) {
		$posted = $this->extract_posted( $submission );
		if ( empty( $posted ) ) {
			return $widgets;
		}

		$widgets['handl_insight'] = array(
			'title'   => 'HandL UTM Grabber',
			'content' => $this->content( $posted ),
		);
		return $widgets;
	}

	private function extract_posted( $submission ) {
		if ( ! is_object( $submission ) || empty( $submission->form ) ) {
			return array();
		}
		$response = json_decode( isset( $submission->response ) ? (string) $submission->response : '', true );
		return \Handl\UtmrabberFree\Submissions\Fluent_Forms_Submission_Listener::extract_from_response( $submission->form, is_array( $response ) ? $response : array() );
	}

	private function content( array $posted ) {
		$card = Handl_Insight_Provider::card_for_posted_cached( $posted );

		ob_start();
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
		</style>
		<div class="handl-entry-card">
			<dl class="handl-entry-attr">
				<?php foreach ( self::param_labels() as $key => $label ) : ?>
					<?php if ( ! empty( $posted[ $key ] ) ) : ?>
						<dt><?php echo esc_html( $label ); ?></dt>
						<dd><?php echo esc_html( $posted[ $key ] ); ?></dd>
					<?php endif; ?>
				<?php endforeach; ?>
			</dl>
			<?php if ( $card && ! empty( $card['message'] ) ) : ?>
				<div class="handl-entry-insight handl-sev-<?php echo esc_attr( isset( $card['severity'] ) ? $card['severity'] : 'info' ); ?>">
					<div class="handl-entry-msg"><?php echo esc_html( $card['message'] ); ?></div>
					<?php if ( ! empty( $card['action'] ) ) : ?>
						<div class="handl-entry-action"><?php echo esc_html( $card['action'] ); ?></div>
					<?php endif; ?>
					<?php if ( ! empty( $card['cta_url'] ) && ! empty( $card['cta_label'] ) ) : ?>
						<div class="handl-entry-links"><a class="el-button el-button--default el-button--mini" href="<?php echo esc_url( $card['cta_url'] ); ?>" target="_blank" rel="noopener"><?php echo esc_html( $card['cta_label'] ); ?></a></div>
					<?php endif; ?>
				</div>
			<?php endif; ?>
		</div>
		<?php
		return ob_get_clean();
	}
}
