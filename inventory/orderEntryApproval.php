<?php
session_start();
require __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/authGuard.php';
requireLogin();

$error   = '';
$success = '';

if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST['approve_requisition'])) {
    try {
        $id = (int) $_POST['approve_id'];
        $conn->prepare("UPDATE requisitions SET status = 'Approved' WHERE id = :id")->execute(['id' => $id]);
        header("Location: " . $_SERVER['PHP_SELF'] . "?approved=1");
        exit;
    } catch (PDOException $e) {
        $error = "Error approving: " . $e->getMessage();
    }
}

if ($_SERVER['REQUEST_METHOD'] === "POST" && isset($_POST['delete_requisition'])) {
    try {
        $id = (int) $_POST['delete_id'];
        $conn->prepare("DELETE FROM requisitions WHERE id = :id")->execute(['id' => $id]);
        header("Location: " . $_SERVER['PHP_SELF'] . "?deleted=1");
        exit;
    } catch (PDOException $e) {
        $error = "Error deleting: " . $e->getMessage();
    }
}

if (isset($_GET['approved'])) $success = "Requisition approved!";
if (isset($_GET['deleted']))  $success = "Requisition deleted.";

$requisitions = [];
try {
    $query = $conn->prepare("SELECT * FROM requisitions ORDER BY requisition_date DESC");
    $query->execute();
    $requisitions = $query->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $error = "Error fetching requisitions: " . $e->getMessage();
}

$allItems = [];
try {
    $query = $conn->prepare("
        SELECT ri.*, i.item_name
        FROM requisition_items ri
        LEFT JOIN items i ON i.id = ri.item_code
        ORDER BY ri.requisition_id
    ");
    $query->execute();
    foreach ($query->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $allItems[$row['requisition_id']][] = $row;
    }
} catch (PDOException $e) {
    $error = "Error fetching items: " . $e->getMessage();
}

$pendingCount  = count(array_filter($requisitions, fn($r) => strtolower($r['status'] ?? '') === 'pending'));
$approvedCount = count(array_filter($requisitions, fn($r) => strtolower($r['status'] ?? '') === 'approved'));
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Order Approvals - Think Twice</title>
  <link rel="stylesheet" href="/think-twice/public/theme.css">
</head>
<body class="page-container">

  <?php include __DIR__ . '/../navbar.php'; ?>

  <div class="page-header">
    <h1 class="page-title">Order Approvals</h1>
    <p class="page-subtitle">Review and approve purchase requisitions before goods are ordered</p>
  </div>

  <div class="page-content">

    <?php if ($success): ?>
      <div class="alert alert-success">✓ <?= htmlspecialchars($success) ?></div>
    <?php endif; ?>
    <?php if ($error): ?>
      <div class="alert alert-danger">⚠ <?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <!-- STATS -->
    <div class="grid grid-4 mb-lg">
      <div class="stat-box">
        <div class="stat-value"><?= count($requisitions) ?></div>
        <div class="stat-label">Total Requisitions</div>
      </div>
      <div class="stat-box">
        <div class="stat-value" style="color: var(--warn)"><?= $pendingCount ?></div>
        <div class="stat-label">Pending Approval</div>
      </div>
      <div class="stat-box">
        <div class="stat-value" style="color: var(--success)"><?= $approvedCount ?></div>
        <div class="stat-label">Approved</div>
      </div>
      <div class="stat-box">
        <div class="stat-value"><?= count($requisitions) - $pendingCount - $approvedCount ?></div>
        <div class="stat-label">Other Status</div>
      </div>
    </div>

    <!-- REQUISITIONS TABLE -->
    <div class="card">
      <div class="card-header">
        <div class="card-title">All Requisitions (<?= count($requisitions) ?>)</div>
        <a href="/think-twice/inventory/orderEntry.php" class="btn btn-primary btn-sm">+ New Requisition</a>
      </div>

      <?php if (empty($requisitions)): ?>
        <div class="text-center text-muted" style="padding: var(--space-xl);">
          No requisitions found.
          <br><br>
          <a href="/think-twice/inventory/orderEntry.php" class="btn btn-primary btn-sm">Create First Requisition</a>
        </div>
      <?php else: ?>
        <div style="overflow-x: auto;">
          <table class="table">
            <thead>
              <tr>
                <th>#</th>
                <th>Date</th>
                <th>Due Date</th>
                <th>Supplier</th>
                <th>Memo</th>
                <th>Status</th>
                <th>Actions</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($requisitions as $req):
                $status     = $req['status'] ?? 'Pending';
                $badgeClass = match(strtolower($status)) {
                    'approved' => 'badge-success',
                    'rejected' => 'badge-danger',
                    'received' => 'badge-primary',
                    default    => 'badge-warn'
                };
                $reqItems = $allItems[$req['id']] ?? [];
              ?>
              <tr>
                <td>
                  <button type="button" class="btn btn-ghost btn-sm font-mono"
                          onclick="openModal(this)"
                          data-id="<?= $req['id'] ?>"
                          data-date="<?= htmlspecialchars($req['requisition_date']) ?>"
                          data-items='<?= htmlspecialchars(json_encode($reqItems), ENT_QUOTES) ?>'>
                    #<?= str_pad($req['id'], 4, '0', STR_PAD_LEFT) ?>
                  </button>
                </td>
                <td class="font-mono"><?= htmlspecialchars($req['requisition_date']) ?></td>
                <td class="font-mono text-muted"><?= htmlspecialchars($req['due_date'] ?? '—') ?></td>
                <td class="text-muted"><?= htmlspecialchars($req['supplier'] ?? '—') ?></td>
                <td class="text-muted text-sm"
                    style="max-width:160px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;">
                  <?= htmlspecialchars($req['memo'] ?? '') ?>
                </td>
                <td>
                  <span class="badge <?= $badgeClass ?>"><?= htmlspecialchars($status) ?></span>
                </td>
                <td>
                  <div class="flex gap-sm">
                    <?php if (strtolower($status) !== 'approved' && strtolower($status) !== 'received'): ?>
                    <form method="POST" style="display:inline;">
                      <input type="hidden" name="approve_id" value="<?= (int) $req['id'] ?>">
                      <button class="btn btn-success btn-sm" type="submit" name="approve_requisition">Approve</button>
                    </form>
                    <?php endif; ?>
                    <form method="POST" style="display:inline;">
                      <input type="hidden" name="delete_id" value="<?= (int) $req['id'] ?>">
                      <button class="btn btn-danger btn-sm" type="submit" name="delete_requisition"
                              onclick="return confirm('Delete requisition #<?= $req['id'] ?>?')">Delete</button>
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

  <!-- ITEMS MODAL -->
  <div class="modal-backdrop" id="items-modal">
    <div class="modal" style="max-width: 700px; width: 100%;">
      <div class="modal-header">
        <div>
          <div class="modal-title" id="modal-title">Requisition Items</div>
          <div class="text-muted" style="font-size: 12px; margin-top: 4px;" id="modal-subtitle"></div>
        </div>
      </div>
      <div class="modal-body">
        <table class="table" id="modal-table">
          <thead>
            <tr>
              <th>Item</th>
              <th>Description</th>
              <th>Qty</th>
              <th>Unit Price</th>
              <th>Line Total</th>
            </tr>
          </thead>
          <tbody id="modal-tbody"></tbody>
        </table>
      </div>
      <div class="modal-footer">
        <span class="text-muted" style="font-size:13px;" id="modal-item-count"></span>
        <span class="text-primary font-mono font-bold" id="modal-grand-total"></span>
        <button type="button" class="btn btn-secondary" onclick="closeModal()">Close</button>
      </div>
    </div>
  </div>

  <script>
    function openModal(btn) {
      const items    = JSON.parse(btn.getAttribute('data-items') || '[]');
      const date     = btn.getAttribute('data-date');
      const id       = btn.getAttribute('data-id');

      document.getElementById('modal-title').textContent    = `Requisition #${String(id).padStart(4,'0')}`;
      document.getElementById('modal-subtitle').textContent = `Date: ${date}`;

      const tbody = document.getElementById('modal-tbody');
      tbody.innerHTML = '';

      if (items.length === 0) {
        tbody.innerHTML = '<tr><td colspan="5" style="text-align:center; padding:30px; color:var(--text-muted);">No items in this requisition.</td></tr>';
        document.getElementById('modal-item-count').textContent = '0 items';
        document.getElementById('modal-grand-total').textContent = '';
      } else {
        let grand = 0;
        items.forEach(item => {
          const total = parseFloat(item.total) || 0;
          grand += total;
          const tr = document.createElement('tr');
          tr.innerHTML = `
            <td class="font-semibold">${item.item_name || '—'}</td>
            <td class="text-muted">${item.description || '—'}</td>
            <td class="font-mono">${item.quantity}</td>
            <td class="font-mono">KES ${parseFloat(item.price).toLocaleString('en-KE', {minimumFractionDigits:2})}</td>
            <td class="font-mono font-bold text-primary">KES ${total.toLocaleString('en-KE', {minimumFractionDigits:2})}</td>
          `;
          tbody.appendChild(tr);
        });
        document.getElementById('modal-item-count').textContent = `${items.length} item${items.length !== 1 ? 's' : ''}`;
        document.getElementById('modal-grand-total').textContent = 'Total: KES ' + grand.toLocaleString('en-KE', {minimumFractionDigits:2});
      }

      document.getElementById('items-modal').classList.add('active');
    }

    function closeModal() {
      document.getElementById('items-modal').classList.remove('active');
    }

    document.getElementById('items-modal').addEventListener('click', function(e) {
      if (e.target === this) closeModal();
    });

    document.addEventListener('keydown', e => { if (e.key === 'Escape') closeModal(); });
  </script>

</body>
</html>
