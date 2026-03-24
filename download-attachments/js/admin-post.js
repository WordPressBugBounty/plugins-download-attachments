(function(){"use strict";(function($) {
  var tableInitialized = false;
  var filesTable = null;
  function getColumnTypes() {
    return daArgsPost.columnTypes.map(function(column) {
      if (column.orderable === false) return column;
      return $.extend({}, column, {
        orderSequence: ["asc", "desc"]
      });
    });
  }
  $(function() {
    var daEditFrame = null;
    var daAddFrame = null;
    daAddFrame = wp.media({
      frame: "select",
      title: daArgsPost.addTitle,
      multiple: "add",
      button: {
        text: daArgsPost.buttonAddNewFile
      },
      states: [
        new wp.media.controller.Library(
          daArgsPost.library == 0 ? {
            multiple: "add",
            priority: 20,
            filterable: false,
            library: wp.media.query({
              post_parent: wp.media.model.settings.post.id
            })
          } : {
            multiple: "add",
            priority: 20,
            filterable: "all"
          }
        )
      ]
    });
    daAddFrame.on("open", function() {
      var selection = daAddFrame.state().get("selection");
      var id;
      var attachment;
      selection.reset([]);
      $.each($('#da-files tbody tr[id^="att"]'), function() {
        id = $(this).attr("id").split("-")[1];
        if (id !== "") {
          attachment = wp.media.attachment(id);
          attachment.fetch();
          selection.add(attachment ? [attachment] : []);
        }
      });
    });
    daAddFrame.on("close", function() {
      $("#da-add-new-file input").prop("disabled", false);
    });
    daAddFrame.on("select", function() {
      var library = daAddFrame.state().get("selection");
      var ids = library.pluck("id");
      var attachments = [];
      var id;
      $.each($('#da-files tbody tr[id^="att"]'), function(i) {
        id = $(this).attr("id").split("-")[1];
        if (id !== "") attachments[i] = parseInt(id);
      });
      var attachments_add = $.grep(ids, function(i) {
        return $.inArray(i, attachments) < 0;
      });
      var attachments_remove = $.grep(attachments, function(i) {
        return $.inArray(i, ids) < 0;
      });
      if (attachments_add.length > 0) {
        $("#da-spinner").addClass("is-active");
        $.post(ajaxurl, {
          action: "da-new-file",
          danonce: daArgsPost.addNonce,
          html: daAddFrame.link,
          post_id: wp.media.view.settings.post.id,
          attachments_ids: attachments_add.length > 0 ? attachments_add : ["empty"]
        }).done(function(data) {
          try {
            var json = JSON.parse(data);
            if (json.status === "OK") {
              var infoRow2 = $("#da-files tbody tr#da-info");
              if (infoRow2.length === 1) {
                infoRow2.fadeOut(300, function() {
                  $(this).remove();
                  $("#da-files tbody").append(json.files);
                  $("#da-files tbody tr").fadeIn(300);
                  initDataTable();
                });
              } else {
                for (const row of json.files) {
                  var node = $.parseHTML(row);
                  filesTable.row.add(node[0]);
                }
                filesTable.on("draw", function() {
                  $("#da-files tbody tr").fadeIn(300);
                });
                filesTable.draw();
              }
              $("#da-infobox").html("").fadeOut(300);
            } else {
              if (json.info !== "") $("#da-infobox").html(json.info).fadeIn(300);
              else $("#da-infobox").html("").fadeOut(300);
            }
          } catch (e) {
            $("#da-infobox").html(daArgsPost.internalUnknownError).fadeIn(300);
          }
          $("#da-spinner").removeClass("is-active");
        }).fail(function() {
          $("#da-infobox").html(daArgsPost.internalUnknownError).fadeIn(300);
          $("#da-spinner").removeClass("is-active");
        });
      }
      if (attachments_remove.length > 0) {
        $.each(attachments_remove, function(i, id2) {
          var node = $("tr#att-" + id2);
          node.fadeOut(300, function() {
            filesTable.row(node).remove().draw();
          });
        });
      }
    });
    $(document).on("click", "#da-add-new-file input", function() {
      if ($(this).is(":disabled")) return false;
      $(this).prop("disabled", true);
      daAddFrame.open();
    });
    $(document).on("click", ".da-edit-file", function() {
      if (daArgsPost.attachmentLink === "modal") {
        var fileID = parseInt($(this).closest('tr[id^="att"]').attr("id").split("-")[1]);
        var attachmentChanged = false;
        if (daEditFrame !== null) {
          daEditFrame.detach();
          daEditFrame.dispose();
          daEditFrame = null;
        }
        daEditFrame = wp.media({
          frame: "select",
          title: daArgsPost.editTitle,
          multiple: false,
          button: {
            text: daArgsPost.buttonEditFile
          },
          library: {
            post__in: fileID
          }
        });
        daEditFrame.on("open", function() {
          var attachment = wp.media.attachment(fileID);
          daEditFrame.$el.closest(".media-modal").addClass("da-edit-modal");
          attachment.fetch();
          daEditFrame.state().get("selection").add(attachment);
          daEditFrame.$el.on("change", ".setting input, .setting textarea", function() {
            attachmentChanged = true;
          });
        });
        daEditFrame.on("close", function() {
          daEditFrame.$el.closest(".media-modal").removeClass("da-edit-modal");
          if (attachmentChanged === true) {
            var title = daEditFrame.$el.find('.setting[data-setting="title"] input').val();
            var caption = daEditFrame.$el.find('.setting[data-setting="caption"] textarea').val();
            var description = daEditFrame.$el.find('.setting[data-setting="description"] textarea').val();
            $("tr#att-" + fileID + " td.file-title p").fadeOut(100, function() {
              $(this).find("a").html(title);
              $(this).find('span[class="description"]').html(description);
              $(this).find('span[class="caption"]').html(caption);
              $(this).fadeIn(300);
            });
          }
        });
        daEditFrame.open();
      }
    });
    $(document).on("click", ".da-remove-file", function() {
      if (confirm(daArgsPost.deleteFile)) {
        var attId = $(this).closest('tr[id^="att"]').attr("id").split("-")[1];
        var node = $("tr#att-" + parseInt(attId));
        node.fadeOut(300, function() {
          filesTable.row(node).remove().draw();
        });
      }
      return false;
    });
    $(document).on("click", ".da-save-files", function() {
      if ($(this).find("input").is(":disabled")) return false;
      var attachments = [];
      var postID = parseInt($("#da-files").attr("rel"));
      $("p.da-save-files input").prop("disabled", true);
      $("#da-spinner").addClass("is-active");
      $.each($('#da-files tr[id^="att"]'), function(i) {
        attachments[i] = [
          parseInt($(this).attr("id").split("-")[1]),
          $(this).find("td.file-exclude input.exclude-attachment").is(":checked") === true ? 1 : 0
        ];
      });
      $.post(ajaxurl, {
        action: "da-save-files",
        attachment_data: attachments.length > 0 ? attachments : ["empty"],
        post_id: postID,
        danonce: daArgsPost.saveNonce
      }).done(function(data) {
        try {
          var json = JSON.parse(data);
          if (json.status === "OK") $("#da-infobox").html("").fadeOut(300);
          if (json.status === "OK") $("#da-infobox").html("").fadeOut(300);
          else if (json.info !== "") $("#da-infobox").html(json.info).fadeIn(300);
        } catch (e) {
          $("#da-infobox").html(daArgsPost.internalUnknownError).fadeIn(300);
        }
        $("#da-spinner").removeClass("is-active");
        $("p.da-save-files input").prop("disabled", false);
      }).fail(function() {
        $("#da-infobox").html(daArgsPost.internalUnknownError).fadeIn(300);
        $("#da-infobox").html(daArgsPost.internalUnknownError).fadeIn(300);
        $("#da-spinner").removeClass("is-active");
        $("p.da-save-files input").prop("disabled", false);
      });
      return false;
    });
    $("#da-files tbody").sortable({
      axis: "y",
      cursor: "move",
      delay: 0,
      distance: 0,
      items: "tr",
      forceHelperSize: false,
      forcePlaceholderSize: false,
      handle: ".file-drag",
      opacity: 0.6,
      revert: true,
      scroll: true,
      tolerance: "pointer",
      helper: function(e, ui) {
        var original = ui.children();
        var helper = ui.clone();
        helper.children().each(function(i) {
          $(this).width(original.eq(i).width());
        });
        return helper;
      },
      start: function(e, ui) {
        $("#da-add-new-file input").prop("disabled", true);
        $("p.da-save-files input").prop("disabled", true);
      },
      stop: function(e, ui) {
        $("#da-add-new-file input").prop("disabled", false);
        $("p.da-save-files input").prop("disabled", false);
      }
    });
    var infoRow = $("#da-files tbody tr#da-info");
    if (infoRow.length !== 1) initDataTable();
  });
  function initDataTable() {
    if (tableInitialized) return;
    filesTable = $("#da-files").DataTable({
      paging: false,
      info: false,
      searching: false,
      ordering: true,
      order: [],
      columns: getColumnTypes(),
      language: {
        emptyTable: daArgsPost.noFiles
      }
    });
    tableInitialized = true;
  }
})(jQuery);
})();