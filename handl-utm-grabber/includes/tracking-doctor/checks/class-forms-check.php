<?php
namespace Handl\UtmrabberFree\TrackingDoctor;

if ( ! defined( 'ABSPATH' ) ) exit;

/** Per-form hidden-field coverage of the tracked params, via the integrations layer. */
class Forms_Check extends Handl_Doctor_Check {

	/** @var \Handl\UtmrabberFree\Integrations\Handl_Integrations_Manager|null */
	private $integrations_manager;

	/** @var array<string, \Handl\UtmrabberFree\Integrations\Handl_Integration>|null Headless fallback instances. */
	private $integrations = null;

	public function __construct( $integrations_manager = null ) {
		$this->integrations_manager = $integrations_manager;
	}

	public function get_id() {
		return 'forms';
	}

	public function get_label() {
		return 'Form tracking';
	}

	public function scope_note() {
		return 'ONLY review form plugin integrations. The audit JSON already contains the full per-form, per-parameter matrix and the UI displays it to the user as a table — do NOT repeat per-parameter pass/fail results. Summarize instead: how many forms are fully covered, name only the forms with gaps, explain the attribution impact in plain language (leads submitted through a form with missing fields arrive without source/campaign data), and give the next action (one-click setup on the Integrations screen; suggest deleting leftover test forms instead of fixing them). Do not discuss WooCommerce orders, cookie consent plugins, caching, or global cookie settings.';
	}

	public function ai_site_context() {
		return array_merge(
			parent::ai_site_context(),
			array(
				'tracked_params' => handl_lite_tracking_params(),
				'param_catalog'  => $this->param_catalog(),
			)
		);
	}

	public function run() {
		$detailed      = $this->audit_forms_detailed();
		$integrations  = $detailed['integrations'];
		$coverage      = $detailed['coverage'];
		$problem_forms = array();

		foreach ( $integrations as $integration ) {
			foreach ( $integration['forms'] as $form ) {
				if ( $form['status'] !== 'complete' && ! empty( $form['missing'] ) ) {
					$problem_forms[] = array(
						'integration'       => $integration['slug'],
						'integration_label' => $integration['label'],
						'form_id'           => $form['form_id'],
						'title'             => $form['title'],
						'status'            => $form['status'],
						'missing'           => $form['missing'],
						'integrated'        => $form['integrated'],
						'params'            => $form['params'],
					);
				}
			}
		}

		if ( empty( $integrations ) ) {
			return $this->build_check(
				'warn',
				'No supported form plugins are active (Contact Form 7, Gravity Forms, Ninja Forms, or Elementor).',
				$detailed,
				array(
					'label' => 'Set up form tracking',
					'url'   => admin_url( 'admin.php?page=handl-onboarding#/connect' ),
					'type'  => 'link',
				)
			);
		}

		if ( ! empty( $problem_forms ) ) {
			return $this->build_check(
				'fail',
				sprintf(
					'%1$d of %2$d form(s) missing one or more of %3$d required HandL hidden fields.',
					count( $problem_forms ),
					$coverage['forms'],
					$coverage['params_per_form']
				),
				array_merge( $detailed, array( 'problem_forms' => $problem_forms ) ),
				array(
					'label' => 'Fix this',
					'url'   => admin_url( 'admin.php?page=handl-utm-grabber.php#/integrations' ),
					'type'  => 'link',
				)
			);
		}

		return $this->build_check(
			'pass',
			sprintf(
				'All %1$d form(s) include every required hidden field (%2$d params: %3$s).',
				$coverage['forms'],
				$coverage['params_per_form'],
				implode( ', ', $detailed['tracked_params'] )
			),
			$detailed
		);
	}

	/** Per-form parameter matrix, coverage totals, and per-param rollups for active integrations. */
	private function audit_forms_detailed() {
		$tracked     = handl_lite_tracking_params();
		$param_count = count( $tracked );

		$integrations  = array();
		$totals        = array(
			'forms'           => 0,
			'complete_forms'  => 0,
			'partial_forms'   => 0,
			'none_forms'      => 0,
			'params_per_form' => $param_count,
		);
		$param_rollups = array();
		foreach ( $tracked as $param ) {
			$param_rollups[ $param ] = array(
				'present_on_forms' => 0,
				'missing_on_forms' => 0,
				'total_forms'      => 0,
			);
		}

		foreach ( $this->integrations() as $slug => $integration ) {
			if ( ! $integration->is_active() ) {
				continue;
			}

			$form_rows = array();
			foreach ( $integration->get_forms() as $form ) {
				$status = $integration->get_form_status( $form['id'] );
				$params = array();

				foreach ( $tracked as $param ) {
					$present  = in_array( $param, $status['integrated'], true );
					$params[] = array(
						'param'          => $param,
						'present'        => $present,
						'expected_field' => $this->expected_field_name( $slug, $param ),
					);

					$param_rollups[ $param ]['total_forms']++;
					if ( $present ) {
						$param_rollups[ $param ]['present_on_forms']++;
					} else {
						$param_rollups[ $param ]['missing_on_forms']++;
					}
				}

				++$totals['forms'];
				if ( $status['status'] === 'complete' ) {
					++$totals['complete_forms'];
				} elseif ( $status['status'] === 'partial' ) {
					++$totals['partial_forms'];
				} elseif ( $status['status'] === 'none' ) {
					++$totals['none_forms'];
				}

				$form_rows[] = array(
					'form_id'          => (string) $form['id'],
					'title'            => isset( $form['title'] ) ? (string) $form['title'] : '',
					'status'           => $status['status'],
					'integrated_count' => count( $status['integrated'] ),
					'missing_count'    => count( $status['missing'] ),
					'required_count'   => $param_count,
					'integrated'       => array_values( $status['integrated'] ),
					'missing'          => array_values( $status['missing'] ),
					'params'           => $params,
				);
			}

			$integrations[] = array(
				'slug'         => $slug,
				'label'        => $integration->get_label(),
				'form_count'   => count( $form_rows ),
				'forms'        => $form_rows,
				'field_naming' => $this->field_naming_note( $slug ),
			);
		}

		return array(
			'param_catalog'   => $this->param_catalog(),
			'tracked_params'  => $tracked,
			'coverage'        => $totals,
			'param_rollups'   => $param_rollups,
			'integrations'    => $integrations,
			'scanned_plugins' => $this->scanned_plugins(),
		);
	}

	/**
	 * From the admin manager when injected, else owned directly (like
	 * Handl_Site_Health_Manager) so the audit also runs headless.
	 *
	 * @return array<string, \Handl\UtmrabberFree\Integrations\Handl_Integration>
	 */
	private function integrations() {
		if ( $this->integrations_manager ) {
			return $this->integrations_manager->get_integrations();
		}

		if ( $this->integrations === null ) {
			$base = dirname( dirname( __DIR__ ) ) . '/integrations';
			require_once $base . '/class-integration.php';
			require_once $base . '/gravity-forms/class-gravity-forms-integration.php';
			require_once $base . '/contact-form-7/class-contact-form-7-integration.php';
			require_once $base . '/ninja-forms/class-ninja-forms-integration.php';
			require_once $base . '/elementor/class-elementor-integration.php';

			$this->integrations = array(
				'gravity-forms'  => new \Handl\UtmrabberFree\Integrations\Gravity_Forms_Integration(),
				'contact-form-7' => new \Handl\UtmrabberFree\Integrations\Contact_Form_7_Integration(),
				'ninja-forms'    => new \Handl\UtmrabberFree\Integrations\Ninja_Forms_Integration(),
				'elementor'      => new \Handl\UtmrabberFree\Integrations\Elementor_Integration(),
			);
		}

		return $this->integrations;
	}

	/** Supported form plugins with active state, from the integrations layer. */
	private function scanned_plugins() {
		$out = array();
		foreach ( $this->integrations() as $slug => $integration ) {
			$out[] = array(
				'slug'   => $slug,
				'label'  => $integration->get_label(),
				'active' => (bool) $integration->is_active(),
			);
		}
		return $out;
	}

	/** Expected hidden-field identifier per integration. */
	private function expected_field_name( $slug, $param ) {
		switch ( $slug ) {
			case 'contact-form-7':
				return $param . '_cf7';
			case 'gravity-forms':
				return $param . ' (inputName)';
			case 'ninja-forms':
				return $param . ' (field key)';
			case 'elementor':
				return $param . ' (custom_id, hidden)';
			default:
				return $param;
		}
	}

	private function field_naming_note( $slug ) {
		$notes = array(
			'contact-form-7' => 'Hidden tags: [hidden {param}_cf7 ... class:{param} id:{param}]',
			'gravity-forms'  => 'Hidden fields with inputName equal to the parameter key (e.g. utm_source)',
			'ninja-forms'    => 'Hidden fields with key equal to the parameter, default {handl:param}',
			'elementor'      => 'Hidden form fields with custom_id equal to the parameter key',
		);
		return isset( $notes[ $slug ] ) ? $notes[ $slug ] : '';
	}
}
