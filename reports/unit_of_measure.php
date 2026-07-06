<?php
session_start();
require __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/authGuard.php';
requireLogin();

try {
    $stmt = $conn->prepare("SELECT * FROM units ORDER BY measure_name");
    $stmt->execute();
    $units = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Count items using each unit
    $itemCounts = [];
    $icStmt = $conn->prepare("SELECT unit, COUNT(*) AS cnt FROM items GROUP BY unit");
    $icStmt->execute();
    foreach ($icStmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $itemCounts[$r['unit']] = (int)$r['cnt'];
    }
} catch (PDOException $e) {
    $units  = [];
    $error  = $e->getMessage();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Units of Measure - Think Twice</title>
  <link rel="stylesheet" href="/think-twice/public/theme.css?v=2">
  <style>
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
    <h1 class="page-title">Units of Measure</h1>
    <p class="page-subtitle"><?= count($units) ?> unit<?= count($units) !== 1 ? 's' : '' ?> defined</p>
  </div>

  <div class="page-content">

    <?php if (!empty($error)): ?>
      <div class="alert alert-danger">⚠ <?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <div class="flex-between mb-lg no-print">
      <a href="/think-twice/inventory/unitofMeasure.php" class="btn btn-primary btn-sm">Manage Units</a>
      <button onclick="window.print()" class="btn btn-secondary btn-sm">Print</button>
    </div>

    <div class="card">
      <div class="card-header">
        <div class="card-title">All Units of Measure</div>
        <span class="text-muted text-sm"><?= count($units) ?> records</span>
      </div>

      <?php if (empty($units)): ?>
        <div class="text-center text-muted" style="padding: var(--space-xl);">No units of measure defined.</div>
      <?php else: ?>
        <div style="overflow-x: auto;">
          <table class="table">
            <thead>
              <tr>
                <th>#</th>
                <th>Unit Name</th>
                <th>Abbreviation</th>
                <th class="text-right">Items Using This Unit</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($units as $i => $u): ?>
              <tr>
                <td class="text-muted font-mono"><?= $i + 1 ?></td>
                <td class="font-semibold"><?= htmlspecialchars($u['measure_name']) ?></td>
                <td class="font-mono text-primary"><?= htmlspecialchars($u['abbreviation']) ?></td>
                <td class="text-right font-mono">
                  <?php $cnt = $itemCounts[$u['measure_name']] ?? 0; ?>
                  <?php if ($cnt > 0): ?>
                    <span class="badge badge-primary"><?= $cnt ?></span>
                  <?php else: ?>
                    <span class="text-muted">0</span>
                  <?php endif; ?>
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
