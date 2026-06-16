<?php
/**
 * Front-end test-prompt banner.
 *
 * @var string $back_url   Admin wizard URL (escaped).
 * @var string $form_title Tested form's title, or '' when unresolved.
 */

if ( ! defined( 'ABSPATH' ) ) exit;
?>
<div id="handl-test-banner" role="status">
	<button type="button" id="handl-test-banner-close" aria-label="Dismiss">&times;</button>
	<div class="handl-test-banner__eyebrow">UTM Grabber &middot; Test mode</div>
	<?php if ( $form_title !== '' ) : ?>
		<div class="handl-test-banner__title">Submit the &ldquo;<?php echo esc_html( $form_title ); ?>&rdquo; form on this page</div>
	<?php else : ?>
		<div class="handl-test-banner__title">Submit the form on this page</div>
	<?php endif; ?>
	<p class="handl-test-banner__body">Then switch back to the wizard tab you came from. It detects the submission automatically.</p>
	<a class="handl-test-banner__cta" href="<?php echo $back_url; ?>">Wizard not open? Reopen it &rarr;</a>
</div>
<style>
	#handl-test-banner {
		position: fixed; right: 24px; bottom: 24px; z-index: 999999;
		width: 372px; max-width: calc(100vw - 40px);
		background: hsl(0 0% 100%); color: hsl(222.2 84% 4.9%);
		border: 1px solid hsl(214.3 31.8% 91.4%);
		border-top: 4px solid hsl(221.2 83.2% 53.3%);
		border-radius: 0.625rem;
		box-shadow: 0 18px 50px -8px rgba(37, 99, 235, 0.35), 0 8px 24px rgba(2, 8, 23, 0.16);
		padding: 20px 22px 22px; box-sizing: border-box;
		font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
		animation: handl-test-banner-in 0.4s cubic-bezier(0.16, 1, 0.3, 1);
	}
	@keyframes handl-test-banner-in {
		from { opacity: 0; transform: translateY(16px) scale(0.97); }
		to   { opacity: 1; transform: translateY(0) scale(1); }
	}
	#handl-test-banner-close {
		position: absolute; top: 10px; right: 12px;
		background: none; border: 0; cursor: pointer;
		font-size: 20px; line-height: 1; color: hsl(215.4 16.3% 46.9%); padding: 4px;
	}
	#handl-test-banner-close:hover { color: hsl(222.2 84% 4.9%); }
	.handl-test-banner__eyebrow {
		display: inline-block; font-size: 10.5px; font-weight: 700; letter-spacing: 0.05em;
		text-transform: uppercase; color: hsl(221.2 83.2% 53.3%);
		background: hsl(221.2 83.2% 53.3% / 0.1); padding: 3px 8px; border-radius: 5px;
		margin-bottom: 10px;
	}
	.handl-test-banner__title { font-size: 16.5px; font-weight: 700; line-height: 1.35; margin: 0 0 6px; }
	.handl-test-banner__body { font-size: 13px; line-height: 1.5; color: hsl(215.4 16.3% 46.9%); margin: 0 0 14px; }
	.handl-test-banner__cta {
		display: inline-block; font-size: 13px; font-weight: 600;
		color: hsl(221.2 83.2% 53.3%); text-decoration: none;
	}
	.handl-test-banner__cta:hover { text-decoration: underline; }
</style>
<script>
	(function () {
		var key = 'handlTestBannerDismissed';
		var el = document.getElementById('handl-test-banner');
		if ( ! el ) { return; }
		try {
			if ( window.sessionStorage && sessionStorage.getItem( key ) === '1' ) {
				el.parentNode.removeChild( el );
				return;
			}
		} catch ( e ) {}
		var close = document.getElementById('handl-test-banner-close');
		close && close.addEventListener('click', function () {
			try { window.sessionStorage && sessionStorage.setItem( key, '1' ); } catch ( e ) {}
			el.parentNode.removeChild( el );
		});
	})();
</script>
