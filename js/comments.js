/* Comments and replies: small in-page interactions.
   Progressive enhancement: without JavaScript every Reply / Edit / Delete link
   reloads the page with that panel open, and every form is a normal POST to
   comment_action.php. The server validates everything again. */
(function () {
    'use strict';

    var BUSY_TIMEOUT_MS = 15000;   // re-enable a form if the request never finishes

    function each(list, fn) { Array.prototype.forEach.call(list, fn); }

    /* ---------- Character counter (counts characters, like the server) ---------- */

    function initInput(textarea) {
        var max = parseInt(textarea.getAttribute('data-max'), 10) || 1000;
        var form = textarea.form;
        var counter = form ? form.querySelector('[data-comment-counter]') : null;
        var hint = counter ? counter.parentNode : null;

        // maxlength counts UTF-16 units (an emoji = 2). The server counts characters,
        // so while JavaScript runs the limit is enforced here the same way.
        textarea.removeAttribute('maxlength');

        function update() {
            var chars = Array.from(textarea.value);
            if (chars.length > max) {
                chars = chars.slice(0, max);
                textarea.value = chars.join('');
            }
            if (counter) { counter.textContent = String(chars.length); }
            if (hint) { hint.classList.toggle('is-near', chars.length >= max * 0.9); }
        }

        textarea.addEventListener('input', function (event) {
            if (event.isComposing) { return; }
            clearError(form);
            update();
        });
        textarea.addEventListener('compositionend', update);
        update();
    }

    /* ---------- Inline error line inside a form ---------- */

    function clearError(form) {
        var old = form && form.querySelector('[data-comment-error]');
        if (old) { old.parentNode.removeChild(old); }
    }

    function showError(form, message) {
        clearError(form);
        var p = document.createElement('p');
        p.className = 'comment-form__error';
        p.setAttribute('role', 'alert');
        p.setAttribute('data-comment-error', '');
        p.textContent = message;
        var textarea = form.querySelector('[data-comment-input]');
        textarea.parentNode.insertBefore(p, textarea);
    }

    /* ---------- Reply / edit / delete panels ---------- */

    function togglesFor(content) {
        return content.querySelectorAll('[data-panel-toggle]');
    }

    function syncToggles(content) {
        each(togglesFor(content), function (toggle) {
            var panel = document.getElementById(toggle.getAttribute('aria-controls'));
            toggle.setAttribute('aria-expanded', panel && !panel.hidden ? 'true' : 'false');
        });
    }

    function openPanel(panel) {
        var content = panel.parentNode;
        // One open panel per comment: close its other panels first.
        each(content.querySelectorAll('form[data-comment-form]'), function (other) {
            if (other !== panel && !other.hidden) { other.hidden = true; }
        });
        panel.hidden = false;
        syncToggles(content);

        var target = panel.querySelector('[data-comment-input]') || panel.querySelector('[data-comment-cancel]');
        if (target) {
            target.focus();
            if (target.setSelectionRange) {
                var end = target.value.length;
                target.setSelectionRange(end, end);
            }
        }
    }

    function closePanel(panel, returnFocus) {
        var textarea = panel.querySelector('[data-comment-input]');
        if (textarea) {
            textarea.value = textarea.defaultValue;      // cancelling discards unsaved changes
            textarea.dispatchEvent(new Event('input', { bubbles: true }));
        }
        clearError(panel);
        panel.hidden = true;
        syncToggles(panel.parentNode);
        if (returnFocus) {
            var toggle = document.querySelector('[data-panel-toggle][aria-controls="' + panel.id + '"]');
            if (toggle) { toggle.focus(); }
        }
    }

    document.addEventListener('click', function (event) {
        // Let ctrl/cmd/shift/middle clicks open the fallback link normally.
        if (event.defaultPrevented || event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) {
            return;
        }
        var toggle = event.target.closest ? event.target.closest('[data-panel-toggle]') : null;
        if (toggle) {
            var panel = document.getElementById(toggle.getAttribute('aria-controls'));
            if (!panel) { return; }                       // no panel: follow the link
            event.preventDefault();
            if (panel.hidden) { openPanel(panel); } else { closePanel(panel, true); }
            return;
        }
        var cancel = event.target.closest ? event.target.closest('[data-comment-cancel]') : null;
        if (cancel) {
            var form = cancel.closest('form');
            if (form) {
                event.preventDefault();
                closePanel(form, true);
            }
        }
    });

    document.addEventListener('keydown', function (event) {
        if (event.key !== 'Escape') { return; }
        var form = event.target.closest ? event.target.closest('form[data-comment-form]') : null;
        if (form && form.querySelector('[data-comment-cancel]') && !form.hidden) {
            event.preventDefault();
            closePanel(form, true);
        }
    });

    /* ---------- Submitting: validate, then block double submits ---------- */

    function isBlank(text) {
        return /^[\s\u200B-\u200D\uFEFF]*$/.test(text);
    }

    function resetForm(form) {
        form.removeAttribute('data-submitting');
        each(form.querySelectorAll('[data-comment-submit]'), function (button) {
            button.disabled = false;
            if (button.getAttribute('data-label')) { button.textContent = button.getAttribute('data-label'); }
        });
    }

    document.addEventListener('submit', function (event) {
        var form = event.target;
        if (!form.matches || !form.matches('[data-comment-form]')) { return; }

        if (form.getAttribute('data-submitting') === '1') {      // double click / Enter twice
            event.preventDefault();
            return;
        }

        var textarea = form.querySelector('[data-comment-input]');
        if (textarea && isBlank(textarea.value)) {
            event.preventDefault();
            showError(form, 'Please write something before posting.');
            textarea.focus();
            return;
        }

        form.setAttribute('data-submitting', '1');
        each(form.querySelectorAll('[data-comment-submit]'), function (button) {
            button.setAttribute('data-label', button.textContent);
            button.textContent = button.getAttribute('data-busy-label') || button.textContent;
            // Disabling inside the submit handler does not cancel the submission.
            button.disabled = true;
        });
        window.setTimeout(function () { resetForm(form); }, BUSY_TIMEOUT_MS);
    });

    // Coming back with the Back button can restore a page whose forms were left "busy".
    window.addEventListener('pageshow', function (event) {
        if (event.persisted) {
            each(document.querySelectorAll('[data-comment-form]'), resetForm);
        }
    });

    /* ---------- Start ---------- */

    each(document.querySelectorAll('[data-comment-input]'), initInput);

    // Arriving from a "Comment" button (artwork.php?id=N#comment-form-main-body):
    // the browser scrolls to the box; put the cursor in it as well.
    if (window.location.hash === '#comment-form-main-body') {
        var box = document.getElementById('comment-form-main-body');
        if (box) { box.focus({ preventScroll: true }); }
    }
})();
