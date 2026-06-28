<?php
session_start();
require __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/authGuard.php';
requireLogin();

$error = "";
$items = [];

try {
    $stmt = $conn->prepare("
        SELECT i.*,
               COALESCE(sm.quantity, 0) AS current_stock,
               CASE
                   WHEN COALESCE(sm.quantity, 0) <= i.min_stock AND i.min_stock > 0 THEN 'low'
                   WHEN COALESCE(sm.quantity, 0) = 0 THEN 'out'
                   ELSE 'ok'
               END AS stock_status
        FROM items i
        LEFT JOIN stock_movements sm ON sm.barcode = i.barcode
        ORDER BY i.item_name
    ");
    $stmt->execute();
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $error = "Error fetching items: " . $e->getMessage();
}

$totalItems  = count($items);
$lowStock    = count(array_filter($items, fn($i) => $i['stock_status'] === 'low'));
$outOfStock  = count(array_filter($items, fn($i) => $i['stock_status'] === 'out'));
$totalValue  = array_sum(array_map(fn($i) => $i['current_stock'] * $i['selling_price'], $items));
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Item Listing - Think Twice</title>
  <link rel="stylesheet" href="/think-twice/public/theme.css">
</head>
<body class="page-container">

  <?php include __DIR__ . '/../navbar.php'; ?>

  <div class="page-header">
    <h1 class="page-title">Item Listing</h1>
    <p class="page-subtitle">View all inventory items with current stock levels and values</p>
  </div>

  <div class="page-content">

    <?php if ($error): ?>
      <div class="alert alert-danger">⚠ <?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <!-- STATS -->
    <div class="grid grid-4 mb-lg">
      <div class="stat-box">
        <div class="stat-value"><?= $totalItems ?></div>
        <div class="stat-label">Total Items</div>
      </div>
      <div class="stat-box">
        <div class="stat-value">KES <?= number_format($totalValue, 0) ?></div>
        <div class="stat-label">Stock Value</div>
      </div>
      <div class="stat-box">
        <div class="stat-value" style="color: var(--warn)"><?= $lowStock ?></div>
        <div class="stat-label">Low Stock</div>
      </div>
      <div class="stat-box">
        <div class="stat-value" style="color: var(--danger)"><?= $outOfStock ?></div>
        <div class="stat-label">Out of Stock</div>
      </div>
    </div>

    <!-- ITEMS TABLE -->
    <div class="card">
      <div class="card-header">
        <div class="card-title">All Items (<?= $totalItems ?>)</div>
        <div class="flex gap-sm">
          <a href="/think-twice/inventory/createItem.php" class="btn btn-primary btn-sm">+ Add Item</a>
          <a href="/think-twice/inventory/wareHousing.php" class="btn btn-secondary btn-sm">Update Stock</a>
        </div>
      </div>

      <?php if (empty($items)): ?>
        <div class="text-center text-muted" style="padding: var(--space-xl);">
          No items yet.
          <br><br>
          <a href="/think-twice/inventory/createItem.php" class="btn btn-primary btn-sm">Create First Item</a>
        </div>
      <?php else: ?>
        <div style="overflow-x: auto;">
          <table class="table">
            <thead>
              <tr>
                <th>SKU</th>
                <th>Item Name</th>
                <th>Category</th>
                <th>Unit</th>
                <th>In Stock</th>
                <th>Min Stock</th>
                <th>Selling Price</th>
                <th>Stock Value</th>
                <th>Tax</th>
                <th>Status</th>
                <th>Actions</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($items as $item):
                $stockValue = $item['current_stock'] * $item['selling_price'];
              ?>
              <tr>
                <td class="font-mono text-sm text-muted"><?= htmlspecialchars($item['sku_code']) ?></td>
                <td class="font-semibold"><?= htmlspecialchars($item['item_name']) ?></td>
                <td class="text-muted"><?= htmlspecialchars($item['category']) ?></td>
                <td class="text-muted"><?= htmlspecialchars($item['unit']) ?></td>
                <td>
                  <span class="font-mono font-bold
                    <?= $item['stock_status'] === 'out' ? 'text-danger' : ($item['stock_status'] === 'low' ? 'text-warn' : 'text-primary') ?>">
                    <?= number_format($item['current_stock']) ?>
                  </span>
                </td>
                <td class="font-mono text-muted"><?= htmlspecialchars($item['min_stock']) ?></td>
                <td class="font-mono">KES <?= number_format($item['selling_price'], 2) ?></td>
                <td class="font-mono text-primary">KES <?= number_format($stockValue, 0) ?></td>
                <td>
                  <?php if ($item['tax_type']): ?>
                    <span class="badge badge-warn"><?= htmlspecialchars($item['tax_type']) ?></span>
                  <?php else: ?>
                    <span class="text-muted">—</span>
                  <?php endif; ?>
                </td>
                <td>
                  <?php if ($item['stock_status'] === 'out'): ?>
                    <span class="badge badge-danger">Out of Stock</span>
                  <?php elseif ($item['stock_status'] === 'low'): ?>
                    <span class="badge badge-warn">Low Stock</span>
                  <?php else: ?>
                    <span class="badge badge-success">In Stock</span>
                  <?php endif; ?>
                </td>
                <td>
                  <div class="flex gap-sm">
                    <a href="/think-twice/inventory/createItem.php?editId=<?= (int)$item['id'] ?>"
                       class="btn btn-secondary btn-sm">Edit</a>
                    <a href="/think-twice/inventory/wareHousing.php"
                       class="btn btn-ghost btn-sm">Stock</a>
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

</body>
</html>
