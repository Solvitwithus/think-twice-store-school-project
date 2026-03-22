<?php
$username= $_POST['username'];
$password = $_POST['password'];
$email=$_POST['username'];



// $stmt = $conn->prepare("
//     SELECT r.name AS role, r.permissions 
//     FROM users u 
//     JOIN roles r ON u.role_id = r.id 
//     WHERE u.id = :id
// ");
// $stmt->execute(['id' => $user['id']]);
// $roleData = $stmt->fetch(PDO::FETCH_ASSOC);

// $_SESSION['user_id']     = $user['id'];
// $_SESSION['username']    = $user['username'];
// $_SESSION['role']        = $roleData['role'];
// $_SESSION['permissions'] = json_decode($roleData['permissions'], true);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Login</title>

<style>
* {
    box-sizing: border-box;
    font-family: Arial, sans-serif;
}

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

.login_container h2 {
    text-align: center;
    margin-bottom: 10px;
}

.login_container label {
    font-size: 14px;
    color: #333;
}

.login_container input {
    padding: 10px;
    font-size: 14px;
    border: 1px solid #ccc;
    border-radius: 5px;
}

.login_container input:focus {
    border-color: #007bff;
    outline: none;
}

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

.login_container button:hover {
    background: #0056b3;
}

.login_container button:disabled {
    background: #7aa7e0;
    cursor: not-allowed;
}

/* Spinner */
.spinner {
    width: 16px;
    height: 16px;
    border: 2px solid #fff;
    border-top: 2px solid transparent;
    border-radius: 50%;
    animation: spin 0.6s linear infinite;
}

@keyframes spin {
    to { transform: rotate(360deg); }
}

.footer {
    text-align: center;
    font-size: 14px;
}

.footer a {
    color: #007bff;
    text-decoration: none;
}

.footer a:hover {
    text-decoration: underline;
}
</style>
</head>

<body>

<form method="POST" action="login.php" class="login_container" id="loginForm">
    <h2>Login</h2>

    <label for="username">User Name</label>
    <input type="text" name="username" id="username" required />

    <label for="password">Password</label>
    <input type="password" name="password" id="password" required />

    <button type="submit" id="loginBtn">
        <span id="btnText">Login</span>
    </button>

    <div class="footer">
        Don't have an account? <a href="/think-twice/signup.php">Signup</a>
    </div>
</form>

<script>
const form = document.getElementById("loginForm");
const btn = document.getElementById("loginBtn");
const btnText = document.getElementById("btnText");

form.addEventListener("submit", function () {
   
    btn.disabled = true;

    
    btnText.innerHTML = '<div class="spinner"></div> Logging in...';
});
</script>

</body>
</html>

