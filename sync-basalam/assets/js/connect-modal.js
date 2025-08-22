function openModal() {
  document.getElementById("Basalam-connect-modal").style.display = "block";
}

function closeModal() {
  document.getElementById("Basalam-connect-modal").style.display = "none";
}

jQuery(document).ready(function ($) {
  $("#basalam-search-btn").on("click", function (event) {
    event.preventDefault();
    var searchTerm = $("#basalam-product-search").val();
    var wooProductId = $("#Basalam-woo-product-id").val();

    if (searchTerm.length > 0) {
      $("#basalam-product-results").html("<p>در حال جستجو...</p>");
      $.ajax({
        url: ajaxurl,
        type: "POST",
        data: {
          action: "basalam_search_products",
          title: searchTerm,
          woo_product_id: wooProductId,
        },
        success: function (response) {
          $("#basalam-product-results").html(response);
        },
        error: function () {
          $("#basalam-product-results").html("<p>خطا در دریافت اطلاعات!</p>");
        },
      });
    } else {
      alert("لطفاً عنوان محصول را وارد کنید.");
    }
  });

  $(document).on("click", ".basalam-connect-btn", function () {
    const $btn = $(this);
    const BasalamProductId = $btn.data("basalam-product-id");
    const wooProductId = $btn.data("woo-product-id");
    var nonce = $btn.data("_wpnonce");
    if (!BasalamProductId || !wooProductId) {
      alert("مشخصات محصول ناقص است.");
      return;
    }

    $btn.text("در حال اتصال...").prop("disabled", true);

    $.ajax({
      url: ajaxurl,
      method: "POST",
      data: {
        action: "basalam_connect_product",
        woo_product_id: wooProductId,
        basalam_product_id: BasalamProductId,
        _wpnonce: nonce,
      },
      success: function (data) {
        if (data.success) {
          alert(data.data?.message || "عملیات با موفقیت انجام شد.");
          location.reload();
        } else {
          alert(data.data?.message || "خطایی رخ داده است.");
        }
      },
      error: function (xhr) {
        let response;
        try {
          response = JSON.parse(xhr.responseText);
        } catch (e) {
          response = {};
        }
        alert(response?.data?.message || "خطایی رخ داده است.");
      },
    });
  });
});
