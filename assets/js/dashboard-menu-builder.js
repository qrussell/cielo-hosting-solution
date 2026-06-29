jQuery(function ($) {
  var menuBuilder = {
    init: function () {
      this.initSortable();
      this.initAddEndpoint();
      this.initDeleteEndpoint();
      this.initSaveMenu();
      this.initResetMenu();
      this.initModal();
      this.initMediaUploader();
    },

    getItemIndex: function ($item) {
      var $list = $('#skyhshoso-menu-items');
      return $list.find('.skyhshoso-menu-item').index($item);
    },

    reindexItems: function () {
      var $list = $('#skyhshoso-menu-items');
      $list.find('.skyhshoso-menu-item').each(function (idx) {
        var $item = $(this);
        $item.find('input, select').each(function () {
          var name = $(this).attr('name');
          if (name) {
            $(this).attr('name', name.replace(/menu_items\[\d+\]/, 'menu_items[' + idx + ']'));
          }
        });
      });
    },

    initSortable: function () {
      $('#skyhshoso-menu-items').sortable({
        handle: '.skyhshoso-menu-item-handle',
        axis: 'y',
        placeholder: 'skyhshoso-menu-item-placeholder',
        update: function () {
          menuBuilder.reindexItems();
        }
      });
    },

    initAddEndpoint: function () {
      $('.skyhshoso-add-endpoint-btn').on('click', function () {
        $('#skyhshoso-add-endpoint-modal').show();
      });

      $('.skyhshoso-add-endpoint-confirm').on('click', function () {
        var title = $('#skyhshoso-new-endpoint-title').val().trim();
        var url = $('#skyhshoso-new-endpoint-url').val().trim();
        var visibility = $('#skyhshoso-new-endpoint-visibility').val();
        var icon = $('#skyhshoso-new-endpoint-icon').val().trim();

        if (!title) {
          alert('Please enter a menu label.');
          return;
        }
        if (!url) {
          alert('Please enter a URL.');
          return;
        }

        var id = 'custom_' + Date.now();

        var template = $('#skyhshoso-endpoint-template').html();
        var $list = $('#skyhshoso-menu-items');
        var index = $list.find('.skyhshoso-menu-item').length;

        var html = template
          .replace(/{id}/g, id)
          .replace(/{title}/g, title)
          .replace(/{url}/g, url)
          .replace(/{index}/g, index);

        var $el = $(html);
        $el.find('[name="menu_items[' + index + '][visibility]"]').val(visibility);
        if (icon) {
          $el.find('.skyhshoso-menu-item-icon .dashicons').removeClass().addClass('dashicons ' + icon);
          $el.find('[name="menu_items[' + index + '][icon]"]').val(icon);
        }

        $list.append($el);

        menuBuilder.closeModal();
        menuBuilder.reindexItems();
      });
    },

    initDeleteEndpoint: function () {
      $('#skyhshoso-menu-items').on('click', '.skyhshoso-delete-endpoint-btn', function () {
        if (!confirm(skyhshosoMenuBuilder.i18n.deleteConfirm)) {
          return;
        }
        $(this).closest('.skyhshoso-menu-item').fadeOut(300, function () {
          $(this).remove();
          menuBuilder.reindexItems();
        });
      });
    },

    initSaveMenu: function () {
      $('#skyhshoso-save-menu-btn').on('click', function () {
        var $btn = $(this);
        var $statusTop = $('.skyhshoso-menu-save-status');
        var $statusBottom = $('.skyhshoso-menu-save-status-bottom');

        $btn.prop('disabled', true).text(skyhshosoMenuBuilder.i18n.saving);
        $statusTop.text(skyhshosoMenuBuilder.i18n.saving).css('color', '#646970');
        $statusBottom.text(skyhshosoMenuBuilder.i18n.saving).css('color', '#646970');

        var items = [];
        $('#skyhshoso-menu-items').find('.skyhshoso-menu-item').each(function () {
          var $item = $(this);
          var $inputs = $item.find('input, select');

          var data = {};
          $inputs.each(function () {
            var name = $(this).attr('name');
            if (!name) return;
            var key = name.match(/\[(\w+)\]$/);
            if (!key) return;
            var k = key[1];

            if ($(this).is(':checkbox')) {
              data[k] = $(this).is(':checked') ? '1' : '0';
            } else if ($(this).is(':radio')) {
              if ($(this).is(':checked')) data[k] = $(this).val();
            } else {
              data[k] = $(this).val();
            }
          });

          data.id = $item.data('id');

          if (data.enabled === undefined || data.enabled === '0') {
            var enabledInput = $item.find('[name$="[enabled]"]');
            data.enabled = enabledInput.is(':checked') ? '1' : '0';
          }

          items.push(data);
        });

        $.ajax({
          url: skyhshosoMenuBuilder.ajaxUrl,
          type: 'POST',
          data: {
            action: 'skyhshoso_save_menu_items',
            nonce: skyhshosoMenuBuilder.nonce,
            menu_items: items
          },
          success: function (resp) {
            if (resp.success) {
              $statusTop.text(skyhshosoMenuBuilder.i18n.saved).css('color', '#00a32a');
              $statusBottom.text(skyhshosoMenuBuilder.i18n.saved).css('color', '#00a32a');
              setTimeout(function () {
                $statusTop.text('');
                $statusBottom.text('');
              }, 3000);
            } else {
              $statusTop.text(resp.data.message || skyhshosoMenuBuilder.i18n.error).css('color', '#d63638');
              $statusBottom.text(resp.data.message || skyhshosoMenuBuilder.i18n.error).css('color', '#d63638');
            }
          },
          error: function () {
            $statusTop.text(skyhshosoMenuBuilder.i18n.error).css('color', '#d63638');
            $statusBottom.text(skyhshosoMenuBuilder.i18n.error).css('color', '#d63638');
          },
          complete: function () {
            $btn.prop('disabled', false).text('Save Menu');
          }
        });
      });
    },

    initResetMenu: function () {
      $('.skyhshoso-reset-menu-btn').on('click', function () {
        if (!confirm('Reset the dashboard menu to default? All custom endpoints will be lost.')) {
          return;
        }

        $.ajax({
          url: skyhshosoMenuBuilder.ajaxUrl,
          type: 'POST',
          data: {
            action: 'skyhshoso_get_default_menu',
            nonce: skyhshosoMenuBuilder.nonce
          },
          success: function (resp) {
            if (resp.success) {
              location.reload();
            } else {
              alert(resp.data.message || 'Reset failed.');
            }
          },
          error: function () {
            alert('Reset request failed.');
          }
        });
      });
    },

    initModal: function () {
      $('.skyhshoso-modal-close, .skyhshoso-modal-close-btn, .skyhshoso-modal-backdrop').on('click', function () {
        menuBuilder.closeModal();
      });
      $(document).on('keydown', function (e) {
        if (e.key === 'Escape') {
          menuBuilder.closeModal();
        }
      });
    },

    closeModal: function () {
      $('#skyhshoso-add-endpoint-modal').hide();
      $('#skyhshoso-new-endpoint-title').val('');
      $('#skyhshoso-new-endpoint-url').val('');
      $('#skyhshoso-new-endpoint-visibility').val('all');
      $('#skyhshoso-new-endpoint-icon').val('');
    },

    initMediaUploader: function () {
      if (typeof wp !== 'undefined' && wp.media) {
        $('#skyhshoso-upload-logo-btn').on('click', function (e) {
          e.preventDefault();
          var frame = wp.media({
            title: 'Select Logo',
            multiple: false,
            library: { type: 'image' }
          });
          frame.on('select', function () {
            var attachment = frame.state().get('selection').first().toJSON();
            $('#skyhshoso-custom-logo-url').val(attachment.url);
            $('#skyhshoso-logo-preview').attr('src', attachment.url).show();
          });
          frame.open();
        });

        $('#skyhshoso-remove-logo-btn').on('click', function () {
          $('#skyhshoso-custom-logo-url').val('');
          $('#skyhshoso-logo-preview').hide();
        });
      }
    }
  };

  menuBuilder.init();
});
