(function ($) {
  'use strict';

  $(document).ready(function () {
    function escapeHtml(text) {
      return $('<div/>').text(text).html();
    }

    function renderOpQueueForArticle(data, field) {
      const list = data[field];
      if (!list) {
        return false;
      }
      if (list === 'disabled') {
        return '<ul><li>Synchronization disabled</li></ul>';
      }
      if (list.length === 0) {
        return false;
      }

      const render = list.reduce(function (acc, item) {
        acc +=
          '<li class="' +
          field +
          '"><i>' +
          'Post title: <strong>' +
          escapeHtml(item.post_name) +
          '</strong> ' +
          'Post CMS ID: <strong>' +
          escapeHtml(item.api_id ? item.api_id : 'New post') +
          '</strong>' +
          '</i></li>';
        return acc;
      }, '');

      return '<ul>' + render + '</ul>';
    }

    function renderOpQueueForCategory(data, field) {
      const list = data[field];
      if (!list) {
        return false;
      }
      if (list === 'disabled') {
        return '<ul><li>Synchronization disabled</li></ul>';
      }
      if (list.length === 0) {
        return false;
      }

      const render = list.reduce(function (acc, item) {
        acc += '<li class="' + field + '"><i title="' + escapeHtml(item.slug || '') + '">';
        if (item.name) {
          acc += 'Category : <strong>' + escapeHtml(item.name) + '</strong>';
        } else if (item.wp_id) {
          acc += 'WP Category ID : <strong>' + escapeHtml(item.wp_id) + '</strong>';
        } else if (item.api_id) {
          acc += 'API Category ID : <strong>' + escapeHtml(item.api_id) + '</strong>';
        }
        acc += '</i></li>';
        return acc;
      }, '');

      return '<ul>' + render + '</ul>';
    }

    function renderOpQueueForBadge(data, field) {
      const list = data[field];
      if (!list) {
        return false;
      }
      if (list === 'disabled') {
        return '<ul><li>Synchronization disabled</li></ul>';
      }
      if (list.length === 0) {
        return false;
      }

      const render = list.reduce(function (acc, item) {
        acc += '<li class="' + field + '"><i title="' + escapeHtml(item.description || '') + '">';
        if (item.name) {
          acc += 'Permission : <strong>' + escapeHtml(item.name) + '</strong>';
        } else if (item.wp_id) {
          acc += 'WP Permission ID : <strong>' + escapeHtml(item.wp_id) + '</strong>';
        } else if (item.api_id) {
          acc += 'API Permission ID : <strong>' + escapeHtml(item.api_id) + '</strong>';
        }
        acc += '</i></li>';
        return acc;
      }, '');

      return '<ul>' + render + '</ul>';
    }

    function renderOpQueue(data, showDates, renderCb) {
      let html = '';
      let renderedCount = 0;

      if (data.error) {
        return '<h3>' + escapeHtml(data.error) + '</h3>';
      }

      function renderThis(title, key) {
        const result = renderCb(data, key);
        if (result) {
          html += title;
          html += result;
          renderedCount++;
        }
      }

      renderThis('<h2>Synchronize elements from Crowdaa CMS to WordPress</h2>', 'api_to_wp');
      renderThis('<h2>Synchronize elements from WordPress to Crowdaa CMS</h2>', 'wp_to_api');
      renderThis('<h2>Synchronize new elements from Crowdaa CMS</h2>', 'only_api');
      renderThis('<h2>Synchronize new elements from WordPress</h2>', 'only_wp');
      if (data['remove_api'])
        renderThis('<h2>Elements to remove from Crowdaa CMS</h2>', 'remove_api');
      if (data['remove_wp']) renderThis('<h2>Elements to remove from Wordpress</h2>', 'remove_wp');

      if (renderedCount === 0) {
        html += '<i>Nothing to do</i>';
      }

      html += '<br />';

      function renderSyncFrom(field, text) {
        const fromTs = data[field + '_sync_from'];
        const toTs = data[field + '_last'];
        if (toTs === false) {
          html += '<p><b>' + text + '</b> not checked';
        } else {
          const fromDate = new Date(1000 * fromTs);
          const toDate = new Date(1000 * toTs);
          if (fromTs === toTs) {
            html +=
              '<p><b>' +
              text +
              '</b> changes scanned from ' +
              fromDate.toLocaleDateString() +
              '@' +
              fromDate.toLocaleTimeString();
          } else {
            html +=
              '<p><b>' +
              text +
              '</b> changes scanned between ' +
              fromDate.toLocaleDateString() +
              '@' +
              fromDate.toLocaleTimeString() +
              ' and ' +
              toDate.toLocaleDateString() +
              '@' +
              toDate.toLocaleTimeString();
          }
        }
      }

      if (showDates) {
        renderSyncFrom('wp_to_api', 'WordPress to API');
        renderSyncFrom('api_to_wp', 'API to WordPress');
      }

      return html;
    }

    $('#sync-duration-field').on('change keyup keydown', function () {
      const $field = $('#sync-duration-field');
      const val = parseInt($field.val(), 10);
      const min = parseInt($field.attr('min'));
      if (!val || val < min) {
        $field.addClass('invalid-input');
      } else {
        $field.removeClass('invalid-input');
      }
    });

    function updateFeedCategoriesSelect() {
      const $field = $('#crowdaa-sync-feed-categories-checkbox');
      const $target = $('#crowdaa-sync-feed-categories-select-area');
      if ($field.prop('checked')) {
        $target.hide();
      } else {
        $target.show();
      }
    }
    $('#crowdaa-sync-feed-categories-checkbox').on('change', updateFeedCategoriesSelect);
    updateFeedCategoriesSelect();

    function updateSyncCategoriesSelect() {
      const $field = $('#crowdaa-sync-categories-mode-whitelist-checkbox');
      const $whitelistText = $('#crowdaa-sync-categories-explanation-whitelist');
      const $blacklistText = $('#crowdaa-sync-categories-explanation-blacklist');
      if ($field.prop('checked')) {
        $whitelistText.show();
        $blacklistText.hide();
      } else {
        $whitelistText.hide();
        $blacklistText.show();
      }
    }
    $('#crowdaa-sync-categories-mode-whitelist-checkbox').on('change', updateSyncCategoriesSelect);
    updateSyncCategoriesSelect();

    let pluginApiKeyDisplayTimeout = null;
    $('#plugin_api_key').focus(function (e) {
      e.preventDefault();

      $(e.target).select();
      document.execCommand('copy');

      const $display = $('#plugin_api_key-copied');
      $display.text('Copied!');

      if (pluginApiKeyDisplayTimeout !== null) clearTimeout(pluginApiKeyDisplayTimeout);
      pluginApiKeyDisplayTimeout = setTimeout(() => {
        $display.text('');
        pluginApiKeyDisplayTimeout = null;
      }, 3000);
    });

    $('#crowdaa-reset-request').click(function (e) {
      e.preventDefault();

      $('#crowdaa-reset-form').show();
    });

    $('#crowdaa-reset-dismiss').click(function (e) {
      e.preventDefault();

      $('#crowdaa-reset-form').hide();
    });

    /**
     * Ajax add posts and terms
     * */
    $('#sync-button').click(function (e) {
      e.preventDefault();

      const $loader = $('.loader');
      const $sync_results = $('#sync-results');
      const $opqueue = $('#opqueue');

      $.ajax({
        type: 'POST',
        url: ajax_object.ajax_url,
        data: {
          action: 'synchronization',
        },
        beforeSend: function () {
          $loader.fadeIn();
          $sync_results.fadeIn();
        },
        complete: function () {
          $loader.hide();
        },
        success: function (data) {
          let output;
          if (data.error) {
            output = '<p class="sync-error">' + escapeHtml(data.error) + '</p>';
          } else {
            output = '<p class="sync-success">' + escapeHtml(data.success) + '</p>';
          }

          if (data.opqueue.categories) {
            if (data.error) {
              output += '<p>Here are the categories operations that were attempted :</p>';
            } else {
              output += '<p>Here are the categories operations that were done :</p>';
            }
            output += renderOpQueue(data.opqueue.categories, false, renderOpQueueForCategory);
          }

          if (data.opqueue.badges) {
            if (data.error) {
              output += '<p>Here are the permission operations that were attempted :</p>';
            } else {
              output += '<p>Here are the permission operations that were done :</p>';
            }
            output += renderOpQueue(data.opqueue.badges, false, renderOpQueueForBadge);
          }

          if (data.opqueue.articles) {
            if (data.error) {
              output += '<p>Here are the article operations that were attempted :</p>';
            } else {
              output += '<p>Here are the article operations that were done :</p>';
            }
            output += renderOpQueue(data.opqueue.articles, true, renderOpQueueForArticle);
          }

          $opqueue.html(output);
        },
        error: function (err) {
          $opqueue.html(
            '<h4 class="sync-error">Unknown error : ' +
              err.status +
              ' ' +
              escapeHtml(err.statusText) +
              '</h4>' +
              '<p class="sync-error">Details : ' +
              escapeHtml(err.responseText) +
              '</p>',
          );
        },
      });
    });

    $('#sync-opqueue-button').click(function (e) {
      e.preventDefault();

      const $sync_results = $('#sync-results');
      const $opqueue = $('#opqueue');
      const $loader = $('.loader');

      $.ajax({
        type: 'POST',
        url: ajax_object.ajax_url,
        data: {
          action: 'get_opqueue',
        },
        beforeSend: function () {
          $loader.fadeIn();
          $sync_results.fadeIn();
        },
        complete: function () {
          $loader.hide();
        },
        success: function (data) {
          if (data.error) {
            $opqueue.html('<p class="sync-error">' + escapeHtml(data.error) + '</p>');
          } else {
            $opqueue.html(
              '<p>Here are the category operations that should/will be done :</p>' +
                renderOpQueue(data.categories, false, renderOpQueueForCategory) +
                '<p>Here are the permission operations that should/will be done :</p>' +
                renderOpQueue(data.badges, false, renderOpQueueForBadge) +
                '<p>Here are the article operations that should/will be done :</p>' +
                renderOpQueue(data.articles, true, renderOpQueueForArticle),
            );
          }
        },
        error: function (err) {
          $opqueue.html(
            '<h4 class="sync-error">Unknown error : ' +
              err.status +
              ' ' +
              escapeHtml(err.statusText) +
              '</h4>' +
              '<p class="sync-error">Details : ' +
              escapeHtml(err.responseText) +
              '</p>',
          );
        },
      });
    });

    $('#sync-tail-logs-button').click(function (e) {
      e.preventDefault();

      const $sync_results = $('#sync-results');
      const $opqueue = $('#opqueue');
      const $loader = $('.loader');

      $.ajax({
        type: 'POST',
        url: ajax_object.ajax_url,
        data: {
          action: 'tail_logs',
        },
        beforeSend: function () {
          $loader.fadeIn();
          $sync_results.fadeIn();
        },
        complete: function () {
          $loader.hide();
        },
        success: function (data) {
          if (!(data instanceof Array)) {
            $opqueue.html('<p class="sync-error">Invalid data returned from server.</p>');
          } else {
            $opqueue.html('<p>Here are the last logs :</p>' + data.map(escapeHtml).join('<br>'));
          }
        },
        error: function (err) {
          $opqueue.html(
            '<h4 class="sync-error">Unknown error : ' +
              err.status +
              ' ' +
              escapeHtml(err.statusText) +
              '</h4>' +
              '<p class="sync-error">Details : ' +
              escapeHtml(err.responseText) +
              '</p>',
          );
        },
      });
    });

    // Gallery in the admin panel
    $('.upload_gallery_button').click(function (event) {
      var current_gallery = $(this).closest('label');
      if (event.currentTarget.id === 'clear-gallery') {
        //remove value from input
        current_gallery.find('.gallery_values').val('').trigger('change');

        //remove preview images
        current_gallery.find('.gallery-screenshot').html('');
        return;
      }

      // Make sure the media gallery exists
      if (typeof wp === 'undefined' || !wp.media || !wp.media.gallery) {
        return;
      }

      event.preventDefault();
      var frame = (wp.media.frames.items = wp.media({
        title: 'Add to Gallery',
        multiple: true,
        button: {
          text: 'Select',
        },
        library: {
          type: ['video', 'image'],
        },
      }));

      if (frame) {
        frame.on('select', function () {
          current_gallery.find('.gallery-screenshot').html('');

          var element,
            preview_html = '';
          var ids = frame
            .state()
            .get('selection')
            .map(function (e) {
              element = e.toJSON();
              if (element.filesizeInBytes != undefined && element.filesizeInBytes <= 400000000) {
                if (
                  element.sizes !== undefined &&
                  element.sizes.thumbnail !== undefined &&
                  element.type === 'image'
                ) {
                  preview_html =
                    "<div class='screen-thumb'><img src='" +
                    element.sizes.thumbnail.url +
                    "'/></div>";
                } else {
                  if (element.type === 'image') {
                    preview_html =
                      "<div class='screen-thumb'><img src='" + element.url + "'/></div>";
                  } else {
                    preview_html =
                      '<div class="attachment-preview video js--select-attachment type-video subtype-mp4 landscape" res="' +
                      element.url +
                      '"><div class="thumbnail"> <div class="centered"> <img src="' +
                      element.icon +
                      '" class="icon" draggable="false" alt=""> </div> <div class="filename"> <div>' +
                      element.filename +
                      '</div> </div> </div>';
                  }
                }
              } else {
                alert(
                  'The ' +
                    element.filename +
                    ' file is very large. Uploaded files must be no more than 400 MB',
                );
              }

              current_gallery.find('.gallery-screenshot').append(preview_html);
              return e.id;
            });

          current_gallery.find('.gallery_values').val(ids.join(',')).trigger('change');
        });

        const initialIds = current_gallery.find('.gallery_values').val().split(',');
        if (initialIds.length > 0) {
          frame.on('open', function () {
            const selection = frame.state().get('selection');

            const attachments = initialIds
              .map(function (id) {
                var attachment = wp.media.attachment(id);
                if (!attachment) return null;
                attachment.fetch();
                return attachment;
              })
              .filter((att) => att);

            selection.add(attachments);
          });
        }
      }

      frame.open();

      return false;
    });
  });
})(jQuery);
