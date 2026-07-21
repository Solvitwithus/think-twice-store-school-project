<?php
session_start();
require __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/authGuard.php';
requireLogin();

$error   = '';
$success = '';

/* ── POST handlers ──────────────────────────────────────────── */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $action = $_POST['action'] ?? '';

    if ($action === 'save') {
        $id           = (int)($_POST['id'] ?? 0);
        $company_name = trim($_POST['company_name'] ?? '');
        $contact_name = trim($_POST['contact_name'] ?? '');
        $phone        = trim($_POST['phone']        ?? '');
        $email        = trim($_POST['email']        ?? '');
        $city         = trim($_POST['city']         ?? '');
        $country      = trim($_POST['country']      ?? 'Kenya');
        $address_line1= trim($_POST['address_line1']?? '');
        $postal_code  = trim($_POST['postal_code']  ?? '0000');
        $payment_terms= trim($_POST['payment_terms']?? '');
        $status       = trim($_POST['status']       ?? 'active');
        $notes        = trim($_POST['notes']        ?? '');

        if (!$company_name || !$phone) {
            $error = 'Company name and phone number are required.';
        } else {
            try {
                if ($id > 0) {
                    $stmt = $conn->prepare("
                        UPDATE suppliers
                        SET company_name   = ?, contact_name = ?, phone    = ?,
                            email          = ?, city          = ?, country  = ?,
                            address_line1  = ?, postal_code   = ?,
                            payment_terms  = ?, status        = ?, notes   = ?
                        WHERE id = ?
                    ");
                    $stmt->execute([
                        $company_name, $contact_name, $phone,
                        $email, $city, $country,
                        $address_line1, $postal_code,
                        $payment_terms, $status, $notes,
                        $id
                    ]);
                    $success = 'Supplier updated successfully.';
                } else {
                    $stmt = $conn->prepare("
                        INSERT INTO suppliers
                            (company_name, contact_name, phone, email,
                             city, country, address_line1, postal_code,
                             payment_terms, status, notes)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                    ");
                    $stmt->execute([
                        $company_name, $contact_name, $phone, $email,
                        $city, $country, $address_line1, $postal_code,
                        $payment_terms, $status, $notes
                    ]);
                    $success = 'Supplier added successfully.';
                }
            } catch (PDOException $e) {
                $error = 'Database error: ' . $e->getMessage();
            }
        }
    }

    if ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        try {
            $stmt = $conn->prepare("DELETE FROM suppliers WHERE id = ?");
            $stmt->execute([$id]);
            $success = 'Supplier deleted.';
        } catch (PDOException $e) {
            $error = 'Error deleting supplier: ' . $e->getMessage();
        }
    }
}

/* ── Fetch suppliers ─────────────────────────────────────────── */
try {
    $stmt = $conn->prepare("SELECT * FROM suppliers ORDER BY company_name ASC");
    $stmt->execute();
    $suppliers = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $error     = 'Error fetching suppliers: ' . $e->getMessage();
    $suppliers = [];
}

/* ── Edit mode ───────────────────────────────────────────────── */
$editSupplier = null;
if (isset($_GET['edit'])) {
    $eid = (int)$_GET['edit'];
    foreach ($suppliers as $s) {
        if ($s['id'] === $eid) { $editSupplier = $s; break; }
    }
}

function statusBadge(string $status): string {
    return match($status) {
        'active'      => 'badge-success',
        'blacklisted' => 'badge-danger',
        default       => 'badge-warn',
    };
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Suppliers - Think Twice</title>
  <link rel="stylesheet" href="/think-twice/public/theme.css?v=2">
  <style>
    .supplier-grid { display:grid; grid-template-columns:340px 1fr; gap:var(--space-lg); align-items:start; }
    @media (max-width:900px) { .supplier-grid { grid-template-columns:1fr; } }
    .form-row { display:grid; grid-template-columns:1fr 1fr; gap:var(--space-md); }
    @media (max-width:600px) { .form-row { grid-template-columns:1fr; } }
  </style>
</head>
<body class="page-container">

  <?php include 'navbar.php'; ?>

  <div class="page-header">
    <h1 class="page-title">Suppliers Management</h1>
    <p class="page-subtitle"><?= count($suppliers) ?> supplier<?= count($suppliers) !== 1 ? 's' : '' ?> on record</p>
  </div>

  <div class="page-content">

    <?php if ($error): ?>
      <div class="alert alert-danger">⚠ <?= htmlspecialchars($error) ?></div>
    <?php endif; ?>
    <?php if ($success): ?>
      <div class="alert alert-success">✓ <?= htmlspecialchars($success) ?></div>
    <?php endif; ?>

    <div class="supplier-grid">

      <!-- ── FORM PANEL ────────────────────────────────────── -->
      <div class="card" style="position:sticky; top: calc(var(--nav-height, 64px) + 16px);">
        <div class="card-header">
          <div class="card-title"><?= $editSupplier ? 'Edit Supplier' : 'Add New Supplier' ?></div>
        </div>

        <form method="POST" style="padding: var(--space-lg); display:flex; flex-direction:column; gap:var(--space-md);">
          <input type="hidden" name="action" value="save">
          <input type="hidden" name="id" value="<?= $editSupplier ? $editSupplier['id'] : 0 ?>">

          <div class="form-group">
            <label>Company Name *</label>
            <input type="text" name="company_name"
                   value="<?= htmlspecialchars($editSupplier['company_name'] ?? '') ?>"
                   placeholder="e.g. Nairobi Bale Traders Ltd" required>
          </div>

          <div class="form-group">
            <label>Contact Person</label>
            <input type="text" name="contact_name"
                   value="<?= htmlspecialchars($editSupplier['contact_name'] ?? '') ?>"
                   placeholder="Full name of primary contact">
          </div>

          <div class="form-row">
            <div class="form-group">
              <label>Phone *</label>
              <input type="tel" name="phone"
                     value="<?= htmlspecialchars($editSupplier['phone'] ?? '') ?>"
                     placeholder="+254 712 345 678" required>
            </div>
            <div class="form-group">
              <label>Email</label>
              <input type="email" name="email"
                     value="<?= htmlspecialchars($editSupplier['email'] ?? '') ?>"
                     placeholder="supplier@example.com">
            </div>
          </div>

          <div class="form-row">
            <div class="form-group">
              <label>City</label>
              <input type="text" name="city"
                     value="<?= htmlspecialchars($editSupplier['city'] ?? '') ?>"
                     placeholder="Nairobi">
            </div>
            <div class="form-group">
              <label>Country</label>
              <input type="text" name="country"
                     value="<?= htmlspecialchars($editSupplier['country'] ?? 'Kenya') ?>"
                     placeholder="Kenya">
            </div>
          </div>

          <div class="form-group">
            <label>Address</label>
            <input type="text" name="address_line1"
                   value="<?= htmlspecialchars($editSupplier['address_line1'] ?? '') ?>"
                   placeholder="Street / Building / P.O. Box">
          </div>

          <div class="form-row">
            <div class="form-group">
              <label>Payment Terms</label>
              <select name="payment_terms">
                <?php
                $terms = ['', 'Cash on Delivery', 'Net 7', 'Net 14', 'Net 30', 'Prepaid'];
                $cur   = $editSupplier['payment_terms'] ?? '';
                foreach ($terms as $t):
                ?>
                <option value="<?= $t ?>" <?= $cur === $t ? 'selected' : '' ?>>
                  <?= $t ?: 'Select…' ?>
                </option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="form-group">
              <label>Status</label>
              <select name="status">
                <?php foreach (['active','inactive','blacklisted'] as $st): ?>
                <option value="<?= $st ?>"
                  <?= ($editSupplier['status'] ?? 'active') === $st ? 'selected' : '' ?>>
                  <?= ucfirst($st) ?>
                </option>
                <?php endforeach; ?>
              </select>
            </div>
          </div>

          <div class="form-group">
            <label>Notes</label>
            <textarea name="notes" placeholder="Any additional notes about this supplier…"
                      style="min-height:70px;"><?= htmlspecialchars($editSupplier['notes'] ?? '') ?></textarea>
          </div>

          <div style="display:flex; gap:var(--space-md);">
            <button type="submit" class="btn btn-primary" style="flex:1;">
              <?= $editSupplier ? 'Update Supplier' : 'Add Supplier' ?>
            </button>
            <?php if ($editSupplier): ?>
              <a href="/think-twice/suppliers.php" class="btn btn-secondary" style="flex:1; text-align:center;">Cancel</a>
            <?php endif; ?>
          </div>
        </form>
      </div>

      <!-- ── SUPPLIERS LIST ────────────────────────────────── -->
      <div class="card">
        <div class="card-header">
          <div class="card-title">All Suppliers</div>
          <a href="/think-twice/reports/suppliers.php" class="btn btn-secondary btn-sm">View Report</a>
        </div>

        <?php if (empty($suppliers)): ?>
          <div class="text-center text-muted" style="padding:var(--space-xl);">
            No suppliers yet. Add one using the form.
          </div>
        <?php else: ?>
        <div style="overflow-x:auto;">
          <table class="table">
            <thead>
              <tr>
                <th>Company</th>
                <th>Contact</th>
                <th>Phone</th>
                <th>City</th>
                <th>Terms</th>
                <th>Status</th>
                <th>Actions</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($suppliers as $s): ?>
              <tr>
                <td class="font-semibold"><?= htmlspecialchars($s['company_name']) ?></td>
                <td class="text-muted text-sm"><?= htmlspecialchars($s['contact_name'] ?? '—') ?></td>
                <td class="font-mono text-sm"><?= htmlspecialchars($s['phone']) ?></td>
                <td class="text-muted text-sm">
                  <?= htmlspecialchars(implode(', ', array_filter([$s['city'] ?? null, $s['country'] ?? null]))) ?: '—' ?>
                </td>
                <td class="text-sm"><?= htmlspecialchars($s['payment_terms'] ?? '—') ?></td>
                <td>
                  <span class="badge <?= statusBadge($s['status'] ?? 'inactive') ?>">
                    <?= ucfirst($s['status'] ?? 'Unknown') ?>
                  </span>
                </td>
                <td>
                  <div style="display:flex; gap:var(--space-sm);">
                    <a href="?edit=<?= $s['id'] ?>" class="btn btn-secondary btn-sm">Edit</a>
                    <form method="POST" style="display:inline;"
                          onsubmit="return confirm('Delete <?= htmlspecialchars(addslashes($s['company_name'])) ?>?');">
                      <input type="hidden" name="action" value="delete">
                      <input type="hidden" name="id" value="<?= $s['id'] ?>">
                      <button type="submit" class="btn btn-danger btn-sm">Delete</button>
                    </form>
                  </div>
                </td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
        <?php endif; ?>
      </div>

    </div>
  </div>

</body>
</html>
