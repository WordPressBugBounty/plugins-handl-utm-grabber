<?php
namespace Handl\UtmrabberFree\Submissions;

if ( ! defined( 'ABSPATH' ) ) exit;

require_once __DIR__ . '/class-submission-listener.php';
require_once __DIR__ . '/integrations/class-gravity-forms-submission-listener.php';
require_once __DIR__ . '/integrations/class-contact-form-7-submission-listener.php';
require_once __DIR__ . '/integrations/class-ninja-forms-submission-listener.php';
require_once __DIR__ . '/integrations/class-elementor-submission-listener.php';

/**
 * Boots every per-plugin submission listener. Each listener emits the shared
 * `handl_utm_submission` action, which onboarding and insights consume.
 *
 * Must boot unconditionally: Ninja Forms and Elementor submit via
 * admin-ajax.php, where is_admin() is true.
 */
class Handl_Submissions_Manager {

	/** @var Handl_Submission_Listener[] */
	private $listeners = array();

	public function __construct() {
		$this->listeners = array(
			new Gravity_Forms_Submission_Listener(),
			new Contact_Form_7_Submission_Listener(),
			new Ninja_Forms_Submission_Listener(),
			new Elementor_Submission_Listener(),
		);
	}

	public function boot() {
		foreach ( $this->listeners as $listener ) {
			$listener->boot();
		}
	}
}
