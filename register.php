<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/db.php';

// Already logged in? Skip the form.
if (!empty($_SESSION['user_id'])) {
    header('Location: ' . AFTER_LOGIN_URL);
    exit;
}

$old    = ['first_name' => '', 'surname' => '', 'email' => ''];
$errors = [];
$formError = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $old['first_name'] = clean_text(post_string('first_name'));
    $old['surname']    = clean_text(post_string('surname'));
    $old['email']      = normalize_email(post_string('email'));
    $password          = post_string('password');
    $confirm           = post_string('confirm_password');

    if (!csrf_valid($_POST['csrf_token'] ?? null)) {
        $formError = 'Your session expired. Please try again.';
    } else {
        // --- Validate every field on its own --------------------------
        if ($old['first_name'] === '') {
            $errors['first_name'] = 'Enter your first name.';
        } elseif (text_length($old['first_name']) > NAME_MAX_LENGTH) {
            $errors['first_name'] = 'First name must be ' . NAME_MAX_LENGTH . ' characters or fewer.';
        }

        if ($old['surname'] === '') {
            $errors['surname'] = 'Enter your surname.';
        } elseif (text_length($old['surname']) > NAME_MAX_LENGTH) {
            $errors['surname'] = 'Surname must be ' . NAME_MAX_LENGTH . ' characters or fewer.';
        }

        $emailError = nsbm_email_error($old['email']);
        if ($emailError !== null) {
            $errors['email'] = $emailError;
        }

        if ($password === '') {
            $errors['password'] = 'Enter a password.';
        } elseif (strlen($password) < PASSWORD_MIN_LENGTH) {
            $errors['password'] = 'Password must be at least ' . PASSWORD_MIN_LENGTH . ' characters.';
        } elseif (strlen($password) > PASSWORD_MAX_LENGTH) {
            $errors['password'] = 'Password must be ' . PASSWORD_MAX_LENGTH . ' characters or fewer.';
        }

        if ($confirm === '') {
            $errors['confirm_password'] = 'Confirm your password.';
        } elseif ($password !== $confirm) {
            $errors['confirm_password'] = 'Passwords do not match.';
        }

        // --- Save the account -----------------------------------------
        if (!$errors) {
            try {
                $pdo = db();

                $check = $pdo->prepare('SELECT id FROM users WHERE email = ? LIMIT 1');
                $check->execute([$old['email']]);

                if ($check->fetch()) {
                    $errors['email'] = 'An account with this email already exists. Log in instead.';
                } else {
                    $insert = $pdo->prepare(
                        'INSERT INTO users (first_name, surname, email, password_hash)
                         VALUES (?, ?, ?, ?)'
                    );
                    $insert->execute([
                        $old['first_name'],
                        $old['surname'],
                        $old['email'],
                        password_hash($password, PASSWORD_DEFAULT),
                    ]);

                    // Show a success message on the login page.
                    session_regenerate_id(true);
                    $_SESSION['flash_success'] = 'Account created. Log in with your NSBM email.';
                    header('Location: login.php');
                    exit;
                }
            } catch (PDOException $ex) {
                if ($ex->getCode() === '23000') {
                    // Unique constraint fired: someone registered this email a moment ago.
                    $errors['email'] = 'An account with this email already exists. Log in instead.';
                } else {
                    error_log('Registration failed: ' . $ex->getMessage());
                    $formError = 'We could not create your account. Please try again later.';
                }
            }
        }
    }
}

/** Small helper so the markup below stays readable. */
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
    <title>Sign up | NSBM ArtCraft</title>
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
        <h2 class="card-title" id="form-title">Create your account</h2>

        <?php if ($formError !== ''): ?>
            <div class="alert alert-error" role="alert"><?= e($formError) ?></div>
        <?php endif; ?>

        <form id="auth-form" method="post" action="register.php" data-auth-form="register" novalidate>
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">

            <div class="field-row">
                <div class="field">
                    <label for="first_name">First Name</label>
                    <input class="input<?= field_error($errors, 'first_name') ? ' is-invalid' : '' ?>"
                           type="text" id="first_name" name="first_name"
                           value="<?= e($old['first_name']) ?>"
                           placeholder="Jane" autocomplete="given-name" maxlength="<?= NAME_MAX_LENGTH ?>"
                           required aria-describedby="first_name-error"
                           <?= field_error($errors, 'first_name') ? 'aria-invalid="true"' : '' ?>>
                    <p class="field-error" id="first_name-error"><?= e(field_error($errors, 'first_name')) ?></p>
                </div>

                <div class="field">
                    <label for="surname">Surname</label>
                    <input class="input<?= field_error($errors, 'surname') ? ' is-invalid' : '' ?>"
                           type="text" id="surname" name="surname"
                           value="<?= e($old['surname']) ?>"
                           placeholder="Perera" autocomplete="family-name" maxlength="<?= NAME_MAX_LENGTH ?>"
                           required aria-describedby="surname-error"
                           <?= field_error($errors, 'surname') ? 'aria-invalid="true"' : '' ?>>
                    <p class="field-error" id="surname-error"><?= e(field_error($errors, 'surname')) ?></p>
                </div>
            </div>

            <div class="field">
                <label for="email">NSBM Email Address</label>
                <input class="input<?= field_error($errors, 'email') ? ' is-invalid' : '' ?>"
                       type="email" id="email" name="email"
                       value="<?= e($old['email']) ?>"
                       placeholder="name@student.nsbm.ac.lk" autocomplete="email" maxlength="<?= EMAIL_MAX_LENGTH ?>"
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
                           placeholder="At least <?= PASSWORD_MIN_LENGTH ?> characters" autocomplete="new-password"
                           maxlength="<?= PASSWORD_MAX_LENGTH ?>"
                           required aria-describedby="password-error"
                           <?= field_error($errors, 'password') ? 'aria-invalid="true"' : '' ?>>
                    <button class="toggle-password" type="button" aria-controls="password" aria-pressed="false">Show</button>
                </div>
                <p class="field-error" id="password-error"><?= e(field_error($errors, 'password')) ?></p>
            </div>

            <div class="field">
                <label for="confirm_password">Confirm Password</label>
                <div class="input-wrap">
                    <input class="input has-toggle<?= field_error($errors, 'confirm_password') ? ' is-invalid' : '' ?>"
                           type="password" id="confirm_password" name="confirm_password"
                           placeholder="Re-enter your password" autocomplete="new-password"
                           maxlength="<?= PASSWORD_MAX_LENGTH ?>"
                           required aria-describedby="confirm_password-error"
                           <?= field_error($errors, 'confirm_password') ? 'aria-invalid="true"' : '' ?>>
                    <button class="toggle-password" type="button" aria-controls="confirm_password" aria-pressed="false">Show</button>
                </div>
                <p class="field-error" id="confirm_password-error"><?= e(field_error($errors, 'confirm_password')) ?></p>
            </div>

            <button class="btn" type="submit" data-loading-text="Creating account…">
                <span class="btn-label">Sign Up</span>
            </button>
        </form>

        <p class="switch-link">Already have an account? <a href="login.php">Log in</a></p>
    </section>

</main>
<script src="js/auth.js"></script>
</body>
</html>
