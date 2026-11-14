<?php
/**
 * Boots every part of the plugin.
 *
 * @package LeadSift
 */

defined( 'ABSPATH' ) || exit;

class LeadSift_Plugin {

	/** @var bool */
	private static $booted = false;

	public static function boot() {
		if ( self::$booted ) {
			return;
		}
		self::$booted = true;

		LeadSift_Upgrader::maybe_upgrade();
		LeadSift_Capture::init();
		LeadSift_AI::init();
		LeadSift_Importer::init();
		LeadSift_Portal::init();
		LeadSift_REST::init();
		LeadSift_Admin::init();

		add_action( 'leadsift_daily_cleanup', array( __CLASS__, 'cleanup' ) );
	}

	/**
	 * Optional retention: delete old spam/promotional rows if the user turned it on.
	 */
	public static function cleanup() {
		$days = (int) LeadSift_Settings::get()['retention_days'];
		if ( $days > 0 ) {
			LeadSift_DB::delete_older_than( $days, array( 'spam', 'promotional' ) );
		}
		LeadSift_Usage::cleanup();
	}
}
