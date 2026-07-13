<?php
namespace Handl\UtmrabberFree\Insights;

if ( ! defined( 'ABSPATH' ) ) exit;

/** Records tracked submissions into a per-integration ring buffer (handl_recent_leads option). */
class Handl_Lead_Recorder {

	const OPTION         = 'handl_recent_leads';
	const MAX_PER_PLUGIN = 5;

	/**
	 * handl_utm_submission consumer; records only submissions with >=1 tracked param.
	 * @param array $event { integration, form_id, posted, submission_id, form_title }
	 */
	public function record( $event ) {
		if ( ! is_array( $event ) ) {
			return;
		}

		$posted = isset( $event['posted'] ) && is_array( $event['posted'] ) ? $event['posted'] : array();
		if ( empty( $posted ) ) {
			return;
		}

		$slug = isset( $event['integration'] ) ? (string) $event['integration'] : '';
		if ( $slug === '' ) {
			return;
		}

		$sub_id = isset( $event['submission_id'] ) ? $event['submission_id'] : null;
		$record = array(
			'integration'   => $slug,
			'form_id'       => isset( $event['form_id'] ) ? (string) $event['form_id'] : '',
			'submission_id' => $sub_id !== null ? (string) $sub_id : null,
			'form_title'    => isset( $event['form_title'] ) ? (string) $event['form_title'] : '',
			'posted'        => array_map( 'strval', $posted ),
			'created_at'    => gmdate( 'c' ),
		);

		$all = self::get_buckets();
		if ( ! isset( $all[ $slug ] ) || ! is_array( $all[ $slug ] ) ) {
			$all[ $slug ] = array();
		}

		array_unshift( $all[ $slug ], $record );
		$all[ $slug ] = array_slice( $all[ $slug ], 0, self::MAX_PER_PLUGIN );

		update_option( self::OPTION, $all, false );
	}

	/** @return array<string,array> slug => records (newest-first). */
	public static function get_buckets() {
		$all = get_option( self::OPTION, array() );
		return is_array( $all ) ? $all : array();
	}
}
