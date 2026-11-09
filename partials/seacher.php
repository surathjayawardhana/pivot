<?php

/**
 * Search + sort toolbar.
 *
 * Only renders the form. It does not query the database.
 * Expects these variables from index.php:
 *   $search      string  validated search text ('' when empty)
 *   $sort        string  one of the keys in $sortOptions
 *   $sortOptions array   value => label
 *
 * Search box and dropdown live in ONE GET form, so submitting either one
 * always sends both values and neither resets the other.
 */
?>
<section class="pivot-search" aria-label="Search and sort artworks" id="ps">
    <form class="pivot-search__form" method="get" action="index.php" role="search">

        <div class="pivot-search__field">
            <label class="pivot-search__visually-hidden" for="search">Search artworks</label>
            <svg class="pivot-search__icon" viewBox="0 0 24 24" width="18" height="18" aria-hidden="true"
                 fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round">
                <circle cx="11" cy="11" r="7"></circle>
                <path d="M20 20l-3.5-3.5"></path>
            </svg>
            <input
                class="pivot-search__input"
                type="search"
                id="search"
                name="search"
                maxlength="100"
                placeholder="Search by title or description"
                value="<?= art_e($search) ?>"
                autocomplete="off"
                data-ss-endpoint="search_suggestions.php"
                data-ss-variant="public"
                data-ss-min="2"
                data-ss-max="6"
            >
            <button class="pivot-search__button" type="submit">Search</button>
        </div>

        <div class="pivot-search__sort">
            <label class="pivot-search__visually-hidden" for="sort">Sort artworks</label>
            <select class="pivot-search__select" id="sort" name="sort" onchange="this.form.submit()">
                <?php foreach ($sortOptions as $value => $label): ?>
                    <option value="<?= art_e((string) $value) ?>"<?= $sort === $value ? ' selected' : '' ?>>
                        <?= art_e($label) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

    </form>
</section>

<?php /* Live suggestions: an enhancement only. The form above works without JavaScript. */ ?>
<link rel="stylesheet" href="assets/css/search-suggestions.css">
<script src="js/search-suggestions.js" defer></script>
