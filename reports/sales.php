<?php
session_start();
require __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/authGuard.php';
requireLogin();

$date_from = $_GET['date_from'] ?? date('Y-m-d', strtotime('-30 days'));
$date_to   = $_GET['date_to']   ?? date('Y-m-d');

try {
    $stmt = $conn->prepare("
        SELECT DATE(created_at) AS sale_date,
               COUNT(*) AS total_txns,
               SUM(CASE WHEN result_code = 0 THEN 1 ELSE 0 END) AS successful,
               COALESCE(SUM(CASE WHEN result_code = 0 THEN amount ELSE 0 END), 0) AS revenue
        FROM mpesa_transactions
        WHERE DATE(created_at) BETWEEN :from AND :to
        GROUP BY DATE(created_at)
        ORDER BY sale_date DESC
    ");
    $stmt->execute(['from' => $date_from, 'to' => $date_to]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $totalRevenue = array_sum(array_column($rows, 'revenue'));
    $totalTxns    = array_sum(array_column($rows, 'total_txns'));
    $totalSuccess = array_sum(array_column($rows, 'successful'));
    $activeDays   = count($rows);
    $avgDaily     = $activeDays > 0 ? $totalRevenue / $activeDays : 0;
} catch (PDOException $e) {
    $rows  = [];
    $error = $e->getMessage();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Sales Report - Think Twice</title>
  <link rel="stylesheet" href="/think-twice/public/theme.css?v=2">
  <style>
    .kpi-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px,1fr)); gap: var(--space-md); margin-bottom: var(--space-xl); }
    .kpi-card { background: var(--surface); border: 1px solid var(--border); border-radius: var(--radius-lg); padding: var(--space-lg); }
    .kpi-label { font-size: 11px; text-transform: uppercase; letter-spacing: 0.5px; color: var(--text-muted); margin-bottom: 6px; }
    .kpi-value { font-size: 24px; font-weight: 700; font-family: var(--font-mono); color: var(--primary); }
    .pct-bar { height: 4px; border-radius: 2px; background: var(--primary); margin-top: 4px; }
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
    <h1 class="page-title">Sales Report</h1>
    <p class="page-subtitle"><?= date('d M Y', strtotime($date_from)) ?> — <?= date('d M Y', strtotime($date_to)) ?></p>
  </div>

  <div class="page-content">

    <?php if (!empty($error)): ?>
      <div class="alert alert-danger">⚠ <?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <div class="kpi-grid no-print">
      <div class="kpi-card">
        <div class="kpi-label">Total Revenue</div>
        <div class="kpi-value">KES <?= number_format($totalRevenue ?? 0, 0) ?></div>
      </div>
      <div class="kpi-card">
        <div class="kpi-label">Transactions</div>
        <div class="kpi-value"><?= number_format($totalTxns ?? 0) ?></div>
      </div>
      <div class="kpi-card">
        <div class="kpi-label">Successful</div>
        <div class="kpi-value" style="color:var(--success)"><?= number_format($totalSuccess ?? 0) ?></div>
      </div>
      <div class="kpi-card">
        <div class="kpi-label">Avg / Day</div>
        <div class="kpi-value">KES <?= number_format($avgDaily ?? 0, 0) ?></div>
      </div>
    </div>

    <form method="GET" class="no-print" style="display:flex;gap:var(--space-md);align-items:flex-end;margin-bottom:var(--space-lg);flex-wrap:wrap;">
      <div style="display:flex;flex-direction:column;"><label>From</label><input type="date" name="date_from" value="<?= htmlspecialchars($date_from) ?>"></div>
      <div style="display:flex;flex-direction:column;"><label>To</label><input type="date" name="date_to" value="<?= htmlspecialchars($date_to) ?>"></div>
      <button type="submit" class="btn btn-primary btn-sm">Apply</button>
      <a href="?" class="btn btn-secondary btn-sm">Reset</a>
      <button type="button" onclick="window.print()" class="btn btn-secondary btn-sm" style="margin-left:auto;">Print</button>
    </form>

    <div class="card">
      <div class="card-header">
        <div class="card-title">Daily Sales Summary</div>
        <span class="text-muted text-sm"><?= $activeDays ?? 0 ?> active days</span>
      </div>

      <?php if (empty($rows)): ?>
        <div class="text-center text-muted" style="padding: var(--space-xl);">No sales data for this period.</div>
      <?php else: ?>
        <div style="overflow-x: auto;">
          <table class="table">
            <thead>
              <tr>
                <th>#</th>
                <th>Date</th>
                <th class="text-right">Transactions</th>
                <th class="text-right">Successful</th>
                <th class="text-right">Success Rate</th>
                <th class="text-right">Revenue (KES)</th>
                <th style="width:150px;">Daily Share</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($rows as $i => $row):
                $rate = $row['total_txns'] > 0 ? round(($row['successful'] / $row['total_txns']) * 100, 1) : 0;
                $pct  = ($totalRevenue ?? 0) > 0 ? ($row['revenue'] / $totalRevenue) * 100 : 0;
              ?>
              <tr>
                <td class="text-muted font-mono"><?= $i + 1 ?></td>
                <td class="font-mono"><?= date('D, d M Y', strtotime($row['sale_date'])) ?></td>
                <td class="text-right font-mono"><?= number_format($row['total_txns']) ?></td>
                <td class="text-right font-mono text-success"><?= number_format($row['successful']) ?></td>
                <td class="text-right font-mono <?= $rate >= 80 ? 'text-success' : ($rate >= 50 ? 'text-warn' : 'text-danger') ?>">
                  <?= $rate ?>%
                </td>
                <td class="text-right font-mono font-bold text-primary"><?= number_format($row['revenue'], 2) ?></td>
                <td>
                  <div style="font-size:11px;color:var(--text-muted);margin-bottom:2px;"><?= number_format($pct, 1) ?>%</div>
                  <div class="pct-bar" style="width:<?= min(100, $pct) ?>%"></div>
                </td>
              </tr>
              <?php endforeach; ?>
            </tbody>
            <tfoot>
              <tr style="border-top:2px solid var(--border);">
                <td class="font-bold" colspan="2">Totals</td>
                <td class="text-right font-bold font-mono"><?= number_format($totalTxns ?? 0) ?></td>
                <td class="text-right font-bold font-mono text-success"><?= number_format($totalSuccess ?? 0) ?></td>
                <td></td>
                <td class="text-right font-bold font-mono text-primary">KES <?= number_format($totalRevenue ?? 0, 2) ?></td>
                <td></td>
              </tr>
            </tfoot>
          </table>
        </div>
      <?php endif; ?>
    </div>

  </div>

</body>
</html>
