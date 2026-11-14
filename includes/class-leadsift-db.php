<?php
/**
 * Database access for the inquiries table.
 *
 * @package LeadSift
 */

defined( 'ABSPATH' ) || exit;

class LeadSift_DB {

	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'leadsift_inquiries';
	}

	public static function install() {
		global $wpdb;
		$table   = self::table();
		$charset = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			source varchar(40) NOT NULL DEFAULT '',
			form_id varchar(64) NOT NULL DEFAULT '',
			form_name varchar(190) NOT NULL DEFAULT '',
			name varchar(190) NOT NULL DEFAULT '',
			email varchar(190) NOT NULL DEFAULT '',
			phone varchar(64) NOT NULL DEFAULT '',
			subject varchar(255) NOT NULL DEFAULT '',
			message longtext NULL,
			form_data longtext NULL,
			ip varchar(64) NOT NULL DEFAULT '',
			user_agent varchar(255) NOT NULL DEFAULT '',
			page_url varchar(255) NOT NULL DEFAULT '',
			content_hash char(32) NOT NULL DEFAULT '',
			score tinyint(3) unsigned NOT NULL DEFAULT 0,
			category varchar(20) NOT NULL DEFAULT 'genuine',
			reasons longtext NULL,
			is_manual tinyint(1) NOT NULL DEFAULT 0,
			is_read tinyint(1) NOT NULL DEFAULT 0,
			ai_category varchar(20) NOT NULL DEFAULT '',
			ai_confidence tinyint(3) unsigned NOT NULL DEFAULT 0,
			ai_reason text NULL,
			ai_checked_at datetime NULL,
			external_ref varchar(80) NOT NULL DEFAULT '',
			created_at datetime NOT NULL DEFAULT '1970-01-01 00:00:00',
			PRIMARY KEY  (id),
			KEY category (category),
			KEY created_at (created_at),
			KEY email (email),
			KEY content_hash (content_hash),
			KEY ip (ip),
			KEY form (source,form_id),
			KEY external_ref (external_ref)
		) {$charset};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
		dbDelta( LeadSift_Usage::schema( $charset ) ); // AI usage log (1.3.0).
		update_option( 'leadsift_db_version', LEADSIFT_DB_VERSION );
	}

	public static function drop() {
		global $wpdb;
		$table = self::table();
		$wpdb->query( "DROP TABLE IF EXISTS {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL
	}

	public static function insert( array $row ) {
		global $wpdb;
		$row = wp_parse_args(
			$row,
			array(
				'source'       => '',
				'form_id'      => '',
				'form_name'    => '',
				'name'         => '',
				'email'        => '',
				'phone'        => '',
				'subject'      => '',
				'message'      => '',
				'form_data'    => array(),
				'ip'           => '',
				'user_agent'   => '',
				'page_url'     => '',
				'content_hash' => '',
				'score'        => 0,
				'category'     => 'genuine',
				'reasons'      => array(),
				'is_manual'    => 0,
				'is_read'      => 0,
				'external_ref' => '',
				'created_at'   => current_time( 'mysql' ),
			)
		);

		$row['form_data'] = wp_json_encode( $row['form_data'] );
		$row['reasons']   = wp_json_encode( $row['reasons'] );

		foreach ( array( 'source' => 40, 'form_id' => 64, 'form_name' => 190, 'name' => 190, 'email' => 190, 'phone' => 64, 'subject' => 255, 'ip' => 64, 'user_agent' => 255, 'page_url' => 255 ) as $col => $len ) {
			$row[ $col ] = mb_substr( (string) $row[ $col ], 0, $len );
		}

		$ok = $wpdb->insert( self::table(), $row );
		return $ok ? (int) $wpdb->insert_id : 0;
	}

	public static function get( $id ) {
		global $wpdb;
		$table = self::table();
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ), ARRAY_A ); // phpcs:ignore
		return $row ? self::format( $row ) : null;
	}

	public static function update( $id, array $data ) {
		global $wpdb;
		$allowed = array_intersect_key( $data, array_flip( array( 'category', 'score', 'reasons', 'is_manual', 'is_read', 'ai_category', 'ai_confidence', 'ai_reason', 'ai_checked_at' ) ) );
		if ( isset( $allowed['reasons'] ) && is_array( $allowed['reasons'] ) ) {
			$allowed['reasons'] = wp_json_encode( $allowed['reasons'] );
		}
		if ( empty( $allowed ) ) {
			return false;
		}
		return false !== $wpdb->update( self::table(), $allowed, array( 'id' => (int) $id ) );
	}

	public static function delete( $id ) {
		global $wpdb;
		return (bool) $wpdb->delete( self::table(), array( 'id' => (int) $id ) );
	}

	public static function delete_older_than( $days, array $categories ) {
		global $wpdb;
		$table        = self::table();
		$cutoff       = gmdate( 'Y-m-d H:i:s', current_time( 'timestamp' ) - $days * DAY_IN_SECONDS ); // phpcs:ignore
		$placeholders = implode( ',', array_fill( 0, count( $categories ), '%s' ) );
		$params       = array_merge( array( $cutoff ), $categories );
		// phpcs:ignore WordPress.DB.PreparedSQL
		return $wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE created_at < %s AND is_manual = 0 AND category IN ({$placeholders})", $params ) );
	}

	/**
	 * Lists inquiries with filters and pagination.
	 *
	 * @param array $args Filters.
	 * @return array{items: array, total: int}
	 */
	public static function query( array $args ) {
		global $wpdb;
		$table = self::table();
		$args  = wp_parse_args(
			$args,
			array(
				'category' => '',
				'source'   => '',
				'form'     => '',
				'search'   => '',
				'unread'   => false,
				'review'   => false,
				'page'     => 1,
				'per_page' => 20,
				'orderby'  => 'created_at',
				'order'    => 'DESC',
			)
		);

		$where  = array( '1=1' );
		$params = array();

		if ( in_array( $args['category'], LeadSift_Classifier::CATEGORIES, true ) ) {
			$where[]  = 'category = %s';
			$params[] = $args['category'];
		}
		if ( '' !== $args['source'] ) {
			$where[]  = 'source = %s';
			$params[] = $args['source'];
		}
		if ( '' !== $args['form'] && false !== strpos( $args['form'], ':' ) ) {
			list( $form_source, $form_id ) = explode( ':', $args['form'], 2 );
			$where[]  = 'source = %s AND form_id = %s';
			$params[] = $form_source;
			$params[] = $form_id;
		}
		if ( $args['unread'] ) {
			$where[] = 'is_read = 0';
		}
		if ( $args['review'] ) {
			list( $sql, $review_params ) = self::review_sql();
			$where[] = $sql;
			$params  = array_merge( $params, $review_params );
		}
		if ( '' !== trim( $args['search'] ) ) {
			$like     = '%' . $wpdb->esc_like( trim( $args['search'] ) ) . '%';
			$where[]  = '(name LIKE %s OR email LIKE %s OR phone LIKE %s OR subject LIKE %s OR message LIKE %s OR form_name LIKE %s)';
			$params   = array_merge( $params, array_fill( 0, 6, $like ) );
		}

		$orderby  = in_array( $args['orderby'], array( 'created_at', 'score', 'name', 'id' ), true ) ? $args['orderby'] : 'created_at';
		$order    = 'ASC' === strtoupper( $args['order'] ) ? 'ASC' : 'DESC';
		$per_page = max( 1, min( 200, (int) $args['per_page'] ) );
		$offset   = ( max( 1, (int) $args['page'] ) - 1 ) * $per_page;
		$sql_where = implode( ' AND ', $where );

		$count_sql = "SELECT COUNT(*) FROM {$table} WHERE {$sql_where}";
		$list_sql  = "SELECT * FROM {$table} WHERE {$sql_where} ORDER BY {$orderby} {$order}, id DESC LIMIT %d OFFSET %d";

		// phpcs:disable WordPress.DB.PreparedSQL
		$total = (int) ( $params ? $wpdb->get_var( $wpdb->prepare( $count_sql, $params ) ) : $wpdb->get_var( $count_sql ) );
		$rows  = $wpdb->get_results( $wpdb->prepare( $list_sql, array_merge( $params, array( $per_page, $offset ) ) ), ARRAY_A );
		// phpcs:enable

		return array(
			'items' => array_map( array( __CLASS__, 'format' ), (array) $rows ),
			'total' => $total,
		);
	}

	/**
	 * "Needs a look": not corrected by you yet, and either the AI disagrees with
	 * the verdict or the score is close to the line.
	 *
	 * @return array{0: string, 1: array}
	 */
	public static function review_sql() {
		$t = LeadSift_Classifier::THRESHOLDS[ LeadSift_Settings::get()['sensitivity'] ] ?? 40;
		return array(
			"(is_manual = 0 AND reasons NOT LIKE %s AND ( (ai_category <> '' AND ai_category <> category) OR (score BETWEEN %d AND %d) ))",
			array( '%"type":"rule"%', max( 1, $t - 12 ), $t + 12 ),
		);
	}

	/**
	 * Tiny "has anything changed?" check for the live dashboard (one cheap query).
	 *
	 * @return array{latest_id: int, total: int, signature: string}
	 */
	public static function pulse() {
		global $wpdb;
		$table = self::table();
		// phpcs:ignore WordPress.DB.PreparedSQL
		$row = (array) $wpdb->get_row( "SELECT COUNT(*) AS total, MAX(id) AS latest_id, MAX(ai_checked_at) AS ai_at, SUM(is_read = 0) AS unread, SUM(category = 'genuine') AS g, SUM(category = 'promotional') AS p, SUM(category = 'spam') AS s, SUM(is_manual) AS m FROM {$table}", ARRAY_A );
		return array(
			'latest_id' => (int) ( $row['latest_id'] ?? 0 ),
			'total'     => (int) ( $row['total'] ?? 0 ),
			'signature' => md5( implode( '|', array_map( 'strval', $row ) ) ),
		);
	}

	public static function count_review() {
		global $wpdb;
		$table              = self::table();
		list( $sql, $args ) = self::review_sql();
		// phpcs:ignore WordPress.DB.PreparedSQL
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE {$sql}", $args ) );
	}

	/**
	 * Inquiry counts per form.
	 *
	 * @return array<string, array> Keyed by "source:form_id".
	 */
	public static function form_counts() {
		global $wpdb;
		$table = self::table();
		// phpcs:ignore WordPress.DB.PreparedSQL
		$rows = $wpdb->get_results( "SELECT source, form_id, MAX(form_name) AS form_name, category, COUNT(*) AS total, SUM(is_read = 0) AS unread, MAX(created_at) AS last_at FROM {$table} GROUP BY source, form_id, category", ARRAY_A );
		$out  = array();
		foreach ( (array) $rows as $r ) {
			$key = $r['source'] . ':' . $r['form_id'];
			if ( ! isset( $out[ $key ] ) ) {
				$out[ $key ] = array(
					'source'      => $r['source'],
					'form_id'     => (string) $r['form_id'],
					'name'        => $r['form_name'],
					'total'       => 0,
					'genuine'     => 0,
					'promotional' => 0,
					'spam'        => 0,
					'unread'      => 0,
					'last_at'     => '',
				);
			}
			$out[ $key ]['total']  += (int) $r['total'];
			$out[ $key ]['unread'] += (int) $r['unread'];
			if ( isset( $out[ $key ][ $r['category'] ] ) ) {
				$out[ $key ][ $r['category'] ] = (int) $r['total'];
			}
			if ( $r['last_at'] > $out[ $key ]['last_at'] ) {
				$out[ $key ]['last_at'] = $r['last_at'];
			}
		}
		return $out;
	}

	/** Was this entry from another plugin already imported? */
	public static function has_ref( $ref ) {
		global $wpdb;
		$table = self::table();
		// phpcs:ignore WordPress.DB.PreparedSQL
		return (bool) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE external_ref = %s LIMIT 1", $ref ) );
	}

	/**
	 * Same person + same message within 3 minutes = the same submission
	 * (e.g. caught live by LeadSift and also stored by the form plugin).
	 */
	public static function has_twin( $email, $message, $created_at ) {
		global $wpdb;
		$table = self::table();
		$time  = strtotime( $created_at );
		if ( ! $time ) {
			return false;
		}
		// ±1 day: form plugins store times in UTC or local time.
		$from = gmdate( 'Y-m-d H:i:s', $time - DAY_IN_SECONDS );
		$to   = gmdate( 'Y-m-d H:i:s', $time + DAY_IN_SECONDS );
		// phpcs:ignore WordPress.DB.PreparedSQL
		return (bool) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE email = %s AND message = %s AND created_at BETWEEN %s AND %s LIMIT 1", $email, $message, $from, $to ) );
	}

	public static function count_duplicates( $hash, $email, $days = 30 ) {
		global $wpdb;
		if ( '' === $hash ) {
			return 0;
		}
		$table  = self::table();
		$cutoff = gmdate( 'Y-m-d H:i:s', current_time( 'timestamp' ) - $days * DAY_IN_SECONDS ); // phpcs:ignore
		// phpcs:ignore WordPress.DB.PreparedSQL
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE content_hash = %s AND email <> %s AND created_at >= %s", $hash, $email, $cutoff ) );
	}

	public static function count_recent_from_ip( $ip, $minutes = 60 ) {
		global $wpdb;
		if ( '' === $ip ) {
			return 0;
		}
		$table  = self::table();
		$cutoff = gmdate( 'Y-m-d H:i:s', current_time( 'timestamp' ) - $minutes * MINUTE_IN_SECONDS ); // phpcs:ignore
		// phpcs:ignore WordPress.DB.PreparedSQL
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE ip = %s AND created_at >= %s", $ip, $cutoff ) );
	}

	/**
	 * Numbers for the dashboard.
	 *
	 * @param int $days Days of history for the chart.
	 */
	public static function stats( $days = 30 ) {
		global $wpdb;
		$table  = self::table();
		$days   = max( 7, min( 365, (int) $days ) );
		$now    = current_time( 'timestamp' ); // phpcs:ignore
		$cutoff = gmdate( 'Y-m-d 00:00:00', $now - ( $days - 1 ) * DAY_IN_SECONDS );

		$totals = array();
		foreach ( LeadSift_Classifier::CATEGORIES as $cat ) {
			$totals[ $cat ] = array( 'total' => 0, 'unread' => 0 );
		}
		// phpcs:disable WordPress.DB.PreparedSQL
		$rows = $wpdb->get_results( "SELECT category, COUNT(*) AS total, SUM(is_read = 0) AS unread FROM {$table} GROUP BY category", ARRAY_A );
		foreach ( (array) $rows as $r ) {
			if ( isset( $totals[ $r['category'] ] ) ) {
				$totals[ $r['category'] ] = array( 'total' => (int) $r['total'], 'unread' => (int) $r['unread'] );
			}
		}

		$daily_rows = $wpdb->get_results( $wpdb->prepare( "SELECT DATE(created_at) AS day, category, COUNT(*) AS total FROM {$table} WHERE created_at >= %s GROUP BY day, category", $cutoff ), ARRAY_A );
		$daily      = array();
		for ( $i = $days - 1; $i >= 0; $i-- ) {
			$day           = gmdate( 'Y-m-d', $now - $i * DAY_IN_SECONDS );
			$daily[ $day ] = array( 'day' => $day, 'genuine' => 0, 'promotional' => 0, 'spam' => 0 );
		}
		foreach ( (array) $daily_rows as $r ) {
			if ( isset( $daily[ $r['day'] ][ $r['category'] ] ) ) {
				$daily[ $r['day'] ][ $r['category'] ] = (int) $r['total'];
			}
		}

		$source_rows = $wpdb->get_results( "SELECT source, category, COUNT(*) AS total FROM {$table} GROUP BY source, category", ARRAY_A );
		$sources     = array();
		foreach ( (array) $source_rows as $r ) {
			if ( ! isset( $sources[ $r['source'] ] ) ) {
				$sources[ $r['source'] ] = array( 'source' => $r['source'], 'genuine' => 0, 'promotional' => 0, 'spam' => 0 );
			}
			if ( isset( $sources[ $r['source'] ][ $r['category'] ] ) ) {
				$sources[ $r['source'] ][ $r['category'] ] = (int) $r['total'];
			}
		}

		$reason_rows = $wpdb->get_col( "SELECT reasons FROM {$table} WHERE category IN ('promotional','spam') ORDER BY id DESC LIMIT 500" );
		// phpcs:enable
		$reasons = array();
		foreach ( (array) $reason_rows as $json ) {
			foreach ( (array) json_decode( (string) $json, true ) as $reason ) {
				if ( isset( $reason['label'], $reason['weight'] ) && $reason['weight'] > 0 ) {
					$reasons[ $reason['label'] ] = ( $reasons[ $reason['label'] ] ?? 0 ) + 1;
				}
			}
		}
		arsort( $reasons );
		$top = array();
		foreach ( array_slice( $reasons, 0, 8, true ) as $label => $count ) {
			$top[] = array( 'label' => $label, 'count' => $count );
		}

		return array(
			'totals'      => $totals,
			'daily'       => array_values( $daily ),
			'sources'     => array_values( $sources ),
			'top_reasons' => $top,
		);
	}

	public static function format( array $row ) {
		$row['id']        = (int) $row['id'];
		$row['score']     = (int) $row['score'];
		$row['is_manual'] = (bool) $row['is_manual'];
		$row['is_read']   = (bool) $row['is_read'];
		$row['form_data']     = json_decode( (string) $row['form_data'], true ) ?: array();
		$row['reasons']       = json_decode( (string) $row['reasons'], true ) ?: array();
		$row['ai_category']   = (string) ( $row['ai_category'] ?? '' );
		$row['ai_confidence'] = (int) ( $row['ai_confidence'] ?? 0 );
		$row['ai_reason']     = (string) ( $row['ai_reason'] ?? '' );

		$by_rule = false;
		foreach ( $row['reasons'] as $r ) {
			if ( 'rule' === ( $r['type'] ?? '' ) ) {
				$by_rule = true;
			}
		}
		$t                   = LeadSift_Classifier::THRESHOLDS[ LeadSift_Settings::get()['sensitivity'] ] ?? 40;
		$row['needs_review'] = ! $row['is_manual'] && ! $by_rule && (
			( '' !== $row['ai_category'] && $row['ai_category'] !== $row['category'] )
			|| ( $row['score'] >= max( 1, $t - 12 ) && $row['score'] <= $t + 12 )
		);

		unset( $row['content_hash'] );
		return $row;
	}
}
