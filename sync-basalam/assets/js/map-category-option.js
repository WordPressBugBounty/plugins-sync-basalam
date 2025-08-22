jQuery(document).ready(function ($) {
  $(document).on("click", ".Basalam-delete-option", function (e) {
    e.preventDefault();

    const $row = $(this).closest("tr");
    const woo_name = $row.data("woo");
    const basalam_name = $row.data("basalam");
    const nonce = $(this).data("_wpnonce");

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

  $("#Basalam-map-option-form").on("submit", function (e) {
    e.preventDefault();

    const wooName = $("#woo-option-name").val().trim();
    const BasalamName = $("#Basalam-option-name").val().trim();
    const nonce = $("#basalam_add_map_option_nonce").val();

    if (!wooName || !BasalamName) {
      alert("لطفاً هر دو مقدار را وارد کنید.");
      return;
    }

    $.ajax({
      url: ajaxurl,
      type: "POST",
      data: {
        action: "basalam_add_map_option",
        "woo-option-name": wooName,
        "basalam-option-name": BasalamName,
        _wpnonce: nonce,
      },
      success: function (response) {
        if (response.success) {
          $("#Basalam-map-option-result").text("ویژگی با موفقیت ذخیره شد.");
          $(".options_mapping_section").show();
          $("#woo-option-name").val("");
          $("#Basalam-option-name").val("");

          const newRow = `
        <tr data-woo="${wooName}" data-Basalam="${BasalamName}">
          <td>${wooName}</td>
          <td>${BasalamName}</td>
          <td>
            <button class="Basalam-delete-option basalam-primary-button" onclick="return confirm('آیا مطمئن هستید؟')" style="width:auto">حذف</button>
          </td>
        </tr>
      `;

          if ($(".basalam-table").length === 0) {
            const newTable = `
          <p class="basalam-p">لیست ویژگی ها : </p>
          <table class='basalam-table basalam-p'>
            <thead>
              <tr>
                <th>نام ویژگی در ووکامرس</th>
                <th>نام ویژگی در باسلام</th>
                <th>عملیات</th>
              </tr>
            </thead>
            <tbody>
              ${newRow}
            </tbody>
          </table>
        `;
            $(".Basalam-map-section").html(newTable);
          } else {
            $(".basalam-table tbody").append(newRow);
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
  });

  $(document).ready(function () {
    if ($(".basalam-table tbody tr").length === 0) {
      $(".options_mapping_section").hide();
    }
  });
});
