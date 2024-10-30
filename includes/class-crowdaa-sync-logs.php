<?php

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
class Crowdaa_Sync_Logs
{
  private static $log_file = CROWDAA_SYNC_PLUGIN_DIR . '/logs.txt';

  /**
   * Logs the provided informations
   *
   * @since    1.0.0
   */
  public static function log($description, ...$extra)
  {
    $fd = fopen(self::$log_file, 'a+');

    $to_write = 'CrowdaaSync [' . date('Y-m-d H:i:s') . '] ' . $description;

    if ($extra) {
      foreach ($extra as $item) {
        if (!is_string($item)) {
          $to_write .= ' - ' . print_r($item, true);
        } else {
          $to_write .= ' - ' . $item;
        }
      }
    }

    $to_write .= "\r\n";

    fwrite($fd, $to_write);
    fclose($fd);
  }

  /**
   * Clean logs (truncates the file to 0 bytes)
   *
   * @since    1.0.0
   */
  public static function clear()
  {
    $fd = fopen(self::$log_file, 'a+');
    ftruncate($fd, 0);
    fclose($fd);

    self::log('Cleared logs');
  }

  /**
   * Returns $lines lines from the end of the file.
   *
   * @since    1.0.0
   */
  public static function tail($lines = 10)
  {
    $fd = fopen(self::$log_file, "r");
    if (!$fd) {
      return ([]);
    }
    $linecounter = $lines;
    $pos = -2;
    $beginning = false;
    $ret = array();
    while ($linecounter > 0) {
      do {
        if (fseek($fd, $pos, SEEK_END) === -1) {
          rewind($fd);
          $linecounter = 0;
          break;
        }
        $t = fgetc($fd);
        $pos--;
      } while ($t !== "\n");

      $ret[] = fgets($fd);
      $linecounter--;
    }
    fclose($fd);
    return ($ret);
  }
}
