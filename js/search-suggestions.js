/*
 * ArtCraft - search suggestions (shared by the homepage and the three admin searches).
 *
 * Progressive enhancement: the page's own GET search form keeps working without this file.
 * The script only adds a suggestion list under an input; it never submits while the person is
 * typing, and choosing a suggestion just fills the input and submits the SAME GET form.
 *
 * Every search input is configured with data attributes, nothing page-specific lives here:
 *
 *   data-ss-endpoint     required. URL of the JSON endpoint (relative URLs are resolved against
 *                        the page, so a project in a sub-folder works).
 *   data-ss-context      sent as "context" (admin endpoint: am | um | pr). Omit for the public one.
 *   data-ss-variant      "public" (thumbnails) or "admin" (compact tags). Default: admin when a
 *                        context is set, otherwise public.
 *   data-ss-min          minimum characters before searching (default 2).
 *   data-ss-min-numeric  minimum for digits-only text (an ID). Omit to use data-ss-min.
 *   data-ss-max          suggestions to ask for, 1-8 (default 6; the server enforces it too).
 *   data-ss-filters      comma-separated names of fields in the same form whose CURRENT values
 *                        are sent along (e.g. "um_role,um_state"). Read at request time.
 *   data-ss-reset        comma-separated names of fields (e.g. "am_page") left out when a new
 *                        search is submitted, so pagination starts again at page 1.
 *
 * The suggestion list is a <div> appended to <body> and positioned with position:fixed, so no
 * parent container can clip it. All text is inserted with textContent / text nodes - never HTML.
 */
(function () {
    'use strict';

    if (window.ArtCraftSuggest || typeof window.fetch !== 'function') {
        return;
    }

    var DEBOUNCE_MS = 250;
    var LOADING_DELAY_MS = 150;
    var CACHE_MAX = 20;
    var GAP = 4;
    var THUMB_PATTERN = /^uploads\/artworks\/[A-Za-z0-9._%-]+$/;
    var TONES = { ok: 1, bad: 1, neutral: 1 };
    var TYPES = { artwork: 1, category: 1, user: 1 };

    var counter = 0;
    var instances = [];

    /* ---------------------------------------------------------------- text helpers */

    /** Same clean-up the server and the real search forms apply. */
    function normalize(value) {
        return String(value)
            .replace(/[\u0000-\u001f\u007f]+/g, ' ')
            .replace(/\s+/g, ' ')
            .trim();
    }

    function charLength(value) {
        return Array.from(value).length;
    }

    function text(value, max) {
        return typeof value === 'string' ? value.slice(0, max || 300) : '';
    }

    function toInt(value, fallback, min, max) {
        var n = parseInt(value, 10);
        if (!isFinite(n)) {
            return fallback;
        }
        return Math.min(max, Math.max(min, n));
    }

    function splitNames(value) {
        return (value || '').split(',').map(function (s) { return s.trim(); }).filter(Boolean);
    }

    /**
     * Lower-cases and removes accents so "cafe" highlights "Café", like the case- and
     * accent-insensitive database search does. map/ends point back into the original string.
     */
    function fold(str) {
        var out = '';
        var starts = [];
        var ends = [];
        var index = 0;
        Array.from(str).forEach(function (ch) {
            var base = ch.normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase();
            for (var k = 0; k < base.length; k++) {
                out += base.charAt(k);
                starts.push(index);
                ends.push(index + ch.length);
            }
            index += ch.length;
        });
        return { text: out, starts: starts, ends: ends };
    }

    /** Appends `value` to `el`, wrapping every occurrence of `query` in <mark> (no HTML parsing). */
    function appendHighlighted(el, value, query) {
        var needle = fold(query).text;
        if (!needle) {
            el.appendChild(document.createTextNode(value));
            return;
        }
        var hay = fold(value);
        var cursor = 0;
        var from = 0;
        var found;
        while ((found = hay.text.indexOf(needle, from)) !== -1) {
            var start = hay.starts[found];
            var end = hay.ends[found + needle.length - 1];
            if (start >= cursor) {
                if (start > cursor) {
                    el.appendChild(document.createTextNode(value.slice(cursor, start)));
                }
                var mark = document.createElement('mark');
                mark.textContent = value.slice(start, end);
                el.appendChild(mark);
                cursor = end;
            }
            from = found + needle.length;
        }
        if (cursor < value.length) {
            el.appendChild(document.createTextNode(value.slice(cursor)));
        }
    }

    function clear(node) {
        while (node.firstChild) {
            node.removeChild(node.firstChild);
        }
    }

    /* ---------------------------------------------------------------- one search box */

    function Suggest(input) {
        var self = this;
        this.input = input;
        this.form = input.form || null;
        this.id = ++counter;

        this.endpoint = input.getAttribute('data-ss-endpoint');
        this.endpointUrl = new URL(this.endpoint, document.baseURI);
        this.context = input.getAttribute('data-ss-context') || '';
        this.variant = input.getAttribute('data-ss-variant') || (this.context ? 'admin' : 'public');
        this.min = toInt(input.getAttribute('data-ss-min'), 2, 1, 100);
        this.minNumeric = toInt(input.getAttribute('data-ss-min-numeric'), 0, 0, 100);
        this.max = toInt(input.getAttribute('data-ss-max'), 6, 1, 8);
        this.filters = splitNames(input.getAttribute('data-ss-filters'));
        this.reset = splitNames(input.getAttribute('data-ss-reset'));

        this.items = [];
        this.itemsKey = '';
        this.shownQuery = '';
        this.active = -1;
        this.isOpen = false;
        this.state = 'idle';          // idle | loading | results | empty | error
        this.dismissed = false;       // Escape pressed: stay closed until the person types again
        this.seq = 0;
        this.inflightKey = '';
        this.controller = null;
        this.timer = null;
        this.loadingTimer = null;
        this.positionFrame = 0;
        this.cache = new Map();

        // ---- Elements ----
        this.pop = document.createElement('div');
        this.pop.className = 'ss-popover ss-popover--' + (this.variant === 'admin' ? 'admin' : 'public');
        this.pop.id = 'ss-pop-' + this.id;
        this.pop.hidden = true;

        this.list = document.createElement('ul');
        this.list.className = 'ss-list';
        this.list.id = 'ss-list-' + this.id;
        this.list.setAttribute('role', 'listbox');
        this.list.setAttribute('aria-label', 'Search suggestions');

        this.note = document.createElement('p');
        this.note.className = 'ss-note';
        this.note.hidden = true;

        this.pop.appendChild(this.list);
        this.pop.appendChild(this.note);

        this.live = document.createElement('div');
        this.live.className = 'ss-sr';
        this.live.setAttribute('role', 'status');
        this.live.setAttribute('aria-live', 'polite');
        this.live.setAttribute('aria-atomic', 'true');

        document.body.appendChild(this.pop);
        document.body.appendChild(this.live);

        // ---- ARIA on the input ----
        input.setAttribute('autocomplete', 'off');
        input.setAttribute('role', 'combobox');
        input.setAttribute('aria-autocomplete', 'list');
        input.setAttribute('aria-haspopup', 'listbox');
        input.setAttribute('aria-expanded', 'false');
        input.setAttribute('aria-controls', this.list.id);
        if (!input.hasAttribute('enterkeyhint')) {
            input.setAttribute('enterkeyhint', 'search');
        }

        // ---- Events ----
        input.addEventListener('input', function () { self.onInput(); });
        input.addEventListener('keydown', function (e) { self.onKeydown(e); });
        input.addEventListener('focus', function () { self.onFocus(); });
        input.addEventListener('blur', function () { self.close(); });

        // Keep keyboard focus in the input when the list is clicked or tapped.
        this.pop.addEventListener('mousedown', function (e) { e.preventDefault(); });
        this.pop.addEventListener('mousemove', function (e) {
            var i = self.indexFromEvent(e);
            if (i !== -1 && i !== self.active) {
                self.setActive(i, false);
            }
        });
        this.pop.addEventListener('click', function (e) {
            var i = self.indexFromEvent(e);
            if (i !== -1) {
                self.select(i);
            }
        });

        if (this.form) {
            this.form.addEventListener('submit', function () { self.onSubmit(); });
            this.form.addEventListener('change', function (e) { self.onFormChange(e); });
            this.form.addEventListener('reset', function () { self.abortAll(); self.close(); });
        }

        this.onViewportChange = function () {
            if (!self.isOpen || self.positionFrame) {
                return;
            }
            self.positionFrame = window.requestAnimationFrame(function () {
                self.positionFrame = 0;
                self.position();
            });
        };
    }

    /* ---- Query / filter state ---- */

    Suggest.prototype.query = function () {
        return normalize(this.input.value);
    };

    Suggest.prototype.eligible = function (q) {
        if (q === '') {
            return false;
        }
        if (this.minNumeric > 0 && /^\d+$/.test(q)) {
            return charLength(q) >= this.minNumeric;
        }
        return charLength(q) >= this.min;
    };

    /** Current values of the filter fields (read now, so a changed filter is always respected). */
    Suggest.prototype.filterPairs = function () {
        var pairs = [];
        if (!this.form) {
            return pairs;
        }
        this.filters.forEach(function (name) {
            var field = this.form.elements.namedItem(name);
            var value = '';
            if (field && typeof field.length === 'number' && !field.tagName) {
                for (var i = 0; i < field.length; i++) {          // several fields with one name
                    if (typeof field[i].value === 'string' && field[i].value !== '') {
                        value = field[i].value;
                    }
                }
            } else if (field && typeof field.value === 'string') {
                value = field.value;
            }
            if (value !== '') {
                pairs.push([name, value]);
            }
        }, this);
        return pairs;
    };

    Suggest.prototype.keyFor = function (q, pairs) {
        return [this.context, JSON.stringify(pairs), q].join('\u0001');
    };

    /* ---- Typing ---- */

    Suggest.prototype.onInput = function () {
        var q = this.query();
        this.dismissed = false;
        window.clearTimeout(this.timer);
        window.clearTimeout(this.loadingTimer);

        if (!this.eligible(q)) {
            this.abortAll();
            this.showNothing();
            return;
        }

        var pairs = this.filterPairs();
        var key = this.keyFor(q, pairs);

        if (this.cache.has(key)) {
            // Identical query already answered on this page: no request at all.
            this.abortAll();
            this.showResults(this.cache.get(key), q, key);
            return;
        }
        if (key === this.inflightKey) {
            return;                                     // that exact request is already running
        }

        // The old list belongs to an older query: remove it right away.
        this.abortAll();
        this.showNothing();

        var self = this;
        this.timer = window.setTimeout(function () { self.request(q, pairs, key); }, DEBOUNCE_MS);
    };

    Suggest.prototype.request = function (q, pairs, key) {
        var self = this;
        var seq = ++this.seq;
        this.inflightKey = key;

        var url = new URL(this.endpointUrl.href);
        url.searchParams.set('q', q);
        url.searchParams.set('limit', String(this.max));
        if (this.context) {
            url.searchParams.set('context', this.context);
        }
        pairs.forEach(function (p) { url.searchParams.set(p[0], p[1]); });

        var options = {
            method: 'GET',
            credentials: 'same-origin',
            cache: 'no-store',
            headers: { Accept: 'application/json' }
        };
        if (typeof AbortController === 'function') {
            this.controller = new AbortController();
            options.signal = this.controller.signal;
        }

        this.loadingTimer = window.setTimeout(function () {
            if (seq === self.seq && !self.dismissed && document.activeElement === self.input) {
                self.showNote('Searching\u2026', false, 'loading');
            }
        }, LOADING_DELAY_MS);

        window.fetch(url.href, options)
            .then(function (response) {
                if (!response.ok) {
                    var err = new Error('HTTP ' + response.status);
                    err.status = response.status;
                    throw err;
                }
                return response.json();
            })
            .then(function (data) {
                if (seq !== self.seq || self.query() !== q) {
                    return;                             // outdated answer: never shown
                }
                var items = self.clean(data);
                if (self.keyFor(q, self.filterPairs()) !== key) {
                    return;                             // a filter changed meanwhile
                }
                window.clearTimeout(self.loadingTimer);
                self.inflightKey = '';
                self.remember(key, items);
                if (document.activeElement === self.input && !self.dismissed) {
                    self.showResults(items, q, key);
                }
            })
            .catch(function (error) {
                if (error && error.name === 'AbortError') {
                    return;
                }
                if (seq !== self.seq) {
                    return;
                }
                window.clearTimeout(self.loadingTimer);
                self.inflightKey = '';
                if (document.activeElement !== self.input || self.dismissed) {
                    return;
                }
                var expired = error && (error.status === 401 || error.status === 403);
                self.showNote(
                    expired
                        ? 'Your session has expired. Reload the page to sign in again.'
                        : 'Suggestions are unavailable right now. You can still press Enter to search.',
                    true,
                    'error'
                );
            });
    };

    Suggest.prototype.abortAll = function () {
        this.seq++;                                     // any answer still on its way is now outdated
        this.inflightKey = '';
        window.clearTimeout(this.timer);
        window.clearTimeout(this.loadingTimer);
        if (this.controller) {
            try { this.controller.abort(); } catch (e) { /* ignore */ }
            this.controller = null;
        }
    };

    /** Validates the JSON and keeps only the fields the interface uses. */
    Suggest.prototype.clean = function (data) {
        var list = data && Array.isArray(data.suggestions) ? data.suggestions : [];
        var out = [];
        for (var i = 0; i < list.length && out.length < this.max; i++) {
            var raw = list[i];
            if (!raw || typeof raw !== 'object') {
                continue;
            }
            var label = text(raw.label);
            if (label === '') {
                continue;
            }
            var thumb = '';
            if (typeof raw.thumb === 'string' && THUMB_PATTERN.test(raw.thumb)) {
                thumb = new URL(raw.thumb, this.endpointUrl.href).href;
            }
            out.push({
                type: TYPES[raw.type] ? raw.type : 'artwork',
                label: label,
                meta: text(raw.meta),
                value: text(raw.value) || label,
                tag: text(raw.tag, 40),
                tone: TONES[raw.tone] ? raw.tone : 'neutral',
                thumb: thumb
            });
        }
        return out;
    };

    Suggest.prototype.remember = function (key, items) {
        if (this.cache.size >= CACHE_MAX) {
            this.cache.delete(this.cache.keys().next().value);
        }
        this.cache.set(key, items);
    };

    /* ---- Rendering ---- */

    Suggest.prototype.showNothing = function () {
        this.items = [];
        this.itemsKey = '';
        this.active = -1;
        this.state = 'idle';
        clear(this.list);                               // no stale options left in the page
        this.close();
    };

    Suggest.prototype.showNote = function (message, isError, state) {
        this.items = [];
        this.itemsKey = '';
        this.active = -1;
        this.state = state;
        clear(this.list);
        this.list.hidden = true;
        this.note.hidden = false;
        this.note.textContent = message;
        this.note.className = 'ss-note' + (isError ? ' ss-note--error' : '');
        this.input.removeAttribute('aria-activedescendant');
        this.input.setAttribute('aria-expanded', 'false');
        this.openPopover();
        if (state !== 'loading') {
            this.announce(message);
        }
    };

    Suggest.prototype.showResults = function (items, q, key) {
        if (items.length === 0) {
            this.showNote('No suggestions. Press Enter to search for \u201c' + q + '\u201d.', false, 'empty');
            this.itemsKey = key;
            return;
        }

        this.items = items;
        this.itemsKey = key;
        this.shownQuery = q;
        this.active = -1;
        this.state = 'results';

        clear(this.list);
        this.note.hidden = true;
        this.list.hidden = false;

        var self = this;
        items.forEach(function (item, i) {
            self.list.appendChild(self.buildItem(item, i, q));
        });

        this.input.removeAttribute('aria-activedescendant');
        this.input.setAttribute('aria-expanded', 'true');
        this.openPopover();
        this.announce(
            items.length + (items.length === 1 ? ' suggestion' : ' suggestions') +
            ' available. Use the up and down arrow keys to review, Enter to choose.'
        );
    };

    Suggest.prototype.buildItem = function (item, index, q) {
        var li = document.createElement('li');
        li.className = 'ss-item';
        li.id = 'ss-opt-' + this.id + '-' + index;
        li.setAttribute('role', 'option');
        li.setAttribute('aria-selected', 'false');
        li.setAttribute('data-index', String(index));
        li.title = item.meta ? item.label + ' \u2014 ' + item.meta : item.label;

        if (this.variant !== 'admin') {
            if (item.type === 'category') {
                var glyph = document.createElement('span');
                glyph.className = 'ss-thumb ss-thumb--category';
                glyph.setAttribute('aria-hidden', 'true');
                li.appendChild(glyph);
            } else if (item.thumb) {
                var img = document.createElement('img');
                img.className = 'ss-thumb';
                img.alt = '';
                img.width = 36;
                img.height = 36;
                img.loading = 'lazy';
                img.decoding = 'async';
                img.referrerPolicy = 'no-referrer';
                img.addEventListener('error', function () { img.removeAttribute('src'); });
                img.src = item.thumb;
                li.appendChild(img);
            } else {
                var blank = document.createElement('span');
                blank.className = 'ss-thumb';
                blank.setAttribute('aria-hidden', 'true');
                li.appendChild(blank);
            }
        }

        var body = document.createElement('span');
        body.className = 'ss-text';
        var label = document.createElement('span');
        label.className = 'ss-label';
        appendHighlighted(label, item.label, q);
        body.appendChild(label);
        if (item.meta) {
            var meta = document.createElement('span');
            meta.className = 'ss-meta';
            appendHighlighted(meta, item.meta, q);
            body.appendChild(meta);
        }
        li.appendChild(body);

        if (this.variant === 'admin' && item.tag) {
            var tag = document.createElement('span');
            tag.className = 'ss-tag ss-tag--' + item.tone;
            tag.textContent = item.tag;
            li.appendChild(tag);
        }
        return li;
    };

    Suggest.prototype.announce = function (message) {
        var live = this.live;
        live.textContent = '';
        window.setTimeout(function () { live.textContent = message; }, 40);
    };

    /* ---- Opening, closing, positioning ---- */

    Suggest.prototype.openPopover = function () {
        this.pop.hidden = false;
        if (!this.isOpen) {
            this.isOpen = true;
            window.addEventListener('resize', this.onViewportChange);
            window.addEventListener('scroll', this.onViewportChange, true);
            if (window.visualViewport) {
                window.visualViewport.addEventListener('resize', this.onViewportChange);
                window.visualViewport.addEventListener('scroll', this.onViewportChange);
            }
        }
        this.position();
    };

    Suggest.prototype.close = function () {
        window.clearTimeout(this.loadingTimer);
        if (this.isOpen) {
            window.removeEventListener('resize', this.onViewportChange);
            window.removeEventListener('scroll', this.onViewportChange, true);
            if (window.visualViewport) {
                window.visualViewport.removeEventListener('resize', this.onViewportChange);
                window.visualViewport.removeEventListener('scroll', this.onViewportChange);
            }
        }
        this.isOpen = false;
        this.pop.hidden = true;
        this.setActive(-1, false);
        this.input.setAttribute('aria-expanded', 'false');
        this.input.removeAttribute('aria-activedescendant');
    };

    /** Places the list exactly under the input (or above it when there is no room below). */
    Suggest.prototype.position = function () {
        var rect = this.input.getBoundingClientRect();
        if (rect.width === 0 && rect.height === 0) {
            this.close();                               // the input is not visible any more
            return;
        }
        var pop = this.pop;
        var viewport = window.visualViewport;
        var bottomLimit = viewport ? viewport.offsetTop + viewport.height : window.innerHeight;
        var below = bottomLimit - rect.bottom - GAP - 8;
        var above = rect.top - GAP - 8;

        pop.style.width = rect.width + 'px';          // width first: it decides how much text wraps
        pop.style.left = Math.max(0, rect.left) + 'px';
        pop.style.maxHeight = '';
        var natural = Math.min(pop.scrollHeight + 2, 320);
        var placeAbove = below < natural && above > below;
        var room = Math.max(80, placeAbove ? above : below);
        var height = Math.min(natural, room, 320);

        pop.style.maxHeight = Math.min(320, room) + 'px';
        pop.style.top = (placeAbove ? rect.top - GAP - height : rect.bottom + GAP) + 'px';
        pop.classList.toggle('ss-popover--above', placeAbove);
    };

    /* ---- Selection and keyboard ---- */

    Suggest.prototype.indexFromEvent = function (e) {
        var node = e.target && e.target.closest ? e.target.closest('.ss-item') : null;
        if (!node || !this.list.contains(node)) {
            return -1;
        }
        return parseInt(node.getAttribute('data-index'), 10);
    };

    Suggest.prototype.setActive = function (index, scroll) {
        var options = this.list.children;
        if (this.active >= 0 && options[this.active]) {
            options[this.active].classList.remove('ss-item--active');
            options[this.active].setAttribute('aria-selected', 'false');
        }
        this.active = index;
        if (index >= 0 && options[index]) {
            options[index].classList.add('ss-item--active');
            options[index].setAttribute('aria-selected', 'true');
            this.input.setAttribute('aria-activedescendant', options[index].id);
            if (scroll && options[index].scrollIntoView) {
                options[index].scrollIntoView({ block: 'nearest' });
            }
        } else {
            this.input.removeAttribute('aria-activedescendant');
        }
    };

    Suggest.prototype.canReopen = function () {
        return this.state === 'results' && this.items.length > 0 && this.itemsKey === this.keyFor(this.query(), this.filterPairs());
    };

    Suggest.prototype.onFocus = function () {
        if (!this.isOpen && this.canReopen()) {
            this.input.setAttribute('aria-expanded', 'true');
            this.openPopover();
        }
    };

    Suggest.prototype.onKeydown = function (e) {
        if (e.isComposing || e.keyCode === 229) {
            return;                                     // IME composition (Chinese, Japanese, Sinhala...)
        }
        var count = this.items.length;

        if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
            if (!count || (!this.isOpen && !this.canReopen())) {
                return;
            }
            e.preventDefault();
            if (!this.isOpen) {
                this.dismissed = false;
                this.input.setAttribute('aria-expanded', 'true');
                this.openPopover();
            }
            var next;
            if (this.active === -1) {
                next = e.key === 'ArrowDown' ? 0 : count - 1;
            } else {
                next = (this.active + (e.key === 'ArrowDown' ? 1 : -1) + count) % count;
            }
            this.setActive(next, true);
        } else if (e.key === 'Enter') {
            if (this.isOpen && this.active >= 0) {
                e.preventDefault();
                this.select(this.active);
            }                                           // otherwise the normal form submit runs
        } else if (e.key === 'Escape') {
            if (this.isOpen) {
                e.preventDefault();                     // do not let the browser clear the field too
                this.dismissed = true;
                this.close();
            }
        }
        // Tab and everything else keep their normal behaviour (the list closes on blur).
    };

    Suggest.prototype.select = function (index) {
        var item = this.items[index];
        if (!item) {
            return;
        }
        var value = item.value;
        var limit = this.input.maxLength > 0 ? this.input.maxLength : 100;
        if (value.length > limit) {
            value = Array.from(value).slice(0, limit).join('').slice(0, limit);
        }

        this.abortAll();
        this.input.value = value;
        this.close();

        if (this.form) {
            if (typeof this.form.requestSubmit === 'function') {
                this.form.requestSubmit();
            } else {
                this.form.submit();
            }
        }
    };

    /* ---- Form events ---- */

    Suggest.prototype.onSubmit = function () {
        this.abortAll();
        this.close();

        // A new search starts at page 1: leave the page-number fields out of this submit.
        if (!this.form || !this.reset.length) {
            return;
        }
        var disabled = [];
        this.reset.forEach(function (name) {
            Array.prototype.forEach.call(this.form.querySelectorAll('[name]'), function (el) {
                if (el.name === name && !el.disabled) {
                    el.disabled = true;
                    disabled.push(el);
                }
            });
        }, this);
        if (disabled.length) {
            window.setTimeout(function () {
                disabled.forEach(function (el) { el.disabled = false; });
            }, 0);
        }
    };

    /** A filter changed while suggestions were open: they belong to the old filter, so drop them. */
    Suggest.prototype.onFormChange = function (e) {
        var name = e.target && e.target.name;
        if (name && this.filters.indexOf(name) !== -1) {
            this.abortAll();
            this.showNothing();
        }
    };

    /* ---------------------------------------------------------------- setup */

    function init(root) {
        var scope = root && root.querySelectorAll ? root : document;
        Array.prototype.forEach.call(scope.querySelectorAll('input[data-ss-endpoint]'), function (input) {
            if (input.getAttribute('data-ss-ready') === '1') {
                return;
            }
            input.setAttribute('data-ss-ready', '1');
            try {
                instances.push(new Suggest(input));
            } catch (e) {
                input.removeAttribute('data-ss-ready');   // leave the plain search form alone
                if (window.console && console.error) {
                    console.error('[search-suggestions]', e);
                }
            }
        });
    }

    // Click or tap anywhere outside a search box and its list closes that list.
    document.addEventListener('pointerdown', function (e) {
        instances.forEach(function (s) {
            if (s.isOpen && e.target !== s.input && !s.pop.contains(e.target)) {
                s.close();
            }
        });
    }, true);

    // Coming back through the browser's back/forward cache: never show a stale list.
    window.addEventListener('pageshow', function (e) {
        if (e.persisted) {
            instances.forEach(function (s) { s.abortAll(); s.showNothing(); });
        }
    });

    window.ArtCraftSuggest = { init: init };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function () { init(document); });
    } else {
        init(document);
    }
}());
