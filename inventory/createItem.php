<?php
session_start();
require __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/authGuard.php';
requireLogin();

$error   = "";
$success = "";
$editId    = null;
$isEditing = false;

$updateItemName         = '';
$updateCode             = '';
$updateCategory         = '';
$updateUnit             = '';
$updateBuyingPrice      = 0;
$updateSellingPrice     = 0;
$updateMinStock         = 0;
$updateTaxType          = '';
$updateBarcode          = '';
$updatePurchaseExcluded = 0;
$updateSalesExcluded    = 0;
$updateDescription      = '';

$code = str_pad(random_int(0, 99999999), 8, '0', STR_PAD_LEFT);

if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST['delete-item'])) {
    try {
        $deletionId = (int) $_POST['delete-item-id'];
        $conn->prepare("DELETE FROM items WHERE id = :id")->execute(['id' => $deletionId]);
        header("Location: " . $_SERVER["PHP_SELF"] . "?deletion=1");
        exit;
    } catch (PDOException $e) {
        $error = "Error deleting item: " . $e->getMessage();
    }
}

if ($_SERVER['REQUEST_METHOD'] === "POST" && isset($_POST['Update-item'])) {
    try {
        $itemIdtoUpdate = (int) $_POST['update-id-form'];
        $query = $conn->prepare("
            UPDATE items SET
                sku_code          = :sku_code,
                item_name         = :item_name,
                category          = :category,
                unit              = :unit,
                buying_price      = :buying_price,
                selling_price     = :selling_price,
                min_stock         = :min_stock,
                tax_type          = :tax_type,
                barcode           = :barcode,
                purchase_excluded = :purchase_excluded,
                sales_excluded    = :sales_excluded,
                description       = :description
            WHERE id = :id
        ");
        $query->execute([
            'id'               => $itemIdtoUpdate,
            'sku_code'         => $_POST['sku_code'],
            'item_name'        => $_POST['itemName']          ?? '',
            'category'         => $_POST['category']          ?? '',
            'unit'             => $_POST['unit']              ?? '',
            'buying_price'     => $_POST['buyingPrice']       ?? 0,
            'selling_price'    => $_POST['sellingPrice']      ?? 0,
            'min_stock'        => $_POST['minStock']          ?? 0,
            'tax_type'         => $_POST['taxType']           ?? '',
            'barcode'          => $_POST['barcode']           ?? '',
            'purchase_excluded'=> isset($_POST['purchaseExcluded']) ? 1 : 0,
            'sales_excluded'   => isset($_POST['salesExcluded'])    ? 1 : 0,
            'description'      => $_POST['description']       ?? '',
        ]);
        header("Location: " . $_SERVER['PHP_SELF'] . "?updated=1");
        exit;
    } catch (PDOException $e) {
        $error = "Update Error: " . $e->getMessage();
    }
}

if ($_SERVER["REQUEST_METHOD"] === "GET" && isset($_GET['editId'])) {
    $editId = (int) $_GET['editId'];
    try {
        $query = $conn->prepare("SELECT * FROM items WHERE id = :id");
        $query->execute(['id' => $editId]);
        $row = $query->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            $isEditing              = true;
            $updateItemName         = $row['item_name'];
            $updateCode             = $row['sku_code'];
            $updateCategory         = $row['category'];
            $updateUnit             = $row['unit'];
            $updateBuyingPrice      = $row['buying_price'];
            $updateSellingPrice     = $row['selling_price'];
            $updateMinStock         = $row['min_stock'];
            $updateTaxType          = $row['tax_type'];
            $updateBarcode          = $row['barcode'];
            $updatePurchaseExcluded = $row['purchase_excluded'];
            $updateSalesExcluded    = $row['sales_excluded'];
            $updateDescription      = $row['description'];
        }
    } catch (PDOException $e) {
        $error = "Error loading item for edit: " . $e->getMessage();
    }
}

if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST['create-item'])) {
    try {
        $query = $conn->prepare("
            INSERT INTO items
            (sku_code, item_name, category, unit, buying_price, selling_price,
             min_stock, tax_type, barcode, purchase_excluded, sales_excluded, description)
            VALUES
            (:sku_code, :item_name, :category, :unit, :buying_price, :selling_price,
             :min_stock, :tax_type, :barcode, :purchase_excluded, :sales_excluded, :description)
        ");
        $query->execute([
            'sku_code'         => $code,
            'item_name'        => $_POST['itemName']    ?? '',
            'category'         => $_POST['category']    ?? '',
            'unit'             => $_POST['unit']        ?? '',
            'buying_price'     => $_POST['buyingPrice'] ?? 0,
            'selling_price'    => $_POST['sellingPrice']?? 0,
            'min_stock'        => $_POST['minStock']    ?? 0,
            'tax_type'         => $_POST['taxType']     ?? '',
            'barcode'          => $_POST['barcode']     ?? '',
            'purchase_excluded'=> isset($_POST['purchaseExcluded']) ? 1 : 0,
            'sales_excluded'   => isset($_POST['salesExcluded'])    ? 1 : 0,
            'description'      => $_POST['description'] ?? '',
        ]);
        header("Location: " . $_SERVER['PHP_SELF'] . "?success=1");
        exit;
    } catch (PDOException $e) {
        $error = "Error creating item: " . $e->getMessage();
    }
}

if (isset($_GET['success']))  $success = "Item created successfully!";
if (isset($_GET['updated']))  $success = "Item updated successfully!";
if (isset($_GET['deletion'])) $success = "Item deleted successfully!";

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

$items = [];
try {
    $query = $conn->prepare("SELECT * FROM items ORDER BY item_name");
    $query->execute();
    $items = $query->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $error = "Error fetching items: " . $e->getMessage();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Items Management - Think Twice</title>
  <link rel="stylesheet" href="/think-twice/public/theme.css?v=2">
</head>
<body class="page-container">

  <?php include __DIR__ . '/../navbar.php'; ?>

  <div class="page-header">
    <h1 class="page-title"><?= $isEditing ? 'Edit Item' : 'Create Item' ?></h1>
    <p class="page-subtitle">
      <?= $isEditing ? 'Update existing item details' : 'Add new inventory items with full details' ?>
    </p>
  </div>

  <div class="page-content">

    <?php if ($error): ?>
      <div class="alert alert-danger">⚠ <?= htmlspecialchars($error) ?></div>
    <?php endif; ?>
    <?php if ($success): ?>
      <div class="alert alert-success">✓ <?= htmlspecialchars($success) ?></div>
    <?php endif; ?>

    <div class="grid grid-3 gap-lg mb-lg">

      <!-- FORM -->
      <div class="card" style="grid-column: span 1;">
        <div class="card-header">
          <div class="card-title"><?= $isEditing ? 'Edit Item' : 'New Item' ?></div>
        </div>

        <form method="POST">
          <?php if ($isEditing): ?>
            <input type="hidden" name="update-id-form" value="<?= $editId ?>">
          <?php endif; ?>

          <div class="form-group">
            <label>Item Name</label>
            <input type="text" name="itemName" required
                   value="<?= htmlspecialchars($isEditing ? $updateItemName : '') ?>"
                   placeholder="e.g. Blue Polo Shirt">
          </div>

          <div class="form-group">
            <label>SKU Code</label>
            <input type="text" name="sku_code"
                   value="<?= htmlspecialchars($isEditing ? $updateCode : $code) ?>"
                   readonly style="opacity: 0.6; cursor: not-allowed;">
          </div>

          <div class="form-group">
            <label>Category</label>
            <select name="category" required>
              <option value="">-- Select Category --</option>
              <?php foreach ($categories as $cat): ?>
                <option value="<?= htmlspecialchars($cat['category_name']) ?>"
                  <?= ($isEditing && $cat['category_name'] === $updateCategory) ? 'selected' : '' ?>>
                  <?= htmlspecialchars($cat['category_name']) ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="form-group">
            <label>Unit of Measure</label>
            <select name="unit" required>
              <option value="">-- Select Unit --</option>
              <?php foreach ($units as $u): ?>
                <option value="<?= htmlspecialchars($u['measure_name']) ?>"
                  <?= ($isEditing && $u['measure_name'] === $updateUnit) ? 'selected' : '' ?>>
                  <?= htmlspecialchars($u['measure_name']) ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="grid grid-2 gap-md">
            <div class="form-group">
              <label>Buying Price (KES)</label>
              <input type="number" step="0.01" name="buyingPrice"
                     value="<?= htmlspecialchars($isEditing ? $updateBuyingPrice : '') ?>"
                     placeholder="0.00">
            </div>
            <div class="form-group">
              <label>Selling Price (KES)</label>
              <input type="number" step="0.01" name="sellingPrice" required
                     value="<?= htmlspecialchars($isEditing ? $updateSellingPrice : '') ?>"
                     placeholder="0.00">
            </div>
          </div>

          <div class="form-group">
            <label>Minimum Stock Level</label>
            <input type="number" name="minStock"
                   value="<?= htmlspecialchars($isEditing ? $updateMinStock : '') ?>"
                   placeholder="Alert threshold">
          </div>

          <div class="form-group">
            <label>Tax Type</label>
            <select name="taxType">
              <option value="">-- Select Tax --</option>
              <option value="VAT16"  <?= ($isEditing && $updateTaxType === 'VAT16')  ? 'selected' : '' ?>>VAT 16%</option>
              <option value="ZERO"   <?= ($isEditing && $updateTaxType === 'ZERO')   ? 'selected' : '' ?>>Zero Rated</option>
              <option value="EXEMPT" <?= ($isEditing && $updateTaxType === 'EXEMPT') ? 'selected' : '' ?>>Exempt</option>
            </select>
          </div>

          <div class="form-group">
            <label>Barcode</label>
            <input type="text" name="barcode"
                   value="<?= htmlspecialchars($isEditing ? $updateBarcode : '') ?>"
                   placeholder="Scan or enter barcode">
          </div>

          <div class="form-group">
            <label style="display:flex; align-items:center; gap:10px; text-transform:none; font-size:14px; cursor:pointer;">
              <input type="checkbox" name="purchaseExcluded" value="1"
                     <?= ($isEditing && $updatePurchaseExcluded) ? 'checked' : '' ?>
                     style="width:auto; margin:0;">
              Exclude from Purchase
            </label>
          </div>

          <div class="form-group">
            <label style="display:flex; align-items:center; gap:10px; text-transform:none; font-size:14px; cursor:pointer;">
              <input type="checkbox" name="salesExcluded" value="1"
                     <?= ($isEditing && $updateSalesExcluded) ? 'checked' : '' ?>
                     style="width:auto; margin:0;">
              Exclude from Sale
            </label>
          </div>

          <div class="form-group">
            <label>Description</label>
            <textarea name="description" rows="3"
              placeholder="Optional item notes"><?= htmlspecialchars($isEditing ? $updateDescription : '') ?></textarea>
          </div>

          <div class="flex gap-md">
            <?php if ($isEditing): ?>
              <button type="submit" name="Update-item" class="btn btn-primary flex-1">Save Changes</button>
              <a href="<?= $_SERVER['PHP_SELF'] ?>" class="btn btn-secondary flex-1" style="text-align:center;">Cancel</a>
            <?php else: ?>
              <button type="submit" name="create-item" class="btn btn-primary btn-block">Create Item</button>
            <?php endif; ?>
          </div>
        </form>
      </div>

      <!-- ITEMS TABLE -->
      <div class="card" style="grid-column: span 2;">
        <div class="card-header">
          <div class="card-title">All Items (<?= count($items) ?>)</div>
          <a href="/think-twice/inventory/wareHousing.php" class="btn btn-secondary btn-sm">Manage Stock</a>
        </div>

        <?php if (empty($items)): ?>
          <div class="text-center text-muted" style="padding: var(--space-xl);">
            No items yet. Create one using the form.
          </div>
        <?php else: ?>
          <div style="overflow-x: auto;">
            <table class="table">
              <thead>
                <tr>
                  <th>SKU</th>
                  <th>Item Name</th>
                  <th>Category</th>
                  <th>Unit</th>
                  <th>Buying</th>
                  <th>Selling</th>
                  <th>Min Stock</th>
                  <th>Tax</th>
                  <th>Barcode</th>
                  <th>Actions</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($items as $item):
                  $margin = $item['selling_price'] - $item['buying_price'];
                ?>
                <tr>
                  <td class="font-mono text-sm text-muted"><?= htmlspecialchars($item['sku_code']) ?></td>
                  <td class="font-semibold"><?= htmlspecialchars($item['item_name']) ?></td>
                  <td class="text-muted"><?= htmlspecialchars($item['category']) ?></td>
                  <td class="text-muted"><?= htmlspecialchars($item['unit']) ?></td>
                  <td class="font-mono text-sm">KES <?= number_format($item['buying_price'], 0) ?></td>
                  <td class="font-mono text-sm text-primary">KES <?= number_format($item['selling_price'], 0) ?></td>
                  <td class="font-mono"><?= htmlspecialchars($item['min_stock']) ?></td>
                  <td>
                    <?php if ($item['tax_type']): ?>
                      <span class="badge badge-warn"><?= htmlspecialchars($item['tax_type']) ?></span>
                    <?php endif; ?>
                  </td>
                  <td class="font-mono text-sm text-muted"><?= htmlspecialchars($item['barcode'] ?? '—') ?></td>
                  <td>
                    <div class="flex gap-sm">
                      <a href="?editId=<?= (int) $item['id'] ?>" class="btn btn-secondary btn-sm">Edit</a>
                      <form method="POST" style="display:inline;"
                            onsubmit="return confirm('Delete <?= htmlspecialchars(addslashes($item['item_name'])) ?>?')">
                        <input type="hidden" name="delete-item-id" value="<?= (int) $item['id'] ?>">
                        <button type="submit" name="delete-item" class="btn btn-danger btn-sm">Del</button>
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
