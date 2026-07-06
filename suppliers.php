<?php
session_start();
require __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/authGuard.php';

requireLogin();

$error = '';
$success = '';

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  
  // Add/Update Supplier
  if (isset($_POST['action']) && $_POST['action'] === 'save') {
    $id = (int)($_POST['id'] ?? 0);
    $name = trim($_POST['name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $address = trim($_POST['address'] ?? '');

    if (!$name || !$phone) {
      $error = 'Supplier name and phone are required.';
    } else {
      try {
        if ($id > 0) {
          // Update
          $stmt = $conn->prepare("
            UPDATE suppliers 
            SET name = ?, email = ?, phone = ?, address = ?
            WHERE id = ?
          ");
          $stmt->execute([$name, $email, $phone, $address, $id]);
          $success = 'Supplier updated successfully.';
        } else {
          // Insert
          $stmt = $conn->prepare("
            INSERT INTO suppliers (name, email, phone, address)
            VALUES (?, ?, ?, ?)
          ");
          $stmt->execute([$name, $email, $phone, $address]);
          $success = 'Supplier added successfully.';
        }
      } catch (PDOException $e) {
        $error = 'Database error: ' . $e->getMessage();
      }
    }
  }

  // Delete Supplier
  if (isset($_POST['action']) && $_POST['action'] === 'delete') {
    $id = (int)$_POST['id'];
    try {
      $stmt = $conn->prepare("DELETE FROM suppliers WHERE id = ?");
      $stmt->execute([$id]);
      $success = 'Supplier deleted.';
    } catch (PDOException $e) {
      $error = 'Error deleting supplier: ' . $e->getMessage();
    }
  }
}

// Fetch suppliers
try {
  $stmt = $conn->prepare("SELECT * FROM suppliers ORDER BY name ASC");
  $stmt->execute();
  $suppliers = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
  $error = 'Error fetching suppliers: ' . $e->getMessage();
  $suppliers = [];
}

$editSupplier = null;
if (isset($_GET['edit'])) {
  $id = (int)$_GET['edit'];
  foreach ($suppliers as $s) {
    if ($s['id'] == $id) {
      $editSupplier = $s;
      break;
    }
  }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Suppliers - Think Twice</title>
  <link rel="stylesheet" href="/think-twice/public/theme.css?v=2">
</head>
<body class="page-container">
  
  <?php include 'navbar.php'; ?>

  <div class="page-header">
    <h1 class="page-title">Suppliers Management</h1>
    <p class="page-subtitle">Manage your supplier information and contacts</p>
  </div>

  <div class="page-content">

    <?php if ($error): ?>
      <div class="alert alert-danger">⚠ <?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <?php if ($success): ?>
      <div class="alert alert-success">✓ <?= htmlspecialchars($success) ?></div>
    <?php endif; ?>

    <div class="grid grid-3 gap-lg mb-lg">

      <!-- FORM PANEL -->
      <div class="card">
        <div class="card-header">
          <div class="card-title"><?= $editSupplier ? 'Edit Supplier' : 'Add Supplier' ?></div>
        </div>
        
        <form method="POST" class="card-body">
          <input type="hidden" name="action" value="save">
          <input type="hidden" name="id" value="<?= $editSupplier ? $editSupplier['id'] : 0 ?>">

          <div class="form-group">
            <label for="name">Supplier Name *</label>
            <input 
              type="text" 
              id="name" 
              name="name" 
              value="<?= htmlspecialchars($editSupplier['name'] ?? '') ?>"
              placeholder="e.g., ABC Distributors"
              required
            >
          </div>

          <div class="form-group">
            <label for="email">Email</label>
            <input 
              type="email" 
              id="email" 
              name="email" 
              value="<?= htmlspecialchars($editSupplier['email'] ?? '') ?>"
              placeholder="supplier@example.com"
            >
          </div>

          <div class="form-group">
            <label for="phone">Phone *</label>
            <input 
              type="tel" 
              id="phone" 
              name="phone" 
              value="<?= htmlspecialchars($editSupplier['phone'] ?? '') ?>"
              placeholder="+254 712 345 678"
              required
            >
          </div>

          <div class="form-group">
            <label for="address">Address</label>
            <textarea 
              id="address" 
              name="address"
              placeholder="123 Business Street, City"
              style="min-height: 80px;"
            ><?= htmlspecialchars($editSupplier['address'] ?? '') ?></textarea>
          </div>

          <div class="flex gap-md">
            <button type="submit" class="btn btn-primary flex-1"><?= $editSupplier ? 'Update' : 'Add' ?> Supplier</button>
            <?php if ($editSupplier): ?>
              <a href="/think-twice/suppliers.php" class="btn btn-secondary flex-1" style="text-decoration: none; text-align: center;">Cancel</a>
            <?php endif; ?>
          </div>
        </form>
      </div>

      <!-- SUPPLIERS LIST -->
      <div class="card" style="grid-column: span 2;">
        <div class="card-header">
          <div class="card-title">Suppliers (<?= count($suppliers) ?>)</div>
        </div>

        <div class="card-body">
          <?php if (empty($suppliers)): ?>
            <div class="text-center text-muted">
              <p>No suppliers yet. Add one to get started.</p>
            </div>
          <?php else: ?>
            <table class="table">
              <thead>
                <tr>
                  <th>Name</th>
                  <th>Phone</th>
                  <th>Email</th>
                  <th>Address</th>
                  <th>Actions</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($suppliers as $supplier): ?>
                  <tr>
                    <td class="font-semibold"><?= htmlspecialchars($supplier['name']) ?></td>
                    <td class="font-mono text-sm"><?= htmlspecialchars($supplier['phone']) ?></td>
                    <td class="text-muted"><?= htmlspecialchars($supplier['email'] ?? '-') ?></td>
                    <td class="text-muted text-sm"><?= htmlspecialchars(substr($supplier['address'] ?? '-', 0, 30)) ?></td>
                    <td>
                      <div class="flex gap-sm">
                        <a href="?edit=<?= $supplier['id'] ?>" class="btn btn-secondary btn-sm">Edit</a>
                        <form method="POST" style="display: inline;" onsubmit="return confirm('Delete this supplier?');">
                          <input type="hidden" name="action" value="delete">
                          <input type="hidden" name="id" value="<?= $supplier['id'] ?>">
                          <button type="submit" class="btn btn-danger btn-sm">Delete</button>
                        </form>
                      </div>
                    </td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          <?php endif; ?>
        </div>
      </div>

    </div>

  </div>

</body>
</html>
