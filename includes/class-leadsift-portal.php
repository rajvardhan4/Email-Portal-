<?php
/**
 * Client Portal: a page on the site (created on install) that shows the LeadSift
 * dashboard full-screen – no theme header, footer or styles – behind its own login.
 *
 * Logins are managed in LeadSift → Dashboard page & login. They are NOT WordPress
 * users: a client never gets a wp-admin account. Passwords are stored hashed;
 * signing in returns a random token (kept hashed on the server) that the React app
 * sends with every request in the X-LeadSift-Token header.
 *
 * Every login sees the whole dashboard (inquiries, forms, insights, AI usage,
 * settings). "Manage" logins may also change form setup and settings there;
 * "View" logins only look. Rules, the AI key and the logins stay in wp-admin.
 * Nobody – not even a WordPress admin – sees the dashboard without signing in.
 *
 * @package LeadSift
 */

defined( 'ABSPATH' ) || exit;

class LeadSift_Portal {

	const USERS_OPTION  = 'leadsift_portal_users';
	const TOKENS_OPTION = 'leadsift_portal_tokens';
	const TOKEN_DAYS    = 30;
	const MAX_FAILS     = 8;

	/** @var array|null|false Portal user for the current request (false = checked, none). */
	private static $current = null;

	public static function init() {
		add_filter( 'template_include', array( __CLASS__, 'template' ), 99 );
		add_filter( 'display_post_states', array( __CLASS__, 'post_state' ), 10, 2 );
		add_filter( 'wp_sitemaps_posts_query_args', array( __CLASS__, 'hide_from_sitemap' ), 10, 2 );
	}

	/* ------------------------------ The page ------------------------------ */

	public static function page_id() {
		return (int) get_option( LeadSift_Admin::PAGE_OPTION );
	}

	public static function url() {
		$id = self::page_id();
		return $id ? get_permalink( $id ) : '';
	}

	/**
	 * Creates the public "Client Portal" page once (or upgrades the old private
	 * "Inquiry Dashboard" page from earlier versions).
	 */
	public static function ensure_page() {
		$id = self::page_id();
		if ( $id && get_post( $id ) && 'trash' !== get_post_status( $id ) ) {
			if ( 'publish' !== get_post_status( $id ) || 'inquiry-dashboard' === get_post_field( 'post_name', $id ) ) {
				wp_update_post(
					array(
						'ID'          => $id,
						'post_title'  => __( 'Client Portal', 'leadsift' ),
						'post_name'   => 'client-portal',
						'post_status' => 'publish',
					)
				);
			}
			return $id;
		}
		$id = wp_insert_post(
			array(
				'post_title'     => __( 'Client Portal', 'leadsift' ),
				'post_name'      => 'client-portal',
				'post_content'   => '<!-- wp:shortcode -->[' . LeadSift_Admin::SHORTCODE . ']<!-- /wp:shortcode -->',
				'post_status'    => 'publish',
				'post_type'      => 'page',
				'comment_status' => 'closed',
				'ping_status'    => 'closed',
			)
		);
		if ( $id && ! is_wp_error( $id ) ) {
			update_option( LeadSift_Admin::PAGE_OPTION, (int) $id );
			return (int) $id;
		}
		return 0;
	}

	/** The portal page uses LeadSift's own blank template (no theme header / footer). */
	public static function template( $template ) {
		$id = self::page_id();
		if ( $id && is_page( $id ) ) {
			return LEADSIFT_DIR . 'templates/portal.php';
		}
		return $template;
	}

	public static function post_state( $states, $post ) {
		if ( self::page_id() === (int) $post->ID ) {
			$states['leadsift'] = __( 'LeadSift Client Portal', 'leadsift' );
		}
		return $states;
	}

	public static function hide_from_sitemap( $args, $post_type ) {
		if ( 'page' === $post_type && self::page_id() ) {
			$args['post__not_in']   = isset( $args['post__not_in'] ) ? (array) $args['post__not_in'] : array();
			$args['post__not_in'][] = self::page_id();
		}
		return $args;
	}

	/* ------------------------------ Logins ------------------------------ */

	public static function users() {
		$users = get_option( self::USERS_OPTION, array() );
		return is_array( $users ) ? $users : array();
	}

	/** Users without password hashes, for the dashboard. */
	public static function public_users() {
		$out = array();
		foreach ( self::users() as $u ) {
			$out[] = array(
				'id'         => $u['id'],
				'name'       => $u['name'],
				'username'   => $u['username'],
				'access'     => self::clean_access( $u['access'] ),
				'created'    => $u['created'],
				'last_login' => $u['last_login'] ?? '',
			);
		}
		return $out;
	}

	/**
	 * @return array|WP_Error The new user plus the plain password (shown once).
	 */
	public static function create_user( $name, $username, $password, $access ) {
		$username = strtolower( sanitize_user( (string) $username, true ) );
		$name     = sanitize_text_field( (string) $name );
		if ( strlen( $username ) < 3 ) {
			return new WP_Error( 'leadsift_portal_username', 'Username must have at least 3 letters or numbers.', array( 'status' => 400 ) );
		}
		foreach ( self::users() as $u ) {
			if ( $u['username'] === $username ) {
				return new WP_Error( 'leadsift_portal_exists', 'This username is already used.', array( 'status' => 400 ) );
			}
		}
		$password = (string) $password;
		if ( '' === $password ) {
			$password = wp_generate_password( 14, false );
		} elseif ( strlen( $password ) < 8 ) {
			return new WP_Error( 'leadsift_portal_password', 'Password must be at least 8 characters.', array( 'status' => 400 ) );
		}
		$user = array(
			'id'         => 'u' . substr( md5( uniqid( '', true ) ), 0, 10 ),
			'name'       => $name ? $name : $username,
			'username'   => $username,
			'pass'       => wp_hash_password( $password ),
			'access'     => self::clean_access( $access ),
			'created'    => current_time( 'mysql' ),
			'last_login' => '',
		);
		$users                = self::users();
		$users[ $user['id'] ] = $user;
		update_option( self::USERS_OPTION, $users, false );

		$public             = self::public_users();
		$created            = end( $public );
		$created['password'] = $password;
		return $created;
	}

	/**
	 * Update name / access, or set a new password ("" = generate one).
	 *
	 * @return array|WP_Error
	 */
	public static function update_user( $id, array $data ) {
		$users = self::users();
		if ( ! isset( $users[ $id ] ) ) {
			return new WP_Error( 'leadsift_portal_missing', 'Login not found.', array( 'status' => 404 ) );
		}
		$new_password = null;
		if ( isset( $data['name'] ) ) {
			$users[ $id ]['name'] = sanitize_text_field( (string) $data['name'] );
		}
		if ( isset( $data['access'] ) ) {
			$users[ $id ]['access'] = self::clean_access( $data['access'] );
		}
		if ( array_key_exists( 'password', $data ) ) {
			$new_password = (string) $data['password'];
			if ( '' === $new_password ) {
				$new_password = wp_generate_password( 14, false );
			} elseif ( strlen( $new_password ) < 8 ) {
				return new WP_Error( 'leadsift_portal_password', 'Password must be at least 8 characters.', array( 'status' => 400 ) );
			}
			$users[ $id ]['pass'] = wp_hash_password( $new_password );
			self::revoke_user_tokens( $id ); // Sign out everywhere after a password change.
		}
		update_option( self::USERS_OPTION, $users, false );

		foreach ( self::public_users() as $u ) {
			if ( $u['id'] === $id ) {
				if ( null !== $new_password ) {
					$u['password'] = $new_password;
				}
				return $u;
			}
		}
		return new WP_Error( 'leadsift_portal_missing', 'Login not found.' );
	}

	/**
	 * manage = sees everything on the dashboard and may change form setup & settings there.
	 * view   = sees everything, changes nothing (can still sort inquiries).
	 */
	public static function clean_access( $access ) {
		return in_array( $access, array( 'view', 'inquiries' ), true ) ? 'view' : 'manage';
	}

	public static function can_manage( $user ) {
		return is_array( $user ) && 'manage' === self::clean_access( $user['access'] ?? '' );
	}

	public static function delete_user( $id ) {
		$users = self::users();
		unset( $users[ $id ] );
		update_option( self::USERS_OPTION, $users, false );
		self::revoke_user_tokens( $id );
	}

	/* ------------------------------ Sign in ------------------------------ */

	/**
	 * @return array|WP_Error { token, user }
	 */
	public static function login( $username, $password ) {
		$ip   = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		$key  = 'leadsift_pl_' . md5( $ip );
		$fail = (int) get_transient( $key );
		if ( $fail >= self::MAX_FAILS ) {
			return new WP_Error( 'leadsift_portal_locked', 'Too many wrong attempts. Please try again in 15 minutes.', array( 'status' => 429 ) );
		}

		$username = strtolower( sanitize_user( (string) $username, true ) );
		$found    = null;
		foreach ( self::users() as $u ) {
			if ( hash_equals( $u['username'], $username ) ) {
				$found = $u;
			}
		}
		if ( ! $found || ! wp_check_password( (string) $password, $found['pass'] ) ) {
			set_transient( $key, $fail + 1, 15 * MINUTE_IN_SECONDS );
			return new WP_Error( 'leadsift_portal_wrong', 'Wrong username or password.', array( 'status' => 401 ) );
		}
		delete_transient( $key );

		$token  = bin2hex( random_bytes( 32 ) );
		$tokens = self::tokens();
		$tokens[ hash( 'sha256', $token ) ] = array(
			'user'    => $found['id'],
			'expires' => time() + self::TOKEN_DAYS * DAY_IN_SECONDS,
		);
		update_option( self::TOKENS_OPTION, $tokens, false );

		$users                                = self::users();
		$users[ $found['id'] ]['last_login'] = current_time( 'mysql' );
		update_option( self::USERS_OPTION, $users, false );

		return array(
			'token' => $token,
			'user'  => self::to_public( $found ),
		);
	}

	public static function logout( WP_REST_Request $req ) {
		$token = (string) $req->get_header( 'x_leadsift_token' );
		if ( '' !== $token ) {
			$tokens = self::tokens();
			unset( $tokens[ hash( 'sha256', $token ) ] );
			update_option( self::TOKENS_OPTION, $tokens, false );
		}
	}

	/**
	 * Portal user for this REST request (from the X-LeadSift-Token header), or null.
	 */
	public static function user_from_request( WP_REST_Request $req ) {
		if ( null !== self::$current ) {
			return self::$current ? self::$current : null;
		}
		self::$current = false;
		$token         = (string) $req->get_header( 'x_leadsift_token' );
		if ( strlen( $token ) !== 64 ) {
			return null;
		}
		$tokens = self::tokens();
		$hash   = hash( 'sha256', $token );
		if ( empty( $tokens[ $hash ] ) || $tokens[ $hash ]['expires'] < time() ) {
			return null;
		}
		$users = self::users();
		$user  = $users[ $tokens[ $hash ]['user'] ] ?? null;
		if ( ! $user ) {
			return null;
		}
		self::$current = self::to_public( $user );
		return self::$current;
	}

	private static function to_public( array $u ) {
		return array(
			'id'       => $u['id'],
			'name'     => $u['name'],
			'username' => $u['username'],
			'access'   => self::clean_access( $u['access'] ),
		);
	}

	private static function tokens() {
		$tokens = get_option( self::TOKENS_OPTION, array() );
		$tokens = is_array( $tokens ) ? $tokens : array();
		$now    = time();
		foreach ( $tokens as $hash => $t ) {
			if ( ( $t['expires'] ?? 0 ) < $now ) {
				unset( $tokens[ $hash ] );
			}
		}
		return $tokens;
	}

	private static function revoke_user_tokens( $id ) {
		$tokens = self::tokens();
		foreach ( $tokens as $hash => $t ) {
			if ( $t['user'] === $id ) {
				unset( $tokens[ $hash ] );
			}
		}
		update_option( self::TOKENS_OPTION, $tokens, false );
	}
}
