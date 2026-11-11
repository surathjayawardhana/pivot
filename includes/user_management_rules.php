<?php
/**
 * Business rules for the admin user-management screen (NSBM ArtCraft).
 *
 * One pure function, used twice so the screen and the server can never disagree:
 *   - admin/user_action.php  enforces it inside the database transaction
 *   - admin/partials/user-management.php  uses it to disable buttons and show the reason
 *
 * It does no database work. The caller passes rows that were read from `users`.
 */
declare(strict_types=1);

const UM_ROLES   = ['student', 'admin'];
const UM_ACTIONS = ['set_role', 'toggle_suspend'];

/**
 * Returns a safe, human-readable reason when the change is NOT allowed, or null when it is.
 *
 * @param string  $action           'set_role' or 'toggle_suspend' (active -> suspended, suspended -> active)
 * @param array   $actor            the signed-in administrator: ['id' => int]
 * @param array   $target           the account being changed: id, role, account_status, deleted_at
 * @param int     $activeAdminCount users with role 'admin', account_status 'active' and deleted_at NULL
 * @param ?string $newRole          the requested role (only for 'set_role')
 */
function um_rule_violation(string $action, array $actor, array $target, int $activeAdminCount, ?string $newRole = null): ?string
{
    if ($target['deleted_at'] !== null) {
        return 'This account has already been removed.';
    }

    // Changing your own account mid-request could lock you out of the screen you are using.
    if ((int) $actor['id'] === (int) $target['id']) {
        return $action === 'toggle_suspend'
            ? 'You cannot suspend your own account from this screen.'
            : 'You cannot change your own role from this screen.';
    }

    // The site must always keep at least one administrator who can actually sign in.
    $targetIsActiveAdmin = $target['role'] === 'admin' && $target['account_status'] === 'active';
    // toggle_suspend only costs an administrator when the target is currently ACTIVE (it would become
    // suspended). Reactivating a suspended account never reduces the number of active administrators.
    $targetLosesAdmin    = $action === 'toggle_suspend' || ($action === 'set_role' && $newRole !== 'admin');
    if ($targetIsActiveAdmin && $targetLosesAdmin && $activeAdminCount <= 1) {
        return 'At least one active administrator must remain. This is the last one.';
    }

    return null;
}
