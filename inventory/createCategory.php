<?php
session_start();
require __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/authGuard.php';
requireLogin();

$error   = "";
$success = "";
$editing = false;
$editingId = null;

$editCategoryName     = "";
$editUnit             = "";
$editCategoryType     = "";
$editPurchaseExcluded = 0;
$editSalesExcluded    = 0;
$editTaxType          = "";
$editDescription      = "";

if ($_SERVER['REQUEST_METHOD'] === "POST" && isset($_POST['delete-category'])) {
    $categoryId = $_POST['delete-category-id'];
    try {
        $query = $conn->prepare("DELETE FROM itemCategory WHERE id = :categoryId");
        $query->execute(['categoryId' => $categoryId]);
        header("Location: " . $_SERVER['PHP_SELF'] . "?deleted=1");
        exit;
    } catch (PDOException $e) {
        $error = "Error deleting category: " . $e->getMessage();
    }
}

if ($_SERVER["REQUEST_METHOD"] === "GET" && isset($_GET['editingId'])) {
    $editingId = (int) $_GET['editingId'];
    try {
        $query = $conn->prepare("SELECT * FROM itemCategory WHERE id = :id");
        $query->execute(['id' => $editingId]);
        $row = $query->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            $editing              = true;
            $editCategoryName     = $row['category_name'];
            $editUnit             = $row['unit'];
            $editCategoryType     = $row['category_type'];
            $editPurchaseExcluded = $row['purchase_excluded'];
            $editSalesExcluded    = $row['sales_excluded'];
            $editTaxType          = $row['tax_type'];
            $editDescription      = $row['description'];
        }
    } catch (PDOException $e) {
        $error = "Error fetching category for edit: " . $e->getMessage();
    }
}

if ($_SERVER['REQUEST_METHOD'] === "POST" && isset($_POST['update-category'])) {
    try {
        $categoryId           = $_POST['updating-id'];
        $editCategoryName     = $_POST['categoryName']       ?? '';
        $editUnit             = $_POST['unit']               ?? '';
        $editCategoryType     = $_POST['categoryType']       ?? '';
        $editPurchaseExcluded = isset($_POST['purchaseExcluded']) ? 1 : 0;
        $editSalesExcluded    = isset($_POST['salesExcluded'])    ? 1 : 0;
        $editTaxType          = $_POST['taxType']            ?? '';
        $editDescription      = $_POST['description']        ?? '';

        $query = $conn->prepare("
            UPDATE itemCategory SET
                category_name     = :category_name,
                unit              = :unit,
                category_type     = :category_type,
                purchase_excluded = :purchase_excluded,
                sales_excluded    = :sales_excluded,
                tax_type          = :tax_type,
                description       = :description
            WHERE id = :id
        ");
        $query->execute([
            'category_name'     => $editCategoryName,
            'unit'              => $editUnit,
            'category_type'     => $editCategoryType,
            'purchase_excluded' => $editPurchaseExcluded,
            'sales_excluded'    => $editSalesExcluded,
            'tax_type'          => $editTaxType,
            'description'       => $editDescription,
            'id'                => $categoryId
        ]);
        header("Location: " . $_SERVER['PHP_SELF'] . "?updated=1");
        exit;
    } catch (PDOException $e) {
        $error = "Error updating category: " . $e->getMessage();
    }
}

if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST['create-category'])) {
    try {
        $query = $conn->prepare("
            INSERT INTO itemCategory
            (category_name, unit, category_type, purchase_excluded, sales_excluded, tax_type, description)
            VALUES
            (:category_name, :unit, :category_type, :purchase_excluded, :sales_excluded, :tax_type, :description)
        ");
        $query->execute([
            'category_name'     => $_POST['categoryName']   ?? '',
            'unit'              => $_POST['unit']            ?? '',
            'category_type'     => $_POST['categoryType']   ?? '',
            'purchase_excluded' => isset($_POST['purchaseExcluded']) ? 1 : 0,
            'sales_excluded'    => isset($_POST['salesExcluded'])    ? 1 : 0,
            'tax_type'          => $_POST['taxType']         ?? '',
            'description'       => $_POST['description']    ?? '',
        ]);
        header("Location: " . $_SERVER['PHP_SELF'] . "?success=1");
        exit;
    } catch (PDOException $e) {
        $error = "Error creating category: " . $e->getMessage();
    }
}

if (isset($_GET['success']))  $success = "Category created successfully!";
if (isset($_GET['updated']))  $success = "Category updated successfully!";
if (isset($_GET['deleted']))  $success = "Category deleted successfully!";

$units = [];
try {
    $query = $conn->prepare("SELECT * FROM units ORDER BY measure_name");
    $query->execute();
    $units = $query->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $error = "Error fetching units: " . $e->getMessage();
}

$categories = [];
try {
    $query = $conn->prepare("SELECT * FROM itemCategory ORDER BY category_name");
    $query->execute();
    $categories = $query->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $error = "Error fetching categories: " . $e->getMessage();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Item Categories - Think Twice</title>
  <link rel="stylesheet" href="/think-twice/public/theme.css?v=2">
</head>
<body class="page-container">

  <?php include __DIR__ . '/../navbar.php'; ?>

  <div class="page-header">
    <h1 class="page-title">Item Categories</h1>
    <p class="page-subtitle">Organize items into categories with shared attributes</p>
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
          <div class="card-title"><?= $editing ? 'Edit Category' : 'New Category' ?></div>
        </div>

        <form method="POST">
          <?php if ($editing): ?>
            <input type="hidden" name="updating-id" value="<?= $editingId ?>">
          <?php endif; ?>

          <div class="form-group">
            <label>Category Name</label>
            <input type="text" name="categoryName"
                   value="<?= htmlspecialchars($editing ? $editCategoryName : '') ?>"
                   placeholder="e.g. Beverages" required>
          </div>

          <div class="form-group">
            <label>Unit of Measure</label>
            <select name="unit" required>
              <option value="">-- Select Unit --</option>
              <?php foreach ($units as $unit): ?>
                <option value="<?= htmlspecialchars($unit['measure_name']) ?>"
                  <?= ($editing && $unit['measure_name'] === $editUnit) ? 'selected' : '' ?>>
                  <?= htmlspecialchars($unit['measure_name']) ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="form-group">
            <label>Category Type</label>
            <select name="categoryType" required>
              <option value="">-- Select Type --</option>
              <option value="Raw Material"   <?= ($editing && $editCategoryType === 'Raw Material')   ? 'selected' : '' ?>>Raw Material</option>
              <option value="Finished Good"  <?= ($editing && $editCategoryType === 'Finished Good')  ? 'selected' : '' ?>>Finished Good</option>
            </select>
          </div>

          <div class="form-group">
            <label>Tax Rate</label>
            <select name="taxType" required>
              <option value="">-- Select Tax --</option>
              <option value="VAT16"  <?= ($editing && $editTaxType === 'VAT16')  ? 'selected' : '' ?>>VAT 16%</option>
              <option value="ZERO"   <?= ($editing && $editTaxType === 'ZERO')   ? 'selected' : '' ?>>Zero Rated</option>
              <option value="EXEMPT" <?= ($editing && $editTaxType === 'EXEMPT') ? 'selected' : '' ?>>Exempt</option>
            </select>
          </div>

          <div class="form-group">
            <label style="display: flex; align-items: center; gap: 10px; text-transform: none; font-size: 14px; cursor: pointer;">
              <input type="checkbox" name="purchaseExcluded" value="1"
                     <?= ($editing && $editPurchaseExcluded) ? 'checked' : '' ?>
                     style="width: auto; margin: 0;">
              Exclude from Purchase
            </label>
          </div>

          <div class="form-group">
            <label style="display: flex; align-items: center; gap: 10px; text-transform: none; font-size: 14px; cursor: pointer;">
              <input type="checkbox" name="salesExcluded" value="1"
                     <?= ($editing && $editSalesExcluded) ? 'checked' : '' ?>
                     style="width: auto; margin: 0;">
              Exclude from Sale
            </label>
          </div>

          <div class="form-group">
            <label>Description</label>
            <textarea name="description" rows="3"
              placeholder="Optional notes about this category"><?= htmlspecialchars($editing ? $editDescription : '') ?></textarea>
          </div>

          <div class="flex gap-md">
            <button type="submit" name="<?= $editing ? 'update-category' : 'create-category' ?>"
                    class="btn btn-primary flex-1">
              <?= $editing ? 'Update' : 'Create' ?> Category
            </button>
            <?php if ($editing): ?>
              <a href="<?= $_SERVER['PHP_SELF'] ?>" class="btn btn-secondary flex-1" style="text-align:center;">Cancel</a>
            <?php endif; ?>
          </div>
        </form>
      </div>

      <!-- CATEGORIES TABLE -->
      <div class="card" style="grid-column: span 2;">
        <div class="card-header">
          <div class="card-title">All Categories (<?= count($categories) ?>)</div>
        </div>

        <?php if (empty($categories)): ?>
          <div class="text-center text-muted" style="padding: var(--space-xl);">
            No categories yet. Add one to get started.
          </div>
        <?php else: ?>
          <div style="overflow-x: auto;">
            <table class="table">
              <thead>
                <tr>
                  <th>Name</th>
                  <th>Type</th>
                  <th>Unit</th>
                  <th>Tax</th>
                  <th>Purchase</th>
                  <th>Sale</th>
                  <th>Actions</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($categories as $category): ?>
                <tr>
                  <td class="font-semibold"><?= htmlspecialchars($category['category_name']) ?></td>
                  <td>
                    <span class="badge badge-primary"><?= htmlspecialchars($category['category_type']) ?></span>
                  </td>
                  <td class="text-muted"><?= htmlspecialchars($category['unit']) ?></td>
                  <td>
                    <?php
                      $taxLabels = ['VAT16' => 'VAT 16%', 'ZERO' => 'Zero Rated', 'EXEMPT' => 'Exempt'];
                      $taxLabel  = $taxLabels[$category['tax_type']] ?? $category['tax_type'];
                    ?>
                    <span class="badge badge-warn"><?= htmlspecialchars($taxLabel) ?></span>
                  </td>
                  <td><?= $category['purchase_excluded'] ? '<span class="text-danger">Excluded</span>' : '<span class="text-muted">Included</span>' ?></td>
                  <td><?= $category['sales_excluded']    ? '<span class="text-danger">Excluded</span>' : '<span class="text-muted">Included</span>' ?></td>
                  <td>
                    <div class="flex gap-sm">
                      <a href="?editingId=<?= (int) $category['id'] ?>" class="btn btn-secondary btn-sm">Edit</a>
                      <form method="POST" style="display:inline;"
                            onsubmit="return confirm('Delete this category?')">
                        <input type="hidden" name="delete-category-id" value="<?= $category['id'] ?>">
                        <button type="submit" name="delete-category" class="btn btn-danger btn-sm">Delete</button>
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
