<?php
session_start();
require __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/authGuard.php';
requireLogin();

$error       = '';
$report_type = $_GET['type'] ?? 'sales';
$data        = [];
$insights    = [];

// Date filter (default: last 30 days)
$date_from = $_GET['date_from'] ?? date('Y-m-d', strtotime('-30 days'));
$date_to   = $_GET['date_to']   ?? date('Y-m-d');

try {

    /* ── SALES REPORT ─────────────────────────────────────────────── */
    if ($report_type === 'sales') {
        $stmt = $conn->prepare("
            SELECT
                DATE(created_at) as sale_date,
                COUNT(*) as total_sales,
                SUM(amount) as revenue
            FROM mpesa_transactions
            WHERE result_code = 0
              AND DATE(created_at) BETWEEN :from AND :to
            GROUP BY DATE(created_at)
            ORDER BY sale_date DESC
        ");
        $stmt->execute(['from' => $date_from, 'to' => $date_to]);
        $data = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if ($data) {
            $totalRev  = array_sum(array_column($data, 'revenue'));
            $totalTxns = array_sum(array_column($data, 'total_sales'));
            $days      = count($data);
            $avgDaily  = $days > 0 ? $totalRev / $days : 0;
            $bestRow   = array_reduce($data, fn($carry, $r) => (!$carry || $r['revenue'] > $carry['revenue']) ? $r : $carry);
            $insights  = [
                ['label' => 'Total Revenue',   'value' => 'KES ' . number_format($totalRev, 0),          'color' => 'var(--primary)'],
                ['label' => 'Total Transactions','value' => number_format($totalTxns),                    'color' => 'var(--text)'],
                ['label' => 'Avg Daily Revenue','value' => 'KES ' . number_format($avgDaily, 0),          'color' => 'var(--success)'],
                ['label' => 'Best Day',         'value' => date('M j', strtotime($bestRow['sale_date'])) . ' — KES ' . number_format($bestRow['revenue'], 0), 'color' => 'var(--warn)'],
            ];
        }
    }

    /* ── INVENTORY REPORT ─────────────────────────────────────────── */
    elseif ($report_type === 'inventory') {
        $stmt = $conn->prepare("
            SELECT
                i.item_name,
                i.barcode,
                i.sku_code,
                i.min_stock,
                i.selling_price,
                i.buying_price,
                COALESCE(sm.quantity, 0) AS quantity,
                (COALESCE(sm.quantity, 0) * i.selling_price) AS value
            FROM items i
            LEFT JOIN stock_movements sm ON sm.barcode = i.barcode
            WHERE i.status = 'active'
            ORDER BY value DESC
        ");
        $stmt->execute();
        $data = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if ($data) {
            $totalValue = array_sum(array_column($data, 'value'));
            $lowStock   = count(array_filter($data, fn($r) => $r['quantity'] <= $r['min_stock'] && $r['min_stock'] > 0));
            $outStock   = count(array_filter($data, fn($r) => $r['quantity'] == 0));
            $margins    = array_map(fn($r) => $r['buying_price'] > 0
                ? (($r['selling_price'] - $r['buying_price']) / $r['buying_price']) * 100
                : 0, $data);
            $avgMargin  = count($margins) > 0 ? array_sum($margins) / count($margins) : 0;
            $insights   = [
                ['label' => 'Total Stock Value',  'value' => 'KES ' . number_format($totalValue, 0), 'color' => 'var(--primary)'],
                ['label' => 'Total Active Items', 'value' => count($data),                           'color' => 'var(--text)'],
                ['label' => 'Low Stock Items',    'value' => $lowStock,                              'color' => 'var(--warn)'],
                ['label' => 'Out of Stock',       'value' => $outStock . ' items',                  'color' => 'var(--danger)'],
            ];
        }
    }

    /* ── SUPPLIERS REPORT ─────────────────────────────────────────── */
    elseif ($report_type === 'suppliers') {
        $stmt = $conn->prepare("
            SELECT
                s.id,
                s.company_name AS name,
                s.phone,
                s.email,
                CONCAT_WS(', ', s.city, s.country) AS address,
                s.status
            FROM suppliers s
            ORDER BY s.company_name
        ");
        $stmt->execute();
        $data = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if ($data) {
            $active   = count(array_filter($data, fn($r) => ($r['status'] ?? '') === 'active'));
            $inactive = count($data) - $active;
            $insights = [
                ['label' => 'Total Suppliers',   'value' => count($data),             'color' => 'var(--text)'],
                ['label' => 'Active Suppliers',  'value' => $active,                  'color' => 'var(--success)'],
                ['label' => 'Inactive / Other',  'value' => $inactive,               'color' => 'var(--warn)'],
                ['label' => 'Full Report',       'value' => 'See Suppliers tab →',   'color' => 'var(--text-muted)'],
            ];
        }
    }

    /* ── TRANSACTIONS REPORT ──────────────────────────────────────── */
    elseif ($report_type === 'transactions') {
        $stmt = $conn->prepare("
            SELECT
                id, mpesa_receipt, phone, amount,
                transaction_date, created_at, result_code
            FROM mpesa_transactions
            WHERE DATE(created_at) BETWEEN :from AND :to
            ORDER BY created_at DESC
            LIMIT 200
        ");
        $stmt->execute(['from' => $date_from, 'to' => $date_to]);
        $data = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if ($data) {
            $success  = array_filter($data, fn($r) => $r['result_code'] == 0);
            $failed   = array_filter($data, fn($r) => $r['result_code'] != 0);
            $revenue  = array_sum(array_column(array_values($success), 'amount'));
            $rate     = count($data) > 0 ? round((count($success) / count($data)) * 100, 1) : 0;
            $insights = [
                ['label' => 'Total Transactions', 'value' => count($data),                       'color' => 'var(--text)'],
                ['label' => 'Successful',         'value' => count($success),                    'color' => 'var(--success)'],
                ['label' => 'Failed / Pending',   'value' => count($failed),                     'color' => 'var(--danger)'],
                ['label' => 'Success Rate',       'value' => $rate . '%',                        'color' => 'var(--primary)'],
            ];
        }
    }

} catch (PDOException $e) {
    $error = 'Error fetching report: ' . $e->getMessage();
}

$reportTitles = [
    'sales'        => 'Daily Sales Report',
    'inventory'    => 'Inventory Stock Report',
    'suppliers'    => 'Supplier Performance',
    'transactions' => 'M-Pesa Transactions',
];
$currentTitle = $reportTitles[$report_type] ?? 'Report';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?= $currentTitle ?> - Think Twice</title>
  <link rel="stylesheet" href="/think-twice/public/theme.css?v=2">
  <style>
    .report-tabs { display: flex; gap: 4px; flex-wrap: wrap; margin-bottom: var(--space-xl); }
    .report-tab {
      padding: 10px 20px; border-radius: 8px; text-decoration: none; font-size: 14px;
      font-weight: 600; border: 1px solid var(--border); color: var(--text-muted);
      background: var(--surface); transition: all 0.15s;
    }
    .report-tab:hover { border-color: var(--primary); color: var(--primary); }
    .report-tab.active { background: var(--primary); color: #fff; border-color: var(--primary); }

    .insight-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: var(--space-md); margin-bottom: var(--space-xl); }
    @media (max-width: 768px) { .insight-grid { grid-template-columns: repeat(2, 1fr); } }
    .insight-card {
      background: var(--surface); border: 1px solid var(--border); border-radius: var(--radius-lg);
      padding: var(--space-lg); text-align: center;
    }
    .insight-label { font-size: 11px; text-transform: uppercase; letter-spacing: 0.5px; color: var(--text-muted); margin-bottom: 8px; }
    .insight-value { font-size: 22px; font-weight: 700; font-family: var(--font-mono); }

    .date-filter-row { display: flex; align-items: center; gap: var(--space-md); flex-wrap: wrap; margin-bottom: var(--space-xl); }
    .date-filter-row label { font-size: 13px; color: var(--text-muted); margin-bottom: 0; }
    .date-filter-row input[type="date"] { padding: 8px 12px; font-size: 13px; }
    .date-filter-row .btn { align-self: flex-end; }

    .table td.text-right { text-align: right; }
    .table th.text-right { text-align: right; }
    .pct-bar { height: 4px; border-radius: 2px; background: var(--primary); margin-top: 4px; }

    @media print {
      .navigation-header, .report-tabs, .date-filter-row, .print-btn { display: none !important; }
      body { background: #fff; color: #000; }
      .card { border: 1px solid #ccc !important; box-shadow: none !important; }
      .insight-card { border: 1px solid #ccc; }
      .insight-value { color: #000 !important; }
      .insight-label { color: #555 !important; }
      .badge { border: 1px solid #ccc; color: #000 !important; background: transparent !important; }
    }
  </style>
</head>
<body class="page-container">

  <?php include 'navbar.php'; ?>

  <div class="page-header" style="padding-bottom: 0;">
    <h1 class="page-title">Reports & Analytics</h1>
    <p class="page-subtitle">Generated <?= date('d M Y, H:i') ?></p>
  </div>

  <div class="page-content">

    <?php if ($error): ?>
      <div class="alert alert-danger">⚠ <?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <!-- REPORT TYPE TABS -->
    <div class="report-tabs">
      <a href="?type=sales&date_from=<?= $date_from ?>&date_to=<?= $date_to ?>"
         class="report-tab <?= $report_type === 'sales' ? 'active' : '' ?>">Sales</a>
      <a href="?type=inventory&date_from=<?= $date_from ?>&date_to=<?= $date_to ?>"
         class="report-tab <?= $report_type === 'inventory' ? 'active' : '' ?>">Inventory</a>
      <a href="?type=suppliers&date_from=<?= $date_from ?>&date_to=<?= $date_to ?>"
         class="report-tab <?= $report_type === 'suppliers' ? 'active' : '' ?>">Suppliers</a>
      <a href="?type=transactions&date_from=<?= $date_from ?>&date_to=<?= $date_to ?>"
         class="report-tab <?= $report_type === 'transactions' ? 'active' : '' ?>">Transactions</a>
    </div>

    <!-- DATE FILTER (not for inventory/suppliers) -->
    <?php if (in_array($report_type, ['sales', 'transactions'])): ?>
    <form method="GET" class="date-filter-row">
      <input type="hidden" name="type" value="<?= htmlspecialchars($report_type) ?>">
      <div>
        <label>From</label>
        <input type="date" name="date_from" value="<?= htmlspecialchars($date_from) ?>">
      </div>
      <div>
        <label>To</label>
        <input type="date" name="date_to" value="<?= htmlspecialchars($date_to) ?>">
      </div>
      <button type="submit" class="btn btn-primary btn-sm">Apply Filter</button>
      <a href="?type=<?= $report_type ?>" class="btn btn-secondary btn-sm">Reset</a>
    </form>
    <?php endif; ?>

    <!-- INSIGHT CARDS -->
    <?php if (!empty($insights)): ?>
    <div class="insight-grid">
      <?php foreach ($insights as $ins): ?>
      <div class="insight-card">
        <div class="insight-label"><?= htmlspecialchars($ins['label']) ?></div>
        <div class="insight-value" style="color: <?= $ins['color'] ?>;"><?= htmlspecialchars($ins['value']) ?></div>
      </div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <!-- REPORT TABLE CARD -->
    <div class="card">
      <div class="card-header">
        <div>
          <div class="card-title"><?= $currentTitle ?></div>
          <?php if (in_array($report_type, ['sales', 'transactions'])): ?>
            <div class="card-subtitle"><?= date('d M Y', strtotime($date_from)) ?> — <?= date('d M Y', strtotime($date_to)) ?></div>
          <?php endif; ?>
        </div>
        <button onclick="window.print()" class="btn btn-secondary btn-sm print-btn">Print / Export</button>
      </div>

      <?php if (empty($data)): ?>
        <div class="text-center text-muted" style="padding: var(--space-xl);">
          No data found for the selected criteria.
        </div>

      <?php elseif ($report_type === 'sales'): ?>
        <?php
          $totalRevAll = array_sum(array_column($data, 'revenue'));
        ?>
        <div style="overflow-x: auto;">
          <table class="table">
            <thead>
              <tr>
                <th>Date</th>
                <th class="text-right">Transactions</th>
                <th class="text-right">Revenue (KES)</th>
                <th style="width:180px;">Daily Share</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($data as $row):
                $pct = $totalRevAll > 0 ? ($row['revenue'] / $totalRevAll) * 100 : 0;
              ?>
              <tr>
                <td class="font-mono"><?= date('D, d M Y', strtotime($row['sale_date'])) ?></td>
                <td class="text-right font-mono"><?= number_format($row['total_sales']) ?></td>
                <td class="text-right font-bold text-primary font-mono">
                  <?= number_format($row['revenue'], 2) ?>
                </td>
                <td>
                  <div style="font-size:11px; color:var(--text-muted); margin-bottom:2px;">
                    <?= number_format($pct, 1) ?>%
                  </div>
                  <div class="pct-bar" style="width: <?= min(100, $pct) ?>%"></div>
                </td>
              </tr>
              <?php endforeach; ?>
            </tbody>
            <tfoot>
              <tr style="border-top: 2px solid var(--border);">
                <td class="font-bold">Total</td>
                <td class="text-right font-bold font-mono"><?= number_format(array_sum(array_column($data, 'total_sales'))) ?></td>
                <td class="text-right font-bold text-primary font-mono">
                  <?= number_format($totalRevAll, 2) ?>
                </td>
                <td></td>
              </tr>
            </tfoot>
          </table>
        </div>

      <?php elseif ($report_type === 'inventory'): ?>
        <?php
          $maxVal = max(array_column($data, 'value') ?: [1]);
        ?>
        <div style="overflow-x: auto;">
          <table class="table">
            <thead>
              <tr>
                <th>Item Name</th>
                <th>SKU</th>
                <th class="text-right">In Stock</th>
                <th class="text-right">Min</th>
                <th class="text-right">Buy Price</th>
                <th class="text-right">Sell Price</th>
                <th class="text-right">Margin</th>
                <th class="text-right">Stock Value</th>
                <th>Status</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($data as $row):
                $margin = $row['buying_price'] > 0
                  ? (($row['selling_price'] - $row['buying_price']) / $row['buying_price']) * 100
                  : null;
                $isLow  = $row['quantity'] <= $row['min_stock'] && $row['min_stock'] > 0;
                $isOut  = $row['quantity'] == 0;
              ?>
              <tr>
                <td class="font-semibold"><?= htmlspecialchars($row['item_name']) ?></td>
                <td class="font-mono text-muted text-sm"><?= htmlspecialchars($row['sku_code']) ?></td>
                <td class="text-right font-mono <?= $isOut ? 'text-danger' : ($isLow ? 'text-warn' : 'text-primary') ?> font-bold">
                  <?= number_format($row['quantity']) ?>
                </td>
                <td class="text-right font-mono text-muted"><?= htmlspecialchars($row['min_stock']) ?></td>
                <td class="text-right font-mono"><?= number_format($row['buying_price'], 2) ?></td>
                <td class="text-right font-mono"><?= number_format($row['selling_price'], 2) ?></td>
                <td class="text-right">
                  <?php if ($margin !== null): ?>
                    <span class="badge <?= $margin >= 0 ? 'badge-success' : 'badge-danger' ?>">
                      <?= $margin >= 0 ? '+' : '' ?><?= number_format($margin, 1) ?>%
                    </span>
                  <?php else: ?>
                    <span class="text-muted">—</span>
                  <?php endif; ?>
                </td>
                <td class="text-right font-mono font-bold text-primary">
                  <?= number_format($row['value'], 0) ?>
                </td>
                <td>
                  <?php if ($isOut): ?>
                    <span class="badge badge-danger">Out</span>
                  <?php elseif ($isLow): ?>
                    <span class="badge badge-warn">Low</span>
                  <?php else: ?>
                    <span class="badge badge-success">OK</span>
                  <?php endif; ?>
                </td>
              </tr>
              <?php endforeach; ?>
            </tbody>
            <tfoot>
              <tr style="border-top: 2px solid var(--border);">
                <td colspan="7" class="font-bold text-right" style="padding-right: 16px;">Total Stock Value</td>
                <td class="text-right font-bold text-primary font-mono">
                  <?= number_format(array_sum(array_column($data, 'value')), 0) ?>
                </td>
                <td></td>
              </tr>
            </tfoot>
          </table>
        </div>

      <?php elseif ($report_type === 'suppliers'): ?>
        <div style="overflow-x: auto;">
          <table class="table">
            <thead>
              <tr>
                <th>Company Name</th>
                <th>Phone</th>
                <th>Email</th>
                <th>Location</th>
                <th>Status</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($data as $row):
                $statusBadge = match($row['status'] ?? '') {
                  'active'      => 'badge-success',
                  'blacklisted' => 'badge-danger',
                  default       => 'badge-warn',
                };
              ?>
              <tr>
                <td class="font-semibold"><?= htmlspecialchars($row['name']) ?></td>
                <td class="font-mono text-muted"><?= htmlspecialchars($row['phone']) ?></td>
                <td class="text-muted text-sm"><?= htmlspecialchars($row['email'] ?? '—') ?></td>
                <td class="text-muted text-sm"><?= htmlspecialchars($row['address'] ?? '—') ?></td>
                <td><span class="badge <?= $statusBadge ?>"><?= ucfirst($row['status'] ?? 'Unknown') ?></span></td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>

      <?php elseif ($report_type === 'transactions'): ?>
        <div style="overflow-x: auto;">
          <table class="table">
            <thead>
              <tr>
                <th>Receipt #</th>
                <th>Phone</th>
                <th class="text-right">Amount (KES)</th>
                <th>Transaction Time</th>
                <th>Recorded At</th>
                <th>Status</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($data as $row): ?>
              <tr>
                <td class="font-mono text-sm"><?= htmlspecialchars($row['mpesa_receipt']) ?></td>
                <td class="font-mono text-muted"><?= htmlspecialchars($row['phone']) ?></td>
                <td class="text-right font-bold <?= $row['result_code'] == 0 ? 'text-primary' : 'text-muted' ?> font-mono">
                  <?= number_format($row['amount'], 2) ?>
                </td>
                <td class="font-mono text-sm"><?= htmlspecialchars($row['transaction_date']) ?></td>
                <td class="text-muted text-sm font-mono"><?= date('d M Y H:i', strtotime($row['created_at'])) ?></td>
                <td>
                  <?php if ($row['result_code'] == 0): ?>
                    <span class="badge badge-success">✓ Success</span>
                  <?php else: ?>
                    <span class="badge badge-danger">✗ Code <?= (int)$row['result_code'] ?></span>
                  <?php endif; ?>
                </td>
              </tr>
              <?php endforeach; ?>
            </tbody>
            <?php
              $successRows = array_filter($data, fn($r) => $r['result_code'] == 0);
              $totalSuccessAmt = array_sum(array_column(array_values($successRows), 'amount'));
            ?>
            <tfoot>
              <tr style="border-top: 2px solid var(--border);">
                <td colspan="2" class="font-bold text-right" style="padding-right:16px;">Total Collected</td>
                <td class="text-right font-bold text-primary font-mono"><?= number_format($totalSuccessAmt, 2) ?></td>
                <td colspan="3"></td>
              </tr>
            </tfoot>
          </table>
        </div>
      <?php endif; ?>

    </div>

  </div>

</body>
</html>
