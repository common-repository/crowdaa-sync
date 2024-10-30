<?php

require_once(ABSPATH . 'wp-admin/includes/upgrade.php');

/**
 * This class allows management of permissions management with several permission plugins
 * It is currently a work in progress and will be completed later.
 *
 * @package    Crowdaa_Sync_Permissions
 * @subpackage Crowdaa_Sync_Permissions/includes
 * @author     Crowdaa <contact@crowdaa.com>
 */
class Crowdaa_Sync_Permissions
{
  private static $all_plugins = [
    'paid-memberships-pro' => [
      'name'  => 'Paid Memberships Pro',
      'alias' => 'pmpro',
      'file'  => 'paid-memberships-pro',
    ],
    'simple-membership' => [
      'name'  => 'Simple Membership',
      'alias' => 'swpm',
      'file'  => 'simple-wp-membership',
    ],
  ];
  private static $prices = [
    'subscription_1'  => 0.99,
    'subscription_2'  => 1.99,
    'subscription_3'  => 2.99,
    'subscription_4'  => 3.99,
    'subscription_5'  => 4.99,
    'subscription_6'  => 5.99,
    'subscription_7'  => 6.99,
    'subscription_8'  => 7.99,
    'subscription_9'  => 8.99,
    'subscription_10' => 9.99,
    'subscription_11' => 10.99,
    'subscription_12' => 11.99,
    'subscription_13' => 12.99,
    'subscription_14' => 13.99,
    'subscription_15' => 14.99,
    'subscription_16' => 15.99,
    'subscription_17' => 16.99,
    'subscription_18' => 17.99,
    'subscription_19' => 18.99,
    'subscription_20' => 19.99,
    'subscription_21' => 29.99,
    'subscription_22' => 39.99,
    'subscription_23' => 49.99,
    'subscription_24' => 24.99,
  ];
  private static $plugin = null; // Used as a cache to store the current plugin


  /**
   * Get the list of available permissions plugins
   */
  public static function plugins_list()
  {
    $ret = [];

    foreach (self::$all_plugins as $plugin => $params) {
      if (Crowdaa_Sync_Utils::have_plugin($plugin, $params['file'])) {
        $ret[] = $plugin;
      }
    }

    return ($ret);
  }

  /**
   * Get the hash of available permissions plugins with names
   */
  public static function plugins_names_hash()
  {
    $ret = [];

    foreach (self::$all_plugins as $plugin => $params) {
      if (Crowdaa_Sync_Utils::have_plugin($plugin, $params['file'])) {
        $ret[$plugin] = $params['name'];
      }
    }

    return ($ret);
  }

  public static function plugin_use($plugin)
  {
    $list = self::plugins_list();
    $last = self::plugin_get();
    if ($plugin && array_search($plugin, $list) !== false) {
      self::$plugin = $plugin;
      update_option('crowdaa_sync_perm_plugin', $plugin);
    } else if (count($list) > 0) {
      self::$plugin = $list[0];
      update_option('crowdaa_sync_perm_plugin', $list[0]);
    } else {
      self::$plugin = null;
      delete_option('crowdaa_sync_perm_plugin');
    }

    if ($last !== self::$plugin) {
      self::reset();
    }
  }

  public static function plugin_get()
  {
    if (self::$plugin !== null) return (self::$plugin);

    $option = get_option('crowdaa_sync_perm_plugin', '');
    $list = self::plugins_list();

    if (count($list) === 0) {
      return (null);
    }

    if (array_search($option, $list) === false) {
      update_option('crowdaa_sync_perm_plugin', $list[0]);
      self::$plugin = $list[0];
      return ($list[0]);
    }

    self::$plugin = $option;
    return ($option);
  }

  public static function sync_db()
  {
    return (new Crowdaa_Sync_Syncdb('user_badges'));
  }

  public static function reset()
  {
    $sync_db = self::sync_db();
    $sync_db->reset();
  }

  public static function hash_badge(&$badge)
  {
    $data = [
      'name' => $badge->name,
      'description' => $badge->description,
      'access' => $badge->access,
      'management' => $badge->management,
      'createdAt' => $badge->createdAt,
    ];

    if (isset($badge->updatedAt)) {
      $data['updatedAt'] = $badge->updatedAt;
    }

    return (hash('sha256', serialize($data)));
  }

  public static function hash_permission(&$permission)
  {
    $plugin = self::plugin_get();
    if (!$plugin) throw new Crowdaa_Sync_Error('Missing plugin for Crowdaa_Sync_Permissions::hash_permission()');

    $alias = self::$all_plugins[$plugin]['alias'];
    $function = 'hash_permission__' . $alias;

    return (self::$function($permission));
  }

  private static function hash_permission__pmpro(&$membership)
  {
    $data = [
      'name'          => '' .   $membership->name,
      'description'   => '' .   $membership->description,
      'allow_signups' => (int) $membership->allow_signups,
    ];

    return (hash('sha256', serialize($data)));
  }

  private static function hash_permission__swpm(&$membership)
  {
    $data = [
      'alias' => '' . $membership->alias,
    ];

    return (hash('sha256', serialize($data)));
  }

  public static function get_perms()
  {
    $plugin = self::plugin_get();
    if (!$plugin) throw new Crowdaa_Sync_Error('Missing plugin for Crowdaa_Sync_Permissions::get_perms()');

    $alias = self::$all_plugins[$plugin]['alias'];
    $function = 'get_perms__' . $alias;

    return (self::$function());
  }

  private static function get_perms__pmpro()
  {
    global $wpdb;

    $memberships = Crowdaa_Sync_Utils::quick_select($wpdb->pmpro_membership_levels);

    $permissions_by_id = [];
    foreach ($memberships as $membership) {
      $membership->hash = self::hash_permission__pmpro($membership);
      $permissions_by_id[$membership->id] = (object) [
        'id' => $membership->id,
        'name' => $membership->name,
        'description' => $membership->description,
        'public' => !!$membership->allow_signups,
        'hash' => $membership->hash,
      ];
    }

    return ($permissions_by_id);
  }

  private static function get_perms__swpm()
  {
    $memberships = Crowdaa_Sync_Utils::quick_select(self::swpm_memberships_table());

    $permissions_by_id = [];
    foreach ($memberships as $membership) {
      if ($membership->id !== '1') {
        $membership->hash = self::hash_permission__swpm($membership);
        $permissions_by_id[$membership->id] = (object) [
          'id' => $membership->id,
          'name' => $membership->alias,
          'description' => '',
          'public' => false,
          'hash' => $membership->hash,
        ];
      }
    }

    return ($permissions_by_id);
  }

  public static function get_perms_for_term($termId)
  {
    $plugin = self::plugin_get();
    if (!$plugin) throw new Crowdaa_Sync_Error('Missing plugin for Crowdaa_Sync_Permissions::get_perms_for_term()');

    $alias = self::$all_plugins[$plugin]['alias'];
    $function = 'get_perms_for_term__' . $alias;

    return (self::$function($termId));
  }

  private static function get_perms_for_term__pmpro($termId)
  {
    global $wpdb;

    $perms = Crowdaa_Sync_Utils::quick_select($wpdb->pmpro_memberships_categories, [
      'category_id' => $termId,
    ], ['membership_id']);
    $perms_array = [];
    foreach ($perms as $perm) {
      $perms_array[] = $perm->membership_id;
    }

    return ($perms_array);
  }

  private static function get_perms_for_term__swpm($termId)
  {
    $rootMembership = self::swpm_root_membership();

    $rootCategories = self::swpm_array_unserialize($rootMembership->category_list);

    if (array_search($termId, $rootCategories) === false) {
      return ([]);
    }

    $memberships = Crowdaa_Sync_Utils::quick_select(self::swpm_memberships_table());
    $perms_array = [];
    foreach ($memberships as $membership) {
      if ($membership->id !== '1') {
        $currentCategories = self::swpm_array_unserialize($membership->category_list);

        if (array_search($termId, $currentCategories) !== false) {
          $perms_array[] = $membership->id;
        }
      }
    }

    return ($perms_array);
  }

  public static function set_perms_for_term($termId, $badges)
  {
    $plugin = self::plugin_get();
    if (!$plugin) throw new Crowdaa_Sync_Error('Missing plugin for Crowdaa_Sync_Permissions::set_perms_for_term()');

    $alias = self::$all_plugins[$plugin]['alias'];
    $function = 'set_perms_for_term__' . $alias;

    return (self::$function($termId, $badges));
  }

  private static function set_perms_for_term__pmpro($termId, $badges)
  {
    global $wpdb;

    $sync_db = self::sync_db();
    $current_memberships = Crowdaa_Sync_Utils::quick_select($wpdb->pmpro_memberships_categories, [
      'category_id' => $termId,
    ], ['membership_id']);
    $permission_hash = [];
    foreach ($current_memberships as $membership) {
      $permission_hash[$membership->membership_id] = true;
    }

    foreach ($badges as $item) {
      $sync_obj = $sync_db->get_entry_with_api_id($item->id, 'wp_id');

      if (!isset($permission_hash[$sync_obj->wp_id])) {
        Crowdaa_Sync_Utils::quick_insert(
          $wpdb->pmpro_memberships_categories,
          [
            'membership_id' => $sync_obj->wp_id,
            'category_id' => $termId,
          ]
        );
      } else {
        unset($permission_hash[$sync_obj->wp_id]);
      }
    }

    foreach ($permission_hash as $membership_id => $true) {
      Crowdaa_Sync_Utils::quick_delete($wpdb->pmpro_memberships_categories, [
        'category_id' => $termId,
        'membership_id' => $membership_id,
      ]);
    }
  }

  private static function set_perms_for_term__swpm($termId, $badges)
  {
    $currentPerms = self::get_perms_for_term__swpm($termId);
    $currentPermsHash = [];
    foreach ($currentPerms as $id) {
      $currentPermsHash[$id] = true;
    }

    $sync_db = self::sync_db();
    $memberships = Crowdaa_Sync_Utils::quick_select(self::swpm_memberships_table());
    $membershipsHash = [];
    foreach ($memberships as $membership) {
      $membership->category_list = self::swpm_array_unserialize($membership->category_list);
      $membershipsHash[$membership->id] = $membership;
    }

    foreach ($badges as $item) {
      $sync_obj = $sync_db->get_entry_with_api_id($item->id, 'wp_id');

      if (!isset($currentPermsHash[$sync_obj->wp_id])) {
        foreach ([1, $sync_obj->wp_id] as $membershipId) {
          if (array_search($termId, $membershipsHash[$membershipId]->category_list) === false) {
            $membershipsHash[$membershipId]->category_list[] = $termId;
            Crowdaa_Sync_Utils::quick_update(
              self::swpm_memberships_table(),
              ['id' => $membershipId],
              ['category_list' => self::swpm_array_serialize($membershipsHash[$membershipId]->category_list)]
            );
          }
        }
      } else {
        unset($currentPermsHash[$sync_obj->wp_id]);
      }
    }

    foreach ($currentPermsHash as $membershipId => $true) {
      $membershipsHash[$membershipId]->category_list = array_diff(
        $membershipsHash[$membershipId]->category_list,
        [$termId]
      );

      Crowdaa_Sync_Utils::quick_update(
        self::swpm_memberships_table(),
        ['id' => $membershipId],
        ['category_list' => self::swpm_array_serialize($membershipsHash[$membershipId]->category_list)]
      );

      $found = false;
      foreach ($membershipsHash as $currentId => $membership) {
        if ($currentId === $membershipId || intval($currentId, 10) === 1) {
          continue;
        }

        if (array_search($termId, $membershipsHash[$currentId]->category_list) !== false) {
          $found = true;
          break;
        }
      }

      if (!$found) {
        $membershipsHash[1]->category_list = array_diff(
          $membershipsHash[1]->category_list,
          [$termId]
        );

        Crowdaa_Sync_Utils::quick_update(
          self::swpm_memberships_table(),
          ['id' => 1],
          ['category_list' => self::swpm_array_serialize($membershipsHash[1]->category_list)]
        );
      }
    }
  }

  public static function create_perm($name, $description, $public)
  {
    $plugin = self::plugin_get();
    if (!$plugin) throw new Crowdaa_Sync_Error('Missing plugin for Crowdaa_Sync_Permissions::create_perm()');

    $alias = self::$all_plugins[$plugin]['alias'];
    $function = 'create_perm__' . $alias;

    return (self::$function($name, $description, $public));
  }

  private static function create_perm__pmpro($name, $description, $public)
  {
    global $wpdb;

    $fields = [
      'name' => $name,
      'description' => $description,
      'allow_signups' => $public ? 1 : 0,
      'initial_payment' => 0.0,
      'billing_amount' => 0.0,
    ];

    $insId = Crowdaa_Sync_Utils::quick_insert(
      $wpdb->pmpro_membership_levels,
      $fields
    );

    if (!$insId) {
      return (null);
    }

    $perm = Crowdaa_Sync_Utils::quick_select_first($wpdb->pmpro_membership_levels, ['id' => $insId]);

    return ($perm);
  }

  private static function create_perm__swpm($alias, $description_unused, $public_unused)
  {
    $fields = [
      'alias'                      => $alias,
      'role'                       => 'subscriber',
      'category_list'              => self::swpm_array_serialize([]),
      'page_list'                  => self::swpm_array_serialize([]),
      'post_list'                  => self::swpm_array_serialize([]),
      'comment_list'               => self::swpm_array_serialize([]),
      'attachment_list'            => self::swpm_array_serialize([]),
      'custom_post_list'           => self::swpm_array_serialize([]),

      'permissions'                => 0,
      'subscription_period'        => '',
      'subscription_duration_type' => 0,
      'protect_older_posts'        => 0,
      'campaign_name'              => '',
    ];

    $insId = Crowdaa_Sync_Utils::quick_insert(
      self::swpm_memberships_table(),
      $fields
    );

    if (!$insId) {
      return (null);
    }

    $perm = Crowdaa_Sync_Utils::quick_select_first(self::swpm_memberships_table(), ['id' => $insId]);

    return ($perm);
  }

  public static function update_perm($id, $name, $description, $public)
  {
    $plugin = self::plugin_get();
    if (!$plugin) throw new Crowdaa_Sync_Error('Missing plugin for Crowdaa_Sync_Permissions::update_perm()');

    $alias = self::$all_plugins[$plugin]['alias'];
    $function = 'update_perm__' . $alias;

    return (self::$function($id, $name, $description, $public));
  }

  private static function update_perm__pmpro($id, $name, $description, $public)
  {
    global $wpdb;

    $fields = [
      'name' => $name,
      'description' => $description,
      'allow_signups' => $public ? 1 : 0,
    ];
    $where = ['id' => $id];

    $updCount = Crowdaa_Sync_Utils::quick_update(
      $wpdb->pmpro_membership_levels,
      $where,
      $fields
    );

    if ($updCount === false) {
      return (null);
    }

    $perm = Crowdaa_Sync_Utils::quick_select_first($wpdb->pmpro_membership_levels, $where);

    return ($perm);
  }

  private static function update_perm__swpm($id, $alias, $description_unused, $public_unused)
  {
    global $wpdb;

    $fields = [
      'alias' => $alias,
    ];
    $where = ['id' => $id];

    $updCount = Crowdaa_Sync_Utils::quick_update(
      self::swpm_memberships_table(),
      $where,
      $fields
    );

    if ($updCount === false) {
      return (null);
    }

    $perm = Crowdaa_Sync_Utils::quick_select_first(self::swpm_memberships_table(), $where);

    return ($perm);
  }

  public static function delete_perm($id)
  {
    $plugin = self::plugin_get();
    if (!$plugin) throw new Crowdaa_Sync_Error('Missing plugin for Crowdaa_Sync_Permissions::delete_perm()');

    $alias = self::$all_plugins[$plugin]['alias'];
    $function = 'delete_perm__' . $alias;

    return (self::$function($id));
  }

  private static function delete_perm__pmpro($id)
  {
    global $wpdb;

    $where = ['id' => $id];
    Crowdaa_Sync_Utils::quick_delete($wpdb->pmpro_membership_levels, $where);

    $where = ['membership_id' => $id];
    Crowdaa_Sync_Utils::quick_delete($wpdb->pmpro_membership_orders, $where);
    Crowdaa_Sync_Utils::quick_delete($wpdb->pmpro_memberships_categories, $where);
    Crowdaa_Sync_Utils::quick_delete($wpdb->pmpro_memberships_pages, $where);
    Crowdaa_Sync_Utils::quick_delete($wpdb->pmpro_memberships_users, $where);

    $where = ['pmpro_membership_level_id' => $id];
    Crowdaa_Sync_Utils::quick_delete($wpdb->pmpro_membership_levelmeta, $where);
  }

  private static function delete_perm__swpm($id)
  {
    $where = ['id' => $id];

    Crowdaa_Sync_Utils::quick_delete(self::swpm_memberships_table(), $where);
  }

  public static function get_user_perms($userId)
  {
    $plugin = self::plugin_get();
    if (!$plugin) throw new Crowdaa_Sync_Error('Missing plugin for Crowdaa_Sync_Permissions::get_user_perms()');

    $alias = self::$all_plugins[$plugin]['alias'];
    $function = 'get_user_perms__' . $alias;

    return (self::$function($userId));
  }

  private static function get_user_perms__pmpro($userId)
  {
    global $wpdb;

    $user_memberships = Crowdaa_Sync_Utils::quick_select($wpdb->pmpro_memberships_users, [
      'user_id' => $userId,
      'status' => 'active',
    ], 'membership_id');

    $memberships_ids = Crowdaa_Sync_Utils::object_array_extract_field('membership_id', $user_memberships);

    return ($memberships_ids);
  }

  private static function get_user_perms__swpm($userId)
  {
    $user = get_user_by('id', $userId);
    $memberships = Crowdaa_Sync_Utils::quick_select(self::swpm_members_table(), [
      'user_name'     => $user->data->user_login,
      'account_state' => 'active',
    ], 'membership_level');

    $memberships_ids = Crowdaa_Sync_Utils::object_array_extract_field('membership_level', $memberships);

    return ($memberships_ids);
  }

  public static function set_user_perms($userId, $badges)
  {
    $plugin = self::plugin_get();
    if (!$plugin) throw new Crowdaa_Sync_Error('Missing plugin for Crowdaa_Sync_Permissions::set_user_perms()');

    $alias = self::$all_plugins[$plugin]['alias'];
    $function = 'set_user_perms__' . $alias;

    self::$function($userId, $badges);
  }

  private static function set_user_perms__pmpro($userId, $badges)
  {
    global $wpdb;
    $sync_db = self::sync_db();
    $syncedIds = $sync_db->get_entries_with_api_ids($badges, 'wp_id');
    $syncedIds = Crowdaa_Sync_Utils::object_array_extract_field('wp_id', $syncedIds);

    $user = get_user_by('id', $userId);
    $memberships = Crowdaa_Sync_Utils::quick_select($wpdb->pmpro_memberships_users, [
      'user_id' => $userId,
      'status' => 'active',
    ], 'id');
    $membershipsIds = Crowdaa_Sync_Utils::object_array_extract_field('membership_id', $memberships);

    $toAdd = [];
    $toDelete = [];

    foreach ($syncedIds as $id) {
      if (array_search($id, $membershipsIds) === false) {
        $toAdd[] = $id;
      }
    }

    foreach ($membershipsIds as $id) {
      if (array_search($id, $syncedIds) === false) {
        $toDelete[] = $id;
      }
    }

    $user_registered = explode(' ', $user->data->user_registered);
    foreach ($toAdd as $id) {
      Crowdaa_Sync_Utils::quick_insert($wpdb->pmpro_memberships_users, [
        'user_id' => $userId,
        'status' => 'active',
        'membership_id' => $id,
        'code_id' => 0,
        'initial_payment' => 0.0,
        'billing_amount' => 0.0,
        'cycle_number' => 0,
        'billing_limit' => 0,
        'trial_amount' => 0.0,
        'trial_limit' => 0,
        'status' => 'active',
        'startdate' => $user_registered[0],
      ]);
    }

    foreach ($toDelete as $id) {
      Crowdaa_Sync_Utils::quick_delete($wpdb->pmpro_memberships_users, [
        'user_id'       => $userId,
        'status'        => 'active',
        'membership_id' => $id,
      ]);
    }
  }

  private static function set_user_perms__swpm($userId, $badges)
  {
    $user = get_user_by('id', $userId);
    $sync_db = self::sync_db();

    $memberships = Crowdaa_Sync_Utils::quick_select(self::swpm_members_table(), [
      'username'      => $user->data->user_login,
      'account_state' => 'active',
    ]);

    if (count($badges) > 0 && count($memberships) > 0) {
      $syncedIds = $sync_db->get_entries_with_api_ids($badges, 'wp_id');
      if (count($syncedIds) > 0) {
        $firstSyncedId = $syncedIds[0]->wp_id;
        Crowdaa_Sync_Utils::quick_update(self::swpm_members_table(), [
          'member_id' => $memberships->member_id,
        ], [
          'membership_level' => $firstSyncedId,
        ]);
      }
    } else if (count($badges) == 0 && count($memberships) > 0) {
      Crowdaa_Sync_Utils::quick_delete(self::swpm_members_table(), [
        'member_id' => $memberships->member_id,
      ]);
    } else if (count($badges) > 0 && count($memberships) == 0) {
      $user_registered = explode(' ', $user->data->user_registered);

      Crowdaa_Sync_Utils::quick_insert(self::swpm_members_table(), [
        "user_name"              => $user->user_login,
        "first_name"             => '',
        "last_name"              => '',
        "password"               => $user->user_pass,
        "member_since"           => $user_registered[0],
        "membership_level"       => 2,
        "more_membership_levels" => NULL,
        "account_state"          => 'active',
        "last_accessed_from_ip"  => '',
        "email"                  => $user->data->user_email,
        "phone"                  => '',
        "address_street"         => '',
        "address_city"           => '',
        "address_state"          => '',
        "address_zipcode"        => '',
        "country"                => '',
        "gender"                 => 'not specified',
        "subscription_starts"    => $user_registered[0],
        "txn_id"                 => '',
        "subscr_id"              => '',
        "company_name"           => '',
        "flags"                  => 0,
        "profile_image"          => '',
      ]);
    }
  }

  /* ----- ----- ----- ----- ----- ----- ----- ----- ----- *
   * -----  Custom internal tool/shortcut functions  ----- *
   * ----- ----- ----- ----- ----- ----- ----- ----- ----- */

  private static function swpm_memberships_table()
  {
    return (Crowdaa_Sync_Utils::db_prefix() . 'swpm_membership_tbl');
  }

  private static function swpm_members_table()
  {
    return (Crowdaa_Sync_Utils::db_prefix() . 'swpm_members_tbl');
  }

  private static function swpm_root_membership()
  {
    $rootMembership = Crowdaa_Sync_Utils::quick_select_first(self::swpm_memberships_table(), ['id' => 1]);
    if (!$rootMembership) {
      throw new Crowdaa_Sync_Error('Simple Membership plugin was not initialized!');
    }

    if ($rootMembership)

      return ($rootMembership);
  }

  private static function swpm_array_serialize($array)
  {
    if (count($array) === 0) {
      return (serialize(null));
    }

    return (serialize($array));
  }

  private static function swpm_array_unserialize($data)
  {
    $array = unserialize($data);
    if (!is_array($array)) {
      return ([]);
    }

    return ($array);
  }
}
