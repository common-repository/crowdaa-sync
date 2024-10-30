<?php

/**
 * This class checks the execution time of a script, extends it if it can,
 * and throws an error if we are too close to the end.
 *
 * @link       https://crowdaa.com
 * @since      1.0.0
 *
 * @package    Crowdaa-Sync
 * @subpackage Crowdaa-Sync/includes
 * @author     Crowdaa <contact@crowdaa.com>
 */
class Crowdaa_Sync_Timer
{
  private static $ini_was_set = false;
  private const MARGIN_BEFORE_TIMEOUT = 120;
  private static $start = 0;
  private static $sync_max_duration = 0;

  /**
   * Initialize the script start time, if not already set.
   *
   * @since    1.0.0
   */
  public static function init()
  {
    self::$start = microtime(true);
  }

  /**
   * Checks for a close timeout, and throws an error if that happens.
   *
   * @since    1.0.0
   */
  public static function prepare_ini()
  {
    if (!self::$ini_was_set) {
      self::$ini_was_set = true;
      self::$sync_max_duration = ((int)get_option('crowdaa_sync_max_duration', 10)) * 60 + self::MARGIN_BEFORE_TIMEOUT;
      if ((int)ini_get('max_execution_time') < self::$sync_max_duration) {
        @ini_set('max_execution_time', self::$sync_max_duration);
      }
    }
  }

  /**
   * Checks for a close timeout, and throws an error if that happens.
   *
   * @since    1.0.0
   */
  public static function check()
  {
    if (!self::$ini_was_set) {
      self::prepare_ini();
    }

    $execution_time = (microtime(true) - self::$start);
    $max_execution_time = min((int)ini_get('max_execution_time'), self::$sync_max_duration);
    if ($execution_time >= $max_execution_time - self::MARGIN_BEFORE_TIMEOUT) {
      Crowdaa_Sync_Logs::log('Execution time', 'script time ' . $execution_time . ' max execution time ' . $max_execution_time);
      throw new Crowdaa_Sync_Timeout_Error(__('Execution time limit. Please refresh the plugin settings page and click the sync button again', CROWDAA_SYNC_PLUGIN_NAME));
    }
  }
}
