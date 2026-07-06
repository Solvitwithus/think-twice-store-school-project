<?php
session_start();
require __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/authGuard.php';
requireLogin();

$status_filter = $_GET['status'] ?? 'all';

try {
    $params = [];
    $where  = '';
    if ($status_filter !== 'all') {
        $where            = "WHERE status = :status";
        $params['status'] = $status_filter;
    }
    $stmt = $conn->prepare("SELECT * FROM suppliers $where ORDER BY company_name");
    $stmt->execute($params);
    $suppliers = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $activeCount      = count(array_filter($suppliers, fn($s) => ($s['status'] ?? '') === 'active'));
    $blacklistedCount = count(array_filter($suppliers, fn($s) => ($s['status'] ?? '') === 'blacklisted'));
} catch (PDOException $e) {
    $suppliers        = [];
    $error            = $e->getMessage();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Suppliers Report - Think Twice</title>
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
    <h1 class="page-title">Suppliers Report</h1>
    <p class="page-subtitle"><?= count($suppliers) ?> supplier<?= count($suppliers) !== 1 ? 's' : '' ?></p>
  </div>

  <div class="page-content">

    <?php if (!empty($error)): ?>
      <div class="alert alert-danger">⚠ <?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <div class="grid grid-3 mb-lg no-print">
      <div class="stat-box">
        <div class="stat-value"><?= count($suppliers) ?></div>
        <div class="stat-label">Total Suppliers</div>
      </div>
      <div class="stat-box">
        <div class="stat-value" style="color:var(--success)"><?= $activeCount ?? 0 ?></div>
        <div class="stat-label">Active</div>
      </div>
      <div class="stat-box">
        <div class="stat-value" style="color:var(--danger)"><?= $blacklistedCount ?? 0 ?></div>
        <div class="stat-label">Blacklisted</div>
      </div>
    </div>

    <form method="GET" class="no-print" style="display:flex;gap:var(--space-md);align-items:flex-end;margin-bottom:var(--space-lg);flex-wrap:wrap;">
      <div style="display:flex;flex-direction:column;">
        <label>Status</label>
        <select name="status">
          <option value="all"         <?= $status_filter === 'all'         ? 'selected' : '' ?>>All</option>
          <option value="active"      <?= $status_filter === 'active'      ? 'selected' : '' ?>>Active</option>
          <option value="inactive"    <?= $status_filter === 'inactive'    ? 'selected' : '' ?>>Inactive</option>
          <option value="blacklisted" <?= $status_filter === 'blacklisted' ? 'selected' : '' ?>>Blacklisted</option>
        </select>
      </div>
      <button type="submit" class="btn btn-primary btn-sm">Filter</button>
      <a href="?" class="btn btn-secondary btn-sm">Reset</a>
      <a href="/think-twice/suppliers.php" class="btn btn-secondary btn-sm">Manage Suppliers</a>
      <button type="button" onclick="window.print()" class="btn btn-secondary btn-sm" style="margin-left:auto;">Print</button>
    </form>

    <div class="card">
      <div class="card-header">
        <div class="card-title">All Suppliers</div>
        <span class="text-muted text-sm"><?= count($suppliers) ?> records</span>
      </div>

      <?php if (empty($suppliers)): ?>
        <div class="text-center text-muted" style="padding: var(--space-xl);">No suppliers found.</div>
      <?php else: ?>
        <div style="overflow-x: auto;">
          <table class="table">
            <thead>
              <tr>
                <th>#</th>
                <th>Company Name</th>
                <th>Contact Person</th>
                <th>Phone</th>
                <th>Email</th>
                <th>City / Country</th>
                <th>Payment Terms</th>
                <th>Rating</th>
                <th>Status</th>
                <th>Since</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($suppliers as $i => $s):
                $statusBadge = match($s['status'] ?? '') {
                  'active'      => 'badge-success',
                  'blacklisted' => 'badge-danger',
                  default       => 'badge-warn',
                };
              ?>
              <tr>
                <td class="text-muted font-mono"><?= $i + 1 ?></td>
                <td class="font-semibold"><?= htmlspecialchars($s['company_name']) ?></td>
                <td class="text-muted"><?= htmlspecialchars($s['contact_name'] ?? '—') ?></td>
                <td class="font-mono text-sm"><?= htmlspecialchars($s['phone'] ?? '—') ?></td>
                <td class="text-muted text-sm"><?= htmlspecialchars($s['email'] ?? '—') ?></td>
                <td class="text-muted text-sm">
                  <?= htmlspecialchars(implode(', ', array_filter([$s['city'] ?? null, $s['country'] ?? null]))) ?: '—' ?>
                </td>
                <td class="text-sm"><?= htmlspecialchars($s['payment_terms'] ?? '—') ?></td>
                <td class="font-mono">
                  <?php if ($s['rating']): ?>
                    <span class="badge badge-primary"><?= number_format((float)$s['rating'], 1) ?> ★</span>
                  <?php else: ?>
                    <span class="text-muted">—</span>
                  <?php endif; ?>
                </td>
                <td><span class="badge <?= $statusBadge ?>"><?= ucfirst($s['status'] ?? 'Unknown') ?></span></td>
                <td class="text-muted text-sm font-mono"><?= date('M Y', strtotime($s['created_at'])) ?></td>
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
