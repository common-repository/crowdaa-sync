<?php

/**
 * The core plugin class.
 *
 * This is used to define internationalization, admin-specific hooks, and
 * public-facing site hooks.
 *
 * Also maintains the unique identifier of this plugin as well as the current
 * version of the plugin.
 *
 * @link       https://crowdaa.com
 * @since      1.0.0
 *
 * @package    Crowdaa-Sync
 * @subpackage Crowdaa-Sync/includes
 * @author     Crowdaa <contact@crowdaa.com>
 */
class Crowdaa_Sync
{
  /**
   * The loader that's responsible for maintaining and registering all hooks that power
   * the plugin.
   *
   * @since    1.0.0
   * @access   protected
   * @var      Crowdaa_Sync_Loader    $loader    Maintains and registers all hooks for the plugin.
   */
  protected $loader;

  /**
   * The unique identifier of this plugin.
   *
   * @since    1.0.0
   * @access   protected
   * @var      string    $plugin_name    The string used to uniquely identify this plugin.
   */
  protected $plugin_name;

  /**
   * The current version of the plugin.
   *
   * @since    1.0.0
   * @access   protected
   * @var      string    $version    The current version of the plugin.
   */
  protected $version;

  /**
   * Define the core functionality of the plugin.
   *
   * Set the plugin name and the plugin version that can be used throughout the plugin.
   * Load the dependencies, define the locale, and set the hooks for the admin area and
   * the public-facing side of the site.
   *
   * @since    1.0.0
   */
  public function __construct()
  {
    if (defined('CROWDAA_SYNC_VERSION')) {
      $this->version = CROWDAA_SYNC_VERSION;
    } else {
      $this->version = '1.0.0';
    }
    $this->plugin_name = 'crowdaa-sync';

    $this->load_dependencies();
    $this->set_locale();
    $this->define_admin_hooks();
    $this->define_public_hooks();
  }

  function crowdaa_menu_display()
  {
    add_menu_page(__('Crowdaa Sync', CROWDAA_SYNC_PLUGIN_NAME), __('Crowdaa Sync', CROWDAA_SYNC_PLUGIN_NAME), 'manage_options', 'crowdaa-sync', [$this, 'crowdaa_admin_display_html'], 'dashicons-update');
  }

  function crowdaa_admin_display_html()
  {
    require CROWDAA_SYNC_PLUGIN_DIR . '/admin/partials/crowdaa-sync-admin-display.php';
  }
  /**
   * Load the required dependencies for this plugin.
   *
   * Include the following files that make up the plugin.
   *
   * Create an instance of the loader which will be used to register the hooks
   * with WordPress.
   *
   * @since    1.0.0
   * @access   private
   */
  private function load_dependencies()
  {
    require_once(ABSPATH . 'wp-admin/includes/image.php');

    /**
     * Internal project dependancies (Mainly GuzzlePhp), installed with composer.
     */
    require_once CROWDAA_SYNC_PLUGIN_DIR . '/vendor/autoload.php';

    /**
     * The class responsible for managing versions & upgrades of the plugin, and to
     * handle re-deployment requirements for articles, categories, ... .
     */
    require_once CROWDAA_SYNC_PLUGIN_DIR . '/includes/class-crowdaa-sync-versions.php';

    /**
     * The class responsible for orchestrating the actions and filters of the
     * core plugin.
     */
    require_once CROWDAA_SYNC_PLUGIN_DIR . '/includes/class-crowdaa-sync-loader.php';

    /**
     * The class responsible for general utils used accross the plugin.
     */
    require_once CROWDAA_SYNC_PLUGIN_DIR . '/includes/class-crowdaa-sync-utils.php';

    /**
     * The class responsible logging & logs management.
     */
    require_once CROWDAA_SYNC_PLUGIN_DIR . '/includes/class-crowdaa-sync-logs.php';

    /**
     * The class responsible for defining internationalization functionality
     * of the plugin.
     */
    require_once CROWDAA_SYNC_PLUGIN_DIR . '/includes/class-crowdaa-sync-i18n.php';

    /**
     * The class responsible for errors management of the plugin.
     */
    require_once CROWDAA_SYNC_PLUGIN_DIR . '/includes/class-crowdaa-sync-exception.php';

    /**
     * The class responsible for locks & mutex for the plugin.
     */
    require_once CROWDAA_SYNC_PLUGIN_DIR . '/includes/class-crowdaa-sync-lock.php';

    /**
     * The class responsible for permissions plugins management for the plugin.
     */
    require_once CROWDAA_SYNC_PLUGIN_DIR . '/includes/class-crowdaa-sync-permissions.php';

    /**
     * The class responsible for checking the execution time of this plugin.
     */
    require_once CROWDAA_SYNC_PLUGIN_DIR . '/includes/class-crowdaa-sync-timer.php';

    /**
     * The class responsible for handling custom synchronizations.
     */
    require_once CROWDAA_SYNC_PLUGIN_DIR . '/includes/class-crowdaa-sync-syncdb.php';

    /**
     * The class responsible for defining all actions that occur in the admin area.
     */
    require_once CROWDAA_SYNC_PLUGIN_DIR . '/admin/class-crowdaa-sync-admin.php';

    /**
     * The class responsible for defining all actions that occur in the public-facing
     * side of the site.
     */
    require_once CROWDAA_SYNC_PLUGIN_DIR . '/public/class-crowdaa-sync-public.php';

    /**
     * The class works with the admin display page
     */
    require_once CROWDAA_SYNC_PLUGIN_DIR . '/admin/class-crowdaa-sync-admin-display.php';

    /**
     * The class works with a crowdaa api
     */
    require_once CROWDAA_SYNC_PLUGIN_DIR . '/admin/class-crowdaa-sync-api.php';

    /**
     * The class for add info to wp
     */
    require_once CROWDAA_SYNC_PLUGIN_DIR . '/admin/class-crowdaa-sync-add-info-wp.php';

    /**
     * The class for add info to api
     */
    require_once CROWDAA_SYNC_PLUGIN_DIR . '/admin/class-crowdaa-sync-add-info-api.php';

    /**
     * The class to add form elements to articles & else.
     */
    require_once CROWDAA_SYNC_PLUGIN_DIR . '/admin/class-crowdaa-sync-meta-box.php';

    /**
     * The class to add required rest API endpoints
     */
    require_once CROWDAA_SYNC_PLUGIN_DIR . '/admin/class-crowdaa-sync-rest-api.php';

    /**
     * The class for wp hooks
     */
    require_once CROWDAA_SYNC_PLUGIN_DIR . '/admin/class-crowdaa-sync-wp-hooks.php';

    /**
     * The class for other plugin hooks
     */
    require_once CROWDAA_SYNC_PLUGIN_DIR . '/admin/class-crowdaa-sync-ext-hooks.php';

    $this->loader = new Crowdaa_Sync_Loader();
  }

  /**
   * Define the locale for this plugin for internationalization.
   *
   * Uses the Crowdaa_Sync_i18n class in order to set the domain and to register the hook
   * with WordPress.
   *
   * @since    1.0.0
   * @access   private
   */
  private function set_locale()
  {
    $plugin_i18n = new Crowdaa_Sync_i18n();

    $this->loader->add_action('plugins_loaded', $plugin_i18n, 'load_plugin_textdomain');
  }

  /**
   * Register all of the hooks related to the admin area functionality
   * of the plugin.
   *
   * @since    1.0.0
   * @access   private
   */
  private function define_admin_hooks()
  {
    $plugin_admin = new Crowdaa_Sync_Admin($this->get_plugin_name(), $this->get_version());

    $this->loader->add_action('admin_enqueue_scripts', $plugin_admin, 'enqueue_styles');
    $this->loader->add_action('admin_enqueue_scripts', $plugin_admin, 'enqueue_scripts');
  }

  /**
   * Register all of the hooks related to the public-facing functionality
   * of the plugin.
   *
   * @since    1.0.0
   * @access   private
   */
  private function define_public_hooks()
  {
    $plugin_public = new Crowdaa_Sync_Public($this->get_plugin_name(), $this->get_version());

    $this->loader->add_action('wp_enqueue_scripts', $plugin_public, 'enqueue_styles');
    $this->loader->add_action('wp_enqueue_scripts', $plugin_public, 'enqueue_scripts');

    $api_plugin = new Crowdaa_Sync_Rest_Api($this->get_plugin_name(), $this->get_version());

    $this->loader->add_action('rest_api_init', $api_plugin, 'add_api_routes');
  }

  /**
   * Run the loader to execute all of the hooks with WordPress.
   *
   * @since    1.0.0
   */
  public function run()
  {
    $this->loader->run();
  }

  /**
   * The name of the plugin used to uniquely identify it within the context of
   * WordPress and to define internationalization functionality.
   *
   * @since     1.0.0
   * @return    string    The name of the plugin.
   */
  public function get_plugin_name()
  {
    return $this->plugin_name;
  }

  /**
   * The reference to the class that orchestrates the hooks with the plugin.
   *
   * @since     1.0.0
   * @return    Crowdaa_Sync_Loader    Orchestrates the hooks of the plugin.
   */
  public function get_loader()
  {
    return $this->loader;
  }

  /**
   * Retrieve the version number of the plugin.
   *
   * @since     1.0.0
   * @return    string    The version number of the plugin.
   */
  public function get_version()
  {
    return $this->version;
  }
}
