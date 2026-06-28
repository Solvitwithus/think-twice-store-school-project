<?php
session_start();
require __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/authGuard.php';
requireLogin();

$error   = "";
$success = "";

$editing  = false;
$edit_id  = null;
$edit_name = "";
$edit_abbr = "";

if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST['delete_unit'])) {
    try {
        $id = (int) $_POST['delete_unit_id'];
        $conn->prepare("DELETE FROM units WHERE id = :id")->execute(['id' => $id]);
        header("Location: " . $_SERVER['PHP_SELF'] . "?deleted=1");
        exit;
    } catch (PDOException $e) {
        $error = "Error deleting: " . $e->getMessage();
    }
}

if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST['update_unit'])) {
    try {
        $id   = (int) $_POST['edit_unit_id'];
        $name = $_POST['descriptiveName'];
        $abbr = $_POST['unitAbbreviation'];
        $stmt = $conn->prepare("UPDATE units SET measure_name = :name, abbreviation = :abbr WHERE id = :id");
        $stmt->execute(['name' => $name, 'abbr' => $abbr, 'id' => $id]);
        header("Location: " . $_SERVER['PHP_SELF'] . "?updated=1");
        exit;
    } catch (PDOException $e) {
        $error = "Error updating: " . $e->getMessage();
    }
}

if ($_SERVER["REQUEST_METHOD"] === "GET" && isset($_GET['edit_id'])) {
    $edit_id = (int) $_GET['edit_id'];
    try {
        $stmt = $conn->prepare("SELECT * FROM units WHERE id = :id");
        $stmt->execute(['id' => $edit_id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            $editing   = true;
            $edit_name = $row['measure_name'];
            $edit_abbr = $row['abbreviation'];
        }
    } catch (PDOException $e) {
        $error = "Could not load unit: " . $e->getMessage();
    }
}

if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST['creating_new_measure'])) {
    try {
        $abbr = $_POST['unitAbbreviation'];
        $name = $_POST['descriptiveName'];
        $stmt = $conn->prepare("INSERT INTO units (measure_name, abbreviation) VALUES (:name, :abbr)");
        $stmt->execute(['name' => $name, 'abbr' => $abbr]);
        header("Location: " . $_SERVER['PHP_SELF'] . "?success=1");
        exit;
    } catch (PDOException $e) {
        $error = "Error creating: " . $e->getMessage();
    }
}

if (isset($_GET['success']))  $success = "Unit created successfully!";
if (isset($_GET['updated']))  $success = "Unit updated successfully!";
if (isset($_GET['deleted']))  $success = "Unit deleted successfully!";

$measures = [];
try {
    $stmt = $conn->prepare("SELECT * FROM units ORDER BY measure_name");
    $stmt->execute();
    $measures = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $error = "Error fetching: " . $e->getMessage();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Units of Measure - Think Twice</title>
  <link rel="stylesheet" href="/think-twice/public/theme.css">
</head>
<body class="page-container">

  <?php include __DIR__ . '/../navbar.php'; ?>

  <div class="page-header">
    <h1 class="page-title">Units of Measure</h1>
    <p class="page-subtitle">Define measurement units used across inventory items</p>
  </div>

  <div class="page-content">

    <?php if ($error): ?>
      <div class="alert alert-danger">⚠ <?= htmlspecialchars($error) ?></div>
    <?php endif; ?>
    <?php if ($success): ?>
      <div class="alert alert-success">✓ <?= htmlspecialchars($success) ?></div>
    <?php endif; ?>

    <div class="grid grid-3 gap-lg">

      <!-- FORM -->
      <div class="card">
        <div class="card-header">
          <div class="card-title"><?= $editing ? 'Edit Unit' : 'Add New Unit' ?></div>
        </div>

        <form method="POST">
          <?php if ($editing): ?>
            <input type="hidden" name="edit_unit_id" value="<?= $edit_id ?>">
          <?php endif; ?>

          <div class="form-group">
            <label for="unitAbbreviation">Abbreviation</label>
            <input type="text" name="unitAbbreviation" id="unitAbbreviation"
                   value="<?= htmlspecialchars($edit_abbr) ?>"
                   placeholder="e.g. kg, pcs, L" required>
          </div>

          <div class="form-group">
            <label for="descriptiveName">Full Name</label>
            <input type="text" name="descriptiveName" id="descriptiveName"
                   value="<?= htmlspecialchars($edit_name) ?>"
                   placeholder="e.g. Kilogram, Pieces, Litre" required>
          </div>

          <div class="flex gap-md">
            <?php if ($editing): ?>
              <button type="submit" name="update_unit" class="btn btn-primary flex-1">Save Changes</button>
              <a href="<?= $_SERVER['PHP_SELF'] ?>" class="btn btn-secondary flex-1" style="text-align:center;">Cancel</a>
            <?php else: ?>
              <button type="submit" name="creating_new_measure" class="btn btn-primary btn-block">Add Unit</button>
            <?php endif; ?>
          </div>
        </form>
      </div>

      <!-- TABLE -->
      <div class="card" style="grid-column: span 2;">
        <div class="card-header">
          <div class="card-title">All Units (<?= count($measures) ?>)</div>
        </div>

        <?php if (empty($measures)): ?>
          <div class="text-center text-muted" style="padding: var(--space-xl);">
            No units added yet. Create one to get started.
          </div>
        <?php else: ?>
          <table class="table">
            <thead>
              <tr>
                <th>Full Name</th>
                <th>Abbreviation</th>
                <th>Actions</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($measures as $measure): ?>
              <tr>
                <td class="font-semibold"><?= htmlspecialchars($measure['measure_name']) ?></td>
                <td><span class="badge badge-primary"><?= htmlspecialchars($measure['abbreviation']) ?></span></td>
                <td>
                  <div class="flex gap-sm">
                    <a href="?edit_id=<?= (int) $measure['id'] ?>" class="btn btn-secondary btn-sm">Edit</a>
                    <form method="POST" style="display:inline;"
                          onsubmit="return confirm('Delete <?= htmlspecialchars(addslashes($measure['measure_name'])) ?>?')">
                      <input type="hidden" name="delete_unit_id" value="<?= (int) $measure['id'] ?>">
                      <button type="submit" name="delete_unit" class="btn btn-danger btn-sm">Delete</button>
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

</body>
</html>
