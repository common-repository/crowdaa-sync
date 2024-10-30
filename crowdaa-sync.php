<?php

/**
 * Crowdaa Sync
 *
 * This is the main file and entry point of this plugin.
 *
 * @link              https://crowdaa.com
 * @since             1.0.0
 * @package           Crowdaa-Sync
 *
 * @wordpress-plugin
 * Plugin Name:       Crowdaa sync
 * Plugin URI:        
 * Description:       Plugin for synchronizing WordPress site and Crowdaa CMS
 * Version:           1.10.1
 * Requires at least: 5.5
 * Requires PHP:      7.2
 * Author:            Crowdaa
 * Author URI:        https://www.crowdaa.com/
 * License:           GPL-2.0+
 * License URI:       http://www.gnu.org/licenses/gpl-2.0.txt
 * Text Domain:       crowdaa-sync
 * Domain Path:       /languages
 */

// If this file is called directly, abort.
if (!defined('WPINC')) {
  die;
}

/**
 * Currently plugin version.
 * Uses SemVer - https://semver.org
 */
define('CROWDAA_SYNC_VERSION', '1.10.1');
define('CROWDAA_SYNC_PLUGIN_DIR', __DIR__);
define('CROWDAA_SYNC_PLUGIN_NAME', 'crowdaa-sync');

define('CROWDAA_SYNC_ISO_TIME_FORMAT', 'Y-m-d\TH:i:s\Z');

/**
 * This define is used in case of update/upgrade, when we want to re-run the synchronization.
 * Just bump the version, it will handle the rest.
 */
define('CROWDAA_SYNC_META_VERSION', '3');

$last_version = get_option('crowdaa_last_version', false);
if (!$last_version || $last_version < CROWDAA_SYNC_META_VERSION) {
  update_option('crowdaa_sync_articles_wp_to_api_from', 0);
  update_option('crowdaa_sync_articles_api_to_wp_from', 0);
  update_option('crowdaa_last_version', CROWDAA_SYNC_META_VERSION);
}

/**
 * @TODO Replace this file regularly, to avoid api call problems...
 * Downloade from https://curl.se/ca/cacert.pem
 * See https://curl.se/docs/caextract.html
 * See https://curl.se/docs/sslcerts.html
 * Today (2021/07/09), these certs should expire in 2038, for Amazon.
 */
define('CROWDAA_SYNC_CACERT_PATH', __DIR__ . '/cacert.pem');

/**
 * The code that runs during plugin activation.
 * This action is documented in includes/class-crowdaa-sync-activator.php
 */
function activate_crowdaa_sync()
{
  require_once CROWDAA_SYNC_PLUGIN_DIR . '/includes/class-crowdaa-sync-activator.php';
  Crowdaa_Sync_Activator::activate();
}

/**
 * The code that runs during plugin deactivation.
 * This action is documented in includes/class-crowdaa-sync-deactivator.php
 */
function deactivate_crowdaa_sync()
{
  require_once CROWDAA_SYNC_PLUGIN_DIR . '/includes/class-crowdaa-sync-deactivator.php';
  Crowdaa_Sync_Deactivator::deactivate();
}

register_activation_hook(__FILE__, 'activate_crowdaa_sync');
register_deactivation_hook(__FILE__, 'deactivate_crowdaa_sync');

/**
 * The core plugin class that is used to define internationalization,
 * admin-specific hooks, and public-facing site hooks.
 */
require CROWDAA_SYNC_PLUGIN_DIR . '/includes/class-crowdaa-sync.php';

/**
 * Begins execution of the plugin.
 *
 * Since everything within the plugin is registered via hooks,
 * then kicking off the plugin from this point in the file does
 * not affect the page life cycle.
 *
 * @since    1.0.0
 */
function run_crowdaa_sync()
{
  $plugin = new Crowdaa_Sync();
  $plugin->run();

  add_action('admin_menu', array($plugin, 'crowdaa_menu_display'));

  if (class_exists('Crowdaa_Sync_Versions')) {
    Crowdaa_Sync_Versions::init();
  }

  if (class_exists('Crowdaa_Sync_WP_Hooks')) {
    new Crowdaa_Sync_WP_Hooks;
  }

  if (class_exists('Crowdaa_Sync_Ext_Hooks')) {
    new Crowdaa_Sync_Ext_Hooks;
  }

  if (class_exists('Crowdaa_Sync_Admin_Display')) {
    new Crowdaa_Sync_Admin_Display;
  }

  if (class_exists('Crowdaa_Sync_API')) {
    new Crowdaa_Sync_API;
  }

  if (class_exists('Crowdaa_Sync_Add_Info_WP')) {
    new Crowdaa_Sync_Add_Info_WP;
  }

  if (class_exists('Crowdaa_Sync_Add_Info_API')) {
    new Crowdaa_Sync_Add_Info_API;
  }

  if (class_exists('Crowdaa_Sync_Timer')) {
    Crowdaa_Sync_Timer::init();
  }

  if (class_exists('Crowdaa_Sync_Meta_Box')) {
    new Crowdaa_Sync_Meta_Box;
  }
}

run_crowdaa_sync();
