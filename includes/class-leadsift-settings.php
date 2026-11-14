<?php
/**
 * Settings storage and the "learned senders" memory.
 *
 * @package LeadSift
 */

defined( 'ABSPATH' ) || exit;

class LeadSift_Settings {

	const OPTION         = 'leadsift_settings';
	const LEARNED_OPTION = 'leadsift_learned_senders';
	const MAX_LEARNED    = 5000;

	public static function sources() {
		return array(
			'cf7'          => 'Contact Form 7',
			'wpforms'      => 'WPForms',
			'gravityforms' => 'Gravity Forms',
			'elementor'    => 'Elementor Pro Forms',
			'fluentforms'  => 'Fluent Forms',
			'ninjaforms'   => 'Ninja Forms',
			'formidable'   => 'Formidable Forms',
			'rest'         => 'Custom form (REST API)',
		);
	}

	public static function defaults() {
		$sources = array();
		foreach ( array_keys( self::sources() ) as $key ) {
			$sources[ $key ] = true;
		}

		return array(
			'sensitivity'          => 'balanced', // relaxed | balanced | strict.
			'sources'              => $sources,
			'suppress_emails'      => false,
			'notify_genuine'       => false,
			'notify_email'         => get_option( 'admin_email' ),
			'flag_foreign_scripts' => true,
			'promo_keywords'       => '',
			'genuine_keywords'     => '',
			'trusted_senders'      => '',
			'blocked_senders'      => '',
			'learn_from_actions'   => true,
			'retention_days'       => 0,
			'store_ip'             => true,
			'phone_country'        => self::guess_country(),
			'delete_on_uninstall'  => false,

			// Your own rules (see sanitize_rules()).
			'custom_rules'         => array(),

			// AI check.
			'ai_provider'          => 'none', // none | claude | custom.
			'ai_api_key'           => '',
			'ai_model'             => 'claude-opus-5-5',
			'ai_mode'              => 'unsure', // unsure | all.
			'ai_business_context'  => '',
			'ai_custom_url'        => '',
			'ai_custom_secret'     => '',

			// Any other AI (OpenAI-compatible chat API): OpenAI, Gemini, Groq, Mistral, DeepSeek, OpenRouter, xAI, Together, Ollama…
			'ai_compat_preset'     => 'openai',
			'ai_compat_base_url'   => 'https://api.openai.com/v1',
			'ai_compat_key'        => '',
			'ai_compat_model'      => '',

			// Optional price (USD per 1M tokens) for the cost estimate when the model is not in the built-in list.
			'ai_price_in'          => '',
			'ai_price_out'         => '',
		);
	}

	/**
	 * Ready-made settings for popular AI providers. All of them speak the
	 * OpenAI-style "chat/completions" API, so one connector covers them all.
	 */
	public static function ai_presets() {
		return array(
			'openai'     => array( 'label' => 'OpenAI (ChatGPT)', 'base_url' => 'https://api.openai.com/v1', 'key_url' => 'https://platform.openai.com/api-keys', 'needs_key' => true ),
			'gemini'     => array( 'label' => 'Google Gemini', 'base_url' => 'https://generativelanguage.googleapis.com/v1beta/openai', 'key_url' => 'https://aistudio.google.com/apikey', 'needs_key' => true ),
			'groq'       => array( 'label' => 'Groq', 'base_url' => 'https://api.groq.com/openai/v1', 'key_url' => 'https://console.groq.com/keys', 'needs_key' => true ),
			'mistral'    => array( 'label' => 'Mistral AI', 'base_url' => 'https://api.mistral.ai/v1', 'key_url' => 'https://console.mistral.ai/api-keys', 'needs_key' => true ),
			'deepseek'   => array( 'label' => 'DeepSeek', 'base_url' => 'https://api.deepseek.com/v1', 'key_url' => 'https://platform.deepseek.com/api_keys', 'needs_key' => true ),
			'openrouter' => array( 'label' => 'OpenRouter (hundreds of models)', 'base_url' => 'https://openrouter.ai/api/v1', 'key_url' => 'https://openrouter.ai/keys', 'needs_key' => true ),
			'xai'        => array( 'label' => 'xAI (Grok)', 'base_url' => 'https://api.x.ai/v1', 'key_url' => 'https://console.x.ai', 'needs_key' => true ),
			'together'   => array( 'label' => 'Together AI', 'base_url' => 'https://api.together.xyz/v1', 'key_url' => 'https://api.together.ai/settings/api-keys', 'needs_key' => true ),
			'ollama'     => array( 'label' => 'Ollama (runs on your own server)', 'base_url' => 'http://localhost:11434/v1', 'key_url' => '', 'needs_key' => false ),
			'other'      => array( 'label' => 'Other OpenAI-compatible API', 'base_url' => '', 'key_url' => '', 'needs_key' => false ),
		);
	}

	const RULE_FIELDS     = array( 'any', 'name', 'email', 'email_domain', 'phone', 'subject', 'message', 'form', 'form_type', 'source' );
	const RULE_OPERATORS  = array( 'contains', 'not_contains', 'equals', 'starts_with', 'ends_with', 'regex', 'is_empty' );
	const RULE_ACTIONS    = array( 'set_genuine', 'set_promotional', 'set_spam', 'score_promotional', 'score_spam', 'score_genuine' );
	const AI_MODELS       = array( 'claude-opus-5-5', 'claude-sonnet-5-5', 'claude-haiku-5-5' );
	const MAX_RULES       = 100;

	/** Settings a Client Portal login with "Manage" access may change on the dashboard. Rules and AI stay in wp-admin. */
	const DASHBOARD_KEYS  = array( 'sensitivity', 'sources', 'suppress_emails', 'notify_genuine', 'notify_email', 'flag_foreign_scripts', 'learn_from_actions', 'store_ip', 'phone_country', 'retention_days', 'delete_on_uninstall' );

	/**
	 * Cleans the user's custom rules.
	 *
	 * Rule shape: id, enabled, name, field, operator, value, action, points, invalid.
	 *
	 * @param mixed $rules Raw rules from the dashboard.
	 * @return array
	 */
	public static function sanitize_rules( $rules ) {
		$out = array();
		foreach ( array_slice( (array) $rules, 0, self::MAX_RULES ) as $rule ) {
			if ( ! is_array( $rule ) ) {
				continue;
			}
			$field    = in_array( $rule['field'] ?? '', self::RULE_FIELDS, true ) ? $rule['field'] : 'any';
			$operator = in_array( $rule['operator'] ?? '', self::RULE_OPERATORS, true ) ? $rule['operator'] : 'contains';
			$action   = in_array( $rule['action'] ?? '', self::RULE_ACTIONS, true ) ? $rule['action'] : 'set_promotional';
			$value    = trim( wp_strip_all_tags( (string) ( $rule['value'] ?? '' ) ) );
			$value    = function_exists( 'mb_substr' ) ? mb_substr( $value, 0, 500 ) : substr( $value, 0, 500 );
			$id       = preg_replace( '/[^a-z0-9_-]/i', '', (string) ( $rule['id'] ?? '' ) );

			$invalid = false;
			if ( 'regex' === $operator ) {
				// phpcs:ignore WordPress.PHP.NoSilencedErrors
				$invalid = '' === $value || false === @preg_match( '~' . str_replace( '~', '\~', $value ) . '~iu', '' );
			} elseif ( 'is_empty' !== $operator && '' === $value ) {
				$invalid = true;
			}

			$out[] = array(
				'id'       => $id ? $id : 'r' . substr( md5( uniqid( '', true ) ), 0, 8 ),
				'enabled'  => ! empty( $rule['enabled'] ),
				'name'     => sanitize_text_field( (string) ( $rule['name'] ?? '' ) ),
				'field'    => $field,
				'operator' => $operator,
				'value'    => $value,
				'action'   => $action,
				'points'   => max( 0, min( 100, (int) ( $rule['points'] ?? 20 ) ) ),
				'invalid'  => $invalid,
			);
		}
		return $out;
	}

	/**
	 * Settings safe to send to the browser (secrets masked).
	 */
	public static function public_view() {
		$s                   = self::get();
		$s['ai_key_set']     = '' !== $s['ai_api_key'];
		$s['ai_key_hint']    = $s['ai_key_set'] ? '…' . substr( $s['ai_api_key'], -4 ) : '';
		$s['ai_key_locked']  = defined( 'LEADSIFT_CLAUDE_API_KEY' ) && LEADSIFT_CLAUDE_API_KEY;
		$s['ai_secret_set']  = '' !== $s['ai_custom_secret'];
		$s['ai_compat_key_set']  = '' !== $s['ai_compat_key'];
		$s['ai_compat_key_hint'] = $s['ai_compat_key_set'] ? '…' . substr( $s['ai_compat_key'], -4 ) : '';
		$s['ai_api_key']         = '';
		$s['ai_custom_secret']   = '';
		$s['ai_compat_key']      = '';
		return $s;
	}

	/** 'IN' when the site looks Indian (timezone or language), so Indian mobile numbers are checked strictly. */
	/** What the dashboard (Client Portal) may see and change: no rules, no AI keys. */
	public static function dashboard_view() {
		$s = self::get();
		return array_intersect_key( $s, array_flip( self::DASHBOARD_KEYS ) );
	}

	/** 'IN' when the site looks Indian (timezone or language), so Indian mobile numbers are checked strictly. */
	public static function guess_country() {
		$tz     = (string) get_option( 'timezone_string' );
		$locale = function_exists( 'get_locale' ) ? (string) get_locale() : '';
		return ( in_array( $tz, array( 'Asia/Kolkata', 'Asia/Calcutta' ), true ) || preg_match( '/_IN$/', $locale ) || 0 === strpos( $locale, 'hi' ) ) ? 'IN' : '';
	}

	public static function get() {
		$saved    = get_option( self::OPTION, array() );
		$saved    = is_array( $saved ) ? $saved : array();
		$defaults = self::defaults();
		$out      = array_merge( $defaults, array_intersect_key( $saved, $defaults ) );

		$out['sources'] = array_merge(
			$defaults['sources'],
			isset( $saved['sources'] ) && is_array( $saved['sources'] ) ? $saved['sources'] : array()
		);
		$out['custom_rules'] = is_array( $out['custom_rules'] ) ? $out['custom_rules'] : array();

		// A key in wp-config.php ( define( 'LEADSIFT_CLAUDE_API_KEY', '...' ); ) wins over the saved one.
		if ( defined( 'LEADSIFT_CLAUDE_API_KEY' ) && LEADSIFT_CLAUDE_API_KEY ) {
			$out['ai_api_key'] = (string) LEADSIFT_CLAUDE_API_KEY;
		}

		return $out;
	}

	public static function update( array $input ) {
		$current = self::get();
		$clean   = $current;

		if ( isset( $input['sensitivity'] ) && in_array( $input['sensitivity'], array( 'relaxed', 'balanced', 'strict' ), true ) ) {
			$clean['sensitivity'] = $input['sensitivity'];
		}

		if ( isset( $input['sources'] ) && is_array( $input['sources'] ) ) {
			foreach ( array_keys( self::sources() ) as $key ) {
				if ( array_key_exists( $key, $input['sources'] ) ) {
					$clean['sources'][ $key ] = (bool) $input['sources'][ $key ];
				}
			}
		}

		if ( isset( $input['phone_country'] ) && in_array( $input['phone_country'], array( '', 'IN' ), true ) ) {
			$clean['phone_country'] = $input['phone_country'];
		}

		foreach ( array( 'suppress_emails', 'notify_genuine', 'flag_foreign_scripts', 'learn_from_actions', 'store_ip', 'delete_on_uninstall' ) as $bool ) {
			if ( array_key_exists( $bool, $input ) ) {
				$clean[ $bool ] = (bool) $input[ $bool ];
			}
		}

		if ( isset( $input['notify_email'] ) ) {
			$email                 = sanitize_email( $input['notify_email'] );
			$clean['notify_email'] = $email ? $email : get_option( 'admin_email' );
		}

		foreach ( array( 'promo_keywords', 'genuine_keywords', 'trusted_senders', 'blocked_senders' ) as $text ) {
			if ( isset( $input[ $text ] ) ) {
				$clean[ $text ] = sanitize_textarea_field( $input[ $text ] );
			}
		}

		if ( isset( $input['retention_days'] ) ) {
			$clean['retention_days'] = max( 0, min( 3650, (int) $input['retention_days'] ) );
		}

		if ( isset( $input['custom_rules'] ) ) {
			$clean['custom_rules'] = self::sanitize_rules( $input['custom_rules'] );
		}

		// AI settings. Secrets are only replaced when a new value is typed.
		if ( isset( $input['ai_compat_preset'] ) && array_key_exists( $input['ai_compat_preset'], self::ai_presets() ) ) {
			$clean['ai_compat_preset'] = $input['ai_compat_preset'];
		}
		if ( isset( $input['ai_compat_base_url'] ) ) {
			$clean['ai_compat_base_url'] = untrailingslashit( esc_url_raw( trim( (string) $input['ai_compat_base_url'] ), array( 'https', 'http' ) ) );
		}
		if ( isset( $input['ai_compat_model'] ) ) {
			$clean['ai_compat_model'] = substr( sanitize_text_field( $input['ai_compat_model'] ), 0, 200 );
		}
		if ( ! empty( $input['ai_compat_key'] ) ) {
			$clean['ai_compat_key'] = trim( sanitize_text_field( $input['ai_compat_key'] ) );
		}
		if ( ! empty( $input['ai_compat_key_clear'] ) ) {
			$clean['ai_compat_key'] = '';
		}

		if ( isset( $input['ai_provider'] ) && in_array( $input['ai_provider'], array( 'none', 'claude', 'compat', 'custom' ), true ) ) {
			$clean['ai_provider'] = $input['ai_provider'];
		}
		if ( isset( $input['ai_model'] ) && in_array( $input['ai_model'], self::AI_MODELS, true ) ) {
			$clean['ai_model'] = $input['ai_model'];
		}
		if ( isset( $input['ai_mode'] ) && in_array( $input['ai_mode'], array( 'unsure', 'all' ), true ) ) {
			$clean['ai_mode'] = $input['ai_mode'];
		}
		if ( isset( $input['ai_business_context'] ) ) {
			$clean['ai_business_context'] = sanitize_textarea_field( $input['ai_business_context'] );
		}
		foreach ( array( 'ai_price_in', 'ai_price_out' ) as $price ) {
			if ( isset( $input[ $price ] ) ) {
				$v               = trim( str_replace( array( '$', ',' ), array( '', '.' ), (string) $input[ $price ] ) );
				$clean[ $price ] = '' === $v || ! is_numeric( $v ) ? '' : (string) max( 0, min( 10000, (float) $v ) );
			}
		}
		if ( isset( $input['ai_custom_url'] ) ) {
			$clean['ai_custom_url'] = esc_url_raw( trim( (string) $input['ai_custom_url'] ), array( 'https', 'http' ) );
		}
		if ( ! empty( $input['ai_api_key'] ) ) {
			$clean['ai_api_key'] = trim( sanitize_text_field( $input['ai_api_key'] ) );
		}
		if ( ! empty( $input['ai_api_key_clear'] ) ) {
			$clean['ai_api_key'] = '';
		}
		if ( ! empty( $input['ai_custom_secret'] ) ) {
			$clean['ai_custom_secret'] = trim( sanitize_text_field( $input['ai_custom_secret'] ) );
		}
		if ( ! empty( $input['ai_custom_secret_clear'] ) ) {
			$clean['ai_custom_secret'] = '';
		}

		// Never store the wp-config.php key in the database.
		if ( defined( 'LEADSIFT_CLAUDE_API_KEY' ) && LEADSIFT_CLAUDE_API_KEY && $clean['ai_api_key'] === LEADSIFT_CLAUDE_API_KEY ) {
			$saved               = get_option( self::OPTION, array() );
			$clean['ai_api_key'] = is_array( $saved ) && isset( $saved['ai_api_key'] ) ? $saved['ai_api_key'] : '';
		}

		update_option( self::OPTION, $clean );
		return self::get();
	}

	/**
	 * Splits a textarea (new lines or commas) into a clean lowercase list.
	 *
	 * @param string $text Raw text.
	 * @return string[]
	 */
	public static function lines( $text ) {
		$parts = preg_split( '/[\r\n,]+/', strtolower( (string) $text ) );
		return array_values( array_filter( array_map( 'trim', $parts ), 'strlen' ) );
	}

	/* ---------- Learned senders (the plugin remembers your corrections) ---------- */

	public static function learned() {
		$list = get_option( self::LEARNED_OPTION, array() );
		return is_array( $list ) ? $list : array();
	}

	public static function learn( $email, $category ) {
		$email = strtolower( trim( (string) $email ) );
		if ( '' === $email || ! in_array( $category, LeadSift_Classifier::CATEGORIES, true ) ) {
			return;
		}
		$list           = self::learned();
		unset( $list[ $email ] );
		$list[ $email ] = $category;
		if ( count( $list ) > self::MAX_LEARNED ) {
			$list = array_slice( $list, -self::MAX_LEARNED, null, true );
		}
		update_option( self::LEARNED_OPTION, $list, false );
	}

	public static function forget( $email ) {
		$list = self::learned();
		unset( $list[ strtolower( trim( (string) $email ) ) ] );
		update_option( self::LEARNED_OPTION, $list, false );
	}
}
