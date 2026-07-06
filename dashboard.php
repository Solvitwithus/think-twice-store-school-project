<?php
session_start();
require __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/authGuard.php';
requireLogin();

$stats = [];
$recentSales = [];
$lowStockItems = [];
$topItems = [];
$error_msg = '';

try {
    // Today's revenue and sales count
    $todayStmt = $conn->prepare("
        SELECT COUNT(*) as count, COALESCE(SUM(amount), 0) as revenue
        FROM mpesa_transactions
        WHERE result_code = 0 AND DATE(created_at) = CURDATE()
    ");
    $todayStmt->execute();
    $today = $todayStmt->fetch(PDO::FETCH_ASSOC);
    $stats['today_sales']   = $today['count']   ?? 0;
    $stats['today_revenue'] = $today['revenue']  ?? 0;

    // Total active items
    $itemsStmt = $conn->prepare("SELECT COUNT(*) as total FROM items WHERE status = 'active'");
    $itemsStmt->execute();
    $stats['total_items'] = $itemsStmt->fetch(PDO::FETCH_ASSOC)['total'] ?? 0;

    // Total stock value
    $valueStmt = $conn->prepare("SELECT COALESCE(SUM(sm.quantity * sm.price), 0) as total_value FROM stock_movements sm");
    $valueStmt->execute();
    $stats['total_value'] = $valueStmt->fetch(PDO::FETCH_ASSOC)['total_value'] ?? 0;

    // Total suppliers
    $suppStmt = $conn->prepare("SELECT COUNT(*) as total FROM suppliers");
    $suppStmt->execute();
    $stats['total_suppliers'] = $suppStmt->fetch(PDO::FETCH_ASSOC)['total'] ?? 0;

    // Low stock items
    $lowStmt = $conn->prepare("
        SELECT i.item_name, i.min_stock, COALESCE(sm.quantity, 0) AS current_stock
        FROM items i
        LEFT JOIN stock_movements sm ON sm.barcode = i.barcode
        WHERE COALESCE(sm.quantity, 0) <= i.min_stock AND i.status = 'active' AND i.min_stock > 0
        ORDER BY (i.min_stock - COALESCE(sm.quantity, 0)) DESC
        LIMIT 6
    ");
    $lowStmt->execute();
    $lowStockItems = $lowStmt->fetchAll(PDO::FETCH_ASSOC);
    $stats['low_stock_count'] = count($lowStockItems);

    // Monthly revenue for chart (last 7 days)
    $chartStmt = $conn->prepare("
        SELECT DATE(created_at) as day, COALESCE(SUM(amount), 0) as revenue, COUNT(*) as txns
        FROM mpesa_transactions
        WHERE result_code = 0 AND created_at >= DATE_SUB(CURDATE(), INTERVAL 6 DAY)
        GROUP BY DATE(created_at)
        ORDER BY day ASC
    ");
    $chartStmt->execute();
    $chartData = $chartStmt->fetchAll(PDO::FETCH_ASSOC);

    // Recent successful transactions
    $txStmt = $conn->prepare("
        SELECT mpesa_receipt, amount, transaction_date, phone, result_code
        FROM mpesa_transactions
        WHERE result_code = 0
        ORDER BY created_at DESC
        LIMIT 8
    ");
    $txStmt->execute();
    $recentSales = $txStmt->fetchAll(PDO::FETCH_ASSOC);

    // This week vs last week revenue
    $weekStmt = $conn->prepare("
        SELECT
            SUM(CASE WHEN created_at >= DATE_SUB(CURDATE(), INTERVAL 6 DAY) THEN amount ELSE 0 END) as this_week,
            SUM(CASE WHEN created_at < DATE_SUB(CURDATE(), INTERVAL 6 DAY)
                      AND created_at >= DATE_SUB(CURDATE(), INTERVAL 13 DAY) THEN amount ELSE 0 END) as last_week
        FROM mpesa_transactions
        WHERE result_code = 0
    ");
    $weekStmt->execute();
    $weekData = $weekStmt->fetch(PDO::FETCH_ASSOC);
    $thisWeek  = (float)($weekData['this_week'] ?? 0);
    $lastWeek  = (float)($weekData['last_week'] ?? 0);
    $weekChange = $lastWeek > 0 ? round((($thisWeek - $lastWeek) / $lastWeek) * 100, 1) : 0;

    // Total all-time revenue
    $totalRevStmt = $conn->prepare("SELECT COALESCE(SUM(amount), 0) as total FROM mpesa_transactions WHERE result_code = 0");
    $totalRevStmt->execute();
    $stats['total_revenue'] = $totalRevStmt->fetch(PDO::FETCH_ASSOC)['total'] ?? 0;

} catch (PDOException $e) {
    $error_msg = "Database error: " . $e->getMessage();
}

// Build chart day labels for last 7 days
$chartDays    = [];
$chartAmounts = [];
for ($i = 6; $i >= 0; $i--) {
    $d = date('Y-m-d', strtotime("-$i days"));
    $label = $i === 0 ? 'Today' : ($i === 1 ? 'Yesterday' : date('M j', strtotime($d)));
    $chartDays[]    = $label;
    $found = array_filter($chartData ?? [], fn($r) => $r['day'] === $d);
    $chartAmounts[] = $found ? (float)array_values($found)[0]['revenue'] : 0;
}
$maxChart = max($chartAmounts ?: [1]);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Dashboard - Think Twice</title>
  <link rel="stylesheet" href="/think-twice/public/theme.css?v=2">
  <style>
    .kpi-card {
      background: var(--surface);
      border: 1px solid var(--border);
      border-radius: var(--radius-lg);
      padding: var(--space-lg);
      display: flex;
      flex-direction: column;
      gap: 4px;
    }
    .kpi-label { font-size: 12px; text-transform: uppercase; letter-spacing: 0.5px; color: var(--text-muted); }
    .kpi-value { font-size: 28px; font-weight: 700; font-family: var(--font-mono); color: var(--primary); }
    .kpi-sub   { font-size: 12px; color: var(--text-muted); }
    .kpi-up    { color: var(--success); }
    .kpi-down  { color: var(--danger); }

    .chart-bar-wrap { display: flex; align-items: flex-end; gap: 6px; height: 100px; padding-top: 8px; }
    .chart-bar-col  { flex: 1; display: flex; flex-direction: column; align-items: center; gap: 4px; }
    .chart-bar      { width: 100%; background: var(--primary); border-radius: 4px 4px 0 0; min-height: 3px; transition: opacity 0.15s; }
    .chart-bar:hover { opacity: 0.75; }
    .chart-label    { font-size: 10px; color: var(--text-muted); text-align: center; white-space: nowrap; }

    .quick-action {
      display: flex; align-items: center; gap: 14px;
      padding: 14px 0; border-bottom: 1px solid var(--border);
      text-decoration: none; color: var(--text);
      transition: color 0.15s;
    }
    .quick-action:last-child { border-bottom: none; }
    .quick-action:hover { color: var(--primary); }
    .qa-icon { width: 36px; height: 36px; border-radius: 8px; background: var(--surface-hover); display: flex; align-items: center; justify-content: center; font-size: 16px; flex-shrink: 0; }
    .qa-text strong { display: block; font-size: 14px; font-weight: 600; }
    .qa-text span   { font-size: 12px; color: var(--text-muted); }
    .qa-arrow { margin-left: auto; color: var(--text-muted); font-size: 18px; }

    .low-stock-row { display: flex; justify-content: space-between; align-items: center; padding: 10px 0; border-bottom: 1px solid var(--border); }
    .low-stock-row:last-child { border-bottom: none; }
    .stock-bar-wrap { flex: 1; margin: 0 12px; }
    .stock-bar-bg   { height: 5px; background: var(--bg); border-radius: 3px; overflow: hidden; }
    .stock-bar-fill { height: 100%; border-radius: 3px; }
  </style>
</head>
<body class="page-container">

  <?php include 'navbar.php'; ?>

  <div class="page-header">
    <h1 class="page-title">Dashboard</h1>
    <p class="page-subtitle">
      Welcome back, <?= htmlspecialchars($_SESSION['name']) ?> &nbsp;·&nbsp;
      <?= date('l, d F Y') ?>
    </p>
  </div>

  <div class="page-content">

    <?php if ($error_msg): ?>
      <div class="alert alert-danger">⚠ <?= htmlspecialchars($error_msg) ?></div>
    <?php endif; ?>

    <!-- KPI CARDS -->
    <div class="grid grid-4 mb-lg">

      <div class="kpi-card">
        <div class="kpi-label">Today's Revenue</div>
        <div class="kpi-value">KES <?= number_format($stats['today_revenue'], 0) ?></div>
        <div class="kpi-sub"><?= $stats['today_sales'] ?> transaction<?= $stats['today_sales'] !== 1 ? 's' : '' ?> today</div>
      </div>

      <div class="kpi-card">
        <div class="kpi-label">Total Revenue</div>
        <div class="kpi-value">KES <?= number_format($stats['total_revenue'], 0) ?></div>
        <div class="kpi-sub">
          <?php if ($weekChange >= 0): ?>
            <span class="kpi-up">↑ <?= $weekChange ?>%</span> vs last week
          <?php else: ?>
            <span class="kpi-down">↓ <?= abs($weekChange) ?>%</span> vs last week
          <?php endif; ?>
        </div>
      </div>

      <div class="kpi-card">
        <div class="kpi-label">Stock Value</div>
        <div class="kpi-value">KES <?= number_format($stats['total_value'], 0) ?></div>
        <div class="kpi-sub"><?= $stats['total_items'] ?> active items</div>
      </div>

      <div class="kpi-card">
        <div class="kpi-label">Low Stock Alerts</div>
        <div class="kpi-value" style="color: <?= $stats['low_stock_count'] > 0 ? 'var(--danger)' : 'var(--success)' ?>">
          <?= $stats['low_stock_count'] ?>
        </div>
        <div class="kpi-sub"><?= $stats['total_suppliers'] ?> active suppliers</div>
      </div>

    </div>

    <!-- MAIN GRID -->
    <div class="grid grid-3 gap-lg mb-lg">

      <!-- 7-DAY REVENUE CHART -->
      <div class="card" style="grid-column: span 2;">
        <div class="card-header">
          <div>
            <div class="card-title">Revenue — Last 7 Days</div>
            <div class="card-subtitle">Daily M-Pesa transaction totals</div>
          </div>
          <div style="font-family: var(--font-mono); font-size: 20px; color: var(--primary); font-weight: 700;">
            KES <?= number_format($thisWeek, 0) ?>
          </div>
        </div>

        <div class="chart-bar-wrap">
          <?php foreach ($chartDays as $idx => $label):
            $pct = $maxChart > 0 ? ($chartAmounts[$idx] / $maxChart) * 100 : 0;
            $isToday = $label === 'Today';
          ?>
          <div class="chart-bar-col">
            <div class="chart-bar"
                 style="height: <?= max(4, $pct) ?>%;
                        background: <?= $isToday ? 'var(--primary)' : 'rgba(0,229,160,0.3)' ?>;"
                 title="<?= $label ?>: KES <?= number_format($chartAmounts[$idx], 0) ?>">
            </div>
            <div class="chart-label"><?= $label ?></div>
          </div>
          <?php endforeach; ?>
        </div>
      </div>

      <!-- QUICK ACTIONS -->
      <div class="card">
        <div class="card-header">
          <div class="card-title">Quick Actions</div>
        </div>

        <a href="/think-twice/pos.php" class="quick-action">
          <div class="qa-icon">🛒</div>
          <div class="qa-text">
            <strong>Point of Sale</strong>
            <span>Process a new sale</span>
          </div>
          <div class="qa-arrow">›</div>
        </a>

        <a href="/think-twice/inventory/wareHousing.php" class="quick-action">
          <div class="qa-icon">📦</div>
          <div class="qa-text">
            <strong>Update Stock</strong>
            <span>Stock in / out</span>
          </div>
          <div class="qa-arrow">›</div>
        </a>

        <a href="/think-twice/inventory/orderEntry.php" class="quick-action">
          <div class="qa-icon">📋</div>
          <div class="qa-text">
            <strong>New Requisition</strong>
            <span>Order more stock</span>
          </div>
          <div class="qa-arrow">›</div>
        </a>

        <a href="/think-twice/reports.php" class="quick-action">
          <div class="qa-icon">📊</div>
          <div class="qa-text">
            <strong>View Reports</strong>
            <span>Sales & inventory analytics</span>
          </div>
          <div class="qa-arrow">›</div>
        </a>

        <a href="/think-twice/suppliers.php" class="quick-action">
          <div class="qa-icon">🏢</div>
          <div class="qa-text">
            <strong>Suppliers</strong>
            <span>Manage supplier contacts</span>
          </div>
          <div class="qa-arrow">›</div>
        </a>
      </div>

    </div>

    <div class="grid grid-3 gap-lg">

      <!-- LOW STOCK ALERTS -->
      <div class="card">
        <div class="card-header">
          <div class="card-title">Low Stock Alerts</div>
          <a href="/think-twice/inventory/viewSuppliers.php" class="btn btn-secondary btn-sm">View All</a>
        </div>

        <?php if (empty($lowStockItems)): ?>
          <div class="text-center text-muted" style="padding: var(--space-xl);">
            <div style="font-size: 32px; margin-bottom: 8px;">✓</div>
            All stock levels are healthy
          </div>
        <?php else: ?>
          <?php foreach ($lowStockItems as $item):
            $pct = $item['min_stock'] > 0
                ? min(100, round(($item['current_stock'] / $item['min_stock']) * 100))
                : 0;
            $color = $item['current_stock'] == 0 ? 'var(--danger)' : 'var(--warn)';
          ?>
          <div class="low-stock-row">
            <div style="min-width: 0; flex: 1;">
              <div class="font-semibold text-sm" style="white-space: nowrap; overflow: hidden; text-overflow: ellipsis;">
                <?= htmlspecialchars($item['item_name']) ?>
              </div>
              <div class="text-muted" style="font-size: 11px;">
                <?= $item['current_stock'] ?> / <?= $item['min_stock'] ?> min
              </div>
            </div>
            <div class="stock-bar-wrap">
              <div class="stock-bar-bg">
                <div class="stock-bar-fill"
                     style="width: <?= $pct ?>%; background: <?= $color ?>;"></div>
              </div>
            </div>
            <div style="font-family: var(--font-mono); font-size: 13px; color: <?= $color ?>; font-weight: 700; min-width: 30px; text-align: right;">
              <?= $item['current_stock'] ?>
            </div>
          </div>
          <?php endforeach; ?>
        <?php endif; ?>
      </div>

      <!-- RECENT TRANSACTIONS -->
      <div class="card" style="grid-column: span 2;">
        <div class="card-header">
          <div class="card-title">Recent M-Pesa Transactions</div>
          <a href="/think-twice/reports.php?type=transactions" class="btn btn-secondary btn-sm">View All</a>
        </div>

        <?php if (empty($recentSales)): ?>
          <div class="text-center text-muted" style="padding: var(--space-xl);">
            No successful transactions yet
          </div>
        <?php else: ?>
          <table class="table">
            <thead>
              <tr>
                <th>Receipt</th>
                <th>Amount</th>
                <th>Phone</th>
                <th>Date / Time</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($recentSales as $tx): ?>
              <tr>
                <td class="font-mono text-sm"><?= htmlspecialchars($tx['mpesa_receipt']) ?></td>
                <td class="font-bold text-primary font-mono">KES <?= number_format($tx['amount'], 2) ?></td>
                <td class="font-mono text-muted"><?= htmlspecialchars($tx['phone']) ?></td>
                <td class="text-muted text-sm"><?= htmlspecialchars($tx['transaction_date']) ?></td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        <?php endif; ?>
      </div>

    </div>

  </div>

</body>
</html>
