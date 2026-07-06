<?php
session_start();
require __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/authGuard.php';
requireLogin();

$type     = $_GET['type'] ?? 'all';
$date_from = $_GET['date_from'] ?? date('Y-m-d', strtotime('-30 days'));
$date_to   = $_GET['date_to']   ?? date('Y-m-d');

try {
    $params = ['from' => $date_from, 'to' => $date_to];
    $typeFilter = '';
    if ($type !== 'all') {
        $typeFilter = "AND movement_type = :type";
        $params['type'] = strtoupper($type);
    }
    $stmt = $conn->prepare("
        SELECT id, item_name, barcode, quantity, movement_type, price,
               is_ironed, is_steamed, is_hanged, created_at
        FROM stock_movements
        WHERE DATE(created_at) BETWEEN :from AND :to
        $typeFilter
        ORDER BY created_at DESC
    ");
    $stmt->execute($params);
    $movements = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $totalIn  = array_sum(array_map(fn($r) => $r['movement_type'] === 'IN' ? $r['quantity'] : 0, $movements));
    $totalOut = array_sum(array_map(fn($r) => $r['movement_type'] === 'OUT' ? $r['quantity'] : 0, $movements));
    $totalVal = array_sum(array_map(fn($r) => ($r['price'] ?? 0) * $r['quantity'], $movements));
} catch (PDOException $e) {
    $movements = [];
    $error = $e->getMessage();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Stock Movements - Think Twice</title>
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
    <h1 class="page-title">Stock Movements</h1>
    <p class="page-subtitle"><?= date('d M Y', strtotime($date_from)) ?> — <?= date('d M Y', strtotime($date_to)) ?></p>
  </div>

  <div class="page-content">

    <?php if (!empty($error)): ?>
      <div class="alert alert-danger">⚠ <?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <!-- KPI SUMMARY -->
    <div class="grid grid-3 mb-lg no-print">
      <div class="stat-box">
        <div class="stat-value text-primary"><?= number_format($totalIn) ?></div>
        <div class="stat-label">Units Received (IN)</div>
      </div>
      <div class="stat-box">
        <div class="stat-value" style="color:var(--danger)"><?= number_format($totalOut) ?></div>
        <div class="stat-label">Units Issued (OUT)</div>
      </div>
      <div class="stat-box">
        <div class="stat-value"><?= number_format(count($movements)) ?></div>
        <div class="stat-label">Total Movements</div>
      </div>
    </div>

    <form method="GET" class="filter-bar no-print">
      <div class="filter-group">
        <label>From</label>
        <input type="date" name="date_from" value="<?= htmlspecialchars($date_from) ?>">
      </div>
      <div class="filter-group">
        <label>To</label>
        <input type="date" name="date_to" value="<?= htmlspecialchars($date_to) ?>">
      </div>
      <div class="filter-group">
        <label>Type</label>
        <select name="type">
          <option value="all" <?= $type === 'all' ? 'selected' : '' ?>>All</option>
          <option value="IN" <?= $type === 'IN' ? 'selected' : '' ?>>IN</option>
          <option value="OUT" <?= $type === 'OUT' ? 'selected' : '' ?>>OUT</option>
          <option value="ADJUSTMENT" <?= $type === 'ADJUSTMENT' ? 'selected' : '' ?>>Adjustment</option>
        </select>
      </div>
      <button type="submit" class="btn btn-primary btn-sm">Filter</button>
      <a href="?" class="btn btn-secondary btn-sm">Reset</a>
      <button type="button" onclick="window.print()" class="btn btn-secondary btn-sm" style="margin-left:auto;">Print</button>
    </form>

    <div class="card">
      <div class="card-header">
        <div class="card-title">Movement Log</div>
        <span class="text-muted text-sm"><?= count($movements) ?> records</span>
      </div>

      <?php if (empty($movements)): ?>
        <div class="text-center text-muted" style="padding: var(--space-xl);">No movements found for this period.</div>
      <?php else: ?>
        <div style="overflow-x: auto;">
          <table class="table">
            <thead>
              <tr>
                <th>#</th>
                <th>Item</th>
                <th>Barcode</th>
                <th>Type</th>
                <th class="text-right">Qty</th>
                <th class="text-right">Unit Price</th>
                <th class="text-right">Value</th>
                <th>Ironed</th>
                <th>Steamed</th>
                <th>Hanged</th>
                <th>Date</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($movements as $i => $m): ?>
              <tr>
                <td class="text-muted font-mono"><?= $i + 1 ?></td>
                <td class="font-semibold"><?= htmlspecialchars($m['item_name']) ?></td>
                <td class="font-mono text-muted text-sm"><?= htmlspecialchars($m['barcode']) ?></td>
                <td>
                  <?php $badge = match($m['movement_type']) {
                    'IN'         => 'badge-success',
                    'OUT'        => 'badge-danger',
                    default      => 'badge-warn',
                  }; ?>
                  <span class="badge <?= $badge ?>"><?= $m['movement_type'] ?></span>
                </td>
                <td class="text-right font-mono font-bold"><?= number_format($m['quantity']) ?></td>
                <td class="text-right font-mono"><?= $m['price'] !== null ? number_format($m['price'], 2) : '—' ?></td>
                <td class="text-right font-mono text-primary">
                  <?= $m['price'] !== null ? number_format($m['price'] * $m['quantity'], 2) : '—' ?>
                </td>
                <td><?= $m['is_ironed'] ? '✓' : '—' ?></td>
                <td><?= $m['is_steamed'] ? '✓' : '—' ?></td>
                <td><?= $m['is_hanged'] ? '✓' : '—' ?></td>
                <td class="text-muted text-sm font-mono">
                  <?= date('d M Y H:i', strtotime($m['created_at'])) ?>
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
