<?php
/**
 * Boots the consent module (includes/consent/).
 */

if ( ! defined( 'ABSPATH' ) ) exit;

require_once __DIR__ . '/consent/class-consent-manager.php';

$handl_consent_manager = new \Handl\UtmrabberFree\Consent\Handl_Consent_Manager();
$handl_consent_manager->register_frontend_hooks();

if ( is_admin() ) {
	$handl_consent_manager->register_admin_hooks();
}
