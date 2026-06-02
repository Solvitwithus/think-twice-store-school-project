<?php
session_start();
require __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/authGuard.php';

requireLogin();

try {
  // Fetch dashboard stats
  $stats = [];
  
  // Total items in inventory
  $itemsStmt = $conn->prepare("SELECT COUNT(DISTINCT item_id) as total FROM stock_movements");
  $itemsStmt->execute();
  $stats['total_items'] = $itemsStmt->fetch(PDO::FETCH_ASSOC)['total_items'] ?? 0;
  
  // Total stock value
  $valueStmt = $conn->prepare("
    SELECT SUM(quantity * (SELECT price FROM stock_movements s2 WHERE s2.item_id = s1.item_id LIMIT 1)) as total_value
    FROM (SELECT DISTINCT item_id, quantity FROM stock_movements) s1
  ");
  $valueStmt->execute();
  $stats['total_value'] = $valueStmt->fetch(PDO::FETCH_ASSOC)['total_value'] ?? 0;
  
  // Recent transactions
  $txStmt = $conn->prepare("
    SELECT mpesa_receipt, amount, transaction_date, phone, result_code 
    FROM mpesa_transactions 
    ORDER BY created_at DESC 
    LIMIT 5
  ");
  $txStmt->execute();
  $recent_transactions = $txStmt->fetchAll(PDO::FETCH_ASSOC);
  
  // Total suppliers
  $suppStmt = $conn->prepare("SELECT COUNT(*) as total FROM suppliers");
  $suppStmt->execute();
  $stats['total_suppliers'] = $suppStmt->fetch(PDO::FETCH_ASSOC)['total'] ?? 0;
  
} catch (PDOException $e) {
  $error_msg = "Database error: " . $e->getMessage();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Dashboard - Think Twice</title>
  <link rel="stylesheet" href="/think-twice/public/theme.css">
</head>
<body class="page-container">
  
  <?php include 'navbar.php'; ?>

  <div class="page-header">
    <h1 class="page-title">Dashboard</h1>
    <p class="page-subtitle">Welcome back, <?= htmlspecialchars($_SESSION['name']) ?>. Here's your inventory overview.</p>
  </div>

  <div class="page-content">
    
    <?php if (isset($error_msg)): ?>
      <div class="alert alert-danger">⚠ <?= htmlspecialchars($error_msg) ?></div>
    <?php endif; ?>

    <!-- STATS GRID -->
    <div class="grid grid-4 mb-lg">
      
      <div class="stat-box">
        <div class="stat-value"><?= $stats['total_items'] ?></div>
        <div class="stat-label">Total Items</div>
      </div>

      <div class="stat-box">
        <div class="stat-value">KES <?= number_format($stats['total_value'], 0) ?></div>
        <div class="stat-label">Stock Value</div>
      </div>

      <div class="stat-box">
        <div class="stat-value"><?= $stats['total_suppliers'] ?></div>
        <div class="stat-label">Suppliers</div>
      </div>

      <div class="stat-box">
        <div class="stat-value"><?= count($recent_transactions) ?></div>
        <div class="stat-label">Recent Transactions</div>
      </div>

    </div>

    <!-- QUICK ACTIONS -->
    <div class="grid grid-3 mb-lg">
      
      <div class="card">
        <div class="card-header">
          <div class="card-title">Quick Actions</div>
        </div>
        <div class="card-body" style="display: flex; flex-direction: column; gap: 10px;">
          <a href="/think-twice/pos.php" class="btn btn-primary btn-block">Start POS Sale</a>
          <a href="/think-twice/itemsandInventory.php" class="btn btn-secondary btn-block">Manage Inventory</a>
          <a href="/think-twice/suppliers.php" class="btn btn-secondary btn-block">View Suppliers</a>
          <a href="/think-twice/reports.php" class="btn btn-secondary btn-block">View Reports</a>
        </div>
      </div>

      <div class="card">
        <div class="card-header">
          <div class="card-title">System Info</div>
        </div>
        <div class="card-body" style="font-size: 13px; color: var(--text-muted); line-height: 1.8;">
          <strong>User:</strong> <?= htmlspecialchars($_SESSION['name']) ?><br>
          <strong>Role:</strong> <?= htmlspecialchars($_SESSION['role'] ?? 'N/A') ?><br>
          <strong>Username:</strong> <?= htmlspecialchars($_SESSION['username']) ?><br>
          <strong>Email:</strong> <?= htmlspecialchars($_SESSION['email']) ?><br>
          <strong>Session ID:</strong> <span class="font-mono text-sm"><?= substr(session_id(), 0, 8) ?>...</span>
        </div>
      </div>

      <div class="card">
        <div class="card-header">
          <div class="card-title">Permissions</div>
        </div>
        <div class="card-body" style="font-size: 13px; color: var(--text-muted); line-height: 1.8;">
          <?php if (!empty($_SESSION['permissions'])): ?>
            <?php foreach ($_SESSION['permissions'] as $perm): ?>
              <span class="badge badge-primary"><?= htmlspecialchars($perm) ?></span>
            <?php endforeach; ?>
          <?php else: ?>
            <span class="text-muted">No permissions assigned</span>
          <?php endif; ?>
        </div>
      </div>

    </div>

    <!-- RECENT TRANSACTIONS -->
    <div class="card">
      <div class="card-header">
        <div class="card-title">Recent M-Pesa Transactions</div>
        <a href="/think-twice/reports.php" class="btn btn-secondary btn-sm">View All</a>
      </div>
      
      <?php if (!empty($recent_transactions)): ?>
        <div class="card-body">
          <table class="table">
            <thead>
              <tr>
                <th>Receipt</th>
                <th>Amount</th>
                <th>Phone</th>
                <th>Date & Time</th>
                <th>Status</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($recent_transactions as $tx): ?>
                <tr>
                  <td><span class="font-mono"><?= htmlspecialchars($tx['mpesa_receipt']) ?></span></td>
                  <td class="text-primary font-bold">KES <?= number_format($tx['amount'], 2) ?></td>
                  <td class="font-mono"><?= htmlspecialchars($tx['phone']) ?></td>
                  <td><?= htmlspecialchars($tx['transaction_date']) ?></td>
                  <td>
                    <?php if ($tx['result_code'] == 0): ?>
                      <span class="badge badge-success">✓ Success</span>
                    <?php else: ?>
                      <span class="badge badge-danger">✗ Failed</span>
                    <?php endif; ?>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php else: ?>
        <div class="card-body text-center text-muted">
          <p>No transactions yet</p>
        </div>
      <?php endif; ?>
    </div>

  </div>

</body>
</html>
