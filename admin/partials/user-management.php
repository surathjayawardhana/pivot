<?php
/**
 * Admin: User Management - roles and user removal (section only: no <html>, header or footer).
 *
 * Usage, inside <main> of admin/manage.php:
 *     <?php include __DIR__ . '/partials/user-management.php'; ?>
 *
 * Lists every account, one compact row each, with a role switch and a Suspend / Reactivate button.
 * The buttons post to admin/user_action.php, which does the actual changes inside a
 * transaction together with the audit row. Suspend / Reactivate only flips users.account_status
 * (active <-> suspended); no row is ever deleted from `users`.
 *
 * Reuses: art_db(), art_e()                          (includes/artwork_helpers.php)
 *         profile_viewer()                           (includes/profile_helpers.php)
 *         csrf_token()                               (includes/db.php)
 *         um_rule_violation(), UM_ROLES              (includes/user_management_rules.php)
 *
 * All variables start with $um so they cannot clash with manage.php or other sections.
 * Needs the migration in database/migrations/001_user_management.sql.
 */
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/includes/db.php';
require_once dirname(__DIR__, 2) . '/includes/profile_helpers.php';   // also loads artwork_helpers.php
require_once dirname(__DIR__, 2) . '/includes/user_management_rules.php';

if (!defined('UM_PAGE_SIZE')) {
    define('UM_PAGE_SIZE', 50);
}

// ---- Administrators only (checked against the database) --------------------
try {
    $umDb     = art_db();
    $umViewer = profile_viewer($umDb);
} catch (Throwable $umError) {
    error_log('[user-management] ' . $umError->getMessage());
    return;
}
if (!$umViewer || $umViewer['role'] !== 'admin') {
    return;
}

// ---- Message left by user_action.php (its own key, so the showcase section cannot take it) ----
$umFlash = $_SESSION['um_flash'] ?? null;
unset($_SESSION['um_flash']);
if (!is_array($umFlash) || !is_string($umFlash['message'] ?? null)) {
    $umFlash = null;
}

// ---- Read and whitelist the search / filter / page parameters --------------
$umSearch = isset($_GET['um_q']) && is_string($_GET['um_q']) ? trim($_GET['um_q']) : '';
if ($umSearch !== '' && (mb_strlen($umSearch, 'UTF-8') > 100 || !mb_check_encoding($umSearch, 'UTF-8'))) {
    $umSearch = '';
}

$umRoleFilter = isset($_GET['um_role']) && is_string($_GET['um_role']) ? $_GET['um_role'] : '';
if (!in_array($umRoleFilter, UM_ROLES, true)) {
    $umRoleFilter = '';
}

$umStateFilter = isset($_GET['um_state']) && is_string($_GET['um_state']) ? $_GET['um_state'] : '';
if (!in_array($umStateFilter, ['active', 'suspended', 'removed'], true)) {
    $umStateFilter = '';
}

$umPage = (int) filter_var($_GET['um_page'] ?? 1, FILTER_VALIDATE_INT, [
    'options' => ['min_range' => 1, 'max_range' => 100000, 'default' => 1],
]);

// Page this partial is shown on (for the search form and pager links).
$umSelf = basename((string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH));
if ($umSelf === '' || !preg_match('/^[A-Za-z0-9._-]+$/', $umSelf)) {
    $umSelf = 'manage.php';
}

// ---- Load data --------------------------------------------------------------
$umUsers       = [];
$umTotal       = 0;
$umPages       = 1;
$umCounts      = ['total' => 0, 'active' => 0, 'suspended' => 0, 'removed' => 0, 'admins' => 0];
$umLoadFailed  = false;
$umNeedsMigrate = false;

try {
    // The feature needs users.deleted_at and the two audit/session tables.
    $umSchema = $umDb->one(
        "SELECT
            (SELECT COUNT(*) FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'deleted_at') AS has_deleted_at,
            (SELECT COUNT(*) FROM information_schema.TABLES
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ('activity_logs', 'user_sessions')) AS has_tables"
    );
    $umNeedsMigrate = (int) ($umSchema['has_deleted_at'] ?? 0) < 1 || (int) ($umSchema['has_tables'] ?? 0) < 2;

    if (!$umNeedsMigrate) {
        // Summary numbers (whole table, not just the filtered rows).
        $umRow = $umDb->one(
            "SELECT COUNT(*) AS total,
                    SUM(deleted_at IS NULL AND account_status = 'active')    AS active,
                    SUM(deleted_at IS NULL AND account_status = 'suspended') AS suspended,
                    SUM(deleted_at IS NOT NULL)                              AS removed,
                    SUM(role = 'admin' AND account_status = 'active' AND deleted_at IS NULL) AS admins
               FROM users"
        );
        foreach (array_keys($umCounts) as $umKey) {
            $umCounts[$umKey] = (int) ($umRow[$umKey] ?? 0);
        }

        // Filters: every value is bound as a parameter; state/role come from fixed whitelists.
        $umWhere  = [];
        $umParams = [];

        if ($umSearch !== '') {
            $umLike    = '%' . addcslashes($umSearch, '\\%_') . '%';
            $umOr      = [
                'u.username LIKE ?',
                'u.email LIKE ?',
                'u.first_name LIKE ?',
                'u.surname LIKE ?',
                "CONCAT(u.first_name, ' ', u.surname) LIKE ?",
            ];
            $umParams  = [$umLike, $umLike, $umLike, $umLike, $umLike];
            if (ctype_digit($umSearch) && strlen($umSearch) <= 10) {
                $umOr[]     = 'u.id = ?';
                $umParams[] = (int) $umSearch;
            }
            $umWhere[] = '(' . implode(' OR ', $umOr) . ')';
        }
        if ($umRoleFilter !== '') {
            $umWhere[]  = 'u.role = ?';
            $umParams[] = $umRoleFilter;
        }
        if ($umStateFilter === 'active') {
            $umWhere[] = "u.deleted_at IS NULL AND u.account_status = 'active'";
        } elseif ($umStateFilter === 'suspended') {
            $umWhere[] = "u.deleted_at IS NULL AND u.account_status = 'suspended'";
        } elseif ($umStateFilter === 'removed') {
            $umWhere[] = 'u.deleted_at IS NOT NULL';
        }
        $umWhereSql = $umWhere ? 'WHERE ' . implode(' AND ', $umWhere) : '';

        $umRow   = $umDb->one('SELECT COUNT(*) AS n FROM users u ' . $umWhereSql, $umParams);
        $umTotal = (int) ($umRow['n'] ?? 0);
        $umPages = max(1, (int) ceil($umTotal / UM_PAGE_SIZE));
        $umPage  = min($umPage, $umPages);

        $umUsers = $umDb->all(
            'SELECT u.id, u.username, u.email, u.first_name, u.surname,
                    u.role, u.account_status, u.deleted_at, u.created_at
               FROM users u ' . $umWhereSql . '
              ORDER BY u.created_at DESC, u.id DESC
              LIMIT ? OFFSET ?',
            array_merge($umParams, [UM_PAGE_SIZE, ($umPage - 1) * UM_PAGE_SIZE])
        );
    }
} catch (Throwable $umError) {
    error_log('[user-management] ' . $umError->getMessage());
    $umLoadFailed = true;
}

// ---- Small helpers for this template (closures: safe if the partial is included twice) ----
$umToken = csrf_token();

/** Hidden inputs that bring the admin back to the same search / filter / page after an action. */
$umHidden = static function () use ($umSearch, $umRoleFilter, $umStateFilter, $umPage): string {
    $html = '';
    foreach (['um_q' => $umSearch, 'um_role' => $umRoleFilter, 'um_state' => $umStateFilter] as $name => $value) {
        if ($value !== '') {
            $html .= '<input type="hidden" name="' . $name . '" value="' . art_e($value) . '">';
        }
    }
    if ($umPage > 1) {
        $html .= '<input type="hidden" name="um_page" value="' . $umPage . '">';
    }
    return $html;
};

$umUrl = static function (int $page) use ($umSelf, $umSearch, $umRoleFilter, $umStateFilter): string {
    $query = array_filter(
        ['um_q' => $umSearch, 'um_role' => $umRoleFilter, 'um_state' => $umStateFilter, 'um_page' => $page > 1 ? $page : ''],
        static fn($v): bool => $v !== ''
    );
    return $umSelf . ($query ? '?' . http_build_query($query) : '') . '#user-management';
};

$umDate = static fn(?string $value): string => ($value === null || $value === '') ? 'Not recorded' : substr($value, 0, 10);

$umFilterActive = $umSearch !== '' || $umRoleFilter !== '' || $umStateFilter !== '';
$umFrom         = $umTotal === 0 ? 0 : ($umPage - 1) * UM_PAGE_SIZE + 1;
$umTo           = min($umTotal, $umPage * UM_PAGE_SIZE);
?>
<link rel="stylesheet" href="../css/user-management.css">
<?php if (empty($GLOBALS['ss_assets_loaded'])): $GLOBALS['ss_assets_loaded'] = true; ?>
<link rel="stylesheet" href="../assets/css/search-suggestions.css">
<script src="../js/search-suggestions.js" defer></script>
<?php endif; ?>

<section class="um-admin"  id="user-management" aria-labelledby="um-title">

    <header class="um-admin__header">
        <h2 class="um-admin__title" id="um-title">User Management</h2>
        <p class="um-admin__summary">
            <?= $umCounts['total'] ?> users
            <span aria-hidden="true">·</span> <?= $umCounts['active'] ?> active
            <span aria-hidden="true">·</span> <?= $umCounts['suspended'] ?> suspended
            <span aria-hidden="true">·</span> <?= $umCounts['removed'] ?> removed
            <span aria-hidden="true">·</span> <?= $umCounts['admins'] ?> active admin<?= $umCounts['admins'] === 1 ? '' : 's' ?>
        </p>
    </header>

    <?php if ($umFlash): ?>
        <?php $umFlashOk = ($umFlash['type'] ?? '') === 'success'; ?>
        <p
            class="um-admin__notice um-admin__notice--<?= $umFlashOk ? 'success' : 'error' ?>"
            role="<?= $umFlashOk ? 'status' : 'alert' ?>"
        >
            <strong><?= $umFlashOk ? 'Done.' : 'Not changed.' ?></strong>
            <?= art_e((string) $umFlash['message']) ?>
        </p>
    <?php endif; ?>

    <?php if ($umLoadFailed): ?>
        <p class="um-admin__notice um-admin__notice--error" role="alert">
            The user list could not be loaded. Please refresh the page in a moment.
        </p>
    <?php elseif ($umNeedsMigrate): ?>
        <p class="um-admin__notice um-admin__notice--error" role="alert">
            The database needs an update before users can be managed. Run
            <code>database/migrations/001_user_management.sql</code> once, then reload this page.
        </p>
    <?php else: ?>

        <!-- Search and filters -->
        <form class="um-admin__filters" method="get" action="<?= art_e($umSelf) ?>#user-management" role="search">
            <div class="um-admin__field um-admin__field--grow">
                <label for="um-q">Search</label>
                <input
                    class="um-admin__input"
                    type="search"
                    id="um-q"
                    name="um_q"
                    value="<?= art_e($umSearch) ?>"
                    maxlength="100"
                    placeholder="Username, email, name or ID"
                    autocomplete="off"
                    data-ss-endpoint="search_suggestions.php"
                    data-ss-context="um"
                    data-ss-variant="admin"
                    data-ss-min="2"
                    data-ss-min-numeric="1"
                    data-ss-max="6"
                    data-ss-filters="um_role,um_state"
                    data-ss-reset="um_page"
                >
            </div>
            <div class="um-admin__field">
                <label for="um-role">Role</label>
                <select class="um-admin__input" id="um-role" name="um_role">
                    <option value="">All roles</option>
                    <option value="admin"<?= $umRoleFilter === 'admin' ? ' selected' : '' ?>>Admin</option>
                    <option value="student"<?= $umRoleFilter === 'student' ? ' selected' : '' ?>>Student</option>
                </select>
            </div>
            <div class="um-admin__field">
                <label for="um-state">Account</label>
                <select class="um-admin__input" id="um-state" name="um_state">
                    <option value="">All accounts</option>
                    <option value="active"<?= $umStateFilter === 'active' ? ' selected' : '' ?>>Active</option>
                    <option value="suspended"<?= $umStateFilter === 'suspended' ? ' selected' : '' ?>>Suspended</option>
                    <option value="removed"<?= $umStateFilter === 'removed' ? ' selected' : '' ?>>Removed</option>
                </select>
            </div>
            <div class="um-admin__field um-admin__field--buttons">
                <button type="submit" class="um-admin__button um-admin__button--solid">Apply</button>
                <?php if ($umFilterActive): ?>
                    <a class="um-admin__link" href="<?= art_e($umSelf) ?>#user-management">Reset</a>
                <?php endif; ?>
            </div>
        </form>

        <?php if (!$umUsers): ?>
            <p class="um-admin__empty">
                <?= $umFilterActive ? 'No users match these filters.' : 'There are no users yet.' ?>
            </p>
        <?php else: ?>

            <p class="um-admin__count">Showing <?= $umFrom ?>–<?= $umTo ?> of <?= $umTotal ?></p>

            <div class="um-admin__scroll" role="region" aria-labelledby="um-title" tabindex="0">
                <table class="um-admin__table">
                    <caption class="um-admin__sr">Users, newest registration first</caption>
                    <thead>
                        <tr>
                            <th scope="col">User</th>
                            <th scope="col">Name</th>
                            <th scope="col">Email</th>
                            <th scope="col">Role</th>
                            <th scope="col">Account</th>
                            <th scope="col">Registered</th>
                            <th scope="col">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($umUsers as $umUser): ?>
                            <?php
                            $umId        = (int) $umUser['id'];
                            $umIsSelf    = $umId === $umViewer['id'];
                            $umRemoved   = $umUser['deleted_at'] !== null;
                            $umIsAdmin   = $umUser['role'] === 'admin';
                            $umNewRole   = $umIsAdmin ? 'student' : 'admin';
                            $umFullName  = trim((string) $umUser['first_name'] . ' ' . (string) $umUser['surname']);

                            if ($umRemoved) {
                                $umState = 'removed';
                            } elseif ($umUser['account_status'] === 'suspended') {
                                $umState = 'suspended';
                            } else {
                                $umState = 'active';
                            }

                            // Same rule function the server uses, so a disabled button always has a real reason.
                            $umRoleBlock    = null;
                            $umSuspendBlock = null;
                            if (!$umRemoved) {
                                $umRoleBlock    = um_rule_violation('set_role', $umViewer, $umUser, $umCounts['admins'], $umNewRole);
                                $umSuspendBlock = um_rule_violation('toggle_suspend', $umViewer, $umUser, $umCounts['admins']);
                            }
                            $umReason = $umIsSelf
                                ? 'This is your account. It cannot be changed here.'
                                : ($umRoleBlock ?? $umSuspendBlock);
                            ?>
                            <tr class="um-admin__row um-admin__row--<?= $umState ?>">
                                <td data-label="User">
                                    <span class="um-admin__username"><?= art_e((string) $umUser['username']) ?></span>
                                    <span class="um-admin__id">#<?= $umId ?></span>
                                    <?php if ($umIsSelf): ?><span class="um-admin__you">You</span><?php endif; ?>
                                </td>
                                <td data-label="Name"><?= $umFullName !== '' ? art_e($umFullName) : '<span class="um-admin__muted">—</span>' ?></td>
                                <td data-label="Email" class="um-admin__email"><?= art_e((string) $umUser['email']) ?></td>
                                <td data-label="Role">
                                    <span class="um-admin__badge um-admin__badge--<?= $umIsAdmin ? 'admin' : 'student' ?>">
                                        <?= $umIsAdmin ? 'Admin' : 'Student' ?>
                                    </span>
                                </td>
                                <td data-label="Account">
                                    <span class="um-admin__badge um-admin__badge--<?= $umState ?>">
                                        <span class="um-admin__dot" aria-hidden="true"></span>
                                        <?= art_e(ucfirst($umState)) ?>
                                    </span>
                                    <?php if ($umRemoved): ?>
                                        <span class="um-admin__muted um-admin__when"><?= art_e($umDate((string) $umUser['deleted_at'])) ?></span>
                                    <?php endif; ?>
                                </td>
                                <td data-label="Registered"><time><?= art_e($umDate((string) $umUser['created_at'])) ?></time></td>
                                <td data-label="Actions" class="um-admin__actions">
                                    <?php if ($umRemoved): ?>
                                        <span class="um-admin__muted">No actions</span>
                                    <?php else: ?>
                                        <div class="um-admin__controls">
                                            <!-- Role change -->
                                            <form method="post" action="user_action.php">
                                                <input type="hidden" name="csrf_token" value="<?= art_e($umToken) ?>">
                                                <input type="hidden" name="action" value="set_role">
                                                <input type="hidden" name="user_id" value="<?= $umId ?>">
                                                <input type="hidden" name="new_role" value="<?= $umNewRole ?>">
                                                <?= $umHidden() ?>
                                                <button
                                                    type="submit"
                                                    class="um-admin__button"
                                                    <?= $umRoleBlock !== null ? 'disabled' : '' ?>
                                                    aria-label="<?= art_e('Make ' . $umUser['username'] . ' ' . ($umNewRole === 'admin' ? 'an admin' : 'a student')) ?>"
                                                >
                                                    <?= $umNewRole === 'admin' ? 'Make admin' : 'Make student' ?>
                                                </button>
                                            </form>

                                            <?php if ($umState === 'suspended'): ?>
                                                <!-- Reactivate: suspended -> active -->
                                                <form method="post" action="user_action.php">
                                                    <input type="hidden" name="csrf_token" value="<?= art_e($umToken) ?>">
                                                    <input type="hidden" name="action" value="toggle_suspend">
                                                    <input type="hidden" name="from_status" value="suspended">
                                                    <input type="hidden" name="user_id" value="<?= $umId ?>">
                                                    <?= $umHidden() ?>
                                                    <button
                                                        type="submit"
                                                        class="um-admin__button um-admin__button--solid"
                                                        <?= $umSuspendBlock !== null ? 'disabled' : '' ?>
                                                        aria-label="<?= art_e('Reactivate ' . $umUser['username']) ?>"
                                                    >
                                                        Reactivate
                                                    </button>
                                                </form>
                                            <?php elseif ($umSuspendBlock !== null): ?>
                                                <button type="button" class="um-admin__button um-admin__button--danger" disabled>Suspend</button>
                                            <?php else: ?>
                                                <!-- Suspend: active -> suspended; needs a second click (UI safeguard only; the server re-checks everything) -->
                                                <details class="um-admin__confirm">
                                                    <summary class="um-admin__button um-admin__button--danger">Suspend</summary>
                                                    <div class="um-admin__confirm-box">
                                                        <p>
                                                            Suspend <strong><?= art_e((string) $umUser['username']) ?></strong>?
                                                            They will be signed out and unable to log in until you reactivate them. Their artworks and history are kept.
                                                        </p>
                                                        <form method="post" action="user_action.php">
                                                            <input type="hidden" name="csrf_token" value="<?= art_e($umToken) ?>">
                                                            <input type="hidden" name="action" value="toggle_suspend">
                                                            <input type="hidden" name="from_status" value="active">
                                                            <input type="hidden" name="user_id" value="<?= $umId ?>">
                                                            <?= $umHidden() ?>
                                                            <button type="submit" class="um-admin__button um-admin__button--danger-solid">
                                                                Confirm suspend
                                                            </button>
                                                        </form>
                                                    </div>
                                                </details>
                                            <?php endif; ?>
                                        </div>
                                        <?php if ($umReason !== null): ?>
                                            <p class="um-admin__reason"><?= art_e($umReason) ?></p>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <?php if ($umPages > 1): ?>
                <nav class="um-admin__pager" aria-label="User pages">
                    <?php if ($umPage > 1): ?>
                        <a href="<?= art_e($umUrl($umPage - 1)) ?>">Previous</a>
                    <?php endif; ?>
                    <span>Page <?= $umPage ?> of <?= $umPages ?></span>
                    <?php if ($umPage < $umPages): ?>
                        <a href="<?= art_e($umUrl($umPage + 1)) ?>">Next</a>
                    <?php endif; ?>
                </nav>
            <?php endif; ?>
        <?php endif; ?>

    <?php endif; ?>

</section>

