jQuery(document).ready(function ($) {
  function getDeleteNonce() {
    return (
      $(".options_mapping_section").data("delete-nonce") ||
      $(".Basalam-delete-option").first().data("_wpnonce") ||
      ""
    );
  }

  function submitMapOption() {
    const wooName = $("#woo-option-name").val().trim();
    const basalamName = $("#Basalam-option-name").val().trim();
    const nonce = $("#basalam_add_map_option_nonce").val();

    if (!wooName || !basalamName) {
      alert("لطفاً هر دو مقدار را وارد کنید.");
      return;
    }

    $.ajax({
      url: ajaxurl,
      type: "POST",
      data: {
        action: "basalam_add_map_option",
        "woo-option-name": wooName,
        "basalam-option-name": basalamName,
        _wpnonce: nonce,
      },
      success: function (response) {
        if (response.success) {
          $("#Basalam-map-option-result").text("ویژگی با موفقیت ذخیره شد.");
          $(".options_mapping_section").show();
          $("#woo-option-name").val("");
          $("#Basalam-option-name").val("");

          const deleteNonce = getDeleteNonce();
          const newRow = `
        <tr data-woo="${wooName}" data-basalam="${basalamName}">
          <td>${wooName}</td>
          <td>${basalamName}</td>
          <td>
            <button type="button" class="Basalam-delete-option basalam-primary-button basalam-delete-option-auto" data-_wpnonce="${deleteNonce}">حذف</button>
          </td>
        </tr>
      `;

          const $tableBody = $(".basalam-table tbody");
          if ($tableBody.length > 0) {
            $tableBody.append(newRow);
          }
        } else {
          const errorMessage = response.data?.message || "خطا در ذخیره‌سازی.";
          alert(errorMessage);
          $("#Basalam-map-option-result").text(errorMessage);
        }
      },
      error: function (xhr) {
        const errorMessage =
          xhr.responseJSON?.data?.message || "خطا در ارسال درخواست.";
        alert(errorMessage);
        $("#Basalam-map-option-result").text(errorMessage);
      },
    });
  }

  $(document).on("click", ".Basalam-delete-option", function (e) {
    e.preventDefault();

    if (!window.confirm("آیا مطمئن هستید؟")) {
      return;
    }

    const $row = $(this).closest("tr");
    const woo_name = $row.data("woo");
    const basalam_name = $row.data("basalam");
    const nonce = $(this).data("_wpnonce") || getDeleteNonce();

    $.ajax({
      url: ajaxurl,
      method: "POST",
      data: {
        action: "basalam_delete_mapped_option",
        woo_name: woo_name,
        basalam_name: basalam_name,
        _wpnonce: nonce,
      },
      success: function (response) {
        if (response.success) {
          $row.remove();
          if ($(".basalam-table tbody tr").length === 0) {
            $(".options_mapping_section").hide();
          }
        } else {
          alert("عملیات ناموفق بود");
        }
      },
      error: function () {
        alert("خطایی در ارسال درخواست رخ داد.");
        location.reload();
      },
    });
  });

  $(document).on("click", "#Basalam-map-option-submit", function (e) {
    e.preventDefault();
    submitMapOption();
  });

  $(document).on("keydown", "#Basalam-map-option-form input", function (e) {
    if (e.key === "Enter") {
      e.preventDefault();
      submitMapOption();
    }
  });

  if ($(".basalam-table tbody tr").length === 0) {
    $(".options_mapping_section").hide();
  }
});
