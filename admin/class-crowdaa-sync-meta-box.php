<?php

/**
 * This class handles the metabox for this plugin
 *
 * @link       https://crowdaa.com
 * @since      1.0.0
 *
 * @package    Crowdaa_Sync_Meta_Box
 * @subpackage Crowdaa_Sync_Meta_Box/admin
 * @author     Crowdaa <contact@crowdaa.com>
 */
class Crowdaa_Sync_Meta_Box
{
  /**
   * Initialize the class and set its properties.
   *
   * @since    1.0.0
   */
  public function __construct()
  {
    add_action('add_meta_boxes', [$this, 'add_meta_boxes'], 10, 2);
    add_action('save_post', [$this, 'save_meta_boxes']);
  }


  /**
   * Add a meta box
   *
   * @since    1.0.0
   * @return boolean
   */
  function add_meta_boxes()
  {
    add_meta_box(
      'crowdaa_feat_img_slider',
      'Crowdaa-Sync: Featured Image Gallery',
      [$this, 'print_feat_img_slider_box'],
      'post',
      'advanced',
      'high',
      array(
        '__back_compat_meta_box' => false,
      )
    );
    add_meta_box(
      'crowdaa_feat_event_fields',
      'Crowdaa-Sync: Event articles',
      [$this, 'print_feat_event_fields'],
      'post',
      'advanced',
      'high',
      array(
        '__back_compat_meta_box' => false,
      )
    );
    add_meta_box(
      'crowdaa-sync-notification-send',
      'Crowdaa-Sync: Send notifications when published?',
      [$this, 'print_send_notification_checkbox'],
      'post',
      'advanced',
      'high',
      array(
        '__back_compat_meta_box' => false,
      )
    );
    add_meta_box(
      'crowdaa-sync-display-options',
      'Crowdaa-Sync: Mobile display options',
      [$this, 'print_display_options_checkbox'],
      'post',
      'advanced',
      'high',
      array(
        '__back_compat_meta_box' => false,
      )
    );
  }

  /**
   * Gallery form
   *
   * @since    1.0.0
   * @param $name
   * @param $value
   * @return boolean
   */
  function render_image_uploader_field($name, $value = '')
  {
?>
    <p>
      <i>
        <?php
        esc_html_e('Set Images or Videos for the Gallery. Hold Ctrl to select multiple items. Shortcode - [display_gallery]', CROWDAA_SYNC_PLUGIN_NAME);
        ?>
      </i>
    </p>

    <label>
      <div class="gallery-screenshot clearfix">
        <?php
        if ($value) {
          $ids = explode(',', $value);
          foreach ($ids as $attachment_id) {
            $img = wp_get_attachment_image_src($attachment_id, 'thumbnail');
            if ($img) {
        ?>
              <div class="screen-thumb">
                <img src="<?php echo esc_url($img[0]) ?>" />
              </div>
            <?php
            } else {
              $video_url = wp_get_attachment_url($attachment_id);
              $video_title = get_the_title($attachment_id);
            ?>
              <div class="attachment-preview video js--select-attachment type-video subtype-mp4 landscape" res="<?php echo esc_attr($video_url) ?>">
                <div class="thumbnail">
                  <div class="centered">
                    <img src="../wp-includes/images/media/video.png" class="icon" draggable="false" alt="" />
                  </div>
                  <div class="filename">
                    <div>
                      <?php echo esc_html($video_title) ?>
                    </div>
                  </div>
                </div>
              </div>
        <?php
            }
          }
        }
        ?>
      </div>

      <input id="edit-gallery" class="button upload_gallery_button" type="button" value="<?php esc_html_e('Edit Gallery', 'crowdaa'); ?>" />
      <input id="clear-gallery" class="button upload_gallery_button" type="button" value="<?php esc_html_e('Clear', 'crowdaa'); ?>" />
      <input type="hidden" name="<?php echo esc_attr($name); ?>" id="<?php echo esc_attr($name); ?>" value="<?php echo esc_attr($value); ?>" class="gallery_values" />
    </label>
  <?php
  }

  /**
   * Gallery form
   *
   * @since    1.0.0
   * @param $name
   * @param $value
   * @return boolean
   */
  function render_event_fields(
    $start_name,
    $end_name,
    $start_value = '',
    $end_value = ''
  ) {
    $now = wp_date('Y-m-dTG:i:00.000Z');
    if (!$start_value) $start_value = $now;
    if (!$end_value) $end_value = $now;
  ?>
    <p>
      <i>
        <?php esc_html_e('Set the event start and end dates below. These dates will only be used if the article is inside an event category.', CROWDAA_SYNC_PLUGIN_NAME); ?>
      </i>
    </p>

    <div class="crowdaa-meta-box-input-row">
      <label>
        <?php esc_html_e('Start date :', CROWDAA_SYNC_PLUGIN_NAME); ?>
        <br />
        <input type="datetime-local" id="<?php echo esc_attr("$start_name-display"); ?>" name="<?php echo esc_attr("$start_name-display"); ?>" placeholder="<?php esc_attr_e('Event start date & time', CROWDAA_SYNC_PLUGIN_NAME); ?>" />
        <input type="hidden" id="<?php echo esc_attr($start_name); ?>" name="<?php echo esc_attr($start_name); ?>" value="<?php echo esc_attr($start_value); ?>" />
        <br />
        <?php esc_html_e('End date :', CROWDAA_SYNC_PLUGIN_NAME); ?>
        <br />
        <input type="datetime-local" id="<?php echo esc_attr("$end_name-display"); ?>" name="<?php echo esc_attr("$end_name-display"); ?>" placeholder="<?php esc_attr_e('Event end date & time', CROWDAA_SYNC_PLUGIN_NAME); ?>" />
        <input type="hidden" id="<?php echo esc_attr($end_name); ?>" name="<?php echo esc_attr($end_name); ?>" value="<?php echo esc_attr($end_value); ?>" />
      </label>
    </div>

    <script type="text/javascript">
      const $start_display = document.getElementById(<?php echo json_encode("$start_name-display"); ?>);
      const $start_value = document.getElementById(<?php echo json_encode($start_name); ?>);
      const $end_display = document.getElementById(<?php echo json_encode("$end_name-display"); ?>);
      const $end_value = document.getElementById(<?php echo json_encode($end_name); ?>);

      function crowdaa_utc_date_to_local(date) {
        if (!date) {
          date = new Date().toISOString();
        }

        date = new Date(date);
        if (!date.getFullYear()) {
          return ('');
        }

        const pad2 = (x) => {
          x = `${x}`;
          while (x.length < 2) {
            x = `0${x}`;
          }
          return (x);
        }

        let ret = date.getFullYear();
        ret += '-';
        ret += pad2(date.getMonth() + 1);
        ret += '-';
        ret += pad2(date.getDate());
        ret += 'T';
        ret += pad2(date.getHours());
        ret += ':';
        ret += pad2(date.getMinutes());

        return (ret);
      }

      $start_display.value = crowdaa_utc_date_to_local($start_value.value);
      $end_display.value = crowdaa_utc_date_to_local($end_value.value);

      $start_display.addEventListener('change', () => {
        $start_value.value = new Date($start_display.value).toISOString();
      });
      $end_display.addEventListener('change', () => {
        $end_value.value = new Date($end_display.value).toISOString();
      });
    </script>
  <?php
  }

  /**
   * Image selector meta box
   *
   * @since    1.0.0
   * @param $post
   * @return string
   */
  function print_feat_img_slider_box($post)
  {
    wp_nonce_field('save_feat_gallery', 'feat_gallery_nonce');

    $meta_key = 'second_featured_img';
    $this->render_image_uploader_field($meta_key, get_post_meta($post->ID, $meta_key, true));
  }

  /**
   * Event start & end fields meta box
   *
   * @since    1.0.0
   * @param $post
   * @return string
   */
  function print_feat_event_fields($post)
  {
    wp_nonce_field('save_feat_event_fields', 'feat_event_fields_nonce');

    $meta_start = 'crowdaa_event_start';
    $meta_end = 'crowdaa_event_end';
    $this->render_event_fields(
      $meta_start,
      $meta_end,
      get_post_meta($post->ID, $meta_start, true),
      get_post_meta($post->ID, $meta_end, true)
    );
  }

  /**
   * Notification checkbox meta box
   *
   * @since    1.0.0
   * @param $post
   * @return string
   */
  function print_send_notification_checkbox($post)
  {
    $notification_content = get_post_meta($post->ID, 'crowdaa_notification_content', true) ?: '';
    $notification_title = get_post_meta($post->ID, 'crowdaa_notification_title', true) ?: '';
    wp_nonce_field('crowdaa_notification_checkbox', 'crowdaa_notification_checkbox_nonce');

  ?>
    <p>
      <i>
        <?php
        esc_html_e('Check this box if you want to send a notification when the article is published or right now if it is already published.', CROWDAA_SYNC_PLUGIN_NAME);
        ?>
      </i>
    </p>

    <div class="crowdaa-meta-box-input-row">
      <label>
        <?php
        esc_html_e('Send notification :', CROWDAA_SYNC_PLUGIN_NAME);
        ?>
        <input type="checkbox" name="crowdaa_send_post_notification" />
      </label>
    </div>
    <div class="crowdaa-meta-box-input-row">
      <label>
        <?php
        esc_html_e('Notification text (When both are not set, defaults to the article title & first characters) :', CROWDAA_SYNC_PLUGIN_NAME);
        ?>
        <br />
        <input style="display: inline-block; width: 500px;" type="text" name="crowdaa_send_post_notification_title" placeholder="<?php esc_attr_e('Notification title', CROWDAA_SYNC_PLUGIN_NAME); ?>" value="<?php echo esc_attr($notification_title); ?>" />
        <br />
        <input style="display: inline-block; width: 500px;" type="text" name="crowdaa_send_post_notification_content" placeholder="<?php esc_attr_e('Notification content', CROWDAA_SYNC_PLUGIN_NAME); ?>" value="<?php echo esc_attr($notification_content); ?>" />
      </label>
    </div>
  <?php
  }

  function print_display_options_checkbox($post)
  {
    $display_option_fullscreen = (get_post_meta($post->ID, 'display_option_fullscreen', true) === 'yes');
    wp_nonce_field('crowdaa_display_options_checkbox', 'crowdaa_display_options_checkboxes_nonce');

  ?>
    <p>
      <i>
        <?php
        esc_html_e('Display options for the mobile view of this article.', CROWDAA_SYNC_PLUGIN_NAME);
        ?>
      </i>
    </p>

    <div class="crowdaa-meta-box-input-row">
      <label>
        <?php
        esc_html_e('Display the article as full screen :', CROWDAA_SYNC_PLUGIN_NAME);
        ?>
        <input type="checkbox" name="crowdaa_display_option_fullscreen" <?php if ($display_option_fullscreen) echo esc_attr('checked'); ?> />
      </label>
    </div>
<?php
  }

  function save_gallery_meta_box($post_id)
  {
    if (!isset($_POST['feat_gallery_nonce'])) {
      return;
    } else if (!wp_verify_nonce($_POST['feat_gallery_nonce'], 'save_feat_gallery')) {
      return;
    } else if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
      return;
    } else if (!current_user_can('edit_post', $post_id)) {
      return;
    }

    if (isset($_POST['second_featured_img'])) {
      $imgs = array_reduce(explode(',', $_POST['second_featured_img']), function ($imgs, $id) {
        $intVal = (int) $id;
        if ($intVal > 0) {
          $imgs[] = $intVal;
        }
        return ($imgs);
      }, []);

      update_post_meta($post_id, 'second_featured_img', implode(',', $imgs));
    } else {
      update_post_meta($post_id, 'second_featured_img', '');
    }
  }

  function save_notifications_meta_box($post_id)
  {
    if (!isset($_POST['crowdaa_notification_checkbox_nonce'])) {
      return;
    } else if (!wp_verify_nonce($_POST['crowdaa_notification_checkbox_nonce'], 'crowdaa_notification_checkbox')) {
      return;
    } else if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
      return;
    } else if (!current_user_can('edit_post', $post_id)) {
      return;
    }

    if (isset($_POST['crowdaa_send_post_notification'])) {
      update_post_meta($post_id, 'crowdaa_notification_send', 'yes');
      update_post_meta($post_id, 'crowdaa_notification_sent', 'no');
      update_post_meta($post_id, 'crowdaa_notification_title', $_POST['crowdaa_send_post_notification_title'] ?: '');
      update_post_meta($post_id, 'crowdaa_notification_content', $_POST['crowdaa_send_post_notification_content'] ?: '');
    }
  }

  function save_display_options_meta_box($post_id)
  {
    if (!isset($_POST['crowdaa_display_options_checkboxes_nonce'])) {
      return;
    } else if (!wp_verify_nonce($_POST['crowdaa_display_options_checkboxes_nonce'], 'crowdaa_display_options_checkbox')) {
      return;
    } else if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
      return;
    } else if (!current_user_can('edit_post', $post_id)) {
      return;
    }

    if (isset($_POST['crowdaa_display_option_fullscreen'])) {
      update_post_meta($post_id, 'display_option_fullscreen', 'yes');
    } else {
      update_post_meta($post_id, 'display_option_fullscreen', 'no');
    }
  }

  function save_event_fields_meta_box($post_id)
  {
    if (!isset($_POST['feat_event_fields_nonce'])) {
      return;
    } else if (!wp_verify_nonce($_POST['feat_event_fields_nonce'], 'save_feat_event_fields')) {
      return;
    } else if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
      return;
    } else if (!current_user_can('edit_post', $post_id)) {
      return;
    }

    if (isset($_POST['crowdaa_event_start'])) {
      update_post_meta($post_id, 'crowdaa_event_start', $_POST['crowdaa_event_start']);
    }

    if (isset($_POST['crowdaa_event_end'])) {
      update_post_meta($post_id, 'crowdaa_event_end', $_POST['crowdaa_event_end']);
    }
  }

  /**
   * Save meta boxes data
   *
   * @since    1.0.0
   * @param $post_id
   * @return boolean
   */
  function save_meta_boxes($post_id)
  {
    $this->save_gallery_meta_box($post_id);
    $this->save_notifications_meta_box($post_id);
    $this->save_display_options_meta_box($post_id);
    $this->save_event_fields_meta_box($post_id);
  }
}
