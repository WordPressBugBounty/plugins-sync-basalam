jQuery(document).ready(function ($) {
  $(".basalam-category-tab").click(function () {
    $(".basalam-category-tab").removeClass("active");
    $(this).addClass("active");

    const category = $(this).data("category");

    $(".basalam-faq-section").hide();

    $(`.basalam-faq-section[data-category="${category}"]`).show();

    $("#basalam-search-results").empty().hide();
    $("#basalam-faq-search").val("");
  });

  $(".basalam-faq-question").click(function () {
    const $answer = $(this).next(".basalam-faq-answer");
    const $toggle = $(this).find(".basalam-faq-toggle");

    $(".basalam-faq-answer").not($answer).slideUp();
    $(".basalam-faq-toggle").not($toggle).text("+");

    if ($answer.is(":visible")) {
      $answer.slideUp();
      $toggle.text("+");
    } else {
      $answer.slideDown();
      $toggle.text("-");
    }
  });

  $('.basalam-faq-section[data-category="عمومی"]').show();
  $(".basalam-faq-section").not('[data-category="عمومی"]').hide();

  let searchTimeout;
  $("#basalam-faq-search").on("input", function () {
    const query = $(this).val();
    clearTimeout(searchTimeout);

    if (query.length < 2) {
      $("#basalam-search-results").empty().hide();
      return;
    }

    searchTimeout = setTimeout(function () {
      $.ajax({
        url: ajaxurl,
        type: "POST",
        data: {
          action: "Basalam_search_faqs",
          query: query,
          nonce: Basalam_ajax.nonce,
        },
        success: function (response) {
          if (response.success) {
            const results = response.data;
            displaySearchResults(results);
          }
        },
      });
    }, 300);
  });

  function displaySearchResults(results) {
    const $resultsDiv = $("#basalam-search-results");
    $resultsDiv.empty();

    if (results.length === 0) {
      $resultsDiv.append(
        '<p class="basalam--no-results">نتیجه‌ای یافت نشد</p>'
      );
    } else {
      results.forEach((result) => {
        $resultsDiv.append(`
                    <div class="basalam-search-result-item">
                        <h4>${result.question}</h4>
                        <p>${result.answer}</p>
                        <span class="basalam-result-category">${result.category}</span>
                    </div>
                `);
      });
    }

    $resultsDiv.show();
  }
});
