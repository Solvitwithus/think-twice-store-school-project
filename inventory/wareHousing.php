<?php 
require __DIR__ . '/../config/db.php';

$error = '';
$success = '';

// ===================== HANDLE FORM =====================
if($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST['update-stock'])){

    $item_id      = $_POST['itemSelectedId'] ?? null;
    $quantity     = (int)($_POST['quantity'] ?? 0);
    $movementType = $_POST['movementType'] ?? null;
    $isIroned     = isset($_POST['isIroned'])  ? 1 : 0;
    $isSteamed    = isset($_POST['isSteamed']) ? 1 : 0;
    $isHanged     = isset($_POST['isHanged'])  ? 1 : 0;

    if(!$item_id || !$movementType || $quantity <= 0){
        $error = "All fields are required and quantity must be greater than 0.";
    } else {

        $allowed = ['IN','OUT','ADJUSTMENT'];
        if(!in_array($movementType, $allowed)){
            $error = "Invalid movement type.";
        } else {

            try{
                $conn->beginTransaction();

                // Validate item exists — grab barcode too
                $stmt = $conn->prepare("SELECT id, barcode FROM items WHERE id = :id");
                $stmt->execute(['id' => $item_id]);
                $itemRow = $stmt->fetch(PDO::FETCH_ASSOC);
                if(!$itemRow){
                    throw new Exception("Item not found.");
                }
                $barcode = $itemRow['barcode'];

                // Get current stock
                $stmt = $conn->prepare("
                    SELECT 
                        SUM(
                            CASE 
                                WHEN movement_type = 'IN'  THEN quantity
                                WHEN movement_type = 'OUT' THEN -quantity
                                ELSE 0
                            END
                        ) AS stock
                    FROM stock_movements sm
                    INNER JOIN items i ON i.id = sm.item_id
                    WHERE i.barcode = :barcode
                ");
                $stmt->execute(['barcode' => $barcode]);
                $result     = $stmt->fetch(PDO::FETCH_ASSOC);
                $currentQty = (int)($result['stock'] ?? 0);

                // Handle ADJUSTMENT → resolve to IN or OUT before the upsert
                if($movementType === "ADJUSTMENT"){
                    $diff = $quantity - $currentQty;
                    if($diff === 0){
                        throw new Exception("Adjustment equals current stock — nothing to change.");
                    }
                    $movementType = $diff > 0 ? "IN" : "OUT";
                    $quantity     = abs($diff);
                }

                // Check for ANY existing row for this item (match on barcode only)
                $check = $conn->prepare("
                    SELECT sm.id, sm.quantity
                    FROM stock_movements sm
                    INNER JOIN items i ON i.id = sm.item_id
                    WHERE i.barcode = :barcode
                    LIMIT 1
                ");
                $check->execute(['barcode' => $barcode]);
                $existing = $check->fetch(PDO::FETCH_ASSOC);

                if($existing){
                    // ✅ Row exists — adjust quantity based on movement direction
                    $newQty = match($movementType){
                        'IN'  => $existing['quantity'] + $quantity,
                        'OUT' => $existing['quantity'] - $quantity,
                        default => $quantity  // ADJUSTMENT already resolved to IN/OUT above
                    };

                    if($newQty < 0){
                        throw new Exception("Not enough stock. Current stock: {$existing['quantity']}.");
                    }

                    $update = $conn->prepare("
                        UPDATE stock_movements
                        SET quantity      = :qty,
                            movement_type = :type,
                            is_ironed     = :ironed,
                            is_steamed    = :steamed,
                            is_hanged     = :hanged
                        WHERE id = :id
                    ");
                    $update->execute([
                        'qty'    => $newQty,
                        'type'   => $movementType,
                        'ironed' => $isIroned,
                        'steamed'=> $isSteamed,
                        'hanged' => $isHanged,
                        'id'     => $existing['id']
                    ]);
                    $success = "Stock updated — new quantity: {$newQty}.";
                } else {
                    // 🆕 First time this item appears — insert fresh row
                    if($movementType === 'OUT'){
                        throw new Exception("Cannot stock out — no existing record for this item.");
                    }
                    $insert = $conn->prepare("
                        INSERT INTO stock_movements 
                            (item_id, quantity, movement_type, is_ironed, is_steamed, is_hanged)
                        VALUES 
                            (:item_id, :qty, :type, :ironed, :steamed, :hanged)
                    ");
                    $insert->execute([
                        'item_id' => $item_id,
                        'qty'     => $quantity,
                        'type'    => $movementType,
                        'ironed'  => $isIroned,
                        'steamed' => $isSteamed,
                        'hanged'  => $isHanged
                    ]);
                }

                $conn->commit();
                if(empty($success)) $success = "Stock added successfully.";

            } catch(Exception $e){
                $conn->rollBack();
                $error = $e->getMessage();
            }
        }
    }
}

// ===================== FETCH ITEMS =====================
$items = [];
try{
    $query = $conn->prepare("SELECT id, item_name, barcode, sku_code, unit FROM items WHERE status = 'active' ORDER BY item_name");
    $query->execute();
    $items = $query->fetchAll(PDO::FETCH_ASSOC);
} catch(PDOException $e){
    $error = "Error fetching items.";
}

// ===================== FETCH MOVEMENTS (with item info, no duplicates) =====================
$movements = [];
try{
    $stmt = $conn->prepare("
        SELECT 
            sm.id,
            sm.quantity,
            sm.movement_type,
            sm.is_ironed,
            sm.is_steamed,
            sm.is_hanged,
            sm.created_at,
            i.item_name,
            i.barcode,
            i.sku_code
        FROM stock_movements sm
        INNER JOIN items i ON i.id = sm.item_id
        ORDER BY sm.created_at DESC
        LIMIT 100
    ");
    $stmt->execute();
    $movements = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch(PDOException $e){
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
        .sku-text     { font-size: 0.8rem; color: #aaa; }

        .empty-state {
            text-align: center;
            padding: 40px 0;
            color: #aaa;
            font-size: 0.9rem;
        }
    </style>
</head>
<body>

<h2>Warehouse Stock Management</h2>

<!-- ── FORM CARD ── -->
<div class="card">

    <?php if($error): ?>
        <div class="alert alert-error">⚠️ <?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <?php if($success): ?>
        <div class="alert alert-success">✅ <?= htmlspecialchars($success) ?></div>
    <?php endif; ?>

    <form method="POST">

        <!-- ITEM SELECT -->
        <div class="form-group">
            <label for="itemSelectedId">Item</label>
            <select name="itemSelectedId" id="itemSelectedId" required onchange="showItemMeta(this)">
                <option value="">— Select an item —</option>
                <?php foreach($items as $item): ?>
                    <option 
                        value="<?= $item['id'] ?>"
                        data-barcode="<?= htmlspecialchars($item['barcode'] ?? '—') ?>"
                        data-sku="<?= htmlspecialchars($item['sku_code'] ?? '—') ?>"
                        data-unit="<?= htmlspecialchars($item['unit'] ?? '—') ?>"
                        <?= (isset($_POST['itemSelectedId']) && $_POST['itemSelectedId'] == $item['id']) ? 'selected' : '' ?>
                    >
                        <?= htmlspecialchars($item['item_name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <div class="item-meta" id="itemMeta">
                <span>📊 <strong>SKU:</strong> <span id="metaSku"></span></span>
                <span>🔖 <strong>Barcode:</strong> <span id="metaBarcode"></span></span>
                <span>📐 <strong>Unit:</strong> <span id="metaUnit"></span></span>
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
    <h3>Recent Stock Movements</h3>

    <?php if(empty($movements)): ?>
        <div class="empty-state">No stock movements recorded yet.</div>
    <?php else: ?>
    <table>
        <thead>
            <tr>
                <th>#</th>
                <th>Item</th>
                <th>Barcode</th>
                <th>SKU</th>
                <th>Qty</th>
                <th>Type</th>
                <th>Ironed</th>
                <th>Steamed</th>
                <th>Hanged</th>
                <th>Date & Time</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach($movements as $i => $m): ?>
            <tr>
                <td><?= $i + 1 ?></td>
                <td><strong><?= htmlspecialchars($m['item_name']) ?></strong></td>
                <td class="barcode-text"><?= htmlspecialchars($m['barcode'] ?? '—') ?></td>
                <td class="sku-text"><?= htmlspecialchars($m['sku_code'] ?? '—') ?></td>
                <td><strong><?= number_format($m['quantity']) ?></strong></td>
                <td>
                    <?php 
                        $type = $m['movement_type'];
                        $badgeClass = match($type) {
                            'IN'  => 'badge-in',
                            'OUT' => 'badge-out',
                            default => 'badge-adj'
                        };
                    ?>
                    <span class="badge <?= $badgeClass ?>"><?= htmlspecialchars($type) ?></span>
                </td>
                <td><?= $m['is_ironed']  ? '<span class="check-icon">✔</span>' : '<span class="cross-icon">—</span>' ?></td>
                <td><?= $m['is_steamed'] ? '<span class="check-icon">✔</span>' : '<span class="cross-icon">—</span>' ?></td>
                <td><?= $m['is_hanged']  ? '<span class="check-icon">✔</span>' : '<span class="cross-icon">—</span>' ?></td>
                <td><?= htmlspecialchars(date('d M Y, H:i', strtotime($m['created_at']))) ?></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>
</div>

<script>
    // Show barcode/SKU/unit below the item dropdown when selected
    function showItemMeta(select) {
        const opt    = select.options[select.selectedIndex];
        const meta   = document.getElementById('itemMeta');
        const barcode = opt.dataset.barcode;

        if (!barcode) { meta.style.display = 'none'; return; }

        document.getElementById('metaSku').textContent     = opt.dataset.sku     || '—';
        document.getElementById('metaBarcode').textContent = opt.dataset.barcode || '—';
        document.getElementById('metaUnit').textContent    = opt.dataset.unit    || '—';
        meta.style.display = 'block';
    }

    // Restore meta on page reload after POST
    window.addEventListener('DOMContentLoaded', () => {
        const sel = document.getElementById('itemSelectedId');
        if (sel && sel.value) showItemMeta(sel);
    });
</script>

</body>
</html>