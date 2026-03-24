<?php
require __DIR__ . '/../config/db.php';
$error   = "";
$success = "";

// Track edit state
$editing  = false;
$edit_id  = null;
$edit_name = "";
$edit_abbr = "";

// ── DELETE ──────────────────────────────────────────────
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

// ── UPDATE ───────────────────────────────────────────────
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

// ── LOAD ROW INTO FORM FOR EDITING ───────────────────────
// Triggered by clicking the Edit link: ?edit_id=X
if ($_SERVER["REQUEST_METHOD"] === "GET" && isset($_GET['edit_id'])) {
    $edit_id = (int) $_GET['edit_id'];
    try {
        $stmt = $conn->prepare("SELECT * FROM units WHERE id = :id");
        $stmt->execute(['id' => $edit_id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($row) {
            $editing   = true;           // switches form to edit mode
            $edit_name = $row['measure_name'];
            $edit_abbr = $row['abbreviation'];
        }
    } catch (PDOException $e) {
        $error = "Could not load unit: " . $e->getMessage();
    }
}

// ── CREATE ───────────────────────────────────────────────
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

// ── FEEDBACK ─────────────────────────────────────────────
if (isset($_GET['success']))  $success = "Unit created successfully!";
if (isset($_GET['updated']))  $success = "Unit updated successfully!";
if (isset($_GET['deleted']))  $success = "Unit deleted.";

// ── FETCH ALL ────────────────────────────────────────────
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
    <link rel="stylesheet" href="/think-twice/styles.css">
    <title>Units of Measure</title>
</head>
<body>
<?php include __DIR__ . '/../navbar.php'; ?>

<?php if ($success): ?><p class="msg-success"><?= htmlspecialchars($success) ?></p><?php endif; ?>
<?php if ($error):   ?><p class="msg-error"><?= htmlspecialchars($error) ?></p><?php endif; ?>

<!--
    FORM — two modes:
    Create: empty fields, submits to creating_new_measure
    Edit:   pre-filled fields, hidden id, submits to update_unit
-->
<form style="display:flex; flex-direction:column; width:60%;" method="POST">

    <?php if ($editing): ?>
        <!-- Carries the ID to the update handler -->
        <input type="hidden" name="edit_unit_id" value="<?= $edit_id ?>">
    <?php endif; ?>

    <label for="unitAbbreviation">Unit Abbreviation</label>
    <input type="text" name="unitAbbreviation" id="unitAbbreviation"
           value="<?= htmlspecialchars($edit_abbr) ?>">

    <label for="descriptiveName">Descriptive Name</label>
    <input type="text" name="descriptiveName" id="descriptiveName"
           value="<?= htmlspecialchars($edit_name) ?>">

    <?php if ($editing): ?>
        <button type="submit" name="update_unit">Save Changes</button>
        <a href="<?= $_SERVER['PHP_SELF'] ?>">Cancel</a>
    <?php else: ?>
        <button type="submit" name="creating_new_measure">Add Unit</button>
    <?php endif; ?>

</form>

<table>
    <thead>
        <tr>
            <th>Unit Name</th>
            <th>Abbreviation</th>
            <th>Actions</th>
        </tr>
    </thead>
    <tbody>
        <?php foreach ($measures as $measure): ?>
        <tr>
            <td><?= htmlspecialchars($measure['measure_name']) ?></td>
            <td><?= htmlspecialchars($measure['abbreviation']) ?></td>
            <td>
                <!-- Edit: GET link loads this row into the form above -->
                <a href="<?= $_SERVER['PHP_SELF'] ?>?edit_id=<?= (int) $measure['id'] ?>">Edit</a>

                <!-- Delete: POST form with just the ID -->
                <form method="POST" style="display:inline;">
                    <input type="hidden" name="delete_unit_id" value="<?= (int) $measure['id'] ?>">
                    <button type="submit" name="delete_unit"
                            onclick="return confirm('Delete <?= htmlspecialchars(addslashes($measure['measure_name'])) ?>?')">
                        Delete
                    </button>
                </form>
            </td>
        </tr>
        <?php endforeach; ?>
    </tbody>
</table>
</body>
</html>