<?php
// ── Session must be started before ANY output ────────────────────────────────
session_start();

require __DIR__ . '/config/db.php';

$error = '';

// ── Only run the login logic on a POST request ───────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $username = strtolower(trim($_POST['username'] ?? ''));
    $password = $_POST['password'] ?? '';

  if (!$username || !$password) {
        $error = 'Both fields are required.';
    }
    elseif (strlen($username) < 3) {
        $error = 'Username cannot have less than 3 characters.';
    }
    elseif (strlen($password) < 8) {
        $error = 'Password cannot be less than 8 characters.';
    }
    elseif (!preg_match('/[!@#$%^&*()_+\-=\[\]{};:"\\\\|,.<>\/?]/', $password)) {
        $error = 'Password must contain at least one special character.';
    }
    else {
        try {
            // ── 1. Fetch the user row ────────────────────────────────────
            // We bring in role_id so we can do the role lookup next.
            $stmt = $conn->prepare("
                SELECT id, name, username, email, password, role_id
                FROM users
                WHERE username = :username
                LIMIT 1
            ");
            $stmt->execute(['username' => $username]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);

            // ── 2. Verify the password ───────────────────────────────────
            // password_verify() compares the plain-text input against the
            // bcrypt hash stored in the database.  Never compare plain text
            // to plain text — the DB column holds the HASH, not the password.
            if (!$user || !password_verify($password, $user['password'])) {
                $error = 'Invalid username or password.';
            } else {

                // ── 3. Fetch the role + permissions ──────────────────────
                // We JOIN users → roles to get both the role name and the
                // JSON permissions string in a single query.
                $roleStmt = $conn->prepare("
                    SELECT r.name AS role, r.permissions
                    FROM users u
                    JOIN roles r ON u.role_id = r.id
                    WHERE u.id = :id
                ");
                $roleStmt->execute(['id' => $user['id']]);
                $roleData = $roleStmt->fetch(PDO::FETCH_ASSOC);

                // ── 4. Rebuild the session ────────────────────────────────
                // session_regenerate_id() swaps the old session ID for a new
                // one — this prevents session-fixation attacks where someone
                // plants a known session ID before login.
                session_regenerate_id(true);

                $_SESSION['user_id']     = $user['id'];
                $_SESSION['name']        = $user['name'];
                $_SESSION['username']    = $user['username'];
                $_SESSION['email']       = $user['email'];

                if ($roleData) {
                    // json_decode(..., true) turns '["pos","inventory"]'
                    // back into a PHP array  → ['pos', 'inventory']
                    $_SESSION['role']        = $roleData['role'];
                    $_SESSION['permissions'] = json_decode($roleData['permissions'], true) ?? [];
                } else {
                    // User exists but has no role assigned yet
                    $_SESSION['role']        = null;
                    $_SESSION['permissions'] = [];
                }

                // ── 5. Redirect to the dashboard ─────────────────────────
                header('Location: /think-twice/dashboard.php');
                exit;
            }

        } catch (PDOException $e) {
            $error = 'Database error: ' . $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Login - Think Twice</title>
  <link rel="stylesheet" href="/think-twice/public/theme.css?v=2">
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
      max-width: 520px;
      padding: 40px 24px;
    }
    .auth-box {
      background: var(--surface);
      border: 1px solid var(--border);
      border-radius: var(--radius-xl);
      padding: 48px 52px;
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

<form method="POST" class="auth-box" id="loginForm">
  <div class="auth-header">
    <div class="auth-brand">◊</div>
    <div class="auth-title">Think Twice</div>
    <div class="auth-subtitle">Inventory & POS System</div>
  </div>

  <?php if ($error): ?>
    <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
  <?php endif; ?>

  <div class="form-group">
    <label for="username">Username</label>
    <input type="text" name="username" id="username" required autocomplete="username" />
  </div>

  <div class="form-group">
    <label for="password">Password</label>
    <input type="password" name="password" id="password" required autocomplete="current-password" />
  </div>

  <button type="submit" id="loginBtn" class="btn btn-primary btn-lg btn-block">
    <span id="btnText">Sign In</span>
  </button>

  <div class="auth-footer">
    Don't have an account? <a href="/think-twice/signup.php">Create one</a>
  </div>
</form>

<script>
  const form    = document.getElementById("loginForm");
  const btn     = document.getElementById("loginBtn");
  const btnText = document.getElementById("btnText");

  form.addEventListener("submit", function () {
    btn.disabled     = true;
    btnText.innerHTML = '<div class="spinner"></div> Signing in...';
  });
</script>

</body>
</html>