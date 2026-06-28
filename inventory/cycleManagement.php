<?php
session_start();
require __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/authGuard.php';
requireLogin();

$error   = "";
$success = "";

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_cycle'])) {
    try {
        $id = (int) $_POST['delete_cycle_id'];
        $conn->prepare("DELETE FROM price_cycles WHERE id = :id")->execute(['id' => $id]);
        header("Location: " . $_SERVER['PHP_SELF'] . "?deleted=1");
        exit;
    } catch (PDOException $e) {
        $error = "Error deleting cycle: " . $e->getMessage();
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['reset_cycle'])) {
    try {
        $id = (int) $_POST['reset_cycle_id'];
        $conn->prepare("UPDATE price_cycles SET cycle_start = CURDATE() WHERE id = :id")->execute(['id' => $id]);
        header("Location: " . $_SERVER['PHP_SELF'] . "?reset=1");
        exit;
    } catch (PDOException $e) {
        $error = "Error resetting cycle: " . $e->getMessage();
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_cycle'])) {
    try {
        $item_id         = (int)   $_POST['item_id'];
        $daily_reduction = (float) $_POST['daily_reduction'];
        $cycle_days      = (int)   $_POST['cycle_days'];

        $row = $conn->prepare("SELECT selling_price FROM items WHERE id = :id");
        $row->execute(['id' => $item_id]);
        $item = $row->fetch(PDO::FETCH_ASSOC);
        if (!$item) throw new Exception("Item not found.");

        $conn->prepare("UPDATE price_cycles SET is_active = 0 WHERE item_id = :item_id AND is_active = 1")
             ->execute(['item_id' => $item_id]);

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

if (isset($_GET['success'])) $success = "Price cycle started successfully!";
if (isset($_GET['deleted'])) $success = "Cycle deleted.";
if (isset($_GET['reset']))   $success = "Cycle reset — clock restarted from today.";

$items = [];
try {
    $stmt = $conn->prepare("SELECT id, item_name, selling_price FROM items ORDER BY item_name");
    $stmt->execute();
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $error = "Error fetching items: " . $e->getMessage();
}

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

foreach ($cycles as &$cycle) {
    $days    = $cycle['days_elapsed'];
    $expired = $days >= $cycle['cycle_days'];
    if ($expired) {
        $cycle['effective_price'] = 0;
        $cycle['days_remaining']  = 0;
        $cycle['expired']         = true;
    } else {
        $reduced = $cycle['base_price'] - ($days * $cycle['daily_reduction']);
        $cycle['effective_price'] = max(0, $reduced);
        $cycle['days_remaining']  = $cycle['cycle_days'] - $days;
        $cycle['expired']         = false;
    }
}
unset($cycle);

$activeCycles  = count(array_filter($cycles, fn($c) => !$c['expired']));
$expiredCycles = count(array_filter($cycles, fn($c) => $c['expired']));
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Cycle Price Management - Think Twice</title>
  <link rel="stylesheet" href="/think-twice/public/theme.css">
</head>
<body class="page-container">

  <?php include __DIR__ . '/../navbar.php'; ?>

  <div class="page-header">
    <h1 class="page-title">Price Cycle Management</h1>
    <p class="page-subtitle">Automatically reduce prices over time to clear aging inventory</p>
  </div>

  <div class="page-content">

    <?php if ($error): ?>
      <div class="alert alert-danger">⚠ <?= htmlspecialchars($error) ?></div>
    <?php endif; ?>
    <?php if ($success): ?>
      <div class="alert alert-success">✓ <?= htmlspecialchars($success) ?></div>
    <?php endif; ?>

    <!-- STATS -->
    <div class="grid grid-3 mb-lg">
      <div class="stat-box">
        <div class="stat-value"><?= count($cycles) ?></div>
        <div class="stat-label">Total Cycles</div>
      </div>
      <div class="stat-box">
        <div class="stat-value" style="color: var(--success)"><?= $activeCycles ?></div>
        <div class="stat-label">Active Cycles</div>
      </div>
      <div class="stat-box">
        <div class="stat-value" style="color: var(--danger)"><?= $expiredCycles ?></div>
        <div class="stat-label">Expired Cycles</div>
      </div>
    </div>

    <div class="grid grid-3 gap-lg">

      <!-- CREATE FORM -->
      <div class="card">
        <div class="card-header">
          <div class="card-title">New Price Cycle</div>
        </div>

        <form method="POST">
          <div class="form-group">
            <label>Item</label>
            <select name="item_id" id="item_id" required>
              <option value="">-- Select Item --</option>
              <?php foreach ($items as $item): ?>
                <option value="<?= $item['id'] ?>">
                  <?= htmlspecialchars($item['item_name']) ?>
                  (KES <?= number_format($item['selling_price'], 0) ?>)
                </option>
              <?php endforeach; ?>
            </select>
            <small class="text-muted" style="display:block; margin-top:6px; font-size:12px;">
              Current selling price will be used as base
            </small>
          </div>

          <div class="form-group">
            <label>Cycle Length (days)</label>
            <input type="number" name="cycle_days" min="1" required placeholder="e.g. 14">
            <small class="text-muted" style="display:block; margin-top:6px; font-size:12px;">
              How many days before the cycle expires
            </small>
          </div>

          <div class="form-group">
            <label>Daily Reduction (KES)</label>
            <input type="number" name="daily_reduction" step="0.01" min="0" required placeholder="e.g. 30">
            <small class="text-muted" style="display:block; margin-top:6px; font-size:12px;">
              Amount deducted from price each day
            </small>
          </div>

          <button type="submit" name="create_cycle" class="btn btn-primary btn-block">Start Cycle</button>
        </form>
      </div>

      <!-- CYCLES TABLE -->
      <div class="card" style="grid-column: span 2;">
        <div class="card-header">
          <div class="card-title">Active Cycles (<?= count($cycles) ?>)</div>
        </div>

        <?php if (empty($cycles)): ?>
          <div class="text-center text-muted" style="padding: var(--space-xl);">
            No price cycles. Create one to start automated price reductions.
          </div>
        <?php else: ?>
          <div style="overflow-x: auto;">
            <table class="table">
              <thead>
                <tr>
                  <th>Item</th>
                  <th>Base Price</th>
                  <th>Daily Drop</th>
                  <th>Progress</th>
                  <th>Today's Price</th>
                  <th>Status</th>
                  <th>Actions</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($cycles as $cycle): ?>
                <tr>
                  <td class="font-semibold"><?= htmlspecialchars($cycle['item_name']) ?></td>
                  <td class="font-mono">KES <?= number_format($cycle['base_price'], 2) ?></td>
                  <td class="font-mono text-danger">-KES <?= number_format($cycle['daily_reduction'], 2) ?>/day</td>
                  <td>
                    <?php
                      $progress = $cycle['cycle_days'] > 0
                          ? min(100, round(($cycle['days_elapsed'] / $cycle['cycle_days']) * 100))
                          : 100;
                    ?>
                    <div style="display: flex; align-items: center; gap: 8px;">
                      <div style="flex: 1; background: var(--bg); border-radius: 4px; height: 6px; overflow: hidden;">
                        <div style="width: <?= $progress ?>%; height: 100%; background: <?= $cycle['expired'] ? 'var(--danger)' : 'var(--primary)' ?>; border-radius: 4px;"></div>
                      </div>
                      <span class="text-muted" style="font-size: 11px; white-space: nowrap;">
                        <?= $cycle['days_elapsed'] ?>/<?= $cycle['cycle_days'] ?>d
                      </span>
                    </div>
                  </td>
                  <td>
                    <strong class="<?= $cycle['expired'] ? 'text-danger' : 'text-primary' ?> font-mono">
                      KES <?= number_format($cycle['effective_price'], 2) ?>
                    </strong>
                  </td>
                  <td>
                    <?php if ($cycle['expired']): ?>
                      <span class="badge badge-danger">Expired</span>
                    <?php else: ?>
                      <span class="badge badge-primary"><?= $cycle['days_remaining'] ?>d left</span>
                    <?php endif; ?>
                  </td>
                  <td>
                    <div class="flex gap-sm">
                      <form method="POST" style="display:inline;">
                        <input type="hidden" name="reset_cycle_id" value="<?= (int) $cycle['id'] ?>">
                        <button type="submit" name="reset_cycle" class="btn btn-secondary btn-sm"
                                onclick="return confirm('Restart this cycle from today?')">Reset</button>
                      </form>
                      <form method="POST" style="display:inline;">
                        <input type="hidden" name="delete_cycle_id" value="<?= (int) $cycle['id'] ?>">
                        <button type="submit" name="delete_cycle" class="btn btn-danger btn-sm"
                                onclick="return confirm('Delete this cycle?')">Delete</button>
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
