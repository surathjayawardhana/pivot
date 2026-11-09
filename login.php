<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/db.php';

// Already logged in? Send the user to the correct home page for their role.
if (!empty($_SESSION['user_id'])) {
    $destination = (($_SESSION['role'] ?? 'student') === 'admin')
        ? 'admin/index.php'
        : 'index.php';

    header('Location: ' . $destination);
    exit;
}

// One-time message set by register.php after a successful sign up.
$success = '';
if (!empty($_SESSION['flash_success']) && is_string($_SESSION['flash_success'])) {
    $success = $_SESSION['flash_success'];
}
unset($_SESSION['flash_success']);

// A real bcrypt hash of a throwaway string. It is checked when the email is
// unknown so that "no such account" and "wrong password" take the same time.
const DUMMY_PASSWORD_HASH = '$2y$10$eJthlL27WyuNlTF2NBZy3.tS1W5nhoBhExkyN3exff6txkL2HUs4W';

$old       = ['email' => ''];
$errors    = [];
$formError = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $old['email'] = normalize_email(post_string('email'));
    $password     = post_string('password');

    if (!csrf_valid($_POST['csrf_token'] ?? null)) {
        $formError = 'Your session expired. Please try again.';
    } else {
        $emailError = nsbm_email_error($old['email']);
        if ($emailError !== null) {
            $errors['email'] = $emailError;
        }

        if ($password === '') {
            $errors['password'] = 'Enter your password.';
        } elseif (strlen($password) > PASSWORD_MAX_LENGTH) {
            // Cannot be a real password (registration caps the length); skip the lookup.
            $errors['password'] = 'Password must be ' . PASSWORD_MAX_LENGTH . ' characters or fewer.';
        }

        if (!$errors) {
            try {
                $pdo  = db();
                $stmt = $pdo->prepare(
                    'SELECT id, first_name, password_hash, role, account_status, deleted_at FROM users WHERE email = ? LIMIT 1'
                );
                $stmt->execute([$old['email']]);
                $user = $stmt->fetch();

                $hash  = $user['password_hash'] ?? DUMMY_PASSWORD_HASH;
                // A removed (soft-deleted) account is treated exactly like an unknown email:
                // it gets the same generic error, so the form never reveals that it existed.
                $valid = password_verify($password, $hash) && $user !== false && $user['deleted_at'] === null;

                if ($valid) {
                    // Do not allow suspended accounts to sign in.
                    if (($user['account_status'] ?? 'active') !== 'active') {
                        $formError = 'Your account is suspended. Please contact an administrator.';
                    } else {
                        // Upgrade the stored hash if PHP's default algorithm has changed.
                        if (password_needs_rehash($user['password_hash'], PASSWORD_DEFAULT)) {
                            $update = $pdo->prepare('UPDATE users SET password_hash = ? WHERE id = ?');
                            $update->execute([password_hash($password, PASSWORD_DEFAULT), $user['id']]);
                        }

                        // Regenerate the session ID to prevent session fixation.
                        session_regenerate_id(true);
                        $_SESSION['user_id']    = (int) $user['id'];
                        $_SESSION['first_name'] = $user['first_name'];
                        $_SESSION['role']       = (string) $user['role'];

                        // Redirect admins to the admin dashboard and students to the public home page.
                        $destination = $_SESSION['role'] === 'admin'
                            ? 'admin/index.php'
                            : 'index.php';

                        header('Location: ' . $destination);
                        exit;
                    }
                } else {
                    // Same message for unknown email and wrong password.
                    $formError = 'Incorrect email or password.';
                }
            } catch (PDOException $ex) {
                error_log('Login failed: ' . $ex->getMessage());
                $formError = 'We could not log you in. Please try again later.';
            }
        }
    }
}

function field_error(array $errors, string $name): string
{
    return $errors[$name] ?? '';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Log in | NSBM ArtCraft</title>
    <link rel="icon" type="image/png" href="assets/images/favicon.png">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Roboto:wght@400;500;700&amp;display=swap">
    <link rel="stylesheet" href="css/auth_style.css">
</head>
<body>
<main class="auth">

    <header class="brand">
        <img class="brand-logo" src="assets/images/Nlogo.png" width="200" height="90" alt="NSBM Green University Town">
        <p class="brand-intro">Welcome to</p>
        <h1 class="brand-title">NARTCRAFT</h1>
        <p class="brand-subtitle">NSBM ARTCRAFT PLATFORM</p>
    </header>

    <section class="card" aria-labelledby="form-title">
        <h2 class="card-title" id="form-title">Log in to your account</h2>

        <?php if ($success !== ''): ?>
            <div class="alert alert-success" role="status"><?= e($success) ?></div>
        <?php endif; ?>

        <?php if ($formError !== ''): ?>
            <div class="alert alert-error" role="alert"><?= e($formError) ?></div>
        <?php endif; ?>

        <form id="auth-form" method="post" action="login.php" data-auth-form="login" novalidate>
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">

            <div class="field">
                <label for="email">NSBM Email Address</label>
                <input class="input<?= field_error($errors, 'email') ? ' is-invalid' : '' ?>"
                       type="email" id="email" name="email"
                       value="<?= e($old['email']) ?>"
                       placeholder="name@student.nsbm.ac.lk" autocomplete="username" maxlength="<?= EMAIL_MAX_LENGTH ?>"
                       autocapitalize="none" spellcheck="false"
                       required aria-describedby="email-error"
                       <?= field_error($errors, 'email') ? 'aria-invalid="true"' : '' ?>>
                <p class="field-error" id="email-error"><?= e(field_error($errors, 'email')) ?></p>
            </div>

            <div class="field">
                <label for="password">Password</label>
                <div class="input-wrap">
                    <input class="input has-toggle<?= field_error($errors, 'password') ? ' is-invalid' : '' ?>"
                           type="password" id="password" name="password"
                           placeholder="Your password" autocomplete="current-password"
                           maxlength="<?= PASSWORD_MAX_LENGTH ?>"
                           required aria-describedby="password-error"
                           <?= field_error($errors, 'password') ? 'aria-invalid="true"' : '' ?>>
                    <button class="toggle-password" type="button" aria-controls="password" aria-pressed="false">Show</button>
                </div>
                <p class="field-error" id="password-error"><?= e(field_error($errors, 'password')) ?></p>
            </div>

            <button class="btn" type="submit" data-loading-text="Logging in…">
                <span class="btn-label">Log In</span>
            </button>
        </form>

        <p class="switch-link">New to NSBM ArtCraft? <a href="register.php">Create an account</a></p>
    </section>

</main>
<script src="js/auth.js"></script>
</body>
</html>
