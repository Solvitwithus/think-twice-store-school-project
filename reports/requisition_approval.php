<?php
session_start();
require __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/authGuard.php';
requireLogin();

$status_filter = $_GET['status'] ?? 'all';
$date_from     = $_GET['date_from'] ?? date('Y-m-d', strtotime('-90 days'));
$date_to       = $_GET['date_to']   ?? date('Y-m-d');
$selectedId    = isset($_GET['id']) ? (int)$_GET['id'] : null;

try {
    /* ── KPIs across the date range ─────────────────────────── */
    $kpiStmt = $conn->prepare("
        SELECT LOWER(r.status) AS status,
               COUNT(DISTINCT r.id)       AS cnt,
               COALESCE(SUM(ri.total), 0) AS val
        FROM requisitions r
        LEFT JOIN requisition_items ri ON ri.requisition_id = r.id
        WHERE DATE(r.created_at) BETWEEN :from AND :to
        GROUP BY LOWER(r.status)
    ");
    $kpiStmt->execute(['from' => $date_from, 'to' => $date_to]);
    $kpis = ['pending' => 0, 'approved' => 0, 'received' => 0, 'rejected' => 0, 'total_val' => 0.0];
    foreach ($kpiStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $s = $row['status'];
        if (isset($kpis[$s])) $kpis[$s] = (int)$row['cnt'];
        $kpis['total_val'] += (float)$row['val'];
    }
    $kpis['total'] = $kpis['pending'] + $kpis['approved'] + $kpis['received'] + $kpis['rejected'];

    /* ── Requisition list ────────────────────────────────────── */
    $params = ['from' => $date_from, 'to' => $date_to];
    $where  = "WHERE DATE(r.created_at) BETWEEN :from AND :to";
    if ($status_filter !== 'all') {
        $where .= " AND LOWER(r.status) = :status";
        $params['status'] = strtolower($status_filter);
    }

    $stmt = $conn->prepare("
        SELECT r.id, r.requisition_date, r.due_date, r.status, r.memo, r.created_at,
               s.company_name AS supplier_name,
               COUNT(ri.id)               AS item_count,
               COALESCE(SUM(ri.total), 0) AS total_value,
               DATEDIFF(NOW(), r.created_at) AS days_open
        FROM requisitions r
        LEFT JOIN suppliers s ON r.supplier = s.id
        LEFT JOIN requisition_items ri ON ri.requisition_id = r.id
        $where
        GROUP BY r.id, r.requisition_date, r.due_date, r.status, r.memo, r.created_at, s.company_name
        ORDER BY r.created_at DESC
    ");
    $stmt->execute($params);
    $requisitions = $stmt->fetchAll(PDO::FETCH_ASSOC);

    /* ── Drill-down for selected requisition ─────────────────── */
    $lineItems = [];
    $selectedReq = null;
    if ($selectedId) {
        $rStmt = $conn->prepare("
            SELECT r.*, s.company_name AS supplier_name, s.phone AS supplier_phone,
                   s.email AS supplier_email, s.city AS supplier_city
            FROM requisitions r
            LEFT JOIN suppliers s ON r.supplier = s.id
            WHERE r.id = :id
        ");
        $rStmt->execute(['id' => $selectedId]);
        $selectedReq = $rStmt->fetch(PDO::FETCH_ASSOC);

        $liStmt = $conn->prepare("SELECT * FROM requisition_items WHERE requisition_id = :id ORDER BY id");
        $liStmt->execute(['id' => $selectedId]);
        $lineItems = $liStmt->fetchAll(PDO::FETCH_ASSOC);
    }

} catch (PDOException $e) {
    $requisitions = [];
    $error = $e->getMessage();
    $kpis  = ['total' => 0, 'pending' => 0, 'approved' => 0, 'received' => 0, 'rejected' => 0, 'total_val' => 0];
}

function statusBadge(string $status): string {
    return match(strtolower($status)) {
        'approved' => 'badge-success',
        'received' => 'badge-primary',
        'rejected' => 'badge-danger',
        default    => 'badge-warn',
    };
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Requisition Approval Report - Think Twice</title>
  <link rel="stylesheet" href="/think-twice/public/theme.css?v=2">
  <style>
    .approval-timeline { display:flex; gap:0; margin-bottom: var(--space-xl); }
    .tl-step { flex:1; position:relative; text-align:center; }
    .tl-step:not(:last-child)::after {
      content:''; position:absolute; top:16px; left:50%; width:100%;
      height:3px; background:var(--border); z-index:0;
    }
    .tl-dot { width:34px; height:34px; border-radius:50%; display:inline-flex;
              align-items:center; justify-content:center; font-size:14px; font-weight:700;
              position:relative; z-index:1; }
    .tl-label { font-size:11px; margin-top:4px; color:var(--text-muted); }
    .tl-count { font-size:18px; font-weight:700; }
    .req-detail-header { background:var(--surface); border:1px solid var(--border);
                         border-radius:var(--radius-lg); padding:var(--space-lg);
                         margin-bottom:var(--space-lg); }
    .req-meta-grid { display:grid; grid-template-columns:repeat(auto-fit,minmax(160px,1fr));
                     gap:var(--space-md); margin-top:var(--space-md); }
    .req-meta-item label { font-size:11px; text-transform:uppercase; letter-spacing:.4px;
                           color:var(--text-muted); display:block; margin-bottom:2px; }
    .req-meta-item span { font-weight:600; font-size:14px; color:var(--text); }
    @media print {
      .navigation-header, .no-print { display:none !important; }
      body { background:#fff; color:#000; }
    }
  </style>
</head>
<body class="page-container">

  <div class="no-print">
    <?php include __DIR__ . '/../navbar.php'; ?>
  </div>

  <div class="page-header">
    <h1 class="page-title">Requisition Approval Report</h1>
    <p class="page-subtitle">Track procurement requests from creation through approval to receipt</p>
  </div>

  <div class="page-content">

    <?php if (!empty($error)): ?>
      <div class="alert alert-danger">⚠ <?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <!-- KPI Summary -->
    <div class="grid grid-4 mb-lg no-print">
      <div class="stat-box">
        <div class="stat-value"><?= $kpis['total'] ?></div>
        <div class="stat-label">Total Requisitions</div>
      </div>
      <div class="stat-box">
        <div class="stat-value" style="color:var(--warn)"><?= $kpis['pending'] ?></div>
        <div class="stat-label">Pending Approval</div>
      </div>
      <div class="stat-box">
        <div class="stat-value" style="color:var(--success)"><?= $kpis['received'] ?></div>
        <div class="stat-label">Received (GRN Done)</div>
      </div>
      <div class="stat-box">
        <div class="stat-value" style="color:var(--danger)"><?= $kpis['rejected'] ?></div>
        <div class="stat-label">Rejected</div>
      </div>
    </div>

    <!-- Approval pipeline visual -->
    <div class="card mb-lg no-print">
      <div class="card-header">
        <div class="card-title">Approval Pipeline</div>
        <span class="text-muted text-sm"><?= date('d M Y', strtotime($date_from)) ?> – <?= date('d M Y', strtotime($date_to)) ?></span>
      </div>
      <div style="padding: var(--space-lg);">
        <div class="approval-timeline">
          <?php
          $steps = [
            ['label' => 'Raised',   'key' => 'total',    'color' => 'var(--text-muted)',  'bg' => 'var(--border)',   'icon' => '✎'],
            ['label' => 'Pending',  'key' => 'pending',  'color' => 'var(--warn)',         'bg' => 'rgba(224,123,0,0.15)', 'icon' => '⏳'],
            ['label' => 'Approved', 'key' => 'approved', 'color' => 'var(--info)',         'bg' => 'rgba(37,99,235,0.12)', 'icon' => '✓'],
            ['label' => 'Received', 'key' => 'received', 'color' => 'var(--success)',      'bg' => 'rgba(0,153,94,0.12)',  'icon' => '📦'],
          ];
          foreach ($steps as $step): ?>
          <div class="tl-step">
            <div class="tl-dot" style="background:<?= $step['bg'] ?>; color:<?= $step['color'] ?>;">
              <?= $step['icon'] ?>
            </div>
            <div class="tl-count" style="color:<?= $step['color'] ?>;"><?= $kpis[$step['key']] ?></div>
            <div class="tl-label"><?= $step['label'] ?></div>
          </div>
          <?php endforeach; ?>
        </div>

        <?php if ($kpis['total'] > 0): ?>
          <?php
          $receivedPct = round(($kpis['received'] / $kpis['total']) * 100);
          $pendingPct  = round(($kpis['pending']  / $kpis['total']) * 100);
          ?>
          <div style="display:flex; gap:var(--space-md); flex-wrap:wrap; margin-top:var(--space-md);">
            <span class="text-muted text-sm">
              Fulfilment rate:
              <strong style="color:var(--success)"><?= $receivedPct ?>%</strong>
            </span>
            <span class="text-muted text-sm">
              Still pending:
              <strong style="color:var(--warn)"><?= $pendingPct ?>%</strong>
            </span>
            <span class="text-muted text-sm">
              Total value:
              <strong style="color:var(--primary)">KES <?= number_format($kpis['total_val'], 2) ?></strong>
            </span>
          </div>
        <?php endif; ?>
      </div>
    </div>

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
        <label>Status</label>
        <select name="status">
          <option value="all"      <?= $status_filter === 'all'      ? 'selected' : '' ?>>All Statuses</option>
          <option value="pending"  <?= $status_filter === 'pending'  ? 'selected' : '' ?>>Pending</option>
          <option value="approved" <?= $status_filter === 'approved' ? 'selected' : '' ?>>Approved</option>
          <option value="received" <?= $status_filter === 'received' ? 'selected' : '' ?>>Received</option>
          <option value="rejected" <?= $status_filter === 'rejected' ? 'selected' : '' ?>>Rejected</option>
        </select>
      </div>
      <button type="submit" class="btn btn-primary btn-sm">Apply</button>
      <a href="?" class="btn btn-secondary btn-sm">Reset</a>
      <a href="/think-twice/inventory/requisitions.php" class="btn btn-secondary btn-sm">+ New Requisition</a>
      <button type="button" onclick="window.print()" class="btn btn-secondary btn-sm" style="margin-left:auto;">Print</button>
    </form>

    <!-- Drill-down panel -->
    <?php if ($selectedId && $selectedReq): ?>
    <div class="req-detail-header mb-lg">
      <div style="display:flex; justify-content:space-between; align-items:flex-start; flex-wrap:wrap; gap:var(--space-md);">
        <div>
          <div style="font-size:11px; text-transform:uppercase; letter-spacing:.5px; color:var(--text-muted);">Purchase Requisition</div>
          <div style="font-size:22px; font-weight:700; color:var(--text);">
            REQ-<?= str_pad($selectedReq['id'], 4, '0', STR_PAD_LEFT) ?>
          </div>
        </div>
        <div style="display:flex; gap:var(--space-sm); align-items:center;" class="no-print">
          <span class="badge <?= statusBadge($selectedReq['status']) ?>" style="font-size:13px; padding:6px 14px;">
            <?= ucfirst($selectedReq['status']) ?>
          </span>
          <a href="?date_from=<?= urlencode($date_from) ?>&date_to=<?= urlencode($date_to) ?>&status=<?= urlencode($status_filter) ?>"
             class="btn btn-secondary btn-sm">← Back to List</a>
        </div>
      </div>

      <div class="req-meta-grid">
        <div class="req-meta-item">
          <label>Supplier</label>
          <span><?= htmlspecialchars($selectedReq['supplier_name'] ?? 'Not specified') ?></span>
        </div>
        <div class="req-meta-item">
          <label>Supplier Phone</label>
          <span><?= htmlspecialchars($selectedReq['supplier_phone'] ?? '—') ?></span>
        </div>
        <div class="req-meta-item">
          <label>Requisition Date</label>
          <span><?= $selectedReq['requisition_date'] ? date('d M Y', strtotime($selectedReq['requisition_date'])) : '—' ?></span>
        </div>
        <div class="req-meta-item">
          <label>Due Date</label>
          <span><?= $selectedReq['due_date'] ? date('d M Y', strtotime($selectedReq['due_date'])) : '—' ?></span>
        </div>
        <div class="req-meta-item">
          <label>Created</label>
          <span><?= date('d M Y H:i', strtotime($selectedReq['created_at'])) ?></span>
        </div>
        <div class="req-meta-item">
          <label>Memo / Notes</label>
          <span><?= htmlspecialchars($selectedReq['memo'] ?: '—') ?></span>
        </div>
      </div>
    </div>

    <?php if (!empty($lineItems)): ?>
    <div class="card mb-lg">
      <div class="card-header">
        <div class="card-title">Line Items — REQ-<?= str_pad($selectedId, 4, '0', STR_PAD_LEFT) ?></div>
        <span class="text-muted text-sm"><?= count($lineItems) ?> items</span>
      </div>
      <div style="overflow-x:auto;">
        <table class="table">
          <thead>
            <tr>
              <th>#</th>
              <th>Item Code</th>
              <th>Description</th>
              <th class="text-right">Qty Requested</th>
              <th class="text-right">Unit Price (KES)</th>
              <th class="text-right">Line Total (KES)</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($lineItems as $i => $li): ?>
            <tr>
              <td class="text-muted font-mono"><?= $i + 1 ?></td>
              <td class="font-mono text-sm"><?= htmlspecialchars($li['item_code'] ?? '—') ?></td>
              <td><?= htmlspecialchars($li['description'] ?? '—') ?></td>
              <td class="text-right font-mono font-bold"><?= number_format((int)$li['quantity']) ?></td>
              <td class="text-right font-mono"><?= number_format((float)$li['price'], 2) ?></td>
              <td class="text-right font-mono font-bold text-primary"><?= number_format((float)$li['total'], 2) ?></td>
            </tr>
            <?php endforeach; ?>
          </tbody>
          <tfoot>
            <tr style="border-top:2px solid var(--border);">
              <td colspan="5" class="text-right font-bold">Grand Total</td>
              <td class="text-right font-mono font-bold text-primary" style="font-size:16px;">
                KES <?= number_format(array_sum(array_column($lineItems, 'total')), 2) ?>
              </td>
            </tr>
          </tfoot>
        </table>
      </div>
    </div>
    <?php else: ?>
      <div class="card mb-lg">
        <div class="text-center text-muted" style="padding:var(--space-xl);">No line items recorded for this requisition.</div>
      </div>
    <?php endif; ?>
    <?php endif; ?>

    <!-- Main requisitions table -->
    <div class="card">
      <div class="card-header">
        <div class="card-title">All Requisitions</div>
        <span class="text-muted text-sm"><?= count($requisitions) ?> records</span>
      </div>

      <?php if (empty($requisitions)): ?>
        <div class="text-center text-muted" style="padding:var(--space-xl);">No requisitions found for the selected period.</div>
      <?php else: ?>
      <div style="overflow-x:auto;">
        <table class="table">
          <thead>
            <tr>
              <th>#</th>
              <th>REQ ID</th>
              <th>Supplier</th>
              <th>Raised On</th>
              <th>Due Date</th>
              <th class="text-right">Items</th>
              <th class="text-right">Total Value (KES)</th>
              <th>Memo</th>
              <th>Days Open</th>
              <th>Status</th>
              <th class="no-print"></th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($requisitions as $i => $req):
              $daysOpen   = (int)$req['days_open'];
              $daysColor  = strtolower($req['status']) === 'received' ? 'text-muted' :
                            ($daysOpen > 14 ? 'text-danger' : ($daysOpen > 7 ? 'text-warn' : 'text-success'));
            ?>
            <tr>
              <td class="text-muted font-mono"><?= $i + 1 ?></td>
              <td class="font-mono font-bold text-primary">REQ-<?= str_pad($req['id'], 4, '0', STR_PAD_LEFT) ?></td>
              <td class="font-semibold"><?= htmlspecialchars($req['supplier_name'] ?? 'Unknown') ?></td>
              <td class="font-mono text-sm"><?= $req['requisition_date'] ? date('d M Y', strtotime($req['requisition_date'])) : '—' ?></td>
              <td class="font-mono text-sm"><?= $req['due_date'] ? date('d M Y', strtotime($req['due_date'])) : '—' ?></td>
              <td class="text-right font-mono"><?= number_format((int)$req['item_count']) ?></td>
              <td class="text-right font-mono font-bold text-primary"><?= number_format((float)$req['total_value'], 2) ?></td>
              <td class="text-muted text-sm"><?= htmlspecialchars(mb_strimwidth($req['memo'] ?? '—', 0, 35, '…')) ?></td>
              <td class="font-mono text-sm <?= $daysColor ?>">
                <?= strtolower($req['status']) === 'received' ? '✓ Done' : $daysOpen . 'd' ?>
              </td>
              <td>
                <span class="badge <?= statusBadge($req['status']) ?>"><?= ucfirst($req['status']) ?></span>
              </td>
              <td class="no-print">
                <a href="?id=<?= $req['id'] ?>&date_from=<?= urlencode($date_from) ?>&date_to=<?= urlencode($date_to) ?>&status=<?= urlencode($status_filter) ?>"
                   class="btn btn-secondary btn-sm">View</a>
              </td>
            </tr>
            <?php endforeach; ?>
          </tbody>
          <tfoot>
            <tr style="border-top:2px solid var(--border);">
              <td colspan="6" class="text-right font-bold">Period Total</td>
              <td class="text-right font-mono font-bold text-primary">
                KES <?= number_format(array_sum(array_column($requisitions, 'total_value')), 2) ?>
              </td>
              <td colspan="4"></td>
            </tr>
          </tfoot>
        </table>
      </div>
      <?php endif; ?>
    </div>

  </div>
</body>
</html>
