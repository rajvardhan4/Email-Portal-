<?php
/**
 * Activation, deactivation and version upgrades.
 *
 * Every release stores its version in the `leadsift_version` option. When the
 * code version is newer than the stored one, the matching migration steps in
 * self::migrations() run once, in order.
 *
 * @package LeadSift
 */

defined( 'ABSPATH' ) || exit;

class LeadSift_Upgrader {

	public static function activate() {
		LeadSift_DB::install();
		LeadSift_Admin::ensure_dashboard_page();

		if ( false === get_option( LeadSift_Settings::OPTION ) ) {
			add_option( LeadSift_Settings::OPTION, LeadSift_Settings::defaults() );
		}
		if ( ! wp_next_scheduled( 'leadsift_daily_cleanup' ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'leadsift_daily_cleanup' );
		}

		self::run_migrations( get_option( 'leadsift_version', '0.0.0' ) );
		update_option( 'leadsift_version', LEADSIFT_VERSION );

		// Import and filter entries the site received before LeadSift was installed.
		LeadSift_Importer::start();

		// Show "LeadSift read your forms + Client Portal link" on the next admin screen.
		set_transient( 'leadsift_show_scan_notice', 1, 30 * MINUTE_IN_SECONDS );
	}

	public static function deactivate() {
		wp_clear_scheduled_hook( 'leadsift_daily_cleanup' );
		wp_unschedule_hook( LeadSift_AI::CRON_HOOK );
		wp_unschedule_hook( LeadSift_Importer::CRON_HOOK );
	}

	/**
	 * Runs on every load; cheap when nothing changed.
	 */
	public static function maybe_upgrade() {
		if ( get_option( 'leadsift_db_version' ) !== LEADSIFT_DB_VERSION ) {
			LeadSift_DB::install();
		}

		$stored = get_option( 'leadsift_version', '0.0.0' );
		if ( version_compare( $stored, LEADSIFT_VERSION, '<' ) ) {
			self::run_migrations( $stored );
			update_option( 'leadsift_version', LEADSIFT_VERSION );
		}
	}

	/**
	 * Version => callback. Add a new entry here whenever a release needs a
	 * one-time data change (example kept for reference).
	 *
	 * @return array<string, callable>
	 */
	private static function migrations() {
		return array(
			'1.0.0' => array( __CLASS__, 'migrate_1_0_0' ),
			'1.1.0' => array( __CLASS__, 'migrate_1_1_0' ),
			'1.6.0' => array( __CLASS__, 'migrate_1_6_0' ),
			'1.8.0' => array( __CLASS__, 'migrate_1_8_0' ),
		);
	}

	/**
	 * 1.6.0: the private "Inquiry Dashboard" page becomes the public Client Portal,
	 * and earlier form entries are imported (also when updating by upload, where
	 * WordPress does not run the activation hook).
	 */
	private static function migrate_1_6_0() {
		LeadSift_Portal::ensure_page();
		LeadSift_Importer::start();
		set_transient( 'leadsift_show_scan_notice', 1, 30 * MINUTE_IN_SECONDS );
	}

	/**
	 * 1.8.0: Forms and Settings moved from wp-admin to the dashboard page. Logins made
	 * earlier get "Manage" access so the owner can still change them there (any login
	 * can be switched to "View only" in LeadSift -> Dashboard page & login).
	 */
	private static function migrate_1_8_0() {
		$users = LeadSift_Portal::users();
		foreach ( $users as $id => $u ) {
			$users[ $id ]['access'] = 'manage';
		}
		update_option( LeadSift_Portal::USERS_OPTION, $users, false );
	}

	private static function migrate_1_1_0() {
		// New AI columns are added by LeadSift_DB::install() (LEADSIFT_DB_VERSION 1.1.0).
		// Make sure the new setting keys exist with their defaults.
		LeadSift_Settings::update( array() );
	}

	private static function run_migrations( $from ) {
		foreach ( self::migrations() as $version => $callback ) {
			if ( version_compare( $from, $version, '<' ) && version_compare( $version, LEADSIFT_VERSION, '<=' ) ) {
				call_user_func( $callback );
			}
		}
	}

	private static function migrate_1_0_0() {
		// First release: nothing to migrate.
	}
}
