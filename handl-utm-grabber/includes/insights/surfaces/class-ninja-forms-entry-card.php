<?php
namespace Handl\UtmrabberFree\Insights;

if ( ! defined( 'ABSPATH' ) ) exit;

/** NF submission metabox (React submissions page, NF >= 3.6.1). Text-only:
 *  no HTML/links/async, so the insight is cache-or-manual, never a fresh AI call. */
class Ninja_Forms_Entry_Card extends Handl_Entry_Card_Surface {

	public function get_slug() {
		return 'ninja-forms';
	}

	public function boot() {
		add_filter( 'nf_react_table_extra_value_keys', function ( $handlers ) {
			$handlers['handl_utm_insight'] = self::class;
			return $handlers;
		} );
	}

	/**
	 * NF instantiates this and calls handle() per submission; null = no metabox.
	 *
	 * @param mixed                          $extraValue Stored extra value (unused).
	 * @param \NF_Database_Models_Submission $nfSub
	 * @return \NinjaForms\Includes\Entities\MetaboxOutputEntity|null
	 */
	public function handle( $extraValue, $nfSub ) {
		if ( ! class_exists( '\NinjaForms\Includes\Entities\MetaboxOutputEntity' ) || ! is_object( $nfSub ) ) {
			return null;
		}

		$posted = $this->extract_posted( $nfSub );
		if ( empty( $posted ) ) {
			return null;
		}

		$rows = array();
		foreach ( self::param_labels() as $key => $label ) {
			if ( isset( $posted[ $key ] ) && $posted[ $key ] !== '' ) {
				$rows[] = array( 'label' => $label, 'value' => $posted[ $key ] );
			}
		}

		$card = Handl_Insight_Provider::card_for_posted_cached( $posted );
		if ( $card ) {
			if ( ! empty( $card['message'] ) ) {
				$rows[] = array(
					'label'   => 'Insight',
					'value'   => $card['message'],
					'styling' => ( isset( $card['severity'] ) && 'warning' === $card['severity'] ) ? 'alert' : '',
				);
			}
			if ( ! empty( $card['action'] ) ) {
				$rows[] = array( 'label' => 'Next step', 'value' => $card['action'] );
			}
		}

		return \NinjaForms\Includes\Entities\MetaboxOutputEntity::fromArray( array(
			'title'                => 'HandL UTM Grabber',
			'labelValueCollection' => $rows,
		) );
	}

	private function extract_posted( $nfSub ) {
		return \Handl\UtmrabberFree\Submissions\Ninja_Forms_Submission_Listener::extract_from_sub( $nfSub );
	}
}
