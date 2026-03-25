<?php
require __DIR__ . '/../config/db.php';
$error   = '';
$success = '';

// ── APPROVE ──────────────────────────────────────────────
// FIXED: approval should be POST not GET, and the UPDATE syntax was completely wrong
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST['approve_requisition'])) {
    try {
        $id = (int) $_POST['approve_id'];
        $conn->prepare("UPDATE requisitions SET status = 'Approved' WHERE id = :id")
             ->execute(['id' => $id]);
        header("Location: " . $_SERVER['PHP_SELF'] . "?approved=1");
        exit;
    } catch (PDOException $e) {
        $error = "Error approving: " . $e->getMessage();
    }
}

// ── DELETE ───────────────────────────────────────────────
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

// ── FEEDBACK ─────────────────────────────────────────────
if (isset($_GET['approved'])) $success = "Requisition approved!";
if (isset($_GET['deleted']))  $success = "Requisition deleted.";

// ── FETCH REQUISITIONS ────────────────────────────────────
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
        // Group items under their requisition ID
        $allItems[$row['requisition_id']][] = $row;
    }
} catch (PDOException $e) {
    $error = "Error fetching items: " . $e->getMessage();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="/think-twice/styles.css">
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600&family=DM+Mono&display=swap" rel="stylesheet">
    <title>Order Entry Approval</title>
<style>
    :root {
        --ink:     #1a1a2e;
        --ink-mid: #4a4a6a;
        --ink-dim: #9090b0;
        --surface: #f7f7fb;
        --card:    #ffffff;
        --accent:  #3d5af1;
        --success: #22c55e;
        --danger:  #ef4444;
        --border:  #e2e2ee;
        --shadow:  0 2px 16px rgba(61,90,241,0.07);
    }

    * { box-sizing: border-box; margin: 0; padding: 0; }
    body { font-family: 'DM Sans', sans-serif; background: var(--surface); color: var(--ink); }

    .page-wrapper { max-width: 1100px; margin: 0 auto; padding: 32px 24px 80px; }

    .page-header { display: flex; align-items: baseline; gap: 14px; margin-bottom: 28px; }
    .page-header h1 { font-size: 26px; font-weight: 600; letter-spacing: -0.5px; }
    .page-header span {
        font-family: 'DM Mono', monospace; font-size: 12px;
        color: var(--ink-dim); background: var(--border);
        padding: 3px 10px; border-radius: 20px;
    }

    .toast {
        display: flex; align-items: center; gap: 10px;
        padding: 14px 18px; border-radius: 10px; margin-bottom: 24px;
        font-weight: 500; font-size: 14px; animation: slideIn 0.3s ease;
    }
    .toast-success { background: #f0fdf4; border: 1px solid #bbf7d0; color: #166534; }
    .toast-error   { background: #fef2f2; border: 1px solid #fecaca; color: #991b1b; }
    @keyframes slideIn {
        from { opacity: 0; transform: translateY(-8px); }
        to   { opacity: 1; transform: translateY(0); }
    }

    .card { background: var(--card); border: 1px solid var(--border); border-radius: 14px; box-shadow: var(--shadow); overflow: hidden; }

    table { width: 100%; border-collapse: collapse; font-size: 14px; }
    thead th {
        background: var(--ink); color: #fff;
        padding: 12px 16px; text-align: left;
        font-size: 11px; text-transform: uppercase; letter-spacing: 0.07em; font-weight: 500;
    }
    tbody tr { border-bottom: 1px solid var(--border); transition: background 0.15s; }
    tbody tr:hover { background: var(--surface); }
    tbody td { padding: 12px 16px; vertical-align: middle; }

    /* Clickable date cell */
    .date-link {
        font-family: 'DM Mono', monospace;
        font-size: 13px;
        color: var(--accent);
        cursor: pointer;
        text-decoration: underline dotted;
        background: none;
        border: none;
        padding: 0;
        font-family: inherit;
    }
    .date-link:hover { color: var(--ink); }

    /* Status badge */
    .badge {
        display: inline-block;
        padding: 3px 10px; border-radius: 20px;
        font-size: 12px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.04em;
    }
    .badge-pending  { background: #fef9c3; color: #854d0e; }
    .badge-approved { background: #dcfce7; color: #166534; }
    .badge-rejected { background: #fee2e2; color: #991b1b; }

    .btn {
        display: inline-flex; align-items: center; gap: 5px;
        padding: 7px 14px; border-radius: 7px;
        font-family: 'DM Sans', sans-serif; font-size: 13px; font-weight: 500;
        border: none; cursor: pointer; transition: opacity 0.15s;
    }
    .btn:hover { opacity: 0.82; }
    .btn-approve { background: var(--success); color: #fff; }
    .btn-delete  { background: #fef2f2; color: var(--danger); border: 1px solid #fecaca; }

    .actions { display: flex; gap: 8px; }

    /* ── Modal ── */
    .modal-overlay {
        display: none;
        position: fixed; inset: 0;
        background: rgba(26,26,46,0.45);
        backdrop-filter: blur(3px);
        z-index: 100;
        align-items: center;
        justify-content: center;
    }
    .modal-overlay.open { display: flex; }

    .modal {
        background: var(--card);
        border-radius: 16px;
        box-shadow: 0 24px 60px rgba(0,0,0,0.18);
        width: 90%;
        max-width: 700px;
        max-height: 80vh;
        display: flex;
        flex-direction: column;
        animation: popIn 0.2s ease;
    }
    @keyframes popIn {
        from { opacity: 0; transform: scale(0.95); }
        to   { opacity: 1; transform: scale(1); }
    }

    .modal-header {
        display: flex; justify-content: space-between; align-items: center;
        padding: 20px 24px; border-bottom: 1px solid var(--border);
    }
    .modal-header h3 { font-size: 16px; font-weight: 600; }
    .modal-header p  { font-size: 12px; color: var(--ink-dim); margin-top: 2px; }

    .modal-close {
        background: var(--surface); border: 1px solid var(--border);
        border-radius: 8px; width: 32px; height: 32px;
        cursor: pointer; font-size: 16px; display: flex;
        align-items: center; justify-content: center;
        transition: background 0.15s;
    }
    .modal-close:hover { background: var(--border); }

    .modal-body { padding: 20px 24px; overflow-y: auto; }

    .modal-table { width: 100%; border-collapse: collapse; font-size: 13px; }
    .modal-table th {
        background: var(--surface); padding: 9px 12px;
        text-align: left; font-size: 11px; text-transform: uppercase;
        letter-spacing: 0.06em; color: var(--ink-dim); font-weight: 600;
    }
    .modal-table td { padding: 10px 12px; border-bottom: 1px solid var(--border); }
    .modal-table tr:last-child td { border-bottom: none; }

    .modal-footer {
        padding: 14px 24px; border-top: 1px solid var(--border);
        display: flex; justify-content: space-between; align-items: center;
        background: var(--surface); border-radius: 0 0 16px 16px;
    }
    .modal-footer .grand { font-weight: 600; font-family: 'DM Mono', monospace; color: var(--accent); }

    .empty-state { text-align: center; padding: 40px; color: var(--ink-dim); font-size: 14px; }
</style>
</head>
<body>
<?php include __DIR__ . '/../navbar.php'; ?>

<div class="page-wrapper">
    <div class="page-header">
        <h1>Order Approvals</h1>
        <span><?= count($requisitions) ?> requisitions</span>
    </div>

    <?php if ($success): ?>
        <div class="toast toast-success">✓ <?= htmlspecialchars($success) ?></div>
    <?php endif; ?>
    <?php if ($error): ?>
        <div class="toast toast-error">✗ <?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <div class="card">
        <?php if (empty($requisitions)): ?>
            <div class="empty-state">No requisitions found.</div>
        <?php else: ?>
        <table>
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
                <?php foreach ($requisitions as $req): ?>
                <?php
                    $status = $req['status'] ?? 'Pending';
                    $badgeClass = match(strtolower($status)) {
                        'approved' => 'badge-approved',
                        'rejected' => 'badge-rejected',
                        default    => 'badge-pending'
                    };
                    // Items for this requisition (pre-grouped above)
                    $reqItems = $allItems[$req['id']] ?? [];
                ?>
                <tr>
                    <td style="font-family:'DM Mono',monospace; color:var(--ink-dim); font-size:12px;">
                        #<?= str_pad($req['id'], 4, '0', STR_PAD_LEFT) ?>
                    </td>
                    <td>
                        <!--
                            Clicking the date opens the modal.
                            Items data is passed as a JSON data attribute so
                            no extra AJAX request is needed.
                        -->
                        <button class="date-link"
                                onclick="openModal(this)"
                                data-id="<?= $req['id'] ?>"
                                data-date="<?= htmlspecialchars($req['requisition_date']) ?>"
                                data-items='<?= htmlspecialchars(json_encode($reqItems), ENT_QUOTES) ?>'>
                            <?= htmlspecialchars($req['requisition_date']) ?>
                        </button>
                    </td>
                    <td style="font-family:'DM Mono',monospace; font-size:13px;">
                        <?= htmlspecialchars($req['due_date'] ?? '—') ?>
                    </td>
                    <td><?= htmlspecialchars($req['supplier'] ?? '—') ?></td>
                    <td style="color:var(--ink-mid); font-size:13px; max-width:200px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;">
                        <?= htmlspecialchars($req['memo'] ?? '') ?>
                    </td>
                    <td>
                        <span class="badge <?= $badgeClass ?>">
                            <?= htmlspecialchars($status) ?>
                        </span>
                    </td>
                    <td>
                        <div class="actions">
                            <?php if (strtolower($status) !== 'approved'): ?>
                            <!-- Approve button — POST form -->
                            <form method="POST" style="display:inline;">
                                <input type="hidden" name="approve_id" value="<?= (int) $req['id'] ?>">
                                <button class="btn btn-approve" type="submit" name="approve_requisition">
                                    ✓ Approve
                                </button>
                            </form>
                            <?php endif; ?>

                            <!-- Delete button — POST form -->
                            <form method="POST" style="display:inline;">
                                <input type="hidden" name="delete_id" value="<?= (int) $req['id'] ?>">
                                <button class="btn btn-delete" type="submit" name="delete_requisition"
                                        onclick="return confirm('Delete requisition #<?= $req['id'] ?>?')">
                                    ✕ Delete
                                </button>
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


<!-- ── MODAL ──────────────────────────────────────────── -->
<div class="modal-overlay" id="modalOverlay" onclick="closeOnBackdrop(event)">
    <div class="modal">
        <div class="modal-header">
            <div>
                <h3 id="modalTitle">Requisition Items</h3>
                <p id="modalSubtitle"></p>
            </div>
            <button class="modal-close" onclick="closeModal()">✕</button>
        </div>
        <div class="modal-body">
            <table class="modal-table">
                <thead>
                    <tr>
                        <th>Item</th>
                        <th>Description</th>
                        <th>Qty</th>
                        <th>Unit Price</th>
                        <th>Line Total</th>
                    </tr>
                </thead>
                <tbody id="modalTableBody"></tbody>
            </table>
        </div>
        <div class="modal-footer">
            <span style="font-size:13px; color:var(--ink-dim);" id="modalItemCount"></span>
            <span class="grand" id="modalGrandTotal"></span>
        </div>
    </div>
</div>

<script>
function openModal(btn) {
    const items    = JSON.parse(btn.getAttribute('data-items') || '[]');
    const date     = btn.getAttribute('data-date');
    const id       = btn.getAttribute('data-id');

    // Set modal header
    document.getElementById('modalTitle').textContent    = `Requisition #${String(id).padStart(4,'0')}`;
    document.getElementById('modalSubtitle').textContent = `Date: ${date}`;

    // Build table rows
    const tbody = document.getElementById('modalTableBody');
    tbody.innerHTML = '';

    if (items.length === 0) {
        tbody.innerHTML = '<tr><td colspan="5" style="text-align:center; padding:30px; color:#9090b0;">No items found for this requisition.</td></tr>';
    } else {
        let grand = 0;
        items.forEach(item => {
            const total = parseFloat(item.total) || 0;
            grand += total;
            const tr = document.createElement('tr');
            tr.innerHTML = `
                <td><strong>${item.item_name || '—'}</strong></td>
                <td style="color:#4a4a6a;">${item.description || '—'}</td>
                <td style="font-family:'DM Mono',monospace;">${item.quantity}</td>
                <td style="font-family:'DM Mono',monospace;">KSh ${parseFloat(item.price).toLocaleString('en-KE', {minimumFractionDigits:2})}</td>
                <td style="font-family:'DM Mono',monospace; font-weight:600;">KSh ${total.toLocaleString('en-KE', {minimumFractionDigits:2})}</td>
            `;
            tbody.appendChild(tr);
        });

        document.getElementById('modalGrandTotal').textContent =
            'Grand Total: KSh ' + grand.toLocaleString('en-KE', {minimumFractionDigits:2});
        document.getElementById('modalItemCount').textContent =
            `${items.length} item${items.length !== 1 ? 's' : ''}`;
    }

    document.getElementById('modalOverlay').classList.add('open');
}

function closeModal() {
    document.getElementById('modalOverlay').classList.remove('open');
}

// Close if user clicks the dark backdrop (not the modal itself)
function closeOnBackdrop(e) {
    if (e.target === document.getElementById('modalOverlay')) closeModal();
}

// Close on Escape key
document.addEventListener('keydown', e => { if (e.key === 'Escape') closeModal(); });
</script>
</body>
</html>