
<?php
// Database configuration
$host = "localhost";
$dbname = "think-twice"; // change this
$username = "root";
$password = ""; // default for XAMPP is empty

try {
    // Create PDO connection
    $conn = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8", $username, $password);

    // Set error mode to exception
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // Optional: echo "Connected successfully";
} catch (PDOException $e) {
    die("Database connection failed: " . $e->getMessage());
}
?>

