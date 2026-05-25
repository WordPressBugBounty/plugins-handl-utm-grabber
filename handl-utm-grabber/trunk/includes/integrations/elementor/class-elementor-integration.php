<?php
namespace Handl\UtmrabberFree\Integrations;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Elementor (Pro) one-click hidden-field injector.
 *
 * Unlike Gravity Forms (a `forms` API), Contact Form 7 (a text blob) and Ninja
 * Forms (per-field DB rows), Elementor stores everything inside a single
 * `_elementor_data` post meta as a JSON tree of widgets nested in
 * containers/sections/columns. A single post can hold multiple form widgets,
 * each with its own `settings.form_fields` repeater.
 *
 * Form id is therefore composite — `{post_id}:{widget_id}` — so we can route
 * each Add/Remove to the correct widget.
 *
 * Runtime auto-population is already handled by the existing free-plugin
 * filter at `lite/elementor.php` (hook `elementor_pro/forms/render/item/hidden`)
 * which fills any hidden field whose `custom_id` matches a tracked UTM from
 * cookie. So we don't need to set `field_value`, dynamic tags, or shortcodes —
 * we just inject the field with the right `custom_id`.
 */
class Elementor_Integration extends Handl_Integration {

	public function get_slug() {
		return 'elementor';
	}

	public function get_label() {
		return 'Elementor';
	}

	public function is_active() {
		if ( ! function_exists( 'is_plugin_active' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		return is_plugin_active( 'elementor/elementor.php' )
			&& is_plugin_active( 'elementor-pro/elementor-pro.php' );
	}

	public function get_forms() {
		if ( ! $this->is_active() ) {
			return array();
		}

		global $wpdb;

		// Pre-filter on the meta_value so we don't deserialize every Elementor
		// page just to find form widgets. The literal `"widgetType":"form"`
		// substring is how Elementor serializes the widget type.
		$rows = $wpdb->get_results(
			"SELECT p.ID AS post_id, p.post_title, pm.meta_value
			 FROM {$wpdb->postmeta} pm
			 INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
			 WHERE pm.meta_key = '_elementor_data'
			 AND pm.meta_value LIKE '%\"widgetType\":\"form\"%'
			 AND p.post_status = 'publish'
			 AND p.post_type IN ('post','page','elementor_library')",
			ARRAY_A
		);

		if ( ! is_array( $rows ) ) {
			return array();
		}

		$out = array();
		foreach ( $rows as $row ) {
			$tree = json_decode( (string) $row['meta_value'], true );
			if ( ! is_array( $tree ) ) {
				continue;
			}

			$widgets = array();
			$this->collect_form_widgets( $tree, $widgets );

			foreach ( $widgets as $widget ) {
				$widget_id   = isset( $widget['id'] ) ? (string) $widget['id'] : '';
				$form_name   = isset( $widget['settings']['form_name'] ) ? (string) $widget['settings']['form_name'] : '';
				$post_title  = (string) $row['post_title'];

				if ( $widget_id === '' ) {
					continue;
				}

				$title = $post_title !== '' ? $post_title : sprintf( '#%d', (int) $row['post_id'] );
				if ( $form_name !== '' ) {
					$title .= ' — ' . $form_name;
				} else {
					$title .= ' — Form (' . $widget_id . ')';
				}

				$out[] = array(
					'id'    => sprintf( '%d:%s', (int) $row['post_id'], $widget_id ),
					'title' => $title,
				);
			}
		}

		return $out;
	}

	public function apply( $form_ids, $param_keys, $action, $options = array() ) {
		if ( ! $this->is_active() ) {
			return array();
		}

		// One post can hold multiple form widgets — group composite ids by
		// post id so we read/write `_elementor_data` once per post.
		$by_post = array();
		foreach ( $form_ids as $composite ) {
			$parts = explode( ':', (string) $composite, 2 );
			if ( count( $parts ) !== 2 ) {
				continue;
			}
			$post_id   = (int) $parts[0];
			$widget_id = (string) $parts[1];
			if ( $post_id <= 0 || $widget_id === '' ) {
				continue;
			}
			$by_post[ $post_id ][] = $widget_id;
		}

		$results = array();

		foreach ( $by_post as $post_id => $widget_ids ) {
			$raw  = get_post_meta( $post_id, '_elementor_data', true );
			$tree = is_string( $raw ) ? json_decode( $raw, true ) : null;

			if ( ! is_array( $tree ) ) {
				foreach ( $widget_ids as $wid ) {
					$results[] = array(
						'form_id' => sprintf( '%d:%s', $post_id, $wid ),
						'ok'      => false,
						'message' => 'Could not load Elementor data for post.',
					);
				}
				continue;
			}

			$post_changed = false;

			foreach ( $widget_ids as $wid ) {
				$widget = &$this->find_form_widget( $tree, $wid );
				if ( $widget === null ) {
					$results[] = array(
						'form_id' => sprintf( '%d:%s', $post_id, $wid ),
						'ok'      => false,
						'message' => 'Form widget not found on this post.',
					);
					unset( $widget );
					continue;
				}

				if ( ! isset( $widget['settings'] ) || ! is_array( $widget['settings'] ) ) {
					$widget['settings'] = array();
				}
				if ( ! isset( $widget['settings']['form_fields'] ) || ! is_array( $widget['settings']['form_fields'] ) ) {
					$widget['settings']['form_fields'] = array();
				}

				if ( $action === 'add' ) {
					$res = $this->add_fields_to_widget( $widget, $param_keys );
				} else {
					$res = $this->remove_fields_from_widget( $widget );
				}

				$results[] = array(
					'form_id' => sprintf( '%d:%s', $post_id, $wid ),
					'ok'      => true,
					'message' => $res['message'],
					'added'   => isset( $res['added'] )   ? (int) $res['added']   : 0,
					'skipped' => isset( $res['skipped'] ) ? (int) $res['skipped'] : 0,
					'removed' => isset( $res['removed'] ) ? (int) $res['removed'] : 0,
				);

				$post_changed = true;
				unset( $widget );
			}

			if ( $post_changed ) {
				// Re-slash on write — Elementor itself uses `wp_slash( wp_json_encode(...) )`
				// when persisting (see Form_Snapshot_Repository::create_or_update()),
				// otherwise WP's update_post_meta would unslash the JSON and corrupt
				// any literal backslashes inside string values.
				update_post_meta(
					$post_id,
					'_elementor_data',
					wp_slash( wp_json_encode( $tree ) )
				);

				// Drop per-post CSS cache as a precaution
				if ( class_exists( '\Elementor\Core\Files\CSS\Post' ) ) {
					\Elementor\Core\Files\CSS\Post::create( $post_id )->update();
				}
			}
		}

		return $results;
	}

	/**
	 * Recursively walk an Elementor tree, collecting every node with
	 * `widgetType === 'form'`.
	 *
	 * @param array $tree
	 * @param array $out
	 */
	private function collect_form_widgets( $tree, &$out ) {
		if ( ! is_array( $tree ) ) {
			return;
		}
		foreach ( $tree as $node ) {
			if ( ! is_array( $node ) ) {
				continue;
			}
			if ( isset( $node['widgetType'] ) && $node['widgetType'] === 'form' ) {
				$out[] = $node;
			}
			if ( isset( $node['elements'] ) && is_array( $node['elements'] ) ) {
				$this->collect_form_widgets( $node['elements'], $out );
			}
		}
	}

	/**
	 * Recursively finds the form widget by id and returns it by reference, or `null` by reference if not found.
	 *
	 * @param array  $tree
	 * @param string $widget_id
	 * @return array|null
	 */
	private function &find_form_widget( &$tree, $widget_id ) {
		$null = null;
		if ( ! is_array( $tree ) ) {
			return $null;
		}
		foreach ( $tree as $i => &$node ) {
			if ( ! is_array( $node ) ) {
				continue;
			}
			if (
				isset( $node['widgetType'], $node['id'] )
				&& $node['widgetType'] === 'form'
				&& (string) $node['id'] === (string) $widget_id
			) {
				return $tree[ $i ];
			}
			if ( isset( $node['elements'] ) && is_array( $node['elements'] ) ) {
				$found = &$this->find_form_widget( $node['elements'], $widget_id );
				if ( $found !== null ) {
					return $found;
				}
				unset( $found );
			}
		}
		return $null;
	}

	/**
	 * Append HandL hidden fields to a form widget for any param not already
	 * present (matched by `custom_id`).
	 *
	 * @param array $widget Reference; mutated in place.
	 * @param array $param_keys
	 * @return array{message: string, added: int, skipped: int}
	 */
	private function add_fields_to_widget( &$widget, $param_keys ) {
		$existing_custom_ids = array();
		foreach ( $widget['settings']['form_fields'] as $field ) {
			if ( isset( $field['custom_id'] ) && $field['custom_id'] !== '' ) {
				$existing_custom_ids[] = (string) $field['custom_id'];
			}
		}

		$added   = 0;
		$skipped = 0;

		foreach ( $param_keys as $param ) {
			if ( in_array( (string) $param, $existing_custom_ids, true ) ) {
				$skipped++;
				continue;
			}

			$widget['settings']['form_fields'][] = array(
				'_id'         => substr( md5( 'handl_' . $param . '_' . microtime( true ) ), 0, 7 ),
				'field_type'  => 'hidden',
				'field_label' => sprintf( 'HandL ( %s )', $param ),
				'custom_id'   => (string) $param,
			);
			$existing_custom_ids[] = (string) $param;
			$added++;
		}

		$parts = array();
		if ( $added > 0 )   { $parts[] = sprintf( 'Added %d.', $added ); }
		if ( $skipped > 0 ) { $parts[] = sprintf( 'Skipped %d (already present).', $skipped ); }
		if ( empty( $parts ) ) {
			$parts[] = 'No changes.';
		}
		return array(
			'message' => implode( ' ', $parts ),
			'added'   => $added,
			'skipped' => $skipped,
		);
	}

	/**
	 * Drop every field that looks HandL-managed, via either of two paths:
	 *
	 *   (a) A hidden field whose `custom_id` is one of the tracked params.
	 *       Covers our own one-click injections and any field a user added
	 *       manually with the UTM as the field id.
	 *
	 *   (b) A field — any type, any `custom_id` — whose default value is
	 *       wired to the `Cookies` dynamic tag registered in
	 *       `lite/elementor.php`
	 * @param array $widget Reference; mutated in place.
	 * @return array{message: string, removed: int}
	 */
	private function remove_fields_from_widget( &$widget ) {
		$tracked = array_map( 'strval', $this->get_tracked_params() );
		$before  = count( $widget['settings']['form_fields'] );

		$widget['settings']['form_fields'] = array_values(
			array_filter(
				$widget['settings']['form_fields'],
				function ( $field ) use ( $tracked ) {
					$type = (string) ( $field['field_type'] ?? '' );
					$cid  = (string) ( $field['custom_id'] ?? '' );
					$dyn  = (string) ( $field['__dynamic__']['field_value'] ?? '' );

					// (a) Our custom_id-flow fields.
					if ( $type === 'hidden' && in_array( $cid, $tracked, true ) ) {
						return false;
					}

					// (b) Any field wired to our `Cookies` dynamic tag.
					if ( $dyn !== '' && strpos( $dyn, 'name="cookies"' ) !== false ) {
						return false;
					}

					return true;
				}
			)
		);

		$removed = $before - count( $widget['settings']['form_fields'] );
		return array(
			'message' => $removed > 0
				? sprintf( 'Removed %d HandL field%s.', $removed, $removed === 1 ? '' : 's' )
				: 'No HandL fields to remove.',
			'removed' => $removed,
		);
	}
}
