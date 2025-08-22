jQuery(document).ready(function ($) {
  $(".basalam_add_unsync_orders").on("click", function (e) {
    e.preventDefault();

    const $btn = $(this);
    var nonce = $(this).data("nonce");
    $btn.text("در حال بررسی...").prop("disabled", true);

    $.ajax({
      url: ajaxurl,
      type: "POST",
      data: {
        action: "add_unsync_orders_from_basalam",
        _wpnonce: nonce,
      },
      success: function (response) {
        if (response.success) {
          alert(response.data?.message || "عملیات با موفقیت انجام شد.");
        } else {
          alert(response.data?.message || "خطایی رخ داده است.");
        }
        location.reload();
      },
      error: function (jqXHR) {
        let message = "خطایی در ارتباط با سرور رخ داد.";
        try {
          const response = JSON.parse(jqXHR.responseText);
          message = response.data?.message || message;
        } catch (e) {}
        alert(message);
        $btn.text("بررسی سفارشات باسلام").prop("disabled", false);
      },
    });
  });
});
