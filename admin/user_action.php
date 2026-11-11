<?php
/**
 * User-management actions (POST only), used by admin/partials/user-management.php.
 *
 *   action=set_role  user_id=N  new_role=student|admin    change a user's role
 *   action=toggle_suspend  user_id=N  from_status=active|suspended
 *                                                       flip account_status: active -> suspended,
 *                                                       suspended -> active (nothing else is changed)
 *
 * Same pattern as showcase_action.php: check who is asking (in the database), check the
 * CSRF token, validate the input, change the database inside ONE transaction together
 * with its audit row, store a flash message and redirect back to admin/manage.php.
 *
 * If the activity_logs insert fails, the user change is rolled back too.
 */
declare(strict_types=1);

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/profile_helpers.php';        // also loads artwork_helpers.php
require_once __DIR__ . '/../includes/user_management_rules.php';

/** A refusal that is the admin's problem to read (not a technical error). */
final class UmRefused extends RuntimeException
{
}

/** Own flash key: art_flash_*() is shared with the showcase section, which would consume it. */
function um_flash(string $type, string $message): void
{
    $_SESSION['um_flash'] = ['type' => $type, 'message' => $message];
}

/** Page and filters the admin was on, rebuilt from validated values (never a raw redirect target). */
function um_return_url(): string
{
    $q = [];

    $search = isset($_POST['um_q']) && is_string($_POST['um_q']) ? trim($_POST['um_q']) : '';
    if ($search !== '' && mb_strlen($search, 'UTF-8') <= 100 && mb_check_encoding($search, 'UTF-8')) {
        $q['um_q'] = $search;
    }
    $role = post_string('um_role');
    if (in_array($role, UM_ROLES, true)) {
        $q['um_role'] = $role;
    }
    $state = post_string('um_state');
    if (in_array($state, ['active', 'suspended', 'removed'], true)) {
        $q['um_state'] = $state;
    }
    $page = filter_var($_POST['um_page'] ?? 1, FILTER_VALIDATE_INT, [
        'options' => ['min_range' => 1, 'max_range' => 100000, 'default' => 1],
    ]);
    if ((int) $page > 1) {
        $q['um_page'] = (int) $page;
    }

    return 'manage.php' . ($q ? '?' . http_build_query($q) : '') . '#user-management';
}

function um_finish(string $type, string $message): never
{
    um_flash($type, $message);
    header('Location: ' . um_return_url());
    exit;
}

function um_client_ip(): ?string
{
    $ip = $_SERVER['REMOTE_ADDR'] ?? null;
    return is_string($ip) && filter_var($ip, FILTER_VALIDATE_IP) !== false ? $ip : null;
}

function um_user_agent(): ?string
{
    $ua = $_SERVER['HTTP_USER_AGENT'] ?? null;
    if (!is_string($ua) || $ua === '' || !mb_check_encoding($ua, 'UTF-8')) {
        return null;
    }
    return mb_strcut($ua, 0, 512, 'UTF-8');
}

/**
 * Writes one activity_logs row. Called INSIDE the caller's transaction on purpose: if this
 * throws, the caller rolls back the user change as well.
 */
function um_log_activity(
    PDO $pdo,
    array $actor,
    string $action,
    array $target,
    string $description,
    array $old,
    array $new,
    string $now
): void {
    $st = $pdo->prepare(
        'INSERT INTO activity_logs
            (actor_user_id, actor_username_snapshot, actor_role_snapshot, action,
             entity_type, entity_id, entity_label, description,
             old_values, new_values, ip_address, user_agent, created_at)
         VALUES (?, ?, ?, ?, \'user\', ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $st->execute([
        (int) $actor['id'],
        (string) $actor['username'],
        (string) $actor['role'],
        $action,
        (int) $target['id'],
        mb_substr((string) $target['username'], 0, 255, 'UTF-8'),
        mb_substr($description, 0, 500, 'UTF-8'),
        json_encode($old, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
        json_encode($new, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
        um_client_ip(),
        um_user_agent(),
        $now,
    ]);
}

/** Marks every active tracked session of the user as revoked. Returns how many were revoked. */
function um_revoke_sessions(PDO $pdo, int $userId, string $reason, string $now): int
{
    $st = $pdo->prepare(
        "UPDATE user_sessions
            SET status = 'revoked', ended_at = ?, end_reason = ?
          WHERE user_id = ? AND status = 'active'"
    );
    $st->execute([$now, $reason, $userId]);
    return $st->rowCount();
}

// ---- 1. POST only ---------------------------------------------------------
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST');
    header('Location: manage.php');
    exit;
}

// ---- 2. Administrators only (role is read from the database, not the session) ----
try {
    $umDb     = art_db();
    $umViewer = profile_viewer($umDb);   // active, not removed, role from the database
} catch (Throwable $umError) {
    error_log('[user_action] ' . $umError->getMessage());
    http_response_code(500);
    exit('The service is temporarily unavailable.');
}

if (!$umViewer) {
    header('Location: ../login.php');
    exit;
}
if ($umViewer['role'] !== 'admin') {
    http_response_code(403);
    exit('You do not have permission to perform this action.');
}

// ---- 3. CSRF --------------------------------------------------------------
if (!csrf_valid($_POST['csrf_token'] ?? null)) {
    um_finish('error', 'Your session expired. Please refresh the page and try again.');
}

// ---- 4. Validate input (whitelists + positive integers) --------------------
$umAction = post_string('action');
if (!in_array($umAction, UM_ACTIONS, true)) {
    um_finish('error', 'Invalid action.');
}

$umTargetId = filter_var($_POST['user_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if ($umTargetId === false || $umTargetId === null) {
    um_finish('error', 'Invalid user.');
}
$umTargetId = (int) $umTargetId;

$umFromStatus = null;
if ($umAction === 'toggle_suspend') {
    // The status the admin SAW on screen. If someone else changed it meanwhile, nothing is flipped.
    $umFromStatus = post_string('from_status');
    if (!in_array($umFromStatus, ['active', 'suspended'], true)) {
        um_finish('error', 'Invalid request.');
    }
}

$umNewRole = null;
if ($umAction === 'set_role') {
    $umNewRole = post_string('new_role');
    if (!in_array($umNewRole, UM_ROLES, true)) {
        um_finish('error', 'Invalid role.');
    }
}

// ---- 5. Change + audit row, in one transaction -----------------------------
$umPdo = db();   // the same PDO connection that art_db() wraps

try {
    $umPdo->beginTransaction();

    $umNow = (string) $umPdo->query('SELECT NOW()')->fetchColumn();

    // Lock the acting admin and the target together, always in ascending id order.
    $umSt = $umPdo->prepare(
        'SELECT id, username, role, account_status, deleted_at
           FROM users
          WHERE id IN (?, ?)
          ORDER BY id
            FOR UPDATE'
    );
    $umSt->execute([$umViewer['id'], $umTargetId]);
    $umRows = [];
    foreach ($umSt->fetchAll() as $umRow) {
        $umRows[(int) $umRow['id']] = $umRow;
    }

    // Re-check the acting admin INSIDE the transaction: they may have been demoted a moment ago.
    $umActor = $umRows[$umViewer['id']] ?? null;
    if (
        !$umActor
        || $umActor['deleted_at'] !== null
        || $umActor['account_status'] !== 'active'
        || $umActor['role'] !== 'admin'
    ) {
        throw new UmRefused('Your administrator access has changed. Nothing was changed.');
    }

    $umTarget = $umRows[$umTargetId] ?? null;
    if (!$umTarget) {
        throw new UmRefused('That user could not be found.');
    }

    // Lock the active administrators so two admins cannot demote each other at the same moment.
    $umAdminIds = $umPdo->query(
        "SELECT id FROM users
          WHERE role = 'admin' AND account_status = 'active' AND deleted_at IS NULL
          ORDER BY id
            FOR UPDATE"
    )->fetchAll(PDO::FETCH_COLUMN);

    if ($umAction === 'set_role' && $umTarget['deleted_at'] === null && $umTarget['role'] === $umNewRole
        && (int) $umTarget['id'] !== (int) $umActor['id']) {
        // Nothing to change: no UPDATE and no audit row.
        $umPdo->rollBack();
        um_finish('success', $umTarget['username'] . ' is already ' . ($umNewRole === 'admin' ? 'an administrator' : 'a student') . '. Nothing was changed.');
    }

    $umProblem = um_rule_violation($umAction, $umActor, $umTarget, count($umAdminIds), $umNewRole);
    if ($umProblem !== null) {
        throw new UmRefused($umProblem);
    }

    if ($umAction === 'set_role') {
        // users.username is a GENERATED column: it is never written here.
        $umSt = $umPdo->prepare('UPDATE users SET role = ? WHERE id = ? AND deleted_at IS NULL');
        $umSt->execute([$umNewRole, $umTargetId]);
        if ($umSt->rowCount() !== 1) {
            throw new RuntimeException('Role change for user #' . $umTargetId . ' changed ' . $umSt->rowCount() . ' rows.');
        }

        // A demoted administrator must not keep admin access through an old session.
        $umRevoked = $umTarget['role'] === 'admin' && $umNewRole === 'student'
            ? um_revoke_sessions($umPdo, $umTargetId, 'admin_revoked', $umNow)
            : 0;

        $umNewValues = ['role' => $umNewRole, 'account_status' => $umTarget['account_status']];
        if ($umRevoked > 0) {
            $umNewValues['sessions_revoked'] = $umRevoked;
        }
        um_log_activity(
            $umPdo,
            $umActor,
            'user.role_changed',
            $umTarget,
            'Role of ' . $umTarget['username'] . ' changed from ' . $umTarget['role'] . ' to ' . $umNewRole . '.',
            ['role' => $umTarget['role'], 'account_status' => $umTarget['account_status']],
            $umNewValues,
            $umNow
        );

        $umMessage = $umTarget['username'] . ' is now ' . ($umNewRole === 'admin' ? 'an administrator' : 'a student') . '.';
    } else {
        // toggle_suspend: the ONLY change to `users` is account_status (active <-> suspended).
        // deleted_at, role and every other column stay exactly as they are.
        $umOldStatus = (string) $umTarget['account_status'];
        if ($umOldStatus !== $umFromStatus) {
            throw new UmRefused($umTarget['username'] . ' is now ' . $umOldStatus . ' (it changed since this page loaded). Nothing was changed.');
        }
        $umNewStatus = $umOldStatus === 'active' ? 'suspended' : 'active';

        $umSt = $umPdo->prepare(
            'UPDATE users SET account_status = ?
              WHERE id = ? AND deleted_at IS NULL AND account_status = ?'
        );
        $umSt->execute([$umNewStatus, $umTargetId, $umOldStatus]);
        if ($umSt->rowCount() !== 1) {
            throw new RuntimeException('Status change for user #' . $umTargetId . ' changed ' . $umSt->rowCount() . ' rows.');
        }

        $umNewValues = ['role' => $umTarget['role'], 'account_status' => $umNewStatus];
        if ($umNewStatus === 'suspended') {
            // A suspended person must not stay signed in through an old session.
            $umNewValues['sessions_revoked'] = um_revoke_sessions($umPdo, $umTargetId, 'account_suspended', $umNow);
        }

        um_log_activity(
            $umPdo,
            $umActor,
            $umNewStatus === 'suspended' ? 'user.suspended' : 'user.reactivated',
            $umTarget,
            'Account of ' . $umTarget['username'] . ' changed from ' . $umOldStatus . ' to ' . $umNewStatus . '.',
            ['role' => $umTarget['role'], 'account_status' => $umOldStatus],
            $umNewValues,
            $umNow
        );

        $umMessage = $umNewStatus === 'suspended'
            ? $umTarget['username'] . ' was suspended. They are signed out and cannot log in until reactivated.'
            : $umTarget['username'] . ' was reactivated and can log in again.';
    }

    $umPdo->commit();
} catch (UmRefused $umRefusal) {
    if ($umPdo->inTransaction()) {
        $umPdo->rollBack();
    }
    um_finish('error', $umRefusal->getMessage());
} catch (Throwable $umError) {
    // Includes a failed audit insert: the user change above is rolled back with it.
    if ($umPdo->inTransaction()) {
        $umPdo->rollBack();
    }
    error_log('[user_action] ' . $umAction . ': ' . $umError->getMessage());
    um_finish('error', 'The change could not be saved, so nothing was changed. Please try again.');
}

um_finish('success', $umMessage);
