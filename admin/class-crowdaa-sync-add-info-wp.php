<?php

/**
 * The class for create/update posts or terms
 *
 * @link       https://crowdaa.com
 * @since      1.0.0
 *
 * @package    Crowdaa_Sync_Add_Info_WP
 * @subpackage Crowdaa_Sync_Add_Info_WP/admin
 * @author     Crowdaa <contact@crowdaa.com>
 */
class Crowdaa_Sync_Add_Info_WP
{
  /**
   * Initialize the class and set its properties.
   *
   * @since    1.0.0
   */
  public function __construct()
  {
  }

  private function create_permission($name, $description, $public)
  {
    $perm = Crowdaa_Sync_Permissions::create_perm($name, $description, $public);

    if (!$perm) {
      throw new Crowdaa_Sync_Badge_Error('Could not insert permission named "' . $name . '" in SQL database');
    }

    return ($perm);
  }

  private function update_permission($wp_id, $name, $description, $public)
  {
    $perm = Crowdaa_Sync_Permissions::update_perm($wp_id, $name, $description, $public);

    if (!$perm) {
      throw new Crowdaa_Sync_Badge_Error('Could not update permission named "' . $name . '" in SQL database');
    }

    return ($perm);
  }

  private function remove_permission($wp_id)
  {
    Crowdaa_Sync_Permissions::delete_perm($wp_id);
  }

  private function create_term($name, $slug, $parentId, $badges, $is_event)
  {
    $term_data = wp_insert_term(esc_html($name), 'category', [
      'parent' => $parentId ?: 0,
      'slug'   => $slug ?: '',
    ]);
    $term = get_term($term_data['term_id'], 'category');

    if (Crowdaa_Sync_Permissions::plugin_get() && $badges) {
      if (isset($badges->list) && is_array($badges->list)) {
        Crowdaa_Sync_Permissions::set_perms_for_term($term->term_id, $badges->list);
      }
    }

    update_term_meta($term->term_id, 'crowdaa_is_event', $is_event ? 'yes' : 'no');

    return ($term);
  }

  private function update_term($term_id, $name, $slug, $parentId, $badges, $is_event)
  {
    wp_update_term($term_id, 'category', [
      'name'   => esc_html($name),
      'parent' => $parentId ?: 0,
      'slug'   => $slug ?: '',
    ]);

    if (Crowdaa_Sync_Permissions::plugin_get() && $badges) {
      if (isset($badges->list) && is_array($badges->list)) {
        Crowdaa_Sync_Permissions::set_perms_for_term($term_id, $badges->list);
      }
    }

    update_term_meta($term_id, 'crowdaa_is_event', $is_event ? 'yes' : 'no');
    $term = get_term($term_id, 'category');

    return ($term);
  }

  private function delete_term($term_id)
  {
    wp_delete_term($term_id, 'category');
  }

  public function sync_badges_wp(
    &$api_to_wp,
    &$only_api,
    &$remove_wp
  ) {
    $errors            = [];
    $sync_db           = Crowdaa_Sync_Permissions::sync_db();
    $badges_sync_to_wp = array_merge($api_to_wp, $only_api, $remove_wp);

    foreach ($badges_sync_to_wp as $curBadgeId => $curBadge) {
      Crowdaa_Sync_Timer::check();
      Crowdaa_Sync_Logs::log('Syncing API>WP Badge', $curBadgeId, wp_json_encode($curBadge));

      if (in_array($curBadge, $only_api, true)) {
        $permission = $this->create_permission($curBadge['name'], $curBadge['description'], $curBadge['public']);
        $sync_db->create_entry($permission->id, $curBadge['api_id'], [
          'badge_hash' => $curBadge['hash'],
          'permission_hash' => Crowdaa_Sync_Permissions::hash_permission($permission),
        ]);
      } else if (in_array($curBadge, $api_to_wp, true)) {
        $permission = $this->update_permission($curBadge['wp_id'], $curBadge['name'], $curBadge['description'], $curBadge['public']);
        $sync_db->update_entry(['wp_id' => $curBadge['wp_id']], [
          'sync_data' => [
            'badge_hash' => $curBadge['hash'],
            'permission_hash' => Crowdaa_Sync_Permissions::hash_permission($permission),
          ],
        ]);
      } else if (in_array($curBadge, $remove_wp, true)) {
        $this->remove_permission($curBadge['wp_id']);
        $sync_db->delete_entry(['wp_id' => $curBadge['wp_id']]);
      }
    }

    if (count($badges_sync_to_wp) > 0) {
      Crowdaa_Sync_Logs::log(
        'Created/updated WP permissions',
        count($only_api) . ' permissions created, ' .
          count($api_to_wp) . ' permissions updated and ' .
          count($remove_wp) . ' permissions removed with ' .
          count($errors) . ' errors'
      );
    }

    return ($errors);
  }

  public function sync_categories_wp(
    &$api_to_wp,
    &$only_api,
    &$remove_wp
  ) {
    $errors                = [];
    $sync_db               = new Crowdaa_Sync_Syncdb('categories');
    $categories_sync_to_wp = array_merge($api_to_wp, $only_api, $remove_wp);

    foreach ($categories_sync_to_wp as $curCategoryId => $curCategory) {
      Crowdaa_Sync_Timer::check();
      Crowdaa_Sync_Logs::log('Syncing API>WP Category', $curCategoryId, wp_json_encode($curCategory));

      if (in_array($curCategory, $only_api, true)) {
        $parentId = null;
        if ($curCategory['parentId']) {
          $parentId = $sync_db->get_entry_with_api_id($curCategory['parentId'], 'wp_id');
          $parentId = $parentId->wp_id;
        }

        /** @var object */
        $term = $this->create_term($curCategory['name'], $curCategory['slug'], $parentId, $curCategory['badges'], $curCategory['isEvent']);
        if (Crowdaa_Sync_Utils::have_plugin('custom-category-image')) {
          $this->post_sync_api_term_picture($term->term_id, $curCategory['picture']);
        }
        $term->rootParentId = $parentId;
        $sync_db->create_entry($term->term_id, $curCategory['api_id'], [
          'category_hash' => $curCategory['hash'],
          'term_hash' => Crowdaa_Sync_Add_Info_API::hash_term($term),
        ]);
      } else if (in_array($curCategory, $api_to_wp, true)) {
        $parentId = null;
        if ($curCategory['parentId']) {
          $parentId = $sync_db->get_entry_with_api_id($curCategory['parentId'], 'wp_id');
          $parentId = $parentId->wp_id;
        }

        /** @var object */
        $term = $this->update_term($curCategory['wp_id'], $curCategory['name'], $curCategory['slug'], $parentId, $curCategory['badges'], $curCategory['isEvent']);
        if (Crowdaa_Sync_Utils::have_plugin('custom-category-image')) {
          $this->post_sync_api_term_picture($term->term_id, $curCategory['picture']);
        }
        $term->rootParentId = $parentId;
        $sync_db->update_entry(['wp_id' => $curCategory['wp_id']], [
          'sync_data' => [
            'category_hash' => $curCategory['hash'],
            'term_hash' => Crowdaa_Sync_Add_Info_API::hash_term($term),
          ],
        ]);
      } else if (in_array($curCategory, $remove_wp, true)) {
        $this->delete_term($curCategory['wp_id']);
        $sync_db->delete_entry(['wp_id' => $curCategory['wp_id']]);
      }
    }

    if (count($categories_sync_to_wp) > 0) {
      Crowdaa_Sync_Logs::log(
        'Created/updated WP categories',
        count($only_api) . ' categories created, ' .
          count($api_to_wp) . ' categories updated and ' .
          count($remove_wp) . ' categories removed with ' .
          count($errors) . ' errors'
      );
    }

    return ($errors);
  }

  /**
   * Create post
   *
   * @since    1.0.0
   * @param $api_to_wp
   * @param $only_api
   * @return array
   */
  public function sync_info_wp(&$api_to_wp, &$only_api)
  {
    $errors = [];
    $api_data_class = new Crowdaa_Sync_API();
    $posts_sync_to_wp = array_merge($api_to_wp, $only_api);

    foreach ($posts_sync_to_wp as $postArrayId => $api_post) {
      Crowdaa_Sync_Timer::check();
      Crowdaa_Sync_Logs::log('Syncing API>WP Post', $postArrayId, wp_json_encode($api_post));

      if (!$api_post['api_id']) {
        continue;
      }

      $api_whole_post = $api_data_class->get_article($api_post['api_id']);
      if (isset($api_whole_post->message)) {
        $errors[] = 'Get API article error for [' . $api_post['api_id'] . '] : ' . $api_whole_post->message;
        continue;
      }

      try {
        if (in_array($api_post, $only_api, true)) {
          $sync_errors = $this->create_wp_post_from_api($api_whole_post);
        } else {
          $sync_errors = $this->update_wp_post_from_api($api_whole_post, $api_post['post_id']);
        }
        $errors += $sync_errors;
      } catch (Crowdaa_Sync_Post_Skip_Error $e) {
        Crowdaa_Sync_Logs::log($e->getMessage());
        continue;
      } catch (Crowdaa_Sync_Post_Error $e) {
        $errors[] = $e->getMessage();
        continue;
      }
    }

    if (count($posts_sync_to_wp) > 0) {
      Crowdaa_Sync_Logs::log(
        'Created/updated WP posts',
        count($only_api) . ' posts created and ' . count($api_to_wp) . ' posts updated with ' . count($errors) . ' errors'
      );
    }

    return ($errors);
  }

  private function create_wp_post_from_api($api_data)
  {
    $created_posts  = [];
    $cat_sync_db    = new Crowdaa_Sync_Syncdb('categories');

    if (isset($api_data->categories) && count($api_data->categories) > 0) {
      $api_ids = [];
      foreach ($api_data->categories as $category) {
        $api_ids[] = $category->_id;
      }
      $terms = $cat_sync_db->get_entries_with_api_ids($api_ids, 'wp_id');
      $terms = Crowdaa_Sync_Utils::object_array_extract_field('wp_id', $terms);
    } else {
      $term = $cat_sync_db->get_entry_with_api_id($api_data->category->_id, 'wp_id');
      $terms = [(int) $term->wp_id];
    }

    // $publicationTime = self::api_date_to_unix($api_data->publicationDate);
    $post_data = [
      'post_title'    => $api_data->title,
      'post_content'  => $api_data->text,
      // 'post_date'     => date('Y-m-d H:i:s', $publicationTime),
      // 'post_date_gmt' => gmdate('Y-m-d H:i:s', $publicationTime),
      'post_status'   => 'publish',
      'post_type'     => 'post',
      'post_author'   => get_current_user_id(),
    ];
    // if ($publicationTime > time()) {
    //   $data['post_status'] = 'future';
    // }

    $wp_post_id = wp_insert_post($post_data);
    update_post_meta($wp_post_id, 'api_post_id', $api_data->_id);

    $eventStartDate = $api_data->eventStartDate;
    $eventEndDate = $api_data->eventEndDate;
    if ($eventStartDate) {
      update_post_meta($wp_post_id, 'crowdaa_event_start', $eventStartDate);
    }
    if ($eventEndDate) {
      update_post_meta($wp_post_id, 'crowdaa_event_end', $eventEndDate);
    }

    $fullscreen = false;
    if (isset($api_data->displayOptions) && isset($api_data->displayOptions->fullscreen)) {
      $fullscreen = $api_data->displayOptions->fullscreen;
    }
    update_post_meta($wp_post_id, 'display_option_fullscreen', ($fullscreen ? 'yes' : 'no'));

    wp_set_post_terms($wp_post_id, $terms, 'category');

    $sync_errors = $this->post_sync_api_medias($api_data, $wp_post_id);
    if ($sync_errors) {
      return ($sync_errors);
    }

    update_post_meta($wp_post_id, 'crowdaa_need_sync', 'no');
    update_post_meta($wp_post_id, 'crowdaa_version', Crowdaa_Sync_Versions::get_version());

    Crowdaa_Sync_Logs::log('Created WP post', $wp_post_id);

    return ([]);
  }

  // private static function api_date_to_unix($date) {
  //   $matches = [];
  //   preg_match('/^(\d+)-(\d+)-(\d+)T(\d+):(\d+):(\d+)\.\d+Z/', $date, $matches);
  //   $time = gmmktime(
  //     intval($matches[4], 10),
  //     intval($matches[5], 10),
  //     intval($matches[6], 10),
  //     intval($matches[2], 10),
  //     intval($matches[3], 10),
  //     intval($matches[1], 10),
  //   );
  //   return ($time);
  // }

  private function update_wp_post_from_api($api_data, $wp_post_id)
  {
    $created_posts  = [];
    $cat_sync_db    = new Crowdaa_Sync_Syncdb('categories');

    if (isset($api_data->categories) && count($api_data->categories) > 0) {
      $api_ids = [];
      foreach ($api_data->categories as $category) {
        $api_ids[] = $category->_id;
      }
      $terms = $cat_sync_db->get_entries_with_api_ids($api_ids, 'wp_id');
      $terms = Crowdaa_Sync_Utils::object_array_extract_field('wp_id', $terms);
    } else {
      $term = $cat_sync_db->get_entry_with_api_id($api_data->category->_id, 'wp_id');
      $terms = [(int) $term->wp_id];
    }

    // $publicationTime = self::api_date_to_unix($api_data->publicationDate);
    $data = [
      'ID'            => $wp_post_id,
      'post_title'    => $api_data->title,
      'post_content'  => $api_data->text,
      // 'post_date'     => date('Y-m-d H:i:s', $publicationTime),
      // 'post_date_gmt' => gmdate('Y-m-d H:i:s', $publicationTime),
    ];
    // if ($publicationTime > time()) {
    //   $data['post_status'] = 'future';
    // }

    wp_set_post_terms($wp_post_id, $terms, 'category');
    wp_update_post($data);
    update_post_meta($wp_post_id, 'api_post_id', $api_data->_id);

    $eventStartDate = $api_data->eventStartDate;
    $eventEndDate = $api_data->eventEndDate;
    if ($eventStartDate) {
      update_post_meta($wp_post_id, 'crowdaa_event_start', $eventStartDate);
    }
    if ($eventEndDate) {
      update_post_meta($wp_post_id, 'crowdaa_event_end', $eventEndDate);
    }


    $fullscreen = false;
    if (isset($api_data->displayOptions) && isset($api_data->displayOptions->fullscreen)) {
      $fullscreen = $api_data->displayOptions->fullscreen;
    }
    update_post_meta($wp_post_id, 'display_option_fullscreen', ($fullscreen ? 'yes' : 'no'));

    $sync_errors = $this->post_sync_api_medias($api_data, $wp_post_id);
    if ($sync_errors) {
      return ($sync_errors);
    }

    update_post_meta($wp_post_id, 'crowdaa_need_sync', 'no');
    update_post_meta($wp_post_id, 'crowdaa_version', Crowdaa_Sync_Versions::get_version());

    Crowdaa_Sync_Logs::log('Updated WP post', $wp_post_id, $api_data->_id);

    return ([]);
  }

  /**
   * Synchronize images from API to WP
   *
   * @since    1.0.0
   * @param $info
   * @return string
   */
  private function post_sync_api_medias($api_data, $wp_post_id)
  {
    $errors = [];

    $api_media_map_raw = get_post_meta($wp_post_id, 'api_media_map', true);
    $api_media_map = $api_media_map_raw ? unserialize($api_media_map_raw) : [];

    // Feed picture processing
    $this->post_sync_api_media_feedpicture($api_data, $wp_post_id, $errors);

    // Images & videos indexing
    $images_map = [];
    $videos_map = [];
    foreach ($api_media_map as $api_id => $data) {
      $attachment_id = $data['attachment_id'];
      if ($data['image']) {
        $images_map[$api_id] = (int)$attachment_id;
      } else if ($data['video']) {
        $videos_map[$api_id] = (int)$attachment_id;
      }
    }
    $wp_ids = get_post_meta($wp_post_id, 'second_featured_img', true) ?: '';
    $wp_ids = array_flip(explode(',', $wp_ids));

    // Processing images
    $this->post_sync_api_media_images($api_data, $wp_post_id, $wp_ids, $images_map, $errors);

    // Processing videos
    $this->post_sync_api_media_videos($api_data, $wp_post_id, $wp_ids, $videos_map, $errors);

    // Saving changes
    $attaches = [];
    $api_media_map = [];
    foreach ($images_map as $api_id => $attachment_id) {
      $attaches[] = $attachment_id;
      $api_media_map[$api_id] = [
        'attachment_id' => $attachment_id,
        'image' => true,
      ];
    }
    foreach ($videos_map as $api_id => $attachment_id) {
      $attaches[] = $attachment_id;
      $api_media_map[$api_id] = [
        'attachment_id' => $attachment_id,
        'video' => true,
      ];
    }

    update_post_meta($wp_post_id, 'api_media_map', serialize($api_media_map));
    update_post_meta($wp_post_id, 'second_featured_img', implode(',', $attaches));

    return ($errors);
  }

  private function post_sync_api_term_picture($wp_term_id, $api_image)
  {
    $synced_picture_prev_image = null;
    $synced_picture_ids_raw = get_term_meta($wp_term_id, 'crowdaa_picture', true);
    if ($synced_picture_ids_raw) {
      $synced_picture_ids = unserialize($synced_picture_ids_raw);
      $synced_picture_prev_image = $synced_picture_ids['api_id'];
    }

    $api_image_id = null;
    $api_image_url = null;
    if ($api_image) {
      $api_image_id = $api_image['id'];
      $api_image_url = $api_image['url'];
    }

    if ($synced_picture_prev_image !== $api_image_id) {
      if ($api_image_id) {
        $ext = pathinfo(parse_url($api_image_url, PHP_URL_PATH), PATHINFO_EXTENSION);
        if (!$ext) $ext = 'jpg';
        $feed_pic_name = $api_image_id . '.' . $ext;
        $fetch_err = false;
        if (!$this->check_uploads($feed_pic_name)) {
          $fetch_err = $this->get_uploads($api_image_url, $feed_pic_name);
          if ($fetch_err) {
            Crowdaa_Sync_Logs::log('Term image download error', $wp_term_id, $api_image_id, $fetch_err);
            throw new Crowdaa_Sync_Category_Error(__('Errors when downloading term image for ', CROWDAA_SYNC_PLUGIN_NAME) . $wp_term_id . ' : ' . $fetch_err);
          }
        }

        try {
          $attachment_id = $this->set_term_upload($wp_term_id, $feed_pic_name);
          update_term_meta($wp_term_id, 'crowdaa_picture', serialize([
            'api_id' => $api_image_id,
            'attachment_id' => $attachment_id,
          ]));
        } catch (\Throwable $e) {
          Crowdaa_Sync_Logs::log('Set WP term feed picture error', $e->getMessage());
          throw new Crowdaa_Sync_Category_Error(__('Set WP term feed picture error : ', CROWDAA_SYNC_PLUGIN_NAME) . $e->getMessage());
        }
      } else {
        delete_term_meta($wp_term_id, 'crowdaa_picture');
        delete_term_meta($wp_term_id, '_category_image_id');
      }
    }
  }

  /**
   * Feed picture processing from API to WP
   *
   * @since    1.0.0
   * @param $info
   * @return string
   */
  private function post_sync_api_media_feedpicture($api_data, $wp_post_id, &$errors)
  {
    $api_feedpicture = get_post_meta($wp_post_id, 'api_feedpicture_id', true) ?: '';
    $api_feedpicture_wp = null;
    $api_feedpicture_api = null;
    if ($api_feedpicture) {
      $api_feedpicture = unserialize($api_feedpicture);
      $api_feedpicture_wp = $api_feedpicture['attachment_id'];
      $api_feedpicture_api = $api_feedpicture['api_id'];
    }

    $article_vid = false;
    $article_pic = false;
    if ($api_data->videos) $article_vid = $api_data->videos[0];
    else if ($api_data->pictures) $article_pic = $api_data->pictures[0];

    $have_a_picture = isset($api_data->feedPicture->_id) || $article_vid || $article_pic;
    if (isset($api_data->feedPicture->_id)) {
      $feed_pic_url = $api_data->feedPicture->pictureUrl;
      $feed_pic_id = $api_data->feedPicture->_id;
    } else if ($article_vid) {
      $feed_pic_url = $article_vid->thumbUrl;
      $feed_pic_id = $article_vid->_id;
    } else if ($article_pic) {
      $feed_pic_url = $article_pic->pictureUrl;
      $feed_pic_id = $article_pic->_id;
    }

    $need_feed_update = false;
    if ($have_a_picture && !$api_feedpicture) {
      $need_feed_update = true;
    } else if (!$have_a_picture && $api_feedpicture) {
      if ($article_vid && $article_vid->_id !== $api_feedpicture_api) {
        $need_feed_update = true;
      } else if ($article_pic && $article_pic->_id !== $api_feedpicture_api) {
        $need_feed_update = true;
      }
    } else if ($have_a_picture && $api_feedpicture) {
      if ($feed_pic_id !== $api_feedpicture_api) {
        $need_feed_update = true;
      } else {
        $wp_feed_attachment = get_post_thumbnail_id($wp_post_id);
        if ($wp_feed_attachment !== $api_feedpicture_wp) {
          $need_feed_update = true;
        }
      }
    }

    if ($need_feed_update) {
      if (!$have_a_picture) {
        delete_post_thumbnail($wp_post_id);
        update_post_meta($wp_post_id, 'api_feedpicture_id', '');
      } else {
        $ext = pathinfo(parse_url($feed_pic_url, PHP_URL_PATH), PATHINFO_EXTENSION);
        if (!$ext) $ext = 'jpg';
        $feed_pic_name = $feed_pic_id . '-thumbnail.' . $ext;
        $fetch_err = false;
        if (!$this->check_uploads($feed_pic_name)) {
          $fetch_err = $this->get_uploads($feed_pic_url, $feed_pic_name);
          if ($fetch_err) {
            $errors[] = $fetch_err;
          }
        }
        if (!$fetch_err) {
          try {
            $attachment_id = $this->set_uploads($wp_post_id, $feed_pic_name, true);
          } catch (\Throwable $e) {
            Crowdaa_Sync_Logs::log('Set WP post feed picture error', $e->getMessage());
            $errors[] = $e->getMessage();
            return;
          }
          update_post_meta($wp_post_id, 'api_feedpicture_id', serialize([
            'api_id' => $feed_pic_id,
            'attachment_id' => $attachment_id,
          ]));
        }
      }
    }
  }

  /**
   * Images processing from API to WP
   *
   * @since    1.0.0
   * @param $info
   * @return string
   */
  private function post_sync_api_media_images($api_data, $wp_post_id, &$wp_ids, &$images_map, &$errors)
  {
    $api_feedpicture = get_post_meta($wp_post_id, 'api_feedpicture_id', true) ?: '';
    $api_feedpicture_api = null;
    if ($api_feedpicture) {
      $api_feedpicture = unserialize($api_feedpicture);
      $api_feedpicture_api = $api_feedpicture['api_id'];
    }

    $api_images_map = [];
    if (isset($api_data->pictures)) {
      foreach ($api_data->pictures as $image) {
        $image_id = $image->_id;
        if ($image_id === $api_feedpicture_api) {
          continue;
        }
        $api_images_map[$image_id] = true;

        if (isset($images_map[$image_id]) && array_key_exists($images_map[$image_id], $wp_ids)) {
          continue;
        }

        $image_url = $image->pictureUrl;
        $ext = pathinfo(parse_url($image_url, PHP_URL_PATH), PATHINFO_EXTENSION);
        if (!$ext) $ext = 'jpg';
        $image_name = $image_id . '.' . $ext;

        if (!$this->check_uploads($image_name)) {
          $fetch_err = $this->get_uploads($image_url, $image_name);
          if ($fetch_err) {
            $errors[] = $fetch_err;
            continue;
          }
        }

        try {
          $attachment_id = $this->set_uploads($wp_post_id, $image_name);
          $images_map[$image_id] = $attachment_id;
        } catch (\Throwable $e) {
          Crowdaa_Sync_Logs::log('Set WP post image error', $e->getMessage());
          $errors[] = $e->getMessage();
          continue;
        }
      }

      $images_map_ids = array_keys($images_map);
      foreach ($images_map_ids as $api_id) {
        if (!array_key_exists($api_id, $api_images_map)) {
          unset($images_map[$api_id]);
        }
      }
    } else {
      $images_map = [];
    }
  }

  /**
   * Videos processing from API to WP
   *
   * @since    1.0.0
   * @param $info
   * @return string
   */
  private function post_sync_api_media_videos($api_data, $wp_post_id, &$wp_ids, &$videos_map, &$errors)
  {
    $api_videos_map = [];
    if (isset($api_data->videos)) {
      foreach ($api_data->videos as $video) {
        $video_id = $video->_id;
        $api_videos_map[$video_id] = true;
        $video_name = $video_id . '.mp4';

        if (isset($videos_map[$video_id]) && array_key_exists($videos_map[$video_id], $wp_ids)) {
          continue;
        }

        if (!$this->check_uploads($video_name)) {
          $convert_error = $this->ffmpeg_video_converter($video->url, $video_name);
          if ($convert_error) {
            $errors[] = $convert_error;
            continue;
          }
        }

        try {
          $attachment_id = $this->set_uploads($wp_post_id, $video_name);
          $videos_map[$video_id] = $attachment_id;
        } catch (\Throwable $e) {
          Crowdaa_Sync_Logs::log('Set WP post video error', $e->getMessage());
          $errors[] = $e->getMessage();
          continue;
        }
      }

      $videos_map_ids = array_keys($videos_map);
      foreach ($videos_map_ids as $api_id) {
        if (!array_key_exists($api_id, $api_videos_map)) {
          unset($videos_map[$api_id]);
        }
      }
    } else {
      $videos_map = [];
    }
  }

  /**
   * Implode images id
   *
   * @since    1.0.0
   * @param $info
   * @return string
   */
  public function serialize_medias_array_id($info)
  {
    $info_array  = [];
    $info_id_str = '';
    if ($info) {
      foreach ($info as $item) {
        $info_array[] = $item->_id;
      }
      $info_id_str = implode(', ', $info_array);
    }
    return $info_id_str;
  }

  /**
   * Check if upload exist
   *
   * @since    1.0.0
   * @param $file_name
   * @return boolean
   */
  public function check_uploads($file_name)
  {
    $wp_upload_dir = wp_upload_dir();
    $file_path     = $wp_upload_dir['basedir'] . '/' . 'catalogue_images/' . $file_name;
    if (file_exists($file_path)) {
      return true;
    } else {
      return false;
    }
  }

  private function set_term_upload($wp_term_id, $img_name)
  {
    $wp_upload_dir   = wp_upload_dir();
    $upload_dir_path = $wp_upload_dir['basedir'] . '/catalogue_images/';
    $img_full_path = $upload_dir_path . $img_name;
    $post_mime_type = mime_content_type($img_full_path);
    $attachment = [
      'guid'           => $wp_upload_dir['url'] . '/' . $upload_dir_path,
      'post_mime_type' => $post_mime_type,
      'post_title'     => sanitize_file_name(pathinfo($img_name, PATHINFO_FILENAME)),
      'post_content'   => '',
      'post_status'    => 'inherit',
    ];
    $attach_id   = wp_insert_attachment($attachment, $img_name, $wp_term_id);
    $attach_data = wp_generate_attachment_metadata($attach_id, $img_full_path);
    wp_update_attachment_metadata($attach_id, $attach_data);
    update_attached_file($attach_id, $img_full_path);

    update_term_meta($wp_term_id, '_category_image_id', $attach_id);

    Crowdaa_Sync_Logs::log('Added term media to Wordpress', wp_json_encode([
      'wp_term_id' => $wp_term_id,
      'attach_id' => $attach_id,
      'img_name' => $img_name,
    ]));

    return ($attach_id);
  }

  /**
   * Set uploads to the post
   *
   * @since    1.0.0
   * @param $wp_post_id
   * @param $img_name
   * @param $is_thumbnail
   * @return int
   */
  private function set_uploads($wp_post_id, $img_name, $is_thumbnail = false)
  {
    $wp_upload_dir   = wp_upload_dir();
    $upload_dir_path = $wp_upload_dir['basedir'] . '/catalogue_images/';
    $img_full_path = $upload_dir_path . $img_name;
    $post_mime_type = mime_content_type($img_full_path);
    $attachment = [
      'guid'           => $wp_upload_dir['url'] . '/' . $upload_dir_path,
      'post_mime_type' => $post_mime_type,
      'post_title'     => sanitize_file_name(pathinfo($img_name, PATHINFO_FILENAME)),
      'post_content'   => '',
      'post_status'    => 'inherit',
    ];
    $attach_id   = wp_insert_attachment($attachment, $img_name, $wp_post_id);
    $attach_data = wp_generate_attachment_metadata($attach_id, $img_full_path);
    wp_update_attachment_metadata($attach_id, $attach_data);
    update_attached_file($attach_id, $img_full_path);

    if ($is_thumbnail) {
      $set_ok = set_post_thumbnail($wp_post_id, $attach_id);
    }

    Crowdaa_Sync_Logs::log('Added media to Wordpress', wp_json_encode([
      'wp_post_id' => $wp_post_id,
      'attach_id' => $attach_id,
      'img_name' => $img_name,
      'is_thumbnail' => $is_thumbnail,
    ]));

    return ($attach_id);
  }

  /**
   * Download uploads
   *
   * @since    1.0.0
   * @param $img_url
   * @param $img_name
   * @return boolean
   */
  private function get_uploads($img_url, $img_name)
  {
    $wp_upload_dir = wp_upload_dir();
    $file_path     = $wp_upload_dir['basedir'] . '/' . 'catalogue_images/' . $img_name;

    Crowdaa_Sync_Logs::log(
      'Downloading media to Wordpress',
      'img_url=' . $img_url,
      'img_path=' . $file_path,
    );

    try {
      wp_remote_get($img_url, [
        'method'          => 'GET',
        'timeout'         => 45,
        'stream'          => true,
        'filename'        => $file_path,
        'sslcertificates' => CROWDAA_SYNC_CACERT_PATH,
      ]);

      return (false);
    } catch (\Throwable $e) {
      return ($e->getMessage());
    }
  }

  /**
   * Convert video from ts parts to mp4
   *
   * @since    1.0.0
   * @param $ffmpeg_path
   * @param $video_name
   * @return boolean
   */
  public function ffmpeg_video_converter($ffmpeg_path, $video_name)
  {
    $wp_upload_dir = wp_upload_dir();
    $converted_video_path = $wp_upload_dir['basedir'] . '/catalogue_images' . '/' . $video_name;
    $result_code = null;
    $output = null;
    $ffmpeg = trim(exec('which ffmpeg', $output, $result_code));

    if ($result_code !== 0) {
      throw new Crowdaa_Sync_Post_Skip_Error(__('You don\'t have ffmpeg installed on your server to download videos, please install it or contact your hosting provider', CROWDAA_SYNC_PLUGIN_NAME));
    }
    // command to convert untouched video to mp4
    $convert_video_command = 'ffmpeg -i ' .
      escapeshellarg($ffmpeg_path) .
      ' -bsf:a aac_adtstoasc -vcodec copy -c copy -crf 50 ' .
      escapeshellarg($converted_video_path);
    $last_output = exec($convert_video_command, $output, $result_code);
    if ($result_code !== 0) {
      return ($last_output);
    }

    return (false);
  }
}
