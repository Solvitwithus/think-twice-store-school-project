<?php
require __DIR__ . '/config/db.php';

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    $fullname = $_POST['name'] ?? '';
    $username = strtolower(trim($_POST['Username'] ?? ''));
    $password = $_POST['password'] ?? '';
    $email = $_POST['email'] ?? '';
    $confirmPassword = $_POST['confirmPassword'] ?? '';

    try {
        if (!$fullname || !$username || !$password || !$email || !$confirmPassword) {
            echo "<script>alert('All fields are required!')</script>";
            exit;
        }

        if ($password !== $confirmPassword) {
            echo "<script>alert('Passwords do not match!')</script>";
            exit;
        }

        // Check duplicates
        $check = $conn->prepare("SELECT id FROM users WHERE email = :email OR username = :username");
        $check->execute(['email' => $email, 'username' => $username]);

        if ($check->fetch()) {
            echo "<script>alert('User already exists!')</script>";
            exit;
        }

        // Insert user
        $query = $conn->prepare("
            INSERT INTO users (name, username, password, email) 
            VALUES (:fullname, :username, :password, :email)
        ");

        $query->execute([
            'fullname' => $fullname,
            'username' => $username,
            'password' => password_hash($password, PASSWORD_DEFAULT),
            'email' => $email
        ]);

        // ✅ Redirect to login
        header("Location: /think-twice"); 
        exit;

    } catch (PDOException $e) {
        echo "<script>alert('Error: ".$e->getMessage()."')</script>";
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
      height: 100vh;
    }

    .signup-container {
      background: #fff;
      padding: 30px;
      border-radius: 10px;
      width: 100%;
      max-width: 400px;
      box-shadow: 0 4px 15px rgba(0,0,0,0.1);
    }

    .signup-container h2 {
      text-align: center;
      margin-bottom: 20px;
    }

    .form-group {
      margin-bottom: 15px;
      display: flex;
      flex-direction: column;
    }

    .form-group label {
      font-size: 14px;
      margin-bottom: 5px;
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

    .btn {
      width: 100%;
      padding: 10px;
      background: #007bff;
      color: #fff;
      border: none;
      border-radius: 5px;
      font-size: 16px;
      cursor: pointer;
    }

    .btn:hover {
      background: #0056b3;
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

    .error {
      color: red;
      font-size: 12px;
      margin-top: 5px;
    }
    .spinner {
  border: 3px solid #fff;
  border-top: 3px solid transparent;
  border-radius: 50%;
  width: 16px;
  height: 16px;
  animation: spin 0.7s linear infinite;
  display: inline-block;
}

@keyframes spin {
  to { transform: rotate(360deg); }
}
  </style>
</head>
<body>

  <div class="signup-container">
  <h2>Create Account</h2>

  <form id="signupForm" method="POST">
    
    <div class="form-group">
      <label>Full Name</label>
      <input type="text" name="name" required>
    </div>

    <div class="form-group">
      <label>Username</label>
      <input type="text" name="Username" required>
    </div>

    <div class="form-group">
      <label>Email</label>
      <input type="email" name="email" required>
    </div>

    <div class="form-group">
      <label>Password</label>
      <input type="password" id="password" name="password" required>
    </div>

    <div class="form-group">
      <label>Confirm Password</label>
      <input type="password" id="confirmPassword" name="confirmPassword" required>
      <div id="errorMsg" class="error"></div>
    </div>

    <button type="submit" class="btn" id="submitBtn">
      <span id="btnText">Sign Up</span>
    </button>
  </form>

  <div class="footer-text">
    Already have an account? <a href="/think-twice">Login</a>
  </div>
</div>

<script>
const form = document.getElementById("signupForm");
const password = document.getElementById("password");
const confirmPassword = document.getElementById("confirmPassword");
const errorMsg = document.getElementById("errorMsg");
const button = document.getElementById("submitBtn");
const btnText = document.getElementById("btnText");

form.addEventListener("submit", function(e) {
  if (password.value !== confirmPassword.value) {
    e.preventDefault();
    errorMsg.textContent = "Passwords do not match!";
    return;
  }

  errorMsg.textContent = "";

  // Show spinner
  button.disabled = true;
  btnText.innerHTML = `<span class="spinner"></span>`;
});
</script>
</body>
</html>

