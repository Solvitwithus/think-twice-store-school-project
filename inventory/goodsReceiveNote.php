<?php
require __DIR__ . '/../config/db.php';
$error      = "";
$success    = "";
$modalItems = [];
$modalID    = null;

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $action  = $_POST['action'] ?? 'load';
    $modalID = $_POST['passed-Id'] ?? null;

    // ── SAVE: update quantities + mark requisition as Received ──
    if ($action === 'save' && $modalID) {
        $itemIds    = $_POST['item_id']      ?? [];
        $quantities = $_POST['update-quantity'] ?? [];

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
                    $upd->execute([
                        'qty' => $newQty,
                        'id'  => $itemId,
                        'rid' => $modalID,
                    ]);
                }
            }

            $updStatus = $conn->prepare("
                UPDATE requisitions SET status = 'Received' WHERE id = :id
            ");
            $updStatus->execute(['id' => $modalID]);

            $conn->commit();
            $success = "Requisition #$modalID marked as Received.";
            $modalID = null; // close modal after save

        } catch (PDOException $e) {
            $conn->rollBack();
            $error = "Update failed: " . $e->getMessage();
        }
    }

    // ── LOAD: fetch items to show in modal ──
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
    $query = $conn->prepare("SELECT * FROM requisitions WHERE status = :status");
    $query->execute(['status' => 'Approved']);
    $requisitions = $query->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $error = "error fetching requisitions" . $e->getMessage();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Document</title>
</head>
<body>
    <h6>Goods receive note</h6>

    <?php if ($error): ?>
        <p style="color:red"><?= htmlspecialchars($error) ?></p>
    <?php endif; ?>
    <?php if ($success): ?>
        <p style="color:green"><?= htmlspecialchars($success) ?></p>
    <?php endif; ?>

    <table>
        <thead>
            <tr>
                <th>id</th>
                <th>Requisition Date</th>
                <th>Due Date</th>
                <th>Memo</th>
                <th>Status</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($requisitions as $requisition): ?>
            <tr>
                <td>
                    <a href="#" onclick="openModal(<?= htmlspecialchars($requisition['id']) ?>)">
                        <?= htmlspecialchars($requisition['id']) ?>
                    </a>
                </td>
                <td><?= htmlspecialchars($requisition['requisition_date']) ?></td>
                <td><?= htmlspecialchars($requisition['due_date']) ?></td>
                <td><?= htmlspecialchars($requisition['memo']) ?></td>
                <td><?= htmlspecialchars($requisition['status']) ?></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>

    <!-- Load form: opens the modal -->
    <form method="POST" id="modal-form">
        <input type="hidden" name="action"    value="load">
        <input type="hidden" name="passed-Id" id="hidden-id">
    </form>

    <div id="myModal" style="display:none; position:fixed; top:0; left:0; background:red;">
        <p>ID <span id="modal-id"></span></p>

        <!-- Save form: updates quantities + status -->
        <form method="POST" id="save-form">
            <input type="hidden" name="action"    value="save">
            <input type="hidden" name="passed-Id" value="<?= htmlspecialchars($modalID ?? '') ?>">

            <?php if (!empty($modalItems)): ?>
                <table>
                    <thead>
                        <tr>
                            <th>Item Name</th>
                            <th>Quantity</th>
                            <th>Confirm Quantity</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($modalItems as $item): ?>
                        <tr>
                            <td><?= htmlspecialchars($item['item_name']) ?></td>
                            <td><?= htmlspecialchars($item['quantity']) ?></td>
                            <td>
                                <!-- Send item id so PHP knows which row to update -->
                                <input type="hidden" name="item_id[]" value="<?= htmlspecialchars($item['id']) ?>">
                                <input type="number" name="update-quantity[]" min="0">
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <button type="submit">Confirm Receipt</button>
            <?php endif; ?>
        </form>

        <button onclick="closeModal()">Close</button>
    </div>

    <script>
        function openModal(id) {
            document.getElementById("modal-id").innerText = id;
            document.getElementById("hidden-id").value = id;
            document.getElementById("modal-form").submit(); // POST action=load
        }

        function closeModal() {
            document.getElementById("myModal").style.display = "none";
        }

        // Auto-open modal after POST if an ID was returned
        <?php if ($modalID): ?>
            document.getElementById("modal-id").innerText = <?= json_encode($modalID) ?>;
            document.getElementById("myModal").style.display = "block";
        <?php endif; ?>
    </script>
</body>
</html>