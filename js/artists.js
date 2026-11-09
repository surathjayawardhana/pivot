/* Artist directory (artist.php).
   Everything here is an enhancement: without JavaScript the follow buttons are
   normal form posts, the galleries still scroll, and "Load more" is a normal
   link to the next page. The server validates every request again. */
(function () {
    'use strict';

    var list = document.getElementById('artist-list');
    if (!list) { return; }

    var statusEl = document.querySelector('[data-artists-status]');
    var reduceMotion = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;

    function announce(message) {
        if (!statusEl) { return; }
        statusEl.textContent = '';                       // re-set so repeated messages are read again
        window.setTimeout(function () { statusEl.textContent = message; }, 30);
    }

    /* ---------- Follow / unfollow without a page reload ---------- */

    function setFollowState(form, following, name) {
        var button = form.querySelector('[data-follow-button]');
        var action = form.querySelector('[data-follow-action]');
        var rest = form.querySelector('[data-label-rest]');

        action.value = following ? 'unfollow' : 'follow';
        rest.textContent = following ? 'Following' : 'Follow';
        button.classList.toggle('is-following', following);
        button.setAttribute('aria-label', (following ? 'Unfollow ' : 'Follow ') + name);
    }

    list.addEventListener('submit', function (event) {
        var form = event.target;
        if (!form.matches || !form.matches('[data-follow-form]')) { return; }
        if (!window.fetch || !window.FormData) { return; }          // plain form post instead

        event.preventDefault();

        var button = form.querySelector('[data-follow-button]');
        var name = form.getAttribute('data-artist-name') || 'this artist';
        if (button.disabled) { return; }

        button.disabled = true;
        button.setAttribute('aria-busy', 'true');

        // getAttribute: the form has an input named "action", which shadows form.action.
        fetch(form.getAttribute('action'), {
            method: 'POST',
            body: new FormData(form),
            credentials: 'same-origin',
            headers: { 'X-Requested-With': 'fetch', 'Accept': 'application/json' }
        })
            .then(function (response) {
                return response.json().then(function (data) {
                    return { status: response.status, data: data };
                });
            })
            .then(function (result) {
                var data = result.data;

                if (result.status === 401 && data.login) {          // session ended: go and log in
                    window.location.href = data.login;
                    return;
                }
                if (!data.ok) { throw new Error(data.message || 'Request failed'); }

                setFollowState(form, !!data.following, name);

                var card = form.closest('.artist-card');
                var countEl = card && card.querySelector('[data-followers-count]');
                var wordEl = card && card.querySelector('[data-followers-word]');
                if (countEl) { countEl.textContent = Number(data.followers).toLocaleString(); }
                if (wordEl) { wordEl.textContent = Number(data.followers) === 1 ? 'follower' : 'followers'; }

                announce(data.message || (data.following ? 'You are now following ' + name + '.' : 'You unfollowed ' + name + '.'));
            })
            .catch(function (error) {
                announce((error && error.message && error.message !== 'Request failed' && error.name !== 'TypeError' && error.name !== 'SyntaxError')
                    ? error.message
                    : 'Something went wrong. Please try again.');
            })
            .then(function () {                                      // always runs
                button.disabled = false;
                button.removeAttribute('aria-busy');
            });
    });

    /* ---------- Horizontal galleries: arrow buttons when there is more to see ---------- */

    function initGallery(works) {
        var gallery = works.querySelector('[data-gallery]');
        var prev = works.querySelector('[data-gallery-prev]');
        var next = works.querySelector('[data-gallery-next]');
        if (!gallery || !prev || !next || gallery.hasAttribute('data-gallery-ready')) { return; }
        gallery.setAttribute('data-gallery-ready', '');

        function update() {
            var overflow = gallery.scrollWidth - gallery.clientWidth > 2;
            prev.hidden = !overflow;
            next.hidden = !overflow;
            prev.disabled = gallery.scrollLeft <= 2;
            next.disabled = gallery.scrollLeft >= gallery.scrollWidth - gallery.clientWidth - 2;
        }

        function scrollByPage(direction) {
            gallery.scrollBy({
                left: direction * gallery.clientWidth * 0.9,
                behavior: reduceMotion ? 'auto' : 'smooth'
            });
        }

        prev.addEventListener('click', function () { scrollByPage(-1); });
        next.addEventListener('click', function () { scrollByPage(1); });
        gallery.addEventListener('scroll', update, { passive: true });
        window.addEventListener('resize', update);
        if (window.ResizeObserver) { new ResizeObserver(update).observe(gallery); }

        // Images load lazily and change the width: measure again when they arrive.
        Array.prototype.forEach.call(gallery.querySelectorAll('img'), function (img) {
            if (!img.complete) { img.addEventListener('load', update, { once: true }); }
        });
        update();
    }

    function initAllGalleries(root) {
        Array.prototype.forEach.call(root.querySelectorAll('.artist-card__works'), initGallery);
    }

    initAllGalleries(list);

    /* ---------- Load more artists ---------- */

    var rangeEl = document.querySelector('[data-range]');
    var loadMore = document.querySelector('[data-load-more]');

    function updateRange(lastShown) {
        if (!rangeEl) { return; }
        var first = parseInt(rangeEl.getAttribute('data-first'), 10) || 1;
        var total = parseInt(rangeEl.getAttribute('data-total'), 10) || 0;
        rangeEl.setAttribute('data-last', String(lastShown));
        rangeEl.textContent = 'Artists ' + first + '\u2013' + lastShown + ' of ' + total.toLocaleString();
    }

    if (loadMore && window.fetch && window.DOMParser) {
        loadMore.addEventListener('click', function (event) {
            if (event.metaKey || event.ctrlKey || event.shiftKey || event.button !== 0) { return; }
            event.preventDefault();
            if (loadMore.getAttribute('aria-busy') === 'true') { return; }

            var url = loadMore.getAttribute('href');
            var originalLabel = loadMore.textContent;
            loadMore.setAttribute('aria-busy', 'true');
            loadMore.textContent = 'Loading\u2026';

            fetch(url, { credentials: 'same-origin', headers: { 'Accept': 'text/html' } })
                .then(function (response) {
                    if (!response.ok) { throw new Error('HTTP ' + response.status); }
                    return response.text();
                })
                .then(function (html) {
                    var doc = new DOMParser().parseFromString(html, 'text/html');
                    var items = doc.querySelectorAll('#artist-list > li');
                    if (!items.length) { throw new Error('No artists in response'); }

                    var firstNew = null;
                    Array.prototype.forEach.call(items, function (item) {
                        var node = document.adoptNode(item);
                        if (!firstNew) { firstNew = node; }
                        list.appendChild(node);
                    });
                    initAllGalleries(list);

                    var newRange = doc.querySelector('[data-range]');
                    if (newRange) { updateRange(parseInt(newRange.getAttribute('data-last'), 10) || 0); }

                    // Point the button at the next page, or remove it after the last one.
                    var newMore = doc.querySelector('[data-load-more]');
                    if (newMore) {
                        loadMore.setAttribute('href', newMore.getAttribute('href'));
                        loadMore.textContent = originalLabel;
                        loadMore.removeAttribute('aria-busy');
                    } else {
                        loadMore.parentNode.removeChild(loadMore);
                    }

                    // Keep keyboard users in the new content.
                    var link = firstNew && firstNew.querySelector('.artist-card__name a');
                    if (link) { link.focus({ preventScroll: true }); }
                    announce(items.length + ' more artists loaded.');
                })
                .catch(function () {
                    // Anything unexpected: use the normal link instead.
                    window.location.href = url;
                });
        });
    }
})();
