<?php
require __DIR__ . '/../config/db.php';

// =====================================================================
// AJAX HANDLERS — before any HTML output
// =====================================================================
if ($_SERVER["REQUEST_METHOD"] === "POST") {

    if (isset($_POST['inline-edit'])) {
        header('Content-Type: application/json');
        $row_id = (int)($_POST['row_id'] ?? 0);
        $qty    = (int)($_POST['quantity'] ?? -1);
        $type   = $_POST['movementType'] ?? '';
        if ($qty < 0 || !in_array($type, ['IN', 'OUT'])) {
            echo json_encode(['success' => false, 'message' => 'Invalid data.']);
            exit;
        }
        try {
            $stmt = $conn->prepare("UPDATE stock_movements SET quantity = :qty, movement_type = :type WHERE id = :id");
            $stmt->execute(['qty' => $qty, 'type' => $type, 'id' => $row_id]);
            echo json_encode(['success' => true]);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
        exit;
    }

    if (isset($_POST['inline-delete'])) {
        header('Content-Type: application/json');
        $row_id = (int)($_POST['row_id'] ?? 0);
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

// =====================================================================
// HANDLE MAIN FORM SUBMISSION
// =====================================================================
$error   = '';
$success = '';

if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST['update-stock'])) {

    $item_id      = $_POST['itemSelectedId'] ?? null;
    $quantity     = (int)($_POST['quantity']  ?? 0);
    $movementType = $_POST['movementType']    ?? null;
    $isIroned     = isset($_POST['isIroned'])  ? 1 : 0;
    $isSteamed    = isset($_POST['isSteamed']) ? 1 : 0;
    $isHanged     = isset($_POST['isHanged'])  ? 1 : 0;

    if (!$item_id || !$movementType || $quantity <= 0) {
        $error = "All fields are required and quantity must be greater than 0.";
    } elseif (!in_array($movementType, ['IN', 'OUT', 'ADJUSTMENT'])) {
        $error = "Invalid movement type.";
    } else {
        try {
            $conn->beginTransaction();

            // ----------------------------------------------------------
            // 1. Fetch item details from items table
            // ----------------------------------------------------------
            $stmt = $conn->prepare("
                SELECT id, item_name, barcode, selling_price
                FROM items
                WHERE id = :id AND status = 'active'
            ");
            $stmt->execute(['id' => $item_id]);
            $itemRow = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$itemRow) throw new Exception("Item not found or inactive.");

            $itemName = $itemRow['item_name'];
            $barcode  = $itemRow['barcode'];

            // ----------------------------------------------------------
            // 2. Fetch the single existing row for this item (if any)
            // ----------------------------------------------------------
            $stmt2 = $conn->prepare("
                SELECT id, quantity FROM stock_movements WHERE barcode = :barcode LIMIT 1
            ");
            $stmt2->execute(['barcode' => $barcode]);
            $existing = $stmt2->fetch(PDO::FETCH_ASSOC);
            $currentQty = $existing ? (int)$existing['quantity'] : 0;

            // ----------------------------------------------------------
            // 3. Calculate new quantity based on movement type
            //    IN  → add to current
            //    OUT → subtract from current
            //    ADJUSTMENT → overwrite directly
            // ----------------------------------------------------------
            if ($movementType === 'ADJUSTMENT') {
                $newQty = $quantity;   // direct overwrite
            } elseif ($movementType === 'IN') {
                $newQty = $currentQty + $quantity;
            } else { // OUT
                $newQty = $currentQty - $quantity;
                if ($newQty < 0) throw new Exception("Not enough stock. Current stock: {$currentQty}.");
            }

            // ----------------------------------------------------------
            // 4. Fetch effective price (active price cycle or selling_price)
            // ----------------------------------------------------------
            $priceStmt = $conn->prepare("
                SELECT
                    CASE
                        WHEN pc.id IS NOT NULL
                             AND DATEDIFF(CURDATE(), pc.cycle_start) < pc.cycle_days
                        THEN GREATEST(0,
                                pc.base_price
                                - (DATEDIFF(CURDATE(), pc.cycle_start) * pc.daily_reduction)
                             )
                        ELSE COALESCE(i.selling_price, 0)
                    END AS effective_price
                FROM items i
                LEFT JOIN price_cycles pc ON pc.item_id = i.id AND pc.is_active = 1
                WHERE i.id = :item_id
                LIMIT 1
            ");
            $priceStmt->execute(['item_id' => $item_id]);
            $priceRow       = $priceStmt->fetch(PDO::FETCH_ASSOC);
            $effectivePrice = $priceRow ? (float)$priceRow['effective_price'] : (float)$itemRow['selling_price'];

            // ----------------------------------------------------------
            // 5. UPDATE existing row, or INSERT if this item has no row yet
            //    movement_type saved reflects the last operation performed
            // ----------------------------------------------------------
            if ($existing) {
                $conn->prepare("
                    UPDATE stock_movements
                    SET item_name     = :item_name,
                        quantity      = :qty,
                        movement_type = :type,
                        is_ironed     = :ironed,
                        is_steamed    = :steamed,
                        is_hanged     = :hanged,
                        price         = :price,
                        created_at    = NOW()
                    WHERE id = :id
                ")->execute([
                    'item_name' => $itemName,
                    'qty'       => $newQty,
                    'type'      => $movementType,
                    'ironed'    => $isIroned,
                    'steamed'   => $isSteamed,
                    'hanged'    => $isHanged,
                    'price'     => $effectivePrice,
                    'id'        => $existing['id'],
                ]);
                $action = match($movementType) {
                    'IN'         => "Added {$quantity} → new stock: {$newQty}",
                    'OUT'        => "Removed {$quantity} → new stock: {$newQty}",
                    'ADJUSTMENT' => "Adjusted to {$newQty}",
                };
            } else {
                if ($movementType === 'OUT') throw new Exception("Cannot stock out — no existing record for this item.");
                $conn->prepare("
                    INSERT INTO stock_movements
                        (item_name, barcode, quantity, movement_type,
                         is_ironed, is_steamed, is_hanged, price)
                    VALUES
                        (:item_name, :barcode, :qty, :type,
                         :ironed, :steamed, :hanged, :price)
                ")->execute([
                    'item_name' => $itemName,
                    'barcode'   => $barcode,
                    'qty'       => $newQty,
                    'type'      => $movementType,
                    'ironed'    => $isIroned,
                    'steamed'   => $isSteamed,
                    'hanged'    => $isHanged,
                    'price'     => $effectivePrice,
                ]);
                $action = "Stock created → quantity: {$newQty}";
            }

            $success = $action . " for \"{$itemName}\".";
            $conn->commit();

        } catch (Exception $e) {
            $conn->rollBack();
            $error = $e->getMessage();
        }
    }
}

// =====================================================================
// FETCH DROPDOWN ITEMS
// =====================================================================
$items = [];
try {
    $query = $conn->prepare("
        SELECT id, item_name, barcode, sku_code, unit, selling_price
        FROM items
        WHERE status = 'active'
        ORDER BY item_name
    ");
    $query->execute();
    $items = $query->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $error = "Error fetching items: " . $e->getMessage();
}

// =====================================================================
// FETCH STOCK MOVEMENTS — join items + price_cycles for live price
// =====================================================================
$movements = [];
try {
    $stmt = $conn->prepare("
        SELECT
            sm.id,
            sm.barcode,
            sm.quantity,
            sm.movement_type,
            sm.is_ironed,
            sm.is_steamed,
            sm.is_hanged,
            sm.created_at,

            -- Always pull item_name fresh from items table
            COALESCE(i.item_name, sm.item_name)  AS item_name,

            -- Effective price: active cycle > stored price > items.selling_price
            CASE
                WHEN pc.id IS NOT NULL
                     AND DATEDIFF(CURDATE(), pc.cycle_start) < pc.cycle_days
                THEN GREATEST(0,
                        pc.base_price
                        - (DATEDIFF(CURDATE(), pc.cycle_start) * pc.daily_reduction)
                     )
                ELSE COALESCE(sm.price, i.selling_price, 0)
            END AS effective_price,

            CASE
                WHEN pc.id IS NOT NULL
                     AND DATEDIFF(CURDATE(), pc.cycle_start) < pc.cycle_days
                THEN 1
                ELSE 0
            END AS is_cycle_price,

            GREATEST(0, pc.cycle_days - DATEDIFF(CURDATE(), pc.cycle_start))
                AS cycle_days_remaining

        FROM stock_movements sm

        -- Join items by barcode to get the authoritative item name + price
        LEFT JOIN items i
               ON i.barcode = sm.barcode
              AND i.status   = 'active'

        -- Join active price cycle for this item (if any)
        LEFT JOIN price_cycles pc
               ON pc.item_id  = i.id
              AND pc.is_active = 1

        ORDER BY sm.created_at DESC
        LIMIT 100
    ");
    $stmt->execute();
    $movements = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $error = "Error fetching movements: " . $e->getMessage();
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

        h2 { font-size: 1.6rem; font-weight: 700; color: #1a1a2e; margin-bottom: 24px; display: flex; align-items: center; gap: 10px; }
        h2::before { content: "📦"; }
        h3 { font-size: 1.1rem; font-weight: 600; color: #1a1a2e; margin-bottom: 16px; display: flex; align-items: center; gap: 8px; }
        h3::before { content: "📋"; }

        .card {
            background: #fff;
            border-radius: 12px;
            box-shadow: 0 2px 12px rgba(0,0,0,0.07);
            padding: 28px 32px;
            max-width: 680px;
            margin-bottom: 32px;
        }

        .alert { padding: 12px 16px; border-radius: 8px; margin-bottom: 20px; font-size: 0.9rem; font-weight: 500; }
        .alert-error   { background: #fef2f2; border-left: 4px solid #ef4444; color: #b91c1c; }
        .alert-success { background: #f0fdf4; border-left: 4px solid #22c55e; color: #15803d; }

        .form-group { margin-bottom: 18px; }

        label { display: block; font-size: 0.85rem; font-weight: 600; color: #555; margin-bottom: 6px; text-transform: uppercase; letter-spacing: 0.03em; }

        select, input[type="number"] {
            width: 100%; padding: 10px 14px; border: 1.5px solid #dde1e9; border-radius: 8px;
            font-size: 0.95rem; color: #333; background: #fafafa; transition: border-color 0.2s; appearance: none;
        }
        select:focus, input[type="number"]:focus { outline: none; border-color: #6366f1; background: #fff; }

        .item-meta { margin-top: 8px; padding: 8px 12px; background: #f5f6ff; border-radius: 6px; font-size: 0.82rem; color: #555; display: none; }
        .item-meta span { margin-right: 14px; }
        .item-meta strong { color: #6366f1; }

        .checkboxes { display: flex; gap: 20px; flex-wrap: wrap; }
        .checkbox-label { display: flex; align-items: center; gap: 8px; font-size: 0.9rem; font-weight: 500; color: #444; cursor: pointer; text-transform: none; letter-spacing: 0; }
        .checkbox-label input[type="checkbox"] { width: 17px; height: 17px; accent-color: #6366f1; cursor: pointer; }

        .btn { display: inline-flex; align-items: center; gap: 8px; padding: 11px 28px; border: none; border-radius: 8px; font-size: 0.95rem; font-weight: 600; cursor: pointer; transition: background 0.2s, transform 0.1s; }
        .btn-primary { background: #6366f1; color: #fff; }
        .btn-primary:hover { background: #4f46e5; transform: translateY(-1px); }

        .table-wrapper { max-width: 1200px; overflow-x: auto; background: #fff; border-radius: 12px; box-shadow: 0 2px 12px rgba(0,0,0,0.07); padding: 28px 32px; }

        table { width: 100%; border-collapse: collapse; font-size: 0.88rem; }
        thead th { background: #f5f6ff; color: #6366f1; font-weight: 700; font-size: 0.78rem; text-transform: uppercase; letter-spacing: 0.05em; padding: 12px 14px; text-align: left; white-space: nowrap; }
        tbody tr { border-bottom: 1px solid #f0f0f5; transition: background 0.15s; }
        tbody tr:hover { background: #fafbff; }
        tbody td { padding: 11px 14px; vertical-align: middle; }

        .badge { display: inline-block; padding: 3px 10px; border-radius: 20px; font-size: 0.75rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.04em; }
        .badge-in  { background: #dcfce7; color: #15803d; }
        .badge-out { background: #fee2e2; color: #b91c1c; }
        .badge-adj { background: #fef9c3; color: #92400e; }

        .price-cycle { color: #7c3aed; font-weight: 700; }
        .cycle-tag {
            display: inline-block; margin-left: 6px; padding: 1px 7px;
            background: #ede9fe; color: #7c3aed; border-radius: 20px;
            font-size: 0.7rem; font-weight: 700; vertical-align: middle;
        }

        .check-icon { color: #22c55e; font-size: 1rem; }
        .cross-icon { color: #d1d5db; font-size: 1rem; }
        .barcode-text { font-family: monospace; font-size: 0.82rem; color: #777; }
        .empty-state { text-align: center; padding: 40px 0; color: #aaa; font-size: 0.9rem; }

        .btn-edit { padding: 4px 12px; font-size: 0.78rem; border: none; border-radius: 6px; cursor: pointer; background: #e0e7ff; color: #4338ca; font-weight: 600; transition: background 0.15s; }
        .btn-edit:hover { background: #c7d2fe; }
        .btn-delete { padding: 4px 12px; font-size: 0.78rem; border: none; border-radius: 6px; cursor: pointer; background: #fee2e2; color: #b91c1c; font-weight: 600; transition: background 0.15s; margin-left: 6px; }
        .btn-delete:hover { background: #fecaca; }

        .inline-edit-input { width: 70px; padding: 4px 8px; border: 1.5px solid #6366f1; border-radius: 6px; font-size: 0.85rem; text-align: center; }
    </style>
</head>
<body>

<h2>Warehouse Stock Management</h2>

<div class="card">
    <?php if ($error): ?><div class="alert alert-error">⚠️ <?= htmlspecialchars($error) ?></div><?php endif; ?>
    <?php if ($success): ?><div class="alert alert-success">✅ <?= htmlspecialchars($success) ?></div><?php endif; ?>

    <form method="POST">

        <div class="form-group">
            <label for="itemSelectedId">Item</label>
            <select name="itemSelectedId" id="itemSelectedId" required onchange="showItemMeta(this)">
                <option value="">— Select an item —</option>
                <?php foreach ($items as $item): ?>
                    <option
                        value="<?= $item['id'] ?>"
                        data-barcode="<?= htmlspecialchars($item['barcode']      ?? '—') ?>"
                        data-sku="<?= htmlspecialchars($item['sku_code']         ?? '—') ?>"
                        data-unit="<?= htmlspecialchars($item['unit']            ?? '—') ?>"
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

        <div class="form-group">
            <label for="quantity">Quantity</label>
            <input type="number" name="quantity" id="quantity" min="1" placeholder="Enter quantity"
                value="<?= htmlspecialchars($_POST['quantity'] ?? '') ?>" required>
        </div>

        <div class="form-group">
            <label for="movementType">Movement Type</label>
            <select name="movementType" id="movementType" required>
                <option value="">— Select type —</option>
                <option value="IN"         <?= (($_POST['movementType'] ?? '') === 'IN')         ? 'selected' : '' ?>>Stock In</option>
                <option value="OUT"        <?= (($_POST['movementType'] ?? '') === 'OUT')        ? 'selected' : '' ?>>Stock Out</option>
                <option value="ADJUSTMENT" <?= (($_POST['movementType'] ?? '') === 'ADJUSTMENT') ? 'selected' : '' ?>>Adjustment</option>
            </select>
        </div>

        <div class="form-group">
            <label>Attributes</label>
            <div class="checkboxes">
                <label class="checkbox-label">
                    <input type="checkbox" name="isIroned"  value="1" <?= isset($_POST['isIroned'])  ? 'checked' : '' ?>> 🧺 Ironed
                </label>
                <label class="checkbox-label">
                    <input type="checkbox" name="isSteamed" value="1" <?= isset($_POST['isSteamed']) ? 'checked' : '' ?>> 💨 Steamed
                </label>
                <label class="checkbox-label">
                    <input type="checkbox" name="isHanged"  value="1" <?= isset($_POST['isHanged'])  ? 'checked' : '' ?>> 🪝 Hanged
                </label>
            </div>
        </div>

        <button type="submit" name="update-stock" class="btn btn-primary">➕ Update Stock</button>
    </form>
</div>

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
                <th>Price Today</th>
                <th>Type</th>
                <th>Ironed</th>
                <th>Steamed</th>
                <th>Hanged</th>
                <th>Date &amp; Time</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($movements as $i => $m): ?>
            <tr id="row-<?= $m['id'] ?>">
                <td><?= $i + 1 ?></td>
                <td><strong><?= htmlspecialchars($m['item_name']) ?></strong></td>
                <td class="barcode-text"><?= htmlspecialchars($m['barcode'] ?? '—') ?></td>

                <td id="qty-<?= $m['id'] ?>">
                    <strong><?= number_format($m['quantity']) ?></strong>
                </td>

                <td>
                    <?php if ($m['is_cycle_price']): ?>
                        <span class="price-cycle">KES <?= number_format((float)$m['effective_price'], 2) ?></span>
                        <span class="cycle-tag" title="<?= (int)$m['cycle_days_remaining'] ?> days left in cycle">🔄 Cycle</span>
                    <?php else: ?>
                        KES <?= number_format((float)$m['effective_price'], 2) ?>
                    <?php endif; ?>
                </td>

                <td id="type-<?= $m['id'] ?>">
                    <?php
                        $type       = $m['movement_type'];
                        $badgeClass = match ($type) { 'IN' => 'badge-in', 'OUT' => 'badge-out', default => 'badge-adj' };
                    ?>
                    <span class="badge <?= $badgeClass ?>"><?= htmlspecialchars($type) ?></span>
                </td>

                <td><?= $m['is_ironed']  ? '<span class="check-icon">✔</span>' : '<span class="cross-icon">—</span>' ?></td>
                <td><?= $m['is_steamed'] ? '<span class="check-icon">✔</span>' : '<span class="cross-icon">—</span>' ?></td>
                <td><?= $m['is_hanged']  ? '<span class="check-icon">✔</span>' : '<span class="cross-icon">—</span>' ?></td>

                <td><?= htmlspecialchars(date('d M Y, H:i', strtotime($m['created_at']))) ?></td>

                <td id="actions-<?= $m['id'] ?>">
                    <button class="btn-edit"
                        onclick="startEdit(<?= $m['id'] ?>, <?= (int)$m['quantity'] ?>, '<?= htmlspecialchars($m['movement_type'], ENT_QUOTES) ?>')">
                        ✏️ Edit
                    </button>
                    <button class="btn-delete" onclick="deleteRow(<?= $m['id'] ?>)">🗑️ Delete</button>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>
</div>

<script>
    function showItemMeta(select) {
        const opt  = select.options[select.selectedIndex];
        const meta = document.getElementById('itemMeta');
        if (!opt.dataset.barcode || opt.dataset.barcode === '—') { meta.style.display = 'none'; return; }
        document.getElementById('metaBarcode').textContent = opt.dataset.barcode || '—';
        document.getElementById('metaSku').textContent     = opt.dataset.sku     || '—';
        document.getElementById('metaUnit').textContent    = opt.dataset.unit    || '—';
        document.getElementById('metaPrice').textContent   = 'KES ' + (opt.dataset.price || '0.00');
        meta.style.display = 'block';
    }

    window.addEventListener('DOMContentLoaded', () => {
        const sel = document.getElementById('itemSelectedId');
        if (sel && sel.value) showItemMeta(sel);
    });

    function startEdit(id, currentQty, currentType) {
        document.getElementById(`qty-${id}`).innerHTML =
            `<input class="inline-edit-input" id="edit-qty-${id}" type="number" min="0" value="${currentQty}">`;
        document.getElementById(`type-${id}`).innerHTML = `
            <select id="edit-type-${id}" style="padding:4px 8px;border-radius:6px;border:1.5px solid #6366f1;font-size:0.85rem;">
                <option value="IN"  ${currentType === 'IN'  ? 'selected' : ''}>IN</option>
                <option value="OUT" ${currentType === 'OUT' ? 'selected' : ''}>OUT</option>
            </select>`;
        document.getElementById(`actions-${id}`).innerHTML = `
            <button class="btn-edit" onclick="saveEdit(${id})">💾 Save</button>
            <button class="btn-delete" onclick="location.reload()">✖ Cancel</button>`;
    }

    function saveEdit(id) {
        const qty  = document.getElementById(`edit-qty-${id}`).value;
        const type = document.getElementById(`edit-type-${id}`).value;
        fetch('', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: `inline-edit=1&row_id=${id}&quantity=${encodeURIComponent(qty)}&movementType=${encodeURIComponent(type)}`
        })
        .then(r => r.json())
        .then(data => { if (data.success) location.reload(); else alert('Error: ' + data.message); })
        .catch(() => alert('Network error. Please try again.'));
    }

    function deleteRow(id) {
        if (!confirm('Delete this stock record?')) return;
        fetch('', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: `inline-delete=1&row_id=${id}`
        })
        .then(r => r.json())
        .then(data => { if (data.success) document.getElementById(`row-${id}`).remove(); else alert('Error: ' + data.message); })
        .catch(() => alert('Network error. Please try again.'));
    }
</script>

</body>
</html>