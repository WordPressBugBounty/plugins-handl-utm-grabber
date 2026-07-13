<?php
namespace Handl\UtmrabberFree\Insights;

if ( ! defined( 'ABSPATH' ) ) exit;

require_once __DIR__ . '/class-entry-card-surface.php';
require_once __DIR__ . '/class-gravity-forms-entry-card.php';
require_once __DIR__ . '/class-ninja-forms-entry-card.php';

/** Boots the in-context entry-detail surfaces. Their render hooks fire only in admin. */
class Handl_Entry_Cards_Manager {

	/** @var Handl_Entry_Card_Surface[] */
	private $surfaces = array();

	public function __construct() {
		$this->surfaces = array(
			new Gravity_Forms_Entry_Card(),
			new Ninja_Forms_Entry_Card(),
		);
	}

	public function boot() {
		foreach ( $this->surfaces as $surface ) {
			$surface->boot();
		}
	}
}
