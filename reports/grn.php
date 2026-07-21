<?php
session_start();
require __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/authGuard.php';
requireLogin();

$date_from = $_GET['date_from'] ?? date('Y-m-d', strtotime('-90 days'));
$date_to   = $_GET['date_to']   ?? date('Y-m-d');
$search    = trim($_GET['q'] ?? '');
$view      = $_GET['view'] ?? 'summary';   // 'summary' | 'detail'
$grn_date  = $_GET['grn_date'] ?? null;    // drill into a specific day's GRN

try {
    /* ── KPI Totals ─────────────────────────────────────────── */
    $kpiStmt = $conn->prepare("
        SELECT COUNT(*)           AS grn_lines,
               COALESCE(SUM(quantity), 0) AS total_units,
               COALESCE(SUM(quantity * price), 0) AS total_value,
               COUNT(DISTINCT DATE(created_at)) AS receipt_days,
               SUM(CASE WHEN is_ironed = 1 THEN quantity ELSE 0 END) AS ironed_units,
               SUM(CASE WHEN is_steamed = 1 THEN quantity ELSE 0 END) AS steamed_units,
               SUM(CASE WHEN is_hanged  = 1 THEN quantity ELSE 0 END) AS hanged_units
        FROM stock_movements
        WHERE movement_type = 'IN'
          AND DATE(created_at) BETWEEN :from AND :to
    ");
    $kpiStmt->execute(['from' => $date_from, 'to' => $date_to]);
    $kpi = $kpiStmt->fetch(PDO::FETCH_ASSOC);

    /* ── GRN Summary — one row per receipt date ─────────────── */
    $sumParams = ['from' => $date_from, 'to' => $date_to];
    $sumSearch = '';
    if ($search !== '') {
        $sumSearch = "AND (sm.item_name LIKE :q OR sm.barcode LIKE :q)";
        $sumParams['q'] = "%$search%";
    }

    $summaryStmt = $conn->prepare("
        SELECT DATE(sm.created_at)              AS grn_date,
               COUNT(*)                          AS line_count,
               COALESCE(SUM(sm.quantity), 0)    AS total_units,
               COALESCE(SUM(sm.quantity * sm.price), 0) AS total_value,
               SUM(CASE WHEN sm.is_ironed=1 THEN 1 ELSE 0 END) AS ironed_lines,
               SUM(CASE WHEN sm.is_steamed=1 THEN 1 ELSE 0 END) AS steamed_lines,
               SUM(CASE WHEN sm.is_hanged=1  THEN 1 ELSE 0 END) AS hanged_lines
        FROM stock_movements sm
        WHERE sm.movement_type = 'IN'
          AND DATE(sm.created_at) BETWEEN :from AND :to
          $sumSearch
        GROUP BY DATE(sm.created_at)
        ORDER BY grn_date DESC
    ");
    $summaryStmt->execute($sumParams);
    $grnSummary = $summaryStmt->fetchAll(PDO::FETCH_ASSOC);

    /* ── Detail lines for a specific GRN date ───────────────── */
    $grnLines = [];
    if ($grn_date) {
        $detailParams = ['d' => $grn_date];
        $detailSearch = '';
        if ($search !== '') {
            $detailSearch = "AND (sm.item_name LIKE :q OR sm.barcode LIKE :q)";
            $detailParams['q'] = "%$search%";
        }
        $detailStmt = $conn->prepare("
            SELECT sm.id, sm.item_name, sm.barcode, sm.quantity, sm.price,
                   (sm.quantity * sm.price) AS line_total,
                   sm.is_ironed, sm.is_steamed, sm.is_hanged, sm.created_at,
                   i.sku_code, i.category, i.unit
            FROM stock_movements sm
            LEFT JOIN items i ON i.barcode = sm.barcode
            WHERE sm.movement_type = 'IN'
              AND DATE(sm.created_at) = :d
              $detailSearch
            ORDER BY sm.created_at ASC
        ");
        $detailStmt->execute($detailParams);
        $grnLines = $detailStmt->fetchAll(PDO::FETCH_ASSOC);
    }

} catch (PDOException $e) {
    $kpi = [];
    $grnSummary = [];
    $grnLines   = [];
    $error = $e->getMessage();
}

$grnRef = fn(string $date): string => 'GRN-' . date('Ymd', strtotime($date));
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Goods Received Notes (GRN) - Think Twice</title>
  <link rel="stylesheet" href="/think-twice/public/theme.css?v=2">
  <style>
    .process-chip { display:inline-flex; align-items:center; gap:4px; padding:2px 8px;
                    border-radius:10px; font-size:11px; font-weight:600; margin:1px; }
    .chip-yes { background:rgba(0,153,94,0.12); color:var(--success); }
    .chip-no  { background:var(--bg); color:var(--text-muted); }
    .grn-print-header { display:none; }
    @media print {
      .navigation-header, .no-print { display:none !important; }
      body { background:#fff; color:#000; }
      .grn-print-header { display:block; text-align:center; margin-bottom:16px; }
      .grn-print-header h2 { font-size:20px; margin:0 0 4px; }
      .grn-print-header p { font-size:12px; color:#555; margin:0; }
    }
  </style>
</head>
<body class="page-container">

  <div class="no-print">
    <?php include __DIR__ . '/../navbar.php'; ?>
  </div>

  <!-- Print header shown only on print -->
  <div class="grn-print-header">
    <h2>Think Twice Stores — Goods Received Note</h2>
    <?php if ($grn_date): ?>
      <p><?= htmlspecialchars($grnRef($grn_date)) ?> &nbsp;|&nbsp; <?= date('d F Y', strtotime($grn_date)) ?></p>
    <?php else: ?>
      <p>Period: <?= date('d M Y', strtotime($date_from)) ?> – <?= date('d M Y', strtotime($date_to)) ?></p>
    <?php endif; ?>
  </div>

  <div class="page-header">
    <h1 class="page-title">Goods Received Notes (GRN)</h1>
    <p class="page-subtitle">All stock received — <?= date('d M Y', strtotime($date_from)) ?> to <?= date('d M Y', strtotime($date_to)) ?></p>
  </div>

  <div class="page-content">

    <?php if (!empty($error)): ?>
      <div class="alert alert-danger">⚠ <?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <!-- KPI Cards -->
    <div class="grid grid-4 mb-lg no-print">
      <div class="stat-box">
        <div class="stat-value"><?= number_format((int)($kpi['grn_lines'] ?? 0)) ?></div>
        <div class="stat-label">GRN Line Entries</div>
      </div>
      <div class="stat-box">
        <div class="stat-value text-primary"><?= number_format((int)($kpi['total_units'] ?? 0)) ?></div>
        <div class="stat-label">Total Units Received</div>
      </div>
      <div class="stat-box">
        <div class="stat-value">KES <?= number_format((float)($kpi['total_value'] ?? 0), 0) ?></div>
        <div class="stat-label">Total Stock Value</div>
      </div>
      <div class="stat-box">
        <div class="stat-value" style="color:var(--info)"><?= number_format((int)($kpi['receipt_days'] ?? 0)) ?></div>
        <div class="stat-label">Receipt Days</div>
      </div>
    </div>

    <!-- Processing Status Summary -->
    <?php if (!empty($kpi) && (int)$kpi['total_units'] > 0): ?>
    <div class="card mb-lg no-print">
      <div class="card-header">
        <div class="card-title">Processing Completion</div>
        <span class="text-muted text-sm">Per-line status across all received items</span>
      </div>
      <div style="display:grid; grid-template-columns:repeat(3,1fr); gap:var(--space-lg); padding:var(--space-lg);">
        <?php
        $proc = [
          ['Ironed', $kpi['ironed_lines'] ?? 0,  $kpi['grn_lines'] ?? 1, 'var(--primary)'],
          ['Steamed', $kpi['steamed_lines'] ?? 0, $kpi['grn_lines'] ?? 1, 'var(--info)'],
          ['Hanged',  $kpi['hanged_lines'] ?? 0,  $kpi['grn_lines'] ?? 1, 'var(--success)'],
        ];
        foreach ($proc as [$label, $done, $total, $color]):
          $pct = $total > 0 ? round(($done / $total) * 100) : 0;
        ?>
        <div>
          <div style="display:flex; justify-content:space-between; margin-bottom:4px;">
            <span style="font-size:13px; font-weight:600; color:var(--text);"><?= $label ?></span>
            <span style="font-size:13px; color:<?= $color ?>; font-weight:700;"><?= $pct ?>%</span>
          </div>
          <div style="height:8px; background:var(--border); border-radius:4px; overflow:hidden;">
            <div style="height:100%; width:<?= $pct ?>%; background:<?= $color ?>; border-radius:4px;"></div>
          </div>
          <div style="font-size:11px; color:var(--text-muted); margin-top:2px;"><?= number_format($done) ?> of <?= number_format($total) ?> lines</div>
        </div>
        <?php endforeach; ?>
      </div>
    </div>
    <?php endif; ?>

    <!-- Filters -->
    <form method="GET" class="no-print"
          style="display:flex;gap:var(--space-md);align-items:flex-end;margin-bottom:var(--space-lg);flex-wrap:wrap;">
      <div style="display:flex;flex-direction:column;">
        <label>From</label>
        <input type="date" name="date_from" value="<?= htmlspecialchars($date_from) ?>">
      </div>
      <div style="display:flex;flex-direction:column;">
        <label>To</label>
        <input type="date" name="date_to" value="<?= htmlspecialchars($date_to) ?>">
      </div>
      <div style="display:flex;flex-direction:column;">
        <label>Search Item / Barcode</label>
        <input type="text" name="q" value="<?= htmlspecialchars($search) ?>" placeholder="Name or barcode…" style="width:200px;">
      </div>
      <?php if ($grn_date): ?>
        <input type="hidden" name="grn_date" value="<?= htmlspecialchars($grn_date) ?>">
      <?php endif; ?>
      <button type="submit" class="btn btn-primary btn-sm">Apply</button>
      <a href="?" class="btn btn-secondary btn-sm">Reset</a>
      <button type="button" onclick="window.print()" class="btn btn-secondary btn-sm" style="margin-left:auto;">Print GRN</button>
    </form>

    <!-- Drill-down: specific GRN date -->
    <?php if ($grn_date && !empty($grnLines)): ?>
    <div class="card mb-lg">
      <div class="card-header">
        <div>
          <div class="card-title"><?= htmlspecialchars($grnRef($grn_date)) ?></div>
          <div class="text-muted text-sm"><?= date('l, d F Y', strtotime($grn_date)) ?></div>
        </div>
        <div style="display:flex; gap:var(--space-sm);" class="no-print">
          <a href="?date_from=<?= urlencode($date_from) ?>&date_to=<?= urlencode($date_to) ?><?= $search ? '&q='.urlencode($search) : '' ?>"
             class="btn btn-secondary btn-sm">← All GRNs</a>
        </div>
      </div>

      <!-- GRN totals for this date -->
      <?php
        $grnTotalUnits = array_sum(array_column($grnLines, 'quantity'));
        $grnTotalValue = array_sum(array_column($grnLines, 'line_total'));
      ?>
      <div style="display:flex; gap:var(--space-xl); padding:var(--space-md) var(--space-lg); background:var(--bg); border-bottom:1px solid var(--border);">
        <span class="text-sm"><strong><?= count($grnLines) ?></strong> <span class="text-muted">line items</span></span>
        <span class="text-sm"><strong><?= number_format($grnTotalUnits) ?></strong> <span class="text-muted">units received</span></span>
        <span class="text-sm"><strong style="color:var(--primary)">KES <?= number_format($grnTotalValue, 2) ?></strong> <span class="text-muted">total value</span></span>
      </div>

      <div style="overflow-x:auto;">
        <table class="table">
          <thead>
            <tr>
              <th>#</th>
              <th>Item Name</th>
              <th>Barcode</th>
              <th>SKU</th>
              <th>Category</th>
              <th>Unit</th>
              <th class="text-right">Qty Received</th>
              <th class="text-right">Unit Cost (KES)</th>
              <th class="text-right">Line Total (KES)</th>
              <th>Ironed</th>
              <th>Steamed</th>
              <th>Hanged</th>
              <th>Time</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($grnLines as $i => $line): ?>
            <tr>
              <td class="text-muted font-mono"><?= $i + 1 ?></td>
              <td class="font-semibold"><?= htmlspecialchars($line['item_name']) ?></td>
              <td class="font-mono text-sm text-muted"><?= htmlspecialchars($line['barcode']) ?></td>
              <td class="font-mono text-sm text-muted"><?= htmlspecialchars($line['sku_code'] ?? '—') ?></td>
              <td class="text-sm"><?= htmlspecialchars($line['category'] ?? '—') ?></td>
              <td class="text-sm text-muted"><?= htmlspecialchars($line['unit'] ?? '—') ?></td>
              <td class="text-right font-mono font-bold text-primary"><?= number_format((int)$line['quantity']) ?></td>
              <td class="text-right font-mono"><?= $line['price'] ? number_format((float)$line['price'], 2) : '—' ?></td>
              <td class="text-right font-mono font-bold"><?= $line['line_total'] ? number_format((float)$line['line_total'], 2) : '—' ?></td>
              <td>
                <span class="process-chip <?= $line['is_ironed'] ? 'chip-yes' : 'chip-no' ?>">
                  <?= $line['is_ironed'] ? '✓ Yes' : '— No' ?>
                </span>
              </td>
              <td>
                <span class="process-chip <?= $line['is_steamed'] ? 'chip-yes' : 'chip-no' ?>">
                  <?= $line['is_steamed'] ? '✓ Yes' : '— No' ?>
                </span>
              </td>
              <td>
                <span class="process-chip <?= $line['is_hanged'] ? 'chip-yes' : 'chip-no' ?>">
                  <?= $line['is_hanged'] ? '✓ Yes' : '— No' ?>
                </span>
              </td>
              <td class="font-mono text-sm text-muted"><?= date('H:i', strtotime($line['created_at'])) ?></td>
            </tr>
            <?php endforeach; ?>
          </tbody>
          <tfoot>
            <tr style="border-top:2px solid var(--border);">
              <td colspan="6" class="text-right font-bold">Totals</td>
              <td class="text-right font-mono font-bold text-primary"><?= number_format($grnTotalUnits) ?></td>
              <td></td>
              <td class="text-right font-mono font-bold text-primary">KES <?= number_format($grnTotalValue, 2) ?></td>
              <td colspan="4"></td>
            </tr>
          </tfoot>
        </table>
      </div>
    </div>

    <?php elseif ($grn_date): ?>
      <div class="card mb-lg">
        <div class="text-center text-muted" style="padding:var(--space-xl);">No stock received on <?= date('d M Y', strtotime($grn_date)) ?>.</div>
      </div>
    <?php endif; ?>

    <!-- GRN Summary table (one row per receipt date) -->
    <?php if (!$grn_date || !empty($grnLines)): ?>
    <div class="card">
      <div class="card-header">
        <div class="card-title">GRN Register</div>
        <span class="text-muted text-sm"><?= count($grnSummary) ?> receipt day<?= count($grnSummary) !== 1 ? 's' : '' ?></span>
      </div>

      <?php if (empty($grnSummary)): ?>
        <div class="text-center text-muted" style="padding:var(--space-xl);">No goods received in this period.</div>
      <?php else: ?>
      <div style="overflow-x:auto;">
        <table class="table">
          <thead>
            <tr>
              <th>#</th>
              <th>GRN Reference</th>
              <th>Receipt Date</th>
              <th class="text-right">Line Items</th>
              <th class="text-right">Total Units</th>
              <th class="text-right">Total Value (KES)</th>
              <th>Ironed</th>
              <th>Steamed</th>
              <th>Hanged</th>
              <th class="no-print"></th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($grnSummary as $i => $grn):
              $allIroned  = $grn['ironed_lines']  == $grn['line_count'];
              $allSteamed = $grn['steamed_lines'] == $grn['line_count'];
              $allHanged  = $grn['hanged_lines']  == $grn['line_count'];
            ?>
            <tr>
              <td class="text-muted font-mono"><?= $i + 1 ?></td>
              <td class="font-mono font-bold text-primary"><?= htmlspecialchars($grnRef($grn['grn_date'])) ?></td>
              <td class="font-mono"><?= date('D, d M Y', strtotime($grn['grn_date'])) ?></td>
              <td class="text-right font-mono"><?= number_format((int)$grn['line_count']) ?></td>
              <td class="text-right font-mono font-bold text-primary"><?= number_format((int)$grn['total_units']) ?></td>
              <td class="text-right font-mono font-bold">KES <?= number_format((float)$grn['total_value'], 2) ?></td>
              <td>
                <span class="process-chip <?= $allIroned ? 'chip-yes' : 'chip-no' ?>">
                  <?= $allIroned ? '✓ All' : number_format($grn['ironed_lines']) . '/' . $grn['line_count'] ?>
                </span>
              </td>
              <td>
                <span class="process-chip <?= $allSteamed ? 'chip-yes' : 'chip-no' ?>">
                  <?= $allSteamed ? '✓ All' : number_format($grn['steamed_lines']) . '/' . $grn['line_count'] ?>
                </span>
              </td>
              <td>
                <span class="process-chip <?= $allHanged ? 'chip-yes' : 'chip-no' ?>">
                  <?= $allHanged ? '✓ All' : number_format($grn['hanged_lines']) . '/' . $grn['line_count'] ?>
                </span>
              </td>
              <td class="no-print">
                <a href="?grn_date=<?= urlencode($grn['grn_date']) ?>&date_from=<?= urlencode($date_from) ?>&date_to=<?= urlencode($date_to) ?>"
                   class="btn btn-primary btn-sm">Open GRN</a>
              </td>
            </tr>
            <?php endforeach; ?>
          </tbody>
          <tfoot>
            <tr style="border-top:2px solid var(--border);">
              <td colspan="4" class="text-right font-bold">Period Totals</td>
              <td class="text-right font-mono font-bold text-primary">
                <?= number_format(array_sum(array_column($grnSummary, 'total_units'))) ?>
              </td>
              <td class="text-right font-mono font-bold text-primary">
                KES <?= number_format(array_sum(array_column($grnSummary, 'total_value')), 2) ?>
              </td>
              <td colspan="4"></td>
            </tr>
          </tfoot>
        </table>
      </div>
      <?php endif; ?>
    </div>
    <?php endif; ?>

  </div>
</body>
</html>
