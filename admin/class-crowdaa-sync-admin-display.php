<?php

/**
 * The authenticate user in the admin panel of the plugin.
 *
 * @link       https://crowdaa.com
 * @since      1.0.0
 *
 * @package    Crowdaa_Sync_Admin_Display
 * @subpackage Crowdaa_Sync_Admin_Display/admin
 * @author     Crowdaa <contact@crowdaa.com>
 */

class Crowdaa_Sync_Admin_Display
{
  private const AUTH_API_KEY = 'nQ9ZO9DEgfaOzWY44Xu2J2uaPtP92t176PpBkdqu';
  /**
   * Initialize the class and set its properties.
   *
   * @since    1.0.0
   */
  public function __construct() {}

  /**
   * Admin notices
   *
   * @since    1.0.0
   */
  public function database_reset()
  {
    if (empty($_POST) || empty($_POST['crowdaa_reset']) || !wp_verify_nonce($_POST['crowdaa_reset'], 'crowdaa_reset_data')) {
      return;
    }

    Crowdaa_Sync_Logs::log('Running database reset');

    update_option('crowdaa_cron_sync_enabled', 'no');

    Crowdaa_Sync_Permissions::reset();

    $sync_db = new Crowdaa_Sync_Syncdb('categories');
    $sync_db->reset();

    $table = Crowdaa_Sync_Utils::db_prefix() . 'postmeta';
    Crowdaa_Sync_Utils::quick_query("DELETE FROM `$table` WHERE meta_key = %s", ['api_picture_id']);
    Crowdaa_Sync_Utils::quick_query("DELETE FROM `$table` WHERE meta_key = %s", ['api_videos_id']);
    Crowdaa_Sync_Utils::quick_query("DELETE FROM `$table` WHERE meta_key = %s", ['api_feedpicture_id']);
    Crowdaa_Sync_Utils::quick_query("DELETE FROM `$table` WHERE meta_key = %s", ['api_media_map']);
    Crowdaa_Sync_Utils::quick_query("DELETE FROM `$table` WHERE meta_key = %s", ['api_post_id']);
    Crowdaa_Sync_Utils::quick_query("DELETE FROM `$table` WHERE meta_key LIKE 'crowdaa_%'");

    $table = Crowdaa_Sync_Utils::db_prefix() . 'termmeta';
    Crowdaa_Sync_Utils::quick_query("DELETE FROM `$table` WHERE meta_key = %s", ['api_term_id']);
    Crowdaa_Sync_Utils::quick_query("DELETE FROM `$table` WHERE meta_key LIKE 'crowdaa_%'");

    Crowdaa_Sync_Versions::bump_version();

    Crowdaa_Sync_Logs::log('Database reset done');
  }

  public function enable_sync()
  {
    if (!isset($_POST['crowdaa_cron_sync_enabled']) || !wp_verify_nonce($_POST['crowdaa_cron_sync_enabled'], 'crowdaa_cron_sync_enabled_data')) {
      return;
    }

    if (isset($_POST['cron_sync_enable'])) {
      update_option('crowdaa_cron_sync_enabled', 'yes');
    } else {
      update_option('crowdaa_cron_sync_enabled', 'no');
    }

    if (isset($_POST['sync_wp_to_api_enable'])) {
      update_option('crowdaa_sync_articles_wp_to_api_enabled', 'yes');
    } else {
      update_option('crowdaa_sync_articles_wp_to_api_enabled', 'no');
    }

    if (isset($_POST['sync_api_to_wp_enable'])) {
      update_option('crowdaa_sync_articles_api_to_wp_enabled', 'yes');
    } else {
      update_option('crowdaa_sync_articles_api_to_wp_enabled', 'no');
    }

    if (isset($_POST['sync_wpapi_register_enable'])) {
      update_option('crowdaa_sync_wpapi_register_enabled', 'yes');
    } else {
      update_option('crowdaa_sync_wpapi_register_enabled', 'no');
    }

    Crowdaa_Sync_Permissions::plugin_use($_POST['sync_perm_plugin']);

    do_action('crowdaa_sync_configure_levers');

    $sync_max_duration = (int) $_POST['sync_max_duration'];
    if ($sync_max_duration < 2) {
      $sync_max_duration = 2;
    }
    update_option('crowdaa_sync_max_duration', $sync_max_duration);
    $sync_logs = [
      'Auto' => get_option('crowdaa_cron_sync_enabled') === 'yes',
      'WP to API' => get_option('crowdaa_sync_articles_wp_to_api_enabled') === 'yes',
      'API to WP' => get_option('crowdaa_sync_articles_api_to_wp_enabled') === 'yes',
      'WP-API register' => get_option('crowdaa_sync_wpapi_register_enabled') === 'yes',
      'duration' => get_option('crowdaa_sync_max_duration', 10),
      'perm_plugin' => Crowdaa_Sync_Permissions::plugin_get(),
    ];
    $sync_logs = apply_filters('crowdaa_sync_configure_levers_log', $sync_logs);
    Crowdaa_Sync_Logs::log('Parameters', 'sync', wp_json_encode($sync_logs));
  }

  public function post_login_setup()
  {
    $pluginApiKey = Crowdaa_Sync_Utils::generate_plugin_api_key();
    $syncDomainNames = get_option('crowdaa_sync_domain_names');
    $api = new Crowdaa_Sync_API();
    $setupPayload = [
      'action' => 'setup',
      'pluginApiKey' => $pluginApiKey,
      'wordpressApiUrl' => preg_replace('~/$~', '', get_rest_url()),
      'defaultWordpressUrl' => get_site_url(),
    ];
    if ($syncDomainNames) {
      $setupPayload['syncDomainNames'] = explode(',', $syncDomainNames);
    }
    $response = $api->http_request('POST', '/websites/crowdaa-sync/autosetup', $setupPayload);

    $err    = is_wp_error($response) ? $response->get_error_message() : null;
    if (!$err) {
      $body = wp_remote_retrieve_body($response);
      $json = json_decode($body);
    }

    if ($err) {
      Crowdaa_Sync_Logs::log('Login autosetup error', $err);
      return false;
    } else if (isset($json->message)) {
      Crowdaa_Sync_Logs::log('Login autosetup error message', $json->message);
      return false;
    } else if (isset($json->errors)) {
      Crowdaa_Sync_Logs::log('Login autosetup error messages', $json->errors);
      return false;
    } else if (!isset($json->data)) {
      Crowdaa_Sync_Logs::log('Login autosetup, no response!');
      return false;
    }

    return true;
  }

  public function on_logout_unset()
  {
    $pluginApiKey = Crowdaa_Sync_Utils::get_plugin_api_key();
    $api = new Crowdaa_Sync_API();
    $api->http_request('POST', '/websites/crowdaa-sync/autosetup', [
      'action' => 'logout',
      'pluginApiKey' => $pluginApiKey,
      'wordpressApiUrl' => preg_replace('~/$~', '', get_rest_url()),
    ]);
  }

  /**
   * Admin notices
   *
   * @since    1.0.0
   */
  public function login()
  {
    if (empty($_POST) || !isset($_POST['crowdaa_login']) || !wp_verify_nonce($_POST['crowdaa_login'], 'crowdaa_login_data')) {
      return;
    }

    Crowdaa_Sync_Logs::log('Parameters', 'connection_settings', 'Enabling connection...');
    if (isset($_POST['status']) && $_POST['status'] == 'connect') {
      if (isset($_POST['api_url']) && isset($_POST['user_app_id']) && isset($_POST['user_email']) && isset($_POST['user_password'])) {
        update_option('crowdaa_api_url', esc_url_raw($_POST['api_url']));
        $user_email = sanitize_email($_POST['user_email']);
        $user_password = Crowdaa_Sync_Api::sanitize_api_password($_POST['user_password']);
        $user_app_id = Crowdaa_Sync_Api::sanitize_api_key($_POST['user_app_id']);
        $sync_domain_names = Crowdaa_Sync_Api::sanitize_sync_domain_names($_POST['sync_domain_names']);
        $data = [
          'email'    => $user_email,
          'password' => $user_password,
        ];

        $api = new Crowdaa_Sync_API();

        $response = $api->http_request('POST', '/auth/login', $data, ['X-Api-Key' => self::AUTH_API_KEY]);
        $err    = is_wp_error($response) ? $response->get_error_message() : null;
        if (!$err) {
          $body = wp_remote_retrieve_body($response);
          $json = json_decode($body);
        }

        if ($err) {
          Crowdaa_Sync_Logs::log('HTTP API error', $err);
          add_action(
            'admin_notices',
            function () {
              self::admin_notice('error', __('Failed to get data. Please try again later', CROWDAA_SYNC_PLUGIN_NAME));
            }
          );
          return;
        }

        if (!$json) {
          Crowdaa_Sync_Logs::log('HTTP API json decoding error', $err);
          add_action(
            'admin_notices',
            function () {
              self::admin_notice('error', __('Failed to get data. Please try again later', CROWDAA_SYNC_PLUGIN_NAME));
            }
          );
          return;
        }

        if (isset($json->message)) {
          add_action(
            'admin_notices',
            function () use ($json) {
              if ($json->message === 'forbidden') {
                $message = __('Data is incorrect please double-check and try again', CROWDAA_SYNC_PLUGIN_NAME);
              } else {
                $message = $json->message;
              }
              self::admin_notice('error', $message);
            }
          );
          return;
        }

        if (isset($json->status) && $json->status === 'success') {
          update_option('crowdaa_user_email', $user_email);
          update_option('crowdaa_user_password', md5($user_password));
          update_option('crowdaa_user_api_key', 'appId:' . $user_app_id);
          update_option('crowdaa_user_id', $json->data->userId);
          update_option('crowdaa_auth_token', $json->data->authToken);
          update_option('crowdaa_sync_domain_names', $sync_domain_names);

          $ok = $this->post_login_setup();
          if (!$ok) {
            update_option('crowdaa_user_id', '');
            update_option('crowdaa_auth_token', '');
            update_option('crowdaa_api_url', '');
            update_option('crowdaa_user_email', '');
            update_option('crowdaa_user_password', '');
            update_option('crowdaa_user_api_key', '');
            update_option('crowdaa_sync_domain_names', '');

            self::admin_notice('error', __('Failed to complete login initialization process', CROWDAA_SYNC_PLUGIN_NAME));
            return;
          }

          add_action(
            'admin_notices',
            function () {
              self::admin_notice('success', __('You are successfully logged in', CROWDAA_SYNC_PLUGIN_NAME));
            }
          );
          Crowdaa_Sync_Logs::log('Parameters', 'connection_settings', 'Connection enabled!');
        } else {
          return;
        }
      } else {
        add_action(
          'admin_notices',
          function () {
            self::admin_notice('error', __('Please, provide all user data', CROWDAA_SYNC_PLUGIN_NAME));
          }
        );
        return;
      }
      return;
    } else {
      $this->on_logout_unset();

      update_option('crowdaa_user_id', '');
      update_option('crowdaa_auth_token', '');
      update_option('crowdaa_api_url', '');
      update_option('crowdaa_user_email', '');
      update_option('crowdaa_user_password', '');
      update_option('crowdaa_user_api_key', '');
      update_option('crowdaa_sync_domain_names', '');

      Crowdaa_Sync_Logs::log('Parameters', 'connection_settings', 'Connection disabled');
    }
  }

  /**
   * Choose default gallery image
   *
   * @since    1.0.0
   */
  public function choose_default_image()
  {
    if (isset($_POST['submit'])) {
      $file_return = wp_handle_upload($_FILES['file'], array('test_form' => false));
      if (isset($file_return['error']) || isset($file_return['upload_error_handler'])) {
        return false;
      } else {
        $filename = $file_return['file'];
        Crowdaa_Sync_Logs::log('Parameters', 'choose_default_image', 'Selected image "' . $filename . '"');

        $post_title = sanitize_file_name(pathinfo($filename, PATHINFO_FILENAME));
        $attachment  = [
          'guid'           => $file_return['url'],
          'post_mime_type' => $file_return['type'],
          'post_title'     => $post_title,
          'post_content'   => '',
          'post_status'    => 'inherit',
        ];

        $attach_id   = wp_insert_attachment($attachment, $filename);
        $attach_data = wp_generate_attachment_metadata($attach_id, $filename);
        wp_update_attachment_metadata($attach_id, $attach_data);

        $api = new Crowdaa_Sync_API();
        $meta = [
          'file_type'     => $file_return['type'],
          'file_name'     => $post_title,
          'file_size'     => filesize($filename),
          'attachment_id' => $attach_id,
          'image_url'     => $file_return['url'],
        ];
        $api->create_image_api($meta, 'default_image');

        return $attach_id;
      }
    } else {
      add_action(
        'admin_notices',
        function () {
          self::admin_notice('error', 'Please, add Default image');
        }
      );
    }
  }

  /**
   * Set which categories will be displayed on the app feed page
   *
   * @since    1.0.0
   */
  public function choose_feed_categories()
  {
    if (!isset($_POST['crowdaa_set_feed_categories']) || !wp_verify_nonce($_POST['crowdaa_set_feed_categories'], 'crowdaa_set_feed_categories_data')) {
      return;
    }

    if (isset($_POST['sync_feed_categories_all'])) {
      update_option('crowdaa_sync_feed_categories', 'all');
    } else {
      $categories = array_filter($_POST['sync_feed_categories'], function ($v) {
        if (empty($v)) return (false);
        $v = (string) $v;
        if (!preg_match('/^[0-9]+$/', $v)) return (false);

        return (true);
      });

      update_option('crowdaa_sync_feed_categories', implode(',', $categories));
    }

    Crowdaa_Sync_Logs::log('Parameters', 'choose_feed_categories', 'Updated feed categories : ' . get_option('crowdaa_sync_feed_categories', 'all'));

    Crowdaa_Sync_Versions::bump_version();
  }

  /**
   * Set which categories will be synchronized or not on the app
   *
   * @since    1.0.0
   */
  public function choose_sync_categories()
  {
    if (!isset($_POST['crowdaa_set_sync_categories']) || !wp_verify_nonce($_POST['crowdaa_set_sync_categories'], 'crowdaa_set_sync_categories_data')) {
      return;
    }

    if (isset($_POST['sync_categories_mode_whitelist'])) {
      update_option('crowdaa_sync_categories_mode', 'whitelist');
    } else {
      update_option('crowdaa_sync_categories_mode', 'blacklist');
    }

    $categories = array_filter($_POST['sync_categories'], function ($v) {
      if (empty($v)) return (false);
      $v = (string) $v;
      if (!preg_match('/^[0-9]+$/', $v)) return (false);

      return (true);
    });

    update_option('crowdaa_sync_categories_list', implode(',', $categories));

    Crowdaa_Sync_Logs::log('Parameters', 'choose_sync_categories', 'Updated sync categories : ' . get_option('crowdaa_sync_categories_list', ''));

    Crowdaa_Sync_Versions::bump_version();
  }

  /**
   * Admin notices
   *
   * @since    1.0.0
   * @param $result
   * @param $message
   */
  public static function admin_notice($result = '', $message = '')
  {
    switch ($result) {
      case 'error':
      case 'warning':
      case 'success':
      case 'info':
        break;
      default:
        $result = 'error';
        break;
    }

?>
    <div class="notice notice-<?php echo esc_attr($result) ?> is-dismissible">
      <p>
        <?php esc_html_e($message, CROWDAA_SYNC_PLUGIN_NAME) ?>
      </p>
    </div>
<?php
  }
}
