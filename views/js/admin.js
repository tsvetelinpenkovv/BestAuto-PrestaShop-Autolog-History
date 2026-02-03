(function () {
  'use strict';

  // UI pagination size inside the employee "dropdown" (collapse list)
  var PER_PAGE = 10;

  // Client request: remove the short "refresh" after opening the dropdown.
  // We keep the server-rendered content and disable AJAX re-render + polling.
  // (If you ever need realtime updates back, set to true.)
  var ENABLE_REALTIME_REFRESH = false;

  function getWrap() {
    return document.querySelector('.baal-wrap');
  }

  function buildAjaxUrl(params) {
    var url = new URL(window.location.href);
    url.hash = '';
    Object.keys(params).forEach(function (k) { url.searchParams.set(k, params[k]); });
    return url.toString();
  }

  function escapeHtml(str) {
    return str.replace(/[&<>"']/g, function (m) {
      return ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' })[m];
    });
  }

  function initTooltips(root) {
    if (!root) root = document;
    // Prestashop BO uses Bootstrap + jQuery. If not present, silently skip.
    if (!(window.jQuery && window.jQuery.fn && window.jQuery.fn.tooltip)) return;
    try {
      // Dispose existing tooltips in the subtree to prevent duplicates after AJAX refresh
      window.jQuery(root).find('[data-toggle="tooltip"]').tooltip('dispose');
      window.jQuery(root).find('[data-toggle="tooltip"]').tooltip({ container: 'body' });
    } catch (e) {
      // ignore
    }
  }

  function ensurePager(container) {
    if (!container) return null;
    var existing = container.querySelector('.baal-mini-pager');
    if (existing) return existing;

    var pager = document.createElement('div');
    pager.className = 'baal-mini-pager';
    pager.innerHTML =
      '<button type="button" class="btn btn-default btn-xs baal-pg-prev" data-toggle="tooltip" title="Предишна страница">&larr;</button>' +
      '<div class="baal-pg-pages"></div>' +
      '<button type="button" class="btn btn-default btn-xs baal-pg-next" data-toggle="tooltip" title="Следваща страница">&rarr;</button>' +
      '<span class="baal-pg-info text-muted"></span>';

    container.appendChild(pager);

    // Keep collapse open; prevent any default navigation
    pager.addEventListener('click', function (e) {
      e.preventDefault();
      e.stopPropagation();
    });
    return pager;
  }

  function renderPageButtons(pager, page, totalPages) {
    if (!pager) return;
    var pagesWrap = pager.querySelector('.baal-pg-pages');
    var info = pager.querySelector('.baal-pg-info');
    if (!pagesWrap || !info) return;

    // Show up to 5 numeric buttons around current page
    var maxBtns = 5;
    var start = Math.max(1, page - Math.floor(maxBtns / 2));
    var end = Math.min(totalPages, start + maxBtns - 1);
    start = Math.max(1, end - maxBtns + 1);

    var html = '';
    for (var p = start; p <= end; p++) {
      html += '<button type="button" class="btn btn-default btn-xs baal-pg-page' + (p === page ? ' active' : '') + '" data-page="' + p + '">' + p + '</button>';
    }
    pagesWrap.innerHTML = html;
    info.textContent = ' ' + page + ' / ' + totalPages;
  }

  function paginateActions(targetEl) {
    if (!targetEl) return;
    var actions = targetEl.querySelectorAll('.baal-session-action');
    var total = actions.length;

    // If <= PER_PAGE hide pager and show all
    var totalPages = Math.max(1, Math.ceil(total / PER_PAGE));
    var pager = ensurePager(targetEl);
    if (pager) {
      pager.style.display = totalPages > 1 ? '' : 'none';
    }

    // remember page
    var currentPage = parseInt(targetEl.getAttribute('data-baal-page') || '1', 10);
    if (!currentPage || currentPage < 1) currentPage = 1;
    if (currentPage > totalPages) currentPage = totalPages;
    targetEl.setAttribute('data-baal-page', String(currentPage));

    // Render visibility
    var start = (currentPage - 1) * PER_PAGE;
    var end = start + PER_PAGE;
    for (var i = 0; i < actions.length; i++) {
      actions[i].style.display = (i >= start && i < end) ? '' : 'none';
    }

    if (pager) {
      // Buttons
      renderPageButtons(pager, currentPage, totalPages);
      var prevBtn = pager.querySelector('.baal-pg-prev');
      var nextBtn = pager.querySelector('.baal-pg-next');
      if (prevBtn) prevBtn.disabled = currentPage <= 1;
      if (nextBtn) nextBtn.disabled = currentPage >= totalPages;

      // Attach handlers (idempotent)
      if (!pager._baalBound) {
        pager._baalBound = true;
        pager.addEventListener('click', function (e) {
          var t = e.target;
          if (!t) return;
          if (t.classList.contains('baal-pg-prev')) {
            var p1 = parseInt(targetEl.getAttribute('data-baal-page') || '1', 10) - 1;
            targetEl.setAttribute('data-baal-page', String(Math.max(1, p1)));
            paginateActions(targetEl);
            initTooltips(pager);
          } else if (t.classList.contains('baal-pg-next')) {
            var p2 = parseInt(targetEl.getAttribute('data-baal-page') || '1', 10) + 1;
            targetEl.setAttribute('data-baal-page', String(Math.min(totalPages, p2)));
            paginateActions(targetEl);
            initTooltips(pager);
          } else if (t.classList.contains('baal-pg-page')) {
            var p3 = parseInt(t.getAttribute('data-page') || '1', 10);
            targetEl.setAttribute('data-baal-page', String(p3));
            paginateActions(targetEl);
            initTooltips(pager);
          }
        });
      }
    }

    initTooltips(targetEl);
  }

  function fetchEmployeeActions(employeeId, targetEl) {
    var wrap = getWrap();
    if (!wrap) return Promise.resolve();

    var token = wrap.getAttribute('data-baal-token') || '';
    var url = buildAjaxUrl({
      baal_ajax: '1',
      baal_action: 'employee_actions',
      employee_id: String(employeeId),
      token: token,
      limit: '160'
    });

    if (targetEl && targetEl.getAttribute('data-baal-has-content') !== '1') {
      targetEl.innerHTML = '<div class="baal-muted"><i class="icon-refresh"></i> Зареждане...</div>';
    }

    return fetch(url, { credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (data) {
        if (!data || !data.ok) return;

        if (targetEl) {
          targetEl.innerHTML = data.items_html || '';
          targetEl.setAttribute('data-baal-has-content', '1');
          // paginate inside the "dropdown" list
          paginateActions(targetEl);
        }

        var lastAt = document.querySelector('.baal-last-at[data-employee-id="' + employeeId + '"]');
        if (lastAt && data.last_at_fmt) {
          lastAt.innerHTML = '<i class="icon-calendar"></i> ' + escapeHtml(String(data.last_at_fmt));
        }
        var cEl = document.querySelector('.baal-actions-count[data-employee-id="' + employeeId + '"]');
        if (cEl && typeof data.count === 'number') {
          cEl.textContent = String(data.count);
        }
      })
      .catch(function () { /* ignore */ });
  }

  function attachCollapseEvents(el, onShown, onHidden) {
    if (window.jQuery && window.jQuery.fn && window.jQuery.fn.on) {
      window.jQuery(el).on('shown.bs.collapse', onShown);
      window.jQuery(el).on('hidden.bs.collapse', onHidden);
    } else {
      el.addEventListener('shown.bs.collapse', onShown);
      el.addEventListener('hidden.bs.collapse', onHidden);
    }
  }

  function setupRealtime() {
    var collapses = document.querySelectorAll('.baal-emp-collapse');
    Array.prototype.forEach.call(collapses, function (col) {
      attachCollapseEvents(
        col,
        function () {
          var employeeId = col.getAttribute('data-employee-id');
          var target = col.querySelector('.baal-live-items[data-employee-id="' + employeeId + '"]');
          if (!employeeId || !target) return;

          // Keep UI snappy: no AJAX re-render on open (client request)
          paginateActions(target);
          initTooltips(target);

          if (ENABLE_REALTIME_REFRESH) {
            fetchEmployeeActions(employeeId, target);
            if (col._baalTimer) clearInterval(col._baalTimer);
            col._baalTimer = setInterval(function () {
              fetchEmployeeActions(employeeId, target);
            }, 10000);
          }
        },
        function () {
          // Stop polling if it was enabled
          if (col._baalTimer) {
            clearInterval(col._baalTimer);
            col._baalTimer = null;
          }
        }
      );
    });

    // init tooltips on initial render
    initTooltips(document);

    // init pagination for any server-rendered lists (first open before AJAX)
    var initialLists = document.querySelectorAll('.baal-live-items');
    Array.prototype.forEach.call(initialLists, function (el) {
      paginateActions(el);
    });
  }

  document.addEventListener('DOMContentLoaded', setupRealtime);
})();
