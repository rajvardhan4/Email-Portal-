<?php
/**
 * REST API used by the React dashboard (and the public endpoint for custom forms).
 *
 * Namespace: /wp-json/leadsift/v1/
 *
 * @package LeadSift
 */

defined( 'ABSPATH' ) || exit;

class LeadSift_REST {

	const NS = 'leadsift/v1';

	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
	}

	public static function can_manage() {
		return current_user_can( 'manage_options' );
	}

	/** Admins, or a signed-in Client Portal login. */
	public static function can_view( WP_REST_Request $req ) {
		return current_user_can( 'manage_options' ) || (bool) LeadSift_Portal::user_from_request( $req );
	}

	/** Setup in wp-admin (rules, AI, logins) is for WordPress admins only – never Client Portal logins. */
	public static function can_edit( WP_REST_Request $req ) {
		return current_user_can( 'manage_options' );
	}

	/** Changing things on the dashboard (form setup, settings, re-check, import): admins, or a login with "Manage" access. */
	public static function can_manage_dashboard( WP_REST_Request $req ) {
		if ( current_user_can( 'manage_options' ) ) {
			return true;
		}
		$user = LeadSift_Portal::user_from_request( $req );
		return $user && LeadSift_Portal::can_manage( $user );
	}

	/** Only a real Client Portal sign-in (the dashboard page never lets anyone in without a Login ID & password). */
	public static function has_portal_login( WP_REST_Request $req ) {
		return (bool) LeadSift_Portal::user_from_request( $req );
	}

	public static function routes() {
		$admin = array( __CLASS__, 'can_manage' ); // WordPress administrators only.
		$view  = array( __CLASS__, 'can_view' );   // Admins + every Client Portal login.
		$edit  = array( __CLASS__, 'can_edit' );   // WordPress admins only (setup).
		$manage = array( __CLASS__, 'can_manage_dashboard' ); // Admins + portal logins with "Manage" access.

		// ---- Client Portal sign-in (public) and logins (admins only) ----
		register_rest_route(
			self::NS,
			'/portal/login',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'portal_login' ),
				'permission_callback' => '__return_true',
			)
		);
		register_rest_route(
			self::NS,
			'/portal/me',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'portal_me' ),
				'permission_callback' => array( __CLASS__, 'has_portal_login' ),
			)
		);
		register_rest_route(
			self::NS,
			'/portal/logout',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'portal_logout' ),
				'permission_callback' => '__return_true',
			)
		);
		register_rest_route(
			self::NS,
			'/portal/users',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( __CLASS__, 'portal_users' ),
					'permission_callback' => $admin,
				),
				array(
					'methods'             => 'POST',
					'callback'            => array( __CLASS__, 'portal_user_create' ),
					'permission_callback' => $admin,
				),
			)
		);
		register_rest_route(
			self::NS,
			'/portal/users/(?P<id>[a-z0-9]+)',
			array(
				array(
					'methods'             => 'POST',
					'callback'            => array( __CLASS__, 'portal_user_update' ),
					'permission_callback' => $admin,
				),
				array(
					'methods'             => 'DELETE',
					'callback'            => array( __CLASS__, 'portal_user_delete' ),
					'permission_callback' => $admin,
				),
			)
		);

		// ---- Import & filter entries received before LeadSift was installed ----
		register_rest_route(
			self::NS,
			'/import',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( __CLASS__, 'import_state' ),
					'permission_callback' => $view,
				),
				array(
					'methods'             => 'POST',
					'callback'            => array( __CLASS__, 'import_start' ),
					'permission_callback' => $manage,
				),
			)
		);
		register_rest_route(
			self::NS,
			'/import/step',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'import_step' ),
				'permission_callback' => $view,
			)
		);

		register_rest_route(
			self::NS,
			'/inquiries',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'list_inquiries' ),
				'permission_callback' => $view,
			)
		);
		register_rest_route(
			self::NS,
			'/inquiries/(?P<id>\d+)',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( __CLASS__, 'get_inquiry' ),
					'permission_callback' => $view,
				),
				array(
					'methods'             => 'PATCH, POST',
					'callback'            => array( __CLASS__, 'update_inquiry' ),
					'permission_callback' => $view,
				),
				array(
					'methods'             => 'DELETE',
					'callback'            => array( __CLASS__, 'delete_inquiry' ),
					'permission_callback' => $view,
				),
			)
		);
		register_rest_route(
			self::NS,
			'/inquiries/(?P<id>\d+)/ai',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'ai_check' ),
				'permission_callback' => $manage,
			)
		);
		register_rest_route(
			self::NS,
			'/pulse',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'pulse' ),
				'permission_callback' => $view,
			)
		);
		register_rest_route(
			self::NS,
			'/ai/usage',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'ai_usage' ),
				'permission_callback' => $view,
			)
		);
		register_rest_route(
			self::NS,
			'/ai/models',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'ai_models' ),
				'permission_callback' => $edit,
			)
		);
		register_rest_route(
			self::NS,
			'/business/analyze',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'business_analyze' ),
				'permission_callback' => $edit,
			)
		);
		register_rest_route(
			self::NS,
			'/forms',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'forms' ),
				'permission_callback' => $view,
			)
		);
		register_rest_route(
			self::NS,
			'/forms/fields',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( __CLASS__, 'form_fields' ),
					'permission_callback' => $view,
				),
				array(
					'methods'             => 'POST',
					'callback'            => array( __CLASS__, 'save_form_map' ),
					'permission_callback' => $manage,
				),
				array(
					'methods'             => 'DELETE',
					'callback'            => array( __CLASS__, 'reset_form_map' ),
					'permission_callback' => $manage,
				),
			)
		);
		register_rest_route(
			self::NS,
			'/inquiries/bulk',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'bulk' ),
				'permission_callback' => $view,
			)
		);
		register_rest_route(
			self::NS,
			'/stats',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'stats' ),
				'permission_callback' => $view,
			)
		);
		register_rest_route(
			self::NS,
			'/rescan',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'rescan' ),
				'permission_callback' => $manage,
			)
		);
		register_rest_route(
			self::NS,
			'/settings',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( __CLASS__, 'get_settings' ),
					'permission_callback' => $view,
				),
				array(
					'methods'             => 'POST',
					'callback'            => array( __CLASS__, 'save_settings' ),
					'permission_callback' => $manage,
				),
			)
		);
		register_rest_route(
			self::NS,
			'/test',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'test' ),
				'permission_callback' => $edit,
			)
		);
		register_rest_route(
			self::NS,
			'/export',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'export' ),
				'permission_callback' => $view,
			)
		);

		// Public endpoint so any custom HTML / headless form can send inquiries.
		register_rest_route(
			self::NS,
			'/submit',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'submit' ),
				'permission_callback' => '__return_true',
			)
		);
	}

	/* -------------------------- Client Portal -------------------------- */

	public static function portal_login( WP_REST_Request $req ) {
		$result = LeadSift_Portal::login( (string) $req->get_param( 'username' ), (string) $req->get_param( 'password' ) );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		$response = rest_ensure_response( $result );
		$response->header( 'Cache-Control', 'no-store' );
		return $response;
	}

	/** Who is signed in to the dashboard page (always a Login ID & password – also for admins). */
	public static function portal_me( WP_REST_Request $req ) {
		$user           = LeadSift_Portal::user_from_request( $req );
		$user['manage'] = LeadSift_Portal::can_manage( $user );
		$response       = rest_ensure_response( $user );
		$response->header( 'Cache-Control', 'no-store' );
		return $response;
	}

	public static function portal_logout( WP_REST_Request $req ) {
		LeadSift_Portal::logout( $req );
		return rest_ensure_response( array( 'ok' => true ) );
	}

	public static function portal_users() {
		return rest_ensure_response(
			array(
				'users' => LeadSift_Portal::public_users(),
				'url'   => LeadSift_Portal::url(),
				'title' => get_the_title( LeadSift_Portal::page_id() ),
			)
		);
	}

	public static function portal_user_create( WP_REST_Request $req ) {
		$user = LeadSift_Portal::create_user( $req->get_param( 'name' ), $req->get_param( 'username' ), (string) $req->get_param( 'password' ), (string) $req->get_param( 'access' ) );
		return is_wp_error( $user ) ? $user : rest_ensure_response( $user );
	}

	public static function portal_user_update( WP_REST_Request $req ) {
		$data = array();
		foreach ( array( 'name', 'access', 'password' ) as $key ) {
			if ( null !== $req->get_param( $key ) ) {
				$data[ $key ] = $req->get_param( $key );
			}
		}
		if ( $req->get_param( 'new_password' ) ) {
			$data['password'] = '';
		}
		$user = LeadSift_Portal::update_user( (string) $req['id'], $data );
		return is_wp_error( $user ) ? $user : rest_ensure_response( $user );
	}

	public static function portal_user_delete( WP_REST_Request $req ) {
		LeadSift_Portal::delete_user( (string) $req['id'] );
		return rest_ensure_response( array( 'deleted' => true ) );
	}

	/* ------------------------------ Import ------------------------------ */

	public static function import_state() {
		return rest_ensure_response( LeadSift_Importer::public_state() );
	}

	public static function import_start( WP_REST_Request $req ) {
		return rest_ensure_response( LeadSift_Importer::start( rest_sanitize_boolean( $req->get_param( 'restart' ) ) ) );
	}

	/** The open dashboard calls this to finish the import faster than WP-Cron alone. */
	public static function import_step() {
		return rest_ensure_response( LeadSift_Importer::step() );
	}

	/* ---------------------------- Inquiries ---------------------------- */

	public static function list_inquiries( WP_REST_Request $req ) {
		$result = LeadSift_DB::query(
			array(
				'category' => (string) $req->get_param( 'category' ),
				'source'   => sanitize_key( (string) $req->get_param( 'source' ) ),
				'form'     => sanitize_text_field( (string) $req->get_param( 'form' ) ),
				'search'   => sanitize_text_field( (string) $req->get_param( 'search' ) ),
				'unread'   => rest_sanitize_boolean( $req->get_param( 'unread' ) ),
				'review'   => rest_sanitize_boolean( $req->get_param( 'review' ) ),
				'page'     => (int) ( $req->get_param( 'page' ) ? $req->get_param( 'page' ) : 1 ),
				'per_page' => (int) ( $req->get_param( 'per_page' ) ? $req->get_param( 'per_page' ) : 20 ),
				'orderby'  => (string) $req->get_param( 'orderby' ),
				'order'    => (string) $req->get_param( 'order' ),
			)
		);
		return rest_ensure_response( $result );
	}

	public static function get_inquiry( WP_REST_Request $req ) {
		$row = LeadSift_DB::get( (int) $req['id'] );
		if ( ! $row ) {
			return new WP_Error( 'leadsift_not_found', 'Inquiry not found.', array( 'status' => 404 ) );
		}
		return rest_ensure_response( $row );
	}

	public static function update_inquiry( WP_REST_Request $req ) {
		$id  = (int) $req['id'];
		$row = LeadSift_DB::get( $id );
		if ( ! $row ) {
			return new WP_Error( 'leadsift_not_found', 'Inquiry not found.', array( 'status' => 404 ) );
		}
		$data = array();
		if ( null !== $req->get_param( 'is_read' ) ) {
			$data['is_read'] = rest_sanitize_boolean( $req->get_param( 'is_read' ) ) ? 1 : 0;
		}
		$category = $req->get_param( 'category' );
		if ( $category ) {
			if ( ! in_array( $category, LeadSift_Classifier::CATEGORIES, true ) ) {
				return new WP_Error( 'leadsift_bad_category', 'Unknown category.', array( 'status' => 400 ) );
			}
			$data = array_merge( $data, self::manual_category_data( $row, $category ) );
			$learn = $req->get_param( 'learn' );
			if ( ( null === $learn || rest_sanitize_boolean( $learn ) ) && LeadSift_Settings::get()['learn_from_actions'] ) {
				LeadSift_Settings::learn( $row['email'], $category );
			}
		}
		LeadSift_DB::update( $id, $data );
		return rest_ensure_response( LeadSift_DB::get( $id ) );
	}

	/**
	 * "Ask AI now" button in the inquiry panel.
	 */
	public static function ai_check( WP_REST_Request $req ) {
		if ( ! LeadSift_AI::enabled( LeadSift_Settings::get() ) ) {
			return new WP_Error( 'leadsift_ai_off', 'Set up the AI check first (Rules & AI tab).', array( 'status' => 400 ) );
		}
		$row = LeadSift_AI::check( (int) $req['id'] );
		if ( is_wp_error( $row ) ) {
			return new WP_Error( $row->get_error_code(), $row->get_error_message(), array( 'status' => 502 ) );
		}
		return rest_ensure_response( $row );
	}

	/**
	 * Live dashboard: polled every few seconds; never cached.
	 */
	public static function pulse() {
		$response = rest_ensure_response( LeadSift_DB::pulse() );
		$response->header( 'Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0' );
		return $response;
	}

	/**
	 * Lists the models an OpenAI-compatible API offers. Uses the typed key, or the saved one.
	 * Body: { preset, base_url, key? }
	 */
	public static function ai_models( WP_REST_Request $req ) {
		$settings = LeadSift_Settings::get();
		$preset   = (string) $req->get_param( 'preset' );
		if ( array_key_exists( $preset, LeadSift_Settings::ai_presets() ) ) {
			$settings['ai_compat_preset'] = $preset;
		}
		$base = untrailingslashit( esc_url_raw( trim( (string) $req->get_param( 'base_url' ) ), array( 'https', 'http' ) ) );
		if ( '' === $base ) {
			return new WP_Error( 'leadsift_bad_url', 'Enter the API address first.', array( 'status' => 400 ) );
		}
		$settings['ai_compat_base_url'] = $base;
		$key                            = trim( sanitize_text_field( (string) $req->get_param( 'key' ) ) );
		if ( '' !== $key ) {
			$settings['ai_compat_key'] = $key;
		}
		$models = LeadSift_AI::compat_models( $settings );
		if ( is_wp_error( $models ) ) {
			return new WP_Error( $models->get_error_code(), $models->get_error_message(), array( 'status' => 502 ) );
		}
		return rest_ensure_response( array( 'models' => $models ) );
	}

	/**
	 * "Read my business" – returns suggestions only; nothing is saved.
	 */
	public static function business_analyze() {
		$result = LeadSift_Business::analyze();
		if ( is_wp_error( $result ) ) {
			return new WP_Error( $result->get_error_code(), $result->get_error_message(), array( 'status' => 502 ) );
		}
		update_option( 'leadsift_business_scanned_at', current_time( 'mysql' ), false );
		return rest_ensure_response( $result );
	}

	public static function forms() {
		return rest_ensure_response( LeadSift_Forms::all() );
	}

	/** Splits "cf7:12" into [ 'cf7', '12' ]. */
	private static function form_key( WP_REST_Request $req ) {
		$key = sanitize_text_field( (string) $req->get_param( 'key' ) );
		if ( false === strpos( $key, ':' ) ) {
			return new WP_Error( 'leadsift_bad_form', 'Unknown form.', array( 'status' => 400 ) );
		}
		list( $source, $id ) = explode( ':', $key, 2 );
		return array( sanitize_key( $source ), $id );
	}

	/**
	 * Fields of one form with what LeadSift thinks each field is.
	 */
	public static function form_fields( WP_REST_Request $req ) {
		$key = self::form_key( $req );
		if ( is_wp_error( $key ) ) {
			return $key;
		}
		$name = sanitize_text_field( (string) $req->get_param( 'name' ) );
		return rest_ensure_response( LeadSift_Form_Reader::read( $key[0], $key[1], $name ) );
	}

	/**
	 * Saves the field map: { key, roles: { fieldKey: role }, type }.
	 */
	public static function save_form_map( WP_REST_Request $req ) {
		$key = self::form_key( $req );
		if ( is_wp_error( $key ) ) {
			return $key;
		}
		$roles = (array) $req->get_param( 'roles' );
		LeadSift_Form_Reader::save_map(
			$key[0] . ':' . $key[1],
			$roles,
			(string) $req->get_param( 'type' ),
			(array) $req->get_param( 'required' ),
			(array) $req->get_param( 'checks' )
		);
		return rest_ensure_response( LeadSift_Form_Reader::read( $key[0], $key[1], sanitize_text_field( (string) $req->get_param( 'name' ) ) ) );
	}

	public static function reset_form_map( WP_REST_Request $req ) {
		$key = self::form_key( $req );
		if ( is_wp_error( $key ) ) {
			return $key;
		}
		LeadSift_Form_Reader::reset_map( $key[0] . ':' . $key[1] );
		return rest_ensure_response( LeadSift_Form_Reader::read( $key[0], $key[1], sanitize_text_field( (string) $req->get_param( 'name' ) ) ) );
	}

	public static function delete_inquiry( WP_REST_Request $req ) {
		return rest_ensure_response( array( 'deleted' => LeadSift_DB::delete( (int) $req['id'] ) ) );
	}

	public static function bulk( WP_REST_Request $req ) {
		$ids    = array_filter( array_map( 'intval', (array) $req->get_param( 'ids' ) ) );
		$action = (string) $req->get_param( 'action' );
		$learn  = LeadSift_Settings::get()['learn_from_actions'];
		$done   = 0;

		foreach ( $ids as $id ) {
			if ( 'delete' === $action ) {
				$done += LeadSift_DB::delete( $id ) ? 1 : 0;
				continue;
			}
			if ( 'read' === $action || 'unread' === $action ) {
				$done += LeadSift_DB::update( $id, array( 'is_read' => 'read' === $action ? 1 : 0 ) ) ? 1 : 0;
				continue;
			}
			if ( 0 === strpos( $action, 'mark_' ) ) {
				$category = substr( $action, 5 );
				$row      = LeadSift_DB::get( $id );
				if ( $row && in_array( $category, LeadSift_Classifier::CATEGORIES, true ) ) {
					LeadSift_DB::update( $id, self::manual_category_data( $row, $category ) );
					if ( $learn ) {
						LeadSift_Settings::learn( $row['email'], $category );
					}
					$done++;
				}
			}
		}
		return rest_ensure_response( array( 'updated' => $done ) );
	}

	private static function manual_category_data( array $row, $category ) {
		$reasons = array_values(
			array_filter(
				(array) $row['reasons'],
				static function ( $r ) {
					return 'manual' !== ( $r['type'] ?? '' );
				}
			)
		);
		if ( $category !== $row['category'] || $row['is_manual'] ) {
			array_unshift(
				$reasons,
				array(
					'label'  => 'Manually marked as ' . ucfirst( $category ),
					'weight' => 0,
					'type'   => 'manual',
				)
			);
		}
		return array(
			'category'  => $category,
			'is_manual' => 1,
			'reasons'   => $reasons,
		);
	}

	/* ----------------------------- Other ----------------------------- */

	public static function stats( WP_REST_Request $req ) {
		$days            = (int) ( $req->get_param( 'days' ) ? $req->get_param( 'days' ) : 30 );
		$stats           = LeadSift_DB::stats( $days );
		$stats['review'] = LeadSift_DB::count_review();
		$stats['ai_on']  = LeadSift_AI::enabled( LeadSift_Settings::get() );
		$stats['import'] = LeadSift_Importer::public_state();
		return rest_ensure_response( $stats );
	}

	/**
	 * Re-runs the classifier on everything you have NOT manually corrected.
	 * Useful after changing sensitivity or keywords. Works in batches.
	 */
	public static function rescan( WP_REST_Request $req ) {
		global $wpdb;
		$table    = LeadSift_DB::table();
		$after    = (int) $req->get_param( 'after' );
		$settings = LeadSift_Settings::get();
		$changed  = 0;

		// phpcs:ignore WordPress.DB.PreparedSQL
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE id > %d AND is_manual = 0 ORDER BY id ASC LIMIT 300", $after ), ARRAY_A );
		$last = $after;
		foreach ( (array) $rows as $raw ) {
			$row    = LeadSift_DB::format( $raw );
			$last   = $row['id'];
			$result = LeadSift_Capture::reclassify_row( $row, $settings );
			if ( $result['category'] !== $row['category'] || $result['score'] !== $row['score'] ) {
				$changed++;
			}
			LeadSift_DB::update( $row['id'], $result );
		}
		return rest_ensure_response(
			array(
				'processed' => count( (array) $rows ),
				'changed'   => $changed,
				'next'      => count( (array) $rows ) === 300 ? $last : null,
			)
		);
	}

	/** wp-admin gets every setting (keys masked); the dashboard page only its own settings (no rules, no AI). */
	public static function get_settings( WP_REST_Request $req ) {
		return rest_ensure_response( self::settings_response( $req ) );
	}

	public static function save_settings( WP_REST_Request $req ) {
		$input = (array) $req->get_json_params();
		if ( ! self::is_wp_admin_request( $req ) ) {
			$input = array_intersect_key( $input, array_flip( array_merge( LeadSift_Settings::DASHBOARD_KEYS, array( 'clear_learned' ) ) ) );
		}
		if ( ! empty( $input['clear_learned'] ) ) {
			update_option( LeadSift_Settings::LEARNED_OPTION, array(), false );
		}
		LeadSift_Settings::update( $input );
		return rest_ensure_response( self::settings_response( $req ) );
	}

	/** True for wp-admin screens (WordPress admin, no portal token). */
	private static function is_wp_admin_request( WP_REST_Request $req ) {
		return current_user_can( 'manage_options' ) && ! LeadSift_Portal::user_from_request( $req );
	}

	private static function settings_response( WP_REST_Request $req ) {
		return array(
			'settings' => self::is_wp_admin_request( $req ) ? LeadSift_Settings::public_view() : LeadSift_Settings::dashboard_view(),
			'learned'  => count( LeadSift_Settings::learned() ),
		);
	}

	/** Dashboard → AI usage: every AI call with tokens, time and estimated cost. */
	public static function ai_usage() {
		$response = rest_ensure_response( LeadSift_Usage::report() );
		$response->header( 'Cache-Control', 'no-store' );
		return $response;
	}

	/**
	 * Try the filter on sample text without saving anything.
	 * Optional: custom_rules (unsaved rules to try), with_ai (also ask the AI).
	 */
	public static function test( WP_REST_Request $req ) {
		$p        = (array) $req->get_json_params();
		$settings = LeadSift_Settings::get();
		if ( isset( $p['custom_rules'] ) ) {
			$settings['custom_rules'] = LeadSift_Settings::sanitize_rules( $p['custom_rules'] );
		}
		$parts  = array(
			'name'      => sanitize_text_field( $p['name'] ?? '' ),
			'email'     => sanitize_text_field( $p['email'] ?? '' ),
			'phone'     => sanitize_text_field( $p['phone'] ?? '' ),
			'subject'   => sanitize_text_field( $p['subject'] ?? '' ),
			'message'   => sanitize_textarea_field( $p['message'] ?? '' ),
			'form_name' => sanitize_text_field( $p['form_name'] ?? '' ),
			'source'    => sanitize_key( $p['source'] ?? '' ),
		);
		$result       = LeadSift_Classifier::classify( $parts, $settings, array() );
		$result['ai'] = null;

		if ( ! empty( $p['with_ai'] ) && LeadSift_AI::enabled( $settings ) ) {
			LeadSift_Usage::context( 'test' );
			$answer = LeadSift_AI::ask( $parts, $settings, array( 'category' => $result['category'], 'score' => $result['score'] ) );
			if ( is_wp_error( $answer ) ) {
				$result['ai'] = array( 'error' => $answer->get_error_message() );
			} else {
				$result['ai'] = $answer;
				$result       = array_merge( $result, LeadSift_AI::combine( $result, $answer['category'], $answer['confidence'], $answer['reason'], LeadSift_AI::label( $settings ) ) );
				$result['ai'] = $answer;
			}
		}
		return rest_ensure_response( $result );
	}

	/**
	 * Returns CSV text; the dashboard turns it into a download.
	 */
	public static function export( WP_REST_Request $req ) {
		$category = (string) $req->get_param( 'category' );
		$filters  = array(
			'category' => $category,
			'search'   => sanitize_text_field( (string) $req->get_param( 'search' ) ),
			'source'   => sanitize_key( (string) $req->get_param( 'source' ) ),
			'form'     => sanitize_text_field( (string) $req->get_param( 'form' ) ),
			'review'   => rest_sanitize_boolean( $req->get_param( 'review' ) ),
			'per_page' => 200,
		);
		$result = LeadSift_DB::query( array_merge( $filters, array( 'page' => 1 ) ) );
		$items  = $result['items'];
		$pages  = (int) ceil( $result['total'] / 200 );
		for ( $p = 2; $p <= $pages; $p++ ) {
			$more  = LeadSift_DB::query( array_merge( $filters, array( 'page' => $p ) ) );
			$items = array_merge( $items, $more['items'] );
		}

		$handle = fopen( 'php://temp', 'r+' );
		fputcsv( $handle, array( 'ID', 'Date', 'Category', 'Score', 'Name', 'Email', 'Phone', 'Subject', 'Message', 'Form', 'Source', 'Page URL', 'Reasons', 'All fields' ), ',', '"', '' );
		foreach ( $items as $row ) {
			$reasons = implode( '; ', array_map( static function ( $r ) { return $r['label']; }, $row['reasons'] ) );
			$fields  = array();
			foreach ( $row['form_data'] as $k => $v ) {
				$fields[] = $k . ': ' . $v;
			}
			fputcsv(
				$handle,
				array_map(
					array( __CLASS__, 'csv_safe' ),
					array( $row['id'], $row['created_at'], $row['category'], $row['score'], $row['name'], $row['email'], $row['phone'], $row['subject'], $row['message'], $row['form_name'], $row['source'], $row['page_url'], $reasons, implode( ' | ', $fields ) )
				),
				',',
				'"',
				''
			);
		}
		rewind( $handle );
		$csv = stream_get_contents( $handle );
		fclose( $handle );

		return rest_ensure_response(
			array(
				'filename' => 'leadsift-' . ( $category ? $category : 'all' ) . '-' . gmdate( 'Y-m-d' ) . '.csv',
				'csv'      => "\xEF\xBB\xBF" . $csv,
				'count'    => count( $items ),
			)
		);
	}

	/** Stops spreadsheet formula injection. */
	public static function csv_safe( $value ) {
		$value = (string) $value;
		return preg_match( '/^[=+\-@\t\r]/', $value ) ? "'" . $value : $value;
	}

	/**
	 * Public: POST /wp-json/leadsift/v1/submit  { "name": "", "email": "", "message": "", ... }
	 */
	public static function submit( WP_REST_Request $req ) {
		if ( empty( LeadSift_Settings::get()['sources']['rest'] ) ) {
			return new WP_Error( 'leadsift_disabled', 'Endpoint disabled.', array( 'status' => 403 ) );
		}
		$params = $req->get_json_params();
		if ( empty( $params ) ) {
			$params = $req->get_body_params();
		}
		$params = (array) $params;
		if ( ! empty( $params['website_url_hp'] ) ) {
			// Honeypot filled in: pretend success, store nothing.
			return rest_ensure_response( array( 'ok' => true ) );
		}

		$ip  = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		$key = 'leadsift_rl_' . md5( $ip );
		$hit = (int) get_transient( $key );
		if ( $hit >= 10 ) {
			return new WP_Error( 'leadsift_rate_limited', 'Too many submissions. Try again later.', array( 'status' => 429 ) );
		}
		set_transient( $key, $hit + 1, 10 * MINUTE_IN_SECONDS );

		$form_name = sanitize_text_field( (string) ( $params['form_name'] ?? 'Custom form' ) );
		unset( $params['form_name'], $params['website_url_hp'] );
		$params = array_slice( $params, 0, 40, true );

		$row = LeadSift_Capture::capture( $params, 'rest', array( 'form_name' => $form_name ) );
		return rest_ensure_response( array( 'ok' => (bool) $row ) );
	}
}
