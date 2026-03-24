(function(){"use strict";(function($) {
  var open_media_window = function() {
    var window = wp.media({
      frame: "select",
      title: daArgsPost.selectTitle,
      multiple: false,
      filterable: true,
      button: {
        text: daArgsPost.buttonInsertLink
      }
    });
    window.on("select", function() {
      var selected_file = window.state().get("selection").first().toJSON();
      var title = selected_file.title != "" ? selected_file.title : selected_file.filename;
      wp.media.editor.insert(
        '[download-attachment id="' + selected_file.id + '" title="' + title + '"]'
      );
    });
    window.open();
    return false;
  };
  tinymce.create("tinymce.plugins.download_attachments", {
    init: function(ed, url) {
      ed.addButton("download_attachments", {
        title: daArgsPost.selectTitle,
        icon: "icon dashicons-arrow-down-alt",
        onclick: function() {
          open_media_window();
        }
      });
    },
    createControl: function(n, cm) {
      return null;
    },
    getInfo: function() {
      return {
        longname: "Download Attachments",
        author: "Digital Factory",
        authorurl: "http://www.dfactory.co/",
        infourl: "http://www.dfactory.co/",
        version: tinymce.majorVersion + "." + tinymce.minorVersion
      };
    }
  });
  tinymce.PluginManager.add("download_attachments", tinymce.plugins.download_attachments);
})(jQuery);
})();