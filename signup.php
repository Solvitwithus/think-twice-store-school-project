<?php
require __DIR__ . '/config/db.php';

$error = '';

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    $fullname        = trim($_POST['name'] ?? '');
    $username        = strtolower(trim($_POST['Username'] ?? ''));
    $password        = $_POST['password'] ?? '';
    $email           = trim($_POST['email'] ?? '');
    $confirmPassword = $_POST['confirmPassword'] ?? '';

    try {

        // ── Validation ─────────────────────────────────────────────
        if (!$fullname || !$username || !$password || !$email || !$confirmPassword) {
            $error = 'All fields are required.';
        }
        elseif (strlen($fullname) < 3) {
            $error = 'Full name must be at least 3 characters.';
        }
        elseif (strlen($username) < 3) {
            $error = 'Username must be at least 3 characters.';
        }
        elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = 'Please enter a valid email address.';
        }
        elseif (strlen($password) < 8) {
            $error = 'Password must be at least 8 characters.';
        }
        elseif (
            !preg_match(
                '/^(?=.*[a-z])(?=.*[A-Z])(?=.*\d)(?=.*[!@#$%^&*()_+\-=\[\]{};:"\\\\|,.<>\/?]).{8,}$/',
                $password
            )
        ) {
            $error = 'Password must contain uppercase, lowercase, number and special character.';
        }
        elseif ($password !== $confirmPassword) {
            $error = 'Passwords do not match.';
        }

        // ── Duplicate check ───────────────────────────────────────
        if (!$error) {

            $check = $conn->prepare("
                SELECT id
                FROM users
                WHERE email = :email
                   OR username = :username
                LIMIT 1
            ");

            $check->execute([
                'email'    => $email,
                'username' => $username
            ]);

            if ($check->fetch()) {
                $error = 'Username or email already exists.';
            }
        }

        // ── Create user ───────────────────────────────────────────
        if (!$error) {

            $roleStmt = $conn->prepare("
                SELECT id
                FROM roles
                WHERE name = 'admin'
                LIMIT 1
            ");

            $roleStmt->execute();

            $defaultRole = $roleStmt->fetch(PDO::FETCH_ASSOC);
            $defaultRoleId = $defaultRole['id'] ?? null;

            $query = $conn->prepare("
                INSERT INTO users
                (
                    name,
                    username,
                    password,
                    email,
                    role_id
                )
                VALUES
                (
                    :fullname,
                    :username,
                    :password,
                    :email,
                    :role_id
                )
            ");

            $query->execute([
                'fullname' => $fullname,
                'username' => $username,
                'password' => password_hash($password, PASSWORD_DEFAULT),
                'email'    => $email,
                'role_id'  => $defaultRoleId,
            ]);

            header("Location: /think-twice");
            exit;
        }

    } catch (PDOException $e) {
        $error = 'Database error: ' . $e->getMessage();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sign Up - Think Twice</title>
    <link rel="stylesheet" href="/think-twice/public/theme.css">
    <style>
        body {
            display: flex;
            justify-content: center;
            align-items: center;
            height: 100vh;
            margin: 0;
        }
        .auth-container {
            width: 100%;
            max-width: 400px;
            padding: 40px;
        }
        .auth-box {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: var(--radius-xl);
            padding: var(--space-xl);
            box-shadow: var(--shadow-lg);
        }
        .auth-header {
            text-align: center;
            margin-bottom: var(--space-xl);
        }
        .auth-brand {
            font-size: 28px;
            font-weight: 700;
            color: var(--primary);
            margin-bottom: 8px;
        }
        .auth-title {
            font-size: 20px;
            font-weight: 700;
            color: var(--text);
            margin-bottom: 4px;
        }
        .auth-subtitle {
            font-size: 13px;
            color: var(--text-muted);
        }
        .auth-footer {
            text-align: center;
            margin-top: var(--space-lg);
            font-size: 13px;
        }
        .auth-footer a {
            color: var(--primary);
            text-decoration: none;
            font-weight: 600;
        }
        .auth-footer a:hover {
            text-decoration: underline;
        }
    </style>
</head>
<body>

<div class="auth-container">
  <div class="auth-box">
    <div class="auth-header">
      <div class="auth-brand">◊</div>
      <div class="auth-title">Create Account</div>
      <div class="auth-subtitle">Join Think Twice</div>
    </div>

    <?php if ($error): ?>
      <div class="alert alert-danger">
        <?= htmlspecialchars($error) ?>
      </div>
    <?php endif; ?>

    <form id="signupForm" method="POST">

        <div class="form-group">
            <label for="name">Full Name</label>
            <input
                type="text"
                id="name"
                name="name"
                value="<?= htmlspecialchars($_POST['name'] ?? '') ?>"
                required
            >
        </div>

        <div class="form-group">
            <label for="username">Username</label>
            <input
                type="text"
                id="username"
                name="Username"
                value="<?= htmlspecialchars($_POST['Username'] ?? '') ?>"
                required
            >
        </div>

        <div class="form-group">
            <label for="email">Email</label>
            <input
                type="email"
                id="email"
                name="email"
                value="<?= htmlspecialchars($_POST['email'] ?? '') ?>"
                required
            >
        </div>

        <div class="form-group">
            <label for="password">Password</label>
            <input
                type="password"
                id="password"
                name="password"
                required
            >
            <small class="text-muted" style="margin-top: 4px; display: block;">Min 8 chars, uppercase, lowercase, number, special char</small>
        </div>

        <div class="form-group">
            <label for="confirmPassword">Confirm Password</label>
            <input
                type="password"
                id="confirmPassword"
                name="confirmPassword"
                required
            >
            <div id="errorMsg" style="color: var(--danger); font-size: 12px; margin-top: 4px;"></div>
        </div>

        <button type="submit" class="btn btn-primary btn-lg btn-block" id="submitBtn">
            <span id="btnText">Create Account</span>
        </button>

    </form>

    <div class="auth-footer">
        Already have an account?
        <a href="/think-twice">Login</a>
    </div>
  </div>
</div>

<script>
    const form = document.getElementById("signupForm");
    const password = document.getElementById("password");
    const confirmPassword = document.getElementById("confirmPassword");
    const errorMsg = document.getElementById("errorMsg");
    const button = document.getElementById("submitBtn");
    const btnText = document.getElementById("btnText");

    form.addEventListener("submit", function (e) {

        if (password.value.length < 8) {
            e.preventDefault();
            errorMsg.textContent = "Password must be at least 8 characters.";
            return;
        }

        if (password.value !== confirmPassword.value) {
            e.preventDefault();
            errorMsg.textContent = "Passwords do not match.";
            return;
        }

        errorMsg.textContent = "";

        button.disabled = true;
        btnText.innerHTML = '<div class="spinner"></div> Creating Account...';
    });
</script>

</body>
</html>
