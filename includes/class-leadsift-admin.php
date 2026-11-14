<?php
/**
 * Admin menu, the auto-created dashboard page, and loading the React app.
 *
 * @package LeadSift
 */

defined( 'ABSPATH' ) || exit;

class LeadSift_Admin {

	const PAGE_OPTION = 'leadsift_dashboard_page_id';
	const SHORTCODE   = 'leadsift_dashboard';

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'admin_assets' ) );
		add_shortcode( self::SHORTCODE, array( __CLASS__, 'shortcode' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( LEADSIFT_FILE ), array( __CLASS__, 'action_links' ) );
		add_filter( 'display_post_states', array( __CLASS__, 'post_state' ), 10, 2 );
		add_action( 'admin_notices', array( __CLASS__, 'scan_notice' ) );
	}

	/**
	 * Right after activation: read every form once and tell the owner what was found.
	 */
	public static function scan_notice() {
		if ( ! current_user_can( 'manage_options' ) || ! get_transient( 'leadsift_show_scan_notice' ) ) {
			return;
		}
		delete_transient( 'leadsift_show_scan_notice' );

		$forms    = LeadSift_Forms::all();
		$fields   = array_sum( array_column( $forms, 'field_count' ) );
		$warnings = array_sum( array_column( $forms, 'warnings' ) );
		$plugins  = array_unique( array_column( $forms, 'source_label' ) );
		$url      = LeadSift_Portal::url();

		echo '<div class="notice notice-success is-dismissible"><p><strong>LeadSift is ready.</strong> ';
		if ( $forms ) {
			printf(
				/* translators: 1: forms, 2: fields, 3: form plugins */
				esc_html__( 'It read %1$d forms with %2$d fields (%3$s) and worked out which field is the name, email, phone and message.', 'leadsift' ),
				count( $forms ),
				(int) $fields,
				esc_html( implode( ', ', $plugins ) )
			);
			if ( $warnings ) {
				echo ' ' . esc_html( sprintf( _n( '%d thing needs your attention.', '%d things need your attention.', $warnings, 'leadsift' ), $warnings ) );
			}
			if ( $url ) {
				echo ' ' . esc_html__( 'You can check them on the dashboard page (Forms tab).', 'leadsift' );
			}
		} else {
			esc_html_e( 'No forms found yet. Inquiries from supported form plugins will appear automatically.', 'leadsift' );
		}
		echo '</p>';

		$import = LeadSift_Importer::public_state();
		if ( $import['total'] > 0 ) {
			echo '<p>' . esc_html(
				sprintf(
					/* translators: %d: number of old entries */
					_n( 'Found %d earlier form entry – it is being imported and filtered in the background.', 'Found %d earlier form entries – they are being imported and filtered in the background.', $import['total'], 'leadsift' ),
					$import['total']
				)
			) . '</p>';
		}

		$portal = LeadSift_Portal::url();
		if ( $portal ) {
			echo '<p><strong>' . esc_html__( 'Client Portal:', 'leadsift' ) . '</strong> ' . esc_html( get_the_title( LeadSift_Portal::page_id() ) ) . ' – <a href="' . esc_url( $portal ) . '" target="_blank">' . esc_html( $portal ) . '</a>. ';
			echo '<a href="' . esc_url( admin_url( 'admin.php?page=leadsift' ) ) . '">' . esc_html__( 'Set the login ID & password', 'leadsift' ) . '</a></p>';
		}
		echo '</div>';
	}

	public static function menu() {
		$unread = LeadSift_DB::query( array( 'category' => 'genuine', 'unread' => true, 'per_page' => 1 ) )['total'];
		$bubble = $unread ? ' <span class="awaiting-mod">' . (int) $unread . '</span>' : '';

		// wp-admin is for SETUP only. The dashboard itself is the Client Portal page.
		add_menu_page(
			__( 'LeadSift Setup', 'leadsift' ),
			__( 'LeadSift', 'leadsift' ) . $bubble,
			'manage_options',
			'leadsift',
			array( __CLASS__, 'render' ),
			'dashicons-filter',
			26
		);
		add_submenu_page( 'leadsift', __( 'Dashboard page & login', 'leadsift' ), __( 'Dashboard page & login', 'leadsift' ), 'manage_options', 'leadsift', array( __CLASS__, 'render' ) );
		add_submenu_page( 'leadsift', __( 'Rules & AI', 'leadsift' ), __( 'Rules & AI', 'leadsift' ), 'manage_options', 'leadsift-rules', array( __CLASS__, 'render' ) );

		// "Open dashboard ↗" straight to the Client Portal page.
		$url = LeadSift_Portal::url();
		if ( $url ) {
			global $submenu;
			$submenu['leadsift'][] = array( __( 'Open dashboard ↗', 'leadsift' ), 'manage_options', $url ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride
		}
	}

	public static function render() {
		echo '<div class="wrap leadsift-wrap"><div id="leadsift-root" data-context="admin"></div></div>';
	}

	public static function admin_assets( $hook ) {
		if ( false === strpos( (string) $hook, 'leadsift' ) ) {
			return;
		}
		self::enqueue( 'admin' );
	}

	public static function enqueue( $context ) {
		$js  = LEADSIFT_DIR . 'build/index.js';
		$css = LEADSIFT_DIR . 'build/index.css';

		wp_enqueue_script(
			'leadsift-app',
			LEADSIFT_URL . 'build/index.js',
			array( 'wp-element', 'wp-api-fetch' ),
			file_exists( $js ) ? LEADSIFT_VERSION . '.' . filemtime( $js ) : LEADSIFT_VERSION,
			true
		);
		wp_enqueue_style(
			'leadsift-app',
			LEADSIFT_URL . 'build/index.css',
			array(),
			file_exists( $css ) ? LEADSIFT_VERSION . '.' . filemtime( $css ) : LEADSIFT_VERSION
		);

		$page_id  = (int) get_option( self::PAGE_OPTION );
		// phpcs:disable WordPress.Security.NonceVerification
		$pages    = array(
			'leadsift'       => 'portal',
			'leadsift-rules' => 'rules',
		);
		$sub_page = isset( $_GET['page'] ) && isset( $pages[ $_GET['page'] ] ) ? $pages[ $_GET['page'] ] : 'portal';
		if ( isset( $_GET['tab'] ) && in_array( $_GET['tab'], array( 'portal', 'rules' ), true ) ) {
			$sub_page = sanitize_key( $_GET['tab'] );
		}
		// phpcs:enable

		$is_admin = current_user_can( 'manage_options' );
		$config   = array(
			'restUrl'     => esc_url_raw( rest_url( LeadSift_REST::NS . '/' ) ),
			// The dashboard page ALWAYS works with a Login ID & password (LeadSift token), even for
			// WordPress admins – so no WP nonce there. wp-admin setup screens use the nonce.
			'nonce'       => 'admin' === $context && is_user_logged_in() ? wp_create_nonce( 'wp_rest' ) : '',
			'isAdmin'     => 'admin' === $context && $is_admin,
			'siteName'    => get_bloginfo( 'name' ),
			'siteUrl'     => home_url( '/' ),
			'portalUrl'   => LeadSift_Portal::url(),
			'version'     => LEADSIFT_VERSION,
			'author'      => 'Rajvardhan Singh Rathore',
			'context'     => $context,
			'startTab'    => $sub_page,
			'adminUrl'    => admin_url( 'admin.php?page=leadsift' ),
			'pageUrl'     => $page_id ? get_permalink( $page_id ) : '',
			'sources'     => LeadSift_Settings::sources(),
			'aiPresets'   => LeadSift_Settings::ai_presets(),
			'submitUrl'   => esc_url_raw( rest_url( LeadSift_REST::NS . '/submit' ) ),
			'dateFormat'  => get_option( 'date_format' ),
		);
		wp_add_inline_script( 'leadsift-app', 'window.LeadSiftConfig = ' . wp_json_encode( $config ) . ';', 'before' );
	}

	/**
	 * [leadsift_dashboard] – the same React dashboard on a normal page.
	 * Only administrators can see it.
	 */
	/**
	 * [leadsift_dashboard] – the portal app inside a normal page (used if a theme or
	 * page builder bypasses the blank portal template).
	 */
	public static function shortcode() {
		self::enqueue( 'portal' );
		return '<div class="leadsift-page"><div id="leadsift-root" data-context="portal"></div></div>';
	}

	/** Kept for older code paths: the dashboard page is now the Client Portal. */
	public static function ensure_dashboard_page() {
		return LeadSift_Portal::ensure_page();
	}

	public static function action_links( $links ) {
		array_unshift(
			$links,
			'<a href="' . esc_url( admin_url( 'admin.php?page=leadsift' ) ) . '">' . esc_html__( 'Setup', 'leadsift' ) . '</a>',
			'<a href="' . esc_url( admin_url( 'admin.php?page=leadsift-rules' ) ) . '">' . esc_html__( 'Rules & AI', 'leadsift' ) . '</a>',
			'<a href="' . esc_url( LeadSift_Portal::url() ) . '" target="_blank">' . esc_html__( 'Open dashboard', 'leadsift' ) . '</a>'
		);
		return $links;
	}

	public static function post_state( $states, $post ) {
		return $states; // The portal adds its own label (LeadSift_Portal::post_state).
	}
}
