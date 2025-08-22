jQuery(document).ready(function ($) {
  $(document).on("change", ".category-option", function () {
    if ($(this).is(":checked")) {
      var catID = $(this).val().toString().split(",")[0];
      var nonce = $("#basalam_get_category_attrs_nonce").val();
      $(".basalam-action-button").attr("data-cat-id", catID);
      $("#sync_basalam_category_attributes")
        .html("در حال دریافت ویژگی‌ها...")
        .removeClass("basalam--hidden");

      $.ajax({
        url: ajaxurl,
        method: "POST",
        data: {
          action: "basalam_get_category_attrs",
          catID: catID,
          _wpnonce: nonce,
        },
        success: function (res) {
          var html = "";
          if (
            res.success &&
            res.data.length &&
            res.data[0].attributes[0].attributes.length
          ) {
            html += `
              <div style="margin-bottom: 10px; font-weight: bold;">ویژگی‌های دسته‌بندی:</div>
              <div style="display:flex; flex-wrap:wrap; gap:6px;">
            `;
            res.data[0].attributes[0].attributes.forEach(function (attr) {
              html += `
                <div class="copy-attr" style="
                  color: white;
                  cursor: pointer;
                  padding: 4px 10px;
                  background: var(--basalam-primary-color);
                  border-radius: 3px;
                  font-size: 10px;
                  display: flex;
                  align-items: center;
                ">
                  ${attr.title}
                </div>
              `;
            });

            html += "</div>";
          } else {
            html = "ویژگی‌ای برای این دسته‌بندی پیدا نشد.";
          }

          $("#sync_basalam_category_attributes").html(html);

          $(".copy-attr").on("click", function () {
            const text = $(this).text().trim();

            if (navigator.clipboard) {
              navigator.clipboard
                .writeText(text)
                .then(function () {})
                .catch(function (err) {
                  console.error("خطا در کپی:", err);
                });
            } else {
              var tempInput = document.createElement("input");
              tempInput.value = text;
              document.body.appendChild(tempInput);
              tempInput.select();
              document.execCommand("copy");
              document.body.removeChild(tempInput);
            }
          });
        },
        error: function (xhr, status, error) {
          $("#sync_basalam_category_attributes").html(
            "خطا در دریافت ویژگی‌ها: " + error
          );
        },
      });
    } else {
      $(".basalam-action-button").removeAttr("data-cat-id");
      $("#sync_basalam_category_attributes").html("");
    }
  });

  $("#title").on("blur", function () {
    var productTitle = $("#title").val();
    var nonce = $("#basalam_get_category_ids_nonce").val();
    $("#sync_basalam_category_id")
      .html("در حال دریافت دسته‌بندی...")
      .removeClass("basalam--hidden");

    $.ajax({
      url: ajaxurl,
      method: "POST",
      data: {
        action: "basalam_get_category_ids",
        productTitle: productTitle,
        _wpnonce: nonce,
      },
      success: function (res) {
        if (res.success) {
          var categories = res.data;
          var html = `
              <div style="margin-bottom: 10px; font-weight: bold;">دسته‌بندی‌های باسلام:</div>
              <div style="display: flex; flex-wrap: wrap; gap: 8px;">
            `;

          categories.forEach(function (category) {
            html += `
                <label for="cat_${category.cat_id}" style="    border: 1px solid #00000036;
      padding: 7px;
      border-radius: 6px;
      width: -webkit-fill-available;              ">
                  <input type="radio" class="category-option" name="selected_category" value="${category.cat_id}" id="cat_${category.cat_id}" style="accent-color: var(--basalam-primary-color);" />
                  ${category.cat_title}
                </label>
              `;
          });

          html += `</div>`;
          $("#sync_basalam_category_id").html(html);
        } else {
          $("#sync_basalam_category_id").html("خطا در دریافت دسته بندی ها");
        }
      },
      error: function (xhr, status, error) {
        $("#sync_basalam_category_id").html("خطایی در ارتباط رخ داد: " + error);
      },
    });
  });
});
