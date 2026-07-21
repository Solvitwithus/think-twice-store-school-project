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
        $where            = "WHERE r.status = :status";
        $params['status'] = $status_filter;
    }

    $stmt = $conn->prepare("
        SELECT r.id, r.requisition_date, r.due_date, r.status, r.memo, r.created_at,
               s.company_name AS supplier_name,
               COUNT(ri.id) AS item_count,
               COALESCE(SUM(ri.total), 0) AS total_value
        FROM requisitions r
        LEFT JOIN suppliers s ON r.supplier = s.id
        LEFT JOIN requisition_items ri ON ri.requisition_id = r.id
        $where
        GROUP BY r.id
        ORDER BY r.created_at DESC
    ");
    $stmt->execute($params);
    $requisitions = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $selectedId = isset($_GET['id']) ? (int)$_GET['id'] : null;
    $lineItems  = [];
    if ($selectedId) {
        $lineStmt = $conn->prepare("SELECT * FROM requisition_items WHERE requisition_id = :id ORDER BY id");
        $lineStmt->execute(['id' => $selectedId]);
        $lineItems = $lineStmt->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (PDOException $e) {
    $requisitions = [];
    $error        = $e->getMessage();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Purchase Requisitions - Think Twice</title>
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
    <h1 class="page-title">Purchase Requisitions</h1>
    <p class="page-subtitle"><?= count($requisitions) ?> requisition<?= count($requisitions) !== 1 ? 's' : '' ?></p>
  </div>

  <div class="page-content">

    <?php if (!empty($error)): ?>
      <div class="alert alert-danger">⚠ <?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <form method="GET" class="no-print" style="display:flex;gap:var(--space-md);align-items:flex-end;margin-bottom:var(--space-lg);flex-wrap:wrap;">
      <div style="display:flex;flex-direction:column;">
        <label>Status</label>
        <select name="status">
          <option value="all"      <?= $status_filter === 'all'      ? 'selected' : '' ?>>All</option>
          <option value="pending"  <?= $status_filter === 'pending'  ? 'selected' : '' ?>>Pending</option>
          <option value="approved" <?= $status_filter === 'approved' ? 'selected' : '' ?>>Approved</option>
          <option value="rejected" <?= $status_filter === 'rejected' ? 'selected' : '' ?>>Rejected</option>
        </select>
      </div>
      <button type="submit" class="btn btn-primary btn-sm">Filter</button>
      <a href="?" class="btn btn-secondary btn-sm">Reset</a>
      <button type="button" onclick="window.print()" class="btn btn-secondary btn-sm" style="margin-left:auto;">Print</button>
    </form>

    <?php if ($selectedId && !empty($lineItems)): ?>
    <div class="card mb-lg">
      <div class="card-header">
        <div class="card-title">Requisition #<?= str_pad($selectedId, 4, '0', STR_PAD_LEFT) ?> — Line Items</div>
        <a href="?status=<?= urlencode($status_filter) ?>" class="btn btn-secondary btn-sm no-print">← Back</a>
      </div>
      <div style="overflow-x:auto;">
        <table class="table">
          <thead>
            <tr>
              <th>#</th>
              <th>Code</th>
              <th>Description</th>
              <th class="text-right">Qty</th>
              <th class="text-right">Unit Price</th>
              <th class="text-right">Total (KES)</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($lineItems as $i => $li): ?>
            <tr>
              <td class="text-muted"><?= $i + 1 ?></td>
              <td class="font-mono text-muted text-sm"><?= htmlspecialchars($li['item_code'] ?? '—') ?></td>
              <td><?= htmlspecialchars($li['description'] ?? '—') ?></td>
              <td class="text-right font-mono"><?= number_format((int)$li['quantity']) ?></td>
              <td class="text-right font-mono"><?= number_format((float)$li['price'], 2) ?></td>
              <td class="text-right font-mono font-bold text-primary"><?= number_format((float)$li['total'], 2) ?></td>
            </tr>
            <?php endforeach; ?>
          </tbody>
          <tfoot>
            <tr style="border-top:2px solid var(--border);">
              <td colspan="5" class="text-right font-bold">Grand Total</td>
              <td class="text-right font-mono font-bold text-primary">
                <?= number_format(array_sum(array_column($lineItems, 'total')), 2) ?>
              </td>
            </tr>
          </tfoot>
        </table>
      </div>
    </div>
    <?php endif; ?>

    <div class="card">
      <div class="card-header">
        <div class="card-title">All Requisitions</div>
        <span class="text-muted text-sm"><?= count($requisitions) ?> records</span>
      </div>

      <?php if (empty($requisitions)): ?>
        <div class="text-center text-muted" style="padding: var(--space-xl);">No requisitions found.</div>
      <?php else: ?>
        <div style="overflow-x: auto;">
          <table class="table">
            <thead>
              <tr>
                <th>#</th>
                <th>REQ ID</th>
                <th>Supplier</th>
                <th>Date</th>
                <th>Due Date</th>
                <th class="text-right">Items</th>
                <th class="text-right">Total (KES)</th>
                <th>Memo</th>
                <th>Status</th>
                <th class="no-print"></th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($requisitions as $i => $req):
                $badge = match(strtolower($req['status'])) {
                  'approved' => 'badge-success',
                  'rejected' => 'badge-danger',
                  default    => 'badge-warn',
                };
              ?>
              <tr>
                <td class="text-muted font-mono"><?= $i + 1 ?></td>
                <td class="font-mono font-bold"><?= str_pad($req['id'], 4, '0', STR_PAD_LEFT) ?></td>
                <td class="font-semibold"><?= htmlspecialchars($req['supplier_name'] ?? 'Unknown') ?></td>
                <td class="font-mono text-sm"><?= $req['requisition_date'] ? date('d M Y', strtotime($req['requisition_date'])) : '—' ?></td>
                <td class="font-mono text-sm"><?= $req['due_date'] ? date('d M Y', strtotime($req['due_date'])) : '—' ?></td>
                <td class="text-right font-mono"><?= number_format((int)$req['item_count']) ?></td>
                <td class="text-right font-mono font-bold text-primary"><?= number_format((float)$req['total_value'], 2) ?></td>
                <td class="text-muted text-sm"><?= htmlspecialchars(mb_strimwidth($req['memo'] ?? '—', 0, 40, '…')) ?></td>
                <td><span class="badge <?= $badge ?>"><?= ucfirst($req['status']) ?></span></td>
                <td class="no-print">
                  <a href="?id=<?= $req['id'] ?>&status=<?= urlencode($status_filter) ?>" class="btn btn-secondary btn-sm">Details</a>
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
