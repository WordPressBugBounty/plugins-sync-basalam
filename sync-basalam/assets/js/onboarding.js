jQuery(document).ready(function ($) {

  // Handle webhook reset
  $(".reset-webhook").on("click", function (e) {
    e.preventDefault();

    if (
      confirm(
        "آیا مطمئن هستید که می‌خواهید اطلاعات وب‌هوک را حذف و مجددا دریافت کنید؟"
      )
    ) {
      $.ajax({
        url: Basalam_ajax.ajax_url,
        type: "POST",
        data: {
          action: "reset_webhook",
          nonce: Basalam_ajax.nonce,
        },
        success: function (response) {
          if (response.success) {
            location.reload();
          } else {
            alert("خطا در حذف اطلاعات. لطفا مجددا تلاش کنید.");
          }
        },
      });
    }
  });
});
