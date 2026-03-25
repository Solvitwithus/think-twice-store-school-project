<?php
require __DIR__ . '/../config/db.php';

$error   = "";
$success = "";

// ── UPDATE SELLING PRICE ─────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_price'])) {
    try {
        $item_id       = (int)   $_POST['item_id'];
        $selling_price = (float) $_POST['selling_price'];

        $stmt = $conn->prepare("UPDATE items SET selling_price = :selling_price WHERE id = :id");
        $stmt->execute([
            'selling_price' => $selling_price,
            'id'            => $item_id
        ]);

        header("Location: " . $_SERVER['PHP_SELF'] . "?success=1");
        exit;
    } catch (PDOException $e) {
        $error = "Error updating price: " . $e->getMessage();
    }
}

// ── FEEDBACK ─────────────────────────────────────────────
if (isset($_GET['success'])) $success = "Price updated successfully!";

// ── FETCH ITEMS ───────────────────────────────────────────
$items = [];
try {
    $stmt = $conn->prepare("SELECT id, item_name, buying_price, selling_price FROM items ORDER BY item_name");
    $stmt->execute();
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
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
    <title>Price Setting</title>
    <style>
        body { font-family: Arial, sans-serif; padding: 20px; }

        table { width: 100%; border-collapse: collapse; margin-top: 20px; }
        th, td { padding: 10px; border: 1px solid #ddd; text-align: left; }
        th { background: #4CAF50; color: white; }
        tr:nth-child(even) { background: #f9f9f9; }

        .price-input {
            width: 120px;
            padding: 6px;
            font-size: 14px;
            border: 1px solid #ccc;
            border-radius: 4px;
        }
        .btn-save {
            padding: 6px 12px;
            background: black;
            color: white;
            border: none;
            border-radius: 4px;
            cursor: pointer;
        }
        .btn-save:hover { background: #333; }

        /* Warn if selling price is below buying price */
        .margin-warn { color: #e53935; font-size: 12px; }
        .margin-ok   { color: #4CAF50; font-size: 12px; }

        .msg-success { color: green; }
        .msg-error   { color: red; }
    </style>
</head>
<body>
<?php include __DIR__ . '/../navbar.php'; ?>

<h2>Price Setting</h2>

<?php if ($success): ?><p class="msg-success"><?= htmlspecialchars($success) ?></p><?php endif; ?>
<?php if ($error):   ?><p class="msg-error"><?= htmlspecialchars($error) ?></p><?php endif; ?>

<?php if (empty($items)): ?>
    <p style="color:#999;">No items found. Create items first.</p>
<?php else: ?>
<table>
    <thead>
        <tr>
            <th>Item Name</th>
            <th>Buying Price (KSh)</th>
            <th>Current Selling Price (KSh)</th>
            <th>New Selling Price (KSh)</th>
            <th>Margin</th>
            <th>Action</th>
        </tr>
    </thead>
    <tbody>
        <?php foreach ($items as $item): ?>
        <?php
            // Calculate current margin for display
            $margin = $item['selling_price'] - $item['buying_price'];
            $margin_pct = $item['buying_price'] > 0
                ? round(($margin / $item['buying_price']) * 100, 1)
                : 0;
        ?>
        <tr>
            <td><?= htmlspecialchars($item['item_name']) ?></td>
            <td>KSh <?= number_format($item['buying_price'], 2) ?></td>
            <td>KSh <?= number_format($item['selling_price'], 2) ?></td>
            <td>
                <!--
                    Each row is its own small form.
                    The hidden field carries the item ID.
                    The input carries the new price.
                -->
                <form method="POST" style="display:inline;">
                    <input type="hidden" name="item_id" value="<?= (int) $item['id'] ?>">
                    <input
                        class="price-input"
                        type="number"
                        name="selling_price"
                        step="0.01"
                        min="0"
                        value="<?= htmlspecialchars($item['selling_price']) ?>"
                        required
                    >
            </td>
            <td>
                    <?php if ($margin < 0): ?>
                        <span class="margin-warn">▼ KSh <?= number_format(abs($margin), 2) ?> loss</span>
                    <?php else: ?>
                        <span class="margin-ok">▲ KSh <?= number_format($margin, 2) ?> (<?= $margin_pct ?>%)</span>
                    <?php endif; ?>
            </td>
            <td>
                    <button class="btn-save" type="submit" name="update_price">Save</button>
                </form>
            </td>
        </tr>
        <?php endforeach; ?>
    </tbody>
</table>
<?php endif; ?>

</body>
</html>