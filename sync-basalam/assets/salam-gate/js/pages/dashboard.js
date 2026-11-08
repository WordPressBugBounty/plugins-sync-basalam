// =====================================================================
// Salam Gate — dashboard stats (part 5)
// =====================================================================
jQuery(function ($) {
  if (window.WoosalamGateBoot && !window.WoosalamGateBoot.claim("dashboard")) return;
  var $statsBlocks = $("[data-sg-stats]");
  if (!$statsBlocks.length) return;

  $statsBlocks.each(function () {
    var $block = $(this);
    var $state = $block.siblings("[data-sg-stats-state]") || $();
    function loadStats() {
    $state.text("").hide();
    var statsLoading = typeof window !== "undefined" && window.WoosalamGateLoading && window.WoosalamGateLoading.begin($block[0], "stats", loadStats);
    var request = typeof window !== "undefined" && window.WoosalamGateRequests ? window.WoosalamGateRequests.post : $.post;
    request(basalam_ajax.ajaxurl, { _wpnonce: basalam_ajax.nonce, action: "salam_gate_stats" })
      .done(function (res) {
        if (res && res.success && res.data) {
          var d = res.data;
          $block.find("[data-stat='order_count']").text(String(d.order_count != null ? d.order_count : "—"));
          $block.find("[data-stat='avg_shipping_cost_toman']").text(
            d.avg_shipping_cost_toman != null
              ? window.SalamGateAmount.toman(Number(d.avg_shipping_cost_toman), false)
              : "—"
          );
          $block.find("[data-stat='total_profit_toman']").text(
            d.total_profit_toman != null
              ? window.SalamGateAmount.toman(Number(d.total_profit_toman), false)
              : "—"
          );
          if (statsLoading) statsLoading.ready();
          return;
        }
        var detail = res && res.data && res.data.message;
        $state.text(typeof detail === "string" && detail.trim()
          ? "دریافت گزارش عملکرد انجام نشد: " + detail
          : "گزارش عملکرد فعلاً در دسترس نیست؛ دوباره تلاش کنید.").show();
        if (statsLoading) statsLoading.fail($state.text());
      })
      .fail(function (xhr, status) {
        var message = xhr && (xhr.status === 401 || xhr.status === 403)
          ? "اعتبار صفحه پایان یافته یا دسترسی مجاز نیست؛ صفحه را تازه‌سازی کنید."
          : (status === "timeout"
            ? "دریافت گزارش عملکرد بیش از حد طول کشید؛ دوباره تلاش کنید."
            : "دریافت گزارش عملکرد انجام نشد؛ اتصال سایت و پاسخ سرور را بررسی کنید.");
        $state.text(message).show();
        if (statsLoading) statsLoading.fail($state.text());
      });
    }
    loadStats();
  });
});
