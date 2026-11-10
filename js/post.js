/* Artwork add / edit pages. Client-side checks are a convenience only;
   the server validates everything again. */
(function () {
    'use strict';

    var MAX_BYTES = 5 * 1024 * 1024;
    var TYPES = ['image/jpeg', 'image/png', 'image/webp'];

    function $(root, sel) { return root.querySelector(sel); }

    function setError(form, name, message) {
        var el = form.querySelector('[data-error-for="' + name + '"]');
        if (!el) { return; }
        el.textContent = message || '';
        var field = el.closest('.post-field');
        if (field) { field.classList.toggle('has-error', !!message); }
    }

    function initForm(form) {
        var requireImage = form.hasAttribute('data-require-image');
        var title = $(form, '#post-title');
        var desc = $(form, '#post-description');
        var counter = $(form, '[data-counter]');
        var submitBtn = $(form, '[data-submit]');
        var submitting = false;

        /* ---------- Image preview ---------- */
        var upload = $(form, '[data-upload]');
        var input = $(upload, '#post-image');
        var box = $(upload, '.post-upload__box');
        var img = $(upload, '.post-upload__img');
        var actions = $(upload, '[data-upload-actions]');
        var changeBtn = $(upload, '[data-upload-change]');
        var resetBtn = $(upload, '[data-upload-reset]');
        var stageToken = $(form, '[data-stage-token]');
        var original = upload.getAttribute('data-original') || '';
        var staged = upload.getAttribute('data-staged') === '1';
        var objectUrl = null;

        function showImage(src) {
            img.src = src;
            img.hidden = false;
            box.classList.add('has-image');
            actions.hidden = false;
        }

        function showEmpty() {
            img.removeAttribute('src');
            img.hidden = true;
            box.classList.remove('has-image');
            actions.hidden = true;
        }

        function releaseUrl() {
            if (objectUrl) { URL.revokeObjectURL(objectUrl); objectUrl = null; }
        }

        function refreshReset() {
            var hasNew = !!(input.files && input.files.length);
            if (hasNew || staged) {
                resetBtn.hidden = false;
                resetBtn.textContent = original ? 'Keep current image' : 'Remove image';
            } else {
                resetBtn.hidden = true;
            }
        }

        input.addEventListener('change', function () {
            var file = input.files && input.files[0];
            setError(form, 'image', '');
            if (!file) { return; }

            // Check the file extension on the frontend.
            // PHP will perform the actual MIME and image-content validation.
            var allowedExtension = /\.(jpg|jpeg|png|webp)$/i.test(file.name);

            if (!allowedExtension) {
                input.value = '';
                setError(form, 'image', 'Only JPG, PNG or WEBP images are allowed.');
                return;
            }
            if (file.size > MAX_BYTES) {
                input.value = '';
                setError(form, 'image', 'The image is too large. The maximum size is 5 MB.');
                return;
            }
            releaseUrl();
            objectUrl = URL.createObjectURL(file);
            showImage(objectUrl);
            refreshReset();
        });

        changeBtn.addEventListener('click', function () { input.click(); });

        resetBtn.addEventListener('click', function () {
            input.value = '';
            releaseUrl();
            staged = false;
            if (stageToken) { stageToken.value = ''; }
            if (original) { showImage(original); } else { showEmpty(); }
            setError(form, 'image', '');
            refreshReset();
        });

        ['dragenter', 'dragover'].forEach(function (evt) {
            box.addEventListener(evt, function (e) { e.preventDefault(); box.classList.add('is-dragover'); });
        });
        ['dragleave', 'drop'].forEach(function (evt) {
            box.addEventListener(evt, function (e) { e.preventDefault(); box.classList.remove('is-dragover'); });
        });
        box.addEventListener('drop', function (e) {
            if (e.dataTransfer && e.dataTransfer.files && e.dataTransfer.files.length) {
                input.files = e.dataTransfer.files;
                input.dispatchEvent(new Event('change', { bubbles: true }));
            }
        });
        refreshReset();

        /* ---------- Category selection ---------- */
        var radios = form.querySelectorAll('.post-cat__input');
        radios.forEach(function (radio) {
            radio.addEventListener('change', function () {
                form.querySelectorAll('.post-cat').forEach(function (c) {
                    var r = c.querySelector('.post-cat__input');
                    c.classList.toggle('is-selected', r.checked);
                });
                setError(form, 'category', '');
            });
        });

        /* ---------- Counters and inline error clearing ---------- */
        function updateCounter() { if (desc && counter) { counter.textContent = desc.value.length; } }
        if (desc) { desc.addEventListener('input', updateCounter); updateCounter(); }
        if (title) { title.addEventListener('input', function () { setError(form, 'title', ''); }); }

        /* ---------- Validation and loading state ---------- */
        form.addEventListener('submit', function (e) {
            if (submitting) { e.preventDefault(); return; }

            var firstBad = null;
            var t = title.value.trim();
            var hasImage = (input.files && input.files.length) || staged || (original && true) ||
                !requireImage;
            var picked = form.querySelector('.post-cat__input:checked');

            setError(form, 'title', '');
            setError(form, 'category', '');
            if (!(input.files && input.files.length)) { /* keep server-provided image error until retry */ }

            if (!t) {
                setError(form, 'title', 'Please enter a title for your artwork.');
                firstBad = firstBad || title;
            } else if (t.length > 150) {
                setError(form, 'title', 'The title can be at most 150 characters.');
                firstBad = firstBad || title;
            }
            if (!hasImage) {
                setError(form, 'image', 'Please upload an image of your artwork.');
                firstBad = firstBad || input;
            }
            if (!picked) {
                setError(form, 'category', 'Please select a category.');
                firstBad = firstBad || form.querySelector('.post-cat__input');
            }

            if (firstBad) {
                e.preventDefault();
                var target = firstBad === input ? box : firstBad;
                target.scrollIntoView({ behavior: 'smooth', block: 'center' });
                if (firstBad !== input) { firstBad.focus({ preventScroll: true }); }
                return;
            }

            submitting = true;
            if (submitBtn) {
                submitBtn.classList.add('is-loading');
                submitBtn.setAttribute('aria-busy', 'true');
                // Disable after the browser has collected the form data.
                setTimeout(function () { submitBtn.disabled = true; }, 0);
            }
        });

        // Restore the button if the page is shown again from the back/forward cache.
        window.addEventListener('pageshow', function (ev) {
            if (ev.persisted && submitBtn) {
                submitting = false;
                submitBtn.disabled = false;
                submitBtn.classList.remove('is-loading');
                submitBtn.removeAttribute('aria-busy');
            }
        });
    }

    /* ---------- Delete confirmation (edit page) ---------- */
    function initDelete() {
        var dialog = document.getElementById('delete-dialog');
        var openBtn = document.querySelector('[data-delete-open]');
        if (!dialog || !openBtn) { return; }

        var cancelBtn = dialog.querySelector('[data-dialog-cancel]');
        var confirmBtn = dialog.querySelector('[data-delete-confirm]');
        var deleteForm = document.getElementById('delete-form');

        openBtn.addEventListener('click', function () {
            if (typeof dialog.showModal === 'function') {
                dialog.showModal();
                cancelBtn.focus();
            } else if (window.confirm('Delete this artwork permanently? This can\'t be undone.')) {
                deleteForm.submit();
            }
        });
        cancelBtn.addEventListener('click', function () { dialog.close(); });
        dialog.addEventListener('click', function (e) { if (e.target === dialog) { dialog.close(); } });
        confirmBtn.addEventListener('click', function () {
            // The form attribute submits the delete form; block repeat clicks.
            setTimeout(function () { confirmBtn.disabled = true; }, 0);
        });
    }

    /* ---------- Notices ---------- */
    function initNotices() {
        document.querySelectorAll('[data-dismiss]').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var n = btn.closest('.post-notice');
                if (n) { n.remove(); }
            });
        });
        var first = document.querySelector('[data-notice]');
        if (first) {
            first.scrollIntoView({ block: 'center' });
            first.focus({ preventScroll: true });
        }
    }

    function init() {
        document.querySelectorAll('[data-post-form]').forEach(initForm);
        initDelete();
        initNotices();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
