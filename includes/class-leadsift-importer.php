<?php
/**
 * Imports and filters form entries that were received BEFORE LeadSift was installed.
 *
 * Reads the entries each form plugin already stores:
 *   WPForms (Pro entries table), Gravity Forms, Fluent Forms, Ninja Forms, Formidable,
 *   Elementor Pro submissions, Flamingo (Contact Form 7) and "Contact Form Entries"
 *   (CRM Perks, which also stores WPForms Lite / CF7 / other submissions).
 *
 * Runs in small batches – automatically in the background after activation
 * (WP-Cron) and faster while the dashboard is open. Every entry is sorted
 * with the same engine as new inquiries; no emails or AI calls are made.
 * Each entry gets an external reference, so nothing is imported twice.
 *
 * @package LeadSift
 */

defined( 'ABSPATH' ) || exit;

class LeadSift_Importer {

	const STATE_OPTION = 'leadsift_import_state';
	const CRON_HOOK    = 'leadsift_import_run';
	const BATCH        = 40;

	/** Readers in the order they run. */
	const READERS = array( 'wpforms', 'gravityforms', 'fluentforms', 'ninjaforms', 'formidable', 'elementor', 'flamingo', 'cfentries' );

	public static function init() {
		add_action( self::CRON_HOOK, array( __CLASS__, 'run_cron' ) );
	}

	public static function labels() {
		return array(
			'wpforms'      => 'WPForms entries',
			'gravityforms' => 'Gravity Forms entries',
			'fluentforms'  => 'Fluent Forms entries',
			'ninjaforms'   => 'Ninja Forms submissions',
			'formidable'   => 'Formidable entries',
			'elementor'    => 'Elementor form submissions',
			'flamingo'     => 'Contact Form 7 messages (Flamingo)',
			'cfentries'    => 'Contact Form Entries plugin',
		);
	}

	/* ------------------------------ State ------------------------------ */

	public static function state() {
		$s = get_option( self::STATE_OPTION, array() );
		return wp_parse_args(
			is_array( $s ) ? $s : array(),
			array(
				'status'   => 'idle', // idle | running | done.
				'cursor'   => array(), // reader => last id / offset.
				'found'    => array(), // reader => total entries.
				'imported' => 0,
				'skipped'  => 0,
				'started'  => '',
				'finished' => '',
			)
		);
	}

	private static function save( array $s ) {
		update_option( self::STATE_OPTION, $s, false );
	}

	/**
	 * Counts old entries and starts the background import.
	 */
	public static function start( $restart = false ) {
		$s = self::state();
		if ( $restart || 'running' !== $s['status'] ) {
			$s = array(
				'status'   => 'running',
				'cursor'   => $restart ? array() : (array) $s['cursor'],
				'found'    => self::count_all(),
				'imported' => $restart ? 0 : (int) $s['imported'],
				'skipped'  => $restart ? 0 : (int) $s['skipped'],
				'started'  => current_time( 'mysql' ),
				'finished' => '',
			);
			if ( 0 === array_sum( $s['found'] ) ) {
				$s['status']   = 'done';
				$s['finished'] = current_time( 'mysql' );
			}
			self::save( $s );
		}
		if ( 'running' === $s['status'] ) {
			self::schedule();
		}
		return self::public_state();
	}

	private static function schedule() {
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_single_event( time() + 5, self::CRON_HOOK );
		}
	}

	public static function run_cron() {
		$end = microtime( true ) + 20;
		do {
			$st = self::step();
		} while ( 'running' === $st['status'] && microtime( true ) < $end );
		if ( 'running' === $st['status'] ) {
			wp_schedule_single_event( time() + 10, self::CRON_HOOK );
		}
	}

	/**
	 * Imports one batch from the first reader that still has entries.
	 */
	public static function step() {
		$s = self::state();
		if ( 'running' !== $s['status'] ) {
			return self::public_state();
		}
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 60 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		}

		foreach ( self::READERS as $reader ) {
			if ( empty( $s['found'][ $reader ] ) || ! empty( $s['cursor'][ $reader . '_done' ] ) ) {
				continue;
			}
			$after = $s['cursor'][ $reader ] ?? 0;
			try {
				$batch = call_user_func( array( __CLASS__, 'read_' . $reader ), $after, self::BATCH );
			} catch ( \Throwable $e ) {
				$batch = array( 'entries' => array(), 'next' => null );
			}
			foreach ( $batch['entries'] as $entry ) {
				$row = LeadSift_Capture::capture(
					$entry['fields'],
					$entry['source'],
					array(
						'import'       => true,
						'external_ref' => $entry['ref'],
						'form_id'      => $entry['form_id'],
						'form_name'    => $entry['form_name'],
						'created_at'   => $entry['created_at'],
						'ip'           => $entry['ip'] ?? '',
						'page_url'     => $entry['page_url'] ?? '',
					)
				);
				if ( $row ) {
					$s['imported']++;
				} else {
					$s['skipped']++;
				}
			}
			if ( null === $batch['next'] || empty( $batch['entries'] ) ) {
				$s['cursor'][ $reader . '_done' ] = true;
			} else {
				$s['cursor'][ $reader ] = $batch['next'];
			}
			self::save( $s );
			return self::public_state();
		}

		$s['status']   = 'done';
		$s['finished'] = current_time( 'mysql' );
		self::save( $s );
		return self::public_state();
	}

	public static function public_state() {
		$s      = self::state();
		$labels = self::labels();
		$found  = array();
		foreach ( (array) $s['found'] as $reader => $count ) {
			if ( $count > 0 ) {
				$found[] = array(
					'reader' => $reader,
					'label'  => $labels[ $reader ] ?? $reader,
					'count'  => (int) $count,
				);
			}
		}
		return array(
			'status'   => $s['status'],
			'total'    => array_sum( (array) $s['found'] ),
			'imported' => (int) $s['imported'],
			'skipped'  => (int) $s['skipped'],
			'found'    => $found,
			'finished' => $s['finished'],
		);
	}

	/* ------------------------------ Counting ------------------------------ */

	public static function count_all() {
		$out = array();
		foreach ( self::READERS as $reader ) {
			try {
				$out[ $reader ] = (int) call_user_func( array( __CLASS__, 'count_' . $reader ) );
			} catch ( \Throwable $e ) {
				$out[ $reader ] = 0;
			}
		}
		return $out;
	}

	private static function table_exists( $table ) {
		global $wpdb;
		return $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table;
	}

	private static function count_table( $table, $where = '1=1' ) {
		global $wpdb;
		if ( ! self::table_exists( $table ) ) {
			return 0;
		}
		// phpcs:ignore WordPress.DB.PreparedSQL
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE {$where}" );
	}

	private static function count_wpforms() {
		global $wpdb;
		return self::count_table( $wpdb->prefix . 'wpforms_entries' );
	}

	private static function count_gravityforms() {
		global $wpdb;
		return self::count_table( $wpdb->prefix . 'gf_entry', "status = 'active'" );
	}

	private static function count_fluentforms() {
		global $wpdb;
		return self::count_table( $wpdb->prefix . 'fluentform_submissions', "status <> 'trashed'" );
	}

	private static function count_ninjaforms() {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'nf_sub' AND post_status = 'publish'" );
	}

	private static function count_formidable() {
		global $wpdb;
		return self::count_table( $wpdb->prefix . 'frm_items', 'is_draft = 0' );
	}

	private static function count_elementor() {
		global $wpdb;
		return self::count_table( $wpdb->prefix . 'e_submissions', "status <> 'trash'" );
	}

	private static function count_flamingo() {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'flamingo_inbound' AND post_status = 'publish'" );
	}

	private static function count_cfentries() {
		global $wpdb;
		return self::count_table( $wpdb->prefix . 'vxcf_leads' );
	}

	/* ------------------------------ Readers ------------------------------ */
	/* Each returns array( 'entries' => array( entry… ), 'next' => cursor|null ). */

	private static function entry( $ref, $source, $form_id, $form_name, array $fields, $created_at, $ip = '', $page_url = '' ) {
		return array(
			'ref'        => $ref,
			'source'     => $source,
			'form_id'    => (string) $form_id,
			'form_name'  => (string) $form_name,
			'fields'     => $fields,
			'created_at' => $created_at ? $created_at : current_time( 'mysql' ),
			'ip'         => filter_var( $ip, FILTER_VALIDATE_IP ) ? $ip : '',
			'page_url'   => (string) $page_url,
		);
	}

	private static function done_if_short( array $entries, $next, $limit ) {
		return array(
			'entries' => $entries,
			'next'    => count( $entries ) < $limit ? null : $next,
		);
	}

	private static function read_wpforms( $after, $limit ) {
		global $wpdb;
		$table = $wpdb->prefix . 'wpforms_entries';
		// phpcs:ignore WordPress.DB.PreparedSQL
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT entry_id, form_id, fields, date, ip_address FROM {$table} WHERE entry_id > %d ORDER BY entry_id ASC LIMIT %d", (int) $after, $limit ), ARRAY_A );
		$out  = array();
		$last = $after;
		foreach ( (array) $rows as $r ) {
			$last   = (int) $r['entry_id'];
			$fields = array();
			foreach ( (array) json_decode( (string) $r['fields'], true ) as $f ) {
				$label            = ! empty( $f['name'] ) ? $f['name'] : 'Field ' . ( $f['id'] ?? '' );
				$fields[ $label ] = $f['value'] ?? '';
			}
			$out[] = self::entry( 'wpforms:' . $r['entry_id'], 'wpforms', $r['form_id'], get_the_title( (int) $r['form_id'] ), $fields, $r['date'], $r['ip_address'] );
		}
		return self::done_if_short( $out, $last, $limit );
	}

	private static function read_gravityforms( $offset, $limit ) {
		if ( ! class_exists( 'GFAPI' ) ) {
			return array( 'entries' => array(), 'next' => null );
		}
		$entries = GFAPI::get_entries( 0, array( 'status' => 'active' ), array( 'key' => 'id', 'direction' => 'ASC' ), array( 'offset' => (int) $offset, 'page_size' => $limit ) );
		$forms   = array();
		$out     = array();
		foreach ( (array) $entries as $e ) {
			$fid = (int) $e['form_id'];
			if ( ! isset( $forms[ $fid ] ) ) {
				$forms[ $fid ] = GFAPI::get_form( $fid );
			}
			$fields = array();
			foreach ( (array) ( $forms[ $fid ]['fields'] ?? array() ) as $field ) {
				if ( ! is_object( $field ) || in_array( $field->type, array( 'html', 'section', 'page', 'captcha' ), true ) ) {
					continue;
				}
				$fields[ $field->label ? $field->label : 'Field ' . $field->id ] = method_exists( $field, 'get_value_export' ) ? $field->get_value_export( $e ) : ( $e[ (string) $field->id ] ?? '' );
			}
			$out[] = self::entry( 'gravityforms:' . $e['id'], 'gravityforms', $fid, $forms[ $fid ]['title'] ?? '', $fields, $e['date_created'] ? get_date_from_gmt( $e['date_created'] ) : '', $e['ip'] ?? '', $e['source_url'] ?? '' );
		}
		return self::done_if_short( $out, (int) $offset + count( $out ), $limit );
	}

	private static function read_fluentforms( $after, $limit ) {
		global $wpdb;
		$table = $wpdb->prefix . 'fluentform_submissions';
		$forms = $wpdb->prefix . 'fluentform_forms';
		// phpcs:ignore WordPress.DB.PreparedSQL
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT s.id, s.form_id, s.response, s.created_at, s.ip, s.source_url, f.title FROM {$table} s LEFT JOIN {$forms} f ON f.id = s.form_id WHERE s.id > %d AND s.status <> 'trashed' ORDER BY s.id ASC LIMIT %d", (int) $after, $limit ), ARRAY_A );
		$out  = array();
		$last = $after;
		foreach ( (array) $rows as $r ) {
			$last  = (int) $r['id'];
			$out[] = self::entry( 'fluentforms:' . $r['id'], 'fluentforms', $r['form_id'], $r['title'], (array) json_decode( (string) $r['response'], true ), $r['created_at'], $r['ip'], $r['source_url'] );
		}
		return self::done_if_short( $out, $last, $limit );
	}

	private static function read_ninjaforms( $after, $limit ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$ids    = $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'nf_sub' AND post_status = 'publish' AND ID > %d ORDER BY ID ASC LIMIT %d", (int) $after, $limit ) );
		$labels = array();
		$out    = array();
		$last   = $after;
		foreach ( (array) $ids as $id ) {
			$last    = (int) $id;
			$form_id = (int) get_post_meta( $id, '_form_id', true );
			if ( ! isset( $labels[ $form_id ] ) ) {
				$labels[ $form_id ] = array( 'fields' => array(), 'title' => '' );
				if ( function_exists( 'Ninja_Forms' ) ) {
					foreach ( (array) Ninja_Forms()->form( $form_id )->get_fields() as $f ) {
						$labels[ $form_id ]['fields'][ $f->get_id() ] = $f->get_setting( 'label' );
					}
					$labels[ $form_id ]['title'] = Ninja_Forms()->form( $form_id )->get()->get_setting( 'title' );
				}
			}
			$fields = array();
			foreach ( get_post_meta( $id ) as $key => $vals ) {
				if ( preg_match( '/^_field_(\d+)$/', $key, $m ) ) {
					$value = maybe_unserialize( $vals[0] );
					$fields[ $labels[ $form_id ]['fields'][ (int) $m[1] ] ?? 'Field ' . $m[1] ] = is_array( $value ) ? implode( ', ', $value ) : $value;
				}
			}
			$out[] = self::entry( 'ninjaforms:' . $id, 'ninjaforms', $form_id, $labels[ $form_id ]['title'], $fields, get_post_field( 'post_date', $id ) );
		}
		return self::done_if_short( $out, $last, $limit );
	}

	private static function read_formidable( $after, $limit ) {
		global $wpdb;
		$items  = $wpdb->prefix . 'frm_items';
		$metas  = $wpdb->prefix . 'frm_item_metas';
		$fields = $wpdb->prefix . 'frm_fields';
		$forms  = $wpdb->prefix . 'frm_forms';
		// phpcs:ignore WordPress.DB.PreparedSQL
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT i.id, i.form_id, i.created_at, i.ip, f.name AS form_name FROM {$items} i LEFT JOIN {$forms} f ON f.id = i.form_id WHERE i.id > %d AND i.is_draft = 0 ORDER BY i.id ASC LIMIT %d", (int) $after, $limit ), ARRAY_A );
		$out  = array();
		$last = $after;
		foreach ( (array) $rows as $r ) {
			$last = (int) $r['id'];
			// phpcs:ignore WordPress.DB.PreparedSQL
			$vals = $wpdb->get_results( $wpdb->prepare( "SELECT m.meta_value, fl.name FROM {$metas} m LEFT JOIN {$fields} fl ON fl.id = m.field_id WHERE m.item_id = %d", $r['id'] ), ARRAY_A );
			$data = array();
			foreach ( (array) $vals as $v ) {
				$value                                       = maybe_unserialize( $v['meta_value'] );
				$data[ $v['name'] ? $v['name'] : 'Field' ] = is_array( $value ) ? implode( ', ', array_filter( $value, 'is_scalar' ) ) : $value;
			}
			$out[] = self::entry( 'formidable:' . $r['id'], 'formidable', $r['form_id'], $r['form_name'], $data, $r['created_at'] ? get_date_from_gmt( $r['created_at'] ) : '', $r['ip'] );
		}
		return self::done_if_short( $out, $last, $limit );
	}

	private static function read_elementor( $after, $limit ) {
		global $wpdb;
		$subs = $wpdb->prefix . 'e_submissions';
		$vals = $wpdb->prefix . 'e_submissions_values';
		// phpcs:ignore WordPress.DB.PreparedSQL
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT id, element_id, form_name, created_at, user_ip, referer FROM {$subs} WHERE id > %d AND status <> 'trash' ORDER BY id ASC LIMIT %d", (int) $after, $limit ), ARRAY_A );
		$out  = array();
		$last = $after;
		foreach ( (array) $rows as $r ) {
			$last = (int) $r['id'];
			// phpcs:ignore WordPress.DB.PreparedSQL
			$pairs = $wpdb->get_results( $wpdb->prepare( "SELECT `key`, `value` FROM {$vals} WHERE submission_id = %d", $r['id'] ), ARRAY_A );
			$data  = array();
			foreach ( (array) $pairs as $p ) {
				$data[ $p['key'] ] = $p['value'];
			}
			$out[] = self::entry( 'elementor:' . $r['id'], 'elementor', $r['element_id'], $r['form_name'], $data, $r['created_at'], $r['user_ip'], $r['referer'] );
		}
		return self::done_if_short( $out, $last, $limit );
	}

	private static function read_flamingo( $after, $limit ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$ids = $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'flamingo_inbound' AND post_status = 'publish' AND ID > %d ORDER BY ID ASC LIMIT %d", (int) $after, $limit ) );
		$out  = array();
		$last = $after;
		foreach ( (array) $ids as $id ) {
			$last   = (int) $id;
			$fields = array();
			foreach ( get_post_meta( $id ) as $key => $v ) {
				if ( 0 === strpos( $key, '_field_' ) ) {
					$value                       = maybe_unserialize( $v[0] );
					$fields[ substr( $key, 7 ) ] = is_array( $value ) ? implode( ', ', $value ) : $value;
				}
			}
			// The channel term is the CF7 form; map it back to the form ID when possible.
			$form_name = '';
			$form_id   = '';
			$terms     = wp_get_object_terms( $id, 'flamingo_inbound_channel' );
			if ( ! is_wp_error( $terms ) && $terms ) {
				$form_name = $terms[0]->name;
				$form      = get_page_by_title( $form_name, OBJECT, 'wpcf7_contact_form' ); // phpcs:ignore WordPress.WP.DeprecatedFunctions
				$form_id   = $form ? $form->ID : $terms[0]->slug;
			}
			$meta  = (array) get_post_meta( $id, '_meta', true );
			$out[] = self::entry( 'flamingo:' . $id, 'cf7', $form_id, $form_name, $fields, get_post_field( 'post_date', $id ), $meta['remote_ip'] ?? '', $meta['url'] ?? '' );
		}
		return self::done_if_short( $out, $last, $limit );
	}

	/**
	 * "Contact Form Entries" by CRM Perks: stores submissions of many form plugins
	 * (including WPForms Lite, which keeps no entries of its own).
	 */
	private static function read_cfentries( $after, $limit ) {
		global $wpdb;
		$leads  = $wpdb->prefix . 'vxcf_leads';
		$detail = $wpdb->prefix . 'vxcf_leads_detail';
		// phpcs:ignore WordPress.DB.PreparedSQL
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$leads} WHERE id > %d ORDER BY id ASC LIMIT %d", (int) $after, $limit ), ARRAY_A );
		$out  = array();
		$last = $after;
		$map  = array( 'cf' => 'cf7', 'wp' => 'wpforms', 'gf' => 'gravityforms', 'fm' => 'formidable', 'frm' => 'formidable', 'nf' => 'ninjaforms', 'el' => 'elementor', 'ff' => 'fluentforms' );
		foreach ( (array) $rows as $r ) {
			$last = (int) $r['id'];
			// phpcs:ignore WordPress.DB.PreparedSQL
			$pairs = $wpdb->get_results( $wpdb->prepare( "SELECT name, value FROM {$detail} WHERE lead_id = %d", $r['id'] ), ARRAY_A );

			$raw_form = (string) ( $r['form_id'] ?? '' );
			$source   = 'cfentries';
			$form_id  = $raw_form;
			if ( preg_match( '/^([a-z]+)_(\d+)$/i', $raw_form, $m ) && isset( $map[ strtolower( $m[1] ) ] ) ) {
				$source  = $map[ strtolower( $m[1] ) ];
				$form_id = $m[2];
			}
			$labels = self::field_labels( $source, $form_id );
			$data   = array();
			foreach ( (array) $pairs as $p ) {
				$key          = (string) $p['name'];
				$label        = $labels[ $key ] ?? $key;
				$data[ $label ] = $p['value'];
			}
			$form_name = '';
			if ( 'wpforms' === $source || 'cf7' === $source ) {
				$form_name = get_the_title( (int) $form_id );
			}
			$created = $r['created'] ?? ( $r['created_at'] ?? '' );
			$out[]   = self::entry( 'cfentries:' . $r['id'], $source, $form_id, $form_name, $data, $created, $r['ip'] ?? '', $r['url'] ?? '' );
		}
		return self::done_if_short( $out, $last, $limit );
	}

	/** Field id → label, so stored "1", "2"… become "Name", "Email"… */
	private static function field_labels( $source, $form_id ) {
		static $cache = array();
		$key = $source . ':' . $form_id;
		if ( isset( $cache[ $key ] ) ) {
			return $cache[ $key ];
		}
		$labels = array();
		if ( 'wpforms' === $source ) {
			$post = get_post( (int) $form_id );
			if ( $post ) {
				$data = function_exists( 'wpforms_decode' ) ? wpforms_decode( $post->post_content ) : json_decode( $post->post_content, true );
				foreach ( (array) ( $data['fields'] ?? array() ) as $id => $f ) {
					if ( ! empty( $f['label'] ) ) {
						$labels[ (string) $id ] = $f['label'];
					}
				}
			}
		}
		$cache[ $key ] = $labels;
		return $labels;
	}
}
