<?php
namespace Handl\UtmrabberFree\Integrations;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Contact Form 7 one-click hidden-field injector.
 *
 * CF7 stores each form as a single text blob plus mail templates, so we append
 * an idempotent marker-fenced block of `[hidden {param}_cf7 …]` tags.
 *
 * CATCH — two tag formats exist on real sites and all three paths (detect, add,
 * remove) must handle both:
 *
 *   block  `[hidden utm_source_cf7 …]`  tag TYPE `hidden`, NAME `{param}_cf7`
 *   custom `[utm_source_cf7 my_field]`  tag TYPE `{param}_cf7` (registered by
 *                                       lite/contact-form-7.php), NAME user-chosen
 *
 * Only the custom form has `{param}_cf7` right after the bracket, so the
 * patterns never collide. Custom tags are excluded from a fresh block (else the
 * param posts twice under two names) and removed on remove (the tag type only
 * resolves because we register it, so it is unambiguously ours).
 */
class Contact_Form_7_Integration extends Handl_Integration {

	const FORM_START = '<!-- HandL UTM start -->';
	const FORM_END   = '<!-- HandL UTM end -->';
	const MAIL_START = '/* HandL UTM start */';
	const MAIL_END   = '/* HandL UTM end */';

	/**
	 * Our injected block tags: `[hidden {param}_cf7 …]`.
	 *
	 * @param string $param Tracked param name.
	 * @return string Regex.
	 */
	private static function pattern_ours( $param ) {
		return '/\[hidden\s+' . preg_quote( $param . '_cf7', '/' ) . '[^\]]*\]/';
	}

	/**
	 * Custom tags: `[{param}_cf7 name …]`. Bracket-anchored so it never
	 * matches the block form.
	 *
	 * @param string $param Tracked param name.
	 * @return string Regex.
	 */
	private static function pattern_pro( $param ) {
		return '/\[' . preg_quote( $param . '_cf7', '/' ) . '(?:\s[^\]]*)?\]/';
	}

	/**
	 * Mail-tag reference: `[{param}_cf7]` or `[_raw_{param}_cf7]`.
	 * Bracket-anchored (else utm_source matches inside first_utm_source).
	 *
	 * @param string $param Tracked param name.
	 * @return string Regex.
	 */
	private static function pattern_mail_tag( $param ) {
		return '/\[(?:_raw_)?' . preg_quote( $param . '_cf7', '/' ) . '\]/';
	}

	/**
	 * CATCH: never use get_instance()/wpcf7_contact_form() here — they set
	 * WPCF7_ContactForm::$current as a side effect, which makes CF7's admin
	 * list page render an editor instead of the list. find() does not.
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

			$props              = $cf->get_properties();
			$original_form_text = isset( $props['form'] ) ? (string) $props['form'] : '';

			$added    = 0;
			$skipped  = 0;
			$removed  = 0;
			$to_write = array();

			// Strip only block tags; custom tags are user-placed, never rewritten.
			$form_text = $this->strip_block( $original_form_text, self::FORM_START, self::FORM_END );
			$form_text = $this->strip_legacy_lines( $form_text, $this->get_tracked_params() );

			if ( $action === 'add' ) {
				foreach ( $param_keys as $param ) {
					if ( preg_match( self::pattern_pro( $param ), $original_form_text ) === 1 ) {
						// Covered by a custom tag; adding it again would double-post.
						$skipped++;
						continue;
					}
					if ( preg_match( self::pattern_ours( $param ), $original_form_text ) === 1 ) {
						$skipped++; // strip-and-rewritten, not new
					} else {
						$added++;
					}
					$to_write[] = $param;
				}

				if ( ! empty( $to_write ) ) {
					$block     = $this->build_form_block( $to_write, $form_id );
					$form_text = $this->insert_before_submit( $form_text, $block );
				}
			} else {
				foreach ( $this->get_tracked_params() as $param ) {
					$removed += preg_match_all( self::pattern_ours( $param ), $original_form_text );

					// Custom tags are unambiguously ours; remove clears them too.
					$pro_hits = preg_match_all( self::pattern_pro( $param ), $form_text );
					if ( $pro_hits ) {
						$removed  += $pro_hits;
						$form_text = preg_replace( self::pattern_pro( $param ), '', $form_text );
					}
				}
			}
			$props['form'] = $form_text;

			// Mail bodies: always strip on remove; on add only when opted in.
			foreach ( array( 'mail', 'mail_2' ) as $mail_key ) {
				if ( ! isset( $props[ $mail_key ]['body'] ) ) {
					continue;
				}
				$body = (string) $props[ $mail_key ]['body'];
				$body = $this->strip_block( $body, self::MAIL_START, self::MAIL_END );

				if ( $action === 'add' && $also_email && ! empty( $to_write ) ) {
					// Skip params already referenced by hand in the mail body
					// (checked post-strip, so our own block never counts) —
					// else docs-followers get every value twice per email.
					$mail_params = array();
					foreach ( $to_write as $param ) {
						if ( preg_match( self::pattern_mail_tag( $param ), $body ) !== 1 ) {
							$mail_params[] = $param;
						}
					}
					if ( ! empty( $mail_params ) ) {
						$body = rtrim( $body ) . "\n\n" . $this->build_mail_block( $mail_params );
					}
				}

				$props[ $mail_key ]['body'] = $body;
			}

			$cf->set_properties( $props );
			$saved = $cf->save();

			$ok        = (bool) $saved;
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
			if ( preg_match( self::pattern_ours( $param ), $form_text ) === 1
				|| preg_match( self::pattern_pro( $param ), $form_text ) === 1 ) {
				$present[] = (string) $param;
			}
		}

		return $present;
	}

	/**
	 * Remove marker-fenced content incl. trailing whitespace.
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
	 * Strip stray `[hidden {param}_cf7 …]` lines outside the marker block.
	 *
	 * @param string $text
	 * @param array  $params Tracked param names.
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
	 * @param array $params  Tracked param names.
	 * @param int   $form_id
	 * @return string
	 */
	private function build_form_block( $params, $form_id ) {
		$lines = array( self::FORM_START );
		foreach ( $params as $param ) {
			$name    = $param . '_cf7';
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
	 * Mail-tags resolve against field names, hence the `_cf7` suffix.
	 *
	 * @param array $params Tracked param names.
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
