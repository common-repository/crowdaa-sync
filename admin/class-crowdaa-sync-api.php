<?php

/**
 * The authenticate user in the admin panel of the plugin.
 *
 * @link       https://crowdaa.com
 * @since      1.0.0
 *
 * @package    Crowdaa_Sync_API
 * @subpackage Crowdaa_Sync_API/admin
 * @author     Crowdaa <contact@crowdaa.com>
 */

class Crowdaa_Sync_API
{
  private $api_key = null;
  private $api_url = null;

  public function __construct()
  {
    $this->api_key = get_option('crowdaa_user_api_key');
    $this->api_url = get_option('crowdaa_api_url');
  }

  public function get_categories()
  {
    $response = $this->http_request('GET', '/admin/press/categories/');
    $err    = is_wp_error($response) ? $response->get_error_message() : null;
    if (!$err) {
      $body = wp_remote_retrieve_body($response);
      $json = json_decode($body);
    }

    if ($err) {
      Crowdaa_Sync_Logs::log('Get categories query error', $err);
      return ((object) [
        'message' => $err,
      ]);
    } else {
      if (isset($json->message)) {
        Crowdaa_Sync_Logs::log('Get categories error', $json->message);
      }

      return ($json);
    }
  }

  public function get_articles($params)
  {
    $url = '/admin/press/articlesFrom?' . http_build_query($params);
    $response = $this->http_request('GET', $url);
    $err    = is_wp_error($response) ? $response->get_error_message() : null;
    if (!$err) {
      $body = wp_remote_retrieve_body($response);
      $json = json_decode($body);
    }

    if ($err) {
      Crowdaa_Sync_Logs::log('Get articles query error', $err);
      return ((object) [
        'message' => $err,
      ]);
    }

    if (isset($json->message)) {
      Crowdaa_Sync_Logs::log('Get articles error', $json->message);
    }

    return ($json);
  }

  public function get_article($id)
  {
    $url = '/press/articles/' . rawurlencode($id);
    $response = $this->http_request('GET', $url);
    $err    = is_wp_error($response) ? $response->get_error_message() : null;
    if (!$err) {
      $body = wp_remote_retrieve_body($response);
      $json = json_decode($body);
    }

    if ($err) {
      Crowdaa_Sync_Logs::log('Get article query error', $id, $err);
      return ((object) [
        'message' => $err,
      ]);
    }

    if (isset($json->message)) {
      Crowdaa_Sync_Logs::log('Get article error', $json->message);
    }

    return ($json);
  }

  public function get_badges()
  {
    $url = '/userBadges';
    $response = $this->http_request('GET', $url);
    $err = is_wp_error($response) ? $response->get_error_message() : null;
    if (!$err) {
      $body = wp_remote_retrieve_body($response);
      $json = json_decode($body);
    }

    if ($err) {
      Crowdaa_Sync_Logs::log('Get badges query error', $err);
      return ((object) [
        'message' => $err,
      ]);
    }

    if (isset($json->message)) {
      Crowdaa_Sync_Logs::log('Get badges error', $json->message);
    }

    foreach ($json->userBadges as &$v) {
      if (!isset($v->management)) {
        $v->management = 'private-internal';
      }
      if (!isset($v->access)) {
        $v->access = 'hidden';
      }
    }

    return ($json->userBadges);
  }

  /**
   * Function to retrieve post medias for API synchronization
   *
   * @since    1.0.0
   * @param $wp_post_id
   */
  private function fetch_post_api_medias($wp_post_id)
  {
    $captions = [];
    $api_feedpicture_id = get_post_meta($wp_post_id, 'api_feedpicture_id', true) ?: '';
    if ($api_feedpicture_id) {
      $api_feedpicture_id = unserialize($api_feedpicture_id);
      $captions[] = wp_get_attachment_caption($api_feedpicture_id['attachment_id']);
      $api_feedpicture_id = $api_feedpicture_id['api_id'];
    }

    $api_media_map_raw = get_post_meta($wp_post_id, 'api_media_map', true);
    $api_media_map = $api_media_map_raw ? unserialize($api_media_map_raw) : [];
    $pictures_id = [];
    $videos_id = [];
    foreach ($api_media_map as $api_id => $data) {
      if (isset($data['video'])) {
        $videos_id[] = $api_id;
      } elseif ($data['image']) {
        $pictures_id[] = $api_id;
      }
      $captions[] = wp_get_attachment_caption($data['attachment_id']);
    }

    $captions = array_filter($captions, 'strlen');

    return ([
      'api_feedpicture_id' => $api_feedpicture_id,
      'videos_id'          => $videos_id,
      'pictures_id'        => $pictures_id,
      'captions'           => $captions,
    ]);
  }

  /**
   * Function for post update. Post is saved as draft
   *
   * @since    1.0.0
   * @param $category_id
   * @param $wp_post_id
   * @param $api_post_id
   */
  public function update_draft_post_api($category_id, $wp_post_id, $api_post_id, $feed_display = true)
  {
    $draft_id   = '';
    $pictures_id = [];
    $videos_id  = [];

    $auth_token = get_option('crowdaa_auth_token');
    if (!$auth_token) {
      throw new Crowdaa_Sync_Error(__('Not connected to the API', CROWDAA_SYNC_PLUGIN_NAME));
    }

    $api_media_data = $this->fetch_post_api_medias($wp_post_id);

    $api_feedpicture_id = $api_media_data['api_feedpicture_id'];
    $videos_id = $api_media_data['videos_id'];
    $pictures_id = $api_media_data['pictures_id'];

    if (!$pictures_id && !$videos_id) {
      if (!$api_feedpicture_id) {
        throw new Crowdaa_Sync_Post_Skip_Error(__('Cannot sync article without any image or video!', CROWDAA_SYNC_PLUGIN_NAME));
      } else {
        $pictures_id = [$api_feedpicture_id];
      }
    }

    $fullscreen = (get_post_meta($wp_post_id, 'display_option_fullscreen', true) === 'yes');
    $post_content = get_post_field('post_content', $wp_post_id);
    $post_author = get_the_author_meta('display_name', get_post_field('post_author', $wp_post_id));
    $publication_time = get_post_datetime($wp_post_id)->getTimestamp();

    $wp_post_title = get_the_title($wp_post_id) ?: __('Without title', CROWDAA_SYNC_PLUGIN_NAME);
    $wp_post_title = html_entity_decode($wp_post_title, ENT_QUOTES);
    $data = [
      'articleId'      => $api_post_id,
      'actions'        => [],
      'authorName'     => $post_author,
      'feedPicture'    => $api_feedpicture_id,
      'md'             => $post_content ?: __('Without content', CROWDAA_SYNC_PLUGIN_NAME),
      'pictures'       => $pictures_id,
      'summary'        => $wp_post_title,
      'hideFromFeed'   => !$feed_display,
      'productId'      => '',
      'title'          => $wp_post_title,
      'videos'         => $videos_id,
      'mediaCaptions'  => implode(' | ', $api_media_data['captions']),
      'displayOptions' => [
        'fullscreen' => $fullscreen,
      ],
    ];
    if (is_array($category_id)) {
      $data['categoriesId'] = $category_id;
    } else {
      $data['categoryId'] = $category_id;
    }
    $eventStartDate = get_post_meta($wp_post_id, 'crowdaa_event_start', true);
    $eventEndDate = get_post_meta($wp_post_id, 'crowdaa_event_end', true);
    if ($eventStartDate) {
      $data['eventStartDate'] = $eventStartDate;
    }
    if ($eventEndDate) {
      $data['eventEndDate'] = $eventEndDate;
    }
    $data = apply_filters('crowdaa_sync_api_update_article_payload', $data, $wp_post_id);

    $response = $this->http_request('PUT', '/press/articles', $data);
    $err    = is_wp_error($response) ? $response->get_error_message() : null;
    if (!$err) {
      $body = wp_remote_retrieve_body($response);
      $json = json_decode($body);
    }

    if ($err) {
      Crowdaa_Sync_Logs::log('Article network update error', $wp_post_id, $api_post_id, $err);
      throw new Crowdaa_Sync_Post_Error(__('Post update error, query response : ', CROWDAA_SYNC_PLUGIN_NAME) . $err);
    } else {
      if (isset($json->message)) {
        Crowdaa_Sync_Logs::log('Article API update error', $wp_post_id, $api_post_id, $json->message);
        throw new Crowdaa_Sync_Post_Error(__('Post update error, API response : ', CROWDAA_SYNC_PLUGIN_NAME) . $json->message);
      }

      $send_notification = (
        get_post_meta($wp_post_id, 'crowdaa_notification_send', true) === 'yes' &&
        get_post_meta($wp_post_id, 'crowdaa_notification_sent', true) !== 'yes'
      );
      $publish_error = $this->publish_post_draft_api(
        $json->draftId,
        $api_post_id,
        $publication_time,
        $send_notification,
        (get_post_meta($wp_post_id, 'crowdaa_notification_content', true) ?: null),
        (get_post_meta($wp_post_id, 'crowdaa_notification_title', true) ?: null),
      );
      if ($publish_error) {
        Crowdaa_Sync_Logs::log('Article publish error', $api_post_id, $publish_error);
        throw new Crowdaa_Sync_Post_Error(__('Post publish error : ', CROWDAA_SYNC_PLUGIN_NAME) . $publish_error);
      }

      if ($send_notification) {
        update_post_meta($wp_post_id, 'crowdaa_notification_sent', 'yes');
      }

      Crowdaa_Sync_Logs::log('Updated API article successfully', $api_post_id, $json->draftId);
    }
  }


  /**
   * Publish post from draft
   *
   * @since    1.0.0
   * @param $draft_id
   * @param $api_post_id
   * @return boolean
   */
  public function publish_post_draft_api(
    $draft_id,
    $api_post_id,
    $publication_time,
    $send_notification = false,
    $notification_content = null,
    $notification_title = null
  ) {
    $data = [
      'draftId'             => $draft_id,
      'date'                => gmdate(CROWDAA_SYNC_ISO_TIME_FORMAT, $publication_time),
      'sendNotifications'   => $send_notification,
      'notificationContent' => $notification_content,
      'notificationTitle'   => $notification_title,
    ];

    $response = $this->http_request('PUT', '/press/articles/' . $api_post_id . '/publish', $data);
    $err    = is_wp_error($response) ? $response->get_error_message() : null;
    if (!$err) {
      $body = wp_remote_retrieve_body($response);
      $json = json_decode($body);
    }

    if ($err) {
      Crowdaa_Sync_Logs::log('Publish API article query error', $api_post_id, $err);
      return ($err);
    } else {
      if (isset($json->message)) {
        Crowdaa_Sync_Logs::log('Publish API article error', $api_post_id, $json->message);
        return ($json->message);
      }
    }

    return (false);
  }

  /**
   * Create custom post on the API
   *
   * @since    1.0.0
   * @param $article_data
   */
  public function create_custom_article_api($article_data, $publication_time, $send_notification = false)
  {
    $auth_token = get_option('crowdaa_auth_token');
    if (!$auth_token) {
      throw new Crowdaa_Sync_Error(__('Not connected to the API', CROWDAA_SYNC_PLUGIN_NAME));
    }

    if (!$article_data['categoryId'] && !$article_data['categoriesId']) {
      throw new Crowdaa_Sync_Post_Skip_Error(__('Unable to create article without Category', CROWDAA_SYNC_PLUGIN_NAME));
    }

    if (!$article_data['pictures'] && !$article_data['videos']) {
      throw new Crowdaa_Sync_Post_Skip_Error(__('Cannot sync article without any image and video!', CROWDAA_SYNC_PLUGIN_NAME));
    }

    if (!$article_data['title'] || !$article_data['md']) {
      throw new Crowdaa_Sync_Post_Skip_Error(__('Cannot sync article without any title or content!', CROWDAA_SYNC_PLUGIN_NAME));
    }

    Crowdaa_Sync_Logs::log('Creating API custom article post', wp_json_encode($article_data));

    $response = $this->http_request('POST', '/press/articles', $article_data);
    $err    = is_wp_error($response) ? $response->get_error_message() : null;
    if (!$err) {
      $body = wp_remote_retrieve_body($response);
      $json = json_decode($body);
    }

    if ($err) {
      Crowdaa_Sync_Logs::log('Create custom API article query error', $err);
      throw new Crowdaa_Sync_Post_Error(sprintf(__('Custom article creation error, query response : %s', CROWDAA_SYNC_PLUGIN_NAME), $err));
    } else if (!$json) {
      Crowdaa_Sync_Logs::log('Create custom API article data parsing error', $response);
      throw new Crowdaa_Sync_Post_Error(sprintf(__('Custom article creation error, response : %s', CROWDAA_SYNC_PLUGIN_NAME), $response));
    } else {
      if (isset($json->message)) {
        throw new Crowdaa_Sync_Post_Error(sprintf(__('Custom article creation error, API response : %s', CROWDAA_SYNC_PLUGIN_NAME), $json->message));
      }

      $publish_error = $this->publish_post_draft_api(
        $json->draftId,
        $json->articleId,
        $publication_time,
        $send_notification
      );
      if ($publish_error) {
        Crowdaa_Sync_Logs::log('Custom article first publish error', $json->articleId, $publish_error);
        throw new Crowdaa_Sync_Post_Error(__('Post first publish error : ', CROWDAA_SYNC_PLUGIN_NAME) . $publish_error);
      }

      Crowdaa_Sync_Logs::log('Created custom API article', $json->articleId);

      return ($json->articleId);
    }
  }

  /**
   * Update custom post on the API
   *
   * @since    1.0.0
   * @param $article_data
   */
  public function update_custom_article_api($article_data, $publication_time, $send_notification = false)
  {
    $auth_token = get_option('crowdaa_auth_token');
    if (!$auth_token) {
      throw new Crowdaa_Sync_Error(__('Not connected to the API', CROWDAA_SYNC_PLUGIN_NAME));
    }

    if (!$article_data['categoryId'] && !$article_data['categoriesId']) {
      throw new Crowdaa_Sync_Post_Skip_Error(__('Unable to update article without Category', CROWDAA_SYNC_PLUGIN_NAME));
    }

    if (!$article_data['pictures'] && !$article_data['videos']) {
      throw new Crowdaa_Sync_Post_Skip_Error(__('Cannot sync article without any image and video!', CROWDAA_SYNC_PLUGIN_NAME));
    }

    if (!$article_data['title'] || !$article_data['md']) {
      throw new Crowdaa_Sync_Post_Skip_Error(__('Cannot sync article without any title or content!', CROWDAA_SYNC_PLUGIN_NAME));
    }

    Crowdaa_Sync_Logs::log('Updating API custom article post', wp_json_encode($article_data));

    $response = $this->http_request('PUT', '/press/articles', $article_data);
    $err    = is_wp_error($response) ? $response->get_error_message() : null;
    if (!$err) {
      $body = wp_remote_retrieve_body($response);
      $json = json_decode($body);
    }

    if ($err) {
      Crowdaa_Sync_Logs::log('Update custom API article query error', $err);
      throw new Crowdaa_Sync_Post_Error(sprintf(__('Custom article update error, query response : %s', CROWDAA_SYNC_PLUGIN_NAME), $err));
    } else if (!$json) {
      Crowdaa_Sync_Logs::log('Update custom API article data parsing error', $response);
      throw new Crowdaa_Sync_Post_Error(sprintf(__('Custom article update error, response : %s', CROWDAA_SYNC_PLUGIN_NAME), $response));
    } else {
      if (isset($json->message)) {
        throw new Crowdaa_Sync_Post_Error(sprintf(__('Custom article update error, API response : %s', CROWDAA_SYNC_PLUGIN_NAME), $json->message));
      }

      $publish_error = $this->publish_post_draft_api(
        $json->draftId,
        $json->articleId,
        $publication_time,
        $send_notification
      );
      if ($publish_error) {
        Crowdaa_Sync_Logs::log('Custom article publish error', $json->articleId, $publish_error);
        throw new Crowdaa_Sync_Post_Error(__('Post publish error : ', CROWDAA_SYNC_PLUGIN_NAME) . $publish_error);
      }

      Crowdaa_Sync_Logs::log('Updated custom API article', $json->articleId);

      return ($json->articleId);
    }
  }

  /**
   * Create post from the API
   *
   * @since    1.0.0
   * @param $category_id
   * @param $wp_post_id
   */
  public function create_post_api($category_id, $wp_post_id, $feed_display = true)
  {
    if (!$category_id) {
      Crowdaa_Sync_Logs::log('Unable to sync article without Category or Media in Gallery', $wp_post_id);
      throw new Crowdaa_Sync_Post_Skip_Error(__('Unable to sync article without Category or Media in Gallery', CROWDAA_SYNC_PLUGIN_NAME));
    }

    $auth_token = get_option('crowdaa_auth_token');
    if (!$auth_token) {
      throw new Crowdaa_Sync_Error(__('Not connected to the API', CROWDAA_SYNC_PLUGIN_NAME));
    }

    $api_media_data = $this->fetch_post_api_medias($wp_post_id);

    $api_feedpicture_id = $api_media_data['api_feedpicture_id'];
    $videos_id = $api_media_data['videos_id'];
    $pictures_id = $api_media_data['pictures_id'];

    if (!$pictures_id && !$videos_id) {
      if (!$api_feedpicture_id) {
        Crowdaa_Sync_Logs::log('Cannot sync article without any image or video', $wp_post_id);
        throw new Crowdaa_Sync_Post_Skip_Error(__('Cannot sync article without any image or video!', CROWDAA_SYNC_PLUGIN_NAME));
      } else {
        $pictures_id = [$api_feedpicture_id];
      }
    }

    $fullscreen = (get_post_meta($wp_post_id, 'display_option_fullscreen', true) === 'yes');
    $post_content = get_post_field('post_content', $wp_post_id);
    $post_author = get_the_author_meta('display_name', get_post_field('post_author', $wp_post_id));
    $publication_time = get_post_datetime($wp_post_id)->getTimestamp();

    $wp_post_title = get_the_title($wp_post_id) ?: __('Without title', CROWDAA_SYNC_PLUGIN_NAME);
    $wp_post_title = html_entity_decode($wp_post_title, ENT_QUOTES);
    $data = [
      'actions'        => [],
      'authorName'     => $post_author,
      'feedPicture'    => $api_feedpicture_id,
      'md'             => $post_content ?: __('Without content', CROWDAA_SYNC_PLUGIN_NAME),
      'pictures'       => $pictures_id,
      'summary'        => $wp_post_title,
      'hideFromFeed'   => !$feed_display,
      'productId'      => '',
      'title'          => $wp_post_title,
      'videos'         => $videos_id,
      'mediaCaptions'  => implode(' | ', $api_media_data['captions']),
      'displayOptions' => [
        'fullscreen' => $fullscreen,
      ],
    ];
    if (is_array($category_id)) {
      $data['categoriesId'] = $category_id;
    } else {
      $data['categoryId'] = $category_id;
    }
    $eventStartDate = get_post_meta($wp_post_id, 'crowdaa_event_start', true);
    $eventEndDate = get_post_meta($wp_post_id, 'crowdaa_event_end', true);
    if ($eventStartDate) {
      $data['eventStartDate'] = $eventStartDate;
    }
    if ($eventEndDate) {
      $data['eventEndDate'] = $eventEndDate;
    }
    $data = apply_filters('crowdaa_sync_api_create_article_payload', $data, $wp_post_id);

    Crowdaa_Sync_Logs::log('Creating API post', $wp_post_id, wp_json_encode($data));

    $response = $this->http_request('POST', '/press/articles', $data);
    $err    = is_wp_error($response) ? $response->get_error_message() : null;
    if (!$err) {
      $body = wp_remote_retrieve_body($response);
      $json = json_decode($body);
    }

    if ($err) {
      Crowdaa_Sync_Logs::log('Create API post query error', $wp_post_id, $err);
      throw new Crowdaa_Sync_Post_Error(sprintf(__('Post creation error for wp post %d, query response : %s', CROWDAA_SYNC_PLUGIN_NAME), $wp_post_id, $err));
    } else if (!$json) {
      Crowdaa_Sync_Logs::log('Create API post data parsing error', $wp_post_id, $response);
      throw new Crowdaa_Sync_Post_Error(sprintf(__('Post creation error for wp post %d, response : %s', CROWDAA_SYNC_PLUGIN_NAME), $wp_post_id, $response));
    } else {
      if (isset($json->message)) {
        Crowdaa_Sync_Logs::log('Create API post API response error', $wp_post_id, $json->message);
        throw new Crowdaa_Sync_Post_Error(sprintf(__('Post creation error for wp post %d, API response : %s', CROWDAA_SYNC_PLUGIN_NAME), $wp_post_id, $json->message));
      }

      $send_notification = (
        get_post_meta($wp_post_id, 'crowdaa_notification_send', true) === 'yes' &&
        get_post_meta($wp_post_id, 'crowdaa_notification_sent', true) !== 'yes'
      );
      $publish_error = $this->publish_post_draft_api(
        $json->draftId,
        $json->articleId,
        $publication_time,
        $send_notification,
        (get_post_meta($wp_post_id, 'crowdaa_notification_content', true) ?: null),
        (get_post_meta($wp_post_id, 'crowdaa_notification_title', true) ?: null),
      );
      if ($publish_error) {
        Crowdaa_Sync_Logs::log('Article first publish error', $json->articleId, $json->draftId, $publish_error);
        throw new Crowdaa_Sync_Post_Error(__('Post first publish error : ', CROWDAA_SYNC_PLUGIN_NAME) . $publish_error);
      }

      update_post_meta($wp_post_id, 'api_post_id', $json->articleId);
      if ($send_notification) {
        update_post_meta($wp_post_id, 'crowdaa_notification_sent', 'yes');
      }
      Crowdaa_Sync_Logs::log('Created API post', $json->articleId);
    }
  }

  public function get_image_id_by_url($image_url)
  {
    global $wpdb;
    $attachment = $wpdb->get_col($wpdb->prepare("SELECT ID FROM $wpdb->posts WHERE guid='%s';", $image_url));
    return $attachment[0];
  }

  public function sync_post_images($wp_post_id)
  {
    $errors = [];
    $api_media_map_raw = get_post_meta($wp_post_id, 'api_media_map', true);
    $api_media_map = $api_media_map_raw ? unserialize($api_media_map_raw) : [];
    if (!$api_media_map_raw) {
      $api_media_map_raw = serialize($api_media_map);
      update_post_meta($wp_post_id, 'api_media_map', $api_media_map_raw);
    }

    $default_image = get_option('default_image');
    if (!$default_image) {
      throw new Crowdaa_Sync_Error(__('Default article image not set', CROWDAA_SYNC_PLUGIN_NAME));
    }
    $default_image = unserialize($default_image);

    $api_feedpicture_prev_attachment = null;
    $api_feedpicture_id_raw = get_post_meta($wp_post_id, 'api_feedpicture_id', true);
    if ($api_feedpicture_id_raw) {
      $api_feedpicture_id = unserialize($api_feedpicture_id_raw);
      $api_feedpicture_prev_attachment = $api_feedpicture_id['attachment_id'];
    }

    $thumbnail_id = get_post_thumbnail_id($wp_post_id) ?: null;
    if ($thumbnail_id !== $api_feedpicture_prev_attachment) {
      if (!$thumbnail_id) {
        delete_post_meta($wp_post_id, 'api_feedpicture_id');
      } else {
        $attachment_size = filesize(get_attached_file($thumbnail_id));
        if ($thumbnail_id && $attachment_size) {
          $img_data_arr = $this->get_attachment_data($thumbnail_id, $wp_post_id);
          if ($img_data_arr === false) {
            $errors[] = __('Missing featured image on post, skipping', CROWDAA_SYNC_PLUGIN_NAME);
          } else {
            $error = $this->create_image_api($img_data_arr, 'featured_img');
            if ($error) {
              throw new Crowdaa_Sync_Post_Error(__('Image synchronization error : ', CROWDAA_SYNC_PLUGIN_NAME) . $error);
            }
          }
        }
      }

      $api_feedpicture_id_raw = get_post_meta($wp_post_id, 'api_feedpicture_id', true);
      if ($api_feedpicture_id_raw) {
        $api_feedpicture_id = unserialize($api_feedpicture_id_raw);
        $api_feedpicture_prev_attachment = $api_feedpicture_id['attachment_id'];
      } else {
        $api_feedpicture_id = null;
        $api_feedpicture_prev_attachment = null;
      }
    }

    // Get images from gallery
    $gallery_ids = get_post_meta($wp_post_id, 'second_featured_img', true);

    if (empty($gallery_ids) && $thumbnail_id === null) {
      update_post_meta($wp_post_id, 'second_featured_img', $default_image['attachment_id']);
      $gallery_ids = get_post_meta($wp_post_id, 'second_featured_img', true);
    } else if ($gallery_ids === '' . $default_image['attachment_id'] && $thumbnail_id !== null) {
      $gallery_ids = '' . $thumbnail_id;
      $api_media_map[$api_feedpicture_id['api_id']] = [
        'attachment_id' => $thumbnail_id,
        'image' => true,
      ];
      update_post_meta($wp_post_id, 'api_media_map', serialize($api_media_map));
    }
    $api_media_reverse_map = [];
    if ($api_media_map) {
      foreach ($api_media_map as $api_id => $data) {
        $api_media_reverse_map[$data['attachment_id']] = $api_id;
      }
    }
    $gallery_ids = explode(',', $gallery_ids);
    if ($gallery_ids) {
      $bad_ids = [];

      foreach ($gallery_ids as $attachment_id) {
        if ($attachment_id == $default_image['attachment_id']) {
          $api_media_map[$default_image['api_id']] = [
            'attachment_id' => $attachment_id,
            'image' => true,
          ];
          update_post_meta($wp_post_id, 'api_media_map', serialize($api_media_map));
        } else if ($thumbnail_id && $attachment_id == $thumbnail_id) {
          $api_media_map[$api_feedpicture_id['api_id']] = [
            'attachment_id' => $attachment_id,
            'image' => true,
          ];
          update_post_meta($wp_post_id, 'api_media_map', serialize($api_media_map));
        } elseif ($attachment_id !== '' && !array_key_exists($attachment_id, $api_media_reverse_map)) {
          $img_data_arr = $this->get_attachment_data($attachment_id, $wp_post_id);
          if ($img_data_arr === false) {
            $errors[] = __('Missing gallery image/video on post, skipping', CROWDAA_SYNC_PLUGIN_NAME);
            $bad_ids[] = $attachment_id;
          } else {
            $error = $this->create_image_api($img_data_arr, 'gallery');
            if ($error) {
              throw new Crowdaa_Sync_Post_Error(__('Image synchronization error : ', CROWDAA_SYNC_PLUGIN_NAME) . $error);
            }
          }
        }
      }

      $api_media_map = unserialize(get_post_meta($wp_post_id, 'api_media_map', true));
      $to_remove_medias = [];
      foreach ($api_media_map as $api_id => $data) {
        $attachment_id = $data['attachment_id'];
        if (!in_array($attachment_id, $gallery_ids)) {
          $to_remove_medias[] = $api_id;
        }
      }

      if (count($to_remove_medias) > 0) {
        foreach ($to_remove_medias as $api_id) {
          unset($api_media_map[$api_id]);
        }
        update_post_meta($wp_post_id, 'api_media_map', serialize($api_media_map));
      }

      if (count($bad_ids) > 0) {
        $gallery_ids = array_diff($gallery_ids, $bad_ids);
        if (count($gallery_ids) === 0 && !$thumbnail_id) {
          throw new Crowdaa_Sync_Post_Error(__('No picture could be synchronized for post ', CROWDAA_SYNC_PLUGIN_NAME) . $wp_post_id);
        }
      }

      update_post_meta($wp_post_id, 'second_featured_img', implode(',', $gallery_ids));
    }

    return ($errors);
  }

  public function get_attachment_data($attachment_id, $wp_post_id = null)
  {
    $attachment_metadata = wp_get_attachment_metadata($attachment_id);
    $file                = get_attached_file($attachment_id);

    if (!$attachment_metadata) {
      return (false);
    }

    $file_type = isset($attachment_metadata['mime_type']) ? $attachment_metadata['mime_type'] : mime_content_type($file);
    $file_path_exploded = explode('/', (
      isset($attachment_metadata['file']) ? $attachment_metadata['file'] : $file
    ));
    $file_name = array_pop($file_path_exploded);
    $file_size = filesize($file);

    return ([
      'file_type' => $file_type,
      'file_name' => $file_name,
      'file_size' => $file_size,
      'attachment_id' => $attachment_id,
      'wp_post_id' => $wp_post_id,
    ]);
  }

  /**
   * Create image from the API
   *
   * @since    1.0.0
   * @param $img_data_arr
   * @return array
   */
  public function create_image_api($img_data_arr, $img_type, &$api_id = null)
  {
    $auth_token = get_option('crowdaa_auth_token');
    if (!$auth_token) {
      throw new Crowdaa_Sync_Error(__('Not logged in', CROWDAA_SYNC_PLUGIN_NAME));
    }

    $file_type     = isset($img_data_arr['file_type'])     ? $img_data_arr['file_type']     : null;
    $file_name     = isset($img_data_arr['file_name'])     ? $img_data_arr['file_name']     : null;
    $file_size     = isset($img_data_arr['file_size'])     ? $img_data_arr['file_size']     : null;
    $attachment_id = isset($img_data_arr['attachment_id']) ? $img_data_arr['attachment_id'] : null;
    $wp_post_id    = isset($img_data_arr['wp_post_id'])    ? $img_data_arr['wp_post_id']    : null;
    $image_url     = isset($img_data_arr['image_url'])     ? $img_data_arr['image_url']     : null;

    $data = [
      'files' => [
        [
          'name'          => $file_name,
          'type'          => $file_type,
          'size'          => $file_size,
          'typeSanatized' => 'image',
        ],
      ],
      'metadata' => [
        'opts' => '{"keepRatio": true}',
      ],
    ];

    $response = $this->http_request('POST', '/files', $data);
    $err    = is_wp_error($response) ? $response->get_error_message() : null;
    if (!$err) {
      $body = wp_remote_retrieve_body($response);
      $json = json_decode($body);
    }

    if ($err) {
      Crowdaa_Sync_Logs::log('Create media id query error', $file_name, $attachment_id);
      return ($err);
    }

    if (isset($json->message)) {
      Crowdaa_Sync_Logs::log(
        'Create media id error ',
        $file_name,
        $attachment_id,
        'message=' . $json->message,
        'args=' . $img_data_arr,
      );
      return ($json->message);
    }

    $json = (isset($json[0])) ? $json[0] : $json;
    $error = $this->add_image_to_the_aws($json->url, $file_type, $attachment_id, $image_url);
    if ($error) {
      return ($error);
    }

    if ($img_type === 'gallery') {
      $api_media_map_raw = get_post_meta($wp_post_id, 'api_media_map', true);
      $api_media_map = $api_media_map_raw ? unserialize($api_media_map_raw) : [];
      if (strpos($file_type, 'image') !== false) {
        $api_media_map[$json->id] = [
          'attachment_id' => $attachment_id,
          'image' => true,
        ];
      } else {
        $api_media_map[$json->id] = [
          'attachment_id' => $attachment_id,
          'video' => true,
        ];
      }
      update_post_meta($wp_post_id, 'api_media_map', serialize($api_media_map));
    } elseif ($img_type === 'featured_img') {
      update_post_meta($wp_post_id, 'api_feedpicture_id', serialize([
        'api_id' => $json->id,
        'attachment_id' => $attachment_id,
      ]));
    } elseif ($img_type === 'default_image') {
      update_option('default_image', serialize([
        'api_id' => $json->id,
        'attachment_id' => $attachment_id,
        'url' => $image_url,
      ]));
    } else if ($img_type === 'term_picture') {
      update_term_meta($wp_post_id, 'crowdaa_picture', serialize([
        'attachment_id' => $attachment_id,
        'api_id' => $json->id,
      ]));
    }

    $api_id = $json->id;

    Crowdaa_Sync_Logs::log(
      'Added media to the API',
      'source=' . ($wp_post_id ?: $image_url),
      'attachment_id=' . $attachment_id,
      'api_id=' . $json->id,
      'img_type=' . $img_type,
      'file_type=' . $file_type,
    );

    return (false);
  }

  public function add_image_to_the_aws($aws_url, $file_type, $attachment_id, $file = null)
  {
    if (!is_file($file) && $attachment_id) {
      $file = get_attached_file($attachment_id);
    }
    $filesize = filesize($file);

    $err = false;
    try {
      $fd = @fopen($file, 'rb');
      $client = new \GuzzleHttp\Client();
      $response = $client->request('PUT', $aws_url, [
        'body'    => $fd,
        'verify'  => CROWDAA_SYNC_CACERT_PATH,
        'headers' => array(
          'Content-Type: ' . $file_type,
          'Content-Length: ' . $filesize,
        ),
      ]);
      if (is_resource($fd)) {
        @fclose($fd);
      }
      $code = $response->getStatusCode();
      if ($code < 200 || $code >= 300) {
        throw new Exception($response->getReasonPhrase() . ' - ' . $response->getBody());
      }
    } catch (\Throwable $e) {
      $err = $e->getMessage();
    }

    if ($err) {
      Crowdaa_Sync_Logs::log(
        'Upload image to AWS error',
        'aws_url=' . $aws_url,
        'attachment_id=' . $attachment_id,
        'file_type=' . $file_type,
        'err=' . $err
      );
      return ($err);
    }

    return (false);
  }

  public function create_badge($name, $description, $public)
  {
    $data = [
      'name'          => $name,
      'description'   => $description,
      'management'    => $public ? 'public' : 'private-internal',
      'access'        => 'teaser',
      'isDefault'     => false,
      'validationUrl' => '',
    ];
    $data = apply_filters('crowdaa_sync_api_create_badge_payload', $data);

    $response = $this->http_request('POST', '/userBadges', $data);
    $err      = is_wp_error($response) ? $response->get_error_message() : null;
    if (!$err) {
      $body = wp_remote_retrieve_body($response);
      $json = json_decode($body);
    }

    if ($err) {
      Crowdaa_Sync_Logs::log('Create badge query error', $name, $err);
      throw new Crowdaa_Sync_Post_Error(__('Query error during badge creation : ', CROWDAA_SYNC_PLUGIN_NAME) . $err);
    } else {
      if (isset($json->message)) {
        Crowdaa_Sync_Logs::log('Create badge error', $name, $json->message);
        throw new Crowdaa_Sync_Post_Error(__('API error during badge creation : ', CROWDAA_SYNC_PLUGIN_NAME) . $json->message);
      }

      Crowdaa_Sync_Logs::log('Created badge', wp_json_encode($json->userBadge));

      return ($json->userBadge);
    }
  }

  public function update_badge($badge_id, $name, $description, $public)
  {
    $data = [
      'name'          => $name,
      'description'   => $description,
      'management'    => $public ? 'public' : 'private-internal',
      'access'        => 'teaser',
      'isDefault'     => false,
      'validationUrl' => '',
    ];
    $data = apply_filters('crowdaa_sync_api_update_badge_payload', $data);

    $response = $this->http_request('PUT', "/userBadges/$badge_id", $data);
    $err      = is_wp_error($response) ? $response->get_error_message() : null;
    if (!$err) {
      $body = wp_remote_retrieve_body($response);
      $json = json_decode($body);
    }

    if ($err) {
      Crowdaa_Sync_Logs::log('Update badge query error', $name, $err);
      throw new Crowdaa_Sync_Post_Error(__('Query error during badge update : ', CROWDAA_SYNC_PLUGIN_NAME) . $err);
    } else {
      if (isset($json->message)) {
        Crowdaa_Sync_Logs::log('Update badge error', $name, $json->message);
        throw new Crowdaa_Sync_Post_Error(__('API error during badge update : ', CROWDAA_SYNC_PLUGIN_NAME) . $json->message);
      }

      Crowdaa_Sync_Logs::log('Updated badge', wp_json_encode($json->userBadge));

      return ($json->userBadge);
    }
  }

  public function remove_badge($badge_id)
  {
    $response = $this->http_request('DELETE', "/userBadges/$badge_id");
    $err      = is_wp_error($response) ? $response->get_error_message() : null;
    if (!$err) {
      $body = wp_remote_retrieve_body($response);
      $json = json_decode($body);
    }

    if ($err) {
      Crowdaa_Sync_Logs::log('Delete badge query error', $badge_id, $err);
      throw new Crowdaa_Sync_Post_Error(__('Query error during badge delete : ', CROWDAA_SYNC_PLUGIN_NAME) . $err);
    } else {
      if (isset($json->message)) {
        Crowdaa_Sync_Logs::log('Delete badge error', $badge_id, $json->message);
        throw new Crowdaa_Sync_Post_Error(__('API error during badge delete : ', CROWDAA_SYNC_PLUGIN_NAME) . $json->message);
      }

      Crowdaa_Sync_Logs::log('Deleted badge', $badge_id);
    }

    return (true);
  }

  public function sync_term_images($wp_term_id)
  {
    $synced_picture_prev_attachment = null;
    $synced_picture_ids_raw = get_term_meta($wp_term_id, 'crowdaa_picture', true);
    if ($synced_picture_ids_raw) {
      $synced_picture_ids = unserialize($synced_picture_ids_raw);
      $synced_picture_prev_attachment = $synced_picture_ids['attachment_id'];
    }

    $picture_id = get_term_meta($wp_term_id, '_category_image_id', true);
    if (!$picture_id) $picture_id = null;
    else $picture_id = '' . $picture_id;

    if ($synced_picture_prev_attachment !== $picture_id) {
      if ($picture_id) {
        $file_data = $this->get_attachment_data($picture_id, $wp_term_id);
        if ($file_data) {
          $api_id = null;
          $errors = $this->create_image_api($file_data, 'term_picture', $api_id);
          if ($errors) {
            Crowdaa_Sync_Logs::log('Term image upload error', wp_json_encode($file_data), wp_json_encode($errors));
            throw new Crowdaa_Sync_Category_Error(__('Errors when uploading category image for ', CROWDAA_SYNC_PLUGIN_NAME) . $wp_term_id . ' : ' . implode(' | ', $errors));
          }
        }
      } else {
        delete_term_meta($wp_term_id, 'crowdaa_picture');
      }
    }

    $synced_picture_ids_raw = get_term_meta($wp_term_id, 'crowdaa_picture', true);
    if ($synced_picture_ids_raw) {
      $synced_picture_ids = unserialize($synced_picture_ids_raw);
      $ret = $synced_picture_ids['api_id'];
      return ($synced_picture_ids['api_id']);
    }

    return (null);
  }

  /**
   * Create category on the API
   *
   * @since    1.0.0
   * @param $term_id
   * @param $cat_name
   * @param $cat_slug
   * @return object
   */
  public function create_category($cat_name, $cat_slug, $parent_category_id, $badges, $picture_id, $is_event)
  {
    $data = [
      'name'     => $cat_name,
      'pathName' => $cat_slug,
      'color'    => Crowdaa_Sync_Add_Info_API::rand_color(),
      'order'    => 1,
      'hidden'   => false,
      'picture'  => [],
      'parentId' => $parent_category_id,
      'action'   => '',
      'isEvent'  => $is_event,
    ];

    if (is_array($badges)) {
      $data['badges'] = $badges;
      $data['badgesAllow'] = 'any';
    }

    if ($picture_id) {
      $data['picture'][0] = $picture_id;
    }

    $data = apply_filters('crowdaa_sync_api_create_category_payload', $data);

    $response = $this->http_request('POST', '/press/categories', $data);
    $err    = is_wp_error($response) ? $response->get_error_message() : null;
    if (!$err) {
      $body = wp_remote_retrieve_body($response);
      $json = json_decode($body);
    }

    if ($err) {
      Crowdaa_Sync_Logs::log('Create category query error', $cat_name, $err);
      throw new Crowdaa_Sync_Category_Error(__('Query error during category creation : ', CROWDAA_SYNC_PLUGIN_NAME) . $err);
    } else {
      if (isset($json->message)) {
        Crowdaa_Sync_Logs::log('Create category error', $cat_name, $json->message);
        throw new Crowdaa_Sync_Category_Error(__('API error during category creation : ', CROWDAA_SYNC_PLUGIN_NAME) . $json->message);
      }

      Crowdaa_Sync_Logs::log('Created category', $cat_name, $json->_id);

      return ($json);
    }
  }

  /**
   * Update category from the API
   *
   * @since    1.0.0
   * @param $cat_id
   * @param $term_id
   * @param $cat_name
   * @param $cat_slug
   * @return boolean
   */
  public function update_category($cat_id, $cat_name, $cat_slug, $parent_category_id, $badges, $picture_id = false, $is_event = false)
  {
    $data = [
      'name'     => $cat_name,
      'pathName' => $cat_slug,
      'hidden'   => false,
      'parentId' => $parent_category_id,
      'action'   => '',
      'isEvent'  => $is_event,
      // 'picture'  => null,
      // 'color'    => null,
      // 'order'    => null,
    ];

    if (is_array($badges)) {
      $data['badges'] = $badges;
      $data['badgesAllow'] = 'any';
    }

    if ($picture_id !== false) {
      if ($picture_id) $data['picture'] = [$picture_id];
      else $data['picture'] = [];
    }

    $data = apply_filters('crowdaa_sync_api_update_category_payload', $data);

    $response = $this->http_request('PUT', '/press/categories/' . $cat_id, $data);
    $err    = is_wp_error($response) ? $response->get_error_message() : null;
    if (!$err) {
      $body = wp_remote_retrieve_body($response);
      $json = json_decode($body);
    }

    if ($err) {
      Crowdaa_Sync_Logs::log('Updated category query error', $cat_name, $err);
      throw new Crowdaa_Sync_Category_Error(__('Query error during category update : ', CROWDAA_SYNC_PLUGIN_NAME) . $err);
    } else {
      if (isset($json->message)) {
        Crowdaa_Sync_Logs::log('Updated category error', $json->message);
        throw new Crowdaa_Sync_Category_Error(__('API error during category update : ', CROWDAA_SYNC_PLUGIN_NAME) . $json->message);
      }

      Crowdaa_Sync_Logs::log('Updated category', $cat_name);

      /** API just returns 'true', so we must rebuild the object as it would have been returned... */
      $ret = (object) $data;
      $ret->_id = $cat_id;

      if (is_array($badges)) {
        $ret_badges = [];
        foreach ($badges as $badge_id) {
          $ret_badges[] = (object) ['id' => $badge_id];
        }
        $ret->badges = (object) [
          'list' => $ret_badges,
          'allow' => $data['badgesAllow'],
        ];
      }

      return ($ret);
    }
  }

  public function delete_category($cat_id)
  {
    $response = $this->http_request('DELETE', '/press/categories/' . $cat_id);
    $err    = is_wp_error($response) ? $response->get_error_message() : null;
    if (!$err) {
      $body = wp_remote_retrieve_body($response);
      $json = json_decode($body);
    }

    if ($err) {
      Crowdaa_Sync_Logs::log('Deleted category query error', $cat_id, $err);
      throw new Crowdaa_Sync_Category_Error(__('Query error during category delete : ', CROWDAA_SYNC_PLUGIN_NAME) . $err);
    } else {
      if (isset($json->message)) {
        Crowdaa_Sync_Logs::log('Deleted category error', $json->message);
        throw new Crowdaa_Sync_Category_Error(__('API error during category delete : ', CROWDAA_SYNC_PLUGIN_NAME) . $json->message);
      }

      Crowdaa_Sync_Logs::log('Deleted category', $cat_id);
    }
  }

  /**
   * Delete post from the API
   *
   * @since    1.0.0
   * @param $api_post_id
   * @return boolean
   */
  public function delete_post_api($api_post_id)
  {
    $response = $this->http_request('DELETE', '/press/articles/' . $api_post_id);
    $err    = is_wp_error($response) ? $response->get_error_message() : null;
    if (!$err) {
      $body = wp_remote_retrieve_body($response);
      $json = json_decode($body);
    }

    if ($err) {
      Crowdaa_Sync_Logs::log('Delete API post query error', $err);
      return ($err);
    } else {
      if (isset($json->message)) {
        Crowdaa_Sync_Logs::log('Delete API post error', $json->message);
        return ($json->message);
      }

      Crowdaa_Sync_Logs::log('Delete API post OK', $api_post_id);
    }

    return (false);
  }

  public function http_request($method, $url, $data = null, $user_headers = [])
  {
    $auth_token = get_option('crowdaa_auth_token');

    $headers = [
      'Cache-Control' => 'no-cache',
    ];

    $options = [
      'method'          => $method,
      'timeout'         => 45,
      'httpversion'     => '1.1',
      'sslverify'       => true,
      'sslcertificates' => CROWDAA_SYNC_CACERT_PATH,
      /** Set later :
     * 'headers'      => [],
     */
    ];

    if ($this->api_key) {
      $headers['X-Api-Key'] = $this->api_key;
    }
    if ($data) {
      $options['body'] = wp_json_encode($data);
      $headers['Content-Type'] = 'application/json';
    }
    if ($auth_token) {
      $headers['Authorization'] = 'Bearer ' . $auth_token;
    }

    foreach ($user_headers as $k => $v) {
      if ($v === null) {
        unset($headers[$k]);
      } else {
        $headers[$k] = $v;
      }
    }

    $options['headers'] = $headers;

    return (wp_remote_request($this->api_url . $url, $options));
  }

  public static function sanitize_api_password($password)
  {
    return (preg_replace('/[\x00-\x1F]/is', '', $password));
  }

  public static function sanitize_api_key($key)
  {
    return (preg_replace('/(\s|[\x00-\x1F\x7F-\xFF]|[^\x00-\xFF])/is', '', $key));
  }

  public static function sanitize_sync_domain_names($input)
  {
    $raw_list = explode(',', $input);

    $filtered_list = array_filter($raw_list, function ($value) {
      $valid_regex = '/^[a-zA-Z0-9](([a-zA-Z0-9]|-)*[a-zA-Z0-9])?(\.[a-zA-Z0-9](([a-zA-Z0-9]|-)*[a-zA-Z0-9])?)*$/';
      $trimmed = trim($value);
      if (!$trimmed) return false;
      if (!preg_match($valid_regex, $trimmed)) return false;
      return true;
    });

    return implode(',', $filtered_list);
  }
}
