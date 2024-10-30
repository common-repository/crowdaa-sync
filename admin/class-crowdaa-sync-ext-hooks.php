<?php

/**
 * This file add most wordpress hooks to catch
 * events that matter to us.
 *
 * @link       https://crowdaa.com
 * @since      1.0.0
 *
 * @package    Crowdaa-Sync
 * @subpackage Crowdaa-Sync/admin
 * @author     Crowdaa <contact@crowdaa.com>
 */
class Crowdaa_Sync_Ext_Hooks
{
  /**
   * Initialize the class and set its properties.
   *
   * @since    1.0.0
   */
  public function __construct()
  {
    add_filter('jwt_auth_token_before_dispatch', [$this, 'jwt_auth_token_before_dispatch'], 10, 2);
    add_filter('jwt_auth_expire', [$this, 'crowdaa_jwt_auth_expire'], 10, 2);
  }

  /**
   * Sets the new jwt_auth_expires
   *
   * @since    1.0.0
   */
  public function crowdaa_jwt_auth_expire($expire, $issuedAt)
  {
    return ($issuedAt + (DAY_IN_SECONDS * 365));
  }

  /**
   * Add and return an autologin code for the user if not defined
   *
   * @since    1.0.0
   * @param $post_id
   * @return void
   */
  public function jwt_auth_token_before_dispatch($data, $user)
  {
    if (Crowdaa_Sync_Utils::is_crowdaa_api_request()) {
      $data['user_id'] = $user->data->ID;
      if (
        function_exists('pkg_autologin_generate_code') &&
        defined('PKG_AUTOLOGIN_STAGED_CODE_USER_META_KEY') &&
        defined('PKG_AUTOLOGIN_USER_META_KEY')
      ) {
        $code = get_user_meta($user->data->ID, PKG_AUTOLOGIN_USER_META_KEY, True);
        if (!$code) {
          $code = pkg_autologin_generate_code();
          update_user_meta($user->data->ID, PKG_AUTOLOGIN_USER_META_KEY, $code);
        }
        $data['autologin_token'] = '' . $code;
      }

      if (Crowdaa_Sync_Permissions::plugin_get()) {
        $user_memberships_ids = Crowdaa_Sync_Permissions::get_user_perms($user->data->ID);

        if (count($user_memberships_ids) > 0) {
          $sync_db = Crowdaa_Sync_Permissions::sync_db();
          $synced = $sync_db->get_entries_with_wp_ids($user_memberships_ids, 'api_id');
          $api_ids = Crowdaa_Sync_Utils::object_array_extract_field('api_id', $synced);
          $data['user_badges'] = $api_ids;
        }
      }
    }

    return ($data);
  }
}
