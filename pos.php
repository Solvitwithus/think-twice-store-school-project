<?php
session_start();
require __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/mpesa_token.php';

// ═════════════════════════════════════════════════════════════════════════════
//  AJAX BLOCK — ?action=... requests from JavaScript
// ═════════════════════════════════════════════════════════════════════════════
if (isset($_GET['action'])) {
    header('Content-Type: application/json');

    // ── 1. stk_push ──────────────────────────────────────────────────────────
    if ($_GET['action'] === 'stk_push') {

        $body   = json_decode(file_get_contents('php://input'), true);
        $phone  = preg_replace('/\s+/', '', $body['phone'] ?? '');
        $amount = (int) ceil((float)($body['amount'] ?? 0));

        $phone = ltrim($phone, '+');
        if (str_starts_with($phone, '0')) {
            $phone = '254' . substr($phone, 1);
        }

        $shortcode = "174379";
        $passkey   = "bfb279f9aa9bdbcf158e97dd71a467cd2e0c893059b10f78e6b72ada1ed2c919";
        $timestamp = date('YmdHis');
        $password  = base64_encode($shortcode . $passkey . $timestamp);

        $payload = [
            'BusinessShortCode' => $shortcode,
            'Password'          => $password,
            'Timestamp'         => $timestamp,
            'TransactionType'   => 'CustomerPayBillOnline',
            'Amount'            => $amount,
            'PartyA'            => $phone,
            'PartyB'            => $shortcode,
            'PhoneNumber'       => $phone,
            'CallBackURL'       => 'https://think-twice.wuaze.com/mpesa-callback.php',
            // ── AccountReference appears as the account label in the STK prompt.
            // The BUSINESS NAME shown at the top of the prompt is set in Daraja portal
            // under your Paybill/Till registration — not here in code.
            'AccountReference'  => 'Think Twice',
            'TransactionDesc'   => 'POS Sale – Think Twice',
        ];

        if (empty($accessToken)) {
            echo json_encode([
                'error' => 'Failed to get M-Pesa access token. Check your credentials.',
                'resultCode' => 401
            ]);
            exit;
        }

        $ch = curl_init('https://sandbox.safaricom.co.ke/mpesa/stkpush/v1/processrequest');
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($payload),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => [
                'Authorization: Bearer ' . $accessToken,
                'Content-Type: application/json',
            ],
        ]);
        $result = curl_exec($ch);
        curl_close($ch);

        $json = json_decode($result, true);

        if (!empty($json['CheckoutRequestID'])) {
            $_SESSION['stk_checkout_id'] = $json['CheckoutRequestID'];
            $_SESSION['stk_amount']      = $amount;
            $_SESSION['stk_phone']       = $phone;
        }

        echo json_encode($json);
        exit;
    }

    // ── 2. stk_query ─────────────────────────────────────────────────────────
    if ($_GET['action'] === 'stk_query') {

        $checkoutId = $_SESSION['stk_checkout_id'] ?? '';
        if (!$checkoutId) {
            echo json_encode(['error' => 'No pending STK transaction in session']);
            exit;
        }

        $shortcode = getenv('SHORTCODE');
        $passkey   = getenv('PASSKEY');
        $timestamp = date('YmdHis');
        $password  = base64_encode($shortcode . $passkey . $timestamp);

        $payload = [
            'BusinessShortCode' => $shortcode,
            'Password'          => $password,
            'Timestamp'         => $timestamp,
            'CheckoutRequestID' => $checkoutId,
        ];

        $ch = curl_init('https://sandbox.safaricom.co.ke/mpesa/stkpushquery/v1/query');
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($payload),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => [
                'Authorization: Bearer ' . $accessToken,
                'Content-Type: application/json',
            ],
        ]);
        $result = curl_exec($ch);
        curl_close($ch);

        echo $result;
        exit;
    }

    // ── 3. finalize_sale ─────────────────────────────────────────────────────
    if ($_GET['action'] === 'finalize_sale') {

        $body      = json_decode(file_get_contents('php://input'), true);
        $method    = $body['method']    ?? 'mpesa';
        $cashPaid  = (float)($body['cash_paid']  ?? 0);
        $mpesaPaid = (float)($body['mpesa_paid'] ?? 0);

        $total = 0;
        foreach ($_SESSION['cart'] as $ci) {
            $total += $ci['quantity'] * $ci['price'];
        }
        $change = max(0, $cashPaid - ($total - $mpesaPaid));

        $_SESSION['last_receipt'] = [
            'receipt_no' => 'RCT-' . strtoupper(substr(uniqid(), -6)),
            'date'       => date('d M Y H:i'),
            'cashier'    => 'Admin',
            'items'      => $_SESSION['cart'],
            'total'      => $total,
            'method'     => $method,
            'cash_paid'  => $cashPaid,
            'mpesa_paid' => $mpesaPaid,
            'change'     => $change,
        ];

        unset($_SESSION['stk_checkout_id'], $_SESSION['stk_amount'], $_SESSION['stk_phone']);
        $_SESSION['cart'] = [];

        echo json_encode(['success' => true]);
        exit;
    }

    // ── 4. get_transactions ───────────────────────────────────────────────────
    // Returns today's successful M-Pesa callbacks so cashier can manually pick one.
    if ($_GET['action'] === 'get_transactions') {
        try {
            $stmt = $conn->prepare(
                "SELECT id, mpesa_receipt, phone, amount, transaction_date, matched, created_at
                 FROM mpesa_transactions
                 WHERE DATE(created_at) = CURDATE() AND result_code = 0
                 ORDER BY created_at DESC
                 LIMIT 30"
            );
            $stmt->execute();
            echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
        } catch (PDOException $e) {
            echo json_encode(['error' => $e->getMessage()]);
        }
        exit;
    }

    // ── 5. manual_confirm ────────────────────────────────────────────────────
    // Cashier selects a confirmed callback transaction to close the sale manually.
    // This is the fallback when the STK query spinner never resolves (sandbox flakiness).
    if ($_GET['action'] === 'manual_confirm') {
        $body      = json_decode(file_get_contents('php://input'), true);
        $txId      = (int)($body['transaction_id'] ?? 0);
        $mode      = $body['method']    ?? 'mpesa';
        $cashPaid  = (float)($body['cash_paid']  ?? 0);

        if (!$txId) {
            echo json_encode(['error' => 'No transaction selected']);
            exit;
        }

        try {
            $tx = $conn->prepare(
                "SELECT * FROM mpesa_transactions WHERE id = ? AND result_code = 0"
            );
            $tx->execute([$txId]);
            $txRow = $tx->fetch(PDO::FETCH_ASSOC);

            if (!$txRow) {
                echo json_encode(['error' => 'Transaction not found or was not successful']);
                exit;
            }

            $mpesaPaid = (float)$txRow['amount'];
            $total = 0;
            foreach ($_SESSION['cart'] as $ci) {
                $total += $ci['quantity'] * $ci['price'];
            }
            $change = max(0, $cashPaid - ($total - $mpesaPaid));

            $_SESSION['last_receipt'] = [
                'receipt_no'    => 'RCT-' . strtoupper(substr(uniqid(), -6)),
                'date'          => date('d M Y H:i'),
                'cashier'       => 'Admin',
                'items'         => $_SESSION['cart'],
                'total'         => $total,
                'method'        => $mode,
                'cash_paid'     => $cashPaid,
                'mpesa_paid'    => $mpesaPaid,
                'change'        => $change,
                'mpesa_receipt' => $txRow['mpesa_receipt'],
            ];

            // Mark transaction as matched so it can't be reused
            $conn->prepare("UPDATE mpesa_transactions SET matched = 1 WHERE id = ?")->execute([$txId]);

            unset($_SESSION['stk_checkout_id'], $_SESSION['stk_amount'], $_SESSION['stk_phone']);
            $_SESSION['cart'] = [];

            echo json_encode(['success' => true]);
        } catch (PDOException $e) {
            echo json_encode(['error' => $e->getMessage()]);
        }
        exit;
    }

    echo json_encode(['error' => 'Unknown action']);
    exit;
}
// ═════════════════════════════════════════════════════════════════════════════
//  END AJAX BLOCK
// ═════════════════════════════════════════════════════════════════════════════


$error   = "";
$success = "";
$grandTotal      = 0;
$change          = 0;
$cashReceived    = 0;
$paymentComplete = false;

if (!isset($_SESSION['cart']))        $_SESSION['cart']        = [];
if (!isset($_SESSION['held-carts']))  $_SESSION['held-carts']  = [];

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    if (isset($_POST['find-item'])) {
        $code = trim($_POST['code'] ?? '');
        try {
            $stmt = $conn->prepare("SELECT * FROM stock_movements WHERE barcode = :barcode LIMIT 1");
            $stmt->execute(['barcode' => $code]);
            $item = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($item) {
                $found = false;
                foreach ($_SESSION['cart'] as &$cartItem) {
                    if ($cartItem['barcode'] === $item['barcode']) {
                        $cartItem['quantity'] += 1;
                        $found = true;
                        break;
                    }
                }
                unset($cartItem);
                if (!$found) {
                    $item['quantity'] = 1;
                    $_SESSION['cart'][] = $item;
                }
                $success = "Item added to cart.";
            } else {
                $error = "No item found for barcode: " . htmlspecialchars($code);
            }
        } catch (PDOException $e) {
            $error = "Database error: " . $e->getMessage();
        }
    }

    if (isset($_POST['update-cart']) && !empty($_POST['quantities'])) {
        foreach ($_POST['quantities'] as $index => $qty) {
            $qty = (int) $qty;
            if (isset($_SESSION['cart'][$index])) {
                if ($qty <= 0) {
                    array_splice($_SESSION['cart'], (int)$index, 1);
                } else {
                    $_SESSION['cart'][$index]['quantity'] = $qty;
                }
            }
        }
        $_SESSION['cart'] = array_values($_SESSION['cart']);
        $success = "Cart updated.";
    }

    if (isset($_POST['remove-item'])) {
        $idx = (int) $_POST['item-index'];
        if (isset($_SESSION['cart'][$idx])) {
            array_splice($_SESSION['cart'], $idx, 1);
            $_SESSION['cart'] = array_values($_SESSION['cart']);
        }
    }

    if (isset($_POST['clear-cart'])) {
        $_SESSION['cart'] = [];
        $success = "Cart cleared.";
    }

    if (isset($_POST['hold-cart'])) {
        $cartName = trim($_POST['hold'] ?? '');
        if ($cartName === '') {
            $error = "Please enter a name for the held cart.";
        } elseif (empty($_SESSION['cart'])) {
            $error = "Cart is empty — nothing to hold.";
        } else {
            $_SESSION['held-carts'][$cartName] = $_SESSION['cart'];
            $_SESSION['cart'] = [];
            $success = "Cart held as \"" . htmlspecialchars($cartName) . "\".";
        }
    }

    if (isset($_POST['resume-cart'])) {
        $name = $_POST['cart-name'] ?? '';
        if (isset($_SESSION['held-carts'][$name])) {
            $_SESSION['cart'] = $_SESSION['held-carts'][$name];
            unset($_SESSION['held-carts'][$name]);
            $success = "Cart \"" . htmlspecialchars($name) . "\" restored.";
        } else {
            $error = "Held cart not found.";
        }
    }

    if (isset($_POST['check-balance'])) {
        $freshTotal = 0;
        foreach ($_SESSION['cart'] as $ci) {
            $freshTotal += $ci['quantity'] * $ci['price'];
        }
        $cashReceived = (float) ($_POST['cashed'] ?? 0);

        if ($cashReceived >= $freshTotal) {
            $change          = $cashReceived - $freshTotal;
            $paymentComplete = true;

            $_SESSION['last_receipt'] = [
                'receipt_no' => 'RCT-' . strtoupper(substr(uniqid(), -6)),
                'date'       => date('d M Y H:i'),
                'cashier'    => 'Admin',
                'items'      => $_SESSION['cart'],
                'total'      => $freshTotal,
                'method'     => 'cash',
                'cash_paid'  => $cashReceived,
                'mpesa_paid' => 0,
                'change'     => $change,
            ];

            $_SESSION['cart'] = [];
            header('Location: pos.php?show_receipt=1');
            exit;
        } else {
            $shortage = $freshTotal - $cashReceived;
            $error = "Insufficient payment. Short by: " . number_format($shortage, 2);
        }
    }
}

foreach ($_SESSION['cart'] as $ci) {
    $grandTotal += $ci['quantity'] * $ci['price'];
}

$receipt     = $_SESSION['last_receipt'] ?? null;
$showReceipt = isset($_GET['show_receipt']) && $receipt;
if ($showReceipt) unset($_SESSION['last_receipt']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>POS Terminal - Think Twice</title>
<link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Mono:wght@400;500;600&family=IBM+Plex+Sans:wght@300;400;500;600&display=swap" rel="stylesheet">
<link rel="stylesheet" href="/think-twice/public/pos-styles.css">
<style>
  :root {
    --bg:         #f0f4f8;
    --surface:    #ffffff;
    --surface2:   #f5f7fa;
    --border:     #dde2e8;
    --accent:     #00995e;
    --accent-dim: #007a4a;
    --danger:     #d93025;
    --warn:       #e07b00;
    --mpesa:      #00995e;
    --text:       #1a2330;
    --muted:      #6b7685;
    --mono:       'IBM Plex Mono', monospace;
    --sans:       'IBM Plex Sans', sans-serif;
  }

  *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

  body {
    font-family: var(--sans);
    background: var(--bg);
    color: var(--text);
    height: 100vh;
    display: flex;
    flex-direction: column;
    overflow: hidden;
  }

  /* ── TOP BAR ── */
  .topbar {
    display: flex; align-items: center; justify-content: space-between;
    padding: 0 24px; height: 52px;
    background: var(--surface); border-bottom: 1px solid var(--border); flex-shrink: 0;
  }
  .topbar-brand { font-family: var(--mono); font-size: 13px; font-weight: 600; letter-spacing: .12em; color: var(--accent); text-transform: uppercase; }
  .topbar-meta  { font-family: var(--mono); font-size: 11px; color: var(--muted); display: flex; gap: 20px; }
  .topbar-meta span { color: var(--text); }

  /* ── LAYOUT ── */
  .pos-layout { display: flex; flex: 1; overflow: hidden; }
  .pos-left   { flex: 1; display: flex; flex-direction: column; overflow: hidden; border-right: 1px solid var(--border); }

  .search-bar { display: flex; gap: 10px; padding: 16px 20px; background: var(--surface); border-bottom: 1px solid var(--border); flex-shrink: 0; }
  .search-bar input { flex: 1; font-family: var(--mono); font-size: 14px; background: var(--bg); border: 1px solid var(--border); border-radius: 6px; padding: 10px 14px; color: var(--text); outline: none; transition: border-color .2s; }
  .search-bar input:focus { border-color: var(--accent); }
  .search-bar input::placeholder { color: var(--muted); }

  .btn { font-family: var(--sans); font-size: 13px; font-weight: 600; padding: 10px 18px; border: none; border-radius: 6px; cursor: pointer; transition: opacity .15s, transform .1s; letter-spacing: .02em; white-space: nowrap; }
  .btn:active { transform: scale(.97); }
  .btn-primary   { background: var(--accent);  color: #fff; }
  .btn-secondary { background: var(--surface2); color: var(--text); border: 1px solid var(--border); }
  .btn-danger    { background: var(--danger);   color: #fff; }
  .btn-warn      { background: var(--warn);     color: #fff; }
  .btn-ghost     { background: transparent;     color: var(--muted); border: 1px solid var(--border); }
  .btn-mpesa     { background: var(--mpesa);    color: #fff; }
  .btn:hover { opacity: .85; }
  .btn-sm { padding: 6px 12px; font-size: 12px; }

  /* ── CART TABLE ── */
  .cart-wrap { flex: 1; overflow-y: auto; padding: 0; }
  .cart-wrap::-webkit-scrollbar { width: 6px; }
  .cart-wrap::-webkit-scrollbar-track { background: transparent; }
  .cart-wrap::-webkit-scrollbar-thumb { background: var(--border); border-radius: 3px; }
  .cart-form { height: 100%; display: flex; flex-direction: column; }

  table { width: 100%; border-collapse: collapse; font-size: 13px; }
  thead th { font-family: var(--mono); font-size: 10px; font-weight: 500; letter-spacing: .1em; text-transform: uppercase; color: var(--muted); padding: 12px 16px; text-align: left; background: var(--surface); border-bottom: 1px solid var(--border); position: sticky; top: 0; z-index: 1; }
  thead th:last-child { text-align: right; }
  tbody tr { border-bottom: 1px solid var(--border); transition: background .12s; }
  tbody tr:hover { background: var(--surface); }
  td { padding: 11px 16px; vertical-align: middle; }
  td:last-child { text-align: right; }
  .td-num   { font-family: var(--mono); font-size: 11px; color: var(--muted); width: 36px; }
  .td-name  { font-weight: 500; max-width: 200px; }
  .td-code  { font-family: var(--mono); font-size: 12px; color: var(--muted); }
  .td-price { font-family: var(--mono); }
  .td-total { font-family: var(--mono); font-weight: 600; color: var(--accent); }

  .qty-input { font-family: var(--mono); font-size: 13px; width: 64px; padding: 5px 8px; background: var(--bg); border: 1px solid var(--border); border-radius: 4px; color: var(--text); text-align: center; outline: none; }
  .qty-input:focus { border-color: var(--accent); }

  .remove-btn { background: none; border: none; color: var(--muted); cursor: pointer; font-size: 16px; line-height: 1; padding: 2px 6px; border-radius: 4px; transition: color .15s, background .15s; }
  .remove-btn:hover { color: var(--danger); background: rgba(255,77,106,.1); }

  .empty-state { flex: 1; display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 10px; color: var(--muted); font-size: 13px; padding: 40px; text-align: center; }
  .empty-state .icon { font-size: 40px; opacity: .3; }

  /* ── CART FOOTER ── */
  .cart-footer { display: flex; align-items: center; justify-content: space-between; padding: 14px 20px; background: var(--surface); border-top: 1px solid var(--border); flex-shrink: 0; }
  .cart-footer-actions { display: flex; gap: 8px; }
  .total-block { text-align: right; }
  .total-label  { font-family: var(--mono); font-size: 10px; letter-spacing: .1em; text-transform: uppercase; color: var(--muted); display: block; }
  .total-amount { font-family: var(--mono); font-size: 26px; font-weight: 600; color: var(--accent); letter-spacing: .02em; }
  .total-currency { font-size: 14px; color: var(--muted); margin-right: 3px; }

  /* ── RIGHT PANEL ── */
  .pos-right { width: 220px; display: flex; flex-direction: column; background: var(--surface); flex-shrink: 0; overflow-y: auto; }
  .panel-title { font-family: var(--mono); font-size: 10px; font-weight: 500; letter-spacing: .12em; text-transform: uppercase; color: var(--muted); padding: 16px 18px 10px; }

  .action-btn { display: flex; align-items: center; gap: 12px; padding: 14px 18px; border: none; background: transparent; color: var(--text); font-family: var(--sans); font-size: 13px; font-weight: 500; cursor: pointer; border-bottom: 1px solid var(--border); transition: background .12s; width: 100%; text-align: left; text-decoration: none; }
  .action-btn:hover { background: var(--surface2); }
  .action-btn .icon-dot { width: 8px; height: 8px; border-radius: 50%; flex-shrink: 0; }
  .dot-green  { background: var(--accent); }
  .dot-yellow { background: var(--warn); }
  .dot-blue   { background: #5b8ef0; }
  .dot-mpesa  { background: var(--mpesa); }
  .dot-split  { background: #a78bfa; }
  .dot-orange { background: var(--warn); }

  .action-btn.full-pay { background: var(--accent); color: #fff; font-weight: 700; font-size: 14px; margin: 12px; width: calc(100% - 24px); border-radius: 8px; justify-content: center; border: none; padding: 14px; }
  .action-btn.full-pay:hover { background: var(--accent-dim); }

  .held-count-badge { margin-left: auto; background: var(--warn); color: #fff; font-family: var(--mono); font-size: 10px; font-weight: 700; border-radius: 10px; padding: 2px 7px; }

  /* ── TOAST ── */
  .toast-bar { padding: 10px 20px; font-size: 13px; font-weight: 500; display: flex; align-items: center; gap: 10px; flex-shrink: 0; }
  .toast-bar.success { background: rgba(0,153,94,.08); color: var(--accent); border-bottom: 1px solid rgba(0,153,94,.2); }
  .toast-bar.error   { background: rgba(217,48,37,.08); color: var(--danger); border-bottom: 1px solid rgba(217,48,37,.2); }

  /* ── MODAL BACKDROP ── */
  .modal-backdrop { display: none; position: fixed; inset: 0; background: rgba(0,0,0,.65); backdrop-filter: blur(4px); z-index: 200; align-items: center; justify-content: center; }
  .modal-backdrop.open { display: flex; }

  .modal { background: var(--surface); border: 1px solid var(--border); border-radius: 12px; padding: 28px; width: 360px; max-width: 95vw; box-shadow: 0 24px 60px rgba(0,0,0,.5); animation: modalIn .18s ease-out; }
  @keyframes modalIn { from { opacity: 0; transform: translateY(12px) scale(.97); } to { opacity: 1; transform: translateY(0) scale(1); } }
  .modal-title { font-family: var(--mono); font-size: 12px; font-weight: 600; letter-spacing: .1em; text-transform: uppercase; color: var(--muted); margin-bottom: 20px; }

  .modal label { display: block; font-size: 12px; color: var(--muted); margin-bottom: 6px; font-family: var(--mono); letter-spacing: .05em; }
  .modal input[type="text"], .modal input[type="number"], .modal input[type="tel"] { width: 100%; font-family: var(--mono); font-size: 16px; font-weight: 500; background: var(--bg); border: 1px solid var(--border); border-radius: 6px; padding: 12px 14px; color: var(--text); outline: none; margin-bottom: 16px; transition: border-color .2s; }
  .modal input:focus { border-color: var(--accent); }

  .modal-row { display: flex; justify-content: space-between; align-items: center; padding: 10px 0; border-bottom: 1px solid var(--border); font-size: 13px; }
  .modal-row:last-of-type { border-bottom: none; }
  .modal-row .lbl { color: var(--muted); font-size: 12px; }
  .modal-row .val { font-family: var(--mono); font-weight: 600; }
  .val-big    { font-size: 22px; color: var(--accent); }
  .val-change { font-size: 18px; color: var(--warn); }

  .modal-actions { display: flex; gap: 10px; margin-top: 20px; }
  .modal-actions .btn { flex: 1; }

  /* Held carts */
  .held-list { display: flex; flex-direction: column; gap: 10px; max-height: 340px; overflow-y: auto; }
  .held-card { background: var(--bg); border: 1px solid var(--border); border-radius: 8px; padding: 14px; display: flex; justify-content: space-between; align-items: center; }
  .held-card-info strong { display: block; font-size: 14px; margin-bottom: 3px; }
  .held-card-info span { font-size: 11px; color: var(--muted); font-family: var(--mono); }

  /* ── STK STATUS INDICATOR ── */
  .stk-status {
    display: none;
    padding: 14px;
    border-radius: 8px;
    font-size: 13px;
    font-family: var(--mono);
    text-align: center;
    margin-bottom: 16px;
    gap: 8px;
    align-items: center;
    justify-content: center;
  }
  .stk-status.waiting  { display: flex; background: rgba(255,184,48,.1); color: var(--warn);   border: 1px solid rgba(255,184,48,.3); }
  .stk-status.success  { display: flex; background: rgba(0,229,160,.1);  color: var(--accent); border: 1px solid rgba(0,229,160,.3); }
  .stk-status.failed   { display: flex; background: rgba(255,77,106,.1); color: var(--danger); border: 1px solid rgba(255,77,106,.3); }
  .spin { display: inline-block; animation: spin 1s linear infinite; }
  @keyframes spin { to { transform: rotate(360deg); } }

  /* ── SPLIT AMOUNT DISPLAY ── */
  .split-remainder { font-family: var(--mono); font-size: 20px; font-weight: 700; color: var(--mpesa); text-align: center; padding: 8px 0 16px; }
  .split-hint { font-size: 11px; color: var(--muted); text-align: center; margin-top: -12px; margin-bottom: 16px; }

  /* ── RECEIPT MODAL ── */
  #receipt-modal .modal { width: 400px; }
  .receipt-body { background: #fff; color: #111; border-radius: 8px; padding: 20px; font-family: var(--mono); font-size: 12px; line-height: 1.6; }
  .receipt-header { text-align: center; border-bottom: 1px dashed #ccc; padding-bottom: 12px; margin-bottom: 12px; }
  .receipt-header h2 { font-size: 16px; font-weight: 700; letter-spacing: .1em; }
  .receipt-header p  { font-size: 11px; color: #666; margin-top: 2px; }
  .receipt-items { width: 100%; border-collapse: collapse; margin-bottom: 12px; }
  .receipt-items th { font-size: 10px; text-transform: uppercase; letter-spacing: .07em; color: #888; text-align: left; padding: 4px 0; border-bottom: 1px solid #eee; }
  .receipt-items td { padding: 4px 0; font-size: 12px; }
  .receipt-items td:last-child { text-align: right; }
  .receipt-totals { border-top: 1px dashed #ccc; padding-top: 10px; }
  .receipt-totals .r-row { display: flex; justify-content: space-between; padding: 2px 0; }
  .receipt-totals .r-row.total { font-size: 14px; font-weight: 700; border-top: 1px solid #ccc; margin-top: 6px; padding-top: 6px; }
  .receipt-totals .r-row.change { color: #e67e00; }
  .receipt-footer { text-align: center; font-size: 10px; color: #999; margin-top: 12px; border-top: 1px dashed #ccc; padding-top: 10px; }
  .receipt-method-badge { display: inline-block; font-size: 10px; text-transform: uppercase; letter-spacing: .08em; padding: 2px 8px; border-radius: 10px; margin-bottom: 8px; }
  .badge-cash  { background: #e8f5e9; color: #2e7d32; }
  .badge-mpesa { background: #e8f5e9; color: #1b5e20; }
  .badge-split { background: #fff3e0; color: #e65100; }

  /* ── PRINT STYLES ── */
  @media print {
    body > *:not(#print-area) { display: none !important; }
    #print-area {
      display: block !important;
      position: fixed;
      inset: 0;
      background: white;
      padding: 30px;
      font-family: monospace;
      font-size: 13px;
      color: black;
    }
    #print-area .receipt-body { box-shadow: none; }
  }
  #print-area { display: none; }

  /* ── TRANSACTION CARDS (M-Pesa Transactions modal) ── */
  .tx-list { display: flex; flex-direction: column; gap: 8px; max-height: 360px; overflow-y: auto; padding-right: 4px; }
  .tx-list::-webkit-scrollbar { width: 4px; }
  .tx-list::-webkit-scrollbar-thumb { background: var(--border); border-radius: 2px; }

  .tx-card { background: var(--bg); border: 1px solid var(--border); border-radius: 8px; padding: 12px 14px; transition: background .12s, border-color .12s; }
  .tx-card.tx-unmatched { cursor: pointer; }
  .tx-card.tx-unmatched:hover { background: var(--surface2); border-color: var(--accent); }
  .tx-card.tx-matched { opacity: .5; cursor: default; }
  .tx-card-top { display: flex; justify-content: space-between; align-items: center; margin-bottom: 6px; }
  .tx-receipt { font-family: var(--mono); font-size: 13px; font-weight: 600; }
  .tx-amount  { font-family: var(--mono); font-size: 15px; font-weight: 700; color: var(--accent); }
  .tx-card-bottom { display: flex; justify-content: space-between; align-items: center; }
  .tx-phone { font-family: var(--mono); font-size: 11px; color: var(--muted); }
  .tx-time  { font-family: var(--mono); font-size: 10px; color: var(--muted); }
  .tx-badge { font-size: 10px; font-family: var(--mono); font-weight: 600; letter-spacing: .06em; text-transform: uppercase; padding: 2px 8px; border-radius: 10px; }
  .tx-badge.matched   { background: rgba(0,229,160,.1);  color: var(--accent); }
  .tx-badge.unmatched { background: rgba(255,184,48,.12); color: var(--warn); }
</style>
</head>
<body>

<!-- TOP BAR -->
<div class="topbar">
  <div class="topbar-brand"  onclick="window.location.href='/think-twice/dashboard.php'"
    style="cursor:pointer;">&#9632; POS Terminal — Think Twice</div>
  <div class="topbar-meta">
    Cashier: <span>Admin</span> &nbsp;|&nbsp;
    Date: <span><?= date('d M Y') ?></span> &nbsp;|&nbsp;
    Items: <span><?= count($_SESSION['cart']) ?></span>
  </div>
</div>

<!-- TOAST BAR -->
<?php if ($error): ?>
<div class="toast-bar error">⚠ <?= htmlspecialchars($error) ?></div>
<?php elseif ($success): ?>
<div class="toast-bar success">✓ <?= htmlspecialchars($success) ?></div>
<?php endif; ?>

<!-- MAIN LAYOUT -->
<div class="pos-layout">

  <!-- ── LEFT: SEARCH + CART ── -->
  <div class="pos-left">
    <form method="POST" class="search-bar">
      <input type="text" name="code" placeholder="Scan or enter barcode…" autofocus autocomplete="off">
      <button type="submit" name="find-item" class="btn btn-primary">Add Item</button>
    </form>

    <div class="cart-wrap">
      <?php if (!empty($_SESSION['cart'])): ?>
      <form method="POST" class="cart-form" id="cart-form">
        <table>
          <thead><tr><th>#</th><th>Item</th><th>Code</th><th>Qty</th><th>Unit Price</th><th>Total</th><th></th></tr></thead>
          <tbody>
            <?php foreach ($_SESSION['cart'] as $index => $item):
              $lineTotal = $item['quantity'] * $item['price']; ?>
            <tr>
              <td class="td-num"><?= $index + 1 ?></td>
              <td class="td-name"><?= htmlspecialchars($item['item_name']) ?></td>
              <td class="td-code"><?= htmlspecialchars($item['barcode']) ?></td>
              <td>
                <input class="qty-input" type="number" name="quantities[<?= $index ?>]"
                  value="<?= $item['quantity'] ?>" min="0"
                  onchange="document.getElementById('cart-form').requestSubmit(document.getElementById('update-btn'))">
              </td>
              <td class="td-price"><?= number_format($item['price'], 2) ?></td>
              <td class="td-total"><?= number_format($lineTotal, 2) ?></td>
              <td>
                <button type="submit" name="remove-item" class="remove-btn" title="Remove"
                  onclick="this.form.querySelector('[name=item-index]').value=<?= $index ?>">×</button>
              </td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
        <input type="hidden" name="item-index" value="">
        <button type="submit" name="update-cart" id="update-btn" style="display:none">update</button>
      </form>
      <?php else: ?>
      <div class="empty-state">
        <div class="icon">☐</div>
        <strong>Cart is empty</strong>
        <span>Scan a barcode or type a product code above</span>
      </div>
      <?php endif; ?>
    </div>

    <div class="cart-footer">
      <div class="cart-footer-actions">
        <form method="POST" style="display:inline">
          <button type="submit" name="clear-cart" class="btn btn-secondary btn-sm"
            onclick="return confirm('Clear entire cart?')">Clear Cart</button>
        </form>
        <button type="button" class="btn btn-secondary btn-sm" onclick="openModal('hold-modal')">Pause Cart</button>
      </div>
      <div class="total-block">
        <span class="total-label">Total Due</span>
        <div class="total-amount">
          <span class="total-currency">KES</span><?= number_format($grandTotal, 2) ?>
        </div>
      </div>
    </div>
  </div>

  <!-- ── RIGHT PANEL ── -->
  <div class="pos-right">
    <div class="panel-title">Actions</div>

    <button type="button" class="action-btn" onclick="openModal('cash-modal')">
      <span class="icon-dot dot-green"></span> Cash Payment
    </button>

    <button type="button" class="action-btn" onclick="openMpesaModal('mpesa')">
      <span class="icon-dot dot-mpesa"></span> M-Pesa Payment
    </button>

    <button type="button" class="action-btn" onclick="openModal('split-modal')">
      <span class="icon-dot dot-split"></span> Split Payment
    </button>

    <button type="button" class="action-btn" onclick="openModal('held-modal')">
      <span class="icon-dot dot-yellow"></span>
      Paused Carts
      <?php if (!empty($_SESSION['held-carts'])): ?>
      <span class="held-count-badge"><?= count($_SESSION['held-carts']) ?></span>
      <?php endif; ?>
    </button>

    <!-- UPDATED: links to main Inventory dashboard, not just warehousing -->
    <a href="/think-twice/itemsandInventory.php" class="action-btn">
      <span class="icon-dot dot-blue"></span> View Inventory
    </a>

    <!-- NEW: view today's M-Pesa transaction callbacks -->
    <button type="button" class="action-btn" onclick="openTxModal()">
      <span class="icon-dot dot-orange"></span> M-Pesa Transactions
    </button>

    <div style="padding: 12px 14px 4px; border-top: 1px solid var(--border); margin-top: auto;">
      <button type="button" onclick="openModal('cash-modal')" class="action-btn full-pay">
        Charge  KES <?= number_format($grandTotal, 2) ?>
      </button>
    </div>
  </div>

</div><!-- /pos-layout -->


<!-- ══════════════════════════════════════════════════════════════════════════
     MODALS
══════════════════════════════════════════════════════════════════════════ -->

<!-- CASH PAYMENT MODAL -->
<div class="modal-backdrop" id="cash-modal">
  <div class="modal">
    <div class="modal-title">Cash Payment</div>
    <div class="modal-row">
      <span class="lbl">Total Due</span>
      <span class="val val-big">KES <?= number_format($grandTotal, 2) ?></span>
    </div>
    <form method="POST">
      <br>
      <label>Cash Received (KES)</label>
      <input type="number" name="cashed" min="0" step="0.01" placeholder="0.00" autofocus>
      <div class="modal-actions">
        <button type="submit" name="check-balance" class="btn btn-primary">Confirm Payment</button>
        <button type="button" class="btn btn-ghost" onclick="closeModal('cash-modal')">Cancel</button>
      </div>
    </form>
    <?php if ($error && isset($_POST['check-balance'])): ?>
    <p style="color:var(--danger); font-size:12px; margin-top:12px; font-family:var(--mono)">
      <?= htmlspecialchars($error) ?>
    </p>
    <?php endif; ?>
  </div>
</div>

<!-- M-PESA PAYMENT MODAL -->
<div class="modal-backdrop" id="mpesa-modal">
  <div class="modal">
    <div class="modal-title" id="mpesa-modal-title">M-Pesa Payment</div>

    <div class="modal-row">
      <span class="lbl" id="mpesa-amount-label">Amount</span>
      <span class="val val-big" id="mpesa-amount-display">KES —</span>
    </div>

    <br>
    <label>Customer Phone Number</label>
    <input type="tel" id="mpesa-phone" placeholder="07XX XXX XXX" style="margin-bottom:8px">
    <p style="font-size:11px; color:var(--muted); font-family:var(--mono); margin-bottom:16px">
      Formats accepted: 07XX, +2547XX, 2547XX
    </p>

    <!-- STK status area — hidden until JS shows it -->
    <div class="stk-status" id="stk-status-box">
      <span class="spin" id="stk-spin">⟳</span>
      <span id="stk-status-text">Waiting for customer…</span>
    </div>

    <div class="modal-actions" id="mpesa-actions">
      <button type="button" class="btn btn-mpesa" id="btn-send-stk" onclick="sendStkPush()">
        Send STK Push
      </button>
      <!-- NEW: appears after polling times out; lets cashier pick from callbacks -->
      <button type="button" id="btn-manual-tx" class="btn btn-warn"
              style="display:none; flex:none; font-size:12px; padding:10px 12px"
              onclick="cancelMpesa(); openTxModal()">
        📋 Manual Confirm
      </button>
      <button type="button" class="btn btn-ghost" onclick="cancelMpesa()">Cancel</button>
    </div>

    <!-- Tip shown after timeout -->
    <p id="stk-timeout-tip" style="display:none; font-size:11px; color:var(--muted); font-family:var(--mono); margin-top:10px; text-align:center; line-height:1.5">
      Polling timed out — this is common in Sandbox.<br>
      Use <strong style="color:var(--warn)">Manual Confirm</strong> to select the M-Pesa receipt from callbacks.
    </p>
  </div>
</div>

<!-- SPLIT PAYMENT MODAL -->
<div class="modal-backdrop" id="split-modal">
  <div class="modal">
    <div class="modal-title">Split Payment — Cash + M-Pesa</div>

    <div class="modal-row">
      <span class="lbl">Total Due</span>
      <span class="val val-big">KES <?= number_format($grandTotal, 2) ?></span>
    </div>
    <br>

    <label>Cash Amount (KES)</label>
    <input type="number" id="split-cash" min="0" step="0.01" placeholder="0.00"
      max="<?= $grandTotal ?>"
      oninput="updateSplitRemainder()">

    <p style="font-size:12px; color:var(--muted); font-family:var(--mono); margin-bottom:8px">
      M-Pesa will charge:
    </p>
    <div class="split-remainder" id="split-mpesa-display">KES <?= number_format($grandTotal, 2) ?></div>
    <p class="split-hint">Enter 0 for full M-Pesa payment</p>

    <div class="modal-actions">
      <button type="button" class="btn btn-mpesa" onclick="proceedSplit()">Next → M-Pesa STK</button>
      <button type="button" class="btn btn-ghost" onclick="closeModal('split-modal')">Cancel</button>
    </div>
  </div>
</div>

<!-- RECEIPT MODAL -->
<div class="modal-backdrop <?= $showReceipt ? 'open' : '' ?>" id="receipt-modal">
  <div class="modal" style="width:420px">
    <div class="modal-title">✓ Payment Complete</div>

    <?php if ($receipt): ?>
    <div class="receipt-body" id="receipt-content">

      <div class="receipt-header">
        <h2>THINK TWICE CASE STUDY SHOP</h2>
        <p>Point of Sale Receipt</p>
        <p><?= htmlspecialchars($receipt['date']) ?> &nbsp;|&nbsp; Cashier: <?= htmlspecialchars($receipt['cashier']) ?></p>
        <p style="font-size:10px; color:#999"><?= htmlspecialchars($receipt['receipt_no']) ?></p>
        <?php if (!empty($receipt['mpesa_receipt'])): ?>
        <p style="font-size:10px; color:#555; margin-top:4px">M-Pesa Ref: <?= htmlspecialchars($receipt['mpesa_receipt']) ?></p>
        <?php endif; ?>
      </div>

      <table class="receipt-items">
        <thead>
          <tr><th>Item</th><th>Qty</th><th>Price</th><th>Total</th></tr>
        </thead>
        <tbody>
          <?php foreach ($receipt['items'] as $ri): ?>
          <tr>
            <td><?= htmlspecialchars($ri['item_name']) ?></td>
            <td><?= $ri['quantity'] ?></td>
            <td><?= number_format($ri['price'], 2) ?></td>
            <td><?= number_format($ri['quantity'] * $ri['price'], 2) ?></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>

      <div class="receipt-totals">
        <div class="r-row total">
          <span>TOTAL</span><span>KES <?= number_format($receipt['total'], 2) ?></span>
        </div>
        <?php $method = $receipt['method']; ?>
        <?php if ($method === 'cash' || $method === 'split'): ?>
        <div class="r-row">
          <span>Cash Paid</span>
          <span>KES <?= number_format($receipt['cash_paid'], 2) ?></span>
        </div>
        <?php endif; ?>
        <?php if ($method === 'mpesa' || $method === 'split'): ?>
        <div class="r-row">
          <span>M-Pesa Paid</span>
          <span>KES <?= number_format($receipt['mpesa_paid'], 2) ?></span>
        </div>
        <?php endif; ?>
        <?php if ($receipt['change'] > 0): ?>
        <div class="r-row change">
          <span>Change Returned</span>
          <span>KES <?= number_format($receipt['change'], 2) ?></span>
        </div>
        <?php endif; ?>
      </div>

      <div class="receipt-footer">
        <?php
          $badges = ['cash'=>'badge-cash','mpesa'=>'badge-mpesa','split'=>'badge-split'];
          $labels = ['cash'=>'Cash','mpesa'=>'M-Pesa','split'=>'Split Cash+Mpesa'];
        ?>
        <span class="receipt-method-badge <?= $badges[$method] ?? '' ?>">
          <?= $labels[$method] ?? $method ?>
        </span>
        <br>Thank you for shopping with us!<br>
        Goods once sold are not returnable.
      </div>
    </div>

    <div class="modal-actions" style="margin-top:16px">
      <button type="button" class="btn btn-primary" onclick="printReceipt()">🖨 Print</button>
      <a href="pos.php" class="btn btn-ghost">New Sale</a>
    </div>

    <?php else: ?>
    <p style="color:var(--muted); font-size:13px; text-align:center; padding:20px">No receipt data.</p>
    <div class="modal-actions"><a href="pos.php" class="btn btn-ghost">Close</a></div>
    <?php endif; ?>
  </div>
</div>

<!-- PAUSE CART MODAL -->
<div class="modal-backdrop" id="hold-modal">
  <div class="modal">
    <div class="modal-title">Pause / Hold Cart</div>
    <form method="POST">
      <label>Cart Label (e.g. "Table 3", "Customer A")</label>
      <input type="text" name="hold" placeholder="Enter a name…">
      <div class="modal-actions">
        <button type="submit" name="hold-cart" class="btn btn-warn">Hold Cart</button>
        <button type="button" class="btn btn-ghost" onclick="closeModal('hold-modal')">Cancel</button>
      </div>
    </form>
  </div>
</div>

<!-- HELD CARTS MODAL -->
<div class="modal-backdrop" id="held-modal">
  <div class="modal" style="width:420px">
    <div class="modal-title">Paused Carts</div>
    <?php if (!empty($_SESSION['held-carts'])): ?>
    <div class="held-list">
      <?php foreach ($_SESSION['held-carts'] as $cartName => $heldCart): ?>
      <div class="held-card">
        <div class="held-card-info">
          <strong><?= htmlspecialchars($cartName) ?></strong>
          <span><?= count($heldCart) ?> item<?= count($heldCart) !== 1 ? 's' : '' ?></span>
        </div>
        <form method="POST">
          <input type="hidden" name="cart-name" value="<?= htmlspecialchars($cartName) ?>">
          <button type="submit" name="resume-cart" class="btn btn-primary btn-sm">Resume</button>
        </form>
      </div>
      <?php endforeach; ?>
    </div>
    <?php else: ?>
    <p style="color:var(--muted); font-size:13px; text-align:center; padding:20px 0;">No paused carts</p>
    <?php endif; ?>
    <div class="modal-actions" style="margin-top:16px">
      <button type="button" class="btn btn-ghost" onclick="closeModal('held-modal')">Close</button>
    </div>
  </div>
</div>

<!-- ══ NEW: M-PESA TRANSACTIONS MODAL ══════════════════════════════════════
     Lists today's callback-confirmed payments. Cashier can select one to
     manually close the current sale when STK polling never resolves.       -->
<div class="modal-backdrop" id="tx-modal">
  <div class="modal" style="width:500px; max-width:96vw">
    <div class="modal-title">📋 Today's M-Pesa Transactions</div>
    <p style="font-size:11px; color:var(--muted); font-family:var(--mono); margin-bottom:14px; line-height:1.5">
      These are payments confirmed by Safaricom callbacks.
      Click an <span style="color:var(--warn)">unmatched</span> transaction to close the current sale with it.
    </p>
    <div class="tx-list" id="tx-list">
      <p style="color:var(--muted); font-size:13px; text-align:center; padding:20px">Loading…</p>
    </div>
    <div class="modal-actions" style="margin-top:16px; flex-wrap:wrap; gap:8px">
      <button type="button" class="btn btn-secondary btn-sm" onclick="loadTransactions()">↻ Refresh</button>
      <button type="button" class="btn btn-ghost" onclick="closeModal('tx-modal')">Close</button>
    </div>
  </div>
</div>

<!-- ══ NEW: MANUAL CONFIRM MODAL ══════════════════════════════════════════ -->
<div class="modal-backdrop" id="manual-confirm-modal">
  <div class="modal">
    <div class="modal-title">✓ Manual M-Pesa Confirmation</div>
    <div id="manual-tx-details"></div>
    <div style="background:rgba(255,184,48,.08); border:1px solid rgba(255,184,48,.25); border-radius:8px; padding:12px; font-size:12px; font-family:var(--mono); color:var(--warn); line-height:1.6; margin-bottom:4px;">
      ⚠ Verify that this M-Pesa receipt belongs to the current customer before confirming.
      This action cannot be undone.
    </div>
    <div class="modal-actions">
      <button type="button" class="btn btn-primary" id="btn-manual-confirm" onclick="submitManualConfirm()">
        Confirm &amp; Close Sale
      </button>
      <button type="button" class="btn btn-ghost" onclick="closeModal('manual-confirm-modal'); openTxModal()">
        Back
      </button>
    </div>
  </div>
</div>

<!-- PRINT AREA -->
<div id="print-area">
  <?php if ($receipt): ?>
  <div class="receipt-body">
    <div class="receipt-header">
      <h2>THINK TWICE CASE STUDY SHOP</h2>
      <p>Point of Sale Receipt</p>
      <p><?= htmlspecialchars($receipt['date']) ?> | Cashier: <?= htmlspecialchars($receipt['cashier']) ?></p>
      <p><?= htmlspecialchars($receipt['receipt_no']) ?></p>
      <?php if (!empty($receipt['mpesa_receipt'])): ?>
      <p>M-Pesa Ref: <?= htmlspecialchars($receipt['mpesa_receipt']) ?></p>
      <?php endif; ?>
    </div>
    <table class="receipt-items" style="width:100%; border-collapse:collapse; font-family:monospace; font-size:12px; margin-bottom:10px;">
      <thead><tr><th style="text-align:left">Item</th><th>Qty</th><th style="text-align:right">Price</th><th style="text-align:right">Total</th></tr></thead>
      <tbody>
        <?php foreach ($receipt['items'] as $ri): ?>
        <tr>
          <td><?= htmlspecialchars($ri['item_name']) ?></td>
          <td style="text-align:center"><?= $ri['quantity'] ?></td>
          <td style="text-align:right"><?= number_format($ri['price'], 2) ?></td>
          <td style="text-align:right"><?= number_format($ri['quantity'] * $ri['price'], 2) ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    <div style="border-top:1px dashed #333; padding-top:8px; font-family:monospace; font-size:13px;">
      <div style="display:flex; justify-content:space-between; font-weight:bold; font-size:15px;">
        <span>TOTAL</span><span>KES <?= number_format($receipt['total'], 2) ?></span>
      </div>
      <?php if ($receipt['cash_paid'] > 0): ?>
      <div style="display:flex; justify-content:space-between">
        <span>Cash</span><span>KES <?= number_format($receipt['cash_paid'], 2) ?></span>
      </div>
      <?php endif; ?>
      <?php if ($receipt['mpesa_paid'] > 0): ?>
      <div style="display:flex; justify-content:space-between">
        <span>M-Pesa</span><span>KES <?= number_format($receipt['mpesa_paid'], 2) ?></span>
      </div>
      <?php endif; ?>
      <?php if ($receipt['change'] > 0): ?>
      <div style="display:flex; justify-content:space-between">
        <span>Change</span><span>KES <?= number_format($receipt['change'], 2) ?></span>
      </div>
      <?php endif; ?>
    </div>
    <p style="text-align:center; font-family:monospace; font-size:11px; margin-top:12px; border-top:1px dashed #333; padding-top:8px;">
      Thank you for shopping with us!
    </p>
  </div>
  <?php endif; ?>
</div>


<!-- ══════════════════════════════════════════════════════════════════════════
     JAVASCRIPT
══════════════════════════════════════════════════════════════════════════ -->
<script>
// ── Modal helpers ────────────────────────────────────────────────────────────
function openModal(id)  { document.getElementById(id).classList.add('open'); }
function closeModal(id) { document.getElementById(id).classList.remove('open'); }

document.querySelectorAll('.modal-backdrop').forEach(el => {
    el.addEventListener('click', e => { if (e.target === el) closeModal(el.id); });
});

// Grand total from PHP
const GRAND_TOTAL = <?= json_encode($grandTotal) ?>;

// ── SPLIT PAYMENT ────────────────────────────────────────────────────────────
function updateSplitRemainder() {
    const cash      = parseFloat(document.getElementById('split-cash').value) || 0;
    const remainder = Math.max(0, GRAND_TOTAL - cash);
    document.getElementById('split-mpesa-display').textContent =
        'KES ' + remainder.toFixed(2);
}

function proceedSplit() {
    const cash      = parseFloat(document.getElementById('split-cash').value) || 0;
    const remainder = GRAND_TOTAL - cash;

    if (cash < 0 || cash > GRAND_TOTAL) {
        alert('Cash amount must be between 0 and KES ' + GRAND_TOTAL.toFixed(2));
        return;
    }
    if (remainder <= 0) {
        closeModal('split-modal');
        openModal('cash-modal');
        return;
    }

    window._splitCash    = cash;
    window._splitMpesa   = remainder;
    window._paymentMode  = 'split';

    closeModal('split-modal');
    openMpesaModal('split', remainder, cash);
}

// ── M-PESA MODAL ─────────────────────────────────────────────────────────────
function openMpesaModal(mode, mpesaAmount, cashAmount) {
    mpesaAmount = mpesaAmount ?? GRAND_TOTAL;
    cashAmount  = cashAmount  ?? 0;

    window._paymentMode = mode;
    window._mpesaAmount = mpesaAmount;
    window._cashAmount  = cashAmount;

    document.getElementById('mpesa-modal-title').textContent =
        mode === 'split' ? 'M-Pesa — Split Payment' : 'M-Pesa Payment';
    document.getElementById('mpesa-amount-label').textContent =
        mode === 'split' ? 'M-Pesa Portion' : 'Total Due';
    document.getElementById('mpesa-amount-display').textContent =
        'KES ' + mpesaAmount.toFixed(2);

    resetStkStatus();
    openModal('mpesa-modal');
}

// ── STK PUSH ─────────────────────────────────────────────────────────────────
let _pollInterval = null;
let _pollCount    = 0;
const MAX_POLLS   = 24;   // 24 × 5 s = 2 minutes then show manual confirm

async function sendStkPush() {
    const phone  = document.getElementById('mpesa-phone').value.trim();
    const amount = window._mpesaAmount;

    if (!phone) { alert('Please enter a phone number.'); return; }
    if (!amount || amount <= 0) { alert('Invalid amount.'); return; }

    document.getElementById('btn-send-stk').disabled    = true;
    document.getElementById('btn-send-stk').textContent = 'Sending…';
    document.getElementById('btn-manual-tx').style.display   = 'none';
    document.getElementById('stk-timeout-tip').style.display = 'none';
    showStkStatus('waiting', '⟳ Waiting for customer to pay…');
    _pollCount = 0;

    try {
        const res  = await fetch('pos.php?action=stk_push', {
            method:  'POST',
            headers: { 'Content-Type': 'application/json' },
            body:    JSON.stringify({ phone, amount }),
        });
        const data = await res.json();

        if (data.ResponseCode === '0') {
            showStkStatus('waiting', '⟳ Prompt sent! Waiting for PIN entry…');
            _pollInterval = setInterval(pollStkStatus, 5000);
        } else {
            showStkStatus('failed', '✗ Failed: ' + (data.errorMessage || data.ResponseDescription || 'Unknown error'));
            resetStkButton();
        }
    } catch (err) {
        showStkStatus('failed', '✗ Network error. Check your connection.');
        resetStkButton();
    }
}

// ── STK POLL ─────────────────────────────────────────────────────────────────
// Polls every 5 s. After MAX_POLLS (~2 min) stops and reveals Manual Confirm.
// This handles the sandbox flaw where the query endpoint never returns code 0
// even after the customer has already paid and the callback has fired.
async function pollStkStatus() {
    _pollCount++;

    // ── Timeout: stop polling, surface manual confirm ─────────────────────
    if (_pollCount > MAX_POLLS) {
        clearInterval(_pollInterval);
        showStkStatus('failed', '⏱ Polling timed out.');
        document.getElementById('btn-manual-tx').style.display   = 'inline-flex';
        document.getElementById('stk-timeout-tip').style.display = 'block';
        resetStkButton();
        return;
    }

    try {
        const res  = await fetch('pos.php?action=stk_query');
        const data = await res.json();

        const code = parseInt(data.ResultCode ?? data.result ?? -1);

        if (code === 0) {
            clearInterval(_pollInterval);
            showStkStatus('success', '✓ Payment received!');
            setTimeout(() => finalizeSale(), 1000);

        } else if (code === 1032 || code === 1037) {
            clearInterval(_pollInterval);
            const msg = code === 1032
                ? '✗ Customer cancelled the payment.'
                : '✗ Payment timed out. Please try again.';
            showStkStatus('failed', msg);
            resetStkButton();
        }
        // Other codes = still processing — keep polling

    } catch (err) {
        console.warn('Poll error (will retry):', err);
    }
}

// ── FINALIZE SALE ────────────────────────────────────────────────────────────
async function finalizeSale() {
    const mode      = window._paymentMode ?? 'mpesa';
    const mpesaPaid = window._mpesaAmount ?? 0;
    const cashPaid  = window._cashAmount  ?? 0;

    try {
        const res  = await fetch('pos.php?action=finalize_sale', {
            method:  'POST',
            headers: { 'Content-Type': 'application/json' },
            body:    JSON.stringify({ method: mode, mpesa_paid: mpesaPaid, cash_paid: cashPaid }),
        });
        const data = await res.json();

        if (data.success) {
            window.location.href = 'pos.php?show_receipt=1';
        } else {
            alert('Error finalising sale: ' + (data.error ?? 'Unknown'));
        }
    } catch (err) {
        alert('Network error while saving sale. Please check manually.');
    }
}

// ── CANCEL M-PESA ────────────────────────────────────────────────────────────
function cancelMpesa() {
    clearInterval(_pollInterval);
    resetStkStatus();
    resetStkButton();
    document.getElementById('btn-manual-tx').style.display   = 'none';
    document.getElementById('stk-timeout-tip').style.display = 'none';
    closeModal('mpesa-modal');
}

// ── STK UI HELPERS ───────────────────────────────────────────────────────────
function showStkStatus(state, text) {
    const box  = document.getElementById('stk-status-box');
    const span = document.getElementById('stk-status-text');
    box.className    = 'stk-status ' + state;
    span.textContent = text;
    document.getElementById('stk-spin').style.display =
        state === 'waiting' ? 'inline' : 'none';
}
function resetStkStatus() {
    document.getElementById('stk-status-box').className = 'stk-status';
}
function resetStkButton() {
    const btn = document.getElementById('btn-send-stk');
    btn.disabled    = false;
    btn.textContent = 'Send STK Push';
}

// ── PRINT RECEIPT ────────────────────────────────────────────────────────────
function printReceipt() { window.print(); }

// ═════════════════════════════════════════════════════════════════════════════
//  TRANSACTIONS MODAL — browse & manually match M-Pesa callbacks
// ═════════════════════════════════════════════════════════════════════════════
let _selectedTxId     = null;
let _selectedTxAmount = 0;

async function openTxModal() {
    openModal('tx-modal');
    await loadTransactions();
}

async function loadTransactions() {
    const list = document.getElementById('tx-list');
    list.innerHTML = '<p style="color:var(--muted);font-size:13px;text-align:center;padding:20px">Loading…</p>';

    try {
        const res  = await fetch('pos.php?action=get_transactions');
        const data = await res.json();

        if (data.error) {
            list.innerHTML = `<p style="color:var(--danger);font-size:13px;text-align:center;padding:20px">
                DB error: ${data.error}</p>`;
            return;
        }

        if (!Array.isArray(data) || data.length === 0) {
            list.innerHTML = `<p style="color:var(--muted);font-size:13px;text-align:center;padding:20px">
                No M-Pesa transactions recorded today.</p>`;
            return;
        }

        list.innerHTML = data.map(tx => {
            const matched = parseInt(tx.matched) === 1;
            const amount  = parseFloat(tx.amount).toFixed(2);
            const time    = tx.created_at ? tx.created_at.slice(11, 16) : '—';
            const phone   = tx.phone || '—';
            const receipt = tx.mpesa_receipt || '—';

            return `
            <div class="tx-card ${matched ? 'tx-matched' : 'tx-unmatched'}"
                 ${!matched ? `onclick="selectTx(${tx.id}, ${tx.amount}, '${receipt}', '${phone}')"` : ''}>
              <div class="tx-card-top">
                <span class="tx-receipt">${receipt}</span>
                <span class="tx-amount">KES ${amount}</span>
              </div>
              <div class="tx-card-bottom">
                <span class="tx-phone">${phone}</span>
                <span class="tx-time">${time}</span>
                <span class="tx-badge ${matched ? 'matched' : 'unmatched'}">
                  ${matched ? 'Matched' : 'Tap to use'}
                </span>
              </div>
            </div>`;
        }).join('');

    } catch (err) {
        list.innerHTML = `<p style="color:var(--danger);font-size:13px;text-align:center;padding:20px">
            Network error loading transactions.</p>`;
    }
}

function selectTx(id, amount, receipt, phone) {
    _selectedTxId     = id;
    _selectedTxAmount = parseFloat(amount);

    document.getElementById('manual-tx-details').innerHTML = `
        <div class="modal-row"><span class="lbl">M-Pesa Receipt</span><span class="val">${receipt}</span></div>
        <div class="modal-row"><span class="lbl">Phone</span><span class="val">${phone}</span></div>
        <div class="modal-row"><span class="lbl">M-Pesa Amount</span><span class="val val-big">KES ${_selectedTxAmount.toFixed(2)}</span></div>
        <div class="modal-row" style="margin-bottom:12px"><span class="lbl">Sale Total</span><span class="val">KES ${GRAND_TOTAL.toFixed(2)}</span></div>
    `;

    closeModal('tx-modal');
    openModal('manual-confirm-modal');
}

async function submitManualConfirm() {
    if (!_selectedTxId) return;

    const btn = document.getElementById('btn-manual-confirm');
    btn.disabled    = true;
    btn.textContent = 'Processing…';

    const mode     = window._paymentMode ?? 'mpesa';
    const cashPaid = window._cashAmount  ?? 0;

    try {
        const res  = await fetch('pos.php?action=manual_confirm', {
            method:  'POST',
            headers: { 'Content-Type': 'application/json' },
            body:    JSON.stringify({
                transaction_id: _selectedTxId,
                method:         mode,
                cash_paid:      cashPaid,
                mpesa_paid:     _selectedTxAmount,
            }),
        });
        const data = await res.json();

        if (data.success) {
            window.location.href = 'pos.php?show_receipt=1';
        } else {
            alert('Error: ' + (data.error ?? 'Unknown error'));
            btn.disabled    = false;
            btn.textContent = 'Confirm & Close Sale';
        }
    } catch (err) {
        alert('Network error. Please try again.');
        btn.disabled    = false;
        btn.textContent = 'Confirm & Close Sale';
    }
}

// Auto-open receipt modal if redirected with ?show_receipt=1
<?php if ($showReceipt): ?>
openModal('receipt-modal');
<?php endif; ?>

// Auto-open cash modal if payment was short
<?php if (isset($_POST['check-balance']) && $error): ?>
openModal('cash-modal');
<?php endif; ?>
</script>
</body>
</html>