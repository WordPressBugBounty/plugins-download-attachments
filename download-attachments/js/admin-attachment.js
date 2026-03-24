(function(){"use strict";(function($) {
  $(function() {
    var container = $("#attachment-downloads-input-container");
    var counter = $("#attachment-downloads-display strong");
    var editLink = $("#attachment-downloads .edit-attachment-downloads");
    var counterInput = $("#attachment-downloads-input");
    editLink.on("click", function() {
      if (container.is(":hidden")) {
        container.slideDown("fast");
        $(this).hide();
      }
      return false;
    });
    $("#attachment-downloads .save-attachment-downloads").on("click", function() {
      counter.text();
      container.slideUp("fast");
      editLink.show();
      var downloads = parseInt(counterInput.val());
      counterInput.val(downloads);
      counter.text(downloads);
      return false;
    });
    $("#attachment-downloads .cancel-attachment-downloads").on("click", function() {
      counter.text();
      container.slideUp("fast");
      editLink.show();
      var downloads = parseInt($("#attachment-downloads-current").val());
      counter.text(downloads);
      counterInput.val(downloads);
      return false;
    });
  });
})(jQuery);
})();