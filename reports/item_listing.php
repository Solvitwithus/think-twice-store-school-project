<?php
session_start();
require __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/authGuard.php';
requireLogin();

$search = trim($_GET['q'] ?? '');
$status = $_GET['status'] ?? 'all';

try {
    $where   = [];
    $params  = [];

    if ($search !== '') {
        $where[]        = "(i.item_name LIKE :q OR i.sku_code LIKE :q OR i.barcode LIKE :q)";
        $params['q']    = "%$search%";
    }
    if ($status !== 'all') {
        $where[]        = "i.status = :status";
        $params['status'] = $status;
    }

    $sql = "
        SELECT i.id, i.sku_code, i.item_name, i.category, i.unit,
               i.buying_price, i.selling_price, i.min_stock, i.status,
               i.barcode, i.tax_type,
               COALESCE(sm.quantity, 0) AS stock_qty
        FROM items i
        LEFT JOIN stock_movements sm ON sm.barcode = i.barcode
        " . ($where ? "WHERE " . implode(" AND ", $where) : "") . "
        ORDER BY i.item_name
    ";
    $stmt = $conn->prepare($sql);
    $stmt->execute($params);
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $items = [];
    $error = $e->getMessage();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Item Listing - Think Twice</title>
  <link rel="stylesheet" href="/think-twice/public/theme.css?v=2">
  <style>
    .filter-bar { display: flex; gap: var(--space-md); flex-wrap: wrap; align-items: flex-end; margin-bottom: var(--space-lg); }
    .filter-bar label { margin-bottom: 4px; }
    .filter-group { display: flex; flex-direction: column; }
    @media print {
      .navigation-header, .no-print { display: none !important; }
      body { background: #fff; color: #000; }
    }
  </style>
</head>
<body class="page-container">

  <div class="no-print">
    <?php include __DIR__ . '/../navbar.php'; ?>
  </div>

  <div class="page-header">
    <h1 class="page-title">Item Listing</h1>
    <p class="page-subtitle"><?= count($items) ?> items found</p>
  </div>

  <div class="page-content">

    <?php if (!empty($error)): ?>
      <div class="alert alert-danger">⚠ <?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <form method="GET" class="filter-bar no-print">
      <div class="filter-group">
        <label>Search</label>
        <input type="text" name="q" value="<?= htmlspecialchars($search) ?>" placeholder="Name, SKU, barcode…" style="width:220px;">
      </div>
      <div class="filter-group">
        <label>Status</label>
        <select name="status">
          <option value="all" <?= $status === 'all' ? 'selected' : '' ?>>All</option>
          <option value="active" <?= $status === 'active' ? 'selected' : '' ?>>Active</option>
          <option value="inactive" <?= $status === 'inactive' ? 'selected' : '' ?>>Inactive</option>
        </select>
      </div>
      <button type="submit" class="btn btn-primary btn-sm">Filter</button>
      <a href="?" class="btn btn-secondary btn-sm">Reset</a>
      <button type="button" onclick="window.print()" class="btn btn-secondary btn-sm" style="margin-left:auto;">Print</button>
    </form>

    <div class="card">
      <div class="card-header">
        <div class="card-title">Items</div>
        <span class="text-muted text-sm"><?= count($items) ?> records</span>
      </div>

      <?php if (empty($items)): ?>
        <div class="text-center text-muted" style="padding: var(--space-xl);">No items found.</div>
      <?php else: ?>
        <div style="overflow-x: auto;">
          <table class="table">
            <thead>
              <tr>
                <th>#</th>
                <th>Item Name</th>
                <th>SKU</th>
                <th>Barcode</th>
                <th>Category</th>
                <th>Unit</th>
                <th class="text-right">Buy Price</th>
                <th class="text-right">Sell Price</th>
                <th class="text-right">Margin</th>
                <th class="text-right">In Stock</th>
                <th class="text-right">Min Stock</th>
                <th>Status</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($items as $i => $item):
                $margin = $item['buying_price'] > 0
                    ? (($item['selling_price'] - $item['buying_price']) / $item['buying_price']) * 100
                    : null;
                $isLow = $item['stock_qty'] <= $item['min_stock'] && $item['min_stock'] > 0;
                $isOut = $item['stock_qty'] == 0;
              ?>
              <tr>
                <td class="text-muted font-mono"><?= $i + 1 ?></td>
                <td class="font-semibold"><?= htmlspecialchars($item['item_name']) ?></td>
                <td class="font-mono text-muted text-sm"><?= htmlspecialchars($item['sku_code']) ?></td>
                <td class="font-mono text-muted text-sm"><?= htmlspecialchars($item['barcode'] ?? '—') ?></td>
                <td><?= htmlspecialchars($item['category']) ?></td>
                <td class="text-muted"><?= htmlspecialchars($item['unit']) ?></td>
                <td class="text-right font-mono"><?= number_format($item['buying_price'], 2) ?></td>
                <td class="text-right font-mono font-bold text-primary"><?= number_format($item['selling_price'], 2) ?></td>
                <td class="text-right">
                  <?php if ($margin !== null): ?>
                    <span class="badge <?= $margin >= 0 ? 'badge-success' : 'badge-danger' ?>">
                      <?= ($margin >= 0 ? '+' : '') . number_format($margin, 1) ?>%
                    </span>
                  <?php else: ?>
                    <span class="text-muted">—</span>
                  <?php endif; ?>
                </td>
                <td class="text-right font-mono font-bold <?= $isOut ? 'text-danger' : ($isLow ? 'text-warn' : 'text-primary') ?>">
                  <?= number_format($item['stock_qty']) ?>
                </td>
                <td class="text-right font-mono text-muted"><?= $item['min_stock'] ?></td>
                <td>
                  <span class="badge <?= $item['status'] === 'active' ? 'badge-success' : 'badge-danger' ?>">
                    <?= ucfirst($item['status']) ?>
                  </span>
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
