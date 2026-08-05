<?php
namespace Handl\UtmrabberFree\WeeklySnapshot;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * The inline opt-in form shared by every non-React Snapshot ask (admin
 * notices, dashboard widget): editable email + one button, posting to
 * handl_snapshot_notice_optin. Lives in this module because the endpoint
 * does; the notices module only decides where the form appears.
 */
class Handl_Snapshot_Optin_Form {

	/** @var bool */
	private static $script_printed = false;

	/**
	 * Print the form. $surface is a registered notice id (or renderless
	 * surface id) so the opt-in click lands in the shared notice state.
	 *
	 * @param string $surface e.g. 'snap-value', 'surface:dashboard-widget'.
	 */
	public static function markup( $surface ) {
		$user  = wp_get_current_user();
		$email = $user && $user->user_email ? $user->user_email : (string) get_option( 'admin_email' );
		?>
		<div class="handl-snapshot-optin-wrap">
			<form class="handl-snapshot-optin-form" style="display:flex;flex-wrap:wrap;gap:6px;align-items:center;margin:0 0 0.4em;"
				data-nonce="<?php echo esc_attr( wp_create_nonce( Handl_Snapshot_Ajax::NONCE_ACTION ) ); ?>"
				data-surface="<?php echo esc_attr( $surface ); ?>">
				<input type="email" name="email" required value="<?php echo esc_attr( $email ); ?>" style="min-width:230px;" />
				<button type="submit" class="button button-primary">Email me my numbers</button>
				<span class="handl-snapshot-optin-error" style="color:#b32d2e;display:none;"></span>
			</form>
			<p class="description" style="margin:0;">Free, sent from your own site. Plus weekly tracking tips. Unsubscribe in one click.</p>
		</div>
		<?php
		self::print_script();
	}

	/** Once-per-page submit handler; success swaps the form for the confirmation line. */
	private static function print_script() {
		if ( self::$script_printed ) {
			return;
		}
		self::$script_printed = true;
		?>
		<script>
		(function($) {
			$(document).on('submit', '.handl-snapshot-optin-form', function(e) {
				e.preventDefault();
				var $form = $(this);
				var $button = $form.find('button').prop('disabled', true);
				var $error = $form.find('.handl-snapshot-optin-error').hide();
				$.post(ajaxurl, {
					action: 'handl_snapshot_notice_optin',
					nonce: $form.data('nonce'),
					surface: $form.data('surface'),
					email: $form.find('input[name="email"]').val()
				}).done(function(res) {
					if (res && res.success) {
						$form.closest('.handl-snapshot-optin-wrap').empty().append(
							$('<p style="margin:0.4em 0;"></p>').append($('<strong></strong>').text(res.data.message))
						);
						return;
					}
					$button.prop('disabled', false);
					$error.text(res && res.data && res.data.message ? res.data.message : 'Could not save. Try again.').show();
				}).fail(function() {
					$button.prop('disabled', false);
					$error.text('Could not save. Try again.').show();
				});
			});
		})(jQuery);
		</script>
		<?php
	}
}
