(function(){"use strict";(function($) {
  function getTableControls() {
    var before = [];
    var after = [];
    [
      {
        feature: "pageLength",
        placement: daDataTablesArgs.inputs.perPagePlacement
      },
      {
        feature: "search",
        placement: daDataTablesArgs.inputs.searchPlacement
      },
      {
        feature: "info",
        placement: daDataTablesArgs.inputs.recordCountPlacement
      },
      {
        feature: "paging",
        placement: daDataTablesArgs.inputs.paginationLinkPlacement
      }
    ].forEach(function(item) {
      if (item.placement === "after") after.push(item.feature);
      else before.push(item.feature);
    });
    return {
      before,
      after
    };
  }
  function getColumnTypes() {
    return daDataTablesArgs.columnTypes.map(function(column) {
      if (column.orderable === false) return column;
      return $.extend({}, column, {
        orderSequence: ["asc", "desc"]
      });
    });
  }
  $(function() {
    var controls = getTableControls();
    $.fn.DataTable.ext.pager.numbers_length = daDataTablesArgs.inputs.paginationGap.reduce((a, b) => a + b, 0) + 3;
    $(".da-attachments-dynatable").DataTable({
      paging: daDataTablesArgs.features.paginate,
      info: daDataTablesArgs.features.recordCount,
      searching: daDataTablesArgs.features.search,
      stateSave: daDataTablesArgs.features.pushState,
      ordering: daDataTablesArgs.features.sort,
      order: [],
      pageLength: daDataTablesArgs.dataset.perPageDefault,
      lengthChange: daDataTablesArgs.features.perPageSelect,
      lengthMenu: daDataTablesArgs.dataset.perPageOptions,
      columns: getColumnTypes(),
      layout: {
        topStart: null,
        topEnd: null,
        bottomStart: null,
        bottomEnd: null,
        top: controls.before.length > 0 ? controls.before : null,
        bottom: controls.after.length > 0 ? controls.after : null
      },
      pagingType: "simple_numbers",
      language: {
        info: daDataTablesArgs.inputs.recordCountText,
        lengthMenu: daDataTablesArgs.inputs.perPageText,
        loadingRecords: daDataTablesArgs.inputs.processingText,
        paginate: {
          next: daDataTablesArgs.inputs.paginationNext,
          previous: daDataTablesArgs.inputs.paginationPrev
        }
      }
    });
  });
})(jQuery);
})();