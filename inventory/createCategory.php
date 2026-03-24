<?php
require __DIR__ . '/../config/db.php';
$error   = "";
$success = "";
$editing = false;
$editingId = null;

// Variables to prefill edit form
$editCategoryName = "";
$editUnit = "";
$editCategoryType = "";
$editPurchaseExcluded = 0;
$editSalesExcluded = 0;
$editTaxType = "";
$editDescription = "";

// ----------------------------
// Delete Category
// ----------------------------
if($_SERVER['REQUEST_METHOD'] === "POST" && isset($_POST['delete-category'])){
    $categoryId = $_POST['delete-category-id'];

    try{
        $query = $conn->prepare("DELETE FROM itemCategory WHERE id = :categoryId");
        $query->execute(['categoryId' => $categoryId]);
        header("Location: " . $_SERVER['PHP_SELF'] . "?deleted=1");
        exit;
    } catch(PDOException $e){
        $error = "Error deleting itemCategory: " . $e->getMessage();
    }
}

// ----------------------------
// Fetch category to edit
// ----------------------------
if($_SERVER["REQUEST_METHOD"] === "GET" && isset($_GET['editingId'])){
    $editingId = (int) $_GET['editingId'];
    try{
        $query = $conn->prepare("SELECT * FROM itemCategory WHERE id = :id");
        $query->execute(['id' => $editingId]);
        $row = $query->fetch(PDO::FETCH_ASSOC);

        if($row){
            $editing = true;
            $editCategoryName = $row['category_name'];      // UPDATED
            $editUnit = $row['unit'];                        // UPDATED
            $editCategoryType = $row['category_type'];      // UPDATED
            $editPurchaseExcluded = $row['purchase_excluded']; // UPDATED
            $editSalesExcluded = $row['sales_excluded'];    // UPDATED
            $editTaxType = $row['tax_type'];                // UPDATED
            $editDescription = $row['description'];         // UPDATED
        }
    } catch(PDOException $e){
        $error = "Error fetching category for edit: " . $e->getMessage();
    }
}

// ----------------------------
// Update Category
// ----------------------------
if($_SERVER['REQUEST_METHOD'] === "POST" && isset($_POST['update-category'])){
    try{
        $categoryId = $_POST['updating-id'];
        $editCategoryName = $_POST['categoryName'] ?? '';
        $editUnit = $_POST['unit'] ?? '';
        $editCategoryType = $_POST['categoryType'] ?? '';
        $editPurchaseExcluded = $_POST['purchaseExcluded'] ?? 0;
        $editSalesExcluded = $_POST['salesExcluded'] ?? 0;
        $editTaxType = $_POST['taxType'] ?? '';
        $editDescription = $_POST['description'] ?? '';

        $query = $conn->prepare("
            UPDATE itemCategory
            SET 
                category_name = :category_name,
                unit = :unit,
                category_type = :category_type,
                purchase_excluded = :purchase_excluded,
                sales_excluded = :sales_excluded,
                tax_type = :tax_type,
                description = :description
            WHERE id = :id
        ");

        $query->execute([
            'category_name' => $editCategoryName,
            'unit' => $editUnit,
            'category_type' => $editCategoryType,
            'purchase_excluded' => $editPurchaseExcluded,
            'sales_excluded' => $editSalesExcluded,
            'tax_type' => $editTaxType,
            'description' => $editDescription,
            'id' => $categoryId
        ]);

        header("Location: " . $_SERVER['PHP_SELF'] . "?updated=1");
        exit;
    } catch(PDOException $e){
        $error = "Error updating category: " . $e->getMessage();
    }
}

// ----------------------------
// Create Category
// ----------------------------
if($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST['create-category'])){
    try{
        $categoryName = $_POST['categoryName'] ?? '';
        $unit = $_POST['unit'] ?? '';
        $categoryType = $_POST['categoryType'] ?? '';
        $purchaseExcluded = $_POST['purchaseExcluded'] ?? 0;
        $salesExcluded = $_POST['salesExcluded'] ?? 0;
        $taxType = $_POST['taxType'] ?? '';
        $description = $_POST['description'] ?? '';

        $query = $conn->prepare("
            INSERT INTO itemCategory
            (category_name, unit, category_type, purchase_excluded, sales_excluded, tax_type, description)
            VALUES
            (:category_name, :unit, :category_type, :purchase_excluded, :sales_excluded, :tax_type, :description)
        ");

        $query->execute([
            'category_name' => $categoryName,
            'unit' => $unit,
            'category_type' => $categoryType,
            'purchase_excluded' => $purchaseExcluded,
            'sales_excluded' => $salesExcluded,
            'tax_type' => $taxType,
            'description' => $description
        ]);

        header("Location: " . $_SERVER['PHP_SELF'] . "?success=1");
        exit;
    } catch(PDOException $e){
        $error = "Error creating category: " . $e->getMessage();
    }
}

// ----------------------------
// Fetch Units
// ----------------------------
try{
    $units = [];
    $query = $conn->prepare("SELECT * FROM units ORDER BY measure_name");
    $query->execute();
    $units = $query->fetchAll(PDO::FETCH_ASSOC);
} catch(PDOException $e){
    $error = "Error fetching units: " . $e->getMessage();
}

// ----------------------------
// Fetch Categories
// ----------------------------
try{
    $categories = [];
    $query = $conn->prepare("SELECT * FROM itemCategory ORDER BY category_name");
    $query->execute();
    $categories = $query->fetchAll(PDO::FETCH_ASSOC);
} catch(PDOException $e){
    $error = "Error fetching categories: " . $e->getMessage();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Item Categories</title>
</head>
<body>

<?php if($success): ?>
    <p style="color:green"><?= htmlspecialchars($success) ?></p>
<?php endif; ?>
<?php if($error): ?>
    <p style="color:red"><?= htmlspecialchars($error) ?></p>
<?php endif; ?>

<!-- Category Form -->
<form method="POST">
    <?php if($editing): ?>
        <input type="hidden" name="updating-id" value="<?= $editingId ?>"> 
    <?php endif; ?>

    <label>Category Name</label>
    <input type="text" name="categoryName" value="<?= htmlspecialchars($editing ? $editCategoryName : '') ?>"> <!-- UPDATED -->

    <label>Unit of Measure</label>
    <select name="unit">
        <?php foreach($units as $unit): ?>
            <option value="<?= htmlspecialchars($unit['measure_name']) ?>" <?= ($editing && $unit['measure_name'] === $editUnit) ? 'selected' : '' ?>> <!-- UPDATED -->
                <?= htmlspecialchars($unit['measure_name']) ?>
            </option>
        <?php endforeach; ?>
    </select>

    <label>Category Type</label>
    <select name="categoryType">
        <option value="Raw Material" <?= ($editing && $editCategoryType === 'Raw Material') ? 'selected' : '' ?>>Raw Material</option> <!-- UPDATED -->
        <option value="Finished Good" <?= ($editing && $editCategoryType === 'Finished Good') ? 'selected' : '' ?>>Finished Good</option> <!-- UPDATED -->
    </select>

    <label>
        <input type="checkbox" name="purchaseExcluded" value="1" <?= $editing && $editPurchaseExcluded ? 'checked' : '' ?>> Exclude from Purchase <!-- UPDATED -->
    </label>

    <label>
        <input type="checkbox" name="salesExcluded" value="1" <?= $editing && $editSalesExcluded ? 'checked' : '' ?>> Exclude from Sale <!-- UPDATED -->
    </label>

    <label>Tax Rate (%)</label>
    <select name="taxType">
        <option value="VAT16" <?= ($editing && $editTaxType === 'VAT16') ? 'selected' : '' ?>>VAT 16%</option> <!-- UPDATED -->
        <option value="ZERO" <?= ($editing && $editTaxType === 'ZERO') ? 'selected' : '' ?>>Zero Rated</option> <!-- UPDATED -->
        <option value="EXEMPT" <?= ($editing && $editTaxType === 'EXEMPT') ? 'selected' : '' ?>>Exempt</option> <!-- UPDATED -->
    </select>

    <label>Description</label>
    <textarea name="description"><?= htmlspecialchars($editing ? $editDescription : '') ?></textarea> <!-- UPDATED -->

    <button type="submit" name="<?= $editing ? 'update-category' : 'create-category' ?>"> <!-- UPDATED -->
        <?= $editing ? 'Update Category' : 'Create Category' ?> <!-- UPDATED -->
    </button>
</form>

<!-- Categories Table -->
<table border="1" cellpadding="5" cellspacing="0">
<thead>
<tr>
    <th>ID</th>
    <th>Category Name</th>
    <th>Unit</th>
    <th>Category Type</th>
    <th>Exclude from Purchase</th>
    <th>Exclude from Sale</th>
    <th>Tax Type</th>
    <th>Description</th>
    <th>Actions</th>
</tr>
</thead>
<tbody>
<?php foreach($categories as $category): ?>
<tr>
    <td><?= htmlspecialchars($category['id']) ?></td>
    <td><?= htmlspecialchars($category['category_name']) ?></td>
    <td><?= htmlspecialchars($category['unit']) ?></td> <!-- UPDATED -->
    <td><?= htmlspecialchars($category['category_type']) ?></td>
    <td><?= $category['purchase_excluded'] ? 'Yes' : 'No' ?></td>
    <td><?= $category['sales_excluded'] ? 'Yes' : 'No' ?></td>
    <td><?= htmlspecialchars($category['tax_type']) ?></td>
    <td><?= htmlspecialchars($category['description']) ?></td>
    <td>
        <a href="<?= $_SERVER['PHP_SELF'] ?>?editingId=<?= (int)$category['id'] ?>">Edit</a>
        <form method="POST" style="display:inline;">
            <input type="hidden" name="delete-category-id" value="<?= $category['id'] ?>">
            <button type="submit" name="delete-category">Delete</button>
        </form>
    </td>
</tr>
<?php endforeach; ?>
</tbody>
</table>

</body>
</html>