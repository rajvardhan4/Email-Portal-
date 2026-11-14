<?php
/**
 * Plugin Name:       LeadSift – Smart Inquiry Filter
 * Description:       Automatically sorts every website form inquiry into Genuine, Promotional or Spam, keeps all of it, and shows everything on a React dashboard.
 * Version:           1.8.0
 * Requires at least: 5.8
 * Requires PHP:      7.4
 * Author:            Rajvardhan Singh Rathore
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       leadsift
 *
 * @package LeadSift
 */

defined( 'ABSPATH' ) || exit;

/*
 * Version numbers.
 * LEADSIFT_VERSION    – plugin release version (bump with `npm run version:bump`).
 * LEADSIFT_DB_VERSION – database schema version. Only change it when the table
 *                       structure changes; the upgrader then runs automatically.
 */
define( 'LEADSIFT_VERSION', '1.8.0' );
define( 'LEADSIFT_DB_VERSION', '1.3.0' );
define( 'LEADSIFT_FILE', __FILE__ );
define( 'LEADSIFT_DIR', plugin_dir_path( __FILE__ ) );
define( 'LEADSIFT_URL', plugin_dir_url( __FILE__ ) );

require_once LEADSIFT_DIR . 'includes/class-leadsift-settings.php';
require_once LEADSIFT_DIR . 'includes/class-leadsift-db.php';
require_once LEADSIFT_DIR . 'includes/class-leadsift-classifier.php';
require_once LEADSIFT_DIR . 'includes/class-leadsift-capture.php';
require_once LEADSIFT_DIR . 'includes/class-leadsift-usage.php';
require_once LEADSIFT_DIR . 'includes/class-leadsift-ai.php';
require_once LEADSIFT_DIR . 'includes/class-leadsift-form-reader.php';
require_once LEADSIFT_DIR . 'includes/class-leadsift-forms.php';
require_once LEADSIFT_DIR . 'includes/class-leadsift-business.php';
require_once LEADSIFT_DIR . 'includes/class-leadsift-importer.php';
require_once LEADSIFT_DIR . 'includes/class-leadsift-portal.php';
require_once LEADSIFT_DIR . 'includes/class-leadsift-rest.php';
require_once LEADSIFT_DIR . 'includes/class-leadsift-admin.php';
require_once LEADSIFT_DIR . 'includes/class-leadsift-upgrader.php';
require_once LEADSIFT_DIR . 'includes/class-leadsift-plugin.php';

register_activation_hook( __FILE__, array( 'LeadSift_Upgrader', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'LeadSift_Upgrader', 'deactivate' ) );

add_action( 'plugins_loaded', array( 'LeadSift_Plugin', 'boot' ) );
