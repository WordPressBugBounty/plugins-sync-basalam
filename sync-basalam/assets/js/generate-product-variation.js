jQuery(document).ready(function ($) {
  $("#Basalam-generate-variations").click(function (e) {
    e.preventDefault();
    if (
      confirm(
        "آیا مطمئن هستید که می‌خواهید برای همه مقادیر ویژگی‌ها متغیر ایجاد کنید؟"
      )
    ) {
      let productId = $(this).data("product-id");
      let nonce = $(this).data("product-nonce");
      let data = {
        action: "Basalam_generate_variations",
        product_id: productId,
        nonce: nonce,
      };

      $.post(ajaxurl, data, function (response) {
        if (response && response.success) {
          window.BasalamToast.success(response.data?.message || "عملیات با موفقیت انجام شد.");
        } else {
          window.BasalamToast.error(response?.data?.message || "خطایی رخ داده است.");
        }
        location.reload();
      });
    }
  });
});
