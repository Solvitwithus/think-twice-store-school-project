
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
    <title>Sign Up</title>

    <style>
        * {
            box-sizing: border-box;
            font-family: Arial, sans-serif;
        }

        body {
            margin: 0;
            background: #f4f6f9;
            display: flex;
            justify-content: center;
            align-items: center;
            min-height: 100vh;
        }

        .signup-container {
            width: 100%;
            max-width: 400px;
            padding: 30px;
            background: #fff;
            border-radius: 10px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.1);
        }

        .signup-container h2 {
            text-align: center;
            margin-bottom: 20px;
        }

        .alert-error {
            background: #f8d7da;
            color: #721c24;
            padding: 10px 12px;
            border-radius: 6px;
            margin-bottom: 15px;
            font-size: 13px;
        }

        .form-group {
            display: flex;
            flex-direction: column;
            margin-bottom: 15px;
        }

        .form-group label {
            margin-bottom: 5px;
            font-size: 14px;
            color: #333;
        }

        .form-group input {
            padding: 10px;
            border: 1px solid #ccc;
            border-radius: 5px;
            font-size: 14px;
        }

        .form-group input:focus {
            border-color: #007bff;
            outline: none;
        }

        .error {
            color: #dc3545;
            font-size: 12px;
            margin-top: 5px;
        }

        .btn {
            width: 100%;
            padding: 10px;
            font-size: 16px;
            border: none;
            border-radius: 5px;
            background: #007bff;
            color: #fff;
            cursor: pointer;
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 8px;
        }

        .btn:hover {
            background: #0056b3;
        }

        .btn:disabled {
            background: #7aa7e0;
            cursor: not-allowed;
        }

        .footer-text {
            text-align: center;
            margin-top: 15px;
            font-size: 14px;
        }

        .footer-text a {
            color: #007bff;
            text-decoration: none;
        }

        .footer-text a:hover {
            text-decoration: underline;
        }

        .spinner {
            width: 16px;
            height: 16px;
            border: 2px solid #fff;
            border-top: 2px solid transparent;
            border-radius: 50%;
            animation: spin 0.6s linear infinite;
        }

        @keyframes spin {
            to {
                transform: rotate(360deg);
            }
        }
    </style>
</head>
<body>

<div class="signup-container">

    <h2>Create Account</h2>

    <?php if ($error): ?>
        <div class="alert-error">
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
        </div>

        <div class="form-group">
            <label for="confirmPassword">Confirm Password</label>
            <input
                type="password"
                id="confirmPassword"
                name="confirmPassword"
                required
            >
            <div id="errorMsg" class="error"></div>
        </div>

        <button type="submit" class="btn" id="submitBtn">
            <span id="btnText">Sign Up</span>
        </button>

    </form>

    <div class="footer-text">
        Already have an account?
        <a href="/think-twice">Login</a>
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

