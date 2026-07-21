<?php
session_start();

// Load .env file manually (since PHP doesn't auto-load .env)
function loadEnv($path = null) {
    $path = $path ?? __DIR__ . '/../.env';
    if (!file_exists($path)) {
        return false;
    }
    
    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        // Skip comments
        if (strpos(trim($line), '#') === 0) {
            continue;
        }
        
        // Parse key=value
        if (strpos($line, '=') !== false) {
            list($key, $value) = explode('=', $line, 2);
            $key = trim($key);
            $value = trim($value);
            
            // Only set if not already set (system env vars take precedence)
            if (getenv($key) === false) {
                putenv("$key=$value");
                $_ENV[$key] = $value;
                $_SERVER[$key] = $value;
            }
        }
    }
    return true;
}

// Load the .env file
loadEnv();

function getAccessToken() {
    $consumerKey = getenv('CONSUMER_KEY');
    $consumerSecret = getenv('CONSUMER_SECRET');
    
    if (empty($consumerKey) || empty($consumerSecret)) {
        error_log("M-Pesa: Missing CONSUMER_KEY or CONSUMER_SECRET");
        return null;
    }
    
    // Remove any whitespace from credentials
    $consumerKey = trim($consumerKey);
    $consumerSecret = trim($consumerSecret);
    
    $credentials = base64_encode($consumerKey . ":" . $consumerSecret);
    
    $url = "https://sandbox.safaricom.co.ke/oauth/v1/generate?grant_type=client_credentials";
    
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_HTTPHEADER, array(
        "Authorization: Basic " . $credentials
    ));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    
    curl_close($ch);
    
    if ($response === false || $curlError) {
        error_log("M-Pesa: Curl error - " . $curlError);
        return null;
    }
    
    if ($httpCode !== 200) {
        error_log("M-Pesa: HTTP $httpCode - " . $response);
        return null;
    }
    
    $data = json_decode($response);
    
    if (!isset($data->access_token)) {
        error_log("M-Pesa: No access_token in response - " . $response);
        return null;
    }
    
    return $data->access_token;
}

// Auto-refresh logic with error handling
$token = null;
if (
    !isset($_SESSION['access_token']) || 
    !isset($_SESSION['token_time']) || 
    (time() - $_SESSION['token_time']) > 3500  // Refresh 100 seconds before expiry
) {
    $token = getAccessToken();
    if ($token) {
        $_SESSION['access_token'] = $token;
        $_SESSION['token_time'] = time();
    }
} else {
    $token = $_SESSION['access_token'];
}

// Make token available globally
$accessToken = $token;

// If we still don't have a token, try one more time
if (empty($accessToken)) {
    $accessToken = getAccessToken();
    if ($accessToken) {
        $_SESSION['access_token'] = $accessToken;
        $_SESSION['token_time'] = time();
    }
}