<?php

/**
 * Provide a admin area view for the plugin
 *
 * This file is used to markup the admin-facing aspects of the plugin.
 *
 * @link     https://crowdaa.com
 * @since    1.0.0
 *
 * @package  Crowdaa-Sync
 * @subpackage Crowdaa-Sync/admin/partials
 * @author     Crowdaa <contact@crowdaa.com>
 */

$admin_utils = new Crowdaa_Sync_Admin_Display();
?>

<!-- This file should primarily consist of HTML with a little bit of PHP. -->

<div class="crowdaa-html">
  <h1><?php echo esc_html(get_admin_page_title()); ?></h1>

  <h2><?php esc_html_e('Connect to Crowdaa', CROWDAA_SYNC_PLUGIN_NAME); ?></h2>
  <form method="post" action="<?php $admin_utils->login(); ?>">
    <?php
    $api_url            = get_option('crowdaa_api_url');
    $user_email         = get_option('crowdaa_user_email');
    $user_password      = get_option('crowdaa_user_password');
    $user_api_key       = get_option('crowdaa_user_api_key');
    $auth_token         = get_option('crowdaa_auth_token');
    $sync_domain_names  = get_option('crowdaa_sync_domain_names');
    $plugin_api_key     = Crowdaa_Sync_Utils::get_plugin_api_key();

    $is_authenticated = ($user_email && $user_password && $user_api_key && $auth_token);
    $user_app_id = $user_api_key ? substr($user_api_key, 6) : '';

    $api_urls = [
      'https://api.aws.crowdaa.com/v1' => __('US platform', CROWDAA_SYNC_PLUGIN_NAME),
      'https://api-fr.aws.crowdaa.com/v1' => __('FR platform', CROWDAA_SYNC_PLUGIN_NAME),
    ];
    if (isset($_GET['api'])) {
      $api_urls[$_GET['api']] = $_GET['api'];
    }
    if ($api_url && !array_key_exists($api_url, $api_urls)) {
      $api_urls[$api_url] = $api_url;
    }
    ?>
    <div class="crowdaa-html-container">
      <p>
        <label class="text-label" for="api_url"><?php esc_html_e('API URL', CROWDAA_SYNC_PLUGIN_NAME); ?></label>
        <select <?php echo $is_authenticated ? 'disabled' : '' ?> name="api_url">
          <?php
          foreach ($api_urls as $url => $name) { ?>
            <option value="<?php echo esc_attr($url); ?>" <?php echo ($url === $api_url ? 'selected="selected"' : ''); ?>>
              <?php echo esc_html($name); ?>
            </option>
          <?php } ?>
        </select>
      </p>
      <p>
        <label class="text-label" for="user_app_id"><?php esc_html_e('App ID', CROWDAA_SYNC_PLUGIN_NAME); ?></label>
        <input <?php echo $is_authenticated ? 'readonly' : '' ?> type="text" required id="user_app_id" name="user_app_id" value="<?php echo esc_attr(($user_app_id && $is_authenticated) ? $user_app_id : ''); ?>" />
      </p>
      <p>
        <label class="text-label" for="user_email"><?php esc_html_e('Email', CROWDAA_SYNC_PLUGIN_NAME); ?></label>
        <input <?php echo $is_authenticated ? 'readonly' : '' ?> type="text" required id="user_email" name="user_email" value="<?php echo esc_attr(($user_email && $is_authenticated) ? $user_email : ''); ?>" />
      </p>
      <p>
        <label class="text-label" for="user_password"><?php esc_html_e('Password', CROWDAA_SYNC_PLUGIN_NAME); ?></label>
        <input <?php echo $is_authenticated ? 'readonly' : '' ?> type="password" required id="user_password" name="user_password" value="<?php echo esc_attr(($user_password && $is_authenticated) ? $user_password : ''); ?>" />
      </p>
      <p>
        <?php
        $site_url = get_site_url();
        if ($site_url) {
          $current_domain = parse_url($site_url)['host'];
        } else if (isset($_SERVER['HTTP_HOST'])) {
          $current_domain = $_SERVER['HTTP_HOST'];
        } else {
          $current_domain = '';
        }
        ?>
        <label class="text-label" for="sync_domain_names"><?php esc_html_e('Domain names', CROWDAA_SYNC_PLUGIN_NAME); ?></label>
        <input
          <?php echo $is_authenticated ? 'readonly' : '' ?>
          type="text"
          pattern="[a-zA-Z0-9](([a-zA-Z0-9]|-)*[a-zA-Z0-9])?(\.[a-zA-Z0-9](([a-zA-Z0-9]|-)*[a-zA-Z0-9])?)*( *, *[a-zA-Z0-9](([a-zA-Z0-9]|-)*[a-zA-Z0-9])?(\.[a-zA-Z0-9](([a-zA-Z0-9]|-)*[a-zA-Z0-9])?)*)*"
          title="<?php echo esc_attr_e('Comma-separated domain names list for your website, like example.com, www.example.com, other.subdomain.example.com', CROWDAA_SYNC_PLUGIN_NAME); ?>"
          id="sync_domain_names"
          name="sync_domain_names"
          value="<?php echo esc_attr(($sync_domain_names && $is_authenticated) ? $sync_domain_names : $current_domain); ?>" />
      </p>
      <p>
        <label class="text-label" for="plugin_api_key"><?php esc_html_e('Plugin API Key', CROWDAA_SYNC_PLUGIN_NAME); ?></label>
        <input readonly type="text" required id="plugin_api_key" name="plugin_api_key" value="<?php echo esc_attr(($plugin_api_key && $auth_token) ? $plugin_api_key : ''); ?>" />
        <span id="plugin_api_key-copied"></span>
      </p>
    </div>
    <?php
    wp_nonce_field('crowdaa_login_data', 'crowdaa_login');
    if ($is_authenticated) {
    ?>
      <input type="hidden" id="status" name="status" value="disconnect" />
    <?php
      submit_button(__('Disconnect from Crowdaa', CROWDAA_SYNC_PLUGIN_NAME));
    } else {
    ?>
      <input type="hidden" id="status" name="status" value="connect" />
    <?php
      submit_button(__('Connect to Crowdaa', CROWDAA_SYNC_PLUGIN_NAME));
    }
    ?>
  </form>

  <form id="crowdaa-reset-form" method="post" action="<?php $admin_utils->database_reset(); ?>">
    <?php wp_nonce_field('crowdaa_reset_data', 'crowdaa_reset'); ?>
    <div>
      <h3><?php esc_html_e('DO YOU REALLY WANT TO RESET THE DATABASE?', CROWDAA_SYNC_PLUGIN_NAME); ?></h3>
      <?php esc_html_e('This action will erase all synchronization data. If you want to run a synchronization again after this step, you will either have to :', CROWDAA_SYNC_PLUGIN_NAME); ?>
      <ul>
        <li><?php esc_html_e('Change the targeted Crowdaa application', CROWDAA_SYNC_PLUGIN_NAME); ?></li>
        <li><?php esc_html_e('Erase the Crowdaa app content (categories, badges and articles)', CROWDAA_SYNC_PLUGIN_NAME); ?></li>
        <li><?php esc_html_e('Erase the Wordpress content (categories, permissions and articles)', CROWDAA_SYNC_PLUGIN_NAME); ?></li>
      </ul>

      <?php esc_html_e('This will also disable automatic synchronization.', CROWDAA_SYNC_PLUGIN_NAME); ?>

      <div class="crowdaa-reset-form-buttons">
        <button type="button" id="crowdaa-reset-dismiss">
          <?php esc_html_e('Cancel', CROWDAA_SYNC_PLUGIN_NAME); ?>
        </button>

        <button type="submit" class="crowdaa-reset-danger">
          <?php esc_html_e('RESET SYNC DATABASE', CROWDAA_SYNC_PLUGIN_NAME); ?>
        </button>
      </div>
    </div>
  </form>

  <button type="button" class="crowdaa-reset-danger" id="crowdaa-reset-request">
    <?php esc_html_e('RESET SYNC DATABASE', CROWDAA_SYNC_PLUGIN_NAME); ?>
  </button>

  <?php
  if ($is_authenticated) {
  ?>
    <hr />
    <div class="enable_sync">
      <form method="post" action="<?php $admin_utils->enable_sync(); ?>">
        <h2><?php esc_html_e('Enable/disable the synchronization', CROWDAA_SYNC_PLUGIN_NAME); ?></h2>
        <?php
        $sync_cron_enabled           = (get_option('crowdaa_cron_sync_enabled') === 'yes');
        $sync_wp_to_api_enabled      = (get_option('crowdaa_sync_articles_wp_to_api_enabled', 'yes') === 'yes');
        $sync_api_to_wp_enabled      = (get_option('crowdaa_sync_articles_api_to_wp_enabled', 'yes') === 'yes');
        $sync_wpapi_register_enabled = (get_option('crowdaa_sync_wpapi_register_enabled', 'yes') === 'yes');
        $sync_max_duration           = get_option('crowdaa_sync_max_duration', 10);
        $sync_perm_plugin            = Crowdaa_Sync_Permissions::plugin_get();
        ?>
        <label class="sync-form-checkbox-label">
          <span class="sync-form-label"><?php esc_html_e('Periodic synchronization enabled', CROWDAA_SYNC_PLUGIN_NAME); ?></span> &nbsp;
          <input type="checkbox" name="cron_sync_enable" class="sync-enable-checkbox" <?php echo ($sync_cron_enabled ? 'checked="checked"' : ''); ?> />
        </label>
        <br />
        <label class="sync-form-checkbox-label">
          <span class="sync-form-label"><?php esc_html_e('Synchronize WordPress content to API', CROWDAA_SYNC_PLUGIN_NAME); ?></span> &nbsp;
          <input type="checkbox" name="sync_wp_to_api_enable" class="sync-enable-checkbox" <?php echo ($sync_wp_to_api_enabled ? 'checked="checked"' : ''); ?> />
        </label>
        <br />
        <label class="sync-form-checkbox-label">
          <span class="sync-form-label"><?php esc_html_e('Synchronize API content to WordPress', CROWDAA_SYNC_PLUGIN_NAME); ?></span> &nbsp;
          <input type="checkbox" name="sync_api_to_wp_enable" class="sync-enable-checkbox" <?php echo ($sync_api_to_wp_enabled ? 'checked="checked"' : ''); ?> />
        </label>
        <br />
        <label class="sync-form-checkbox-label">
          <span class="sync-form-label"><?php esc_html_e('Enable WP register from app', CROWDAA_SYNC_PLUGIN_NAME); ?></span> &nbsp;
          <input type="checkbox" name="sync_wpapi_register_enable" class="sync-enable-checkbox" <?php echo ($sync_wpapi_register_enabled ? 'checked="checked"' : ''); ?> />
        </label>
        <br />
        <?php do_action('crowdaa_sync_configuration_levers'); ?>
        <label class="sync-form-checkbox-label">
          <span class="sync-form-label"><?php esc_html_e('Maximum synchronization duration (minutes)', CROWDAA_SYNC_PLUGIN_NAME); ?></span> &nbsp;
          <input type="number" name="sync_max_duration" min="2" id="sync-duration-field" value="<?php echo esc_attr($sync_max_duration); ?>" />
        </label>
        <br />
        <label class="sync-form-checkbox-label">
          <span class="sync-form-label"><?php esc_html_e('Permissions plugin', CROWDAA_SYNC_PLUGIN_NAME); ?></span> &nbsp;
          <select class="sync-permissions-plugin-select" name="sync_perm_plugin">
            <?php
            $perm_plugins_list = Crowdaa_Sync_Permissions::plugins_names_hash();
            foreach ($perm_plugins_list as $plugin => $pluginName) { ?>
              <option value="<?php echo esc_attr($plugin); ?>" <?php echo ($plugin === $sync_perm_plugin ? 'selected="selected"' : ''); ?>>
                <?php esc_html_e($pluginName, CROWDAA_SYNC_PLUGIN_NAME); ?>
              </option>
            <?php } ?>
          </select>
        </label>
        <br />
        <?php wp_nonce_field('crowdaa_cron_sync_enabled_data', 'crowdaa_cron_sync_enabled'); ?>
        <?php submit_button('Save parameters'); ?>
      </form>
    </div>
    <hr />

    <div>
      <h2><?php esc_html_e('Add default picture to the Gallery', CROWDAA_SYNC_PLUGIN_NAME) ?></h2>
      <form method="post" action="<?php $admin_utils->choose_default_image(); ?>" enctype="multipart/form-data">
        <?php
        $default_image = get_option('default_image');
        $default_image_url = null;
        if ($default_image) {
          $default_image = unserialize($default_image);
          $default_image_url = $default_image['url'];
        }
        ?>
        <?php if ($default_image_url) { ?>
          <div class="default_image">
            <img src="<?php echo esc_url($default_image_url) ?>" height="150px" />
          </div>
        <?php } ?>
        <input type="file" required name="file" id="image_file" accept=".jpg, .jpeg, .png" />
        <?php
        submit_button('Save picture');
        ?>
      </form>
    </div>

    <hr />

    <div>
      <h2><?php esc_html_e('Set categories to synchronize', CROWDAA_SYNC_PLUGIN_NAME) ?></h2>
      <form method="post" action="<?php $admin_utils->choose_sync_categories(); ?>" enctype="multipart/form-data">
        <?php
        $sync_categories_mode = get_option('crowdaa_sync_categories_mode', 'blacklist');
        $sync_categories_list = explode(',', get_option('crowdaa_sync_categories_list', ''));
        ?>
        <label class="sync-form-checkbox-label">
          <span class="sync-form-label"><?php esc_html_e('Categories sync mode', CROWDAA_SYNC_PLUGIN_NAME); ?></span> &nbsp;
          <input type="checkbox" name="sync_categories_mode_whitelist" class="sync-enable-checkbox-custom" id="crowdaa-sync-categories-mode-whitelist-checkbox" <?php echo ($sync_categories_mode === 'whitelist' ? 'checked="checked"' : ''); ?> />
          <span class="sync-enable-checkbox-custom-content">
            <span class="sync-enable-checkbox-custom-checked">Whitelist</span>
            <span class="sync-enable-checkbox-custom-unchecked">Blacklist</span>
          </span>
        </label>
        <div id="crowdaa-sync-categories-select-area">
          <label>
            <p>
              <span id="crowdaa-sync-categories-explanation-whitelist">
                <?php esc_html_e('Select below the categories that you *want* to synchronize to the app.', CROWDAA_SYNC_PLUGIN_NAME); ?>
              </span>
              <span id="crowdaa-sync-categories-explanation-blacklist">
                <?php esc_html_e('Select below the categories that you *do not want* to synchronize to the app.', CROWDAA_SYNC_PLUGIN_NAME); ?>
              </span>
              <br />
              <?php esc_html_e('Use the Ctrl or Shift keys to select multiple categories.', CROWDAA_SYNC_PLUGIN_NAME); ?><br />
            </p>
            <?php
            $terms = get_terms([
              "hide_empty" => false,
              "taxonomy" => "category",
            ]);
            ?>
            <select class="sync-categories-select" name="sync_categories[]" multiple>
              <option value="" <?php echo (array_search('', $sync_categories_list) !== false ? 'selected="selected"' : ''); ?>>
                <?php esc_html_e('---', CROWDAA_SYNC_PLUGIN_NAME); ?>
              </option>
              <?php foreach ($terms as $term) { ?>
                <option value="<?php echo esc_attr($term->term_id); ?>" <?php echo (array_search($term->term_id, $sync_categories_list) !== false ? 'selected="selected"' : ''); ?>>
                  <?php esc_html_e($term->name . ' (' . $term->slug . ')', CROWDAA_SYNC_PLUGIN_NAME); ?>
                </option>
              <?php } ?>
            </select>
          </label>
        </div>
        <?php wp_nonce_field('crowdaa_set_sync_categories_data', 'crowdaa_set_sync_categories'); ?>
        <?php
        submit_button('Save mode & categories');
        ?>
      </form>
    </div>

    <hr />

    <div>
      <h2><?php esc_html_e('Set feed (front-page) categories', CROWDAA_SYNC_PLUGIN_NAME) ?></h2>
      <form method="post" action="<?php $admin_utils->choose_feed_categories(); ?>" enctype="multipart/form-data">
        <?php
        $feed_categories = get_option('crowdaa_sync_feed_categories', 'all');
        ?>
        <label class="sync-form-checkbox-label">
          <span class="sync-form-label"><?php esc_html_e('Set all categories to be displayed on the app feed', CROWDAA_SYNC_PLUGIN_NAME); ?></span> &nbsp;
          <input type="checkbox" name="sync_feed_categories_all" class="sync-enable-checkbox" id="crowdaa-sync-feed-categories-checkbox" <?php echo ($feed_categories === 'all' ? 'checked="checked"' : ''); ?> />
        </label>
        <div id="crowdaa-sync-feed-categories-select-area">
          <label>
            <p>
              <?php esc_html_e('Select below the categories that you want to see on the feed page.', CROWDAA_SYNC_PLUGIN_NAME); ?><br />
              <?php esc_html_e('Use the Ctrl or Shift keys to select multiple categories.', CROWDAA_SYNC_PLUGIN_NAME); ?><br />
            </p>
            <?php
            $terms = get_terms([
              "hide_empty" => false,
              "taxonomy" => "category",
            ]);
            if ($feed_categories === 'all') {
              $feed_categories = [];
            } else {
              $feed_categories = explode(',', $feed_categories);
            }
            ?>
            <select class="sync-categories-select" name="sync_feed_categories[]" multiple>
              <option value="" <?php echo (array_search('', $feed_categories) !== false ? 'selected="selected"' : ''); ?>>
                <?php esc_html_e('---', CROWDAA_SYNC_PLUGIN_NAME); ?>
              </option>
              <?php foreach ($terms as $term) { ?>
                <option value="<?php echo esc_attr($term->term_id); ?>" <?php echo (array_search($term->term_id, $feed_categories) !== false ? 'selected="selected"' : ''); ?>>
                  <?php esc_html_e($term->name . ' (' . $term->slug . ')', CROWDAA_SYNC_PLUGIN_NAME); ?>
                </option>
              <?php } ?>
            </select>
          </label>
        </div>
        <?php wp_nonce_field('crowdaa_set_feed_categories_data', 'crowdaa_set_feed_categories'); ?>
        <?php
        submit_button('Save feed categories');
        ?>
      </form>
    </div>

    <hr />

    <?php do_action('crowdaa_sync_configuration_custom'); ?>

    <h2><?php esc_html_e('Synchronization', CROWDAA_SYNC_PLUGIN_NAME); ?></h2>
    <p>
      <?php
      $missing_settings = $default_image_url ? [] : [esc_html__('Default image', CROWDAA_SYNC_PLUGIN_NAME)];
      $missing_settings = apply_filters('crowdaa_sync_synchronization_missing_settings', $missing_settings);
      ?>
      <?php if (count($missing_settings) > 0) { ?>
        <?php printf(esc_html__('The synchronization is not available yet, please fill the required fields above (%s).', CROWDAA_SYNC_PLUGIN_NAME), implode(', ', $missing_settings)); ?>
      <?php } else { ?>
        <?php esc_html_e('Click on "Get synchronization queue" to see which changes will be synchronized.', CROWDAA_SYNC_PLUGIN_NAME); ?><br />
        <?php esc_html_e('Click on "Get last plugin logs" to see the last 30 lines of the plugin logs.', CROWDAA_SYNC_PLUGIN_NAME); ?><br />
        <?php esc_html_e('Click on "Synchronize!" button to sync posts between Wordpress and Crowdaa CMS.', CROWDAA_SYNC_PLUGIN_NAME); ?><br />
      <?php } ?>
    </p>
    <?php if (count($missing_settings) === 0) { ?>
      <div id="sync-opqueue-button" class="button button-primary"><?php esc_html_e('Get synchronization queue', CROWDAA_SYNC_PLUGIN_NAME); ?></div>
      <div id="sync-tail-logs-button" class="button button-primary"><?php esc_html_e('Get last plugin logs', CROWDAA_SYNC_PLUGIN_NAME); ?></div>
      <div id="sync-button" class="button button-primary"><?php esc_html_e('Synchronize!', CROWDAA_SYNC_PLUGIN_NAME); ?></div>
      <div id="sync-results">
        <div class="loader"></div>
        <div id="opqueue"></div>
      </div>
    <?php } ?>

  <?php } ?>
</div>