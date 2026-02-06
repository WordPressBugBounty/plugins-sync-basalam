jQuery(document).ready(function ($) {
  $("#_sync_basalam_is_mobile_product_checkbox").on("change", function () {
    var mobileFields = $("#basalam_mobile_product_fields");
    if ($(this).is(":checked")) {
      mobileFields.show();
    } else {
      mobileFields.hide();
    }
  });

  var basalamFields = $("#basalam_product_fields");

  if ($("#_sync_basalam_is_product_type_checkbox").is(":checked")) {
    basalamFields.show();
  } else {
    basalamFields.hide();
  }

  $("#_sync_basalam_is_product_type_checkbox").on("change", function () {
    if ($(this).is(":checked")) {
      basalamFields.show();
    } else {
      basalamFields.hide();
    }
  });
});
