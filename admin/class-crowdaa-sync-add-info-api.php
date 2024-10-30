<?php

/**
 * The class for create/update posts or terms
 *
 * @link       https://crowdaa.com
 * @since      1.0.0
 *
 * @package    Crowdaa_Sync_Add_Info_API
 * @subpackage Crowdaa_Sync_Add_Info_API/admin
 * @author     Crowdaa <contact@crowdaa.com>
 */
class Crowdaa_Sync_Add_Info_API
{
  /**
   * Initialize the class and set its properties.
   *
   * @since    1.0.0
   */
  public function __construct()
  {
    add_action('wp_ajax_synchronization', [$this, 'ajax_synchronization']);
    add_action('wp_ajax_get_opqueue', [$this, 'get_opqueue_ajax']);
    add_action('wp_ajax_tail_logs', [$this, 'ajax_tail_logs']);

    add_filter('cron_schedules', [$this, 'cron_add_each_min']);
    add_action('cron_sync', [$this, 'cron_synchronization']);
    add_action('clear_logs', [$this, 'do_every_week']);
    add_action('init', [$this, 'cron_job']);
  }

  /**
   * Set schedule
   *
   * @since    1.0.0
   * @@param $schedules
   * @return array
   */
  function cron_add_each_min($schedules)
  {
    $schedules['each_min'] = array(
      'interval' => 60,
      'display'  => 'One every minute',
    );
    return $schedules;
  }

  /**
   * Set cron job
   *
   * @since    1.0.0
   * @return boolean
   */
  function cron_job()
  {
    if (!wp_next_scheduled('cron_sync')) {
      wp_schedule_event(time(), 'each_min', 'cron_sync');
    }

    if (!wp_next_scheduled('clear_logs')) {
      wp_schedule_event(time(), 'weekly', 'clear_logs');
    }
  }

  /**
   * Set event for cron
   *
   * @since    1.0.0
   * @return boolean
   */
  function cron_synchronization()
  {
    if (get_option('crowdaa_cron_sync_enabled') !== 'yes') {
      return;
    }

    /**
     * Some plugins are filtering saved content based on who submitted it. We need to be seen as an admin to avoid most filtering...
     * The commented lines below are for opening a session as this user too (Cf. documentation of wp_set_current_user), but it seems that it's not required.
     */
    $original_user_id = get_current_user_id();
    if (!$original_user_id) {
      $admins = get_users(array(
        'role'   => 'administrator',
        'number' => 1,
      ));
      if (isset($admins[0])) {
        $admin = $admins[0];
        wp_set_current_user($admin->ID);
        // wp_set_auth_cookie($admin->ID);
        // do_action('wp_login', $admin->user_login, $admin);
      }
    }

    $result = $this->synchronization();

    if (!isset($result['error'])) {
      Crowdaa_Sync_Logs::log('Synchronzation complete');

      $opqueue = $result['opqueue'];
      if (is_array($opqueue['articles']['wp_to_api']) && $opqueue['articles']['wp_to_api_last']) {
        update_option('crowdaa_sync_articles_wp_to_api_from', $opqueue['articles']['wp_to_api_last']);
      }
      if (is_array($opqueue['articles']['api_to_wp']) && $opqueue['articles']['api_to_wp_last']) {
        update_option('crowdaa_sync_articles_api_to_wp_from', $opqueue['articles']['api_to_wp_last']);
      }
    } else {
      Crowdaa_Sync_Logs::log('Synchronzation ended with error', $result['error']);
    }
  }

  /**
   * Clearing logs once a week
   *
   * @since    1.0.0
   * @return boolean
   */
  function do_every_week()
  {
    Crowdaa_Sync_Logs::clear();
  }

  /**
   * Get a complete list of all synchronzations required, scanning everything (may take a really long time)
   *
   * @since    1.0.0
   * @return array, object
   */
  public function get_opqueue_ajax()
  {
    $categories_opqueue = $this->get_categories_opqueue();
    $badges_opqueue = $this->get_badges_opqueue();
    $articles_opqueue = $this->get_articles_opqueue();

    wp_send_json([
      'articles' => $articles_opqueue,
      'badges' => $badges_opqueue,
      'categories' => $categories_opqueue,
    ]);
  }

  public static function hash_term(&$term)
  {
    $data = [
      'id'          => $term->term_id,
      'name'        => $term->name,
      'slug'        => $term->slug,
      'parent'      => $term->parent,
    ];

    if (Crowdaa_Sync_Utils::have_plugin('custom-category-image')) {
      $id = get_term_meta($term->term_id, '_category_image_id', true);
      if ($id) {
        $data['picture'] = $id;
      }
    }

    $data['isEvent'] = (get_term_meta($term->term_id, 'crowdaa_is_event', true) === 'yes');
    if (isset($term->rootParentId)) {
      $data['rootParentId'] = $term->rootParentId;
    }

    if (Crowdaa_Sync_Permissions::plugin_get()) {
      $data['perm_plugin'] = Crowdaa_Sync_Permissions::plugin_get();
      $data['perms'] = Crowdaa_Sync_Permissions::get_perms_for_term($term->term_id);
    }

    return (hash('sha256', serialize($data)));
  }

  public static function hash_category(&$category)
  {
    $data = [
      'id'       => $category->_id,
      'name'     => $category->name,
      'pathName' => $category->pathName,
      'parentId' => $category->parentId,
      'isEvent'  => $category->isEvent,
    ];

    if (Crowdaa_Sync_Utils::have_plugin('custom-category-image')) {
      if (isset($category->picture) && $category->picture) {
        if (is_object($category->picture[0]) && isset($category->picture[0]->pictureUrl)) {
          $data['picture'] = $category->picture[0]->_id;
        } else if (is_string($category->picture)) {
          $data['picture'] = $category->picture;
        } else if (is_string($category->picture[0])) {
          $data['picture'] = $category->picture[0];
        }
      }
    }

    if (Crowdaa_Sync_Permissions::plugin_get()) {
      $data['badges'] = $category->badges;
    }

    return (hash('sha256', serialize($data)));
  }

  /**
   * Get badges pending sync operations queue
   *
   * @since    1.0.0
   * @return array, object
   */
  public function get_badges_opqueue()
  {
    Crowdaa_Sync_Logs::log('Syncing, Badges OpQueue fetching');

    $crowdaa_sync_articles_wp_to_api_enabled = (get_option('crowdaa_sync_articles_wp_to_api_enabled', 'yes') === 'yes');
    $crowdaa_sync_articles_api_to_wp_enabled = (get_option('crowdaa_sync_articles_api_to_wp_enabled', 'yes') === 'yes');
    $api_data_class = new Crowdaa_Sync_API();
    $sync_db = Crowdaa_Sync_Permissions::sync_db();

    if (!get_option('crowdaa_auth_token')) {
      $result = [
        'error' => __('User is not connected', CROWDAA_SYNC_PLUGIN_NAME),
      ];

      return ($result);
    }

    $result = [
      'api_to_wp'  => [],
      'wp_to_api'  => [],
      'only_api'   => [],
      'only_wp'    => [],
      'remove_api' => [],
      'remove_wp'  => [],
    ];

    if (!Crowdaa_Sync_Permissions::plugin_get()) {
      return ($result);
    }

    if (!$crowdaa_sync_articles_api_to_wp_enabled && !$crowdaa_sync_articles_wp_to_api_enabled) {
      return ($result);
    }

    $badges = $api_data_class->get_badges();
    $synced = $sync_db->get_synced_entries();

    if (isset($badges->message)) {
      $result = [
        'error' => __('API query error : ', CROWDAA_SYNC_PLUGIN_NAME) . $badges->message,
      ];

      return ($result);
    }

    $permissions_by_id = Crowdaa_Sync_Permissions::get_perms();
    Crowdaa_Sync_Logs::log('OpQueue building...', count($badges) . ' api badges and ' . count($permissions_by_id) . ' wp permissions');

    $badges_by_id = [];
    foreach ($badges as $badge) {
      $badge->hash = Crowdaa_Sync_Permissions::hash_badge($badge);
      $badges_by_id[$badge->_id] = $badge;
    }

    $synced_by_api_id = [];
    $synced_by_wp_id = [];
    foreach ($synced as $item) {
      $item->badge_hash = $item->sync_data['badge_hash'];
      $item->permission_hash = $item->sync_data['permission_hash'];
      $synced_by_wp_id[$item->wp_id] = $item;
      $synced_by_api_id[$item->api_id] = $item;
    }

    if ($crowdaa_sync_articles_wp_to_api_enabled) {
      foreach ($permissions_by_id as $id => $permission) {
        if (!array_key_exists($id, $synced_by_wp_id)) {
          $result['only_wp'][] = [
            'api_id' => null,
            'wp_id' => $permission->id,
            'name' => $permission->name,
            'description' => $permission->description,
            'public' => $permission->public,
            'hash' => $permission->hash,
          ];
        } else if (
          $synced_by_wp_id[$id]->permission_hash !== $permission->hash &&
          isset($badges_by_id[$synced_by_wp_id[$id]->api_id])
        ) {
          $result['wp_to_api'][] = [
            'api_id' => $synced_by_wp_id[$id]->api_id,
            'wp_id' => $permission->id,
            'name' => $permission->name,
            'description' => $permission->description,
            'public' => $permission->public,
            'hash' => $permission->hash,
          ];
        }
      }

      foreach ($synced_by_wp_id as $wp_id => $synced_item) {
        if (!array_key_exists($wp_id, $permissions_by_id)) {
          $result['remove_api'][] = [
            'wp_id' => null,
            'api_id' => $synced_item->api_id,
          ];
        }
      }
    }

    if ($crowdaa_sync_articles_api_to_wp_enabled) {
      foreach ($badges_by_id as $id => $badge) {
        if (!array_key_exists($id, $synced_by_api_id)) {
          $result['only_api'][] = [
            'api_id' => $badge->_id,
            'wp_id' => null,
            'name' => $badge->name,
            'description' => $badge->description,
            'public' => (array_search($badge->management, ['request', 'public']) !== false),
            'hash' => $badge->hash,
          ];
        } else if (
          $synced_by_api_id[$id]->badge_hash !== $badge->hash &&
          isset($permissions_by_id[$synced_by_api_id[$id]->wp_id])
        ) {
          $result['api_to_wp'][] = [
            'api_id' => $badge->_id,
            'wp_id' => $synced_by_api_id[$id]->wp_id,
            'name' => $badge->name,
            'description' => $badge->description,
            'public' => (array_search($badge->management, ['request', 'public']) !== false),
            'hash' => $badge->hash,
          ];
        }
      }

      foreach ($synced_by_api_id as $api_id => $synced_item) {
        if (!array_key_exists($api_id, $badges_by_id)) {
          $result['remove_wp'][] = [
            'wp_id' => $synced_item->wp_id,
            'api_id' => null,
          ];
        }
      }
    }

    return ($result);
  }

  public function get_categories_opqueue()
  {
    Crowdaa_Sync_Logs::log('Syncing, Categories OpQueue fetching');

    $crowdaa_sync_articles_wp_to_api_enabled = (get_option('crowdaa_sync_articles_wp_to_api_enabled', 'yes') === 'yes');
    $crowdaa_sync_articles_api_to_wp_enabled = (get_option('crowdaa_sync_articles_api_to_wp_enabled', 'yes') === 'yes');

    $result = [
      'api_to_wp'  => [],
      'wp_to_api'  => [],
      'only_api'   => [],
      'only_wp'    => [],
      'remove_api' => [],
      'remove_wp'  => [],
    ];

    if (!$crowdaa_sync_articles_api_to_wp_enabled && !$crowdaa_sync_articles_wp_to_api_enabled) {
      return ($result);
    }

    /// Fetching all categories and indexing by ID and updated field names
    $categories_by_id = [];
    $sync_api         = new Crowdaa_Sync_API();
    $sync_db          = new Crowdaa_Sync_Syncdb('categories');
    $synced           = $sync_db->get_synced_entries();
    $all_categories   = $sync_api->get_categories();
    if (isset($all_categories->message)) {
      return ([
        'error' => __('API query error : ', CROWDAA_SYNC_PLUGIN_NAME) . $all_categories->message,
      ]);
    }

    if ($all_categories->categories) {
      foreach ($all_categories->categories as $category) {
        if ($category->parentId) {
          $category->rootParentId = $category->parentId;
        }
        $category->isEvent = isset($category->isEvent) ? $category->isEvent : false;
        $category->badges = isset($category->badges) ? $category->badges : ((object) ['list' => [], 'allow' => 'any']);
        $category->hash = self::hash_category($category);
        $category->pictureData = null;
        if ($category->picture && isset($category->picture[0]->pictureUrl)) {
          $category->pictureData = [
            'id' => $category->picture[0]->_id,
            'url' => $category->picture[0]->pictureUrl,
          ];
        }
        $categories_by_id[$category->_id] = $category;
      }
    }

    $args = array(
      'hide_empty' => false,
      'taxonomy'   => 'category',
    );
    /** @var array */
    $all_terms = get_terms($args);
    $terms_by_id = [];
    if (!empty($all_terms)) {
      foreach ($all_terms as $term) {
        $term->perms = [];
        $term->image = null;

        if (Crowdaa_Sync_Permissions::plugin_get()) {
          $term->perms = Crowdaa_Sync_Permissions::get_perms_for_term($term->term_id);
        }
        if (Crowdaa_Sync_Utils::have_plugin('custom-category-image')) {
          $term->image = get_term_meta($term->term_id, '_category_image_id', true);
        }
        $term->isEvent = (get_term_meta($term->term_id, 'crowdaa_is_event', true) === 'yes');

        $terms_by_id[$term->term_id] = $term;
      }
    }

    /// Fetching filtering modes
    $sync_categories_mode = get_option('crowdaa_sync_categories_mode', 'blacklist');
    $sync_categories_list = get_option('crowdaa_sync_categories_list', '');
    if ($sync_categories_list === '') {
      $sync_categories_list = [];
    } else {
      $sync_categories_list = explode(',', $sync_categories_list);
    }

    /// Getting list of categories names
    $sync_categories_names = [];
    foreach ($sync_categories_list as $id) {
      if (isset($terms_by_id[$id])) {
        $sync_categories_names[] = $terms_by_id[$id]->name;
      }
    }

    /// Filtering allowed parents from the WP terms
    foreach ($terms_by_id as $term) {
      $root_term = $term;
      while ($root_term->parent) {
        $root_term = $terms_by_id[$root_term->parent];
      }

      if ($sync_categories_mode === 'whitelist' && !in_array($root_term->term_id, $sync_categories_list)) {
        $term->rootParentId = null;
      } else if ($sync_categories_mode === 'blacklist' && in_array($root_term->term_id, $sync_categories_list)) {
        $term->rootParentId = null;
      } else if ($root_term->term_id !== $term->term_id) {
        $term->rootParentId = $root_term->term_id;
      } else {
        $term->rootParentId = null;
      }
      $term->hash = self::hash_term($term);
    }

    /// Filtering allowed terms
    foreach ($terms_by_id as $id => $term) {
      if ($sync_categories_mode === 'whitelist' && !in_array($term->term_id, $sync_categories_list)) {
        unset($terms_by_id[$id]);
      } else if ($sync_categories_mode === 'blacklist' && in_array($term->term_id, $sync_categories_list)) {
        unset($terms_by_id[$id]);
      }
    }

    /// Filtering allowed categories
    foreach ($categories_by_id as $id => $category) {
      if ($sync_categories_mode === 'whitelist' && !in_array($category->name, $sync_categories_names)) {
        unset($categories_by_id[$id]);
      } else if ($sync_categories_mode === 'blacklist' && in_array($category->name, $sync_categories_names)) {
        unset($categories_by_id[$id]);
      }
    }

    /// Specific code to handle migration from previous sync mechanism
    if (count($synced) === 0) {
      Crowdaa_Sync_Logs::log('Trying to migrate synced entries');
      $created = 0;

      foreach ($terms_by_id as $term) {
        $api_id = get_term_meta($term->term_id, 'api_term_id', true);
        Crowdaa_Sync_Logs::log('Migrate checks', $term->term_id, $api_id);
        if ($api_id && isset($categories_by_id[$api_id])) {
          Crowdaa_Sync_Logs::log('Migrate OK');
          $sync_db->create_entry($term->term_id, $api_id, [
            'category_hash' => $categories_by_id[$api_id]->hash,
            'term_hash' => $term->hash,
          ]);
          $created++;
        }
      }

      Crowdaa_Sync_Logs::log('Migration done, created', $created, 'entries');
      if ($created > 0) {
        $synced = $sync_db->get_synced_entries();
      }
    }

    /// Storing synced
    $synced_by_api_id = [];
    $synced_by_wp_id = [];
    foreach ($synced as $item) {
      $item->category_hash = $item->sync_data['category_hash'];
      $item->term_hash = $item->sync_data['term_hash'];
      if ($sync_categories_mode === 'whitelist' && !in_array($item->wp_id, $sync_categories_list)) {
        $sync_db->delete_entry(['id' => $item->id]);
      } else if ($sync_categories_mode === 'blacklist' && in_array($item->wp_id, $sync_categories_list)) {
        $sync_db->delete_entry(['id' => $item->id]);
      } else {
        $synced_by_wp_id[$item->wp_id] = $item;
        $synced_by_api_id[$item->api_id] = $item;
      }
    }

    Crowdaa_Sync_Logs::log('OpQueue building...', count($all_categories->categories) . ' api categories and ' . count($all_terms) . ' wp categories');

    /// Comparing states and storing oplist
    if ($crowdaa_sync_articles_wp_to_api_enabled) {
      foreach ([false, true] as $hasParent) {
        foreach ($terms_by_id as $id => $term) {
          if ((!!$term->rootParentId) === $hasParent) {
            if (!array_key_exists($id, $synced_by_wp_id)) {
              $result['only_wp'][] = [
                'api_id' => null,
                'wp_id' => $term->term_id,
                'name' => $term->name,
                'slug' => $term->slug,
                'parentId' => $term->rootParentId,
                'perms' => $term->perms,
                'image' => $term->image,
                'isEvent' => $term->isEvent,
                'hash' => $term->hash,
              ];
            } else if (
              $synced_by_wp_id[$id]->term_hash !== $term->hash &&
              isset($categories_by_id[$synced_by_wp_id[$id]->api_id])
            ) {
              $result['wp_to_api'][] = [
                'api_id' => $synced_by_wp_id[$id]->api_id,
                'wp_id' => $term->term_id,
                'name' => $term->name,
                'slug' => $term->slug,
                'parentId' => $term->rootParentId,
                'perms' => $term->perms,
                'image' => $term->image,
                'isEvent' => $term->isEvent,
                'hash' => $term->hash,
              ];
            }
          }
        }
      }

      foreach ($synced_by_wp_id as $wp_id => $synced_item) {
        if (!array_key_exists($wp_id, $terms_by_id)) {
          $result['remove_api'][] = [
            'api_id' => $synced_item->api_id,
            'wp_id' => null,
          ];
        }
      }
    }

    if ($crowdaa_sync_articles_api_to_wp_enabled) {
      foreach ([false, true] as $hasParent) {
        foreach ($categories_by_id as $id => $category) {
          if ((!!$category->parentId) === $hasParent) {
            if (!array_key_exists($id, $synced_by_api_id)) {
              $result['only_api'][] = [
                'api_id' => $category->_id,
                'wp_id' => null,
                'name' => $category->name,
                'slug' => $category->pathName,
                'parentId' => $category->parentId,
                'badges' => $category->badges,
                'picture' => $category->pictureData,
                'isEvent' => $category->isEvent,
                'hash' => $category->hash,
              ];
            } else if (
              $synced_by_api_id[$id]->category_hash !== $category->hash &&
              isset($terms_by_id[$synced_by_api_id[$id]->wp_id])
            ) {
              $result['api_to_wp'][] = [
                'api_id' => $category->_id,
                'wp_id' => $synced_by_api_id[$id]->wp_id,
                'name' => $category->name,
                'slug' => $category->pathName,
                'parentId' => $category->parentId,
                'badges' => $category->badges,
                'picture' => $category->pictureData,
                'isEvent' => $category->isEvent,
                'hash' => $category->hash,
              ];
            }
          }
        }
      }

      foreach ($synced_by_api_id as $api_id => $synced_item) {
        if (!array_key_exists($api_id, $categories_by_id)) {
          $result['remove_wp'][] = [
            'wp_id' => $synced_item->wp_id,
            'api_id' => null,
          ];
        }
      }
    }

    return ($result);
  }

  /**
   * Get posts for create or update
   *
   * @since    1.0.0
   * @return array, object
   */
  public function get_articles_opqueue()
  {
    $api_to_wp_sync_from = get_option('crowdaa_sync_articles_api_to_wp_from', 0);
    $wp_to_api_sync_from = get_option('crowdaa_sync_articles_wp_to_api_from', 0);

    Crowdaa_Sync_Logs::log('Syncing, Articles OpQueue fetching', 'API>WP from:', $api_to_wp_sync_from, 'WP>API from:', $wp_to_api_sync_from);

    // Get all posts from API
    $crowdaa_sync_articles_wp_to_api_enabled = (get_option('crowdaa_sync_articles_wp_to_api_enabled', 'yes') === 'yes');
    $crowdaa_sync_articles_api_to_wp_enabled = (get_option('crowdaa_sync_articles_api_to_wp_enabled', 'yes') === 'yes');

    if (!get_option('crowdaa_auth_token')) {
      $result = [
        'error' => __('User is not connected', CROWDAA_SYNC_PLUGIN_NAME),
      ];

      return ($result);
    }

    $fetch_batch_count = 100;
    $oldest_api_post   = false;
    $oldest_wp_post    = false;

    // Fetch API posts
    $api_data_class    = new Crowdaa_Sync_API();
    $all_api_posts     = [];

    if ($crowdaa_sync_articles_api_to_wp_enabled) {
      $api_posts      = $api_data_class->get_articles([
        'from' => gmdate(CROWDAA_SYNC_ISO_TIME_FORMAT, $api_to_wp_sync_from),
        'start' => '0',
        'limit' => $fetch_batch_count,
        'getAuthors' => 'false',
        'getDrafts' => 'false',
        'getOrphansArticles' => 'false',
        'getPictures' => 'false',
        'onlyPublished' => 'true',
        'showHiddenOnFeed' => 'true',
        'showWithHiddenCategories' => 'false',
      ]);

      if (isset($api_posts->message)) {
        $result = [
          'error' => __('API query error : ', CROWDAA_SYNC_PLUGIN_NAME) . $api_posts->message,
        ];

        return ($result);
      }

      $oldest_api_post = $api_to_wp_sync_from;

      foreach ($api_posts->articles as $api_data) {
        $update_date_unix = strtotime($api_data->publicationDate);
        if ($update_date_unix > $oldest_api_post) {
          $oldest_api_post = $update_date_unix;
        }
        $all_api_posts[$api_data->_id]['post_id'] = $this->get_post_id_by_api_id($api_data->_id);
        $all_api_posts[$api_data->_id]['api_id'] = $api_data->_id;
        $all_api_posts[$api_data->_id]['post_name'] = $api_data->title;
        $all_api_posts[$api_data->_id]['update_date_unix'] = $update_date_unix;
      }
    }

    // Fetch WP posts
    $all_wp_posts = [];
    if ($crowdaa_sync_articles_wp_to_api_enabled) {

      global $wpdb;
      $posts_table = $wpdb->prefix . 'posts';
      $sql_query = $wpdb->prepare(
        "SELECT $posts_table.* FROM $posts_table
          WHERE
            $posts_table.post_modified_gmt >= %s AND
            $posts_table.post_type          = %s AND
            $posts_table.post_status        = %s
          ORDER BY $posts_table.post_modified_gmt ASC
          LIMIT 0, %d",
        gmdate('Y-m-d H:i:s', $wp_to_api_sync_from),
        'post',
        'publish',
        $fetch_batch_count
      );
      $wp_posts = $wpdb->get_results($sql_query, OBJECT);

      $oldest_wp_post = $wp_to_api_sync_from;

      foreach ($wp_posts as $post) {
        $update_date_unix = strtotime($post->post_modified_gmt);
        $publish_date = $update_date_unix;
        if ($publish_date > $oldest_wp_post) {
          $oldest_wp_post = $publish_date;
        }

        $api_post_id      = get_post_meta($post->ID, 'api_post_id', true);
        $api_post_id      = $api_post_id ?: 'wpid-' . $post->ID;

        $all_wp_posts[$api_post_id]['post_id']          = $post->ID;
        $all_wp_posts[$api_post_id]['api_id']           = $api_post_id;
        $all_wp_posts[$api_post_id]['post_name']        = $post->post_title;
        $all_wp_posts[$api_post_id]['update_date_unix'] = $update_date_unix;
      }
    }

    $result = [
      'api_to_wp' => [],
      'wp_to_api' => [],
      'only_api'  => [],
      'only_wp'   => [],
      'api_to_wp_sync_from' => $api_to_wp_sync_from,
      'wp_to_api_sync_from' => $wp_to_api_sync_from,
      'api_to_wp_last'      => $oldest_api_post,
      'wp_to_api_last'      => $oldest_wp_post,
    ];

    Crowdaa_Sync_Logs::log('OpQueue building...', count($all_api_posts) . ' api posts and ' . count($all_wp_posts) . ' wp posts');
    foreach ($all_api_posts as $api_post) {
      $api_post_id = $api_post['api_id'];
      if (!array_key_exists($api_post_id, $all_wp_posts)) {
        $raw_wp_post = get_posts([
          'post_type'    => 'post',
          'post_status'  => 'any',
          'meta_key'     => 'api_post_id',
          'meta_compare' => '=',
          'meta_value'   => $api_post_id,
        ]);

        if (count($raw_wp_post) === 0) {
          $result['only_api'][] = $api_post;
          continue;
        }
        $raw_wp_post = $raw_wp_post[0];

        $wp_post = [];
        $wp_post['post_id']          = $raw_wp_post->ID;
        $wp_post['api_id']           = $api_post_id;
        $wp_post['post_name']        = $raw_wp_post->post_title;
        $wp_post['update_date_unix'] = strtotime($raw_wp_post->post_modified_gmt);
      } else {
        $wp_post = $all_wp_posts[$api_post_id];
      }

      if ($api_post['update_date_unix'] > $wp_post['update_date_unix']) {
        $last_sync = get_post_meta($wp_post['post_id'], 'crowdaa_last_wp_to_api_sync', true) ?: 0;
        if ($api_post['update_date_unix'] > $last_sync) {
          $result['api_to_wp'][] = $api_post;
        }
        unset($all_wp_posts[$api_post_id]);
        unset($all_api_posts[$api_post_id]);
      } elseif ($api_post['update_date_unix'] <= $wp_post['update_date_unix']) {
        $need_sync = get_post_meta($wp_post['post_id'], 'crowdaa_need_sync', true);
        $sync_version = get_post_meta($wp_post['post_id'], 'crowdaa_version', true);
        if ($need_sync !== 'no' || $sync_version != Crowdaa_Sync_Versions::get_version()) {
          $result['wp_to_api'][] = $api_post;
        }
        unset($all_api_posts[$api_post_id]);
        unset($all_wp_posts[$api_post_id]);
      }
    }

    if ($all_wp_posts) {
      foreach ($all_wp_posts as $wp_post) {
        $need_sync = get_post_meta($wp_post['post_id'], 'crowdaa_need_sync', true);
        $sync_version = get_post_meta($wp_post['post_id'], 'crowdaa_version', true);
        if ($need_sync === 'no' && $sync_version === Crowdaa_Sync_Versions::get_version()) {
          continue;
        } else if (get_post_meta($wp_post['post_id'], 'api_post_id', true)) {
          $result['wp_to_api'][] = $wp_post;
        } else {
          $result['only_wp'][] = $wp_post;
        }
      }
    }

    if (!$crowdaa_sync_articles_wp_to_api_enabled) {
      $result['wp_to_api'] = 'disabled';
      $result['only_wp'] = 'disabled';
    }
    if (!$crowdaa_sync_articles_api_to_wp_enabled) {
      $result['api_to_wp'] = 'disabled';
      $result['only_api'] = 'disabled';
    }

    do_action('crowdaa_sync_articles_opqueue_built');

    return $result;
  }

  private static function log_opqueue(&$opqueue, $type, $name)
  {
    if (is_array($opqueue[$name])) {
      Crowdaa_Sync_Logs::log('OpQueue ' . $type . '/' . $name . ' has ' . count($opqueue[$name]) . ' elements');
    } else {
      Crowdaa_Sync_Logs::log('OpQueue ' . $type . '/' . $name . ' is ' . wp_json_encode($opqueue[$name]));
    }
  }

  /**
   * Synchronization process. It will process everything needed according to its inputs
   *
   * @param last_api_to_wp_sync The last api > wp synchronization timestamp. Unset/null for full sync.
   * @param last_wp_to_api_sync The last wp > api synchronization timestamp. Unset/null for full sync.
   * @since    1.0.0
   * @return boolean
   */
  private function synchronization()
  {
    $result = [];

    $missing = self::check_dependencies();
    if ($missing) {
      $result['error'] = __('The synchronization cannot be done, missing dependencies : ', CROWDAA_SYNC_PLUGIN_NAME) . implode(' | ', $missing);
      return ($result);
    }

    if (!Crowdaa_Sync_Lock::sync_lock(true, 10)) {
      $result['error'] = __('Cannot run : a synchronization is already running', CROWDAA_SYNC_PLUGIN_NAME);
      return ($result);
    }

    Crowdaa_Sync_Timer::prepare_ini();

    try {
      do_action('crowdaa_sync_articles_synchronization_init');

      $info_wp = new Crowdaa_Sync_Add_Info_WP();
      $badges_opqueue = $this->get_badges_opqueue();
      $categories_opqueue = $this->get_categories_opqueue();
      $articles_opqueue = $this->get_articles_opqueue();

      if (isset($badges_opqueue['error']) && $badges_opqueue['error']) {
        $result['error'] = $badges_opqueue['error'];
        Crowdaa_Sync_Logs::log('Queue', 'error', $badges_opqueue['error']);
        return ($result);
      }

      if (isset($categories_opqueue['error']) && $categories_opqueue['error']) {
        $result['error'] = $categories_opqueue['error'];
        Crowdaa_Sync_Logs::log('Queue', 'error', $categories_opqueue['error']);
        return ($result);
      }

      if (isset($articles_opqueue['error']) && $articles_opqueue['error']) {
        $result['error'] = $articles_opqueue['error'];
        Crowdaa_Sync_Logs::log('Queue', 'error', $articles_opqueue['error']);
        return ($result);
      }

      $result['opqueue'] = [
        'articles'   => $articles_opqueue,
        'badges'     => $badges_opqueue,
        'categories' => $categories_opqueue,
      ];

      self::log_opqueue($badges_opqueue, 'badges', 'api_to_wp');
      self::log_opqueue($badges_opqueue, 'badges', 'wp_to_api');
      self::log_opqueue($badges_opqueue, 'badges', 'only_api');
      self::log_opqueue($badges_opqueue, 'badges', 'only_wp');
      self::log_opqueue($badges_opqueue, 'badges', 'remove_api');
      self::log_opqueue($badges_opqueue, 'badges', 'remove_wp');
      self::log_opqueue($categories_opqueue, 'categories', 'api_to_wp');
      self::log_opqueue($categories_opqueue, 'categories', 'wp_to_api');
      self::log_opqueue($categories_opqueue, 'categories', 'only_api');
      self::log_opqueue($categories_opqueue, 'categories', 'only_wp');
      self::log_opqueue($categories_opqueue, 'categories', 'remove_api');
      self::log_opqueue($categories_opqueue, 'categories', 'remove_wp');
      self::log_opqueue($articles_opqueue, 'articles', 'api_to_wp');
      self::log_opqueue($articles_opqueue, 'articles', 'wp_to_api');
      self::log_opqueue($articles_opqueue, 'articles', 'only_api');
      self::log_opqueue($articles_opqueue, 'articles', 'only_wp');
      do_action('crowdaa_sync_opqueue_log');

      $wp_errors = false;
      $api_errors = false;

      do_action('crowdaa_sync_articles_synchronization_start');
      // Sync WP => API
      if (
        count($badges_opqueue['wp_to_api']) > 0 ||
        count($badges_opqueue['only_wp']) > 0 ||
        count($badges_opqueue['remove_api']) > 0
      ) {
        /** @TODO Continue me, write this function */
        $api_errors = $this->sync_badges_api(
          $badges_opqueue['wp_to_api'],
          $badges_opqueue['only_wp'],
          $badges_opqueue['remove_api']
        );
      }

      // Sync API => WP
      if (
        count($badges_opqueue['api_to_wp']) > 0 ||
        count($badges_opqueue['only_api']) > 0 ||
        count($badges_opqueue['remove_wp']) > 0
      ) {
        /** @TODO Continue me, write this function */
        $wp_errors = $info_wp->sync_badges_wp(
          $badges_opqueue['api_to_wp'],
          $badges_opqueue['only_api'],
          $badges_opqueue['remove_wp']
        );
      }

      // Sync WP => API
      if (
        count($categories_opqueue['wp_to_api']) > 0 ||
        count($categories_opqueue['only_wp']) > 0 ||
        count($categories_opqueue['remove_api']) > 0
      ) {
        /** @TODO Continue me, write this function */
        $api_errors = $this->sync_categories_api(
          $categories_opqueue['wp_to_api'],
          $categories_opqueue['only_wp'],
          $categories_opqueue['remove_api']
        );
      }

      // Sync API => WP
      if (
        count($categories_opqueue['api_to_wp']) > 0 ||
        count($categories_opqueue['only_api']) > 0 ||
        count($categories_opqueue['remove_wp']) > 0
      ) {
        /** @TODO Continue me, write this function */
        $wp_errors = $info_wp->sync_categories_wp(
          $categories_opqueue['api_to_wp'],
          $categories_opqueue['only_api'],
          $categories_opqueue['remove_wp']
        );
      }

      // Sync WP => API
      if (is_array($articles_opqueue['wp_to_api']) && is_array($articles_opqueue['only_wp'])) {
        if (count($articles_opqueue['wp_to_api']) > 0 || count($articles_opqueue['only_wp']) > 0) {
          $api_errors = $this->sync_info_api($articles_opqueue['wp_to_api'], $articles_opqueue['only_wp']);
        }
      }

      // Sync API => WP
      if (is_array($articles_opqueue['api_to_wp']) && is_array($articles_opqueue['only_api'])) {
        if (count($articles_opqueue['api_to_wp']) > 0 || count($articles_opqueue['only_api']) > 0) {
          $wp_errors = $info_wp->sync_info_wp($articles_opqueue['api_to_wp'], $articles_opqueue['only_api']);
        }
      }

      do_action('crowdaa_sync_synchronization_custom');

      if ($wp_errors || $api_errors) {
        $result['error'] =
          __('Some errors happened during synchronization : ', CROWDAA_SYNC_PLUGIN_NAME) .
          implode(', ', $wp_errors ?: []) . ' | ' . implode(', ', $api_errors ?: []);
      } else {
        $result['success'] = __('All data synced successfully', CROWDAA_SYNC_PLUGIN_NAME);
      }

      $result = apply_filters('crowdaa_sync_synchronization_results', $result);
    } catch (Crowdaa_Sync_Timeout_Error $e) {
      $result['error'] = __('The synchronization process timed out, please retry it until it succeeds.', CROWDAA_SYNC_PLUGIN_NAME);
    } catch (Crowdaa_Sync_Post_Error $e) {
      $result['error'] = __('Uncaught Post synchronization error : ', CROWDAA_SYNC_PLUGIN_NAME) . $e->getMessage();
    } catch (Crowdaa_Sync_Category_Error $e) {
      $result['error'] = __('Category synchronization error : ', CROWDAA_SYNC_PLUGIN_NAME) . $e->getMessage();
    } catch (Crowdaa_Sync_Badge_Error $e) {
      $result['error'] = __('Badge synchronization error : ', CROWDAA_SYNC_PLUGIN_NAME) . $e->getMessage();
    } catch (Crowdaa_Sync_Error $e) {
      $result['error'] = __('Fatal synchronization error : ', CROWDAA_SYNC_PLUGIN_NAME) . $e->getMessage();
    } catch (\Throwable $e) {
      $result['error'] = __('Unknown synchronzation error : ', CROWDAA_SYNC_PLUGIN_NAME) . $e->getMessage();
    }

    Crowdaa_Sync_Lock::sync_unlock();

    return ($result);
  }

  /**
   * Synchronization process ajax callback
   *
   * @since    1.0.0
   * @return boolean
   */
  public function ajax_synchronization()
  {
    $result = $this->synchronization();

    if (!isset($result['error'])) {
      Crowdaa_Sync_Logs::log('Synchronzation complete');

      $opqueue = $result['opqueue'];
      if (is_array($opqueue['articles']['wp_to_api']) && $opqueue['articles']['wp_to_api_last']) {
        update_option('crowdaa_sync_articles_wp_to_api_from', $opqueue['articles']['wp_to_api_last']);
      }
      if (is_array($opqueue['articles']['api_to_wp']) && $opqueue['articles']['api_to_wp_last']) {
        update_option('crowdaa_sync_articles_api_to_wp_from', $opqueue['articles']['api_to_wp_last']);
      }
    } else {
      Crowdaa_Sync_Logs::log('Synchronzation ended with error', $result['error']);
    }

    wp_send_json($result);
  }

  /**
   * Tails plugin logs
   *
   * @since    1.0.0
   * @return boolean
   */
  public function ajax_tail_logs()
  {
    $logs = Crowdaa_Sync_Logs::tail(30);

    wp_send_json(array_reverse($logs));
  }

  /**
   * Get post_id by api_post_id
   *
   * @since    1.0.0
   * @param $meta_value
   * @return boolean
   */
  public function get_post_id_by_api_id($meta_value)
  {
    global $wpdb;
    $result = $wpdb->get_results($wpdb->prepare("SELECT post_id FROM $wpdb->postmeta WHERE meta_key = %s AND meta_value = %s", 'api_post_id', $meta_value));

    return $result ? $result[0]->post_id : 0;
  }


  private function remove_already_synced_post($post_id)
  {
    $sync_api = new Crowdaa_Sync_API();
    $api_post_id = get_post_meta($post_id, 'api_post_id', true);

    if ($api_post_id) {
      try {
        $sync_api->delete_post_api($api_post_id);
      } catch (\Throwable $e) {
        /* Do nothing */
      }
    }

    delete_post_meta($post_id, 'crowdaa_need_sync');
    delete_post_meta($post_id, 'crowdaa_version');
    delete_post_meta($post_id, 'crowdaa_last_wp_to_api_sync');
    delete_post_meta($post_id, 'api_post_id');
  }

  public function sync_badges_api(
    &$wp_to_api,
    &$only_wp,
    &$remove_api
  ) {
    $elements_to_process = array_merge($wp_to_api, $only_wp, $remove_api);
    if (!$elements_to_process) {
      return (false);
    }
    $sync_api = new Crowdaa_Sync_API();
    $sync_db  = Crowdaa_Sync_Permissions::sync_db();
    $errors   = [];

    foreach ($elements_to_process as $curPermId => $curPerm) {
      Crowdaa_Sync_Timer::check();
      Crowdaa_Sync_Logs::log('Syncing WP>API Badge', $curPermId, wp_json_encode($curPerm));

      try {
        if (in_array($curPerm, $only_wp, true)) {
          $badge = $sync_api->create_badge($curPerm['name'], $curPerm['description'], $curPerm['public']);
          $sync_db->create_entry($curPerm['wp_id'], $badge->_id, [
            'badge_hash' => Crowdaa_Sync_Permissions::hash_badge($badge),
            'permission_hash' => $curPerm['hash'],
          ]);
        } else if (in_array($curPerm, $wp_to_api, true)) {
          $badge = $sync_api->update_badge($curPerm['api_id'], $curPerm['name'], $curPerm['description'], $curPerm['public']);
          $sync_db->update_entry(['api_id' => $curPerm['api_id']], [
            'sync_data' => [
              'badge_hash' => Crowdaa_Sync_Permissions::hash_badge($badge),
              'permission_hash' => $curPerm['hash'],
            ],
          ]);
        } else if (in_array($curPerm, $remove_api, true)) {
          $sync_api->remove_badge($curPerm['api_id']);
          $sync_db->delete_entry(['api_id' => $curPerm['api_id']]);
        }
      } catch (Crowdaa_Sync_Post_Skip_Error $e) {
        Crowdaa_Sync_Logs::log($e->getMessage());
        continue;
      } catch (Crowdaa_Sync_Post_Error $e) {
        Crowdaa_Sync_Logs::log('Badge sync error', $e->getMessage());
        $errors[] = $e->getMessage();
        continue;
      }
    }

    if (count($elements_to_process) > 0) {
      foreach ($errors as $error) {
        Crowdaa_Sync_Logs::log('Created/updated/removed API badges errors', $error);
      }

      Crowdaa_Sync_Logs::log(
        'Created/updated API badges',
        count($only_wp) . ' badges created, ' .
          count($wp_to_api) . ' badges updated and ' .
          count($remove_api) . ' badges removed with ' . count($errors) . ' errors'
      );
    }

    return ($errors);
  }

  private static function term_perms_to_badges($perms)
  {
    $perm_sync_db = Crowdaa_Sync_Permissions::sync_db();
    $badges = [];

    foreach ($perms as $perm_id) {
      $entry = $perm_sync_db->get_entry_with_wp_id($perm_id, 'api_id');
      $badges[] = $entry->api_id;
    }

    return ($badges);
  }

  public function sync_categories_api(
    &$wp_to_api,
    &$only_wp,
    &$remove_api
  ) {
    $elements_to_process = array_merge($wp_to_api, $only_wp, $remove_api);
    if (!$elements_to_process) {
      return (false);
    }
    $sync_api     = new Crowdaa_Sync_API();
    $sync_db      = new Crowdaa_Sync_Syncdb('categories');
    $errors       = [];

    foreach ($elements_to_process as $curTermId => $curTerm) {
      Crowdaa_Sync_Timer::check();
      Crowdaa_Sync_Logs::log('Syncing WP>API Category', $curTermId, wp_json_encode($curTerm));

      try {
        if (in_array($curTerm, $only_wp, true)) {
          $parentId = null;
          if ($curTerm['parentId']) {
            $parentId = $sync_db->get_entry_with_wp_id($curTerm['parentId'], 'api_id');
            $parentId = $parentId->api_id;
          }

          $badges = null;

          if (Crowdaa_Sync_Permissions::plugin_get()) {
            $badges = self::term_perms_to_badges($curTerm['perms']);
          }

          $category_picture_id = false;
          if (Crowdaa_Sync_Utils::have_plugin('custom-category-image')) {
            $category_picture_id = $sync_api->sync_term_images($curTerm['wp_id']);
          }
          $category = $sync_api->create_category($curTerm['name'], $curTerm['slug'], $parentId, $badges, $category_picture_id, $curTerm['isEvent']);
          $sync_db->create_entry($curTerm['wp_id'], $category->_id, [
            'category_hash' => self::hash_category($category),
            'term_hash' => $curTerm['hash'],
          ]);
        } else if (in_array($curTerm, $wp_to_api, true)) {
          $parentId = null;
          if ($curTerm['parentId']) {
            $parentId = $sync_db->get_entry_with_wp_id($curTerm['parentId'], 'api_id');
            $parentId = $parentId->api_id;
          }

          $badges = null;

          if (Crowdaa_Sync_Permissions::plugin_get()) {
            $badges = self::term_perms_to_badges($curTerm['perms']);
          }

          $category_picture_id = false;
          if (Crowdaa_Sync_Utils::have_plugin('custom-category-image')) {
            $category_picture_id = $sync_api->sync_term_images($curTerm['wp_id']);
          }
          $category = $sync_api->update_category($curTerm['api_id'], $curTerm['name'], $curTerm['slug'], $parentId, $badges, $category_picture_id, $curTerm['isEvent']);
          $sync_db->update_entry(['api_id' => $curTerm['api_id']], [
            'sync_data' => [
              'category_hash' => self::hash_category($category),
              'term_hash' => $curTerm['hash'],
            ],
          ]);
        } else if (in_array($curTerm, $remove_api, true)) {
          $sync_api->delete_category($curTerm['api_id']);
          $sync_db->delete_entry(['api_id' => $curTerm['api_id']]);
        }
      } catch (Crowdaa_Sync_Post_Skip_Error $e) {
        Crowdaa_Sync_Logs::log($e->getMessage());
        continue;
      } catch (Crowdaa_Sync_Post_Error $e) {
        Crowdaa_Sync_Logs::log('Badge sync error', $e->getMessage());
        $errors[] = $e->getMessage();
        continue;
      }
    }

    if (count($elements_to_process) > 0) {
      foreach ($errors as $error) {
        Crowdaa_Sync_Logs::log('Created/updated/removed API categories errors', $error);
      }

      Crowdaa_Sync_Logs::log(
        'Created/updated API categories',
        count($only_wp) . ' categories created, ' .
          count($wp_to_api) . ' categories updated and ' .
          count($remove_api) . ' categories removed with ' . count($errors) . ' errors'
      );
    }

    return ($errors);
  }

  public function sync_info_api(&$wp_to_api, &$only_wp)
  {
    $posts_add_to_api = array_merge($wp_to_api, $only_wp);
    if (!$posts_add_to_api) {
      return (false);
    }

    $errors             = [];
    $sync_api           = new Crowdaa_Sync_API();
    $cat_sync_db        = new Crowdaa_Sync_Syncdb('categories');
    $cat_synced_entries = $cat_sync_db->get_synced_entries();

    $cat_wp_to_api_id = [];
    foreach ($cat_synced_entries as $item) {
      $cat_wp_to_api_id[$item->wp_id] = $item->api_id;
    }

    $feed_categories = get_option('crowdaa_sync_feed_categories', 'all');
    if ($feed_categories !== 'all') {
      $feed_categories = explode(',', $feed_categories);
    }

    $sync_categories_mode = get_option('crowdaa_sync_categories_mode', 'blacklist');
    $sync_categories_list = get_option('crowdaa_sync_categories_list', '');
    if ($sync_categories_list === '') {
      $sync_categories_list = [];
    } else {
      $sync_categories_list = explode(',', $sync_categories_list);
    }

    foreach ($posts_add_to_api as $postArrayId => $post) {
      Crowdaa_Sync_Timer::check();
      Crowdaa_Sync_Logs::log('Syncing WP>API Post', $postArrayId, wp_json_encode($post));

      // Get category. On multiple categories, pick childless ones first
      $child_term = false;
      $post_terms = get_the_terms($post['post_id'], 'category');
      if (empty($post_terms)) {
        Crowdaa_Sync_Logs::log('No categories for this post, skipping...');
        continue;
      }

      $whitelisted = ($sync_categories_mode !== 'whitelist');
      $blacklisted = false;
      $allowed_term_ids = [];
      foreach ($post_terms as $term) {
        if (in_array($term->term_id, $sync_categories_list)) {
          if ($sync_categories_mode === 'blacklist') {
            $blacklisted = $term->term_id;
            break;
          } else {
            $whitelisted = $term->term_id;
            $allowed_term_ids[] = $term->term_id;
          }
        } else if (
          $sync_categories_mode !== 'whitelist' &&
          !$blacklisted
        ) {
          $allowed_term_ids[] = $term->term_id;
        }
      }

      if (!$whitelisted) {
        Crowdaa_Sync_Logs::log('Could not find a whitelisted category for this article, skipping');
        $api_post_id = get_post_meta($post['post_id'], 'api_post_id');
        if ($api_post_id) {
          Crowdaa_Sync_Logs::log('Post was already synchronized, removing it from the API');
          $this->remove_already_synced_post($post['post_id']);
        }

        continue;
      } else if ($blacklisted) {
        Crowdaa_Sync_Logs::log('Blacklisted category', $blacklisted, 'skipping');
        $api_post_id = get_post_meta($post['post_id'], 'api_post_id');
        if ($api_post_id) {
          Crowdaa_Sync_Logs::log('Post was already synchronized, removing it from the API');
          $this->remove_already_synced_post($post['post_id']);
        }

        continue;
      }

      try {
        $can_sync = apply_filters('crowdaa_sync_can_sync_wp_post', true, $post['post_id']);
        if (!$can_sync) {
          Crowdaa_Sync_Logs::log('Post sync denied by "crowdaa_sync_can_sync_wp_post" filter');
          $api_post_id = get_post_meta($post['post_id'], 'api_post_id');
          if ($api_post_id) {
            Crowdaa_Sync_Logs::log('Post was already synchronized, removing it from the API');
            $this->remove_already_synced_post($post['post_id']);
          }

          continue;
        }

        // Get categories API IDs
        $api_term_ids = [];
        foreach ($allowed_term_ids as $ids) {
          $api_term_id = @$cat_wp_to_api_id[$ids];
          if ($api_term_id) {
            $api_term_ids[] = $api_term_id;
          }
        }
        if (!$api_term_ids) {
          throw new Crowdaa_Sync_Post_Error('Could not find synced category for term ' . $term->term_id . '. This should not happen, aborting sync.');

          continue;
        }

        // Create or update images, if needed
        $img_errors = $sync_api->sync_post_images($post['post_id']);
        if ($img_errors) {
          throw new Crowdaa_Sync_Post_Skip_Error('Medias sync errors, skipping (' . implode(', ', $img_errors) . ')');
        }

        $feed_display = false;
        if ($feed_categories === 'all' || array_search($term->term_id, $feed_categories) !== false) {
          $feed_display = true;
        }

        // Update post
        if (in_array($post, $only_wp, true)) {
          $sync_api->create_post_api($api_term_ids, $post['post_id'], $feed_display);
        } else {
          $sync_api->update_draft_post_api($api_term_ids, $post['post_id'], $post['api_id'], $feed_display);
        }
      } catch (Crowdaa_Sync_Post_Skip_Error $e) {
        Crowdaa_Sync_Logs::log($e->getMessage());
        continue;
      } catch (Crowdaa_Sync_Post_Error $e) {
        Crowdaa_Sync_Logs::log('Post sync error', $e->getMessage());
        $errors[] = $e->getMessage();
        continue;
      }

      update_post_meta($post['post_id'], 'crowdaa_need_sync', 'no');
      update_post_meta($post['post_id'], 'crowdaa_version', Crowdaa_Sync_Versions::get_version());
      update_post_meta($post['post_id'], 'crowdaa_last_wp_to_api_sync', time());
    }

    if (count($posts_add_to_api) > 0) {
      foreach ($errors as $error) {
        Crowdaa_Sync_Logs::log('Created/updated API posts errors', $error);
      }

      Crowdaa_Sync_Logs::log(
        'Created/updated API posts',
        count($only_wp) . ' posts created and ' . count($wp_to_api) . ' posts updated with ' . count($errors) . ' errors'
      );
    }

    return ($errors);
  }

  /**
   * Checks for missing dependencies and returns them (as array of strings) if found, or null.
   *
   * @since    1.0.0
   * @return array|null
   */
  static function check_dependencies()
  {
    $missing = [];
    $result_code = null;
    $output = null;

    $default_image = get_option('default_image');
    if (!$default_image) {
      $missing[] = esc_html__('Default image', CROWDAA_SYNC_PLUGIN_NAME);
    }

    $missing = apply_filters('crowdaa_sync_synchronization_missing_settings', $missing);

    return ($missing ?: null);
  }

  /**
   * Color generator
   *
   * @since    1.0.0
   * @return string
   */
  static function rand_color()
  {
    return '#' . str_pad(dechex(mt_rand(0, 0xFFFFFF)), 6, '0', STR_PAD_LEFT);
  }
}
