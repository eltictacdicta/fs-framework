/**
 * htmx-crud.js — Opt-in HTMX CRUD module (HCS-16, CRD-02, CRD-04, CRD-06).
 *
 * Loaded ONLY via Macro/HtmxCrud.html.twig boot() line.
 * Reads data-fs-crud-config JSON; no-ops when absent.
 * IIFE, 'use strict', CSP-safe (no eval, no inline handlers).
 */
(function () {
    'use strict';

    var configEl = document.querySelector('script[data-fs-crud-config]');
    if (!configEl) {
        return; // No config — module is inert (CRD-05).
    }

    var config;
    try {
        config = JSON.parse(configEl.textContent);
    } catch (e) {
        return; // Malformed config — stay inert.
    }

    // =====================================================================
    // CSRF strip: remove embedded _csrf_token inputs from forms marked
    // [data-fs-csrf-strip]. Header X-CSRF-TOKEN is the only token source
    // on htmx requests (CRD-02, HCS-07).
    // =====================================================================
    function stripCsrfInputs(root) {
        var forms = (root || document).querySelectorAll('[data-fs-csrf-strip] input[name="_csrf_token"]');
        for (var i = 0; i < forms.length; i++) {
            forms[i].remove();
        }
    }

    // Strip at init — ONLY when htmx core is actually loaded. Deferred
    // scripts execute in document order, so htmx.boot()'s asset runs
    // before this module when both are present. Without htmx the embedded
    // token must survive to validate the plain POST fallback.
    if (typeof window.htmx !== 'undefined') {
        stripCsrfInputs(document);
    }

    // =====================================================================
    // fs:flash — transient Bootstrap-3 toasts (HCS-16).
    // errors → alert-danger, messages → alert-success, advices → alert-info.
    // Auto-dismiss after 3 seconds.
    // =====================================================================
    document.addEventListener('fs:flash', function (evt) {
        var detail = evt.detail || {};
        var errors = detail.errors || [];
        var messages = detail.messages || [];
        var advices = detail.advices || [];

        var container = ensureToastContainer();

        function addToast(text, cssClass) {
            var toast = document.createElement('div');
            toast.className = 'alert ' + cssClass + ' alert-dismissible';
            toast.style.cssText = 'margin-bottom:5px;padding:8px 30px 8px 12px;position:relative;';
            toast.textContent = text;

            var closeBtn = document.createElement('button');
            closeBtn.type = 'button';
            closeBtn.className = 'close';
            closeBtn.style.cssText = 'position:absolute;right:8px;top:4px;';
            closeBtn.setAttribute('data-dismiss', 'alert');
            closeBtn.innerHTML = '&times;';
            toast.appendChild(closeBtn);

            container.appendChild(toast);

            // Auto-dismiss after 3 seconds
            setTimeout(function () {
                if (toast.parentNode) {
                    toast.parentNode.removeChild(toast);
                }
            }, 3000);
        }

        for (var i = 0; i < errors.length; i++) {
            addToast(errors[i], 'alert-danger');
        }
        for (var j = 0; j < messages.length; j++) {
            addToast(messages[j], 'alert-success');
        }
        for (var k = 0; k < advices.length; k++) {
            addToast(advices[k], 'alert-info');
        }
    });

    function ensureToastContainer() {
        var existing = document.getElementById('fs-htmx-toast-container');
        if (existing) {
            return existing;
        }
        var div = document.createElement('div');
        div.id = 'fs-htmx-toast-container';
        div.style.cssText = 'position:fixed;top:10px;right:10px;z-index:9999;width:320px;';
        document.body.appendChild(div);
        return div;
    }

    // =====================================================================
    // fs:modal-close — close the containing Bootstrap modal (HCS-16).
    // Also closes custom popup overlays (TarifarioComponents.modal_popup_start).
    // Uses Bootstrap 3 jQuery global.
    // =====================================================================
    document.addEventListener('fs:modal-close', function (evt) {
        var target = evt.target || evt.srcElement;
        var scope = target && target.closest ? target : document;
        if (window.jQuery) {
            var modal = scope.closest ? scope.closest('.modal') : null;
            window.jQuery(modal).modal('hide');
        }
        var overlay = scope.closest ? scope.closest('.modal-popup-overlay') : null;
        if (overlay) {
            overlay.style.display = 'none';
        } else if (scope === document) {
            // Event fired on document (no element context) — hide any visible overlay.
            var overlays = document.querySelectorAll('.modal-popup-overlay');
            for (var i = 0; i < overlays.length; i++) {
                if (overlays[i].style.display !== 'none') {
                    overlays[i].style.display = 'none';
                }
            }
        }
    });

    // =====================================================================
    // htmx:afterRequest — update footer debug labels from X-FS-* headers
    // (HCS-16, HCS-15). Footer markup is frozen; label prefixes preserved.
    // =====================================================================
    document.addEventListener('htmx:afterRequest', function (evt) {
        var xhr = evt.detail && evt.detail.xhr;
        if (!xhr) {
            return;
        }

        var duration = xhr.getResponseHeader('X-FS-Duration');
        var queries = xhr.getResponseHeader('X-FS-Queries');
        var transactions = xhr.getResponseHeader('X-FS-Transactions');

        var footer = document.querySelector('footer.main-footer');
        if (!footer) {
            return;
        }

        // Update label text nodes by suffix replacement
        updateFooterLabel(footer, 'duration', duration);
        updateFooterLabel(footer, 'queries', queries);
        updateFooterLabel(footer, 'transactions', transactions);
    });

    function updateFooterLabel(footer, keyword, value) {
        if (value === null || value === undefined) {
            return;
        }
        // Find span.label elements containing the keyword text
        var labels = footer.querySelectorAll('span.label');
        for (var i = 0; i < labels.length; i++) {
            var text = labels[i].textContent || '';
            var lower = text.toLowerCase();
            if (lower.indexOf(keyword) !== -1) {
                // Replace the numeric portion at the end (after the colon)
                labels[i].textContent = text.replace(/:\s*\S+$/, ': ' + value);
                return;
            }
        }
    }

    // =====================================================================
    // htmx:afterSettle — strip CSRF inputs from newly swapped content
    // (HCS-16, CRD-02).
    // =====================================================================
    document.addEventListener('htmx:afterSettle', function (evt) {
        var target = evt.target || document;
        stripCsrfInputs(target);
    });

    // =====================================================================
    // htmx:oobErrorNoTarget — console.warn diagnostic (HCS-16).
    // =====================================================================
    document.addEventListener('htmx:oobErrorNoTarget', function (evt) {
        if (typeof console !== 'undefined' && console.warn) {
            console.warn('[htmx-crud] OOB target not found:', evt.detail);
        }
    });

    // =====================================================================
    // htmx:confirm — capture-phase interception for hx-confirm dialogs
    // (CRD-06). Uses fs-dialogs bootbox shim (loaded by boot line);
    // falls back to window.confirm if shim absent.
    //
    // htmx 4.0.0 fires htmx:confirm with:
    //   detail.issueRequest() — proceed
    //   detail.dropRequest()  — cancel
    //   detail.question        — the confirm text
    // =====================================================================
    document.addEventListener('htmx:confirm', function (evt) {
        var detail = evt.detail;
        if (!detail || !detail.question) {
            return; // No confirm prompt — let htmx proceed normally.
        }

        evt.preventDefault();

        var question = detail.question;

        // Try the bootbox-compatible shim first (fs-dialogs.js)
        if (window.bootbox && typeof window.bootbox.confirm === 'function') {
            window.bootbox.confirm({
                message: question,
                callback: function (result) {
                    if (result) {
                        detail.issueRequest();
                    }
                }
            });
            return;
        }

        // Fallback: native window.confirm
        if (window.confirm(question)) {
            detail.issueRequest();
        }
    });

    // =====================================================================
    // SortableJS init — from config.sortable (CRD-04).
    // Handle: .drag-handle, draggable: tr[data-codfamilia].
    // Same-madre onMove guard; onEnd marks dirty.
    // =====================================================================
    function initSortable() {
        if (!config.sortable || typeof Sortable === 'undefined') {
            return;
        }

        var tbodyId = String(config.sortable.tbody || '').replace(/^#/, '');
        var tbody = document.getElementById(tbodyId);
        if (!tbody) {
            return;
        }

        var saveBarId = config.sortable.saveBar;
        var saveUrl = config.sortable.url;

        // Capture initial order for cancel restore
        var initialIds = getRowCodes(tbody);

        Sortable.create(tbody, {
            handle: '.drag-handle',
            animation: 150,
            draggable: 'tr[data-codfamilia]',
            onMove: function (evt) {
                // Same-madre guard: reject cross-madre moves
                var draggedMadre = (evt.dragged.dataset.madre || '');
                var relatedMadre = (evt.related.dataset.madre || '');
                return draggedMadre === relatedMadre;
            },
            onEnd: function () {
                markDirty(tbodyId, saveBarId, saveUrl, initialIds);
            }
        });

        // Wire save/cancel buttons
        var saveBar = saveBarId ? document.getElementById(saveBarId + '-savebar') : null;
        if (saveBar) {
            var saveBtn = saveBar.querySelector('.btn-save-order');
            var cancelBtn = saveBar.querySelector('.btn-cancel-order');

            if (saveBtn) {
                saveBtn.addEventListener('click', function () {
                    saveOrder(tbodyId, saveUrl);
                });
            }
            if (cancelBtn) {
                cancelBtn.addEventListener('click', function () {
                    cancelOrder(tbodyId, saveBarId, initialIds);
                });
            }
        }
    }

    function getRowCodes(tbody) {
        var rows = tbody.querySelectorAll('tr[data-codfamilia]');
        var codes = [];
        for (var i = 0; i < rows.length; i++) {
            codes.push(rows[i].dataset.codfamilia);
        }
        return codes;
    }

    function markDirty(tbodyId, saveBarId, saveUrl, initialIds) {
        var tbody = document.getElementById(tbodyId);
        if (!tbody) {
            return;
        }

        var currentIds = getRowCodes(tbody);
        var dirty = JSON.stringify(currentIds) !== JSON.stringify(initialIds);

        var saveBar = saveBarId ? document.getElementById(saveBarId + '-savebar') : null;
        if (saveBar) {
            saveBar.style.display = dirty ? 'block' : 'none';
        }
    }

    function saveOrder(tbodyId, saveUrl) {
        if (!saveUrl) {
            return;
        }
        var tbody = document.getElementById(tbodyId);
        if (!tbody) {
            return;
        }

        var codes = getRowCodes(tbody);

        // Issue a single htmx.ajax POST with the flat codes
        if (typeof htmx !== 'undefined' && htmx.ajax) {
            htmx.ajax('POST', saveUrl, {
                values: { order: JSON.stringify(codes) },
                target: '#' + tbodyId,
                swap: 'outerHTML'
            });
        }
    }

    function cancelOrder(tbodyId, saveBarId, initialIds) {
        // Restore initial DOM order by sorting rows
        var tbody = document.getElementById(tbodyId);
        if (!tbody) {
            return;
        }

        var rows = Array.prototype.slice.call(tbody.querySelectorAll('tr[data-codfamilia]'));
        var orderMap = {};
        for (var i = 0; i < rows.length; i++) {
            orderMap[rows[i].dataset.codfamilia] = rows[i];
        }

        // Re-append in initial order
        for (var j = 0; j < initialIds.length; j++) {
            if (orderMap[initialIds[j]]) {
                tbody.appendChild(orderMap[initialIds[j]]);
            }
        }

        // Hide save bar — no request
        var saveBar = saveBarId ? document.getElementById(saveBarId + '-savebar') : null;
        if (saveBar) {
            saveBar.style.display = 'none';
        }
    }

    // Initialize SortableJS after DOM is ready
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initSortable);
    } else {
        initSortable();
    }
})();
