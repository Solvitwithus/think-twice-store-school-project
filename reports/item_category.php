<?php
session_start();
require __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/authGuard.php';
requireLogin();

try {
    $stmt = $conn->prepare("SELECT * FROM itemCategory ORDER BY category_name");
    $stmt->execute();
    $categories = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $categories = [];
    $error = $e->getMessage();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Item Categories Report - Think Twice</title>
  <link rel="stylesheet" href="/think-twice/public/theme.css?v=2">
  <style>
    @media print {
      .navigation-header, .no-print { display: none !important; }
      body { background: #fff; color: #000; }
      .card { border: 1px solid #ccc !important; box-shadow: none !important; }
      .table th, .table td { border: 1px solid #ccc; }
    }
  </style>
</head>
<body class="page-container">

  <div class="no-print">
    <?php include __DIR__ . '/../navbar.php'; ?>
  </div>

  <div class="page-header">
    <h1 class="page-title">Item Categories</h1>
    <p class="page-subtitle"><?= count($categories) ?> categories registered</p>
  </div>

  <div class="page-content">

    <div class="card">
      <div class="card-header">
        <div class="card-title">All Item Categories</div>
        <button onclick="window.print()" class="btn btn-secondary btn-sm no-print">Print / Export</button>
      </div>

      <?php if (empty($categories)): ?>
        <div class="text-center text-muted" style="padding: var(--space-xl);">No categories found.</div>
      <?php else: ?>
        <div style="overflow-x: auto;">
          <table class="table">
            <thead>
              <tr>
                <th>#</th>
                <th>Category Name</th>
                <th>Unit</th>
                <th>Type</th>
                <th>Tax Type</th>
                <th>Purchase Excl.</th>
                <th>Sales Excl.</th>
                <th>Description</th>
                <th>Created</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($categories as $i => $cat): ?>
              <tr>
                <td class="text-muted font-mono"><?= $i + 1 ?></td>
                <td class="font-semibold"><?= htmlspecialchars($cat['category_name']) ?></td>
                <td class="font-mono text-muted"><?= htmlspecialchars($cat['unit'] ?? '—') ?></td>
                <td><?= htmlspecialchars($cat['category_type'] ?? '—') ?></td>
                <td><?= htmlspecialchars($cat['tax_type'] ?? '—') ?></td>
                <td>
                  <span class="badge <?= $cat['purchase_excluded'] ? 'badge-warn' : 'badge-success' ?>">
                    <?= $cat['purchase_excluded'] ? 'Yes' : 'No' ?>
                  </span>
                </td>
                <td>
                  <span class="badge <?= $cat['sales_excluded'] ? 'badge-warn' : 'badge-success' ?>">
                    <?= $cat['sales_excluded'] ? 'Yes' : 'No' ?>
                  </span>
                </td>
                <td class="text-muted text-sm"><?= htmlspecialchars($cat['description'] ?? '—') ?></td>
                <td class="text-muted text-sm font-mono">
                  <?= isset($cat['created_at']) ? date('d M Y', strtotime($cat['created_at'])) : '—' ?>
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
