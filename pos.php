<?php
session_start();
require __DIR__ . '/config/db.php';

$error   = "";
$success = "";
$grandTotal      = 0;
$change          = 0;
$cashReceived    = 0;
$paymentComplete = false;

// Bootstrap session keys
if (!isset($_SESSION['cart']))        $_SESSION['cart']        = [];
if (!isset($_SESSION['held-carts']))  $_SESSION['held-carts']  = [];

/* ────────────────────────────────────────────────────────────
   ALL POST HANDLERS — each one is independent, not nested
   ──────────────────────────────────────────────────────────── */
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
                unset($cartItem); // ← always unset reference after foreach

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

    // 2. Update quantities from the cart table inputs
    if (isset($_POST['update-cart']) && !empty($_POST['quantities'])) {
        foreach ($_POST['quantities'] as $index => $qty) {
            $qty = (int) $qty;
            if (isset($_SESSION['cart'][$index])) {
                if ($qty <= 0) {
                    array_splice($_SESSION['cart'], (int)$index, 1); // remove row
                } else {
                    $_SESSION['cart'][$index]['quantity'] = $qty;
                }
            }
        }
        // Re-index after possible removal
        $_SESSION['cart'] = array_values($_SESSION['cart']);
        $success = "Cart updated.";
    }

    // 3. Remove a single item by index
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

    // 5. Hold / pause cart under a name
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

    // 6. Resume a held cart (replaces current cart)
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

    // 7. Cash payment — compute change or flag shortage
    if (isset($_POST['check-balance'])) {
        // Compute fresh total from current session cart
        $freshTotal = 0;
        foreach ($_SESSION['cart'] as $ci) {
            $freshTotal += $ci['quantity'] * $ci['price'];
        }
        $cashReceived = (float) ($_POST['cashed'] ?? 0);

        if ($cashReceived >= $freshTotal) {
            $change          = $cashReceived - $freshTotal;
            $paymentComplete = true;
            $success         = "Payment accepted. Change: " . number_format($change, 2);

            // ── INSERT SALE TO DB HERE ──
            // $conn->beginTransaction(); ...
            // On success:
            // $_SESSION['cart'] = [];

        } else {
            $shortage = $freshTotal - $cashReceived;
            $error = "Insufficient payment. Short by: " . number_format($shortage, 2);
        }
    }
}

// ── Compute grand total for display (after all mutations above) ──
foreach ($_SESSION['cart'] as $ci) {
    $grandTotal += $ci['quantity'] * $ci['price'];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>POS Terminal</title>
<link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Mono:wght@400;500;600&family=IBM+Plex+Sans:wght@300;400;500;600&display=swap" rel="stylesheet">
<style>
  :root {
    --bg:         #c0c0c0;
    --surface:    #181c27;
    --surface2:   #1f2535;
    --border:     #2a3145;
    --accent:     #00e5a0;
    --accent-dim: #00a370;
    --danger:     #ff4d6a;
    --warn:       #ffb830;
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
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 0 24px;
    height: 52px;
    background: var(--surface);
    border-bottom: 1px solid var(--border);
    flex-shrink: 0;
  }
  .topbar-brand {
    font-family: var(--mono);
    font-size: 13px;
    font-weight: 600;
    letter-spacing: .12em;
    color: var(--accent);
    text-transform: uppercase;
  }
  .topbar-meta {
    font-family: var(--mono);
    font-size: 11px;
    color: var(--muted);
    display: flex;
    gap: 20px;
  }
  .topbar-meta span { color: var(--text); }

  /* ── LAYOUT ── */
  .pos-layout {
    display: flex;
    flex: 1;
    overflow: hidden;
  }

  /* ── LEFT ── */
  .pos-left {
    flex: 1;
    display: flex;
    flex-direction: column;
    overflow: hidden;
    border-right: 1px solid var(--border);
  }

  .search-bar {
    display: flex;
    gap: 10px;
    padding: 16px 20px;
    background: var(--surface);
    border-bottom: 1px solid var(--border);
    flex-shrink: 0;
  }
  .search-bar input {
    flex: 1;
    font-family: var(--mono);
    font-size: 14px;
    background: var(--bg);
    border: 1px solid var(--border);
    border-radius: 6px;
    padding: 10px 14px;
    color: var(--text);
    outline: none;
    transition: border-color .2s;
  }
  .search-bar input:focus { border-color: var(--accent); }
  .search-bar input::placeholder { color: var(--muted); }

  .btn {
    font-family: var(--sans);
    font-size: 13px;
    font-weight: 600;
    padding: 10px 18px;
    border: none;
    border-radius: 6px;
    cursor: pointer;
    transition: opacity .15s, transform .1s;
    letter-spacing: .02em;
    white-space: nowrap;
  }
  .btn:active { transform: scale(.97); }
  .btn-primary   { background: var(--accent);  color: #0f1117; }
  .btn-secondary { background: var(--surface2); color: var(--text); border: 1px solid var(--border); }
  .btn-danger    { background: var(--danger);   color: #fff; }
  .btn-warn      { background: var(--warn);     color: #0f1117; }
  .btn-ghost     { background: transparent;     color: var(--muted); border: 1px solid var(--border); }
  .btn:hover { opacity: .85; }
  .btn-sm { padding: 6px 12px; font-size: 12px; }

  /* ── CART TABLE ── */
  .cart-wrap {
    flex: 1;
    overflow-y: auto;
    padding: 0;
  }
  .cart-wrap::-webkit-scrollbar { width: 6px; }
  .cart-wrap::-webkit-scrollbar-track { background: transparent; }
  .cart-wrap::-webkit-scrollbar-thumb { background: var(--border); border-radius: 3px; }

  .cart-form { height: 100%; display: flex; flex-direction: column; }

  table {
    width: 100%;
    border-collapse: collapse;
    font-size: 13px;
  }
  thead th {
    font-family: var(--mono);
    font-size: 10px;
    font-weight: 500;
    letter-spacing: .1em;
    text-transform: uppercase;
    color: var(--muted);
    padding: 12px 16px;
    text-align: left;
    background: var(--surface);
    border-bottom: 1px solid var(--border);
    position: sticky;
    top: 0;
    z-index: 1;
  }
  thead th:last-child { text-align: right; }

  tbody tr {
    border-bottom: 1px solid var(--border);
    transition: background .12s;
  }
  tbody tr:hover { background: var(--surface); }

  td {
    padding: 11px 16px;
    vertical-align: middle;
  }
  td:last-child { text-align: right; }

  .td-num {
    font-family: var(--mono);
    font-size: 11px;
    color: var(--muted);
    width: 36px;
  }
  .td-name  { font-weight: 500; max-width: 200px; }
  .td-code  { font-family: var(--mono); font-size: 12px; color: var(--muted); }
  .td-price { font-family: var(--mono); }
  .td-total { font-family: var(--mono); font-weight: 600; color: var(--accent); }

  .qty-input {
    font-family: var(--mono);
    font-size: 13px;
    width: 64px;
    padding: 5px 8px;
    background: var(--bg);
    border: 1px solid var(--border);
    border-radius: 4px;
    color: var(--text);
    text-align: center;
    outline: none;
  }
  .qty-input:focus { border-color: var(--accent); }

  .remove-btn {
    background: none;
    border: none;
    color: var(--muted);
    cursor: pointer;
    font-size: 16px;
    line-height: 1;
    padding: 2px 6px;
    border-radius: 4px;
    transition: color .15s, background .15s;
  }
  .remove-btn:hover { color: var(--danger); background: rgba(255,77,106,.1); }

  .empty-state {
    flex: 1;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    gap: 10px;
    color: var(--muted);
    font-size: 13px;
    padding: 40px;
    text-align: center;
  }
  .empty-state .icon { font-size: 40px; opacity: .3; }

  /* ── CART FOOTER ── */
  .cart-footer {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 14px 20px;
    background: var(--surface);
    border-top: 1px solid var(--border);
    flex-shrink: 0;
  }
  .cart-footer-actions { display: flex; gap: 8px; }
  .total-block { text-align: right; }
  .total-label {
    font-family: var(--mono);
    font-size: 10px;
    letter-spacing: .1em;
    text-transform: uppercase;
    color: var(--muted);
    display: block;
  }
  .total-amount {
    font-family: var(--mono);
    font-size: 26px;
    font-weight: 600;
    color: var(--accent);
    letter-spacing: .02em;
  }
  .total-currency { font-size: 14px; color: var(--muted); margin-right: 3px; }

  /* ── RIGHT PANEL ── */
  .pos-right {
    width: 220px;
    display: flex;
    flex-direction: column;
    background: var(--surface);
    gap: 0;
    flex-shrink: 0;
  }

  .panel-title {
    font-family: var(--mono);
    font-size: 10px;
    font-weight: 500;
    letter-spacing: .12em;
    text-transform: uppercase;
    color: var(--muted);
    padding: 16px 18px 10px;
  }

  .action-btn {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 14px 18px;
    border: none;
    background: transparent;
    color: var(--text);
    font-family: var(--sans);
    font-size: 13px;
    font-weight: 500;
    cursor: pointer;
    border-bottom: 1px solid var(--border);
    transition: background .12s;
    width: 100%;
    text-align: left;
    text-decoration: none;
  }
  .action-btn:hover { background: var(--surface2); }
  .action-btn .icon-dot {
    width: 8px;
    height: 8px;
    border-radius: 50%;
    flex-shrink: 0;
  }
  .dot-green  { background: var(--accent); }
  .dot-yellow { background: var(--warn); }
  .dot-red    { background: var(--danger); }
  .dot-blue   { background: #5b8ef0; }
  .dot-purple { background: #a78bfa; }

  .action-btn.full-pay {
    background: var(--accent);
    color: #0f1117;
    font-weight: 700;
    font-size: 14px;
    margin: 12px;
    width: calc(100% - 24px);
    border-radius: 8px;
    justify-content: center;
    border: none;
    padding: 14px;
  }
  .action-btn.full-pay:hover { background: var(--accent-dim); }

  .held-count-badge {
    margin-left: auto;
    background: var(--warn);
    color: #0f1117;
    font-family: var(--mono);
    font-size: 10px;
    font-weight: 700;
    border-radius: 10px;
    padding: 2px 7px;
  }

  /* ── TOAST ── */
  .toast-bar {
    padding: 10px 20px;
    font-size: 13px;
    font-weight: 500;
    display: flex;
    align-items: center;
    gap: 10px;
    flex-shrink: 0;
  }
  .toast-bar.success { background: rgba(0,229,160,.12); color: var(--accent); border-bottom: 1px solid rgba(0,229,160,.2); }
  .toast-bar.error   { background: rgba(255,77,106,.12); color: var(--danger); border-bottom: 1px solid rgba(255,77,106,.2); }

  /* ── MODAL BACKDROP ── */
  .modal-backdrop {
    display: none;
    position: fixed;
    inset: 0;
    background: rgba(0,0,0,.65);
    backdrop-filter: blur(4px);
    z-index: 200;
    align-items: center;
    justify-content: center;
  }
  .modal-backdrop.open { display: flex; }

  .modal {
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: 12px;
    padding: 28px;
    width: 360px;
    max-width: 95vw;
    box-shadow: 0 24px 60px rgba(0,0,0,.5);
    animation: modalIn .18s ease-out;
  }
  @keyframes modalIn {
    from { opacity: 0; transform: translateY(12px) scale(.97); }
    to   { opacity: 1; transform: translateY(0) scale(1); }
  }
  .modal-title {
    font-family: var(--mono);
    font-size: 12px;
    font-weight: 600;
    letter-spacing: .1em;
    text-transform: uppercase;
    color: var(--muted);
    margin-bottom: 20px;
  }
  .modal label {
    display: block;
    font-size: 12px;
    color: var(--muted);
    margin-bottom: 6px;
    font-family: var(--mono);
    letter-spacing: .05em;
  }
  .modal input[type="text"],
  .modal input[type="number"] {
    width: 100%;
    font-family: var(--mono);
    font-size: 16px;
    font-weight: 500;
    background: var(--bg);
    border: 1px solid var(--border);
    border-radius: 6px;
    padding: 12px 14px;
    color: var(--text);
    outline: none;
    margin-bottom: 16px;
    transition: border-color .2s;
  }
  .modal input:focus { border-color: var(--accent); }

  .modal-row {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 10px 0;
    border-bottom: 1px solid var(--border);
    font-size: 13px;
  }
  .modal-row:last-of-type { border-bottom: none; }
  .modal-row .lbl { color: var(--muted); font-size: 12px; }
  .modal-row .val { font-family: var(--mono); font-weight: 600; }
  .val-big { font-size: 22px; color: var(--accent); }
  .val-change { font-size: 18px; color: var(--warn); }

  .modal-actions { display: flex; gap: 10px; margin-top: 20px; }
  .modal-actions .btn { flex: 1; }

  /* Held carts list */
  .held-list { display: flex; flex-direction: column; gap: 10px; max-height: 340px; overflow-y: auto; }
  .held-card {
    background: var(--bg);
    border: 1px solid var(--border);
    border-radius: 8px;
    padding: 14px;
    display: flex;
    justify-content: space-between;
    align-items: center;
  }
  .held-card-info strong { display: block; font-size: 14px; margin-bottom: 3px; }
  .held-card-info span { font-size: 11px; color: var(--muted); font-family: var(--mono); }
</style>
</head>
<body>

<!-- TOP BAR -->
<div class="topbar">
  <div class="topbar-brand">&#9632; POS Terminal</div>
  <div class="topbar-meta">
    Cashier: <span>Admin</span> &nbsp;|&nbsp;
    Date: <span><?= date('d M Y') ?></span> &nbsp;|&nbsp;
    Items: <span><?= count($_SESSION['cart']) ?></span>
  </div>
</div>

<!-- TOAST / ALERT BAR -->
<?php if ($error): ?>
<div class="toast-bar error">⚠ <?= htmlspecialchars($error) ?></div>
<?php elseif ($success): ?>
<div class="toast-bar success">✓ <?= htmlspecialchars($success) ?></div>
<?php endif; ?>

<!-- MAIN LAYOUT -->
<div class="pos-layout">

  <!-- ── LEFT: SEARCH + CART ── -->
  <div class="pos-left">

    <!-- Search bar -->
    <form method="POST" class="search-bar">
      <input type="text" name="code" placeholder="Scan or enter barcode…" autofocus autocomplete="off">
      <button type="submit" name="find-item" class="btn btn-primary">Add Item</button>
    </form>

    <!-- Cart table wrapped in update form -->
    <div class="cart-wrap">
      <?php if (!empty($_SESSION['cart'])): ?>
      <form method="POST" class="cart-form" id="cart-form">
        <table>
          <thead>
            <tr>
              <th>#</th>
              <th>Item</th>
              <th>Code</th>
              <th>Qty</th>
              <th>Unit Price</th>
              <th>Total</th>
              <th></th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($_SESSION['cart'] as $index => $item):
              $lineTotal = $item['quantity'] * $item['price'];
            ?>
            <tr>
              <td class="td-num"><?= $index + 1 ?></td>
              <td class="td-name"><?= htmlspecialchars($item['item_name']) ?></td>
              <td class="td-code"><?= htmlspecialchars($item['barcode']) ?></td>
              <td>
                <input
                  class="qty-input"
                  type="number"
                  name="quantities[<?= $index ?>]"
                  value="<?= $item['quantity'] ?>"
                  min="0"
                  onchange="document.getElementById('cart-form').requestSubmit(document.getElementById('update-btn'))"
                >
              </td>
              <td class="td-price"><?= number_format($item['price'], 2) ?></td>
              <td class="td-total"><?= number_format($lineTotal, 2) ?></td>
              <td>
                <!-- Remove single item -->
                <button
                  type="submit"
                  name="remove-item"
                  class="remove-btn"
                  title="Remove item"
                  onclick="this.form.querySelector('[name=item-index]').value=<?= $index ?>"
                >×</button>
              </td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
        <!-- Hidden fields for actions -->
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

    <!-- Cart footer -->
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

  </div><!-- /pos-left -->

  <!-- ── RIGHT PANEL ── -->
  <div class="pos-right">
    <div class="panel-title">Actions</div>

    <button type="button" class="action-btn" onclick="openModal('cash-modal')">
      <span class="icon-dot dot-green"></span> Cash Payment
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


<!-- ══════════════════ MODALS ══════════════════ -->

<!-- CASH PAYMENT MODAL -->
<div class="modal-backdrop" id="cash-modal">
  <div class="modal">
    <div class="modal-title">Cash Payment</div>
    <div class="modal-row">
      <span class="lbl">Total Due</span>
      <span class="val val-big">KES <?= number_format($grandTotal, 2) ?></span>
    </div>

    <?php if ($paymentComplete): ?>
    <div class="modal-row">
      <span class="lbl">Cash Received</span>
      <span class="val"><?= number_format($cashReceived, 2) ?></span>
    </div>
    <div class="modal-row">
      <span class="lbl">Change to Return</span>
      <span class="val val-change">KES <?= number_format($change, 2) ?></span>
    </div>
    <form method="POST">
      <div class="modal-actions">
        <button type="submit" name="clear-cart" class="btn btn-primary">New Sale</button>
        <button type="button" class="btn btn-ghost" onclick="closeModal('cash-modal')">Close</button>
      </div>
    </form>
    <?php else: ?>
    <form method="POST">
      <br>
      <label>Cash Received (KES)</label>
      <input type="number" name="cashed" min="0" step="0.01" placeholder="0.00" autofocus>
      <div class="modal-actions">
        <button type="submit" name="check-balance" class="btn btn-primary">Confirm Payment</button>
        <button type="button" class="btn btn-ghost" onclick="closeModal('cash-modal')">Cancel</button>
      </div>
    </form>
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

<!-- HELD / PAUSED CARTS MODAL -->
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

<script>
  function openModal(id)  { document.getElementById(id).classList.add('open'); }
  function closeModal(id) { document.getElementById(id).classList.remove('open'); }

  // Close backdrop on outside click
  document.querySelectorAll('.modal-backdrop').forEach(el => {
    el.addEventListener('click', e => { if (e.target === el) closeModal(el.id); });
  });

  // Auto-open cash modal if payment was just processed
  <?php if ($paymentComplete || (isset($_POST['check-balance']) && $error)): ?>
  openModal('cash-modal');
  <?php endif; ?>
</script>
</body>
</html>