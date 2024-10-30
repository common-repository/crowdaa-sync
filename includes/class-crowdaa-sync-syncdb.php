<?php

require_once(ABSPATH . 'wp-admin/includes/upgrade.php');

/**
 * This class allows management of custom synchronizations between specific data (plugin data for example) and the API
 *
 * @package    Crowdaa_Sync_Syncdb
 * @subpackage Crowdaa_Sync_Syncdb/includes
 * @author     Crowdaa <contact@crowdaa.com>
 */
class Crowdaa_Sync_Syncdb
{
  private $tableName = null;
  private $version = null;
  private static $loaded = array();

  /**
   * Initialize the database.
   */
  public function __construct($tableName, $version = '1')
  {
    global $wpdb;
    $this->tableName = "$wpdb->prefix" . "crowdaa_sync_$tableName";
    $this->version = $version;

    if (!isset(self::$loaded[$this->tableName])) {
      self::$loaded[$this->tableName] = true;

      $charset_collate = $wpdb->get_charset_collate();
      $sql = "CREATE TABLE $this->tableName (
        id INT(8) UNSIGNED NOT NULL AUTO_INCREMENT,
        wp_id VARCHAR(255) NOT NULL,
        api_id VARCHAR(255) NOT NULL,
        sync_version VARCHAR(64) NOT NULL,
        sync_time DATETIME NOT NULL,
        sync_data BLOB NOT NULL DEFAULT '',
        PRIMARY KEY  (id),
		    UNIQUE KEY `wp_id` (`wp_id`),
		    UNIQUE KEY `api_id` (`api_id`)
      ) $charset_collate;";

      dbDelta($sql);

      if ($wpdb->get_var("SHOW TABLES LIKE '$this->tableName'") != $this->tableName) {
        throw new Exception('Crowdaa_Sync_Syncdb error : Could not create table ' . $this->tableName);
      }
    }
  }

  /**
   * Returns an assoc array of synced elements, with the provided fields (null means all)
   */
  public function get_synced_entries($fields = null)
  {
    global $wpdb;

    $fields = self::prepare_fields($fields);

    $result = $wpdb->get_results("SELECT $fields FROM `$this->tableName`", OBJECT_K);

    self::unserialize_data($result);

    return ($result);
  }

  /**
   * Create a new synced entry, marking it as synced.
   */
  public function create_entry($wp_id, $api_id, $sync_data = array())
  {
    global $wpdb;

    $wpdb->insert(
      $this->tableName,
      array(
        'wp_id' => $wp_id,
        'api_id' => $api_id,
        'sync_time' => current_time('mysql'),
        'sync_version' => $this->version,
        'sync_data' => serialize($sync_data),
      ),
    );
  }

  /**
   * Update an existing synced entry, marking it as synced.
   */
  public function update_entry($where, $update)
  {
    global $wpdb;

    $update['sync_time'] = current_time('mysql');
    $update['sync_version'] = $this->version;

    if (isset($update['sync_data'])) {
      $update['sync_data'] = serialize($update['sync_data']);
    }

    $wpdb->update(
      $this->tableName,
      $update,
      $where,
    );
  }

  /**
   * Delete an existing synced entry.
   */
  public function delete_entry($where, $format = null)
  {
    global $wpdb;

    if ($format === null) {
      $format = [];
      foreach ($where as $itm) {
        if (is_integer($itm)) {
          $format[] = '%d';
        } else if (is_float($itm)) {
          $format[] = '%f';
        } else {
          $format[] = '%s';
        }
      }
    }

    $wpdb->delete(
      $this->tableName,
      $where,
      $format
    );
  }

  /**
   * Delete the whole table.
   */
  public function reset($drop = false)
  {
    global $wpdb;

    if ($drop) {
      $wpdb->query("DROP TABLE `$this->tableName`");
    } else {
      $wpdb->query("DELETE FROM `$this->tableName`");
    }
  }

  private static function unserialize_data(&$results)
  {
    foreach ($results as &$v) {
      if (isset($v->sync_data)) {
        $v->sync_data = unserialize($v->sync_data);
      }
    }
  }

  private static function prepare_fields($fields)
  {
    if (is_array($fields) && count($fields) > 0) {
      $fields = implode(', ', $fields);
    } else {
      $fields = '*';
    }

    return ($fields);
  }

  private static function prepare_wherein($whereData, $whereField)
  {
    $whereStr = '';
    $whereArg = [];
    if (is_array($whereData)) {
      $whereINQuery = implode(', ', array_fill(0, count($whereData), '%s'));
      $whereStr = $whereField . ' in (' . $whereINQuery . ')';
      $whereArg = $whereData;
    } else {
      $whereStr = $whereField . ' = %s';
      $whereArg = [$whereData];
    }

    return ([$whereStr, $whereArg]);
  }

  /**
   * Get an entry with the specified API ID.
   */
  public function get_entry_with_api_id($api_id, $fields = null)
  {
    global $wpdb;

    list($whereStr, $whereArg) = self::prepare_wherein($api_id, 'api_id');
    $fields = self::prepare_fields($fields);

    $result = $wpdb->get_results($wpdb->prepare("SELECT $fields FROM `$this->tableName` WHERE $whereStr", ...$whereArg), OBJECT_K);

    self::unserialize_data($result);

    return (array_pop($result));
  }

  /**
   * Get an entry with the specified WP ID.
   */
  public function get_entry_with_wp_id($wp_id, $fields = null)
  {
    global $wpdb;

    list($whereStr, $whereArg) = self::prepare_wherein($wp_id, 'wp_id');
    $fields = self::prepare_fields($fields);

    $result = $wpdb->get_results($wpdb->prepare("SELECT $fields FROM `$this->tableName` WHERE $whereStr", ...$whereArg), OBJECT_K);

    self::unserialize_data($result);

    return (array_pop($result));
  }

  /**
   * Get entries with the specified WP IDs (as an araray).
   */
  public function get_entries_with_wp_ids($wp_ids, $fields = null)
  {
    global $wpdb;

    list($whereStr, $whereArg) = self::prepare_wherein($wp_ids, 'wp_id');
    $fields = self::prepare_fields($fields);

    $result = $wpdb->get_results($wpdb->prepare("SELECT $fields FROM `$this->tableName` WHERE $whereStr", ...$whereArg), OBJECT_K);

    self::unserialize_data($result);

    return ($result);
  }

  /**
   * Get entries with the specified API IDs (as an araray).
   */
  public function get_entries_with_api_ids($api_ids, $fields = null)
  {
    global $wpdb;

    list($whereStr, $whereArg) = self::prepare_wherein($api_ids, 'api_id');
    $fields = self::prepare_fields($fields);

    $result = $wpdb->get_results($wpdb->prepare("SELECT $fields FROM `$this->tableName` WHERE $whereStr", ...$whereArg), OBJECT_K);

    self::unserialize_data($result);

    return ($result);
  }

  /**
   * Get an entry with the specified internal ID.
   */
  public function get_entry_with_id($id, $fields = null)
  {
    global $wpdb;

    list($whereStr, $whereArg) = self::prepare_wherein($id, 'id');
    $fields = self::prepare_fields($fields);

    $result = $wpdb->get_results($wpdb->prepare("SELECT $fields FROM `$this->tableName` WHERE $whereStr", ...$whereArg), OBJECT_K);

    self::unserialize_data($result);

    return (array_pop($result));
  }
}
