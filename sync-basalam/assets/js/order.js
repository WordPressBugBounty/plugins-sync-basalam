jQuery(document).ready(function ($) {
  $(".confirm-order").click(function () {
    if (confirm("آیا از تایید سفارش اطمینان دارید؟")) {
      var orderId = $(this).data("order");
      var nonce = $(this).data("nonce");

      $.ajax({
        url: ajaxurl,
        method: "POST",
        data: {
          action: "confirm_basalam_order",
          order_id: orderId,
          _wpnonce: nonce,
        },
        success: function (data) {
          if (data.success) {
            alert(data.data?.message || "عملیات با موفقیت انجام شد.");
          } else {
            alert(data.data?.message || "خطایی رخ داده است.");
          }
          location.reload();
        },
        error: function () {
          alert("خطایی در ارسال درخواست رخ داد.");
          location.reload();
        },
      });
    }
  });

  // $(".cancel-order").click(function () {
  //   if (confirm("آیا از لغو سفارش اطمینان دارید؟")) {
  //     var orderId = $(this).data("order");
  //     var nonce = $(this).data("nonce");

  //     $.ajax({
  //       url: ajaxurl,
  //       type: "POST",
  //       data: {
  //         action: "admin_cancel_order",
  //         order_id: orderId,
  //         basalam_order_nonce: nonce,
  //       },
  //       success: function (response) {
  //         if (response.success) {
  //           location.reload();
  //         } else {
  //           alert("خطا: " + response.data);
  //         }
  //       },
  //     });
  //   }
  // });

  $(".save-tracking-code").click(function () {
    var orderId = $(this).data("order");
    var trackingCode = $("#order_tracking_code").val();
    var phoneNumber = $("#phone_number").val();

    if (!trackingCode || !phoneNumber) {
      alert("لطفاً کد رهگیری و شماره تلفن را وارد کنید.");
      return;
    }

    $("#shipping-method-popup").show();
  });

  $("#cancel-shipping-method").click(function () {
    $("#shipping-method-popup").hide();
  });

  $("#submit-shipping-method").click(function (e) {
    e.preventDefault();
    var orderId = $(".save-tracking-code").data("order");
    var trackingCode = $("#order_tracking_code").val();
    var phoneNumber = $("#phone_number").val();
    var shippingMethod = $("#shipping-method").val();
    var nonce = $(this).data("nonce");

    if (!shippingMethod) {
      alert("لطفاً روش ارسال را انتخاب کنید.");
      return;
    }

    $.ajax({
      url: ajaxurl,
      method: "POST",
      data: {
        action: "tracking_code_basalam_order",
        order_id: orderId,
        tracking_code: trackingCode,
        phone_number: phoneNumber,
        shipping_method: shippingMethod,
        _wpnonce: nonce,
      },
      success: function (data) {
        if (data.success) {
          alert(data.data?.message || "عملیات با موفقیت انجام شد.");
        } else {
          alert(data.data?.message || "خطایی رخ داده است.");
          console.error("Server error:", data);
        }
        location.reload();
      },
      error: function (xhr) {
        let errorMsg = "خطایی در ارسال درخواست رخ داد.";
        try {
          const json = JSON.parse(xhr.responseText);
          errorMsg = json.data?.message || errorMsg;
        } catch (e) {
          console.error("JSON parse error:", e);
        }
        alert(errorMsg);
        console.error("AJAX error:", xhr);
        location.reload();
      },
    });
  });

  $(".request-delay").click(function () {
    $("#delay-request-popup").show();
  });

  $("#cancel-delay-request").click(function () {
    $("#delay-request-popup").hide();
  });

  $("#submit-delay-request").click(function () {
    event.preventDefault();
    var orderId = $(".request-delay").data("order");
    var description = $("#delay-description").val();
    var postponeDays = $("#postpone-days").val();
    var nonce = $(this).data("nonce");

    if (!description || !postponeDays) {
      alert("لطفاً توضیحات و تعداد روزهای تاخیر را وارد کنید.");
      return;
    }

    $.ajax({
      url: ajaxurl,
      method: "POST",
      data: {
        action: "delay_req_basalam_order",
        order_id: orderId,
        description: description,
        postpone_days: postponeDays,
        _wpnonce: nonce,
      },
      success: function (data) {
        if (data.success) {
          alert(data.data?.message || "عملیات با موفقیت انجام شد.");
        } else {
          alert(data.data?.message || "خطایی رخ داده است.");
        }
        location.reload();
      },
      error: function () {
        alert("خطایی در ارسال درخواست رخ داد.");
        location.reload();
      },
    });
  });
});
