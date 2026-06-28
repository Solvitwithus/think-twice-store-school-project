<?php
session_start();
require __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/authGuard.php';
requireLogin();

$error   = "";
$success = "";

if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["create-requisition"])) {
    $supplier           = $_POST['supplier']           ?? '';
    $requisitionDate    = $_POST['requisitionDate']    ?? '';
    $requisitionDueDate = $_POST['requisitionDueDate'] ?? '';
    $memo               = $_POST['memo']               ?? '';
    $codes              = $_POST['code']               ?? [];
    $descriptions       = $_POST['description']        ?? [];
    $quantities         = $_POST['quantity']           ?? [];
    $prices             = $_POST['price']              ?? [];
    $totals             = $_POST['total']              ?? [];

    try {
        $query = $conn->prepare("
            INSERT INTO requisitions (requisition_date, due_date, memo, supplier)
            VALUES (:date, :due, :memo, :supplier)
        ");
        $query->execute([
            'date'     => $requisitionDate,
            'due'      => $requisitionDueDate,
            'memo'     => $memo,
            'supplier' => $supplier
        ]);
        $requisitionId = $conn->lastInsertId();

        for ($i = 0; $i < count($descriptions); $i++) {
            if (empty($descriptions[$i]) && empty($codes[$i])) continue;
            $itemQuery = $conn->prepare("
                INSERT INTO requisition_items (requisition_id, item_code, description, quantity, price, total)
                VALUES (:req_id, :code, :desc, :qty, :price, :total)
            ");
            $itemQuery->execute([
                'req_id' => $requisitionId,
                'code'   => $codes[$i]        ?? null,
                'desc'   => $descriptions[$i] ?? '',
                'qty'    => $quantities[$i]   ?? 0,
                'price'  => $prices[$i]       ?? 0,
                'total'  => $totals[$i]       ?? 0,
            ]);
        }
        header("Location: " . $_SERVER['PHP_SELF'] . "?success=1");
        exit;
    } catch (PDOException $e) {
        $error = "Error saving requisition: " . $e->getMessage();
    }
}

if (isset($_GET['success'])) $success = "Requisition raised successfully!";

$items = [];
try {
    $query = $conn->prepare("SELECT id, sku_code, item_name, buying_price FROM items ORDER BY item_name");
    $query->execute();
    $items = $query->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $error = "Error fetching items: " . $e->getMessage();
}

$itemPriceMap = [];
foreach ($items as $item) {
    $itemPriceMap[$item['id']] = $item['buying_price'];
}

$suppliers = [];
try {
    $stmt = $conn->prepare("SELECT * FROM suppliers ORDER BY name");
    $stmt->execute();
    $suppliers = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $error = "Could not load suppliers: " . $e->getMessage();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>New Requisition - Think Twice</title>
  <link rel="stylesheet" href="/think-twice/public/theme.css">
  <style>
    .line-table th, .line-table td { padding: 10px 12px; }
    .line-table thead th { background: var(--bg); color: var(--text-muted); font-size: 11px; text-transform: uppercase; letter-spacing: 0.5px; }
    .line-total { font-family: var(--font-mono); color: var(--primary); font-weight: 600; }
    .grand-total { font-family: var(--font-mono); font-size: 20px; color: var(--primary); font-weight: 700; }
    .btn-add-row { background: transparent; border: 1px dashed var(--border-light); color: var(--text-muted); padding: 10px 18px; border-radius: 8px; cursor: pointer; font-size: 13px; width: 100%; margin-top: 12px; transition: all 0.15s; }
    .btn-add-row:hover { border-color: var(--primary); color: var(--primary); }
    .btn-rm { background: none; border: none; color: var(--text-muted); cursor: pointer; font-size: 18px; padding: 4px 8px; border-radius: 4px; transition: color 0.15s; }
    .btn-rm:hover { color: var(--danger); }
    .field-row { display: grid; grid-template-columns: 1fr 1fr; gap: var(--space-md); }
    @media (max-width: 600px) { .field-row { grid-template-columns: 1fr; } }
  </style>
</head>
<body class="page-container">

  <?php include __DIR__ . '/../navbar.php'; ?>

  <div class="page-header">
    <h1 class="page-title">New Requisition</h1>
    <p class="page-subtitle">Create a purchase requisition for inventory replenishment</p>
  </div>

  <div class="page-content">

    <?php if ($success): ?>
      <div class="alert alert-success">✓ <?= htmlspecialchars($success) ?></div>
    <?php endif; ?>
    <?php if ($error): ?>
      <div class="alert alert-danger">⚠ <?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <form method="POST">

      <!-- HEADER DETAILS -->
      <div class="card mb-lg">
        <div class="card-header">
          <div class="card-title">Requisition Details</div>
        </div>

        <div class="field-row">
          <div class="form-group">
            <label>Requisition Date</label>
            <input type="date" name="requisitionDate" value="<?= date('Y-m-d') ?>" required>
          </div>
          <div class="form-group">
            <label>Due Date</label>
            <input type="date" name="requisitionDueDate" required>
          </div>
        </div>

        <div class="form-group">
          <label>Supplier</label>
          <select name="supplier">
            <option value="">-- Select Supplier (optional) --</option>
            <?php foreach ($suppliers as $s): ?>
              <option value="<?= htmlspecialchars($s['id']) ?>">
                <?= htmlspecialchars($s['name']) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="form-group">
          <label>Memo / Notes</label>
          <textarea name="memo" rows="2" placeholder="Any additional notes for this requisition..."></textarea>
        </div>
      </div>

      <!-- LINE ITEMS -->
      <div class="card mb-lg">
        <div class="card-header">
          <div class="card-title">Line Items</div>
        </div>

        <div style="overflow-x: auto;">
          <table class="table line-table" style="min-width: 700px;">
            <thead>
              <tr>
                <th style="width:220px;">Item</th>
                <th>Description</th>
                <th style="width:90px;">Qty</th>
                <th style="width:120px;">Unit Price (KES)</th>
                <th style="width:120px;">Line Total (KES)</th>
                <th style="width:50px;"></th>
              </tr>
            </thead>
            <tbody id="tableBody">
              <tr>
                <td>
                  <select name="code[]" onchange="fillPrice(this)">
                    <option value="">-- Select --</option>
                    <?php foreach ($items as $item): ?>
                      <option value="<?= $item['id'] ?>" data-price="<?= $item['buying_price'] ?>">
                        <?= htmlspecialchars($item['item_name']) ?>
                      </option>
                    <?php endforeach; ?>
                  </select>
                </td>
                <td><input type="text" name="description[]" placeholder="Optional note"></td>
                <td><input type="number" name="quantity[]" min="0" step="1" oninput="calculateTotal(this.closest('tr'))"></td>
                <td><input type="number" name="price[]"    min="0" step="0.01" oninput="calculateTotal(this.closest('tr'))"></td>
                <td><input type="text"   name="total[]"    readonly placeholder="0.00" class="line-total"></td>
                <td><button type="button" class="btn-rm" onclick="removeRow(this)">×</button></td>
              </tr>
            </tbody>
            <tfoot>
              <tr>
                <td colspan="4" style="text-align:right; padding: 14px 12px; color: var(--text-muted); font-size: 13px; font-weight: 600;">
                  Grand Total
                </td>
                <td style="padding: 14px 12px;">
                  <span class="grand-total" id="grandTotal">KES 0.00</span>
                </td>
                <td></td>
              </tr>
            </tfoot>
          </table>
        </div>

        <button type="button" class="btn-add-row" onclick="addRow()">+ Add Item Row</button>
      </div>

      <!-- ACTIONS -->
      <div class="flex gap-md" style="justify-content: flex-end;">
        <a href="<?= $_SERVER['PHP_SELF'] ?>" class="btn btn-secondary">Clear</a>
        <button type="submit" name="create-requisition" class="btn btn-primary">Submit Requisition</button>
      </div>

    </form>
  </div>

  <script>
    const itemPrices = <?= json_encode($itemPriceMap) ?>;

    const itemOptions = `
      <option value="">-- Select --</option>
      <?php foreach ($items as $item): ?>
        <option value="<?= $item['id'] ?>" data-price="<?= $item['buying_price'] ?>">
          <?= htmlspecialchars(addslashes($item['item_name'])) ?>
        </option>
      <?php endforeach; ?>
    `;

    function addRow() {
      const row = document.createElement('tr');
      row.innerHTML = `
        <td><select name="code[]" onchange="fillPrice(this)">${itemOptions}</select></td>
        <td><input type="text" name="description[]" placeholder="Optional note"></td>
        <td><input type="number" name="quantity[]" min="0" step="1" oninput="calculateTotal(this.closest('tr'))"></td>
        <td><input type="number" name="price[]" min="0" step="0.01" oninput="calculateTotal(this.closest('tr'))"></td>
        <td><input type="text" name="total[]" readonly placeholder="0.00" class="line-total"></td>
        <td><button type="button" class="btn-rm" onclick="removeRow(this)">×</button></td>
      `;
      document.getElementById('tableBody').appendChild(row);
    }

    function removeRow(btn) {
      const rows = document.querySelectorAll('#tableBody tr');
      if (rows.length > 1) { btn.closest('tr').remove(); updateGrandTotal(); }
    }

    function fillPrice(select) {
      const price = itemPrices[select.value] || '';
      const row   = select.closest('tr');
      row.querySelector('[name="price[]"]').value = price;
      calculateTotal(row);
    }

    function calculateTotal(row) {
      const qty   = parseFloat(row.querySelector('[name="quantity[]"]').value) || 0;
      const price = parseFloat(row.querySelector('[name="price[]"]').value)    || 0;
      row.querySelector('[name="total[]"]').value = (qty * price).toFixed(2);
      updateGrandTotal();
    }

    function updateGrandTotal() {
      let grand = 0;
      document.querySelectorAll('[name="total[]"]').forEach(input => {
        grand += parseFloat(input.value) || 0;
      });
      document.getElementById('grandTotal').textContent =
        'KES ' + grand.toLocaleString('en-KE', { minimumFractionDigits: 2 });
    }
  </script>

</body>
</html>
