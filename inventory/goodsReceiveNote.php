<?php
session_start();
require __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/authGuard.php';
requireLogin();

$error      = "";
$success    = "";
$modalItems = [];
$modalID    = null;

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $action  = $_POST['action'] ?? 'load';
    $modalID = $_POST['passed-Id'] ?? null;

    if ($action === 'save' && $modalID) {
        $itemIds    = $_POST['item_id']           ?? [];
        $quantities = $_POST['update-quantity']   ?? [];
        try {
            $conn->beginTransaction();
            foreach ($itemIds as $index => $itemId) {
                $newQty = $quantities[$index] ?? null;
                if ($newQty !== null && $newQty !== '') {
                    $upd = $conn->prepare("
                        UPDATE requisition_items
                        SET quantity = :qty
                        WHERE id = :id AND requisition_id = :rid
                    ");
                    $upd->execute(['qty' => $newQty, 'id' => $itemId, 'rid' => $modalID]);
                }
            }
            $conn->prepare("UPDATE requisitions SET status = 'Received' WHERE id = :id")
                 ->execute(['id' => $modalID]);
            $conn->commit();
            $success = "Requisition #$modalID marked as Received.";
            $modalID = null;
        } catch (PDOException $e) {
            $conn->rollBack();
            $error = "Update failed: " . $e->getMessage();
        }
    }

    if ($action === 'load' && $modalID) {
        try {
            $stmt = $conn->prepare("
                SELECT ri.*, i.item_name
                FROM requisition_items ri
                LEFT JOIN items i ON i.id = ri.item_code
                WHERE ri.requisition_id = :rid
                ORDER BY ri.requisition_id
            ");
            $stmt->execute(['rid' => $modalID]);
            $modalItems = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            $error = "Error fetching items: " . $e->getMessage();
        }
    }
}

$requisitions = [];
try {
    $query = $conn->prepare("SELECT * FROM requisitions WHERE status = :status ORDER BY requisition_date DESC");
    $query->execute(['status' => 'Approved']);
    $requisitions = $query->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $error = "Error fetching requisitions: " . $e->getMessage();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Goods Receive Note - Think Twice</title>
  <link rel="stylesheet" href="/think-twice/public/theme.css?v=2">
</head>
<body class="page-container">

  <?php include __DIR__ . '/../navbar.php'; ?>

  <div class="page-header">
    <h1 class="page-title">Goods Receive Note</h1>
    <p class="page-subtitle">Confirm and record received stock against approved requisitions</p>
  </div>

  <div class="page-content">

    <?php if ($error): ?>
      <div class="alert alert-danger">⚠ <?= htmlspecialchars($error) ?></div>
    <?php endif; ?>
    <?php if ($success): ?>
      <div class="alert alert-success">✓ <?= htmlspecialchars($success) ?></div>
    <?php endif; ?>

    <!-- STAT -->
    <div class="grid grid-4 mb-lg">
      <div class="stat-box">
        <div class="stat-value"><?= count($requisitions) ?></div>
        <div class="stat-label">Pending Receipt</div>
      </div>
    </div>

    <!-- APPROVED REQUISITIONS TABLE -->
    <div class="card">
      <div class="card-header">
        <div class="card-title">Approved Requisitions — Awaiting Receipt</div>
        <a href="/think-twice/inventory/orderEntryApproval.php" class="btn btn-secondary btn-sm">View All Orders</a>
      </div>

      <?php if (empty($requisitions)): ?>
        <div class="text-center text-muted" style="padding: var(--space-xl);">
          No approved requisitions awaiting receipt.
          <br><br>
          <a href="/think-twice/inventory/orderEntryApproval.php" class="btn btn-secondary btn-sm">Go to Approvals</a>
        </div>
      <?php else: ?>
        <table class="table">
          <thead>
            <tr>
              <th>#</th>
              <th>Requisition Date</th>
              <th>Due Date</th>
              <th>Memo</th>
              <th>Status</th>
              <th>Action</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($requisitions as $req): ?>
            <tr>
              <td>
                <button type="button" class="btn btn-secondary btn-sm"
                        onclick="openModal(<?= htmlspecialchars($req['id']) ?>)">
                  #<?= str_pad($req['id'], 4, '0', STR_PAD_LEFT) ?>
                </button>
              </td>
              <td class="font-mono"><?= htmlspecialchars($req['requisition_date']) ?></td>
              <td class="font-mono text-muted"><?= htmlspecialchars($req['due_date'] ?? '—') ?></td>
              <td class="text-muted" style="max-width:200px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;">
                <?= htmlspecialchars($req['memo'] ?? '') ?>
              </td>
              <td><span class="badge badge-warn">Approved</span></td>
              <td>
                <button type="button" class="btn btn-primary btn-sm"
                        onclick="openModal(<?= htmlspecialchars($req['id']) ?>)">
                  Receive Goods
                </button>
              </td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      <?php endif; ?>
    </div>

  </div>

  <!-- LOAD MODAL FORM (hidden trigger) -->
  <form method="POST" id="modal-form" style="display:none;">
    <input type="hidden" name="action"    value="load">
    <input type="hidden" name="passed-Id" id="hidden-id">
  </form>

  <!-- RECEIVE MODAL -->
  <div class="modal-backdrop <?= $modalID ? 'active' : '' ?>" id="grn-modal">
    <div class="modal" style="max-width: 600px; width: 100%;">
      <div class="modal-header">
        <div class="modal-title">Confirm Goods Receipt — Requisition #<span id="modal-req-id"></span></div>
      </div>

      <div class="modal-body">
        <form method="POST" id="save-form">
          <input type="hidden" name="action"    value="save">
          <input type="hidden" name="passed-Id" value="<?= htmlspecialchars($modalID ?? '') ?>">

          <?php if (!empty($modalItems)): ?>
            <p class="text-muted" style="font-size: 13px; margin-bottom: var(--space-md);">
              Enter the actual quantity received for each item. Leave blank to keep the ordered quantity.
            </p>
            <table class="table">
              <thead>
                <tr>
                  <th>Item Name</th>
                  <th>Ordered Qty</th>
                  <th>Received Qty</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($modalItems as $item): ?>
                <tr>
                  <td class="font-semibold"><?= htmlspecialchars($item['item_name']) ?></td>
                  <td class="font-mono text-muted"><?= htmlspecialchars($item['quantity']) ?></td>
                  <td>
                    <input type="hidden" name="item_id[]" value="<?= htmlspecialchars($item['id']) ?>">
                    <input type="number" name="update-quantity[]" min="0"
                           placeholder="<?= htmlspecialchars($item['quantity']) ?>"
                           style="width: 100px;">
                  </td>
                </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          <?php elseif ($modalID): ?>
            <p class="text-muted text-center">No items found for this requisition.</p>
          <?php endif; ?>
        </form>
      </div>

      <div class="modal-footer">
        <?php if (!empty($modalItems)): ?>
          <button type="submit" form="save-form" class="btn btn-primary">Confirm Receipt</button>
        <?php endif; ?>
        <button type="button" class="btn btn-secondary" onclick="closeModal()">Close</button>
      </div>
    </div>
  </div>

  <script>
    function openModal(id) {
      document.getElementById('modal-req-id').textContent = String(id).padStart(4, '0');
      document.getElementById('hidden-id').value = id;
      document.getElementById('modal-form').submit();
    }

    function closeModal() {
      document.getElementById('grn-modal').classList.remove('active');
    }

    document.getElementById('grn-modal').addEventListener('click', function(e) {
      if (e.target === this) closeModal();
    });

    <?php if ($modalID): ?>
      document.getElementById('modal-req-id').textContent = String(<?= json_encode($modalID) ?>).padStart(4, '0');
      document.getElementById('grn-modal').classList.add('active');
    <?php endif; ?>
  </script>

</body>
</html>
