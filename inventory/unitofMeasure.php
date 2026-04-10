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
if (isset($_GET['deleted']))  $success = "Unit deleted successfully!";

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
    <title>Units of Measure</title>
    <style>
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background-color: #f8f9fa;
            margin: 0;
            padding: 20px;
            color: #333;
        }

        h1 {
            color: #2c3e50;
            margin-bottom: 20px;
        }

        .msg-success {
            background-color: #d4edda;
            color: #155724;
            padding: 12px 15px;
            border-radius: 6px;
            border: 1px solid #c3e6cb;
            margin-bottom: 20px;
        }

        .msg-error {
            background-color: #f8d7da;
            color: #721c24;
            padding: 12px 15px;
            border-radius: 6px;
            border: 1px solid #f5c6cb;
            margin-bottom: 20px;
        }

        /* Form Styling */
        form {
            background: white;
            padding: 25px;
            border-radius: 8px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            max-width: 500px;
            margin-bottom: 40px;
        }

        label {
            display: block;
            margin: 15px 0 5px;
            font-weight: 600;
            color: #444;
        }

        input[type="text"] {
            width: 100%;
            padding: 10px;
            border: 1px solid #ccc;
            border-radius: 5px;
            font-size: 15px;
            box-sizing: border-box;
        }

        button {
            background-color: #3498db;
            color: white;
            padding: 12px 25px;
            border: none;
            border-radius: 5px;
            font-size: 16px;
            cursor: pointer;
            margin-top: 15px;
        }

        button:hover {
            background-color: #2980b9;
        }

        .cancel-link {
            color: #777;
            text-decoration: none;
            margin-left: 15px;
            font-size: 16px;
        }

        .cancel-link:hover {
            color: #555;
            text-decoration: underline;
        }

        /* Table Styling */
        table {
            width: 100%;
            border-collapse: collapse;
            background: white;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            border-radius: 8px;
            overflow: hidden;
            max-width: 800px;
        }

        th {
            background-color: #2c3e50;
            color: white;
            padding: 12px;
            text-align: left;
        }

        td {
            padding: 12px;
            border-bottom: 1px solid #eee;
        }

        tr:hover {
            background-color: #f1f1f1;
        }

        .actions a {
            color: #3498db;
            text-decoration: none;
            margin-right: 12px;
        }

        .actions a:hover {
            text-decoration: underline;
        }

        .delete-btn {
            background-color: #e74c3c;
            color: white;
            padding: 6px 12px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-size: 14px;
        }

        .delete-btn:hover {
            background-color: #c0392b;
        }

        /* Responsive */
        @media (max-width: 768px) {
            form, table {
                max-width: 100%;
            }
        }
    </style>
</head>
<body>

    <?php include __DIR__ . '/../navbar.php'; ?>

    <h1>Units of Measure</h1>

    <?php if ($success): ?>
        <p class="msg-success"><?= htmlspecialchars($success) ?></p>
    <?php endif; ?>

    <?php if ($error): ?>
        <p class="msg-error"><?= htmlspecialchars($error) ?></p>
    <?php endif; ?>

    <!-- Form -->
    <form method="POST">
        <?php if ($editing): ?>
            <input type="hidden" name="edit_unit_id" value="<?= $edit_id ?>">
        <?php endif; ?>

        <label for="unitAbbreviation">Unit Abbreviation</label>
        <input type="text" name="unitAbbreviation" id="unitAbbreviation"
               value="<?= htmlspecialchars($edit_abbr) ?>" required>

        <label for="descriptiveName">Descriptive Name (e.g. Kilogram)</label>
        <input type="text" name="descriptiveName" id="descriptiveName"
               value="<?= htmlspecialchars($edit_name) ?>" required>

        <?php if ($editing): ?>
            <button type="submit" name="update_unit">Save Changes</button>
            <a href="<?= $_SERVER['PHP_SELF'] ?>" class="cancel-link">Cancel</a>
        <?php else: ?>
            <button type="submit" name="creating_new_measure">Add New Unit</button>
        <?php endif; ?>
    </form>

    <!-- Table -->
    <h2>All Units (<?= count($measures) ?>)</h2>

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
                <td><strong><?= htmlspecialchars($measure['abbreviation']) ?></strong></td>
                <td class="actions">
                    <a href="<?= $_SERVER['PHP_SELF'] ?>?edit_id=<?= (int) $measure['id'] ?>">Edit</a>
                    
                    <form method="POST" style="display:inline;" 
                          onsubmit="return confirm('Delete <?= htmlspecialchars(addslashes($measure['measure_name'])) ?>?')">
                        <input type="hidden" name="delete_unit_id" value="<?= (int) $measure['id'] ?>">
                        <button type="submit" name="delete_unit" class="delete-btn">Delete</button>
                    </form>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>

</body>
</html>