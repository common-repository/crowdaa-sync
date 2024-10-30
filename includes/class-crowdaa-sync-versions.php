<?php

/**
 * First code to be ran, to manage versions, updates and code deployment requirements.
 *
 * @link       https://crowdaa.com
 * @since      1.0.0
 *
 * @package    Crowdaa-Sync
 * @subpackage Crowdaa-Sync/includes
 * @author     Crowdaa <contact@crowdaa.com>
 */
class Crowdaa_Sync_Versions
{
  private static $version_categories = null;
  private static $internal_version = null;

  public static function init()
  {
    $last_version = get_option('crowdaa_last_version', false);
    if (!$last_version || $last_version < CROWDAA_SYNC_META_VERSION) {
      update_option('crowdaa_last_version', CROWDAA_SYNC_META_VERSION);
      update_option('crowdaa_sync_articles_wp_to_api_from', 0);
      update_option('crowdaa_sync_articles_api_to_wp_from', 0);
      update_option('crowdaa_sync_internal_version', '0');
    }

    self::update_versions();
  }

  private static function update_versions()
  {
    self::$internal_version      = CROWDAA_SYNC_META_VERSION . '.' . get_option('crowdaa_sync_internal_version', '0');
  }

  public static function bump_version()
  {
    $ver = get_option('crowdaa_sync_internal_version', '0');
    $ver = (int) $ver;
    $ver++;
    update_option('crowdaa_sync_internal_version', "$ver");

    update_option('crowdaa_sync_articles_wp_to_api_from', 0);
    update_option('crowdaa_sync_articles_api_to_wp_from', 0);

    self::update_versions();
  }

  public static function get_version()
  {
    return (self::$internal_version);
  }
}
