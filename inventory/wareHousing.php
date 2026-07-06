<?php
session_start();
require __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/authGuard.php';
requireLogin();

// ── AJAX HANDLERS ────────────────────────────────────────────────────────────
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

            $stmt = $conn->prepare("SELECT id, item_name, barcode, selling_price FROM items WHERE id = :id AND status = 'active'");
            $stmt->execute(['id' => $item_id]);
            $itemRow = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$itemRow) throw new Exception("Item not found or inactive.");

            $itemName = $itemRow['item_name'];
            $barcode  = $itemRow['barcode'];

            $stmt2 = $conn->prepare("SELECT id, quantity FROM stock_movements WHERE barcode = :barcode LIMIT 1");
            $stmt2->execute(['barcode' => $barcode]);
            $existing   = $stmt2->fetch(PDO::FETCH_ASSOC);
            $currentQty = $existing ? (int)$existing['quantity'] : 0;

            if ($movementType === 'ADJUSTMENT') {
                $newQty = $quantity;
            } elseif ($movementType === 'IN') {
                $newQty = $currentQty + $quantity;
            } else {
                $newQty = $currentQty - $quantity;
                if ($newQty < 0) throw new Exception("Not enough stock. Current: {$currentQty}.");
            }

            $priceStmt = $conn->prepare("
                SELECT CASE
                    WHEN pc.id IS NOT NULL AND DATEDIFF(CURDATE(), pc.cycle_start) < pc.cycle_days
                    THEN GREATEST(0, pc.base_price - (DATEDIFF(CURDATE(), pc.cycle_start) * pc.daily_reduction))
                    ELSE COALESCE(i.selling_price, 0)
                END AS effective_price
                FROM items i
                LEFT JOIN price_cycles pc ON pc.item_id = i.id AND pc.is_active = 1
                WHERE i.id = :item_id LIMIT 1
            ");
            $priceStmt->execute(['item_id' => $item_id]);
            $priceRow       = $priceStmt->fetch(PDO::FETCH_ASSOC);
            $effectivePrice = $priceRow ? (float)$priceRow['effective_price'] : (float)$itemRow['selling_price'];

            if ($existing) {
                $conn->prepare("
                    UPDATE stock_movements SET
                        item_name = :item_name, quantity = :qty, movement_type = :type,
                        is_ironed = :ironed, is_steamed = :steamed, is_hanged = :hanged,
                        price = :price, created_at = NOW()
                    WHERE id = :id
                ")->execute([
                    'item_name' => $itemName, 'qty' => $newQty, 'type' => $movementType,
                    'ironed'    => $isIroned, 'steamed' => $isSteamed, 'hanged' => $isHanged,
                    'price'     => $effectivePrice, 'id' => $existing['id'],
                ]);
                $action = match($movementType) {
                    'IN'         => "Added {$quantity} → new stock: {$newQty}",
                    'OUT'        => "Removed {$quantity} → new stock: {$newQty}",
                    'ADJUSTMENT' => "Adjusted to {$newQty}",
                };
            } else {
                if ($movementType === 'OUT') throw new Exception("Cannot stock out — no existing record for this item.");
                $conn->prepare("
                    INSERT INTO stock_movements (item_name, barcode, quantity, movement_type, is_ironed, is_steamed, is_hanged, price)
                    VALUES (:item_name, :barcode, :qty, :type, :ironed, :steamed, :hanged, :price)
                ")->execute([
                    'item_name' => $itemName, 'barcode' => $barcode, 'qty' => $newQty, 'type' => $movementType,
                    'ironed'    => $isIroned, 'steamed' => $isSteamed, 'hanged' => $isHanged, 'price' => $effectivePrice,
                ]);
                $action = "Stock created → quantity: {$newQty}";
            }

            $success = $action . " for \"$itemName\".";
            $conn->commit();

        } catch (Exception $e) {
            $conn->rollBack();
            $error = $e->getMessage();
        }
    }
}

$items = [];
try {
    $query = $conn->prepare("SELECT id, item_name, barcode, sku_code, unit, selling_price FROM items WHERE status = 'active' ORDER BY item_name");
    $query->execute();
    $items = $query->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $error = "Error fetching items: " . $e->getMessage();
}

$movements = [];
try {
    $stmt = $conn->prepare("
        SELECT sm.id, sm.barcode, sm.quantity, sm.movement_type,
               sm.is_ironed, sm.is_steamed, sm.is_hanged, sm.created_at,
               COALESCE(i.item_name, sm.item_name) AS item_name,
               CASE
                   WHEN pc.id IS NOT NULL AND DATEDIFF(CURDATE(), pc.cycle_start) < pc.cycle_days
                   THEN GREATEST(0, pc.base_price - (DATEDIFF(CURDATE(), pc.cycle_start) * pc.daily_reduction))
                   ELSE COALESCE(sm.price, i.selling_price, 0)
               END AS effective_price,
               CASE WHEN pc.id IS NOT NULL AND DATEDIFF(CURDATE(), pc.cycle_start) < pc.cycle_days THEN 1 ELSE 0 END AS is_cycle_price,
               GREATEST(0, pc.cycle_days - DATEDIFF(CURDATE(), pc.cycle_start)) AS cycle_days_remaining
        FROM stock_movements sm
        LEFT JOIN items i ON i.barcode = sm.barcode AND i.status = 'active'
        LEFT JOIN price_cycles pc ON pc.item_id = i.id AND pc.is_active = 1
        ORDER BY sm.created_at DESC
        LIMIT 200
    ");
    $stmt->execute();
    $movements = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $error = "Error fetching movements: " . $e->getMessage();
}

$totalValue  = array_sum(array_map(fn($m) => $m['quantity'] * $m['effective_price'], $movements));
$totalItems  = count($movements);
$lowStockCount = 0;
try {
    $ls = $conn->prepare("
        SELECT COUNT(*) as c FROM items i
        LEFT JOIN stock_movements sm ON sm.barcode = i.barcode
        WHERE COALESCE(sm.quantity, 0) <= i.min_stock AND i.status = 'active' AND i.min_stock > 0
    ");
    $ls->execute();
    $lowStockCount = (int)($ls->fetch(PDO::FETCH_ASSOC)['c'] ?? 0);
} catch (PDOException $e) {}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Warehouse Stock - Think Twice</title>
  <link rel="stylesheet" href="/think-twice/public/theme.css?v=2">
  <style>
    .cycle-tag {
      display: inline-block; padding: 2px 7px; margin-left: 6px;
      background: rgba(167,139,250,0.15); color: #a78bfa;
      border-radius: 20px; font-size: 11px; font-weight: 600;
    }
    .check-icon { color: var(--success); }
    .cross-icon { color: var(--border-light); }
    .inline-input { width: 70px; padding: 5px 8px; background: var(--bg); border: 1px solid var(--primary); border-radius: 6px; color: var(--text); font-size: 13px; text-align: center; }
    .inline-select { padding: 5px 8px; background: var(--bg); border: 1px solid var(--primary); border-radius: 6px; color: var(--text); font-size: 13px; }
    .item-meta { margin-top: 8px; padding: 10px 14px; background: rgba(0,153,94,0.05); border: 1px solid rgba(0,153,94,0.2); border-radius: 8px; font-size: 12px; color: var(--text-muted); display: none; }
    .item-meta strong { color: var(--primary); }
  </style>
</head>
<body class="page-container">

  <?php include __DIR__ . '/../navbar.php'; ?>

  <div class="page-header">
    <h1 class="page-title">Warehouse Stock Management</h1>
    <p class="page-subtitle">Track stock movements, adjust quantities, and monitor inventory levels</p>
  </div>

  <div class="page-content">

    <?php if ($error): ?>
      <div class="alert alert-danger">⚠ <?= htmlspecialchars($error) ?></div>
    <?php endif; ?>
    <?php if ($success): ?>
      <div class="alert alert-success">✓ <?= htmlspecialchars($success) ?></div>
    <?php endif; ?>

    <!-- STATS -->
    <div class="grid grid-4 mb-lg">
      <div class="stat-box">
        <div class="stat-value"><?= $totalItems ?></div>
        <div class="stat-label">Stock Lines</div>
      </div>
      <div class="stat-box">
        <div class="stat-value">KES <?= number_format($totalValue, 0) ?></div>
        <div class="stat-label">Total Stock Value</div>
      </div>
      <div class="stat-box">
        <div class="stat-value" style="color: var(--danger)"><?= $lowStockCount ?></div>
        <div class="stat-label">Low Stock Alerts</div>
      </div>
      <div class="stat-box">
        <div class="stat-value"><?= count($items) ?></div>
        <div class="stat-label">Active Items</div>
      </div>
    </div>

    <div class="grid grid-3 gap-lg mb-lg">

      <!-- UPDATE STOCK FORM -->
      <div class="card">
        <div class="card-header">
          <div class="card-title">Update Stock</div>
        </div>

        <form method="POST">
          <div class="form-group">
            <label>Item</label>
            <select name="itemSelectedId" id="itemSelectedId" required onchange="showItemMeta(this)">
              <option value="">— Select an item —</option>
              <?php foreach ($items as $item): ?>
                <option value="<?= $item['id'] ?>"
                  data-barcode="<?= htmlspecialchars($item['barcode']      ?? '—') ?>"
                  data-sku="<?= htmlspecialchars($item['sku_code']         ?? '—') ?>"
                  data-unit="<?= htmlspecialchars($item['unit']            ?? '—') ?>"
                  data-price="<?= number_format((float)$item['selling_price'], 2) ?>"
                  <?= (isset($_POST['itemSelectedId']) && $_POST['itemSelectedId'] == $item['id']) ? 'selected' : '' ?>>
                  <?= htmlspecialchars($item['item_name']) ?>
                </option>
              <?php endforeach; ?>
            </select>
            <div class="item-meta" id="itemMeta">
              Barcode: <strong id="metaBarcode"></strong> &nbsp;|&nbsp;
              SKU: <strong id="metaSku"></strong> &nbsp;|&nbsp;
              Unit: <strong id="metaUnit"></strong> &nbsp;|&nbsp;
              Price: <strong id="metaPrice"></strong>
            </div>
          </div>

          <div class="form-group">
            <label>Quantity</label>
            <input type="number" name="quantity" id="quantity" min="1" placeholder="Enter quantity"
                   value="<?= htmlspecialchars($_POST['quantity'] ?? '') ?>" required>
          </div>

          <div class="form-group">
            <label>Movement Type</label>
            <select name="movementType" required>
              <option value="">— Select type —</option>
              <option value="IN"         <?= (($_POST['movementType'] ?? '') === 'IN')         ? 'selected' : '' ?>>Stock In</option>
              <option value="OUT"        <?= (($_POST['movementType'] ?? '') === 'OUT')        ? 'selected' : '' ?>>Stock Out</option>
              <option value="ADJUSTMENT" <?= (($_POST['movementType'] ?? '') === 'ADJUSTMENT') ? 'selected' : '' ?>>Adjustment</option>
            </select>
          </div>

          <div class="form-group">
            <label style="text-transform: none; font-size: 14px;">Attributes</label>
            <div class="flex gap-md" style="flex-wrap: wrap; margin-top: 8px;">
              <label style="display:flex; align-items:center; gap:8px; font-size:13px; cursor:pointer; text-transform:none; font-weight:400;">
                <input type="checkbox" name="isIroned"  value="1" style="width:auto; margin:0;"
                       <?= isset($_POST['isIroned'])  ? 'checked' : '' ?>> Ironed
              </label>
              <label style="display:flex; align-items:center; gap:8px; font-size:13px; cursor:pointer; text-transform:none; font-weight:400;">
                <input type="checkbox" name="isSteamed" value="1" style="width:auto; margin:0;"
                       <?= isset($_POST['isSteamed']) ? 'checked' : '' ?>> Steamed
              </label>
              <label style="display:flex; align-items:center; gap:8px; font-size:13px; cursor:pointer; text-transform:none; font-weight:400;">
                <input type="checkbox" name="isHanged"  value="1" style="width:auto; margin:0;"
                       <?= isset($_POST['isHanged'])  ? 'checked' : '' ?>> Hanged
              </label>
            </div>
          </div>

          <button type="submit" name="update-stock" class="btn btn-primary btn-block">Update Stock</button>
        </form>
      </div>

      <!-- STOCK TABLE -->
      <div class="card" style="grid-column: span 2;">
        <div class="card-header">
          <div class="card-title">Current Inventory (<?= count($movements) ?> lines)</div>
        </div>

        <?php if (empty($movements)): ?>
          <div class="text-center text-muted" style="padding: var(--space-xl);">
            No stock movements recorded yet. Add stock using the form.
          </div>
        <?php else: ?>
          <div style="overflow-x: auto;">
            <table class="table">
              <thead>
                <tr>
                  <th>#</th>
                  <th>Item</th>
                  <th>Qty</th>
                  <th>Price Today</th>
                  <th>Type</th>
                  <th>Prep</th>
                  <th>Last Updated</th>
                  <th>Actions</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($movements as $i => $m): ?>
                <tr id="row-<?= $m['id'] ?>">
                  <td class="text-muted text-sm"><?= $i + 1 ?></td>
                  <td>
                    <div class="font-semibold"><?= htmlspecialchars($m['item_name']) ?></div>
                    <div class="font-mono text-sm text-muted"><?= htmlspecialchars($m['barcode'] ?? '—') ?></div>
                  </td>

                  <td id="qty-<?= $m['id'] ?>">
                    <strong class="font-mono"><?= number_format($m['quantity']) ?></strong>
                  </td>

                  <td>
                    <?php if ($m['is_cycle_price']): ?>
                      <span class="text-primary font-mono">KES <?= number_format((float)$m['effective_price'], 2) ?></span>
                      <span class="cycle-tag" title="<?= (int)$m['cycle_days_remaining'] ?>d left in cycle">Cycle</span>
                    <?php else: ?>
                      <span class="font-mono">KES <?= number_format((float)$m['effective_price'], 2) ?></span>
                    <?php endif; ?>
                  </td>

                  <td id="type-<?= $m['id'] ?>">
                    <?php
                      $type       = $m['movement_type'];
                      $badgeClass = match ($type) { 'IN' => 'badge-success', 'OUT' => 'badge-danger', default => 'badge-warn' };
                    ?>
                    <span class="badge <?= $badgeClass ?>"><?= htmlspecialchars($type) ?></span>
                  </td>

                  <td>
                    <span class="<?= $m['is_ironed']  ? 'check-icon' : 'cross-icon' ?>">
                      <?= $m['is_ironed'] ? 'I' : '—' ?>
                    </span>
                    <span class="<?= $m['is_steamed'] ? 'check-icon' : 'cross-icon' ?>">
                      <?= $m['is_steamed'] ? 'S' : '—' ?>
                    </span>
                    <span class="<?= $m['is_hanged']  ? 'check-icon' : 'cross-icon' ?>">
                      <?= $m['is_hanged'] ? 'H' : '—' ?>
                    </span>
                  </td>

                  <td class="text-muted text-sm">
                    <?= htmlspecialchars(date('d M Y, H:i', strtotime($m['created_at']))) ?>
                  </td>

                  <td id="actions-<?= $m['id'] ?>">
                    <div class="flex gap-sm">
                      <button class="btn btn-secondary btn-sm"
                              onclick="startEdit(<?= $m['id'] ?>, <?= (int)$m['quantity'] ?>, '<?= htmlspecialchars($m['movement_type'], ENT_QUOTES) ?>')">
                        Edit
                      </button>
                      <button class="btn btn-danger btn-sm" onclick="deleteRow(<?= $m['id'] ?>)">Del</button>
                    </div>
                  </td>
                </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php endif; ?>
      </div>

    </div>
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
        `<input class="inline-input" id="edit-qty-${id}" type="number" min="0" value="${currentQty}">`;
      document.getElementById(`type-${id}`).innerHTML = `
        <select id="edit-type-${id}" class="inline-select">
          <option value="IN"  ${currentType === 'IN'  ? 'selected' : ''}>IN</option>
          <option value="OUT" ${currentType === 'OUT' ? 'selected' : ''}>OUT</option>
        </select>`;
      document.getElementById(`actions-${id}`).innerHTML = `
        <div class="flex gap-sm">
          <button class="btn btn-primary btn-sm" onclick="saveEdit(${id})">Save</button>
          <button class="btn btn-secondary btn-sm" onclick="location.reload()">Cancel</button>
        </div>`;
    }

    function saveEdit(id) {
      const qty  = document.getElementById(`edit-qty-${id}`).value;
      const type = document.getElementById(`edit-type-${id}`).value;
      fetch('', {
        method:  'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body:    `inline-edit=1&row_id=${id}&quantity=${encodeURIComponent(qty)}&movementType=${encodeURIComponent(type)}`
      })
      .then(r => r.json())
      .then(data => { if (data.success) location.reload(); else alert('Error: ' + data.message); })
      .catch(() => alert('Network error. Please try again.'));
    }

    function deleteRow(id) {
      if (!confirm('Delete this stock record?')) return;
      fetch('', {
        method:  'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body:    `inline-delete=1&row_id=${id}`
      })
      .then(r => r.json())
      .then(data => { if (data.success) document.getElementById(`row-${id}`).remove(); else alert('Error: ' + data.message); })
      .catch(() => alert('Network error. Please try again.'));
    }
  </script>

</body>
</html>
