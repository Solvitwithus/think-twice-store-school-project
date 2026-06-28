<?php
session_start();
require __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/authGuard.php';
requireLogin();

$error   = "";
$success = "";

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_price'])) {
    try {
        $item_id       = (int)   $_POST['item_id'];
        $selling_price = (float) $_POST['selling_price'];
        $stmt = $conn->prepare("UPDATE items SET selling_price = :selling_price WHERE id = :id");
        $stmt->execute(['selling_price' => $selling_price, 'id' => $item_id]);
        header("Location: " . $_SERVER['PHP_SELF'] . "?success=1");
        exit;
    } catch (PDOException $e) {
        $error = "Error updating price: " . $e->getMessage();
    }
}

if (isset($_GET['success'])) $success = "Price updated successfully!";

$items = [];
try {
    $stmt = $conn->prepare("SELECT id, item_name, buying_price, selling_price FROM items ORDER BY item_name");
    $stmt->execute();
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $error = "Error fetching items: " . $e->getMessage();
}

// Summary stats
$totalItems = count($items);
$belowCost = array_filter($items, fn($i) => $i['selling_price'] < $i['buying_price']);
$avgMargin = $totalItems > 0
    ? array_sum(array_map(fn($i) => $i['selling_price'] - $i['buying_price'], $items)) / $totalItems
    : 0;
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Price Setting - Think Twice</title>
  <link rel="stylesheet" href="/think-twice/public/theme.css">
</head>
<body class="page-container">

  <?php include __DIR__ . '/../navbar.php'; ?>

  <div class="page-header">
    <h1 class="page-title">Price Setting</h1>
    <p class="page-subtitle">Manage buying and selling prices for all inventory items</p>
  </div>

  <div class="page-content">

    <?php if ($error): ?>
      <div class="alert alert-danger">⚠ <?= htmlspecialchars($error) ?></div>
    <?php endif; ?>
    <?php if ($success): ?>
      <div class="alert alert-success">✓ <?= htmlspecialchars($success) ?></div>
    <?php endif; ?>

    <!-- STATS -->
    <div class="grid grid-3 mb-lg">
      <div class="stat-box">
        <div class="stat-value"><?= $totalItems ?></div>
        <div class="stat-label">Total Items</div>
      </div>
      <div class="stat-box">
        <div class="stat-value" style="color: var(--danger)"><?= count($belowCost) ?></div>
        <div class="stat-label">Selling Below Cost</div>
      </div>
      <div class="stat-box">
        <div class="stat-value">KES <?= number_format($avgMargin, 0) ?></div>
        <div class="stat-label">Avg Margin / Item</div>
      </div>
    </div>

    <!-- PRICE TABLE -->
    <div class="card">
      <div class="card-header">
        <div class="card-title">Item Prices</div>
        <div class="card-subtitle">Click Save on any row to update the selling price</div>
      </div>

      <?php if (empty($items)): ?>
        <div class="text-center text-muted" style="padding: var(--space-xl);">
          No items found. Create items first.
        </div>
      <?php else: ?>
        <div style="overflow-x: auto;">
          <table class="table">
            <thead>
              <tr>
                <th>Item Name</th>
                <th>Buying Price</th>
                <th>Current Selling Price</th>
                <th>New Selling Price</th>
                <th>Margin</th>
                <th>Action</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($items as $item):
                $margin     = $item['selling_price'] - $item['buying_price'];
                $margin_pct = $item['buying_price'] > 0
                    ? round(($margin / $item['buying_price']) * 100, 1)
                    : 0;
              ?>
              <tr>
                <td class="font-semibold"><?= htmlspecialchars($item['item_name']) ?></td>
                <td class="font-mono">KES <?= number_format($item['buying_price'], 2) ?></td>
                <td class="font-mono text-primary">KES <?= number_format($item['selling_price'], 2) ?></td>
                <td>
                  <form method="POST" style="display:flex; gap: 8px; align-items: center;">
                    <input type="hidden" name="item_id" value="<?= (int) $item['id'] ?>">
                    <input type="number" name="selling_price" step="0.01" min="0"
                           value="<?= htmlspecialchars($item['selling_price']) ?>"
                           style="width: 130px;" required>
                </td>
                <td>
                    <?php if ($margin < 0): ?>
                      <span class="badge badge-danger">▼ KES <?= number_format(abs($margin), 2) ?> loss</span>
                    <?php else: ?>
                      <span class="badge badge-primary">▲ <?= $margin_pct ?>%</span>
                    <?php endif; ?>
                </td>
                <td>
                    <button type="submit" name="update_price" class="btn btn-primary btn-sm">Save</button>
                  </form>
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
