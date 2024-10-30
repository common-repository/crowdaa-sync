<?php

/**
 * Fired during plugin activation.
 *
 * This class defines all code necessary to run during the plugin's activation.
 *
 * @link       https://crowdaa.com
 * @since      1.0.0
 *
 * @package    Crowdaa-Sync
 * @subpackage Crowdaa-Sync/includes
 * @author     Crowdaa <contact@crowdaa.com>
 */
class Crowdaa_Sync_Activator
{
  /**
   * Short Description. (use period)
   *
   * Long Description.
   *
   * @since    1.0.0
   */
  public static function activate()
  {
    try {
      $wp_upload_dir = wp_upload_dir();
      $upload_dir_path = $wp_upload_dir['basedir'] . '/' . 'catalogue_images';
      if (!file_exists($upload_dir_path)) {
        mkdir($upload_dir_path, 0777, true);
      }
    } catch (\Throwable $e) {
      print_r($e->getMessage());
      return false;
    }
  }
}
