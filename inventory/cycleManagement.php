<?php
require __DIR__ . '/../config/db.php';

$error   = "";
$success = "";

// ── DELETE CYCLE ─────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_cycle'])) {
    try {
        $id = (int) $_POST['delete_cycle_id'];
        $conn->prepare("DELETE FROM price_cycles WHERE id = :id")
             ->execute(['id' => $id]);
        header("Location: " . $_SERVER['PHP_SELF'] . "?deleted=1");
        exit;
    } catch (PDOException $e) {
        $error = "Error deleting cycle: " . $e->getMessage();
    }
}

// ── RESET CYCLE (restart the clock from today) ───────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['reset_cycle'])) {
    try {
        $id = (int) $_POST['reset_cycle_id'];
        $conn->prepare("UPDATE price_cycles SET cycle_start = CURDATE() WHERE id = :id")
             ->execute(['id' => $id]);
        header("Location: " . $_SERVER['PHP_SELF'] . "?reset=1");
        exit;
    } catch (PDOException $e) {
        $error = "Error resetting cycle: " . $e->getMessage();
    }
}

// ── CREATE CYCLE ─────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_cycle'])) {
    try {
        $item_id         = (int)   $_POST['item_id'];
        $daily_reduction = (float) $_POST['daily_reduction'];
        $cycle_days      = (int)   $_POST['cycle_days'];

        // Snapshot the item's current selling_price as the base
        // This is the price we reduce FROM — original is never touched
        $row = $conn->prepare("SELECT selling_price FROM items WHERE id = :id");
        $row->execute(['id' => $item_id]);
        $item = $row->fetch(PDO::FETCH_ASSOC);

        if (!$item) throw new Exception("Item not found.");

        // Deactivate any existing active cycle for this item
        $conn->prepare("UPDATE price_cycles SET is_active = 0 WHERE item_id = :item_id AND is_active = 1")
             ->execute(['item_id' => $item_id]);

        // Create the new cycle starting today
        $stmt = $conn->prepare("
            INSERT INTO price_cycles (item_id, base_price, daily_reduction, cycle_days, cycle_start, is_active)
            VALUES (:item_id, :base_price, :daily_reduction, :cycle_days, CURDATE(), 1)
        ");
        $stmt->execute([
            'item_id'         => $item_id,
            'base_price'      => $item['selling_price'],
            'daily_reduction' => $daily_reduction,
            'cycle_days'      => $cycle_days,
        ]);

        header("Location: " . $_SERVER['PHP_SELF'] . "?success=1");
        exit;
    } catch (Exception $e) {
        $error = "Error creating cycle: " . $e->getMessage();
    }
}

// ── FEEDBACK ─────────────────────────────────────────────
if (isset($_GET['success'])) $success = "Cycle created successfully!";
if (isset($_GET['deleted'])) $success = "Cycle deleted.";
if (isset($_GET['reset']))   $success = "Cycle reset — clock restarted from today.";

// ── FETCH ITEMS (for the dropdown) ───────────────────────
$items = [];
try {
    $stmt = $conn->prepare("SELECT id, item_name, selling_price FROM items ORDER BY item_name");
    $stmt->execute();
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $error = "Error fetching items: " . $e->getMessage();
}

// ── FETCH ACTIVE CYCLES (joined with item name) ───────────
$cycles = [];
try {
    $stmt = $conn->prepare("
        SELECT 
            pc.*,
            i.item_name,
            i.selling_price AS current_base_price,
            DATEDIFF(CURDATE(), pc.cycle_start) AS days_elapsed
        FROM price_cycles pc
        JOIN items i ON i.id = pc.item_id
        WHERE pc.is_active = 1
        ORDER BY pc.cycle_start DESC
    ");
    $stmt->execute();
    $cycles = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $error = "Error fetching cycles: " . $e->getMessage();
}

// ── CALCULATE EFFECTIVE PRICE ─────────────────────────────
// This is the core logic — no cron needed, math runs here
// effective_price = base_price - (days_elapsed * daily_reduction)
// Clamped to 0 so price never goes negative
// If days_elapsed >= cycle_days, the cycle has expired
foreach ($cycles as &$cycle) {
    $days    = $cycle['days_elapsed'];
    $expired = $days >= $cycle['cycle_days'];

    if ($expired) {
        // Cycle has run its full length — show as expired
        $cycle['effective_price'] = 0;
        $cycle['days_remaining']  = 0;
        $cycle['expired']         = true;
    } else {
        $reduced = $cycle['base_price'] - ($days * $cycle['daily_reduction']);
        $cycle['effective_price'] = max(0, $reduced);   // never below 0
        $cycle['days_remaining']  = $cycle['cycle_days'] - $days;
        $cycle['expired']         = false;
    }
}
unset($cycle); // clean up reference
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="/think-twice/styles.css">
    <title>Cycle Management</title>
    <style>
        body { font-family: Arial, sans-serif; padding: 20px; }

        .form-container {
            max-width: 500px;
            display: flex;
            flex-direction: column;
            gap: 10px;
            background: #f9f9f9;
            padding: 20px;
            border-radius: 8px;
            margin-bottom: 30px;
        }
        .form-container label { font-weight: bold; }
        .form-container input,
        .form-container select { padding: 8px; font-size: 14px; }
        .form-container button {
            padding: 10px;
            background: black;
            color: white;
            border: none;
            cursor: pointer;
        }

        table { width: 100%; border-collapse: collapse; margin-top: 10px; }
        th, td { padding: 10px; border: 1px solid #ddd; text-align: left; }
        th { background: #4CAF50; color: white; }

        /* Highlight rows where cycle has expired */
        .expired-row { background-color: #fff3cd; }

        .badge-active  { background: #4CAF50; color: white; padding: 2px 8px; border-radius: 4px; font-size: 12px; }
        .badge-expired { background: #e53935; color: white; padding: 2px 8px; border-radius: 4px; font-size: 12px; }

        .btn-reset  { padding: 4px 10px; background: #2196F3; color: white; border: none; border-radius: 4px; cursor: pointer; }
        .btn-delete { padding: 4px 10px; background: #e53935; color: white; border: none; border-radius: 4px; cursor: pointer; }

        .msg-success { color: green; }
        .msg-error   { color: red; }

        .hint { font-size: 12px; color: #888; margin-top: -6px; }
    </style>
</head>
<body>
<?php include __DIR__ . '/../navbar.php'; ?>

<h2>Cycle Price Management</h2>

<?php if ($success): ?><p class="msg-success"><?= htmlspecialchars($success) ?></p><?php endif; ?>
<?php if ($error):   ?><p class="msg-error"><?= htmlspecialchars($error) ?></p><?php endif; ?>


<!-- ── CREATE CYCLE FORM ──────────────────────────────── -->
<form method="POST" class="form-container">
    <h3 style="margin:0;">New Price Cycle</h3>

    <label for="item_id">Item</label>
    <select name="item_id" id="item_id" required>
        <option value="">-- Select Item --</option>
        <?php foreach ($items as $item): ?>
            <option value="<?= $item['id'] ?>">
                <?= htmlspecialchars($item['item_name']) ?>
                (current price: <?= number_format($item['selling_price'], 2) ?>)
            </option>
        <?php endforeach; ?>
    </select>
    <p class="hint">The item's current selling price will be snapshotted as the base price.</p>

    <label for="cycle_days">Cycle Length (days)</label>
    <input type="number" name="cycle_days" id="cycle_days" min="1" required placeholder="e.g. 14">
    <p class="hint">How many days before the cycle expires.</p>

    <label for="daily_reduction">Daily Reduction (KSh)</label>
    <input type="number" name="daily_reduction" id="daily_reduction" step="0.01" min="0" required placeholder="e.g. 30">
    <p class="hint">Amount deducted from the price each day at midnight.</p>

    <button type="submit" name="create_cycle">Start Cycle</button>
</form>


<!-- ── ACTIVE CYCLES TABLE ───────────────────────────── -->
<h3>Active Cycles</h3>
<?php if (empty($cycles)): ?>
    <p style="color:#999;">No active cycles. Create one above.</p>
<?php else: ?>
<table>
    <thead>
        <tr>
            <th>Item</th>
            <th>Base Price</th>
            <th>Daily Reduction</th>
            <th>Cycle Days</th>
            <th>Days Elapsed</th>
            <th>Days Remaining</th>
            <th>Effective Price Today</th>
            <th>Status</th>
            <th>Actions</th>
        </tr>
    </thead>
    <tbody>
        <?php foreach ($cycles as $cycle): ?>
        <tr class="<?= $cycle['expired'] ? 'expired-row' : '' ?>">
            <td><?= htmlspecialchars($cycle['item_name']) ?></td>
            <td>KSh <?= number_format($cycle['base_price'], 2) ?></td>
            <td>KSh <?= number_format($cycle['daily_reduction'], 2) ?>/day</td>
            <td><?= $cycle['cycle_days'] ?> days</td>
            <td><?= $cycle['days_elapsed'] ?> days</td>
            <td><?= $cycle['expired'] ? '—' : $cycle['days_remaining'] . ' days' ?></td>

            <!--
                This is the price POS / other pages should use.
                Read from price_cycles and calculate — never from items.selling_price directly.
            -->
            <td><strong>KSh <?= number_format($cycle['effective_price'], 2) ?></strong></td>

            <td>
                <?php if ($cycle['expired']): ?>
                    <span class="badge-expired">Expired</span>
                <?php else: ?>
                    <span class="badge-active">Active</span>
                <?php endif; ?>
            </td>

            <td>
                <!-- Reset: restarts the cycle clock from today -->
                <form method="POST" style="display:inline;">
                    <input type="hidden" name="reset_cycle_id" value="<?= (int) $cycle['id'] ?>">
                    <button class="btn-reset" type="submit" name="reset_cycle"
                            onclick="return confirm('Restart this cycle from today?')">
                        Reset
                    </button>
                </form>

                &nbsp;

                <!-- Delete: removes the cycle entirely -->
                <form method="POST" style="display:inline;">
                    <input type="hidden" name="delete_cycle_id" value="<?= (int) $cycle['id'] ?>">
                    <button class="btn-delete" type="submit" name="delete_cycle"
                            onclick="return confirm('Delete this cycle?')">
                        Delete
                    </button>
                </form>
            </td>
        </tr>
        <?php endforeach; ?>
    </tbody>
</table>
<?php endif; ?>

<!--
    HOW TO USE THE EFFECTIVE PRICE IN OTHER PAGES (e.g. POS):

    Instead of: $price = $item['selling_price']

    Use this query:
    SELECT 
        i.*,
        CASE 
            WHEN pc.id IS NOT NULL AND DATEDIFF(CURDATE(), pc.cycle_start) < pc.cycle_days
                THEN GREATEST(0, pc.base_price - (DATEDIFF(CURDATE(), pc.cycle_start) * pc.daily_reduction))
            ELSE i.selling_price
        END AS effective_price
    FROM items i
    LEFT JOIN price_cycles pc ON pc.item_id = i.id AND pc.is_active = 1
    WHERE i.id = :id
-->
</body>
</html>