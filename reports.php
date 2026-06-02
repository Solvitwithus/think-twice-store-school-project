<?php
session_start();
require __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/authGuard.php';

requireLogin();

$error = '';
$report_type = $_GET['type'] ?? 'sales';
$data = [];

try {
  // Sales Report
  if ($report_type === 'sales') {
    $stmt = $conn->prepare("
      SELECT 
        DATE(created_at) as sale_date,
        COUNT(*) as total_sales,
        SUM(amount) as revenue
      FROM mpesa_transactions
      WHERE result_code = 0
      GROUP BY DATE(created_at)
      ORDER BY sale_date DESC
      LIMIT 30
    ");
    $stmt->execute();
    $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
  }

  // Inventory Report
  elseif ($report_type === 'inventory') {
    $stmt = $conn->prepare("
      SELECT 
        item_name,
        barcode,
        quantity,
        price,
        (quantity * price) as value
      FROM stock_movements
      GROUP BY item_name, barcode
      ORDER BY value DESC
      LIMIT 50
    ");
    $stmt->execute();
    $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
  }

  // Supplier Report
  elseif ($report_type === 'suppliers') {
    $stmt = $conn->prepare("
      SELECT 
        s.name,
        s.phone,
        s.email,
        COUNT(DISTINCT sm.id) as items_supplied
      FROM suppliers s
      LEFT JOIN stock_movements sm ON sm.supplier_id = s.id
      GROUP BY s.id, s.name, s.phone, s.email
      ORDER BY items_supplied DESC
    ");
    $stmt->execute();
    $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
  }

  // Transactions Report
  elseif ($report_type === 'transactions') {
    $stmt = $conn->prepare("
      SELECT 
        id,
        mpesa_receipt,
        phone,
        amount,
        transaction_date,
        created_at,
        result_code
      FROM mpesa_transactions
      ORDER BY created_at DESC
      LIMIT 100
    ");
    $stmt->execute();
    $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
  }

} catch (PDOException $e) {
  $error = 'Error fetching report: ' . $e->getMessage();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Reports - Think Twice</title>
  <link rel="stylesheet" href="/think-twice/public/theme.css">
</head>
<body class="page-container">
  
  <?php include 'navbar.php'; ?>

  <div class="page-header">
    <h1 class="page-title">Reports & Analytics</h1>
    <p class="page-subtitle">View detailed reports on sales, inventory, and transactions</p>
  </div>

  <div class="page-content">

    <?php if ($error): ?>
      <div class="alert alert-danger">⚠ <?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <!-- REPORT SELECTOR -->
    <div class="card mb-lg">
      <div class="card-body" style="display: flex; gap: 12px; flex-wrap: wrap;">
        <a href="?type=sales" class="btn <?= $report_type === 'sales' ? 'btn-primary' : 'btn-secondary' ?>">Sales Report</a>
        <a href="?type=inventory" class="btn <?= $report_type === 'inventory' ? 'btn-primary' : 'btn-secondary' ?>">Inventory Report</a>
        <a href="?type=suppliers" class="btn <?= $report_type === 'suppliers' ? 'btn-primary' : 'btn-secondary' ?>">Suppliers Report</a>
        <a href="?type=transactions" class="btn <?= $report_type === 'transactions' ? 'btn-primary' : 'btn-secondary' ?>">Transactions Report</a>
      </div>
    </div>

    <!-- REPORT CONTENT -->
    <div class="card">
      <div class="card-header flex-between">
        <div>
          <div class="card-title">
            <?php 
              $titles = [
                'sales' => 'Daily Sales Report',
                'inventory' => 'Inventory Stock Report',
                'suppliers' => 'Suppliers Performance',
                'transactions' => 'M-Pesa Transactions'
              ];
              echo $titles[$report_type] ?? 'Report';
            ?>
          </div>
          <div class="card-subtitle">Generated: <?= date('d M Y H:i') ?></div>
        </div>
        <button onclick="window.print()" class="btn btn-secondary btn-sm">Print</button>
      </div>

      <div class="card-body">
        <?php if (empty($data)): ?>
          <div class="text-center text-muted">
            <p>No data available for this report.</p>
          </div>
        <?php else: ?>
          <div style="overflow-x: auto;">
            <table class="table">
              <thead>
                <tr>
                  <?php 
                    if ($report_type === 'sales'): ?>
                      <th>Date</th>
                      <th>Total Sales</th>
                      <th>Revenue (KES)</th>
                    <?php elseif ($report_type === 'inventory'): ?>
                      <th>Item Name</th>
                      <th>Barcode</th>
                      <th>Quantity</th>
                      <th>Unit Price (KES)</th>
                      <th>Total Value (KES)</th>
                    <?php elseif ($report_type === 'suppliers'): ?>
                      <th>Supplier Name</th>
                      <th>Phone</th>
                      <th>Email</th>
                      <th>Items Supplied</th>
                    <?php elseif ($report_type === 'transactions'): ?>
                      <th>Receipt #</th>
                      <th>Phone</th>
                      <th>Amount (KES)</th>
                      <th>Date & Time</th>
                      <th>Status</th>
                    <?php endif; ?>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($data as $row): ?>
                  <tr>
                    <?php 
                      if ($report_type === 'sales'):
                        echo '<td>' . htmlspecialchars($row['sale_date']) . '</td>';
                        echo '<td class="font-mono">' . $row['total_sales'] . '</td>';
                        echo '<td class="text-primary font-bold">KES ' . number_format($row['revenue'], 2) . '</td>';
                      
                      elseif ($report_type === 'inventory'):
                        echo '<td>' . htmlspecialchars($row['item_name']) . '</td>';
                        echo '<td class="font-mono text-sm">' . htmlspecialchars($row['barcode']) . '</td>';
                        echo '<td class="font-mono">' . $row['quantity'] . '</td>';
                        echo '<td class="font-mono">KES ' . number_format($row['price'], 2) . '</td>';
                        echo '<td class="text-primary font-bold">KES ' . number_format($row['value'], 2) . '</td>';
                      
                      elseif ($report_type === 'suppliers'):
                        echo '<td>' . htmlspecialchars($row['name']) . '</td>';
                        echo '<td class="font-mono">' . htmlspecialchars($row['phone']) . '</td>';
                        echo '<td class="text-muted">' . htmlspecialchars($row['email'] ?? '-') . '</td>';
                        echo '<td class="font-bold">' . $row['items_supplied'] . '</td>';
                      
                      elseif ($report_type === 'transactions'):
                        echo '<td class="font-mono">' . htmlspecialchars($row['mpesa_receipt']) . '</td>';
                        echo '<td class="font-mono">' . htmlspecialchars($row['phone']) . '</td>';
                        echo '<td class="text-primary font-bold">KES ' . number_format($row['amount'], 2) . '</td>';
                        echo '<td>' . htmlspecialchars($row['transaction_date']) . '</td>';
                        echo '<td>';
                        if ($row['result_code'] == 0) {
                          echo '<span class="badge badge-success">✓ Success</span>';
                        } else {
                          echo '<span class="badge badge-danger">✗ Failed</span>';
                        }
                        echo '</td>';
                      endif;
                    ?>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php endif; ?>
      </div>
    </div>

  </div>

  <style>
    @media print {
      .navigation-header, .page-header, .alert { display: none; }
      .card { border: 1px solid #999; box-shadow: none; }
    }
  </style>

</body>
</html>
