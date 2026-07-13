<?php

namespace Handl\UtmrabberFree\Insights;

if (! defined('ABSPATH')) exit;

/**
 * AI insight analyzer on the WP 7.0 AI Client. Same card shape as the manual engine;
 * returns null on any failure so the provider falls back. Sends the full payload.
 */
class Handl_AI_Analyzer
{

	/** Bump when the prompt or schema changes to invalidate cached results. */
	const PROMPT_VERSION = 'v1';

	const MAX_FIELD_LEN = 500;

	/** @var bool|null Per-request availability cache. */
	private static $available = null;

	/** Site-level opt-out (Settings > HandL). On by default. */
	public static function is_enabled()
	{
		return (bool) get_option('handl_ai_insights_enabled', true);
	}

	public static function is_available()
	{
		if (self::$available !== null) {
			return self::$available;
		}

		self::$available = false;
		if (! self::is_enabled() || ! function_exists('wp_ai_client_prompt')) {
			return self::$available;
		}

		try {
			$ai_client_prompt = wp_ai_client_prompt();
			$is_supported = $ai_client_prompt->is_supported_for_text_generation();
			self::$available = ($is_supported === true);
		} catch (\Throwable $e) {
			self::$available = false;
		}

		return self::$available;
	}

	/** Analyze a lead's params into cards via AI; null on any failure (caller falls back). */
	public static function analyze(array $posted)
	{
		if (empty($posted) || ! self::is_available()) {
			return null;
		}

		$prompt = "Lead tracking parameters (JSON):\n" . wp_json_encode($posted)
			. "\n\nReturn 1-2 insights as specified by the schema.";

		try {
			// Provider-agnostic: omit tuning params (temperature/max_tokens) reasoning models reject.
			$json = wp_ai_client_prompt($prompt)
				->using_system_instruction(self::system_instruction())
				->as_json_response(self::schema())
				->generate_text();
		} catch (\Throwable $e) {
			return null;
		}

		if (is_wp_error($json) || ! is_string($json) || $json === '') {
			return null;
		}

		$decoded = json_decode($json, true);
		if (! is_array($decoded)) {
			return null;
		}

		// Root is an object with an "insights" array (strict schema); tolerate a bare array too.
		$items = (isset($decoded['insights']) && is_array($decoded['insights'])) ? $decoded['insights'] : $decoded;

		return self::to_cards($items);
	}

	/** Convert the decoded AI response into validated cards, or null if unusable. */
	private static function to_cards(array $decoded)
	{
		$allowed_sev = array('info', 'success', 'warning');
		$cards       = array();

		foreach ($decoded as $item) {
			if (! is_array($item)) {
				continue;
			}
			$message = isset($item['message']) ? self::clean($item['message']) : '';
			$action  = isset($item['action']) ? self::clean($item['action']) : '';
			if ($message === '') {
				continue;
			}

			$severity = isset($item['severity']) && in_array($item['severity'], $allowed_sev, true)
				? $item['severity']
				: 'info';

			$card = array(
				'id'       => 'ai',
				'severity' => $severity,
				'priority' => 10,
				'message'  => $message,
				'action'   => $action,
			);

			$cta = isset($item['cta']) ? (string) $item['cta'] : 'none';
			if ('crm' === $cta || 'upgrade' === $cta) {
				$card = array_merge($card, Handl_Insight_Engine::cta($cta, 'ai_' . $cta));
			}

			$cards[] = $card;

			if (count($cards) >= 2) {
				break;
			}
		}

		return ! empty($cards) ? $cards : null;
	}

	/** Sanitize + length-cap a model-provided string. */
	private static function clean($value)
	{
		$value = wp_strip_all_tags((string) $value);
		$value = trim(preg_replace('/\s+/', ' ', $value));
		if (function_exists('mb_substr')) {
			return mb_substr($value, 0, self::MAX_FIELD_LEN);
		}
		return substr($value, 0, self::MAX_FIELD_LEN);
	}

	/** System instruction: role, guardrails, and the param glossary. */
	private static function system_instruction()
	{
		return implode(
			"\n",
			array(
				'You are a marketing attribution analyst inside a WordPress plugin.',
				'You are given the tracking parameters captured from a single form submission (a lead).',
				'Explain, in plain language for a busy marketer, where this lead most likely came from and the single most useful next action.',
				'Be concise and factual. Never invent data that is not present in the parameters. Return at most 2 insights.',
				'Each insight has two fields: "message" is a short headline (about 4-8 words, like a label, no trailing period), and "action" is one short sentence with the recommended next step. Keep the message clearly shorter than the action.',
				'Example "message" (match this brevity): "Google Ads lead - campaign spring_sale", "Paid lead from Facebook (cpc)", "Organic or referral lead - no campaign tags".',
				'',
				'Parameter glossary:',
				'- utm_source: referring platform/vendor (e.g. google, facebook, newsletter).',
				'- utm_medium: marketing channel (e.g. cpc, ppc, email, social, organic, referral).',
				'- utm_campaign: campaign name.',
				'- utm_term: paid keyword.',
				'- utm_content: ad/creative or link variant.',
				'- gclid: Google Ads click identifier; if present, this was a Google Ads click.',
				'- handl_landing_page: the first page the visitor landed on (first touch), full URL.',
				'- handl_original_ref: the original referrer URL the visitor arrived from (first touch).',
				'- handl_ip: the visitor IP address.',
				'A key with an empty string ("") means the form maps that field but this visitor arrived without a value (untagged visit).',
				'A key that is entirely absent means the form has no field for that parameter (a tracking setup gap - often the more actionable insight).',
				'',
				'For each insight set "cta" to: "crm" when the useful next step is sending the lead/campaign data to a CRM;',
				'"upgrade" when richer attribution (first/last touch, click IDs, AI reports) would clearly help; otherwise "none".',
			)
		);
	}

	/**
	 * Output schema. Strict providers need additionalProperties:false, all keys required,
	 * and an object root - hence insights[] wrapped in an object.
	 */
	private static function schema()
	{
		$item = array(
			'type'                 => 'object',
			'additionalProperties' => false,
			'properties'           => array(
				'severity' => array(
					'type' => 'string',
					'enum' => array('info', 'success', 'warning'),
				),
				'message'  => array('type' => 'string'),
				'action'   => array('type' => 'string'),
				'cta'      => array(
					'type' => 'string',
					'enum' => array('none', 'crm', 'upgrade'),
				),
			),
			'required'             => array('severity', 'message', 'action', 'cta'),
		);

		return array(
			'type'                 => 'object',
			'additionalProperties' => false,
			'properties'           => array(
				'insights' => array(
					'type'     => 'array',
					'maxItems' => 2,
					'items'    => $item,
				),
			),
			'required'             => array('insights'),
		);
	}
}
