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
    } else {
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
  <title>Login</title>

  <style>
    * { box-sizing: border-box; font-family: Arial, sans-serif; }

    body {
      display: flex;
      justify-content: center;
      align-items: center;
      height: 100vh;
      margin: 0;
      background: #f4f6f9;
    }

    .login_container {
      display: flex;
      flex-direction: column;
      width: 100%;
      max-width: 350px;
      gap: 12px;
      padding: 25px;
      background: #fff;
      box-shadow: 0 4px 15px rgba(0,0,0,0.1);
      border-radius: 10px;
    }

    .login_container h2 { text-align: center; margin-bottom: 10px; }

    .login_container label { font-size: 14px; color: #333; }

    .login_container input {
      padding: 10px;
      font-size: 14px;
      border: 1px solid #ccc;
      border-radius: 5px;
    }

    .login_container input:focus { border-color: #007bff; outline: none; }

    .login_container button {
      padding: 10px;
      font-size: 16px;
      cursor: pointer;
      background: #007bff;
      color: #fff;
      border: none;
      border-radius: 5px;
      display: flex;
      justify-content: center;
      align-items: center;
      gap: 8px;
    }

    .login_container button:hover    { background: #0056b3; }
    .login_container button:disabled { background: #7aa7e0; cursor: not-allowed; }

    .alert-error {
      background: #f8d7da;
      color: #721c24;
      padding: 9px 12px;
      border-radius: 6px;
      font-size: 13px;
    }

    .spinner {
      width: 16px;
      height: 16px;
      border: 2px solid #fff;
      border-top: 2px solid transparent;
      border-radius: 50%;
      animation: spin 0.6s linear infinite;
    }

    @keyframes spin { to { transform: rotate(360deg); } }

    .footer { text-align: center; font-size: 14px; }
    .footer a { color: #007bff; text-decoration: none; }
    .footer a:hover { text-decoration: underline; }
  </style>
</head>
<body>

<form method="POST" class="login_container" id="loginForm">
  <h2>Login</h2>

  <?php if ($error): ?>
    <!-- Server-side error (wrong password, DB issue, etc.) -->
    <div class="alert-error"><?= htmlspecialchars($error) ?></div>
  <?php endif; ?>

  <label for="username">Username</label>
  <input type="text" name="username" id="username" required autocomplete="username" />

  <label for="password">Password</label>
  <input type="password" name="password" id="password" required autocomplete="current-password" />

  <button type="submit" id="loginBtn">
    <span id="btnText">Login</span>
  </button>

  <div class="footer">
    Don't have an account? <a href="/think-twice/signup.php">Sign up</a>
  </div>
</form>

<script>
  const form    = document.getElementById("loginForm");
  const btn     = document.getElementById("loginBtn");
  const btnText = document.getElementById("btnText");

  form.addEventListener("submit", function () {
    btn.disabled     = true;
    btnText.innerHTML = '<div class="spinner"></div> Logging in...';
  });
</script>

</body>
</html>