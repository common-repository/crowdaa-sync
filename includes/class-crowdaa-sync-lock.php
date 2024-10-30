<?php

/**
 * This class manage plugin sync lock to avoid
 * executing the synchronization more than once at a time.
 *
 * @link       https://crowdaa.com
 * @since      1.0.0
 *
 * @package    Crowdaa-Sync
 * @subpackage Crowdaa-Sync/includes
 * @author     Crowdaa <contact@crowdaa.com>
 */
class Crowdaa_Sync_Lock
{
  private $_owned             = false;

  private $_lockFile          = null;
  private $_lockFilePointer   = null;

  private static $_sync_lock  = null;

  public static function sync_lock($wait = false, $maxWaitTime = null)
  {
    if (!self::$_sync_lock) {
      self::$_sync_lock = new Crowdaa_Sync_Lock('_sync_lock');
    }

    return (self::$_sync_lock->acquire($wait, $maxWaitTime));
  }

  public static function sync_unlock($wait = false, $maxWaitTime = null)
  {
    if (!self::$_sync_lock) {
      self::$_sync_lock = new Crowdaa_Sync_Lock('_sync_lock');
    }

    return (self::$_sync_lock->release());
  }

  public function __construct($name)
  {
    $this->_lockFile = get_temp_dir() . 'crowdaa_sync_lock-' . $name . '.lock';
  }

  public function __destruct()
  {
    $this->release();
  }

  /**
   * Acquires a lock
   *
   * Returns true on success and false on failure.
   * Could be told to wait (block) and if so for a max amount of seconds or return false right away.
   *
   * @param bool $wait
   * @param null $maxWaitTime
   * @return bool
   * @throws \Exception
   */
  public function acquire($wait = false, $maxWaitTime = null)
  {
    $this->_lockFilePointer = fopen($this->_lockFile, 'c');
    if (!$this->_lockFilePointer) {
      throw new \RuntimeException(__('Unable to create lock file', 'dliCore'));
    }

    if ($wait && $maxWaitTime === null) {
      $flags = LOCK_EX;
    } else {
      $flags = LOCK_EX | LOCK_NB;
    }

    $startTime = time();

    while (1) {
      if (flock($this->_lockFilePointer, $flags)) {
        $this->_owned = true;
        return (true);
      } else {
        if ($maxWaitTime === null || time() - $startTime > $maxWaitTime) {
          fclose($this->_lockFilePointer);
          $this->_lockFilePointer = null;
          return (false);
        }
        sleep(1);
      }
    }
  }

  /**
   * Releases the lock
   */
  public function release()
  {
    if ($this->_owned) {
      @unlink($this->_lockFile);
      if ($this->_lockFilePointer) {
        @flock($this->_lockFilePointer, LOCK_UN);
        @fclose($this->_lockFilePointer);
        $this->_lockFilePointer = null;
      }
      $this->_owned = false;
    }
  }
}
