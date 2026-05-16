jQuery(document).ready(function ($) {
  function getAjaxErrorMessage(xhr, fallbackMessage) {
    if (xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) {
      return xhr.responseJSON.data.message;
    }

    if (xhr.responseText) {
      try {
        var parsed = JSON.parse(xhr.responseText);
        if (parsed && parsed.data && parsed.data.message) {
          return parsed.data.message;
        }
      } catch (e) {}
    }

    return fallbackMessage;
  }

  $(document).on("change", ".category-option", function () {
    if ($(this).is(":checked")) {
      var catID = $(this).val();
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
          catID: catID.toString().split(",")[0],
          _wpnonce: nonce,
        },
        success: function (res) {
          var html = "";
          if (
            res.success &&
            res.data.attributes
          ) {
            var attributesData = JSON.parse(res.data.attributes);
            if (attributesData.data && attributesData.data.length && attributesData.data[0].attributes.length) {
              html += `
                <div class="basalam-attributes-header">ویژگی‌های دسته‌بندی:</div>
                <div class="basalam-attributes-container">
              `;
              attributesData.data[0].attributes.forEach(function (attr) {
                html += `
                  <div class="basalam-copy-attr">
                    ${attr.title}
                  </div>
                `;
              });

              html += "</div>";
            } else {
              html = "ویژگی‌ای برای این دسته‌بندی پیدا نشد.";
            }
          } else {
            html = "ویژگی‌ای برای این دسته‌بندی پیدا نشد.";
          }

          $("#sync_basalam_category_attributes").html(html);

          $(".basalam-copy-attr").on("click", function () {
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
          var message = getAjaxErrorMessage(xhr, "خطا در دریافت ویژگی‌ها.");
          $("#sync_basalam_category_attributes").html(message);
        },
      });
    } else {
      $(".basalam-action-button").removeAttr("data-cat-id");
      $("#sync_basalam_category_attributes").html("");
    }
  });

  $("#basalam_fetch_categories_btn").on("click", function () {
    var productTitle = $("#title").val();

    if (!productTitle || productTitle.trim() === "") {
      window.BasalamToast.warning("لطفاً ابتدا عنوان محصول را وارد کنید.");
      $("#title").focus();
      return;
    }

    var nonce = $("#basalam_get_category_ids_nonce").val();
    var $btn = $(this);
    var originalText = $btn.text();

    $btn.prop("disabled", true).text("در حال دریافت...");
    $("#sync_basalam_category_id")
      .html("")
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
              <div class="basalam-categories-header">دسته‌بندی‌های باسلام:</div>
              <div class="basalam-categories-container">
            `;

          categories.forEach(function (category) {
            html += `
                <label for="cat_${category.cat_id}" class="basalam-category-label">
                  <input type="radio" class="category-option basalam-category-option" name="selected_category" value="${category.cat_id}" id="cat_${category.cat_id}" />
                  ${category.cat_title}
                </label>
              `;
          });

          html += `</div>`;
          $("#sync_basalam_category_id").html(html);
        } else {
          $("#sync_basalam_category_id").html(
            res.data && res.data.message
              ? res.data.message
              : "خطا در دریافت دسته‌بندی‌ها."
          );
        }
        $btn.prop("disabled", false).text(originalText);
      },
      error: function (xhr, status, error) {
        var message = getAjaxErrorMessage(xhr, "خطا در دریافت دسته‌بندی خودکار محصول.");
        $("#sync_basalam_category_id").html(message);
        $btn.prop("disabled", false).text(originalText);
      },
    });
  });
});
