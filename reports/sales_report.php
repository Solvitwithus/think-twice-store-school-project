<?php
session_start();
require __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/authGuard.php';
requireLogin();

$date_from = $_GET['date_from'] ?? date('Y-m-d', strtotime('-30 days'));
$date_to   = $_GET['date_to']   ?? date('Y-m-d');
$status    = $_GET['status']    ?? 'all';

try {
    $params = ['from' => $date_from, 'to' => $date_to];
    $statusFilter = '';
    if ($status === 'success') { $statusFilter = "AND result_code = 0"; }
    elseif ($status === 'failed') { $statusFilter = "AND result_code != 0"; }

    $stmt = $conn->prepare("
        SELECT id, mpesa_receipt, phone, amount, transaction_date,
               created_at, result_code, result_desc, matched
        FROM mpesa_transactions
        WHERE DATE(created_at) BETWEEN :from AND :to
        $statusFilter
        ORDER BY created_at DESC
        LIMIT 500
    ");
    $stmt->execute($params);
    $transactions = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $success_rows = array_filter($transactions, fn($r) => $r['result_code'] == 0);
    $totalCollected = array_sum(array_column(array_values($success_rows), 'amount'));
    $successRate = count($transactions) > 0 ? round((count($success_rows) / count($transactions)) * 100, 1) : 0;
} catch (PDOException $e) {
    $transactions = [];
    $error = $e->getMessage();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>M-Pesa Transactions - Think Twice</title>
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
    <h1 class="page-title">M-Pesa Transactions</h1>
    <p class="page-subtitle"><?= date('d M Y', strtotime($date_from)) ?> — <?= date('d M Y', strtotime($date_to)) ?></p>
  </div>

  <div class="page-content">

    <?php if (!empty($error)): ?>
      <div class="alert alert-danger">⚠ <?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <div class="grid grid-4 mb-lg no-print">
      <div class="stat-box">
        <div class="stat-value"><?= count($transactions) ?></div>
        <div class="stat-label">Total Transactions</div>
      </div>
      <div class="stat-box">
        <div class="stat-value" style="color:var(--success)"><?= count($success_rows ?? []) ?></div>
        <div class="stat-label">Successful</div>
      </div>
      <div class="stat-box">
        <div class="stat-value" style="color:var(--danger)"><?= count($transactions) - count($success_rows ?? []) ?></div>
        <div class="stat-label">Failed</div>
      </div>
      <div class="stat-box">
        <div class="stat-value">KES <?= number_format($totalCollected ?? 0, 0) ?></div>
        <div class="stat-label">Total Collected</div>
      </div>
    </div>

    <form method="GET" class="no-print" style="display:flex;gap:var(--space-md);align-items:flex-end;margin-bottom:var(--space-lg);flex-wrap:wrap;">
      <div style="display:flex;flex-direction:column;"><label>From</label><input type="date" name="date_from" value="<?= htmlspecialchars($date_from) ?>"></div>
      <div style="display:flex;flex-direction:column;"><label>To</label><input type="date" name="date_to" value="<?= htmlspecialchars($date_to) ?>"></div>
      <div style="display:flex;flex-direction:column;">
        <label>Status</label>
        <select name="status">
          <option value="all"     <?= $status === 'all'     ? 'selected' : '' ?>>All</option>
          <option value="success" <?= $status === 'success' ? 'selected' : '' ?>>Successful</option>
          <option value="failed"  <?= $status === 'failed'  ? 'selected' : '' ?>>Failed</option>
        </select>
      </div>
      <button type="submit" class="btn btn-primary btn-sm">Apply</button>
      <a href="?" class="btn btn-secondary btn-sm">Reset</a>
      <button type="button" onclick="window.print()" class="btn btn-secondary btn-sm" style="margin-left:auto;">Print</button>
    </form>

    <div class="card">
      <div class="card-header">
        <div class="card-title">Transaction Log</div>
        <span class="text-muted text-sm"><?= count($transactions) ?> records · success rate <?= $successRate ?? 0 ?>%</span>
      </div>

      <?php if (empty($transactions)): ?>
        <div class="text-center text-muted" style="padding: var(--space-xl);">No transactions found.</div>
      <?php else: ?>
        <div style="overflow-x: auto;">
          <table class="table">
            <thead>
              <tr>
                <th>#</th>
                <th>Receipt</th>
                <th>Phone</th>
                <th class="text-right">Amount (KES)</th>
                <th>Txn Time</th>
                <th>Recorded</th>
                <th>Matched</th>
                <th>Status</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($transactions as $i => $tx): ?>
              <tr>
                <td class="text-muted font-mono"><?= $i + 1 ?></td>
                <td class="font-mono text-sm"><?= htmlspecialchars($tx['mpesa_receipt'] ?? '—') ?></td>
                <td class="font-mono text-muted"><?= htmlspecialchars($tx['phone'] ?? '—') ?></td>
                <td class="text-right font-mono font-bold <?= $tx['result_code'] == 0 ? 'text-primary' : 'text-muted' ?>">
                  <?= number_format((float)$tx['amount'], 2) ?>
                </td>
                <td class="font-mono text-sm text-muted"><?= htmlspecialchars($tx['transaction_date'] ?? '—') ?></td>
                <td class="font-mono text-sm text-muted"><?= date('d M Y H:i', strtotime($tx['created_at'])) ?></td>
                <td>
                  <span class="badge <?= $tx['matched'] ? 'badge-success' : 'badge-warn' ?>">
                    <?= $tx['matched'] ? 'Yes' : 'No' ?>
                  </span>
                </td>
                <td>
                  <?php if ($tx['result_code'] == 0): ?>
                    <span class="badge badge-success">✓ Success</span>
                  <?php else: ?>
                    <span class="badge badge-danger">✗ <?= (int)$tx['result_code'] ?></span>
                  <?php endif; ?>
                </td>
              </tr>
              <?php endforeach; ?>
            </tbody>
            <tfoot>
              <tr style="border-top:2px solid var(--border);">
                <td colspan="3" class="text-right font-bold">Total Collected</td>
                <td class="text-right font-mono font-bold text-primary">
                  <?= number_format($totalCollected ?? 0, 2) ?>
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
