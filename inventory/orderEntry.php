<?php
require __DIR__ . '/../config/db.php';

$error   = "";
$success = "";

// ── CREATE REQUISITION ───────────────────────────────────
// FIXED: was checking $_GET instead of $_POST for create-requisition
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["create-requisition"])) {
$supplier = $_POST['supplier'] ?? '';
    $requisitionDate    = $_POST['requisitionDate']    ?? '';
    $requisitionDueDate = $_POST['requisitionDueDate'] ?? '';
    $memo               = $_POST['memo']               ?? '';
    $codes              = $_POST['code']               ?? [];
    $descriptions       = $_POST['description']        ?? [];
    $quantities         = $_POST['quantity']           ?? [];
    $prices             = $_POST['price']              ?? [];
    $totals             = $_POST['total']              ?? [];

    try {
        // 1. Insert the requisition header
        $query = $conn->prepare("
            INSERT INTO requisitions (requisition_date, due_date, memo,supplier) 
            VALUES (:date, :due, :memo,:supplier)
        ");
        $query->execute([
            'date' => $requisitionDate,
            'due'  => $requisitionDueDate,
            'memo' => $memo,
            'supplier'=>$supplier
        ]);

        // 2. Get the new requisition's ID
        $requisitionId = $conn->lastInsertId();

        // 3. Insert each line item
        for ($i = 0; $i < count($descriptions); $i++) {
            // Skip completely empty rows
            if (empty($descriptions[$i]) && empty($codes[$i])) continue;

            $itemQuery = $conn->prepare("
                INSERT INTO requisition_items
                    (requisition_id, item_code, description, quantity, price, total)
                VALUES
                    (:req_id, :code, :desc, :qty, :price, :total)
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

        // FIXED: redirect then read success from $_GET, not $_POST
        header("Location: " . $_SERVER['PHP_SELF'] . "?success=1");
        exit;

    } catch (PDOException $e) {
        $error = "Error saving requisition: " . $e->getMessage();
    }
}

// ── FEEDBACK ─────────────────────────────────────────────
// FIXED: read from $_GET after the redirect, not $_POST
if (isset($_GET['success'])) $success = "Requisition raised successfully!";

// ── FETCH ITEMS FOR DROPDOWN ──────────────────────────────
$items = [];
try {
    $query = $conn->prepare("SELECT id, sku_code, item_name, buying_price FROM items ORDER BY item_name");
    $query->execute();
    $items = $query->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $error = "Error fetching items: " . $e->getMessage();
}

// Build a JS-safe map of item id → price for auto-filling price column
$itemPriceMap = [];
foreach ($items as $item) {
    $itemPriceMap[$item['id']] = $item['buying_price'];
}


$suppliers = [];
try {
    $stmt = $conn->prepare("SELECT * FROM suppliers ORDER BY company_name");
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
    <link rel="stylesheet" href="/think-twice/styles.css">
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600&family=DM+Mono&display=swap" rel="stylesheet">
    <title>Order Entry — Requisition</title>
<style>
    :root {
        --ink:     #1a1a2e;
        --ink-mid: #4a4a6a;
        --ink-dim: #9090b0;
        --surface: #f7f7fb;
        --card:    #ffffff;
        --accent:  #3d5af1;
        --accent2: #22c55e;
        --danger:  #ef4444;
        --border:  #e2e2ee;
        --shadow:  0 2px 16px rgba(61,90,241,0.07);
    }

    * { box-sizing: border-box; margin: 0; padding: 0; }

    body {
        font-family: 'DM Sans', sans-serif;
        background: var(--surface);
        color: var(--ink);
        min-height: 100vh;
    }

    .page-wrapper {
        max-width: 1100px;
        margin: 0 auto;
        padding: 32px 24px 80px;
    }

    /* ── Header ── */
    .page-header {
        display: flex;
        align-items: baseline;
        gap: 14px;
        margin-bottom: 28px;
    }
    .page-header h1 {
        font-size: 26px;
        font-weight: 600;
        letter-spacing: -0.5px;
    }
    .page-header span {
        font-family: 'DM Mono', monospace;
        font-size: 12px;
        color: var(--ink-dim);
        background: var(--border);
        padding: 3px 10px;
        border-radius: 20px;
    }

    /* ── Toast messages ── */
    .toast {
        display: flex;
        align-items: center;
        gap: 10px;
        padding: 14px 18px;
        border-radius: 10px;
        margin-bottom: 24px;
        font-weight: 500;
        font-size: 14px;
        animation: slideIn 0.3s ease;
    }
    .toast-success { background: #f0fdf4; border: 1px solid #bbf7d0; color: #166534; }
    .toast-error   { background: #fef2f2; border: 1px solid #fecaca; color: #991b1b; }
    @keyframes slideIn {
        from { opacity: 0; transform: translateY(-8px); }
        to   { opacity: 1; transform: translateY(0); }
    }

    /* ── Card ── */
    .card {
        background: var(--card);
        border: 1px solid var(--border);
        border-radius: 14px;
        box-shadow: var(--shadow);
        padding: 28px;
        margin-bottom: 24px;
    }
    .card-title {
        font-size: 13px;
        font-weight: 600;
        text-transform: uppercase;
        letter-spacing: 0.08em;
        color: var(--ink-dim);
        margin-bottom: 18px;
    }

    /* ── Form fields ── */
    .field-row {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 16px;
    }
    .field { display: flex; flex-direction: column; gap: 6px; }
    .field label {
        font-size: 13px;
        font-weight: 500;
        color: var(--ink-mid);
    }
    .field input,
    .field textarea,
    .field select {
        padding: 9px 12px;
        border: 1px solid var(--border);
        border-radius: 8px;
        font-family: 'DM Sans', sans-serif;
        font-size: 14px;
        color: var(--ink);
        background: var(--surface);
        transition: border-color 0.2s;
        outline: none;
    }
    .field input:focus,
    .field textarea:focus,
    .field select:focus {
        border-color: var(--accent);
        background: #fff;
    }
    .field textarea { resize: vertical; min-height: 72px; }

    /* ── Line items table ── */
    .table-wrap { overflow-x: auto; }
    table {
        width: 100%;
        border-collapse: collapse;
        font-size: 13.5px;
    }
    thead th {
        background: var(--ink);
        color: #fff;
        padding: 11px 12px;
        text-align: left;
        font-weight: 500;
        font-size: 12px;
        text-transform: uppercase;
        letter-spacing: 0.06em;
    }
    thead th:first-child { border-radius: 8px 0 0 0; }
    thead th:last-child  { border-radius: 0 8px 0 0; }

    tbody tr { border-bottom: 1px solid var(--border); }
    tbody tr:hover { background: var(--surface); }

    tbody td { padding: 8px; vertical-align: middle; }

    tbody td input,
    tbody td select {
        width: 100%;
        padding: 7px 9px;
        border: 1px solid var(--border);
        border-radius: 6px;
        font-family: 'DM Sans', monospace;
        font-size: 13px;
        background: #fff;
        color: var(--ink);
        outline: none;
        transition: border-color 0.2s;
    }
    tbody td input:focus,
    tbody td select:focus { border-color: var(--accent); }
    tbody td input[readonly] {
        background: var(--surface);
        color: var(--accent2);
        font-weight: 600;
        cursor: default;
    }

    /* ── Total footer row ── */
    .total-row td {
        padding: 12px;
        font-weight: 600;
        background: var(--surface);
        border-top: 2px solid var(--border);
    }

    /* ── Buttons ── */
    .btn {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        padding: 9px 18px;
        border-radius: 8px;
        font-family: 'DM Sans', sans-serif;
        font-size: 14px;
        font-weight: 500;
        border: none;
        cursor: pointer;
        transition: opacity 0.15s, transform 0.1s;
    }
    .btn:hover   { opacity: 0.88; }
    .btn:active  { transform: scale(0.97); }

    .btn-primary  { background: var(--accent);  color: #fff; }
    .btn-outline  { background: transparent; border: 1px solid var(--border); color: var(--ink-mid); }
    .btn-danger   { background: #fef2f2; color: var(--danger); border: 1px solid #fecaca; padding: 5px 10px; font-size: 12px; }
    .btn-add      { background: var(--surface); border: 1px dashed var(--accent); color: var(--accent); margin-top: 12px; }

    .form-actions {
        display: flex;
        gap: 12px;
        margin-top: 24px;
        justify-content: flex-end;
    }
</style>
</head>
<body>
<?php include __DIR__ . '/../navbar.php'; ?>

<div class="page-wrapper">

    <div class="page-header">
        <h1>New Requisition</h1>
        <span>Order Entry</span>
    </div>

    <!-- ── Feedback messages ── -->
    <?php if ($success): ?>
        <div class="toast toast-success">
            ✓ <?= htmlspecialchars($success) ?>
        </div>
    <?php endif; ?>
    <?php if ($error): ?>
        <div class="toast toast-error">
            ✗ <?= htmlspecialchars($error) ?>
        </div>
    <?php endif; ?>

    <form method="POST">

        <!-- ── Header details ── -->
        <div class="card">
            <p class="card-title">Requisition Details</p>
            <div class="field-row">
                <div class="field">
                    <label for="requisitionDate">Requisition Date *</label>
                    <input type="date" name="requisitionDate" id="requisitionDate"
                           value="<?= date('Y-m-d') ?>" required>
                </div>
                <div class="field">
                    <label for="requisitionDueDate">Due Date *</label>
                    <input type="date" name="requisitionDueDate" id="requisitionDueDate" required>
                </div>
            </div>
<div class="field">
                    <label for="requisitionDueDate">Supplier</label>
                   <select name="supplier" id="supplier">
    <option value="">-- Select Supplier --</option>
    <?php foreach($suppliers as $supplier): ?>
        <option value="<?= htmlspecialchars($supplier['id']) ?>">
            <?= htmlspecialchars($supplier['company_name']) ?>
        </option>
    <?php endforeach; ?>
</select>
                </div>

        </div>

        <!-- ── Line items ── -->
        <div class="card">
            <p class="card-title">Line Items</p>
            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th style="width:220px;">Item</th>
                            <th>Description</th>
                            <th style="width:100px;">Qty</th>
                            <th style="width:130px;">Unit Price (KSh)</th>
                            <th style="width:130px;">Line Total (KSh)</th>
                            <th style="width:60px;"></th>
                        </tr>
                    </thead>
                    <tbody id="tableBody">
                        <!-- First row rendered by PHP -->
                        <tr>
                            <td>
                                <select name="code[]" onchange="fillPrice(this)">
                                    <option value="">-- Select --</option>
                                    <?php foreach ($items as $item): ?>
                                        <option value="<?= $item['id'] ?>"
                                                data-price="<?= $item['buying_price'] ?>">
                                            <?= htmlspecialchars($item['item_name']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </td>
                            <td><input type="text" name="description[]" placeholder="Optional note"></td>
                            <td><input type="number" name="quantity[]" min="0" step="1"
                                       oninput="calculateTotal(this.closest('tr'))"></td>
                            <td><input type="number" name="price[]" min="0" step="0.01"
                                       oninput="calculateTotal(this.closest('tr'))"></td>
                            <td><input type="text" name="total[]" readonly placeholder="0.00"></td>
                            <td><button type="button" class="btn btn-danger"
                                        onclick="removeRow(this)">✕</button></td>
                        </tr>
                    </tbody>
                    <tfoot>
                        <tr class="total-row">
                            <td colspan="4" style="text-align:right; color: var(--ink-mid); font-size:13px;">
                                Grand Total
                            </td>
                            <td id="grandTotal" style="font-family:'DM Mono',monospace; color:var(--accent);">
                                KSh 0.00
                            </td>
                            <td></td>
                        </tr>
                    </tfoot>
                </table>
            </div>

            <button type="button" class="btn btn-add" onclick="addRow()">
                + Add Row
            </button>
        </div>

        <!-- ── Memo ── -->
        <div class="card">
            <p class="card-title">Memo</p>
            <div class="field">
                <textarea name="memo" placeholder="Any additional notes for this requisition..."></textarea>
            </div>
        </div>

        <!-- ── Actions ── -->
        <div class="form-actions">
            <a href="<?= $_SERVER['PHP_SELF'] ?>" class="btn btn-outline">Clear</a>
            <button type="submit" name="create-requisition" class="btn btn-primary">
                Submit Requisition
            </button>
        </div>

    </form>
</div>

<script>
// Item id → buying_price map built from PHP
const itemPrices = <?= json_encode($itemPriceMap) ?>;

// Build option HTML once, reuse when adding rows
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
        <td><input type="number" name="quantity[]" min="0" step="1"
                   oninput="calculateTotal(this.closest('tr'))"></td>
        <td><input type="number" name="price[]" min="0" step="0.01"
                   oninput="calculateTotal(this.closest('tr'))"></td>
        <td><input type="text" name="total[]" readonly placeholder="0.00"></td>
        <td><button type="button" class="btn btn-danger" onclick="removeRow(this)">✕</button></td>
    `;
    document.getElementById('tableBody').appendChild(row);
}

function removeRow(btn) {
    const rows = document.querySelectorAll('#tableBody tr');
    // Keep at least one row
    if (rows.length > 1) {
        btn.closest('tr').remove();
        updateGrandTotal();
    }
}

// When an item is selected, auto-fill its buying price
function fillPrice(select) {
    const price = itemPrices[select.value] || '';
    const row   = select.closest('tr');
    row.querySelector('[name="price[]"]').value = price;
    calculateTotal(row);
}

// Multiply qty × price and write to total field
function calculateTotal(row) {
    const qty   = parseFloat(row.querySelector('[name="quantity[]"]').value) || 0;
    const price = parseFloat(row.querySelector('[name="price[]"]').value)    || 0;
    row.querySelector('[name="total[]"]').value = (qty * price).toFixed(2);
    updateGrandTotal();
}

// Sum all line totals and display in footer
function updateGrandTotal() {
    let grand = 0;
    document.querySelectorAll('[name="total[]"]').forEach(input => {
        grand += parseFloat(input.value) || 0;
    });
    document.getElementById('grandTotal').textContent =
        'KSh ' + grand.toLocaleString('en-KE', { minimumFractionDigits: 2 });
}
</script>

</body>
</html>