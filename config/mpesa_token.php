<?php
session_start();

function getAccessToken() {
    $consumerKey = getenv('CONSUMER_KEY');
    $consumerSecret = getenv('CONSUMER_SECRET');

    $credentials = base64_encode($consumerKey . ":" . $consumerSecret);

    $url = "https://sandbox.safaricom.co.ke/oauth/v1/generate?grant_type=client_credentials";

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_HTTPHEADER, array(
        "Authorization: Basic " . $credentials
    ));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

    $response = curl_exec($ch);

    if ($response === false) {
        die("Curl error: " . curl_error($ch));
    }

    curl_close($ch);

    $data = json_decode($response);

    return $data->access_token;
}

// Auto-refresh logic
if (
    !isset($_SESSION['access_token']) || 
    !isset($_SESSION['token_time']) || 
    (time() - $_SESSION['token_time']) > 3600
) {
    $_SESSION['access_token'] = getAccessToken();
    $_SESSION['token_time'] = time();
}

// Make token available globally
$accessToken = $_SESSION['access_token'];