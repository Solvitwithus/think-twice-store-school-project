<?php
session_start();
require __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/authGuard.php';
requireLogin();

try {
    $stmt = $conn->prepare("
        SELECT pc.id, pc.base_price, pc.daily_reduction, pc.cycle_days,
               pc.cycle_start, pc.is_active, pc.created_at,
               i.item_name, i.sku_code, i.selling_price AS current_price
        FROM price_cycles pc
        LEFT JOIN items i ON pc.item_id = i.id
        ORDER BY pc.is_active DESC, pc.created_at DESC
    ");
    $stmt->execute();
    $cycles = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $cycles = [];
    $error  = $e->getMessage();
}

function currentCyclePrice(array $cycle): float {
    if (!$cycle['cycle_start'] || !$cycle['is_active']) return (float)$cycle['base_price'];
    $daysElapsed = (int)floor((time() - strtotime($cycle['cycle_start'])) / 86400);
    $price = $cycle['base_price'] - ($daysElapsed * $cycle['daily_reduction']);
    return max(0, $price);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Price Cycles - Think Twice</title>
  <link rel="stylesheet" href="/think-twice/public/theme.css?v=2">
  <style>
    .price-progress { height: 6px; background: var(--bg); border-radius: 3px; overflow: hidden; margin-top: 4px; }
    .price-progress-fill { height: 100%; border-radius: 3px; transition: width 0.3s; }
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
    <h1 class="page-title">Dynamic Price Cycles</h1>
    <p class="page-subtitle">Active and historical pricing cycles — <?= count($cycles) ?> total</p>
  </div>

  <div class="page-content">

    <?php if (!empty($error)): ?>
      <div class="alert alert-danger">⚠ <?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <div class="flex-between mb-lg no-print">
      <a href="/think-twice/inventory/cycleManagement.php" class="btn btn-primary btn-sm">Manage Cycles</a>
      <button onclick="window.print()" class="btn btn-secondary btn-sm">Print</button>
    </div>

    <div class="card">
      <div class="card-header">
        <div class="card-title">All Price Cycles</div>
        <span class="text-muted text-sm"><?= count($cycles) ?> records</span>
      </div>

      <?php if (empty($cycles)): ?>
        <div class="text-center text-muted" style="padding: var(--space-xl);">No price cycles configured.</div>
      <?php else: ?>
        <div style="overflow-x: auto;">
          <table class="table">
            <thead>
              <tr>
                <th>#</th>
                <th>Item</th>
                <th>SKU</th>
                <th class="text-right">Base Price</th>
                <th class="text-right">Daily Reduction</th>
                <th class="text-right">Cycle (days)</th>
                <th>Start Date</th>
                <th class="text-right">Current Price</th>
                <th>Progress</th>
                <th>Status</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($cycles as $i => $c):
                $currentPrice = currentCyclePrice($c);
                $pctRemaining = $c['base_price'] > 0 ? ($currentPrice / $c['base_price']) * 100 : 0;
                $pctColor = $pctRemaining > 60 ? 'var(--success)' : ($pctRemaining > 30 ? 'var(--warn)' : 'var(--danger)');
                $daysElapsed = $c['cycle_start'] ? (int)floor((time() - strtotime($c['cycle_start'])) / 86400) : 0;
                $daysLeft = max(0, (int)$c['cycle_days'] - $daysElapsed);
              ?>
              <tr>
                <td class="text-muted font-mono"><?= $i + 1 ?></td>
                <td class="font-semibold"><?= htmlspecialchars($c['item_name'] ?? 'Unknown') ?></td>
                <td class="font-mono text-muted text-sm"><?= htmlspecialchars($c['sku_code'] ?? '—') ?></td>
                <td class="text-right font-mono"><?= number_format((float)$c['base_price'], 2) ?></td>
                <td class="text-right font-mono text-danger">-<?= number_format((float)$c['daily_reduction'], 2) ?>/day</td>
                <td class="text-right font-mono"><?= (int)$c['cycle_days'] ?></td>
                <td class="font-mono text-sm text-muted"><?= $c['cycle_start'] ? date('d M Y', strtotime($c['cycle_start'])) : '—' ?></td>
                <td class="text-right font-mono font-bold text-primary"><?= number_format($currentPrice, 2) ?></td>
                <td style="min-width: 120px;">
                  <div style="font-size:10px;color:var(--text-muted);"><?= $daysLeft ?> days left</div>
                  <div class="price-progress">
                    <div class="price-progress-fill"
                         style="width:<?= min(100, max(0, $pctRemaining)) ?>%; background:<?= $pctColor ?>;"></div>
                  </div>
                </td>
                <td>
                  <span class="badge <?= $c['is_active'] ? 'badge-success' : 'badge-danger' ?>">
                    <?= $c['is_active'] ? 'Active' : 'Inactive' ?>
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
