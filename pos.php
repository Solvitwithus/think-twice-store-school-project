<?php
session_start();
require __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/mpesa_token.php';
// ─────────────────────────────────────────────────────────────────────────────
// mpesa_token.php already handles token caching in session and exposes
// $accessToken — no changes needed there.
// ─────────────────────────────────────────────────────────────────────────────


// ═════════════════════════════════════════════════════════════════════════════
//  AJAX BLOCK — ?action=... requests from JavaScript
//  Must sit at the very top, BEFORE any HTML, to avoid "headers already sent".
// ═════════════════════════════════════════════════════════════════════════════
if (isset($_GET['action'])) {
    header('Content-Type: application/json');

    // ── 1. stk_push ──────────────────────────────────────────────────────────
    // JS calls this when user clicks "Send STK Push".
    // We build the signed request and fire it at Safaricom's sandbox endpoint.
    // Safaricom then sends a payment prompt directly to the customer's phone.
    if ($_GET['action'] === 'stk_push') {

        $body   = json_decode(file_get_contents('php://input'), true);
        $phone  = preg_replace('/\s+/', '', $body['phone'] ?? '');
        $amount = (int) ceil((float)($body['amount'] ?? 0)); // M-Pesa only accepts whole-number amounts

        // ── Phone normalisation ───────────────────────────────────────────
        // Safaricom expects 2547XXXXXXXX — 12 digits, no + or leading 0.
        $phone = ltrim($phone, '+');                                    // drop leading +
        if (str_starts_with($phone, '0')) {                            // 07XX → 2547XX
            $phone = '254' . substr($phone, 1);
        }

        $shortcode = getenv('SHORTCODE');   // 174379 in sandbox
        $passkey   = getenv('PASSKEY');     // long string from Daraja portal
        $timestamp = date('YmdHis');        // YYYYMMDDHHmmss — must be fresh every call

        // ── Password = Base64(Shortcode + Passkey + Timestamp) ────────────
        // This is NOT your login password. It's a per-request signature that
        // Safaricom uses to verify the request came from you.
        $password = base64_encode($shortcode . $passkey . $timestamp);

        $payload = [
            'BusinessShortCode' => $shortcode,
            'Password'          => $password,
            'Timestamp'         => $timestamp,    // ← must match the one used in $password
            // CustomerPayBillOnline  → Paybill number
            // CustomerBuyGoodsOnline → Till/Buy Goods number
            'TransactionType'   => 'CustomerPayBillOnline',
            'Amount'            => $amount,
            'PartyA'            => $phone,        // customer's number (receives the prompt)
            'PartyB'            => $shortcode,    // your shortcode (receives the money)
            'PhoneNumber'       => $phone,        // same as PartyA for STK
            // ⚠ CALLBACK URL must be a live HTTPS URL.
            // For local dev: run `ngrok http 80` and paste the https URL here.
            // Safaricom will POST the result to this URL after the transaction.
            'CallBackURL'       => 'https://think-twice.wuaze.com/mpesa-callback.php',
            'AccountReference'  => 'POS-Sale',
            'TransactionDesc'   => 'POS Payment',
        ];

        $ch = curl_init('https://sandbox.safaricom.co.ke/mpesa/stkpush/v1/processrequest');
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($payload),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => [
                // $accessToken comes from mpesa_token.php (session-cached Bearer token)
                'Authorization: Bearer ' . $accessToken,
                'Content-Type: application/json',
            ],
        ]);
        $result = curl_exec($ch);
        curl_close($ch);

        $json = json_decode($result, true);

        // Save CheckoutRequestID so stk_query can look it up
        if (!empty($json['CheckoutRequestID'])) {
            $_SESSION['stk_checkout_id'] = $json['CheckoutRequestID'];
            $_SESSION['stk_amount']      = $amount;
        }

        echo json_encode($json);
        exit;
    }

    // ── 2. stk_query ─────────────────────────────────────────────────────────
    // JS polls this every ~5 s after an STK push to check if the customer paid.
    // ResultCode 0      = success
    // ResultCode 1032   = customer cancelled
    // ResultCode 1037   = timeout (no response from customer)
    // "in progress" text = still waiting
    // ⚠ Sandbox note: the query endpoint can be flaky — in production, always
    //   treat the CallbackURL response as the authoritative confirmation.
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

        echo $result;   // forward Safaricom's JSON straight to JS
        exit;
    }

    // ── 3. finalize_sale ─────────────────────────────────────────────────────
    // Called by JS once payment is confirmed (M-Pesa or split).
    // Saves receipt to session, clears cart, then JS redirects to ?show_receipt=1.
    if ($_GET['action'] === 'finalize_sale') {

        $body      = json_decode(file_get_contents('php://input'), true);
        $method    = $body['method']    ?? 'mpesa';   // 'mpesa' | 'split'
        $cashPaid  = (float)($body['cash_paid']  ?? 0);
        $mpesaPaid = (float)($body['mpesa_paid'] ?? 0);

        // Recompute total from session (never trust totals from JS)
        $total = 0;
        foreach ($_SESSION['cart'] as $ci) {
            $total += $ci['quantity'] * $ci['price'];
        }
        $change = max(0, $cashPaid - ($total - $mpesaPaid));

        // Store receipt so the PHP page can render it after redirect
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

        // ── TODO: persist to database ─────────────────────────────────────
        // try {
        //     $conn->beginTransaction();
        //     $s = $conn->prepare("INSERT INTO sales (total,method,cashier,created_at) VALUES (?,?,?,NOW())");
        //     $s->execute([$total, $method, 'Admin']);
        //     $saleId = $conn->lastInsertId();
        //     foreach ($_SESSION['cart'] as $item) {
        //         $s2 = $conn->prepare("INSERT INTO sale_items (sale_id,barcode,qty,unit_price) VALUES (?,?,?,?)");
        //         $s2->execute([$saleId, $item['barcode'], $item['quantity'], $item['price']]);
        //     }
        //     $conn->commit();
        // } catch (PDOException $e) {
        //     $conn->rollBack();
        //     echo json_encode(['error' => $e->getMessage()]); exit;
        // }

        unset($_SESSION['stk_checkout_id'], $_SESSION['stk_amount']);
        $_SESSION['cart'] = [];

        echo json_encode(['success' => true]);
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

// Bootstrap session keys
if (!isset($_SESSION['cart']))        $_SESSION['cart']        = [];
if (!isset($_SESSION['held-carts']))  $_SESSION['held-carts']  = [];

/* ─────────────────────────────────────────────────────────────────────────
   POST HANDLERS  (unchanged from original except #7 cash — now saves receipt)
   ───────────────────────────────────────────────────────────────────────── */
if ($_SERVER["REQUEST_METHOD"] === "POST") {

    // 1. Look up item by barcode and add / increment in cart
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

    // 2. Update quantities
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

    // 3. Remove single item
    if (isset($_POST['remove-item'])) {
        $idx = (int) $_POST['item-index'];
        if (isset($_SESSION['cart'][$idx])) {
            array_splice($_SESSION['cart'], $idx, 1);
            $_SESSION['cart'] = array_values($_SESSION['cart']);
        }
    }

    // 4. Clear entire cart
    if (isset($_POST['clear-cart'])) {
        $_SESSION['cart'] = [];
        $success = "Cart cleared.";
    }

    // 5. Hold cart
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

    // 6. Resume held cart
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

    // 7. CASH PAYMENT — [CHANGED] now also builds receipt and redirects
    if (isset($_POST['check-balance'])) {
        $freshTotal = 0;
        foreach ($_SESSION['cart'] as $ci) {
            $freshTotal += $ci['quantity'] * $ci['price'];
        }
        $cashReceived = (float) ($_POST['cashed'] ?? 0);

        if ($cashReceived >= $freshTotal) {
            $change          = $cashReceived - $freshTotal;
            $paymentComplete = true;

            // ── Save receipt to session ──────────────────────────────────
            // After redirect, ?show_receipt=1 will auto-open the receipt modal.
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

            // TODO: save to DB here (same structure as finalize_sale above)

            $_SESSION['cart'] = [];
            // Redirect so the receipt modal opens on a clean page
            header('Location: pos.php?show_receipt=1');
            exit;
        } else {
            $shortage = $freshTotal - $cashReceived;
            $error = "Insufficient payment. Short by: " . number_format($shortage, 2);
        }
    }
}

// ── Grand total for display ──────────────────────────────────────────────────
foreach ($_SESSION['cart'] as $ci) {
    $grandTotal += $ci['quantity'] * $ci['price'];
}

// ── Pull receipt from session (set by any payment path) ─────────────────────
$receipt     = $_SESSION['last_receipt'] ?? null;
$showReceipt = isset($_GET['show_receipt']) && $receipt;
// Clear receipt from session once we've read it so it doesn't reappear on refresh
if ($showReceipt) unset($_SESSION['last_receipt']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>POS Terminal</title>
<link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Mono:wght@400;500;600&family=IBM+Plex+Sans:wght@300;400;500;600&display=swap" rel="stylesheet">
<style>
  /* ── unchanged base variables ── */
  :root {
    --bg:         #393838;
    --surface:    #181c27;
    --surface2:   #1f2535;
    --border:     #2a3145;
    --accent:     #00e5a0;
    --accent-dim: #00a370;
    --danger:     #ff4d6a;
    --warn:       #ffb830;
    --mpesa:      #4caf50;   /* [NEW] green for M-Pesa branding */
    --text:       #e8ecf5;
    --muted:      #6b7594;
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
  .btn-primary   { background: var(--accent);  color: #0f1117; }
  .btn-secondary { background: var(--surface2); color: var(--text); border: 1px solid var(--border); }
  .btn-danger    { background: var(--danger);   color: #fff; }
  .btn-warn      { background: var(--warn);     color: #0f1117; }
  .btn-ghost     { background: transparent;     color: var(--muted); border: 1px solid var(--border); }
  /* [NEW] M-Pesa green button */
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
  .pos-right { width: 220px; display: flex; flex-direction: column; background: var(--surface); flex-shrink: 0; }
  .panel-title { font-family: var(--mono); font-size: 10px; font-weight: 500; letter-spacing: .12em; text-transform: uppercase; color: var(--muted); padding: 16px 18px 10px; }

  .action-btn { display: flex; align-items: center; gap: 12px; padding: 14px 18px; border: none; background: transparent; color: var(--text); font-family: var(--sans); font-size: 13px; font-weight: 500; cursor: pointer; border-bottom: 1px solid var(--border); transition: background .12s; width: 100%; text-align: left; text-decoration: none; }
  .action-btn:hover { background: var(--surface2); }
  .action-btn .icon-dot { width: 8px; height: 8px; border-radius: 50%; flex-shrink: 0; }
  .dot-green  { background: var(--accent); }
  .dot-yellow { background: var(--warn); }
  .dot-blue   { background: #5b8ef0; }
  .dot-mpesa  { background: var(--mpesa); }   /* [NEW] */
  .dot-split  { background: #a78bfa; }        /* [NEW] */

  .action-btn.full-pay { background: var(--accent); color: #0f1117; font-weight: 700; font-size: 14px; margin: 12px; width: calc(100% - 24px); border-radius: 8px; justify-content: center; border: none; padding: 14px; }
  .action-btn.full-pay:hover { background: var(--accent-dim); }

  .held-count-badge { margin-left: auto; background: var(--warn); color: #0f1117; font-family: var(--mono); font-size: 10px; font-weight: 700; border-radius: 10px; padding: 2px 7px; }

  /* ── TOAST ── */
  .toast-bar { padding: 10px 20px; font-size: 13px; font-weight: 500; display: flex; align-items: center; gap: 10px; flex-shrink: 0; }
  .toast-bar.success { background: rgba(0,229,160,.12); color: var(--accent); border-bottom: 1px solid rgba(0,229,160,.2); }
  .toast-bar.error   { background: rgba(255,77,106,.12); color: var(--danger); border-bottom: 1px solid rgba(255,77,106,.2); }

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

  /* [NEW] ── STK STATUS INDICATOR ─────────────────────────────────────── */
  .stk-status {
    display: none;            /* shown by JS when STK push fires */
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

  /* [NEW] ── SPLIT AMOUNT DISPLAY ────────────────────────────────────── */
  .split-remainder { font-family: var(--mono); font-size: 20px; font-weight: 700; color: var(--mpesa); text-align: center; padding: 8px 0 16px; }
  .split-hint { font-size: 11px; color: var(--muted); text-align: center; margin-top: -12px; margin-bottom: 16px; }

  /* [NEW] ── RECEIPT MODAL ───────────────────────────────────────────── */
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

  /* [NEW] ── PRINT STYLES ─────────────────────────────────────────────
     When window.print() is called, hide everything except the receipt.    */
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
  /* Hidden on screen, shown only during print */
  #print-area { display: none; }
</style>
</head>
<body>

<!-- ══════ TOP BAR (unchanged) ══════ -->
<div class="topbar">
  <div class="topbar-brand">&#9632; POS Terminal</div>
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

  <!-- ── LEFT: SEARCH + CART (unchanged) ── -->
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

  <!-- ── RIGHT PANEL [CHANGED: added M-Pesa and Split buttons] ── -->
  <div class="pos-right">
    <div class="panel-title">Actions</div>

    <button type="button" class="action-btn" onclick="openModal('cash-modal')">
      <span class="icon-dot dot-green"></span> Cash Payment
    </button>

    <!-- [NEW] M-Pesa full payment button -->
    <button type="button" class="action-btn" onclick="openMpesaModal('mpesa')">
      <span class="icon-dot dot-mpesa"></span> M-Pesa Payment
    </button>

    <!-- [NEW] Split: part cash + part M-Pesa -->
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

    <a href="/think-twice/inventory/wareHousing.php" class="action-btn">
      <span class="icon-dot dot-blue"></span> View Inventory
    </a>

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

<!-- CASH PAYMENT MODAL (mostly unchanged, success now handled by redirect) -->
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

<!-- [NEW] M-PESA PAYMENT MODAL ────────────────────────────────────────────
     Used for both full M-Pesa payment and as the STK prompt step in split.
     JS sets the amount and label before opening.                           -->
<div class="modal-backdrop" id="mpesa-modal">
  <div class="modal">
    <div class="modal-title" id="mpesa-modal-title">M-Pesa Payment</div>

    <div class="modal-row">
      <span class="lbl" id="mpesa-amount-label">Amount</span>
      <span class="val val-big" id="mpesa-amount-display">KES —</span>
    </div>

    <br>
    <label>Customer Phone Number</label>
    <!-- tel type helps mobile keyboards show a numpad -->
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
      <button type="button" class="btn btn-ghost" onclick="cancelMpesa()">Cancel</button>
    </div>
  </div>
</div>

<!-- [NEW] SPLIT PAYMENT MODAL ──────────────────────────────────────────────
     Customer pays part in cash and the remainder via M-Pesa STK push.     -->
<div class="modal-backdrop" id="split-modal">
  <div class="modal">
    <div class="modal-title">Split Payment — Cash + M-Pesa</div>

    <div class="modal-row">
      <span class="lbl">Total Due</span>
      <span class="val val-big">KES <?= number_format($grandTotal, 2) ?></span>
    </div>
    <br>

    <label>Cash Amount (KES)</label>
    <!-- oninput recalculates the M-Pesa portion dynamically -->
    <input type="number" id="split-cash" min="0" step="0.01" placeholder="0.00"
      max="<?= $grandTotal ?>"
      oninput="updateSplitRemainder()">

    <p style="font-size:12px; color:var(--muted); font-family:var(--mono); margin-bottom:8px">
      M-Pesa will charge:
    </p>
    <div class="split-remainder" id="split-mpesa-display">KES <?= number_format($grandTotal, 2) ?></div>
    <p class="split-hint">Enter 0 for full M-Pesa payment</p>

    <div class="modal-actions">
      <!-- Clicking this pre-fills the M-Pesa modal with the remainder and opens it -->
      <button type="button" class="btn btn-mpesa" onclick="proceedSplit()">Next → M-Pesa STK</button>
      <button type="button" class="btn btn-ghost" onclick="closeModal('split-modal')">Cancel</button>
    </div>
  </div>
</div>

<!-- [NEW] RECEIPT MODAL ────────────────────────────────────────────────────
     Shown after any successful payment. PHP pre-renders the receipt HTML.  -->
<div class="modal-backdrop <?= $showReceipt ? 'open' : '' ?>" id="receipt-modal">
  <div class="modal" style="width:420px">
    <div class="modal-title">✓ Payment Complete</div>

    <?php if ($receipt): ?>
    <div class="receipt-body" id="receipt-content">

      <div class="receipt-header">
        <h2>THINK TWICE</h2>
        <p>Point of Sale Receipt</p>
        <p><?= htmlspecialchars($receipt['date']) ?> &nbsp;|&nbsp; Cashier: <?= htmlspecialchars($receipt['cashier']) ?></p>
        <p style="font-size:10px; color:#999"><?= htmlspecialchars($receipt['receipt_no']) ?></p>
      </div>

      <!-- Items list -->
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

      <!-- Totals + payment breakdown -->
      <div class="receipt-totals">
        <div class="r-row total">
          <span>TOTAL</span><span>KES <?= number_format($receipt['total'], 2) ?></span>
        </div>

        <?php
          // Show payment breakdown based on method
          $method = $receipt['method'];
        ?>

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
    </div><!-- /receipt-body -->

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

<!-- PAUSE CART MODAL (unchanged) -->
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

<!-- HELD CARTS MODAL (unchanged) -->
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

<!-- [NEW] PRINT AREA — hidden on screen, only rendered during window.print() -->
<div id="print-area">
  <?php if ($receipt): ?>
  <div class="receipt-body">
    <!-- Receipt content is duplicated here so @media print can isolate it -->
    <div class="receipt-header">
      <h2>THINK TWICE</h2>
      <p>Point of Sale Receipt</p>
      <p><?= htmlspecialchars($receipt['date']) ?> | Cashier: <?= htmlspecialchars($receipt['cashier']) ?></p>
      <p><?= htmlspecialchars($receipt['receipt_no']) ?></p>
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
// ── Existing modal helpers ───────────────────────────────────────────────────
function openModal(id)  { document.getElementById(id).classList.add('open'); }
function closeModal(id) { document.getElementById(id).classList.remove('open'); }

document.querySelectorAll('.modal-backdrop').forEach(el => {
    el.addEventListener('click', e => { if (e.target === el) closeModal(el.id); });
});

// ── Grand total from PHP (used by JS calculations) ───────────────────────────
const GRAND_TOTAL = <?= json_encode($grandTotal) ?>; // injected from PHP

// ── [NEW] SPLIT PAYMENT HELPERS ─────────────────────────────────────────────

// Called on every keystroke in the split cash input
function updateSplitRemainder() {
    const cash      = parseFloat(document.getElementById('split-cash').value) || 0;
    const remainder = Math.max(0, GRAND_TOTAL - cash);
    document.getElementById('split-mpesa-display').textContent =
        'KES ' + remainder.toFixed(2);
}

// When user clicks "Next → M-Pesa STK" in the split modal
function proceedSplit() {
    const cash      = parseFloat(document.getElementById('split-cash').value) || 0;
    const remainder = GRAND_TOTAL - cash;

    if (cash < 0 || cash > GRAND_TOTAL) {
        alert('Cash amount must be between 0 and KES ' + GRAND_TOTAL.toFixed(2));
        return;
    }
    if (remainder <= 0) {
        // If cash covers everything, just treat as cash-only — close and open cash modal
        closeModal('split-modal');
        openModal('cash-modal');
        return;
    }

    // Store the split amounts so sendStkPush() knows the context
    window._splitCash    = cash;
    window._splitMpesa   = remainder;
    window._paymentMode  = 'split';   // tells finalizeSale which method to report

    closeModal('split-modal');
    // Open M-Pesa modal pre-filled with the M-Pesa portion
    openMpesaModal('split', remainder, cash);
}

// ── [NEW] OPEN M-PESA MODAL ──────────────────────────────────────────────────
// mode: 'mpesa' = full payment, 'split' = partial
function openMpesaModal(mode, mpesaAmount, cashAmount) {
    mpesaAmount = mpesaAmount ?? GRAND_TOTAL;
    cashAmount  = cashAmount  ?? 0;

    window._paymentMode = mode;
    window._mpesaAmount = mpesaAmount;
    window._cashAmount  = cashAmount;

    // Update modal labels
    document.getElementById('mpesa-modal-title').textContent =
        mode === 'split' ? 'M-Pesa — Split Payment' : 'M-Pesa Payment';
    document.getElementById('mpesa-amount-label').textContent =
        mode === 'split' ? 'M-Pesa Portion' : 'Total Due';
    document.getElementById('mpesa-amount-display').textContent =
        'KES ' + mpesaAmount.toFixed(2);

    // Reset STK status display
    resetStkStatus();

    openModal('mpesa-modal');
}

// ── [NEW] SEND STK PUSH ──────────────────────────────────────────────────────
// Fires a fetch() to ?action=stk_push on this same file.
// Safaricom's server processes it and sends a payment prompt to the phone.
let _pollInterval = null;   // holds the setInterval reference for polling

async function sendStkPush() {
    const phone  = document.getElementById('mpesa-phone').value.trim();
    const amount = window._mpesaAmount;

    if (!phone) { alert('Please enter a phone number.'); return; }
    if (!amount || amount <= 0) { alert('Invalid amount.'); return; }

    // Disable the button to prevent double-submission
    document.getElementById('btn-send-stk').disabled = true;
    document.getElementById('btn-send-stk').textContent = 'Sending…';
    showStkStatus('waiting', '⟳ Waiting for customer to pay…');

    try {
        // ── POST to ?action=stk_push ─────────────────────────────────────
        // This calls Safaricom's sandbox API and sends the STK prompt to the phone.
        const res  = await fetch('pos.php?action=stk_push', {
            method:  'POST',
            headers: { 'Content-Type': 'application/json' },
            body:    JSON.stringify({ phone, amount }),
        });
        const data = await res.json();

        if (data.ResponseCode === '0') {
            // ResponseCode 0 = STK sent successfully (not yet paid — just sent)
            showStkStatus('waiting', '⟳ Prompt sent! Waiting for PIN entry…');
            // Start polling every 5 seconds to check if customer completed payment
            _pollInterval = setInterval(pollStkStatus, 5000);
        } else {
            // Safaricom rejected the request (bad phone, bad credentials, etc.)
            showStkStatus('failed', '✗ Failed: ' + (data.errorMessage || data.ResponseDescription || 'Unknown error'));
            resetStkButton();
        }
    } catch (err) {
        showStkStatus('failed', '✗ Network error. Check your connection.');
        resetStkButton();
    }
}

// ── [NEW] POLL STK STATUS ────────────────────────────────────────────────────
// Called every 5 s after STK push to check if customer paid.
// Stops polling when a definitive result is received.
async function pollStkStatus() {
    try {
        const res  = await fetch('pos.php?action=stk_query');
        const data = await res.json();

        const code = parseInt(data.ResultCode ?? data.result ?? -1);

        if (code === 0) {
            // ── SUCCESS: Customer entered PIN and paid ────────────────────
            clearInterval(_pollInterval);
            showStkStatus('success', '✓ Payment received!');
            // Wait 1 second so the user can see the success message, then finalize
            setTimeout(() => finalizeSale(), 1000);

        } else if (code === 1032 || code === 1037) {
            // 1032 = cancelled by user, 1037 = timeout (no response)
            clearInterval(_pollInterval);
            const msg = code === 1032
                ? '✗ Customer cancelled the payment.'
                : '✗ Payment timed out. Please try again.';
            showStkStatus('failed', msg);
            resetStkButton();

        }
        // Any other code (or undefined) means still processing — keep polling

    } catch (err) {
        // Network hiccup — keep polling, don't give up
        console.warn('Poll error (will retry):', err);
    }
}

// ── [NEW] FINALIZE SALE ──────────────────────────────────────────────────────
// Called after payment is confirmed. Tells PHP to save receipt + clear cart,
// then redirects to ?show_receipt=1 so the receipt modal renders from PHP.
async function finalizeSale() {
    const mode     = window._paymentMode ?? 'mpesa';
    const mpesaPaid = window._mpesaAmount ?? 0;
    const cashPaid  = window._cashAmount  ?? 0;

    try {
        const res  = await fetch('pos.php?action=finalize_sale', {
            method:  'POST',
            headers: { 'Content-Type': 'application/json' },
            body:    JSON.stringify({
                method:     mode,
                mpesa_paid: mpesaPaid,
                cash_paid:  cashPaid,
            }),
        });
        const data = await res.json();

        if (data.success) {
            // Redirect — PHP will see ?show_receipt=1 and auto-open the receipt modal
            window.location.href = 'pos.php?show_receipt=1';
        } else {
            alert('Error finalising sale: ' + (data.error ?? 'Unknown'));
        }
    } catch (err) {
        alert('Network error while saving sale. Please check manually.');
    }
}

// ── [NEW] CANCEL M-PESA ──────────────────────────────────────────────────────
function cancelMpesa() {
    clearInterval(_pollInterval);   // stop polling if running
    resetStkStatus();
    resetStkButton();
    closeModal('mpesa-modal');
}

// ── [NEW] STK UI HELPERS ─────────────────────────────────────────────────────
function showStkStatus(state, text) {
    const box  = document.getElementById('stk-status-box');
    const span = document.getElementById('stk-status-text');
    box.className  = 'stk-status ' + state;   // applies CSS: waiting/success/failed
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

// ── [NEW] PRINT RECEIPT ──────────────────────────────────────────────────────
// Uses CSS @media print (defined in <style>) to show only #print-area on paper.
function printReceipt() {
    window.print();
}

// Auto-open receipt modal if PHP redirected here with ?show_receipt=1
<?php if ($showReceipt): ?>
openModal('receipt-modal');
<?php endif; ?>

// Auto-open cash modal if there was a cash payment error (keep existing behaviour)
<?php if (isset($_POST['check-balance']) && $error): ?>
openModal('cash-modal');
<?php endif; ?>
</script>
</body>
</html>