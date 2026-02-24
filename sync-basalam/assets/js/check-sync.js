jQuery(document).ready(function ($) {
  // Toggle dropdown on arrow click
  $(document).on("click", ".basalam-dropdown-arrow-btn", function (e) {
    e.preventDefault();
    e.stopPropagation();
    const $wrapper = $(this).closest(".basalam-orders-fetch-wrapper");
    const $dropdown = $wrapper.find(".basalam-orders-fetch-dropdown");
    $dropdown.toggle();
  });

  // Close dropdown when clicking outside
  $(document).on("click", function (e) {
    if (!$(e.target).closest(".basalam-orders-fetch-wrapper").length) {
      $(".basalam-orders-fetch-dropdown").hide();
    }
  });

  // Fetch orders with default 7 days
  $(document).on("click", ".basalam-fetch-orders-btn", function (e) {
    e.preventDefault();
    const $btn = $(this);
    const $wrapper = $btn.closest(".basalam-orders-fetch-wrapper");
    const nonce = $btn.data("nonce");

    $btn.prop("disabled", true).find(".basalam-btn-text").text("در حال شروع...");

    $.ajax({
      url: ajaxurl,
      type: "POST",
      data: {
        action: "add_unsync_orders_from_basalam",
        _wpnonce: nonce,
        days: 7,
      },
      success: function (response) {
        if (response.success) {
          alert(response.data?.message || "عملیات با موفقیت انجام شد.");
          location.reload();
        } else {
          alert(response.data?.message || "خطایی رخ داده است.");
          $btn.prop("disabled", false).find(".basalam-btn-text").text("بررسی سفارشات باسلام");
        }
      },
      error: function (jqXHR) {
        let message = "خطایی در ارتباط با سرور رخ داد.";
        try {
          const response = JSON.parse(jqXHR.responseText);
          message = response.data?.message || message;
        } catch (e) {}
        alert(message);
        $btn.prop("disabled", false).find(".basalam-btn-text").text("بررسی سفارشات باسلام");
      },
    });
  });

  // Submit fetch orders with custom days
  $(document).on("click", ".basalam-dropdown-submit", function (e) {
    e.preventDefault();

    const $btn = $(this);
    const $wrapper = $btn.closest(".basalam-orders-fetch-wrapper");
    const $dropdown = $wrapper.find(".basalam-orders-fetch-dropdown");
    const $daysInput = $dropdown.find(".basalam-dropdown-input");
    const $mainBtn = $wrapper.find(".basalam-fetch-orders-btn");
    const nonce = $btn.data("nonce");
    let days = parseInt($daysInput.val());

    // Validate days
    if (isNaN(days) || days < 1 || days > 30) {
      alert("عدد وارد شده باید بین ۱ تا ۳۰ باشد.");
      $daysInput.focus();
      return;
    }

    $btn.text("در حال بررسی...").prop("disabled", true);
    $mainBtn.prop("disabled", true).find(".basalam-btn-text").text("در حال بررسی...");

    $.ajax({
      url: ajaxurl,
      type: "POST",
      data: {
        action: "add_unsync_orders_from_basalam",
        _wpnonce: nonce,
        days: days,
      },
      success: function (response) {
        if (response.success) {
          alert(response.data?.message || "عملیات با موفقیت انجام شد.");
          location.reload();
        } else {
          alert(response.data?.message || "خطایی رخ داده است.");
          $btn.text("بررسی سفارشات").prop("disabled", false);
          $mainBtn.prop("disabled", false).find(".basalam-btn-text").text("بررسی سفارشات باسلام");
        }
      },
      error: function (jqXHR) {
        let message = "خطایی در ارتباط با سرور رخ داد.";
        try {
          const response = JSON.parse(jqXHR.responseText);
          message = response.data?.message || message;
        } catch (e) {}
        alert(message);
        $btn.text("بررسی سفارشات").prop("disabled", false);
        $mainBtn.prop("disabled", false).find(".basalam-btn-text").text("بررسی سفارشات باسلام");
      },
    });
  });

  // Cancel fetch orders
  $(document).on("click", ".basalam-cancel-orders-btn", function (e) {
    e.preventDefault();

    const $btn = $(this);
    const nonce = $btn.data("nonce");

    if (!confirm("آیا مطمئن هستید که می‌خواهید همگام‌سازی سفارشات را لغو کنید؟")) {
      return;
    }

    $btn.prop("disabled", true);

    $.ajax({
      url: ajaxurl,
      type: "POST",
      data: {
        action: "cancel_fetch_orders",
        _wpnonce: nonce,
      },
      success: function (response) {
        if (response.success) {
          alert(response.data?.message || "عملیات لغو شد.");
          location.reload();
        } else {
          alert(response.data?.message || "خطایی رخ داده است.");
          $btn.prop("disabled", false);
        }
      },
      error: function (jqXHR) {
        let message = "خطایی در ارتباط با سرور رخ داد.";
        try {
          const response = JSON.parse(jqXHR.responseText);
          message = response.data?.message || message;
        } catch (e) {}
        alert(message);
        $btn.prop("disabled", false);
      },
    });
  });
});
