jQuery(document).ready(function ($) {
  $("#_sync_basalam_is_mobile_product_checkbox").on("change", function () {
    var mobileFields = $("#basalam_mobile_product_fields");
    if ($(this).is(":checked")) {
      mobileFields.show();
    } else {
      mobileFields.hide();
    }
  });
});
