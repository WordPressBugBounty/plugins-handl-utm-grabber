<?php
namespace Handl\UtmrabberFree\Integrations;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Contact Form 7 one-click hidden-field injector.
 *
 * Unlike Gravity Forms, CF7 stores each form as a single text blob (`form`
 * property) plus separate mail templates (`mail.body`, `mail_2.body`). We can't
 * mutate a `fields[]` array, so we instead append an idempotent HandL-marked
 * block to those text blobs:
 *
 *   <!-- HandL UTM start -->
 *   [hidden utm_source_cf7 utm_source_cf7-12 class:utm_source id:utm_source]
 *   ...
 *   <!-- HandL UTM end -->
 *
 *
 * Optional `also_add_to_email` ($options): also append a HandL block to both
 * mail bodies.
 */
class Contact_Form_7_Integration extends Handl_Integration {

	const FORM_START = '<!-- HandL UTM start -->';
	const FORM_END   = '<!-- HandL UTM end -->';
	const MAIL_START = '/* HandL UTM start */';
	const MAIL_END   = '/* HandL UTM end */';

	/**
	 * Load a single CF7 form without touching WPCF7_ContactForm::$current.
	 *
	 * CF7's get_instance()/wpcf7_contact_form() always overwrite the global
	 * "current" form as a side effect. Our admin code (setup notice, health
	 * checks, AJAX) runs before the CF7 list page renders; if $current is left
	 * set, CF7's wpcf7_admin_management_page() shows that form's editor instead
	 * of the form list.
	 *
	 * WPCF7_ContactForm::find() is the only public accessor that returns form
	 * objects without assigning $current (the constructor is private), so we
	 * query by post ID through it.
	 *
	 * @param int|string $form_id
	 * @return \WPCF7_ContactForm|null
	 */
	public static function load_form( $form_id ) {
		if ( ! class_exists( 'WPCF7_ContactForm' ) ) {
			return null;
		}

		$form_id = (int) $form_id;
		if ( $form_id <= 0 ) {
			return null;
		}

		$forms = \WPCF7_ContactForm::find( array(
			'p'              => $form_id,
			'posts_per_page' => 1,
		) );

		return $forms ? $forms[0] : null;
	}

	public function get_slug() {
		return 'contact-form-7';
	}

	public function get_label() {
		return 'Contact Form 7';
	}

	public function is_active() {
		if ( ! function_exists( 'is_plugin_active' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		return is_plugin_active( 'contact-form-7/wp-contact-form-7.php' )
			&& class_exists( 'WPCF7_ContactForm' );
	}

	public function get_forms() {
		if ( ! $this->is_active() ) {
			return array();
		}

		$posts = \WPCF7_ContactForm::find( array(
			'post_status'    => 'any',
			'posts_per_page' => -1,
		) );

		$out = array();
		foreach ( $posts as $post ) {
			$out[] = array(
				'id'    => (string) (int) $post->id(),
				'title' => (string) $post->title(),
			);
		}
		return $out;
	}

	public function apply( $form_ids, $param_keys, $action, $options = array() ) {
		if ( ! $this->is_active() ) {
			return array();
		}

		$also_email = ! empty( $options['also_add_to_email'] );
		$results    = array();

		foreach ( $form_ids as $form_id ) {
			$form_id = (int) $form_id;
			$cf      = self::load_form( $form_id );
			if ( ! $cf ) {
				$results[] = array(
					'form_id' => (string) $form_id,
					'ok'      => false,
					'message' => 'Form not found.',
				);
				continue;
			}

			$props = $cf->get_properties();

			$original_form_text = isset( $props['form'] ) ? (string) $props['form'] : '';

			$added   = 0;
			$skipped = 0;
			$removed = 0;

			$form_text = $this->strip_block( $original_form_text, self::FORM_START, self::FORM_END );
			$form_text = $this->strip_legacy_lines( $form_text, $this->get_tracked_params() );

			if ( $action === 'add' ) {
				// "skipped" = params already present in the pre-strip blob (we
				// rewrite them); "added" = the rest.
				foreach ( $param_keys as $param ) {
					$pattern = '/\[hidden\s+' . preg_quote( $param . '_cf7', '/' ) . '[^\]]*\]/';
					if ( preg_match( $pattern, $original_form_text ) === 1 ) {
						$skipped++;
					} else {
						$added++;
					}
				}
				$block     = $this->build_form_block( $param_keys, $form_id );
				$form_text = $this->insert_before_submit( $form_text, $block );
			} else {
				// "removed" = total `[hidden {param}_cf7 ...]` tags present in
				// the pre-strip blob (covers both our marker block and any
				// legacy stray lines outside it).
				foreach ( $this->get_tracked_params() as $param ) {
					$pattern  = '/\[hidden\s+' . preg_quote( $param . '_cf7', '/' ) . '[^\]]*\]/';
					$removed += preg_match_all( $pattern, $original_form_text );
				}
			}
			$props['form'] = $form_text;

			// Mail bodies: always strip on remove; on add only when user opted in.
			foreach ( array( 'mail', 'mail_2' ) as $mail_key ) {
				if ( ! isset( $props[ $mail_key ]['body'] ) ) {
					continue;
				}
				$body = (string) $props[ $mail_key ]['body'];
				$body = $this->strip_block( $body, self::MAIL_START, self::MAIL_END );
				if ( $action === 'add' && $also_email ) {
					$body = rtrim( $body ) . "\n\n" . $this->build_mail_block( $param_keys );
				}
				$props[ $mail_key ]['body'] = $body;
			}

			$cf->set_properties( $props );
			$saved = $cf->save();

			$ok = (bool) $saved;
			$results[] = array(
				'form_id' => (string) $form_id,
				'ok'      => $ok,
				'message' => $ok ? 'Updated.' : 'Update failed.',
				'added'   => $added,
				'skipped' => $skipped,
				'removed' => $removed,
			);
		}

		return $results;
	}

	protected function detect_integrated_params( $form_id ) {
		if ( ! $this->is_active() ) {
			return null;
		}

		$cf = self::load_form( (int) $form_id );
		if ( ! $cf ) {
			return null;
		}

		$props     = $cf->get_properties();
		$form_text = isset( $props['form'] ) ? (string) $props['form'] : '';

		$present = array();
		foreach ( $this->get_tracked_params() as $param ) {
			// Matches marker-block + legacy stray `[hidden {param}_cf7 ...]` tags.
			$pattern = '/\[hidden\s+' . preg_quote( $param . '_cf7', '/' ) . '[^\]]*\]/';
			if ( preg_match( $pattern, $form_text ) === 1 ) {
				$present[] = (string) $param;
			}
		}

		return $present;
	}

	/**
	 * Remove all content between (and including) the provided markers, along with any following single newline to prevent leftover blank lines.
	 *
	 * @param string $text
	 * @param string $start
	 * @param string $end
	 * @return string
	 */
	private function strip_block( $text, $start, $end ) {
		$pattern = '/' . preg_quote( $start, '/' ) . '.*?' . preg_quote( $end, '/' ) . '\s*/s';
		return preg_replace( $pattern, '', $text );
	}

	/**
	 * Strip stray `[hidden {param}_cf7 ...]` lines outside our marker block.
	 * Handles legacy CF7 [hidden ...] tags to avoid duplication when adding fields.
	 *
	 * @param string $text
	 * @param array  $params
	 * @return string
	 */
	private function strip_legacy_lines( $text, $params ) {
		foreach ( $params as $param ) {
			$pattern = '/\[hidden\s+' . preg_quote( $param . '_cf7', '/' ) . '[^\]]*\]\s*\n?/';
			$text    = preg_replace( $pattern, '', $text );
		}
		return $text;
	}

	/**
	 * @param string $form_text
	 * @param string $block
	 * @return string
	 */
	private function insert_before_submit( $form_text, $block ) {
		if ( preg_match( '/\[submit\b/', $form_text, $m, PREG_OFFSET_CAPTURE ) ) {
			$pos    = $m[0][1];
			$before = rtrim( substr( $form_text, 0, $pos ) );
			$after  = substr( $form_text, $pos );
			return $before . "\n\n" . $block . "\n\n" . $after;
		}
		return rtrim( $form_text ) . "\n\n" . $block . "\n";
	}

	/**
	 * Emit legacy-format CF7 hidden-tag lines
	 *
	 * @param array $params
	 * @param int   $form_id
	 * @return string
	 */
	private function build_form_block( $params, $form_id ) {
		$lines = array( self::FORM_START );
		foreach ( $params as $param ) {
			$name = $param . '_cf7';
			$lines[] = sprintf(
				'[hidden %s %s-%d class:%s id:%s]',
				$name,
				$name,
				(int) $form_id,
				$param,
				$param
			);
		}
		$lines[] = self::FORM_END;
		return implode( "\n", $lines );
	}

	/**
	 * Mail-body block referencing the `_cf7`-suffixed field names, since CF7
	 * mail-tags resolve against the form's field names.
	 *
	 * @param array $params
	 * @return string
	 */
	private function build_mail_block( $params ) {
		$lines = array( self::MAIL_START );
		foreach ( $params as $param ) {
			$lines[] = sprintf( '%s: [%s_cf7]', $param, $param );
		}
		$lines[] = self::MAIL_END;
		return implode( "\n", $lines );
	}
}
