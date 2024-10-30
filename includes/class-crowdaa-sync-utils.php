<?php

include_once(ABSPATH . 'wp-admin/includes/plugin.php');

/**
 * This class manage plugin logs.
 *
 * @link       https://crowdaa.com
 * @since      1.0.0
 *
 * @package    Crowdaa-Sync
 * @subpackage Crowdaa-Sync/includes
 * @author     Crowdaa <contact@crowdaa.com>
 */
class Crowdaa_Sync_Utils
{
  /**
   * Quick database SELECT query
   */
  public static function quick_select($table, $where = [], $fields = '*')
  {
    global $wpdb;

    if (is_array($fields)) {
      $fields = implode(', ', $fields);
    }

    $whereStr = [];
    $whereArgs = [];
    foreach ($where as $k => $v) {
      if (is_integer($v)) {
        $whereStr[] = $k . ' = %d';
      } else if (is_float($v)) {
        $whereStr[] = $k . ' = %f';
      } else {
        $whereStr[] = $k . ' = %s';
      }
      $whereArgs[] = $v;
    }

    $whereStr = implode(' AND ', $whereStr);
    if (count($whereArgs) > 0) {
      $sqlQuery = $wpdb->prepare("SELECT $fields FROM `$table` WHERE $whereStr", ...$whereArgs);
    } else {
      $sqlQuery = "SELECT $fields FROM `$table`";
    }

    $result = $wpdb->get_results($sqlQuery, OBJECT_K);

    return ($result);
  }

  public static function quick_select_first($table, $where = [], $fields = '*')
  {
    $results = self::quick_select($table, $where, $fields);

    if (count($results) > 0) {
      return (array_shift($results));
    }

    return (null);
  }

  /**
   * Quick database INSERT query
   */
  public static function quick_insert($table, $data = [], $format = null)
  {
    global $wpdb;

    $insCount = $wpdb->insert($table, $data, ($format ? $format : self::wpdb_formats_for(array_values($data))));

    return ($insCount !== false ? $wpdb->insert_id : null);
  }

  /**
   * Quick database UPDATE query
   */
  public static function quick_update($table, $where, $update, $whereFormat = null, $updateFormat = null)
  {
    global $wpdb;

    if (!$updateFormat) $updateFormat = self::wpdb_formats_for(array_values($update));
    if (!$whereFormat) $whereFormat = self::wpdb_formats_for(array_values($where));

    $updCount = $wpdb->update(
      $table,
      $update,
      $where,
      $updateFormat,
      $whereFormat
    );

    return ($updCount);
  }

  /**
   * Quick database DELETE query
   */
  public static function quick_delete($table, $data = [], $format = null)
  {
    global $wpdb;

    $wpdb->delete($table, $data, ($format ? $format : self::wpdb_formats_for(array_values($data))));
  }

  /**
   * Quick custom query
   */
  public static function quick_query($sqlQuery, $args = [])
  {
    global $wpdb;

    if (count($args) > 0) {
      $sqlQuery = $wpdb->prepare($sqlQuery, ...$args);
    }

    $wpdb->query($sqlQuery);
  }

  /**
   * Returns database prefix without having to fetch $wpdb
   */
  public static function db_prefix()
  {
    global $wpdb;

    return ($wpdb->prefix);
  }

  /**
   * Returns an array of either %d, %f or %s corresponding to the types in the $fields array.
   */
  public static function wpdb_formats_for($fields)
  {
    $ret = [];

    foreach ($fields as $v) {
      if (is_integer($v)) {
        $ret[] = '%d';
      } else if (is_float($v)) {
        $ret[] = '%f';
      } else {
        $ret[] = '%s';
      }
    }

    return ($ret);
  }

  /**
   * Checks whether a plugin is installed. Returns true or false.
   */
  public static function have_plugin($name, $file = false)
  {
    if ($file) {
      $path = "$name/$file.php";
    } else {
      $path = "$name/$name.php";
    }
    return (is_plugin_active($path));
  }

  /**
   * Extract field from array of object to an array of this field.
   */
  public static function object_array_extract_field($field, $array)
  {
    $ret = [];
    foreach ($array as $item) {
      $ret[] = $item->$field;
    }
    return ($ret);
  }

  /**
   * Generates and returns a new token with the given length
   */
  public static function random_token($length)
  {
    $alphabet = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ1234567890-_';
    $pass = '';
    $alphaLength = strlen($alphabet) - 1;
    while (strlen($pass) < $length) {
      $c = rand(0, $alphaLength);
      $pass .= $alphabet[$c];
    }
    return $pass;
  }

  /**
   * Generates and returns a new Crowdaa API token
   */
  public static function generate_plugin_api_key()
  {
    $internalApiToken = self::random_token(97);
    update_option('crowdaa_plugin_api_key', $internalApiToken);

    return ($internalApiToken);
  }

  /**
   * Returns the current Crowdaa API token
   */
  public static function get_plugin_api_key()
  {
    $apiKey = get_option('crowdaa_plugin_api_key', null);
    if (!$apiKey) {
      $apiKey = self::generate_plugin_api_key();
    }

    return ($apiKey);
  }

  /**
   * Checks if the current execution was called from the Crowdaa API
   */
  public static function is_crowdaa_api_request()
  {
    if (!isset($_SERVER['HTTP_X_CROWDAA_API_KEY'])) {
      return (false);
    }

    $clientApiKey = $_SERVER['HTTP_X_CROWDAA_API_KEY'];
    $ourApiKey = self::get_plugin_api_key();

    return ($clientApiKey === $ourApiKey);
  }
}
