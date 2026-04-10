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
            $editCategoryName = $row['category_name'];
            $editUnit = $row['unit'];
            $editCategoryType = $row['category_type'];
            $editPurchaseExcluded = $row['purchase_excluded'];
            $editSalesExcluded = $row['sales_excluded'];
            $editTaxType = $row['tax_type'];
            $editDescription = $row['description'];
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

        .message {
            padding: 12px 15px;
            margin-bottom: 20px;
            border-radius: 6px;
            font-weight: 500;
        }

        .success {
            background-color: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }

        .error {
            background-color: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }

        /* Form Styling */
        form {
            background: white;
            padding: 25px;
            border-radius: 8px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            max-width: 700px;
            margin-bottom: 40px;
        }

        label {
            display: block;
            margin: 15px 0 5px;
            font-weight: 600;
            color: #444;
        }

        input[type="text"], 
        select, 
        textarea {
            width: 100%;
            padding: 10px;
            border: 1px solid #ccc;
            border-radius: 5px;
            font-size: 15px;
            box-sizing: border-box;
        }

        input[type="checkbox"] {
            margin-right: 8px;
            transform: scale(1.2);
        }

        button {
            background-color: #3498db;
            color: white;
            padding: 12px 25px;
            border: none;
            border-radius: 5px;
            font-size: 16px;
            cursor: pointer;
            margin-top: 20px;
        }

        button:hover {
            background-color: #2980b9;
        }

        /* Table Styling */
        table {
            width: 100%;
            border-collapse: collapse;
            background: white;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            border-radius: 8px;
            overflow: hidden;
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
            margin-right: 10px;
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

    <h1>Item Categories</h1>

    <?php if(!empty($success) || isset($_GET['success']) || isset($_GET['updated']) || isset($_GET['deleted'])): ?>
        <p class="message success">
            <?= isset($_GET['success']) ? 'Category created successfully!' : '' ?>
            <?= isset($_GET['updated']) ? 'Category updated successfully!' : '' ?>
            <?= isset($_GET['deleted']) ? 'Category deleted successfully!' : '' ?>
            <?= htmlspecialchars($success) ?>
        </p>
    <?php endif; ?>

    <?php if($error): ?>
        <p class="message error"><?= htmlspecialchars($error) ?></p>
    <?php endif; ?>

    <!-- Category Form -->
    <form method="POST">
        <?php if($editing): ?>
            <input type="hidden" name="updating-id" value="<?= $editingId ?>"> 
        <?php endif; ?>

        <label>Category Name</label>
        <input type="text" name="categoryName" value="<?= htmlspecialchars($editing ? $editCategoryName : '') ?>" required>

        <label>Unit of Measure</label>
        <select name="unit" required>
            <option value="">-- Select Unit --</option>
            <?php foreach($units as $unit): ?>
                <option value="<?= htmlspecialchars($unit['measure_name']) ?>" 
                        <?= ($editing && $unit['measure_name'] === $editUnit) ? 'selected' : '' ?>>
                    <?= htmlspecialchars($unit['measure_name']) ?>
                </option>
            <?php endforeach; ?>
        </select>

        <label>Category Type</label>
        <select name="categoryType" required>
            <option value="">-- Select Type --</option>
            <option value="Raw Material" <?= ($editing && $editCategoryType === 'Raw Material') ? 'selected' : '' ?>>Raw Material</option>
            <option value="Finished Good" <?= ($editing && $editCategoryType === 'Finished Good') ? 'selected' : '' ?>>Finished Good</option>
        </select>

        <label>
            <input type="checkbox" name="purchaseExcluded" value="1" <?= $editing && $editPurchaseExcluded ? 'checked' : '' ?>>
            Exclude from Purchase
        </label>

        <label>
            <input type="checkbox" name="salesExcluded" value="1" <?= $editing && $editSalesExcluded ? 'checked' : '' ?>>
            Exclude from Sale
        </label>

        <label>Tax Rate (%)</label>
        <select name="taxType" required>
            <option value="">-- Select Tax Type --</option>
            <option value="VAT16" <?= ($editing && $editTaxType === 'VAT16') ? 'selected' : '' ?>>VAT 16%</option>
            <option value="ZERO" <?= ($editing && $editTaxType === 'ZERO') ? 'selected' : '' ?>>Zero Rated</option>
            <option value="EXEMPT" <?= ($editing && $editTaxType === 'EXEMPT') ? 'selected' : '' ?>>Exempt</option>
        </select>

        <label>Description</label>
        <textarea name="description" rows="4"><?= htmlspecialchars($editing ? $editDescription : '') ?></textarea>

        <button type="submit" name="<?= $editing ? 'update-category' : 'create-category' ?>">
            <?= $editing ? 'Update Category' : 'Create Category' ?>
        </button>
    </form>

    <!-- Categories Table -->
    <h2>All Categories (<?= count($categories) ?>)</h2>
    
    <table>
        <thead>
            <tr>
                <th>ID</th>
                <th>Category Name</th>
                <th>Unit</th>
                <th>Category Type</th>
                <th>Exclude Purchase</th>
                <th>Exclude Sale</th>
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
                <td><?= htmlspecialchars($category['unit']) ?></td>
                <td><?= htmlspecialchars($category['category_type']) ?></td>
                <td><?= $category['purchase_excluded'] ? 'Yes' : 'No' ?></td>
                <td><?= $category['sales_excluded'] ? 'Yes' : 'No' ?></td>
                <td><?= htmlspecialchars($category['tax_type']) ?></td>
                <td><?= htmlspecialchars($category['description'] ?? '') ?></td>
                <td class="actions">
                    <a href="<?= $_SERVER['PHP_SELF'] ?>?editingId=<?= (int)$category['id'] ?>">Edit</a>
                    <form method="POST" style="display:inline;" onsubmit="return confirm('Are you sure you want to delete this category?');">
                        <input type="hidden" name="delete-category-id" value="<?= $category['id'] ?>">
                        <button type="submit" name="delete-category" class="delete-btn">Delete</button>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>

</body>
</html>