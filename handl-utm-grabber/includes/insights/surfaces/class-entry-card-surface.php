<?php
namespace Handl\UtmrabberFree\Insights;

if ( ! defined( 'ABSPATH' ) ) exit;

/** Contract for in-context entry-detail insight surfaces (one per form plugin). */
abstract class Handl_Entry_Card_Surface {

	/** Must match the corresponding integration slug. */
	abstract public function get_slug();

	/** Hook the plugin's entry-detail render slot. Called during load. */
	abstract public function boot();

	/** Human labels for the tracked params, in display order. */
	public static function param_labels() {
		return array(
			'utm_source'         => 'Source',
			'utm_medium'         => 'Medium',
			'utm_campaign'       => 'Campaign',
			'utm_term'           => 'Term',
			'utm_content'        => 'Content',
			'gclid'              => 'Google click ID',
			'handl_landing_page' => 'Landing page',
			'handl_original_ref' => 'Referrer',
			'handl_ip'           => 'IP',
		);
	}
}
