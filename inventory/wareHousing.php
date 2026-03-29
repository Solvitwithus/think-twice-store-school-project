<?php
require __DIR__ . '/../config/db.php';

$error   = '';
$success = '';

// ===================== HANDLE FORM =====================
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST['update-stock'])) {

    $item_id      = $_POST['itemSelectedId']  ?? null;
    $quantity     = (int)($_POST['quantity']   ?? 0);
    $movementType = $_POST['movementType']     ?? null;
    $isIroned     = isset($_POST['isIroned'])  ? 1 : 0;
    $isSteamed    = isset($_POST['isSteamed']) ? 1 : 0;
    $isHanged     = isset($_POST['isHanged'])  ? 1 : 0;
    $unitPrice    = (float)($_POST['unit_price'] ?? 0);

    if (!$item_id || !$movementType || $quantity <= 0) {
        $error = "All fields are required and quantity must be greater than 0.";
    } else {

        $allowed = ['IN', 'OUT', 'ADJUSTMENT'];
        if (!in_array($movementType, $allowed)) {
            $error = "Invalid movement type.";
        } else {

            try {
                $conn->beginTransaction();

                // ── Pull item_name + barcode from items table (just for lookup) ──
                $stmt = $conn->prepare("SELECT item_name, barcode, selling_price FROM items WHERE id = :id AND status = 'active'");
                $stmt->execute(['id' => $item_id]);
                $itemRow = $stmt->fetch(PDO::FETCH_ASSOC);

                if (!$itemRow) {
                    throw new Exception("Item not found or inactive.");
                }

                $itemName  = $itemRow['item_name'];
                $barcode   = $itemRow['barcode'];
                // Use submitted price if provided, otherwise fall back to selling_price
                if ($unitPrice <= 0) $unitPrice = (float)$itemRow['selling_price'];

                // ── Get current stock directly from stock_movements (no JOIN needed) ──
                $stmt = $conn->prepare("
                    SELECT 
                        id,
                        quantity,
                        SUM(
                            CASE
                                WHEN movement_type = 'IN'  THEN quantity
                                WHEN movement_type = 'OUT' THEN -quantity
                                ELSE 0
                            END
                        ) AS net_stock
                    FROM stock_movements
                    WHERE barcode = :barcode
                    LIMIT 1
                ");
                $stmt->execute(['barcode' => $barcode]);
                $existing   = $stmt->fetch(PDO::FETCH_ASSOC);
                $currentQty = (int)($existing['net_stock'] ?? 0);

                // ── Resolve ADJUSTMENT → IN or OUT ──
                if ($movementType === "ADJUSTMENT") {
                    $diff = $quantity - $currentQty;
                    if ($diff === 0) {
                        throw new Exception("Adjustment equals current stock — nothing to change.");
                    }
                    $movementType = $diff > 0 ? "IN" : "OUT";
                    $quantity     = abs($diff);
                }

                if ($existing && $existing['id']) {
                    // ── Row exists — update quantity ──
                    $newQty = match ($movementType) {
                        'IN'  => $existing['quantity'] + $quantity,
                        'OUT' => $existing['quantity'] - $quantity,
                        default => $quantity
                    };

                    if ($newQty < 0) {
                        throw new Exception("Not enough stock. Current stock: {$existing['quantity']}.");
                    }

                    $update = $conn->prepare("
                        UPDATE stock_movements
                        SET quantity      = :qty,
                            movement_type = :type,
                            unit_price    = :price,
                            is_ironed     = :ironed,
                            is_steamed    = :steamed,
                            is_hanged     = :hanged
                        WHERE id = :id
                    ");
                    $update->execute([
                        'qty'     => $newQty,
                        'type'    => $movementType,
                        'price'   => $unitPrice,
                        'ironed'  => $isIroned,
                        'steamed' => $isSteamed,
                        'hanged'  => $isHanged,
                        'id'      => $existing['id'],
                    ]);
                    $success = "Stock updated — new quantity: {$newQty}.";

                } else {
                    // ── No existing row — insert fresh (item_name + barcode stored directly) ──
                    if ($movementType === 'OUT') {
                        throw new Exception("Cannot stock out — no existing record for this item.");
                    }

                    $insert = $conn->prepare("
                        INSERT INTO stock_movements 
                            (item_name, barcode, quantity, movement_type, unit_price, is_ironed, is_steamed, is_hanged)
                        VALUES 
                            (:item_name, :barcode, :qty, :type, :price, :ironed, :steamed, :hanged)
                    ");
                    $insert->execute([
                        'item_name' => $itemName,
                        'barcode'   => $barcode,
                        'qty'       => $quantity,
                        'type'      => $movementType,
                        'price'     => $unitPrice,
                        'ironed'    => $isIroned,
                        'steamed'   => $isSteamed,
                        'hanged'    => $isHanged,
                    ]);
                    $success = "Stock added successfully.";
                }

                $conn->commit();

            } catch (Exception $e) {
                $conn->rollBack();
                $error = $e->getMessage();
            }
        }
    }
}

// ===================== FETCH ITEMS (for dropdown only) =====================
$items = [];
try {
    $query = $conn->prepare("SELECT id, item_name, barcode, sku_code, unit, selling_price FROM items WHERE status = 'active' ORDER BY item_name");
    $query->execute();
    $items = $query->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $error = "Error fetching items.";
}

// ===================== FETCH MOVEMENTS (fully from stock_movements — no JOIN) =====================
$movements = [];
try {
    $stmt = $conn->prepare("
        SELECT id, item_name, barcode, quantity, unit_price, movement_type,
               is_ironed, is_steamed, is_hanged, created_at
        FROM stock_movements
        ORDER BY created_at DESC
        LIMIT 100
    ");
    $stmt->execute();
    $movements = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $error = "Error fetching movements.";
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Stock Movement</title>
    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: #f0f2f5;
            color: #333;
            min-height: 100vh;
            padding: 30px 20px;
        }

        h2 {
            font-size: 1.6rem;
            font-weight: 700;
            color: #1a1a2e;
            margin-bottom: 24px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        h2::before { content: "📦"; }

        h3 {
            font-size: 1.1rem;
            font-weight: 600;
            color: #1a1a2e;
            margin-bottom: 16px;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        h3::before { content: "📋"; }

        .card {
            background: #fff;
            border-radius: 12px;
            box-shadow: 0 2px 12px rgba(0,0,0,0.07);
            padding: 28px 32px;
            max-width: 680px;
            margin-bottom: 32px;
        }

        .alert {
            padding: 12px 16px;
            border-radius: 8px;
            margin-bottom: 20px;
            font-size: 0.9rem;
            font-weight: 500;
        }
        .alert-error   { background: #fef2f2; border-left: 4px solid #ef4444; color: #b91c1c; }
        .alert-success { background: #f0fdf4; border-left: 4px solid #22c55e; color: #15803d; }

        .form-group { margin-bottom: 18px; }

        label {
            display: block;
            font-size: 0.85rem;
            font-weight: 600;
            color: #555;
            margin-bottom: 6px;
            text-transform: uppercase;
            letter-spacing: 0.03em;
        }

        select, input[type="number"] {
            width: 100%;
            padding: 10px 14px;
            border: 1.5px solid #dde1e9;
            border-radius: 8px;
            font-size: 0.95rem;
            color: #333;
            background: #fafafa;
            transition: border-color 0.2s;
            appearance: none;
        }
        select:focus, input[type="number"]:focus {
            outline: none;
            border-color: #6366f1;
            background: #fff;
        }

        .item-meta {
            margin-top: 8px;
            padding: 8px 12px;
            background: #f5f6ff;
            border-radius: 6px;
            font-size: 0.82rem;
            color: #555;
            display: none;
        }
        .item-meta span { margin-right: 14px; }
        .item-meta strong { color: #6366f1; }

        .checkboxes {
            display: flex;
            gap: 20px;
            flex-wrap: wrap;
        }
        .checkbox-label {
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 0.9rem;
            font-weight: 500;
            color: #444;
            cursor: pointer;
            text-transform: none;
            letter-spacing: 0;
        }
        .checkbox-label input[type="checkbox"] {
            width: 17px;
            height: 17px;
            accent-color: #6366f1;
            cursor: pointer;
        }

        .btn {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 11px 28px;
            border: none;
            border-radius: 8px;
            font-size: 0.95rem;
            font-weight: 600;
            cursor: pointer;
            transition: background 0.2s, transform 0.1s;
        }
        .btn-primary { background: #6366f1; color: #fff; }
        .btn-primary:hover { background: #4f46e5; transform: translateY(-1px); }

        /* ── Table ── */
        .table-wrapper {
            max-width: 1100px;
            overflow-x: auto;
            background: #fff;
            border-radius: 12px;
            box-shadow: 0 2px 12px rgba(0,0,0,0.07);
            padding: 28px 32px;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.88rem;
        }
        thead th {
            background: #f5f6ff;
            color: #6366f1;
            font-weight: 700;
            font-size: 0.78rem;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            padding: 12px 14px;
            text-align: left;
            white-space: nowrap;
        }
        tbody tr { border-bottom: 1px solid #f0f0f5; transition: background 0.15s; }
        tbody tr:hover { background: #fafbff; }
        tbody td { padding: 11px 14px; vertical-align: middle; }

        .badge {
            display: inline-block;
            padding: 3px 10px;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.04em;
        }
        .badge-in   { background: #dcfce7; color: #15803d; }
        .badge-out  { background: #fee2e2; color: #b91c1c; }
        .badge-adj  { background: #fef9c3; color: #92400e; }

        .check-icon { color: #22c55e; font-size: 1rem; }
        .cross-icon { color: #d1d5db; font-size: 1rem; }

        .barcode-text { font-family: monospace; font-size: 0.82rem; color: #777; }

        .empty-state {
            text-align: center;
            padding: 40px 0;
            color: #aaa;
            font-size: 0.9rem;
        }

        /* ── Inline edit ── */
        .btn-edit {
            padding: 4px 12px;
            font-size: 0.78rem;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            background: #e0e7ff;
            color: #4338ca;
            font-weight: 600;
            transition: background 0.15s;
        }
        .btn-edit:hover { background: #c7d2fe; }

        .btn-delete {
            padding: 4px 12px;
            font-size: 0.78rem;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            background: #fee2e2;
            color: #b91c1c;
            font-weight: 600;
            transition: background 0.15s;
            margin-left: 6px;
        }
        .btn-delete:hover { background: #fecaca; }

        .inline-edit-input {
            width: 70px;
            padding: 4px 8px;
            border: 1.5px solid #6366f1;
            border-radius: 6px;
            font-size: 0.85rem;
            text-align: center;
        }
    </style>
</head>
<body>

<h2>Warehouse Stock Management</h2>

<!-- ── FORM CARD ── -->
<div class="card">

    <?php if ($error): ?>
        <div class="alert alert-error">⚠️ <?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <?php if ($success): ?>
        <div class="alert alert-success">✅ <?= htmlspecialchars($success) ?></div>
    <?php endif; ?>

    <form method="POST">

        <!-- ITEM SELECT (items table used only for lookup) -->
        <div class="form-group">
            <label for="itemSelectedId">Item</label>
            <select name="itemSelectedId" id="itemSelectedId" required onchange="showItemMeta(this)">
                <option value="">— Select an item —</option>
                <?php foreach ($items as $item): ?>
                    <option
                        value="<?= $item['id'] ?>"
                        data-barcode="<?= htmlspecialchars($item['barcode'] ?? '—') ?>"
                        data-sku="<?= htmlspecialchars($item['sku_code'] ?? '—') ?>"
                        data-unit="<?= htmlspecialchars($item['unit'] ?? '—') ?>"
                        data-price="<?= number_format((float)$item['selling_price'], 2) ?>"
                        <?= (isset($_POST['itemSelectedId']) && $_POST['itemSelectedId'] == $item['id']) ? 'selected' : '' ?>
                    >
                        <?= htmlspecialchars($item['item_name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <div class="item-meta" id="itemMeta">
                <span>🔖 <strong>Barcode:</strong> <span id="metaBarcode"></span></span>
                <span>📊 <strong>SKU:</strong> <span id="metaSku"></span></span>
                <span>📐 <strong>Unit:</strong> <span id="metaUnit"></span></span>
                <span>💰 <strong>Selling Price:</strong> <span id="metaPrice"></span></span>
            </div>
        </div>

        <!-- QUANTITY -->
        <div class="form-group">
            <label for="quantity">Quantity</label>
            <input
                type="number"
                name="quantity"
                id="quantity"
                min="1"
                placeholder="Enter quantity"
                value="<?= htmlspecialchars($_POST['quantity'] ?? '') ?>"
                required
            >
        </div>

        <!-- UNIT PRICE -->
        <div class="form-group">
            <label for="unit_price">Unit Price (KES)</label>
            <input
                type="number"
                name="unit_price"
                id="unit_price"
                min="0"
                step="0.01"
                placeholder="Auto-filled from selling price"
                value="<?= htmlspecialchars($_POST['unit_price'] ?? '') ?>"
            >
        </div>

        <!-- MOVEMENT TYPE -->
        <div class="form-group">
            <label for="movementType">Movement Type</label>
            <select name="movementType" id="movementType" required>
                <option value="">— Select type —</option>
                <option value="IN"         <?= (($_POST['movementType'] ?? '') === 'IN')         ? 'selected' : '' ?>>Stock In</option>
                <option value="OUT"        <?= (($_POST['movementType'] ?? '') === 'OUT')        ? 'selected' : '' ?>>Stock Out</option>
                <option value="ADJUSTMENT" <?= (($_POST['movementType'] ?? '') === 'ADJUSTMENT') ? 'selected' : '' ?>>Adjustment</option>
            </select>
        </div>

        <!-- CHECKBOXES -->
        <div class="form-group">
            <label>Attributes</label>
            <div class="checkboxes">
                <label class="checkbox-label">
                    <input type="checkbox" name="isIroned"  value="1" <?= isset($_POST['isIroned'])  ? 'checked' : '' ?>>
                    🧺 Ironed
                </label>
                <label class="checkbox-label">
                    <input type="checkbox" name="isSteamed" value="1" <?= isset($_POST['isSteamed']) ? 'checked' : '' ?>>
                    💨 Steamed
                </label>
                <label class="checkbox-label">
                    <input type="checkbox" name="isHanged"  value="1" <?= isset($_POST['isHanged'])  ? 'checked' : '' ?>>
                    🪝 Hanged
                </label>
            </div>
        </div>

        <button type="submit" name="update-stock" class="btn btn-primary">
            ➕ Update Stock
        </button>

    </form>
</div>

<!-- ── MOVEMENTS TABLE ── -->
<div class="table-wrapper">
    <h3>Current Inventory / Stock Movements</h3>

    <?php if (empty($movements)): ?>
        <div class="empty-state">No stock movements recorded yet.</div>
    <?php else: ?>
    <table>
        <thead>
            <tr>
                <th>#</th>
                <th>Item</th>
                <th>Barcode</th>
                <th>Qty</th>
                <th>Unit Price</th>
                <th>Type</th>
                <th>Ironed</th>
                <th>Steamed</th>
                <th>Hanged</th>
                <th>Date & Time</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($movements as $i => $m): ?>
            <tr id="row-<?= $m['id'] ?>">
                <td><?= $i + 1 ?></td>
                <td><strong><?= htmlspecialchars($m['item_name']) ?></strong></td>
                <td class="barcode-text"><?= htmlspecialchars($m['barcode'] ?? '—') ?></td>

                <!-- Quantity — switches to input on Edit -->
                <td id="qty-<?= $m['id'] ?>"><strong><?= number_format($m['quantity']) ?></strong></td>

                <!-- Unit Price — switches to input on Edit -->
                <td id="price-<?= $m['id'] ?>">KES <?= number_format((float)($m['unit_price'] ?? 0), 2) ?></td>

                <!-- Type badge -->
                <td id="type-<?= $m['id'] ?>">
                    <?php
                        $type      = $m['movement_type'];
                        $badgeClass = match ($type) {
                            'IN'    => 'badge-in',
                            'OUT'   => 'badge-out',
                            default => 'badge-adj'
                        };
                    ?>
                    <span class="badge <?= $badgeClass ?>"><?= htmlspecialchars($type) ?></span>
                </td>

                <td><?= $m['is_ironed']  ? '<span class="check-icon">✔</span>' : '<span class="cross-icon">—</span>' ?></td>
                <td><?= $m['is_steamed'] ? '<span class="check-icon">✔</span>' : '<span class="cross-icon">—</span>' ?></td>
                <td><?= $m['is_hanged']  ? '<span class="check-icon">✔</span>' : '<span class="cross-icon">—</span>' ?></td>
                <td><?= htmlspecialchars(date('d M Y, H:i', strtotime($m['created_at']))) ?></td>

                <!-- Actions -->
                <td id="actions-<?= $m['id'] ?>">
                    <button class="btn-edit"   onclick="startEdit(<?= $m['id'] ?>, <?= $m['quantity'] ?>, '<?= $m['movement_type'] ?>', <?= (float)($m['unit_price'] ?? 0) ?>)">✏️ Edit</button>
                    <button class="btn-delete" onclick="deleteRow(<?= $m['id'] ?>)">🗑️ Delete</button>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>
</div>

<script>
    // ── Dropdown meta display ──
    function showItemMeta(select) {
        const opt     = select.options[select.selectedIndex];
        const meta    = document.getElementById('itemMeta');
        const barcode = opt.dataset.barcode;
        if (!barcode) { meta.style.display = 'none'; return; }
        document.getElementById('metaBarcode').textContent = opt.dataset.barcode || '—';
        document.getElementById('metaSku').textContent     = opt.dataset.sku     || '—';
        document.getElementById('metaUnit').textContent    = opt.dataset.unit    || '—';
        document.getElementById('metaPrice').textContent   = 'KES ' + (opt.dataset.price || '0.00');
        // Pre-fill the price input with selling_price
        document.getElementById('unit_price').value = opt.dataset.price || '';
        meta.style.display = 'block';
    }

    window.addEventListener('DOMContentLoaded', () => {
        const sel = document.getElementById('itemSelectedId');
        if (sel && sel.value) showItemMeta(sel);
    });

    // ── Inline edit ──
    function startEdit(id, currentQty, currentType, currentPrice) {
        const qtyCell     = document.getElementById(`qty-${id}`);
        const priceCell   = document.getElementById(`price-${id}`);
        const typeCell    = document.getElementById(`type-${id}`);
        const actionsCell = document.getElementById(`actions-${id}`);

        qtyCell.innerHTML = `<input class="inline-edit-input" id="edit-qty-${id}" type="number" min="0" value="${currentQty}">`;

        priceCell.innerHTML = `<input class="inline-edit-input" id="edit-price-${id}" type="number" min="0" step="0.01" style="width:90px" value="${currentPrice}">`;

        typeCell.innerHTML = `
            <select id="edit-type-${id}" style="padding:4px 8px;border-radius:6px;border:1.5px solid #6366f1;font-size:0.85rem;">
                <option value="IN"  ${currentType==='IN'  ? 'selected':''}>IN</option>
                <option value="OUT" ${currentType==='OUT' ? 'selected':''}>OUT</option>
            </select>`;

        actionsCell.innerHTML = `
            <button class="btn-edit"   onclick="saveEdit(${id})">💾 Save</button>
            <button class="btn-delete" onclick="location.reload()">✖ Cancel</button>`;
    }

    function saveEdit(id) {
        const qty   = document.getElementById(`edit-qty-${id}`).value;
        const price = document.getElementById(`edit-price-${id}`).value;
        const type  = document.getElementById(`edit-type-${id}`).value;

        fetch('', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: `inline-edit=1&row_id=${id}&quantity=${qty}&unit_price=${price}&movementType=${type}`
        })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                location.reload();
            } else {
                alert('Error: ' + data.message);
            }
        });
    }

    function deleteRow(id) {
        if (!confirm('Delete this stock record?')) return;
        fetch('', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: `inline-delete=1&row_id=${id}`
        })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                document.getElementById(`row-${id}`).remove();
            } else {
                alert('Error: ' + data.message);
            }
        });
    }
</script>

<?php
// ── Handle inline AJAX edits / deletes (must be before any HTML output in production,
//    but works here because fetch() doesn't parse HTML — just the JSON response) ──
if ($_SERVER["REQUEST_METHOD"] === "POST") {

    // Inline edit
    if (isset($_POST['inline-edit'])) {
        header('Content-Type: application/json');
        $row_id = (int)$_POST['row_id'];
        $qty    = (int)$_POST['quantity'];
        $price  = (float)($_POST['unit_price'] ?? 0);
        $type   = $_POST['movementType'];

        if ($qty < 0 || !in_array($type, ['IN', 'OUT'])) {
            echo json_encode(['success' => false, 'message' => 'Invalid data.']);
            exit;
        }
        try {
            $stmt = $conn->prepare("UPDATE stock_movements SET quantity = :qty, unit_price = :price, movement_type = :type WHERE id = :id");
            $stmt->execute(['qty' => $qty, 'price' => $price, 'type' => $type, 'id' => $row_id]);
            echo json_encode(['success' => true]);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
        exit;
    }

    // Inline delete
    if (isset($_POST['inline-delete'])) {
        header('Content-Type: application/json');
        $row_id = (int)$_POST['row_id'];
        try {
            $stmt = $conn->prepare("DELETE FROM stock_movements WHERE id = :id");
            $stmt->execute(['id' => $row_id]);
            echo json_encode(['success' => true]);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
        exit;
    }
}
?>
</body>
</html>