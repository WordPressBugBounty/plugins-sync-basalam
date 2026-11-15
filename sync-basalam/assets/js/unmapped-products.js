/**
 * صفحه «محصولات باسلامِ متصل‌نشده به ووکامرس»
 * شروع بررسی، polling وضعیت، اقدام‌های هر ردیف (آرشیو/اتصال/نادیده‌گرفتن) و آرشیو گروهی.
 */
jQuery(document).ready(function ($) {
  var config = window.basalamUnmappedProducts;
  var summary = document.getElementById('basalam-unmapped-summary');
  if (!config || !summary) return;

  var POLL_INTERVAL = 8000;
  var SCAN_POLL_INTERVAL = 5000;
  var pollTimer = null;
  var recentArchiveSignature = null;
  var stopSubmitting = false;
  var archivedRowIds = {};
  var recentRevision = 0;
  var productsRevision = 0;
  var recentUpdates = Promise.resolve();
  var productsUpdates = Promise.resolve();
  var statusRequestSequence = 0;
  var lastAppliedStatusRequest = 0;

  function nextPollDelay() {
    return summary.getAttribute('data-status') === 'scanning' || summary.getAttribute('data-archive-active') === '1'
      ? SCAN_POLL_INTERVAL : POLL_INTERVAL;
  }

  var stateBadges = {
    open: { label: 'در انتظار اقدام', className: 'basalam-badge-warning' },
    archiving: { label: 'در صف آرشیو', className: 'basalam-badge-warning' },
    archived: { label: 'آرشیو شد', className: 'basalam-badge-success' },
    connected: { label: 'متصل شد', className: 'basalam-badge-success' },
    ignored: { label: 'نادیده گرفته شد', className: 'basalam-badge-success' },
    failed: { label: 'خطا در آرشیو', className: 'basalam-badge-danger' },
  };

  function toFa(number) {
    return Number(number).toLocaleString('fa-IR');
  }

  function post(action, nonce, data) {
    return $.ajax({
      url: config.url,
      method: 'POST',
      data: $.extend({ action: action, _wpnonce: nonce }, data || {}),
    });
  }

  function errorMessage(xhr, fallback) {
    if (xhr && xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) {
      return xhr.responseJSON.data.message;
    }
    return fallback;
  }

  function setCounter(id, value) {
    var element = document.getElementById(id);
    var text = toFa(value);
    if (element && element.textContent !== text) element.textContent = text;
  }

  function fadeDuration() {
    return window.matchMedia('(prefers-reduced-motion: reduce)').matches ? 0 : 220;
  }

  function fadeIn($element) {
    $element.css('opacity', 0).animate({ opacity: 1 }, fadeDuration(), function () {
      $(this).css('opacity', '');
    });
  }

  function moveFocusBeforeRemoval($elements) {
    if ($elements.toArray().some(function (element) { return element.contains(document.activeElement); })) {
      var heading = document.getElementById('basalam-unmapped-list-title');
      if (heading) {
        heading.setAttribute('tabindex', '-1');
        heading.focus({ preventScroll: true });
      }
    }
  }

  function fadeRemove($elements) {
    moveFocusBeforeRemoval($elements);
    return Promise.all($elements.toArray().map(function (element) {
      var $element = $(element);
      var pending = $element.data('fadeRemoval');
      if (pending) return pending;
      pending = new Promise(function (resolve) {
        $element.animate({ opacity: 0 }, fadeDuration(), function () {
          $element.remove();
          resolve();
        });
      });
      $element.data('fadeRemoval', pending);
      return pending;
    }));
  }

  function placeAt($parent, $element, index) {
    var current = $parent.children().get(index);
    if (current === $element[0]) return;
    if (current) $element.insertBefore(current);
    else $parent.append($element);
  }

  function syncRecentProducts(recent) {
    var signature = JSON.stringify(recent);
    if (signature === recentArchiveSignature) return;
    recentArchiveSignature = signature;
    var revision = ++recentRevision;
    recentUpdates = recentUpdates.then(function () {
      if (revision !== recentRevision) return;
      var $list = $('#basalam-unmapped-archive-recent-list');
      var ids = recent.map(function (product) { return String(product.id); });
      var $outgoing = $list.children().filter(function () {
        return ids.indexOf(this.getAttribute('data-product-id')) === -1;
      });
      return fadeRemove($outgoing).then(function () {
        if (revision !== recentRevision) return;
        recent.forEach(function (product, index) {
          var $item = $list.children().filter(function () {
            return this.getAttribute('data-product-id') === String(product.id);
          }).first();
          var isNew = !$item.length;
          if (isNew) $item = $('<li>').attr('data-product-id', product.id);
          $item.text(product.title || 'محصول باسلام ' + product.id);
          placeAt($list, $item, index);
          if (isNew) fadeIn($item);
        });
        $('#basalam-unmapped-archive-recent-empty').toggleClass('basalam-hidden', recent.length > 0);
      });
    });
  }

  function productsPageRequest() {
    return { products_page: Number($('.basalam-unmapped-list').attr('data-page')) || 1 };
  }

  function syncProductsPage(products) {
    if (!products) return;
    var revision = ++productsRevision;
    productsUpdates = productsUpdates.then(function () {
      if (revision !== productsRevision) return;
      var $section = $('.basalam-unmapped-list');
      var $table = $('#basalam-unmapped-table');
      if (!$table.length) return;
      var $body = $table.children('tbody');
      var $incoming = $('<tbody>').html(products.rows_html).children('tr').filter(function () {
        return !archivedRowIds[this.getAttribute('data-row-id')];
      });
      var ids = $incoming.toArray().map(function (row) { return row.getAttribute('data-row-id'); });
      var $outgoing = $body.children().filter(function () {
        return ids.indexOf(this.getAttribute('data-row-id')) === -1;
      });
      return fadeRemove($outgoing).then(function () {
        if (revision !== productsRevision) return;
        $table.toggleClass('basalam-hidden', !$incoming.length);
        $('#basalam-unmapped-list-empty').toggleClass('basalam-hidden', $incoming.length > 0);
        $incoming.each(function (index) {
          var $next = $(this);
          var id = this.getAttribute('data-row-id');
          var $row = $body.children().filter(function () { return this.getAttribute('data-row-id') === id; }).first();
          var isNew = !$row.length;
          if (isNew) {
            $row = $next;
          } else {
            $row.children('.basalam-unmapped-number').text($next.children('.basalam-unmapped-number').text());
            if ($row.attr('data-state') !== $next.attr('data-state')) {
              moveFocusBeforeRemoval($row.children('.basalam-unmapped-operations'));
              $row.attr('data-state', $next.attr('data-state'));
              $row.children('.basalam-unmapped-state-cell').html($next.children('.basalam-unmapped-state-cell').html());
              $row.children('.basalam-unmapped-operations').html($next.children('.basalam-unmapped-operations').html());
            }
          }
          placeAt($body, $row, index);
          if (isNew) fadeIn($row);
        });
        if (Number($section.attr('data-page')) !== products.page || Number($section.attr('data-pages')) !== products.pages) {
          var $pagination = $section.siblings('.basalam-pagination');
          moveFocusBeforeRemoval($pagination);
          $pagination.remove();
          $section.after(products.pagination_html);
        }
        $section.attr('data-page', products.page).attr('data-pages', products.pages);
      });
    });
  }

  function showArchiveProgress(active) {
    var $panel = $('#basalam-unmapped-archive-progress');
    if (active) {
      if ($panel.hasClass('basalam-hidden')) {
        $panel.removeClass('basalam-hidden');
        fadeIn($panel);
      } else {
        $panel.stop(true, false).css('opacity', '');
      }
    } else if (!$panel.hasClass('basalam-hidden')) {
      $panel.stop(true, false).animate({ opacity: 0 }, fadeDuration(), function () {
        $panel.addClass('basalam-hidden').css('opacity', '');
      });
    }
  }

  function applySummary(data) {
    var counts = data.counts;
    setCounter('basalam-unmapped-count-unresolved', counts.unresolved);
    setCounter('basalam-unmapped-count-archived', counts.archived);
    setCounter('basalam-unmapped-count-connected', counts.connected);
    setCounter('basalam-unmapped-count-ignored', counts.ignored);
    summary.setAttribute('data-unresolved', String(counts.unresolved));

    var archiveActive = !!data.archive_active;
    var archiveStopping = !!data.archive_stopping;
    summary.setAttribute('data-status', data.status);
    summary.setAttribute('data-archive-active', archiveActive ? '1' : '0');
    $('#basalam-unmapped-start')
      .toggleClass('basalam-hidden', archiveActive)
      .toggleClass('basalam-button-disabled-bg', archiveActive || data.status !== 'idle')
      .prop('disabled', archiveActive || data.status !== 'idle');
    $('#basalam-unmapped-archive-all').toggleClass('basalam-hidden', archiveActive || counts.open <= 0);
    showArchiveProgress(archiveActive);
    $('#basalam-unmapped-archive-stop')
      .prop('disabled', archiveStopping || stopSubmitting)
      .attr('title', archiveStopping ? 'در حال توقف آرشیو' : 'توقف آرشیو')
      .attr('aria-label', archiveStopping ? 'در حال توقف آرشیو' : 'توقف آرشیو');
    $('#basalam-unmapped-archive-hint').text(archiveStopping
      ? 'درخواست توقف ثبت شد؛ پس از پایان درخواست جاری، آرشیو متوقف می‌شود.'
      : 'آرشیو در پس‌زمینه ادامه دارد؛ می‌توانید این صفحه را ببندید.');

    var statusLabel = archiveActive ? (archiveStopping ? 'در حال توقف آرشیو' : 'در حال آرشیو')
      : (data.status === 'scanning' ? 'در حال بررسی' : (data.status === 'idle' ? 'آماده بررسی' : 'در انتظار تعیین‌تکلیف'));
    var statusClass = archiveActive || data.status === 'scanning' ? 'is-scanning' : (data.status === 'idle' ? 'is-idle' : 'is-awaiting');
    $('#basalam-unmapped-status-badge')
      .removeClass('is-idle is-awaiting is-scanning').addClass(statusClass)
      .empty().append($('<span>', { class: 'basalam-unmapped-status-dot', 'aria-hidden': 'true' }))
      .append(document.createTextNode(statusLabel));

    syncProductsPage(data.products);

    var progress = data.archive_progress;
    if (!progress) return;
    setCounter('basalam-unmapped-archive-completed', progress.archived);
    setCounter('basalam-unmapped-archive-total', progress.total);
    setCounter('basalam-unmapped-archive-failed-count', progress.failed);
    $('#basalam-unmapped-archive-failures').toggleClass('basalam-hidden', progress.failed <= 0);
    var percent = Math.max(0, Math.min(100, Number(progress.percent) || 0));
    $('#basalam-unmapped-archive-progressbar').attr('aria-valuenow', percent)
      .find('span').css('width', percent + '%');

    var recent = (progress.recent || []).slice(0, 5);
    syncRecentProducts(recent);
  }

  function refreshCounts() {
    var requestSequence = ++statusRequestSequence;
    post('get_unmapped_scan_status', config.nonces.status, productsPageRequest()).done(function (response) {
      if (!response || !response.success || !response.data) return;
      if (requestSequence < lastAppliedStatusRequest) return;
      lastAppliedStatusRequest = requestSequence;
      var archiveWasActive = summary.getAttribute('data-archive-active') === '1';
      applySummary(response.data);
      if (archiveWasActive && !response.data.archive_active) {
        refreshCounts();
        return;
      }
      schedulePoll();
    });
  }

  function applyRowState($row, rowState) {
    $row.attr('data-state', rowState);
    if (rowState === 'archived') {
      archivedRowIds[$row.attr('data-row-id')] = true;
      fadeRemove($row).then(refreshCounts);
      return;
    }
    var badge = stateBadges[rowState] || stateBadges.open;
    var $cell = $row.find('.basalam-unmapped-state-cell');
    $cell.html(
      $('<span>', {
        class: 'basalam-badge ' + badge.className + ' basalam-p',
        text: badge.label,
      })
    );

    if (rowState === 'connected' || rowState === 'ignored') {
      $row.find('.basalam-unmapped-connect, .basalam-unmapped-archive, .basalam-unmapped-ignore').remove();
      refreshCounts();
    }
  }

  function reloadSoon() {
    setTimeout(function () {
      window.location.reload();
    }, 1200);
  }

  function handleRowResponse($row, response) {
    var data = (response && response.data) || {};
    window.BasalamToast.success(data.message || 'انجام شد.');
    applyRowState($row, data.row_state || 'open');
  }

  // ---------- شروع بررسی ----------
  $('#basalam-unmapped-start').on('click', function () {
    var $button = $(this);
    if ($button.prop('disabled')) return;

    $button.prop('disabled', true).addClass('basalam-button-disabled-bg');

    post('start_unmapped_products_scan', config.nonces.start)
      .done(function (response) {
        var message = response && response.data && response.data.message;
        window.BasalamToast.success(message || 'بررسی محصولات آغاز شد.');
        reloadSoon();
      })
      .fail(function (xhr) {
        $button.prop('disabled', false).removeClass('basalam-button-disabled-bg');
        window.BasalamToast.error(errorMessage(xhr, 'خطا در شروع بررسی محصولات.'));
      });
  });

  // ---------- polling وضعیت ----------
  function shouldPoll() {
    var status = summary.getAttribute('data-status');
    var archiveActive = summary.getAttribute('data-archive-active') === '1';
    return status === 'scanning' || archiveActive;
  }

  function schedulePoll() {
    if (pollTimer) clearTimeout(pollTimer);
    pollTimer = shouldPoll() ? setTimeout(poll, nextPollDelay()) : null;
  }

  function poll() {
    pollTimer = null;
    if (document.hidden) {
      schedulePoll();
      return;
    }

    var requestSequence = ++statusRequestSequence;
    post('get_unmapped_scan_status', config.nonces.status, productsPageRequest())
      .done(function (response) {
        if (!response || !response.success || !response.data) return;
        if (requestSequence < lastAppliedStatusRequest) return;
        lastAppliedStatusRequest = requestSequence;
        var data = response.data;
        var pageStatus = summary.getAttribute('data-status');
        var archiveWasActive = summary.getAttribute('data-archive-active') === '1';
        applySummary(data);
        $('#basalam-unmapped-archive-update-error').addClass('basalam-hidden');

        // پیشرفت زندهٔ پیمایش: «n محصول بررسی شد، m مورد متصل‌نشده پیدا شد».
        if (pageStatus === 'scanning') {
          setCounter('basalam-unmapped-scanned', data.scanned || 0);
          setCounter('basalam-unmapped-found-live', data.total_found || 0);
        }

        // اسکن تازه به بارگذاری فهرست نیاز دارد؛ آرشیو بدون reload و با fade به‌روز می‌شود.
        if (pageStatus === 'scanning' && data.status !== 'scanning') {
          window.location.reload();
          return;
        }
        // Settle the final counters after the last worker finishes, without a page reload.
        if (archiveWasActive && !data.archive_active) refreshCounts();
      })
      .fail(function () {
        if (summary.getAttribute('data-archive-active') === '1') {
          $('#basalam-unmapped-archive-update-error').removeClass('basalam-hidden');
        }
      })
      .always(function () {
        schedulePoll();
      });
  }

  schedulePoll();

  // ---------- اقدام‌های هر ردیف ----------
  $(document).on('click', '.basalam-unmapped-archive', function () {
    var $button = $(this);
    var $row = $button.closest('tr');
    var rowId = $button.data('row-id');

    if (!window.confirm('این محصول در باسلام آرشیو شود؟')) return;

    $button.prop('disabled', true).text('در حال آرشیو...');

    post('archive_unmapped_product', config.nonces.archive, { row_id: rowId })
      .done(function (response) {
        handleRowResponse($row, response);
      })
      .fail(function (xhr) {
        $button.prop('disabled', false).text('آرشیو');
        applyRowState($row, 'failed');
        window.BasalamToast.error(errorMessage(xhr, 'آرشیو محصول ناموفق بود.'));
      });
  });

  $(document).on('click', '.basalam-unmapped-ignore', function () {
    var $button = $(this);
    var $row = $button.closest('tr');

    $button.prop('disabled', true);

    post('ignore_unmapped_product', config.nonces.ignore, { row_id: $button.data('row-id') })
      .done(function (response) {
        handleRowResponse($row, response);
      })
      .fail(function (xhr) {
        $button.prop('disabled', false);
        window.BasalamToast.error(errorMessage(xhr, 'نادیده‌گرفتن محصول ناموفق بود.'));
      });
  });

  // ---------- آرشیو گروهی ----------
  $('#basalam-unmapped-archive-stop').on('click', function () {
    var $button = $(this);
    if ($button.prop('disabled')) return;
    stopSubmitting = true;
    $button.prop('disabled', true).attr('aria-busy', 'true');

    post('stop_unmapped_products_archive', config.nonces.stopArchive)
      .done(function (response) {
        if (!response || !response.success || !response.data) {
          $button.prop('disabled', false);
          window.BasalamToast.error((response && response.data && response.data.message) || 'ثبت درخواست توقف آرشیو ناموفق بود.');
          return;
        }
        applySummary(response.data);
        window.BasalamToast.success(response.data.message);
        if (!response.data.archive_active) {
          refreshCounts();
          return;
        }
        schedulePoll();
      })
      .fail(function (xhr) {
        $button.prop('disabled', false);
        window.BasalamToast.error(errorMessage(xhr, 'ثبت درخواست توقف آرشیو ناموفق بود؛ دوباره تلاش کنید.'));
      })
      .always(function () {
        stopSubmitting = false;
        $button.removeAttr('aria-busy');
      });
  });

  var $archiveAllModal = $('#BasalamUnmappedArchiveAllModal');

  $('#basalam-unmapped-archive-all').on('click', function () {
    $archiveAllModal.show();
  });

  $('#basalam-unmapped-archive-all-close').on('click', function () {
    $archiveAllModal.hide();
  });

  $('#basalam-unmapped-archive-all-confirm').on('click', function () {
    var $button = $(this);
    $button.prop('disabled', true).text('در حال ثبت...');

    post('archive_all_unmapped_products', config.nonces.archiveAll)
      .done(function (response) {
        var message = response && response.data && response.data.message;
        window.BasalamToast.success(message || 'آرشیو گروهی در صف قرار گرفت.');
        $archiveAllModal.hide();
        applySummary(response.data);
        if (!response.data.archive_active) {
          refreshCounts();
          return;
        }
        schedulePoll();
      })
      .fail(function (xhr) {
        window.BasalamToast.error(errorMessage(xhr, 'خطا در شروع آرشیو گروهی.'));
      })
      .always(function () {
        $button.prop('disabled', false).text('بله، همه را آرشیو کن');
      });
  });

  // ---------- مودال اتصال ----------
  var $connectModal = $('#BasalamUnmappedConnectModal');
  var connectRowId = null;

  $(document).on('click', '.basalam-unmapped-connect', function () {
    connectRowId = $(this).data('row-id');
    $('#basalam-unmapped-connect-product-title').text($(this).data('title') || '');
    $('#basalam-unmapped-connect-search').val($(this).data('title') || '');
    $('#basalam-unmapped-connect-results').empty();
    $connectModal.show();
  });

  $('#basalam-unmapped-connect-close').on('click', function () {
    $connectModal.hide();
    connectRowId = null;
  });

  function renderSearchResults(products) {
    var $results = $('#basalam-unmapped-connect-results').empty();

    if (!products || !products.length) {
      $results.append($('<p>', { class: 'basalam-p', text: 'محصول ووکامرسِ بدون اتصالی با این عنوان پیدا نشد.' }));
      return;
    }

    products.forEach(function (product) {
      var $item = $('<div>', { class: 'basalam-flex basalam-gap-10', css: { alignItems: 'center', justifyContent: 'space-between', padding: '8px 0', borderBottom: '1px solid #eee' } });
      var label = product.title + (product.price ? ' — ' + toFa(product.price) + ' تومان' : '');
      $item.append($('<span>', { class: 'basalam-p', text: label }));
      $item.append(
        $('<button>', {
          type: 'button',
          class: 'basalam-primary-button basalam-p basalam-padding-small basalam-unmapped-connect-pick',
          text: 'اتصال',
          'data-woo-product-id': product.id,
        })
      );
      $results.append($item);
    });
  }

  function searchWooProducts() {
    var title = String($('#basalam-unmapped-connect-search').val() || '').trim();
    if (!title) {
      window.BasalamToast.warning('عنوان محصول را وارد کنید.');
      return;
    }

    $('#basalam-unmapped-connect-results').html('<p class="basalam-p">در حال جستجو...</p>');

    post('search_woo_products_for_unmapped', config.nonces.search, { title: title })
      .done(function (response) {
        renderSearchResults(response && response.data ? response.data.products : []);
      })
      .fail(function (xhr) {
        $('#basalam-unmapped-connect-results').empty();
        window.BasalamToast.error(errorMessage(xhr, 'خطا در جستجوی محصولات.'));
      });
  }

  $('#basalam-unmapped-connect-search-btn').on('click', searchWooProducts);
  $('#basalam-unmapped-connect-search').on('keydown', function (event) {
    if (event.key === 'Enter') {
      event.preventDefault();
      searchWooProducts();
    }
  });

  $(document).on('click', '.basalam-unmapped-connect-pick', function () {
    var $button = $(this);
    if (!connectRowId) return;

    var $row = $('tr[data-row-id="' + connectRowId + '"]');
    $button.prop('disabled', true).text('اتصال...');

    post('connect_unmapped_product', config.nonces.connect, {
      row_id: connectRowId,
      woo_product_id: $button.data('woo-product-id'),
    })
      .done(function (response) {
        $connectModal.hide();
        connectRowId = null;
        handleRowResponse($row, response);
      })
      .fail(function (xhr) {
        $button.prop('disabled', false).text('اتصال');
        window.BasalamToast.error(errorMessage(xhr, 'اتصال محصول ناموفق بود.'));
      });
  });
});
