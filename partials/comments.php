<?php
/**
 * Discussion section for one artwork (comments + one level of replies).
 *
 * Included by artwork.php, below the artwork card, for approved artwork only:
 *     <?php include __DIR__ . '/partials/comments.php'; ?>
 *
 * Expects from artwork.php:  $art (artwork row with 'id' and 'status'),
 * and optionally $db (ArtDb) and $viewer (profile_viewer() result or null).
 * The artwork id is never hardcoded.
 *
 * Reply / edit / delete are links that work without JavaScript (the page
 * reloads with that panel open); js/comments.js upgrades them to open in place.
 * Everything is a normal POST form to comment_action.php.
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/comment_helpers.php';

$cmArtworkId = (int) ($art['id'] ?? 0);

if ($cmArtworkId < 1 || ($art['status'] ?? '') !== 'approved') {
    return;   // discussions exist for approved artwork only
}

$cmData   = null;
$cmFailed = false;
$cmFlash  = null;

try {
    $cmDb     = ($db ?? null) instanceof ArtDb ? $db : art_db();
    $cmViewer = comment_viewer($cmDb);                   // may write comments (active, not deleted)
    $cmIsAdmin = comment_is_admin($cmViewer);
    $cmFlash  = comment_flash_pull($cmArtworkId);

    // ---- Query string, validated: page, expanded thread, open panel ----
    $cmPage   = comment_request_int($_GET, 'comments_page') ?? 1;
    $cmThread = comment_request_int($_GET, 'thread');
    $cmPanelKind = is_string($_GET['comment_panel'] ?? null) && in_array($_GET['comment_panel'], ['reply', 'edit', 'delete'], true)
        ? $_GET['comment_panel'] : null;
    $cmPanelId   = comment_request_int($_GET, 'comment_id');

    $cmData = comment_load_discussion($cmDb, $cmArtworkId, $cmPage, $cmThread);
} catch (Throwable $cmError) {
    error_log('[comments] ' . $cmError->getMessage());
    $cmFailed = true;
}

if ($cmFailed || $cmData === null): ?>
    <section class="profile-card comments" id="comments" aria-labelledby="comments-title">
        <h2 class="comments__title" id="comments-title">Comments</h2>
        <p class="comments__empty">Comments are unavailable right now. Please try again later.</p>
    </section>
<?php
    return;
endif;

$cmPage   = $cmData['page'];
$cmPages  = $cmData['pages'];
$cmTotal  = $cmData['total'];
$cmToken  = art_csrf_token();
$cmMax    = (int) COMMENT_MAX_LENGTH;
$cmLoggedInButBlocked = $cmViewer === null && !empty($_SESSION['user_id']);

// A failed submit comes back with the typed text and the right form open.
$cmDraft = is_array($cmFlash['draft'] ?? null) ? $cmFlash['draft'] : null;

/** Draft text for a form, or the default (the comment's own text when editing). */
$cmDraftFor = static function (string $mode, ?int $id, string $default = '') use ($cmDraft): string {
    if ($cmDraft && ($cmDraft['mode'] ?? '') === $mode && ($cmDraft['comment_id'] ?? null) === $id) {
        return str_replace(["\r\n", "\r"], "\n", (string) $cmDraft['body']);
    }
    return $default;
};

/** True when this panel should render open (server-side, for no-JavaScript use and failed submits). */
$cmPanelOpen = static function (string $kind, int $id) use ($cmPanelKind, $cmPanelId, $cmDraft): bool {
    if ($cmPanelKind === $kind && $cmPanelId === $id) {
        return true;
    }
    return $cmDraft && ($cmDraft['mode'] ?? '') === $kind && ($cmDraft['comment_id'] ?? null) === $id;
};

/** Error message to show inside a form when the flash belongs to it. */
$cmErrorFor = static function (string $mode, ?int $id) use ($cmFlash, $cmDraft): string {
    if ($cmFlash && ($cmFlash['type'] ?? '') === 'error' && $cmDraft
        && ($cmDraft['mode'] ?? '') === $mode && ($cmDraft['comment_id'] ?? null) === $id) {
        return (string) $cmFlash['message'];
    }
    return '';
};

/** Avatar markup: the member's picture, or a neutral circle with their initial. */
$cmAvatar = static function (array $author, string $size): string {
    if ($author['avatar'] !== null) {
        return '<img class="comment__avatar comment__avatar--' . $size . '" src="' . art_e($author['avatar'])
             . '" alt="" loading="lazy">';
    }
    return '<span class="comment__avatar comment__avatar--' . $size . ' comment__avatar--initial" aria-hidden="true">'
         . art_e($author['initial']) . '</span>';
};

/** Opening markup of one comment form: method, CSRF, ids. Hidden fields only carry ids, never a user. */
$cmFormOpen = static function (string $mode, ?int $commentId, string $domId, bool $open, string $class)
    use ($cmArtworkId, $cmToken, $cmPage): string {
    return '<form class="comment-form ' . $class . '" id="' . art_e($domId) . '" method="post" action="comment_action.php"'
         . ' data-comment-form' . ($open ? '' : ' hidden') . '>'
         . '<input type="hidden" name="csrf_token" value="' . art_e($cmToken) . '">'
         . '<input type="hidden" name="artwork_id" value="' . $cmArtworkId . '">'
         . '<input type="hidden" name="action" value="' . art_e($mode) . '">'
         . ($commentId !== null ? '<input type="hidden" name="comment_id" value="' . $commentId . '">' : '')
         . '<input type="hidden" name="comments_page" value="' . $cmPage . '">';
};

/** Textarea + counter + buttons shared by the new / reply / edit forms. */
$cmFormFields = static function (string $domId, string $text, string $label, string $placeholder, string $submit,
                                 string $error, string $cancelUrl, bool $showCancel) use ($cmMax): string {
    ob_start(); ?>
        <label class="comment-form__label" for="<?= art_e($domId) ?>-body"><?= art_e($label) ?></label>
        <?php if ($error !== ''): ?>
            <p class="comment-form__error" role="alert"><?= art_e($error) ?></p>
        <?php endif; ?>
        <textarea
            class="comment-form__input"
            id="<?= art_e($domId) ?>-body"
            name="body"
            rows="3"
            maxlength="<?= $cmMax ?>"
            required
            placeholder="<?= art_e($placeholder) ?>"
            aria-describedby="<?= art_e($domId) ?>-hint"
            data-comment-input
            data-max="<?= $cmMax ?>"
        ><?= art_e($text) ?></textarea>
        <div class="comment-form__row">
            <span class="comment-form__hint" id="<?= art_e($domId) ?>-hint">
                <span data-comment-counter><?= mb_strlen($text, 'UTF-8') ?></span> / <?= number_format($cmMax) ?> characters
            </span>
            <span class="comment-form__buttons">
                <?php if ($showCancel): ?>
                    <a class="comment-btn comment-btn--ghost" href="<?= art_e($cancelUrl) ?>" data-comment-cancel>Cancel</a>
                <?php endif; ?>
                <button class="comment-btn comment-btn--primary" type="submit" data-comment-submit
                        data-busy-label="Saving…"><?= art_e($submit) ?></button>
            </span>
        </div>
    <?php
    return (string) ob_get_clean();
};

/** One comment or reply. $isReply is true for the indented replies. */
$cmRender = static function (array $row, bool $isReply, array $replies = [], int $replyTotal = 0)
    use (&$cmRender, $cmViewer, $cmIsAdmin, $cmArtworkId, $cmPage, $cmThread, $cmToken, $cmAvatar, $cmFormOpen,
         $cmFormFields, $cmDraftFor, $cmPanelOpen, $cmErrorFor, $cmMax): void {

    $id        = (int) $row['id'];
    $deleted   = $row['deleted_at'] !== null;
    $author    = comment_author($row);
    [$iso, $label] = comment_time($row['created_at']);
    $edited    = !$deleted && $row['updated_at'] !== null;
    [$editIso, $editLabel] = comment_time($row['updated_at']);
    $parentId  = $isReply ? (int) $row['parent_comment_id'] : null;

    $canReply  = !$isReply && comment_can_reply($row, $cmViewer);
    $canEdit   = comment_can_edit($row, $cmViewer);
    $canDelete = comment_can_delete($row, $cmViewer);

    // Fallback links (no JavaScript): same page with the panel open.
    $panelUrl = static fn (string $kind, string $anchor): string => comment_url($cmArtworkId, [
        'page' => $cmPage, 'thread' => $parentId ?? $cmThread, 'panel' => $kind, 'panel_id' => $id, 'anchor' => $anchor,
    ]);
    $closeUrl = comment_url($cmArtworkId, ['page' => $cmPage, 'thread' => $parentId ?? $cmThread, 'anchor' => 'comment-' . $id]);

    $replyOpen  = $canReply  && $cmPanelOpen('reply', $id);
    $editOpen   = $canEdit   && $cmPanelOpen('edit', $id);
    $deleteOpen = $canDelete && $cmPanelOpen('delete', $id);
    ?>
    <li class="comment<?= $isReply ? ' comment--reply' : '' ?><?= $deleted ? ' comment--deleted' : '' ?>"
        id="comment-<?= $id ?>" data-comment>
        <div class="comment__main">
            <?php if ($author['profile'] !== null && !$deleted): ?>
                <a class="comment__avatar-link" href="<?= art_e($author['profile']) ?>" tabindex="-1" aria-hidden="true">
                    <?= $cmAvatar($author, $isReply ? 'small' : 'normal') ?>
                </a>
            <?php else: ?>
                <span class="comment__avatar-link"><?= $cmAvatar($deleted ? ['avatar' => null, 'initial' => '–'] : $author, $isReply ? 'small' : 'normal') ?></span>
            <?php endif; ?>

            <div class="comment__content">
                <?php if ($deleted): ?>
                    <p class="comment__placeholder">
                        <?= !empty($row['removed_by_other']) ? 'This comment was removed by a moderator.' : 'This comment was deleted.' ?>
                    </p>
                <?php else: ?>
                    <div class="comment__head">
                        <?php if ($author['profile'] !== null): ?>
                            <a class="comment__author" href="<?= art_e($author['profile']) ?>"><?= art_e($author['name']) ?></a>
                            <span class="comment__username">@<?= art_e((string) $author['username']) ?></span>
                        <?php else: ?>
                            <span class="comment__author comment__author--former"><?= art_e($author['name']) ?></span>
                        <?php endif; ?>
                        <?php if ($iso !== ''): ?>
                            <time class="comment__time" datetime="<?= art_e($iso) ?>"><?= art_e($label) ?></time>
                        <?php endif; ?>
                        <?php if ($edited): ?>
                            <span class="comment__edited"<?= $editLabel !== '' ? ' title="Edited ' . art_e($editLabel) . '"' : '' ?>>(edited)</span>
                        <?php endif; ?>
                    </div>
                    <div class="comment__body"><?= comment_body_html((string) $row['body']) ?></div>

                    <?php if ($canReply || $canEdit || $canDelete): ?>
                        <div class="comment__actions">
                            <?php if ($canReply): ?>
                                <a class="comment__action" href="<?= art_e($panelUrl('reply', 'reply-form-' . $id)) ?>"
                                   data-panel-toggle="reply" aria-controls="reply-form-<?= $id ?>"
                                   aria-expanded="<?= $replyOpen ? 'true' : 'false' ?>">Reply</a>
                            <?php endif; ?>
                            <?php if ($canEdit): ?>
                                <a class="comment__action" href="<?= art_e($panelUrl('edit', 'edit-form-' . $id)) ?>"
                                   data-panel-toggle="edit" aria-controls="edit-form-<?= $id ?>"
                                   aria-expanded="<?= $editOpen ? 'true' : 'false' ?>">Edit</a>
                            <?php endif; ?>
                            <?php if ($canDelete): ?>
                                <a class="comment__action comment__action--danger" href="<?= art_e($panelUrl('delete', 'delete-form-' . $id)) ?>"
                                   data-panel-toggle="delete" aria-controls="delete-form-<?= $id ?>"
                                   aria-expanded="<?= $deleteOpen ? 'true' : 'false' ?>"><?= $cmIsAdmin && !comment_is_owner($row, $cmViewer) ? 'Remove' : 'Delete' ?></a>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>

                    <?php if ($canReply): ?>
                        <?= $cmFormOpen('reply', $id, 'reply-form-' . $id, $replyOpen, 'comment-form--reply') ?>
                            <?= $cmFormFields(
                                'reply-form-' . $id,
                                $cmDraftFor('reply', $id),
                                'Reply to ' . $author['name'],
                                'Write a reply…',
                                'Post reply',
                                $cmErrorFor('reply', $id),
                                $closeUrl,
                                true
                            ) ?>
                        </form>
                    <?php endif; ?>

                    <?php if ($canEdit): ?>
                        <?= $cmFormOpen('edit', $id, 'edit-form-' . $id, $editOpen, 'comment-form--edit') ?>
                            <?= $cmFormFields(
                                'edit-form-' . $id,
                                $cmDraftFor('edit', $id, (string) $row['body']),
                                $isReply ? 'Edit your reply' : 'Edit your comment',
                                'Write something…',
                                'Save changes',
                                $cmErrorFor('edit', $id),
                                $closeUrl,
                                true
                            ) ?>
                        </form>
                    <?php endif; ?>

                    <?php if ($canDelete): ?>
                        <?= $cmFormOpen('delete', $id, 'delete-form-' . $id, $deleteOpen, 'comment-form--delete') ?>
                            <p class="comment-form__confirm" id="delete-form-<?= $id ?>-text">
                                <?= $isReply ? 'Delete this reply?' : 'Delete this comment?' ?>
                                <?= $isReply ? '' : 'Any replies will stay.' ?> This can’t be undone.
                            </p>
                            <span class="comment-form__buttons">
                                <a class="comment-btn comment-btn--ghost" href="<?= art_e($closeUrl) ?>" data-comment-cancel>Cancel</a>
                                <button class="comment-btn comment-btn--danger" type="submit" data-comment-submit
                                        data-busy-label="Deleting…" aria-describedby="delete-form-<?= $id ?>-text">Yes, delete</button>
                            </span>
                        </form>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>

        <?php if (!$isReply && $replies): ?>
            <ol class="comment__replies" aria-label="Replies">
                <?php foreach ($replies as $reply): ?>
                    <?php $cmRender($reply, true); ?>
                <?php endforeach; ?>
            </ol>
            <?php if ($replyTotal > count($replies)): ?>
                <p class="comment__more">
                    <a href="<?= art_e(comment_url($cmArtworkId, ['page' => $cmPage, 'thread' => $id, 'anchor' => 'comment-' . $id])) ?>">
                        Show all <?= $replyTotal ?> replies
                    </a>
                </p>
            <?php endif; ?>
        <?php endif; ?>
    </li>
    <?php
};
?>
<section class="profile-card comments" id="comments" aria-labelledby="comments-title">

    <header class="comments__header">
        <h2 class="comments__title" id="comments-title">
            Comments <span class="comments__count" aria-label="<?= $cmTotal ?> <?= $cmTotal === 1 ? 'comment' : 'comments' ?>"><?= $cmTotal ?></span>
        </h2>
    </header>

    <?php if ($cmFlash && $cmFlash['type'] !== 'error'): ?>
        <p class="comments__notice comments__notice--<?= art_e((string) $cmFlash['type']) ?>" role="status"><?= art_e((string) $cmFlash['message']) ?></p>
    <?php elseif ($cmFlash && !$cmDraft): ?>
        <p class="comments__notice comments__notice--error" role="alert"><?= art_e((string) $cmFlash['message']) ?></p>
    <?php endif; ?>

    <?php if ($cmViewer): ?>
        <?php
        $cmMainError = $cmErrorFor('create', null);
        echo $cmFormOpen('create', null, 'comment-form-main', true, 'comment-form--main');
        echo $cmFormFields(
            'comment-form-main',
            $cmDraftFor('create', null),
            'Add a comment',
            'Share your thoughts on this artwork…',
            'Post comment',
            $cmMainError,
            '',
            false
        );
        ?>
        </form>
    <?php elseif ($cmLoggedInButBlocked): ?>
        <p class="comments__notice comments__notice--info">Commenting isn’t available for your account.</p>
    <?php else: ?>
        <p class="comments__notice comments__notice--info"><a href="login.php">Log in</a> to join the discussion.</p>
    <?php endif; ?>

    <?php if ($cmData['comments'] === []): ?>
        <p class="comments__empty">Nothing here yet. Start the conversation.</p>
    <?php else: ?>
        <ol class="comments__list">
            <?php foreach ($cmData['comments'] as $cmComment): ?>
                <?php $cmRender($cmComment, false, $cmComment['replies'], (int) $cmComment['reply_total']); ?>
            <?php endforeach; ?>
        </ol>
    <?php endif; ?>

    <?php if ($cmPages > 1): ?>
        <nav class="comments__pager" aria-label="Comment pages">
            <?php if ($cmPage > 1): ?>
                <a class="comment-btn comment-btn--ghost" rel="prev"
                   href="<?= art_e(comment_url($cmArtworkId, ['page' => $cmPage - 1])) ?>">Newer</a>
            <?php endif; ?>
            <span class="comments__pager-text">Page <?= $cmPage ?> of <?= $cmPages ?></span>
            <?php if ($cmPage < $cmPages): ?>
                <a class="comment-btn comment-btn--ghost" rel="next"
                   href="<?= art_e(comment_url($cmArtworkId, ['page' => $cmPage + 1])) ?>">Older</a>
            <?php endif; ?>
        </nav>
    <?php endif; ?>

</section>
