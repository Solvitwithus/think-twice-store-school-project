<?php
// ─────────────────────────────────────────────────────────────────────────────
// mpesa-callback.php
//
// Safaricom POSTs the transaction result here after an STK push completes.
// This file must be:
//   1. On a publicly accessible HTTPS server (not localhost)
//   2. Reachable without authentication / firewalls
//
// In your POS flow, the STK query polling handles confirmations independently,
// so this callback is a backup / audit trail — not required to make the POS work.
// ─────────────────────────────────────────────────────────────────────────────

// ── 1. Read the raw JSON body Safaricom sends ────────────────────────────────
$rawBody = file_get_contents('php://input');
$data    = json_decode($rawBody, true);

// ── 2. Log it to a file so you can inspect it during development ─────────────
// Each callback appends a timestamped entry to mpesa_callbacks.log
$logFile = __DIR__ . '/mpesa_callbacks.log';
$entry   = "\n[" . date('Y-m-d H:i:s') . "]\n" . $rawBody . "\n" . str_repeat('-', 60);
file_put_contents($logFile, $entry, FILE_APPEND | LOCK_EX);

// ── 3. Extract the result ────────────────────────────────────────────────────
$callback       = $data['Body']['stkCallback']           ?? null;
$resultCode     = $callback['ResultCode']                ?? null;
$resultDesc     = $callback['ResultDesc']                ?? 'No description';
$checkoutId     = $callback['CheckoutRequestID']         ?? null;
$merchantId     = $callback['MerchantRequestID']         ?? null;

// ── 4. On success (ResultCode 0), extract payment details ───────────────────
if ($resultCode === 0) {
    $meta = [];
    foreach ($callback['CallbackMetadata']['Item'] ?? [] as $item) {
        $meta[$item['Name']] = $item['Value'] ?? null;
    }

    $amount        = $meta['Amount']             ?? null;
    $receiptNumber = $meta['MpesaReceiptNumber'] ?? null;
    $phone         = $meta['PhoneNumber']        ?? null;
    $txDate        = $meta['TransactionDate']    ?? null;

    // ── TODO: save confirmed payment to your database ────────────────────
    // Example:
    // require __DIR__ . '/config/db.php';
    // $stmt = $conn->prepare(
    //     "UPDATE sales SET mpesa_receipt=?, confirmed=1 WHERE checkout_id=?"
    // );
    // $stmt->execute([$receiptNumber, $checkoutId]);
}

// ── 5. Always respond with 200 OK + the expected JSON ───────────────────────
// Safaricom will retry the callback if you don't return this exact structure.
http_response_code(200);
header('Content-Type: application/json');
echo json_encode([
    'ResultCode' => 0,
    'ResultDesc' => 'Accepted',
]);