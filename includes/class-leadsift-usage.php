<?php
/**
 * AI usage log: one row per call to the AI API (tokens, time, result, cost).
 *
 * Token numbers come straight from the AI provider's answer (the "usage" part of
 * every API response), so they match what the provider bills. The cost is an
 * estimate: tokens × the model's public price per 1 million tokens (built-in
 * list for OpenAI models, or the price you enter in Rules & AI).
 *
 * @package LeadSift
 */

defined( 'ABSPATH' ) || exit;

class LeadSift_Usage {

	const KEEP_DAYS = 400;

	/** @var int ID of the last logged call (so the verdict can be added to it). */
	public static $last_id = 0;

	/** @var string What the next AI call is for: inquiry | test | business. */
	public static $purpose = 'inquiry';

	/** @var int Inquiry the next AI call is about (0 = none). */
	public static $inquiry_id = 0;

	/**
	 * OpenAI standard prices, USD per 1M tokens: input, cached input, output.
	 * Source: https://developers.openai.com/api/docs/pricing (checked Nov 2026).
	 * Longest matching prefix wins, so "gpt-5-mini-2025-08-07" uses "gpt-5-mini".
	 */
	const PRICES = array(
		'gpt-5.6-sol'   => array( 4.00, 0.40, 20.00 ),
		'gpt-5.6-terra' => array( 2.00, 0.20, 12.00 ),
		'gpt-5.6-luna'  => array( 0.20, 0.02, 1.20 ),
		'gpt-5.5-pro'   => array( 30.00, 30.00, 180.00 ),
		'gpt-5.5'       => array( 5.00, 0.50, 30.00 ),
		'gpt-5.4-pro'   => array( 30.00, 30.00, 180.00 ),
		'gpt-5.4-mini'  => array( 0.75, 0.075, 4.50 ),
		'gpt-5.4-nano'  => array( 0.20, 0.02, 1.25 ),
		'gpt-5.4'       => array( 2.50, 0.25, 15.00 ),
		'gpt-5.2-pro'   => array( 21.00, 21.00, 168.00 ),
		'gpt-5.2'       => array( 1.75, 0.175, 14.00 ),
		'gpt-5.1'       => array( 1.25, 0.125, 10.00 ),
		'gpt-5-pro'     => array( 15.00, 15.00, 120.00 ),
		'gpt-5-mini'    => array( 0.25, 0.025, 2.00 ),
		'gpt-5-nano'    => array( 0.05, 0.005, 0.40 ),
		'gpt-5'         => array( 1.25, 0.125, 10.00 ),
		'gpt-4.1-mini'  => array( 0.40, 0.10, 1.60 ),
		'gpt-4.1'       => array( 2.00, 0.50, 8.00 ),
		'gpt-4o-mini'   => array( 0.15, 0.075, 0.60 ),
		'gpt-4o'        => array( 2.50, 1.25, 10.00 ),
		'o3-pro'        => array( 20.00, 20.00, 80.00 ),
		'o3'            => array( 2.00, 0.50, 8.00 ),
	);

	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'leadsift_ai_log';
	}

	public static function schema( $charset ) {
		$table = self::table();
		return "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			created_at datetime NOT NULL DEFAULT '1970-01-01 00:00:00',
			provider varchar(40) NOT NULL DEFAULT '',
			model varchar(120) NOT NULL DEFAULT '',
			purpose varchar(20) NOT NULL DEFAULT 'inquiry',
			inquiry_id bigint(20) unsigned NOT NULL DEFAULT 0,
			input_tokens int(10) unsigned NOT NULL DEFAULT 0,
			cached_tokens int(10) unsigned NOT NULL DEFAULT 0,
			output_tokens int(10) unsigned NOT NULL DEFAULT 0,
			reasoning_tokens int(10) unsigned NOT NULL DEFAULT 0,
			ms int(10) unsigned NOT NULL DEFAULT 0,
			status varchar(10) NOT NULL DEFAULT 'ok',
			error varchar(255) NOT NULL DEFAULT '',
			verdict varchar(20) NOT NULL DEFAULT '',
			confidence tinyint(3) unsigned NOT NULL DEFAULT 0,
			PRIMARY KEY  (id),
			KEY created_at (created_at),
			KEY inquiry_id (inquiry_id)
		) {$charset};";
	}

	/**
	 * Turns a provider's "usage" block into one shape.
	 * OpenAI: prompt_tokens / completion_tokens (+ cached, reasoning details).
	 * Claude: input_tokens / output_tokens (+ cache read / creation).
	 */
	public static function normalize( $usage ) {
		$u = is_array( $usage ) ? $usage : array();
		if ( isset( $u['prompt_tokens'] ) || isset( $u['completion_tokens'] ) ) {
			return array(
				'input'     => (int) ( $u['prompt_tokens'] ?? 0 ),
				'cached'    => (int) ( $u['prompt_tokens_details']['cached_tokens'] ?? 0 ),
				'output'    => (int) ( $u['completion_tokens'] ?? 0 ),
				'reasoning' => (int) ( $u['completion_tokens_details']['reasoning_tokens'] ?? 0 ),
			);
		}
		$read = (int) ( $u['cache_read_input_tokens'] ?? 0 );
		return array(
			'input'     => (int) ( $u['input_tokens'] ?? 0 ) + $read + (int) ( $u['cache_creation_input_tokens'] ?? 0 ),
			'cached'    => $read,
			'output'    => (int) ( $u['output_tokens'] ?? 0 ),
			'reasoning' => 0,
		);
	}

	/**
	 * Saves one AI call. $result is the call's answer (array) or WP_Error.
	 */
	public static function log( array $settings, $model, $usage, $started, $result ) {
		global $wpdb;
		$u     = self::normalize( $usage );
		$error = is_wp_error( $result ) ? $result->get_error_message() : '';
		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			self::table(),
			array(
				'created_at'       => current_time( 'mysql' ),
				'provider'         => substr( LeadSift_AI::label( $settings ), 0, 40 ),
				'model'            => substr( (string) $model, 0, 120 ),
				'purpose'          => self::$purpose,
				'inquiry_id'       => (int) self::$inquiry_id,
				'input_tokens'     => $u['input'],
				'cached_tokens'    => $u['cached'],
				'output_tokens'    => $u['output'],
				'reasoning_tokens' => $u['reasoning'],
				'ms'               => (int) round( ( microtime( true ) - $started ) * 1000 ),
				'status'           => '' === $error ? 'ok' : 'error',
				'error'            => mb_substr( $error, 0, 250 ),
			)
		);
		self::$last_id = (int) $wpdb->insert_id;
	}

	/** Adds the AI's verdict to the call that produced it. */
	public static function set_verdict( $id, $category, $confidence ) {
		global $wpdb;
		if ( $id ) {
			$wpdb->update( self::table(), array( 'verdict' => (string) $category, 'confidence' => (int) $confidence ), array( 'id' => (int) $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		}
	}

	/** Remembers what the next AI call is for. */
	public static function context( $purpose, $inquiry_id = 0 ) {
		self::$purpose    = $purpose;
		self::$inquiry_id = (int) $inquiry_id;
	}

	public static function cleanup() {
		global $wpdb;
		$table = self::table();
		// phpcs:ignore WordPress.DB.PreparedSQL
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE created_at < %s", gmdate( 'Y-m-d H:i:s', time() - self::KEEP_DAYS * DAY_IN_SECONDS ) ) );
	}

	/* ------------------------------ Prices ------------------------------ */

	/**
	 * Price for a model: [ input, cached, output, source ] in USD per 1M tokens, or null.
	 */
	public static function price( $model, array $settings ) {
		$in  = (string) ( $settings['ai_price_in'] ?? '' );
		$out = (string) ( $settings['ai_price_out'] ?? '' );
		if ( '' !== $in && '' !== $out ) {
			return array( (float) $in, (float) $in, (float) $out, 'yours' );
		}
		$model = strtolower( (string) $model );
		$model = preg_replace( '#^openai/#', '', $model ); // OpenRouter style.
		$best  = '';
		foreach ( array_keys( self::PRICES ) as $prefix ) {
			if ( 0 === strpos( $model, $prefix ) && strlen( $prefix ) > strlen( $best ) ) {
				$best = $prefix;
			}
		}
		if ( '' === $best ) {
			return null;
		}
		$p = self::PRICES[ $best ];
		return array( $p[0], $p[1], $p[2], 'built-in' );
	}

	public static function cost( array $row, $price ) {
		if ( ! $price ) {
			return null;
		}
		$cached = min( (int) $row['cached'], (int) $row['input'] );
		return ( ( (int) $row['input'] - $cached ) * $price[0] + $cached * $price[1] + (int) $row['output'] * $price[2] ) / 1000000;
	}

	/** The model the current settings send to. */
	public static function current_model( array $s ) {
		if ( 'compat' === $s['ai_provider'] ) {
			return (string) $s['ai_compat_model'];
		}
		if ( 'claude' === $s['ai_provider'] ) {
			return (string) $s['ai_model'];
		}
		return 'custom' === $s['ai_provider'] ? 'your API' : '';
	}

	/* ------------------------------ Report ------------------------------ */

	/**
	 * Everything the dashboard's "AI usage" tab shows.
	 */
	public static function report() {
		global $wpdb;
		$table = self::table();
		$s     = LeadSift_Settings::get();
		$model = self::current_model( $s );
		$now   = current_time( 'timestamp' ); // phpcs:ignore WordPress.DateTime.CurrentTimeTimestamp
		$today = gmdate( 'Y-m-d 00:00:00', $now );
		$d7    = gmdate( 'Y-m-d 00:00:00', $now - 6 * DAY_IN_SECONDS );
		$d30   = gmdate( 'Y-m-d 00:00:00', $now - 29 * DAY_IN_SECONDS );
		$month = gmdate( 'Y-m-01 00:00:00', $now );

		// Totals per model per period, so each model's own price is used.
		// phpcs:disable WordPress.DB.PreparedSQL
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT model,
					SUM(created_at >= %s) AS today_calls,
					SUM(created_at >= %s) AS d7_calls,
					SUM(created_at >= %s) AS d30_calls,
					SUM(created_at >= %s) AS month_calls,
					COUNT(*) AS all_calls,
					SUM(IF(created_at >= %s, input_tokens, 0)) AS today_in, SUM(IF(created_at >= %s, cached_tokens, 0)) AS today_cached, SUM(IF(created_at >= %s, output_tokens, 0)) AS today_out, SUM(IF(created_at >= %s, reasoning_tokens, 0)) AS today_reason, SUM(IF(created_at >= %s AND status = 'error', 1, 0)) AS today_err,
					SUM(IF(created_at >= %s, input_tokens, 0)) AS d7_in, SUM(IF(created_at >= %s, cached_tokens, 0)) AS d7_cached, SUM(IF(created_at >= %s, output_tokens, 0)) AS d7_out, SUM(IF(created_at >= %s, reasoning_tokens, 0)) AS d7_reason, SUM(IF(created_at >= %s AND status = 'error', 1, 0)) AS d7_err,
					SUM(IF(created_at >= %s, input_tokens, 0)) AS d30_in, SUM(IF(created_at >= %s, cached_tokens, 0)) AS d30_cached, SUM(IF(created_at >= %s, output_tokens, 0)) AS d30_out, SUM(IF(created_at >= %s, reasoning_tokens, 0)) AS d30_reason, SUM(IF(created_at >= %s AND status = 'error', 1, 0)) AS d30_err,
					SUM(IF(created_at >= %s, input_tokens, 0)) AS month_in, SUM(IF(created_at >= %s, cached_tokens, 0)) AS month_cached, SUM(IF(created_at >= %s, output_tokens, 0)) AS month_out, SUM(IF(created_at >= %s, reasoning_tokens, 0)) AS month_reason, SUM(IF(created_at >= %s AND status = 'error', 1, 0)) AS month_err,
					SUM(input_tokens) AS all_in, SUM(cached_tokens) AS all_cached, SUM(output_tokens) AS all_out, SUM(reasoning_tokens) AS all_reason, SUM(status = 'error') AS all_err,
					AVG(IF(status = 'ok', ms, NULL)) AS avg_ms
				FROM {$table} GROUP BY model",
				$today, $d7, $d30, $month,
				$today, $today, $today, $today, $today,
				$d7, $d7, $d7, $d7, $d7,
				$d30, $d30, $d30, $d30, $d30,
				$month, $month, $month, $month, $month
			),
			ARRAY_A
		);

		$periods = array();
		$models  = array();
		foreach ( array( 'today', 'd7', 'month', 'd30', 'all' ) as $p ) {
			$periods[ $p ] = array( 'calls' => 0, 'errors' => 0, 'input' => 0, 'cached' => 0, 'output' => 0, 'reasoning' => 0, 'cost' => 0.0, 'cost_known' => true );
		}
		$ms_sum = 0;
		$ms_n   = 0;
		foreach ( (array) $rows as $r ) {
			$price = self::price( $r['model'], $s );
			foreach ( array_keys( $periods ) as $p ) {
				$t = array(
					'input'  => (int) $r[ $p . '_in' ],
					'cached' => (int) $r[ $p . '_cached' ],
					'output' => (int) $r[ $p . '_out' ],
				);
				$periods[ $p ]['calls']     += (int) $r[ $p . '_calls' ];
				$periods[ $p ]['errors']    += (int) $r[ $p . '_err' ];
				$periods[ $p ]['input']     += $t['input'];
				$periods[ $p ]['cached']    += $t['cached'];
				$periods[ $p ]['output']    += $t['output'];
				$periods[ $p ]['reasoning'] += (int) $r[ $p . '_reason' ];
				$c = self::cost( $t, $price );
				if ( null === $c ) {
					if ( $t['input'] + $t['output'] > 0 ) {
						$periods[ $p ]['cost_known'] = false;
					}
				} else {
					$periods[ $p ]['cost'] += $c;
				}
			}
			if ( null !== $r['avg_ms'] ) {
				$ms_sum += (float) $r['avg_ms'] * (int) $r['all_calls'];
				$ms_n   += (int) $r['all_calls'];
			}
			$models[] = array(
				'model'  => $r['model'],
				'calls'  => (int) $r['all_calls'],
				'input'  => (int) $r['all_in'],
				'output' => (int) $r['all_out'],
				'cost'   => self::cost( array( 'input' => (int) $r['all_in'], 'cached' => (int) $r['all_cached'], 'output' => (int) $r['all_out'] ), $price ),
				'price'  => $price,
			);
		}

		// Per day, last 30 days.
		$daily_rows = $wpdb->get_results(
			$wpdb->prepare( "SELECT DATE(created_at) AS d, model, COUNT(*) AS calls, SUM(input_tokens) AS input, SUM(cached_tokens) AS cached, SUM(output_tokens) AS output FROM {$table} WHERE created_at >= %s GROUP BY d, model", $d30 ),
			ARRAY_A
		);
		$daily = array();
		for ( $i = 29; $i >= 0; $i-- ) {
			$d           = gmdate( 'Y-m-d', $now - $i * DAY_IN_SECONDS );
			$daily[ $d ] = array( 'date' => $d, 'calls' => 0, 'tokens' => 0, 'cost' => 0.0 );
		}
		foreach ( (array) $daily_rows as $r ) {
			if ( isset( $daily[ $r['d'] ] ) ) {
				$daily[ $r['d'] ]['calls']  += (int) $r['calls'];
				$daily[ $r['d'] ]['tokens'] += (int) $r['input'] + (int) $r['output'];
				$daily[ $r['d'] ]['cost']   += (float) self::cost( $r, self::price( $r['model'], $s ) );
			}
		}

		// What the calls were for.
		$purpose = $wpdb->get_results( "SELECT purpose, COUNT(*) AS calls, SUM(input_tokens) AS input, SUM(output_tokens) AS output FROM {$table} GROUP BY purpose", ARRAY_A );

		// Latest calls.
		$inq    = LeadSift_DB::table();
		$recent = $wpdb->get_results(
			"SELECT l.*, i.name AS inquiry_name, i.email AS inquiry_email, i.category AS inquiry_category
			FROM {$table} l LEFT JOIN {$inq} i ON i.id = l.inquiry_id
			ORDER BY l.id DESC LIMIT 30",
			ARRAY_A
		);
		foreach ( (array) $recent as &$r ) {
			$r['cost'] = self::cost(
				array(
					'input'  => $r['input_tokens'],
					'cached' => $r['cached_tokens'],
					'output' => $r['output_tokens'],
				),
				self::price( $r['model'], $s )
			);
			foreach ( array( 'id', 'inquiry_id', 'input_tokens', 'cached_tokens', 'output_tokens', 'reasoning_tokens', 'ms', 'confidence' ) as $k ) {
				$r[ $k ] = (int) $r[ $k ];
			}
		}
		unset( $r );

		// How many inquiries went to the AI, and what the AI changed (last 30 days).
		$inquiries_30 = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$inq} WHERE created_at >= %s AND external_ref = ''", $d30 ) );
		$checked_30   = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(DISTINCT inquiry_id) FROM {$table} WHERE purpose = 'inquiry' AND inquiry_id > 0 AND created_at >= %s", $d30 ) );
		$agree        = $wpdb->get_row( "SELECT SUM(ai_category = category) AS same, SUM(ai_category <> category) AS diff, COUNT(*) AS n FROM {$inq} WHERE ai_category <> ''", ARRAY_A );
		// phpcs:enable

		$ok        = max( 1, $periods['all']['calls'] - $periods['all']['errors'] );
		$avg_cost  = $periods['all']['cost_known'] && $periods['all']['calls'] > $periods['all']['errors'] ? $periods['all']['cost'] / $ok : null;
		$t         = LeadSift_Classifier::THRESHOLDS[ $s['sensitivity'] ] ?? 40;
		$price_now = self::price( $model, $s );

		return array(
			'setup'     => array(
				'enabled'     => LeadSift_AI::enabled( $s ),
				'provider'    => $s['ai_provider'],
				'preset'      => $s['ai_compat_preset'],
				'label'       => 'none' === $s['ai_provider'] ? '' : LeadSift_AI::label( $s ),
				'model'       => $model,
				'mode'        => $s['ai_mode'],
				'range'       => array( max( 1, $t - 25 ), $t + 25 ),
				'threshold'   => $t,
				'trust'       => LeadSift_AI::TRUST,
				'price'       => $price_now,
				'has_context' => '' !== trim( (string) $s['ai_business_context'] ),
			),
			'periods'   => $periods,
			'avg'       => array(
				'input'  => $periods['all']['calls'] ? (int) round( $periods['all']['input'] / $ok ) : 0,
				'output' => $periods['all']['calls'] ? (int) round( $periods['all']['output'] / $ok ) : 0,
				'cost'   => $avg_cost,
				'ms'     => $ms_n ? (int) round( $ms_sum / $ms_n ) : 0,
			),
			'daily'     => array_values( $daily ),
			'purposes'  => array_map(
				static function ( $r ) {
					return array( 'purpose' => $r['purpose'], 'calls' => (int) $r['calls'], 'input' => (int) $r['input'], 'output' => (int) $r['output'] );
				},
				(array) $purpose
			),
			'models'    => $models,
			'recent'    => (array) $recent,
			'coverage'  => array(
				'inquiries' => $inquiries_30,
				'checked'   => $checked_30,
			),
			'agreement' => array(
				'same' => (int) ( $agree['same'] ?? 0 ),
				'diff' => (int) ( $agree['diff'] ?? 0 ),
				'n'    => (int) ( $agree['n'] ?? 0 ),
			),
		);
	}
}
