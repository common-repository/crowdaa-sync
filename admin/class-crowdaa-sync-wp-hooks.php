<?php

/**
 * This file add most wordpress hooks to catch
 * events that matter to us.
 *
 * @link       https://crowdaa.com
 * @since      1.0.0
 *
 * @package    Crowdaa-Sync
 * @subpackage Crowdaa-Sync/admin
 * @author     Crowdaa <contact@crowdaa.com>
 */
class Crowdaa_Sync_WP_Hooks
{
  /**
   * Initialize the class and set its properties.
   *
   * @since    1.0.0
   */
  public function __construct()
  {
    add_action('init', [$this, 'on_init']);
    add_action('save_post', [$this, 'save_post_without_cat_notice']);
    add_action('save_post', [$this, 'set_need_to_sync_for_post']);
    add_action('admin_notices', [$this, 'sync_admin_notice']);
    add_action('before_delete_post', [$this, 'sync_delete_post']);
    add_shortcode('display_gallery', [$this, 'display_gallery']);

    // Event articles/categories hooks
    add_action(sprintf('%s_edit_form_fields', 'category'), [$this, 'custom_categories_edit_fields'], 99, 2);
    add_action(sprintf('%s_add_form_fields', 'category'), [$this, 'custom_categories_add_fields'], 20, 2);
    add_action('created_category', [$this, 'on_category_saved'], 10, 2);
    add_action('edited_category', [$this, 'on_category_saved'], 10, 2);
    add_filter('the_content', [$this, 'add_event_fields']);
  }

  /**
   * Register all new/custom fields for the API
   *
   * @since    1.0.0
   * @param $post_id
   * @return void
   */
  public function on_init()
  {
    register_meta(
      'term',
      'crowdaa_is_event',
      array(
        'object_type' => 'category',
        'single'       => true,
        'type'         => 'boolean',
        'description'  => 'Whether this category contain events (posts with a custom start and end dates)',
        'default'      => false,
        'show_in_rest' => true,
      )
    );
  }

  public function on_category_saved($term_id)
  {
    if (isset($_POST['crowdaa_is_event_field_sent']) && $_POST['crowdaa_is_event_field_sent'] === 'sent') {
      if (isset($_POST['crowdaa_is_event']) && $_POST['crowdaa_is_event'] !== '') {
        $value = $_POST['crowdaa_is_event'] === 'on' ? 'yes' : 'no';
        update_term_meta($term_id, 'crowdaa_is_event', $value);
      } else {
        update_term_meta($term_id, 'crowdaa_is_event', 'no');
      }
    }
  }

  public function custom_categories_add_fields()
  {
?>
    <div class="form-field term-meta-wrap">
      <label for="crowdaa_is_event">
        <?php esc_html_e('Event category', CROWDAA_SYNC_PLUGIN_NAME); ?>
      </label>
      <input type="checkbox" name="crowdaa_is_event" id="crowdaa_is_event" />
      <input name="crowdaa_is_event_field_sent" type="hidden" value="sent" checked />
      <p class="description">
        <?php esc_html_e('Whether this category contain events (posts with a custom start and end dates)', CROWDAA_SYNC_PLUGIN_NAME); ?>
      </p>
    </div>
  <?php
  }

  public function custom_categories_edit_fields($term)
  {
    $is_event = get_term_meta($term->term_id, 'crowdaa_is_event', true) === 'yes';
  ?>
    <tr class="form-field form-required term-name-wrap">
      <th scope="row">
        <label for="crowdaa_is_event"><?php esc_html_e('Event category', CROWDAA_SYNC_PLUGIN_NAME); ?></label>
      </th>
      <td>
        <input name="crowdaa_is_event" id="crowdaa_is_event" type="checkbox" <?php echo ($is_event ? 'checked' : ''); ?> />
        <input name="crowdaa_is_event_field_sent" type="hidden" value="sent" checked />
        <p class="description">
          <?php esc_html_e('Whether this category contain events (posts with a custom start and end dates)', CROWDAA_SYNC_PLUGIN_NAME); ?>
        </p>
      </td>
    </tr>
<?php
  }

  /**
   * Show admin notice
   *
   * @since    1.0.0
   * @param $post_id
   * @return void
   */
  public function save_post_without_cat_notice($post_id)
  {
    // if autosave or revision don't continue
    if (wp_is_post_autosave($post_id) || wp_is_post_revision($post_id)) {
      return;
    }

    if (isset($_POST['post_type']) && 'post' != $_POST['post_type']) {
      return;
    }

    $terms = get_the_terms($post_id, 'category');
    $gallery = get_post_meta($post_id, 'second_featured_img', true);

    if (empty($terms)) {
      $this->sync_admin_notice();
    }

    if (empty($gallery)) {
      $this->sync_admin_notice();
    }
  }

  /**
   * Set need sync option for synchronization
   *
   * @since    1.0.0
   * @return void
   */
  public function sync_admin_notice()
  {
    global $pagenow;
    global $post;
    if (($pagenow == 'post.php') && ($post->post_type == 'post')) {
      $post_type_taxonomy = 'category';
      $terms = get_the_terms(get_the_ID(), $post_type_taxonomy);
      if (empty($terms)) {
        Crowdaa_Sync_Admin_Display::admin_notice('error', __('Crowdaa-Sync: You will not be able to synchronize this post until you add a Sync category', CROWDAA_SYNC_PLUGIN_NAME));
      }
    }
  }

  /**
   * Set need sync option for post synchronization
   *
   * @since    1.0.0
   * @param $post_id
   * @return void
   */
  public function set_need_to_sync_for_post($post_id)
  {
    update_post_meta($post_id, 'crowdaa_need_sync', 'yes');
  }

  /**
   * Hide deleted post from WP to API
   *
   * @since    1.0.0
   * @param $post_id
   * @return void
   */
  public function sync_delete_post($post_id)
  {
    $post_api_id = get_post_meta($post_id, 'api_post_id', true);
    $sync_api    = new Crowdaa_Sync_API();
    if ($post_api_id) {
      $sync_api->delete_post_api($post_api_id);
    }
  }

  /**
   * Shortcode to display gallery in an article
   *
   * @since    1.0.0
   * @return string
   */
  function display_gallery()
  {
    $html          = '<div class="crowdaa_gallery">';
    $pictures_list = get_post_meta(get_the_ID(), 'second_featured_img', true);
    if ($pictures_list) {
      $ids = explode(',', $pictures_list);
      foreach ($ids as $attachment_id) {
        $img = wp_get_attachment_image_src($attachment_id, 'thumbnail');
        if ($img) {
          $html .= '<div class="screen-thumb"><img src="' . esc_url($img[0]) . '" /></div>';
        } else {
          $video_url = wp_get_attachment_url($attachment_id);
          $html     .= '<div class="screen-thumb"><video width="200" height="150" controls="controls">
                          <source src="' . $video_url . '" type="video/mp4">
                        </video></div>';
        }
      }
      return $html . '</div>';
    }
  }

  /**
   * Adds event start and end date for the given article (if set).
   *
   * @since    1.0.0
   * @return string
   */
  public function add_event_fields($content)
  {
    if (is_singular() && in_the_loop() && is_main_query()) {
      $post_id = get_the_ID();
      $terms = wp_get_post_terms($post_id, 'category');
      $is_event = false;
      foreach ($terms as $term) {
        if (get_term_meta($term->term_id, 'crowdaa_is_event', true) === 'yes') {
          $is_event = true;
          break;
        }
      }

      if ($is_event) {
        $eventStartDate = get_post_meta($post_id, 'crowdaa_event_start', true);
        $eventEndDate = get_post_meta($post_id, 'crowdaa_event_end', true);
        if (!$eventStartDate) $eventStartDate = date('Y-m-d\TH:i:s\.\0\0\0\Z');
        if (!$eventEndDate) $eventEndDate = date('Y-m-d\TH:i:s\.\0\0\0\Z');
        $atText = esc_html('at', CROWDAA_SYNC_PLUGIN_NAME);

        $eventStartDateJson = json_encode($eventStartDate);
        $eventEndDateJson = json_encode($eventEndDate);
        $startText = esc_html('This event starts the', CROWDAA_SYNC_PLUGIN_NAME);
        $eventStartCode = <<<SCRIPT
$startText
<script type="text/javascript">
(() => {
  const date = new Date($eventStartDateJson);
  document.currentScript.replaceWith(date.toLocaleDateString());
})();
</script>
$atText
<script type="text/javascript">
(() => {
  const date = new Date($eventStartDateJson);
  document.currentScript.replaceWith(date.toLocaleTimeString());
})();
</script>
SCRIPT;
        $endText = esc_html('and ends the', CROWDAA_SYNC_PLUGIN_NAME);
        $eventEndCode = <<<SCRIPT
$endText
<script type="text/javascript">
(() => {
  const date = new Date($eventEndDateJson);
  document.currentScript.replaceWith(date.toLocaleDateString());
})();
</script>
$atText
<script type="text/javascript">
(() => {
  const date = new Date($eventEndDateJson);
  document.currentScript.replaceWith(date.toLocaleTimeString());
})();
</script>.
SCRIPT;
        $rootStyle = 'font-style: italic;';
        $eventCode = <<<SCRIPT
<p style="$rootStyle">
  $eventStartCode
  $eventEndCode
</p>
SCRIPT;
        $content = $eventCode . $content;
      }

      return ($content);
    }

    return ($content);
  }
}
