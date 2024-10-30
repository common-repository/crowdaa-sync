<?php

/**
 * Define general exceptions used by this plugin
 *
 * @link       https://crowdaa.com
 * @since      1.0.0
 *
 * @package    Crowdaa-Sync
 * @subpackage Crowdaa-Sync/includes
 */

/**
 * The base project exception, when a fatal error occurs.
 *
 * @link       https://crowdaa.com
 * @since      1.0.0
 *
 * @package    Crowdaa-Sync
 * @subpackage Crowdaa-Sync/includes
 * @author     Crowdaa <contact@crowdaa.com>
 */
class Crowdaa_Sync_Error extends Exception
{
}

/**
 * An exception to handle errors on a single badge synchronzation, requesting to abort.
 *
 * @since      1.0.0
 * @package    Crowdaa-Sync
 * @subpackage Crowdaa-Sync/includes
 * @author     Crowdaa <contact@crowdaa.com>
 */
class Crowdaa_Sync_Badge_Error extends Exception
{
}

/**
 * An exception to handle errors on a single category synchronzation, requesting to abort.
 *
 * @since      1.0.0
 * @package    Crowdaa-Sync
 * @subpackage Crowdaa-Sync/includes
 * @author     Crowdaa <contact@crowdaa.com>
 */
class Crowdaa_Sync_Category_Error extends Exception
{
}

/**
 * An exception to handle errors on a single post synchronzation, requesting to skip it.
 *
 * @since      1.0.0
 * @package    Crowdaa-Sync
 * @subpackage Crowdaa-Sync/includes
 * @author     Crowdaa <contact@crowdaa.com>
 */
class Crowdaa_Sync_Post_Error extends Exception
{
}

/**
 * An exception to handle unrecoverable error on articles that we want to skip and avoid syncing.
 *
 * @since      1.0.0
 * @package    Crowdaa-Sync
 * @subpackage Crowdaa-Sync/includes
 * @author     Crowdaa <contact@crowdaa.com>
 */
class Crowdaa_Sync_Post_Skip_Error extends Crowdaa_Sync_Post_Error
{
}

/**
 * An exception to handle timeout errors during synchronization.
 *
 * @since      1.0.0
 * @package    Crowdaa-Sync
 * @subpackage Crowdaa-Sync/includes
 * @author     Crowdaa <contact@crowdaa.com>
 */
class Crowdaa_Sync_Timeout_Error extends Exception
{
}
