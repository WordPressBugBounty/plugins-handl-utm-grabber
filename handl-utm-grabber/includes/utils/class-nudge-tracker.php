<?php
namespace Handl\UtmrabberFree\PluginUtils;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Per-user "new feature" nudges, stored as handl_seen_{key} user meta.
 */
class Nudge_Tracker {

    private $meta_prefix = 'handl_seen_';

    private static $instance = null;

    private function __construct() {
    }

    public static function get_instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function is_active( $key ) {
        return current_user_can( 'manage_options' ) && ! $this->has_seen( $key );
    }

    public function has_seen( $key ) {
        return (bool) get_user_meta( get_current_user_id(), $this->meta_prefix . $key, true );
    }

    public function mark_seen( $key ) {
        update_user_meta( get_current_user_id(), $this->meta_prefix . $key, '1' );
    }

    // Mark $key seen when the given admin page (and optional tab) is opened.
    public function watch( $key, $page, $tab = null ) {
        add_action( 'admin_init', function () use ( $key, $page, $tab ) {
            $current_page = isset( $_GET['page'] ) ? sanitize_text_field( wp_unslash( $_GET['page'] ) ) : '';
            $current_tab  = isset( $_GET['tab'] ) ? sanitize_text_field( wp_unslash( $_GET['tab'] ) ) : '';

            if ( $current_page === $page && ( null === $tab || $current_tab === $tab ) ) {
                $this->mark_seen( $key );
            }
        } );
    }

    // Append the WP-native "new" bubble to a menu label while the nudge is active.
    public function badge_label( $label, $key ) {
        if ( ! $this->is_active( $key ) ) {
            return $label;
        }
        return $label . ' <span class="update-plugins count-1"><span class="update-count">new</span></span>';
    }

    /**
     * Badge a registered top-level menu; runs post-registration so $admin_page_hooks stays clean.
     *
     * @param string $menu_slug  Slug passed to add_menu_page().
     * @param string $key        Nudge key.
     */
    public function badge_menu( $menu_slug, $key ) {
        add_action( 'admin_menu', function () use ( $menu_slug, $key ) {
            global $menu;
            foreach ( $menu as $i => $item ) {
                if ( isset( $item[2] ) && $item[2] === $menu_slug ) {
                    $menu[ $i ][0] = $this->badge_label( $item[0], $key );
                    break;
                }
            }
        }, 9999 );
    }
}
