<?php
require __DIR__ . '/../config/db.php';

$error   = "";
$success = "";
$editId  = null;
$isEditing = false;

// Default values for form fields (used when editing)
$updateItemName        = '';
$updateCode            = '';
$updateCategory        = '';
$updateUnit            = '';
$updateBuyingPrice     = 0;
$updateSellingPrice    = 0;
$updateMinStock        = 0;
$updateTaxType         = '';
$updateBarcode         = '';
$updatePurchaseExcluded = 0;
$updateSalesExcluded   = 0;
$updateDescription     = '';

// Generate SKU for new items
$code = str_pad(random_int(0, 99999999), 8, '0', STR_PAD_LEFT);


// ── DELETE ──────────────────────────────────────────────
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

// ── UPDATE ───────────────────────────────────────────────
// FIXED: reads from $_POST not old $update* variables
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

        // FIXED: reading from $_POST, not stale $update* variables
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

        // FIXED: added redirect + exit after update
        header("Location: " . $_SERVER['PHP_SELF'] . "?updated=1");
        exit;

    } catch (PDOException $e) {
        $error = "Update Error: " . $e->getMessage();
    }
}

// ── LOAD ROW INTO FORM FOR EDITING ───────────────────────
if ($_SERVER["REQUEST_METHOD"] === "GET" && isset($_GET['editId'])) {
    $editId = (int) $_GET['editId'];
    try {
        $query = $conn->prepare("SELECT * FROM items WHERE id = :id");
        $query->execute(['id' => $editId]);
        $row = $query->fetch(PDO::FETCH_ASSOC);

        if ($row) {
            $isEditing = true;
            $updateItemName         = $row['item_name'];
            $updateCode             = $row['sku_code'];
            $updateCategory         = $row['category'];
            $updateUnit             = $row['unit'];
            // FIXED: added $ before row, and used correct underscore column names
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

// ── CREATE ───────────────────────────────────────────────
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
            // FIXED: checkboxes use isset() — if unchecked they don't appear in $_POST at all
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

// ── FEEDBACK MESSAGES ────────────────────────────────────
// FIXED: added updated and deletion messages
if (isset($_GET['success']))  $success = "Item created successfully!";
if (isset($_GET['updated']))  $success = "Item updated successfully!";
if (isset($_GET['deletion'])) $success = "Item deleted.";

// ── FETCH UNITS ──────────────────────────────────────────
$units = [];
try {
    $query = $conn->prepare("SELECT * FROM units ORDER BY measure_name");
    $query->execute();
    $units = $query->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $error = "Error fetching units: " . $e->getMessage();
}

// ── FETCH CATEGORIES ─────────────────────────────────────
$categories = [];
try {
    $query = $conn->prepare("SELECT * FROM itemCategory ORDER BY category_name");
    $query->execute();
    $categories = $query->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $error = "Error fetching categories: " . $e->getMessage();
}

// ── FETCH ALL ITEMS ───────────────────────────────────────
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
    <link rel="stylesheet" href="/think-twice/styles.css">
    <title>Create Item</title>
    <style>
        body { font-family: Arial, sans-serif; padding: 20px; }
        .form-container { max-width: 500px; display: flex; flex-direction: column; gap: 10px; }
        .form-container input,
        .form-container select,
        .form-container textarea { padding: 8px; font-size: 14px; }
        .form-container button { padding: 10px; background: black; color: white; border: none; cursor: pointer; }
        .msg-success { color: green; }
        .msg-error   { color: red; }
    </style>
</head>
<body>
<?php include __DIR__ . '/../navbar.php'; ?>

<?php if ($success): ?><p class="msg-success"><?= htmlspecialchars($success) ?></p><?php endif; ?>
<?php if ($error):   ?><p class="msg-error"><?= htmlspecialchars($error) ?></p><?php endif; ?>

<form method="POST" class="form-container">

    <?php if ($isEditing): ?>
        <!-- Carries the ID to the update handler -->
        <input type="hidden" name="update-id-form" value="<?= $editId ?>">
    <?php endif; ?>

    <label>Item Name</label>
    <input type="text" name="itemName" required
           value="<?= htmlspecialchars($isEditing ? $updateItemName : '') ?>">

    <label>SKU Code</label>
    <!-- FIXED: value uses ternary properly, hidden field sends sku to POST -->
    <input type="text" name="sku_code"
           value="<?= htmlspecialchars($isEditing ? $updateCode : $code) ?>" readonly>

    <label>Category</label>
    <select name="category" required>
        <option value="">-- Select Category --</option>
        <?php foreach ($categories as $cat): ?>
            <!-- FIXED: selected check is on each option inside the loop -->
            <option value="<?= htmlspecialchars($cat['category_name']) ?>"
                <?= ($isEditing && $cat['category_name'] === $updateCategory) ? 'selected' : '' ?>>
                <?= htmlspecialchars($cat['category_name']) ?>
            </option>
        <?php endforeach; ?>
    </select>

    <label>Unit</label>
    <select name="unit" required>
        <option value="">-- Select Unit --</option>
        <?php foreach ($units as $u): ?>
            <!-- FIXED: renamed loop var to $u to avoid collision with $unit scalar -->
            <option value="<?= htmlspecialchars($u['measure_name']) ?>"
                <?= ($isEditing && $u['measure_name'] === $updateUnit) ? 'selected' : '' ?>>
                <?= htmlspecialchars($u['measure_name']) ?>
            </option>
        <?php endforeach; ?>
    </select>

    <label>Buying Price</label>
    <input type="number" step="0.01" name="buyingPrice"
           value="<?= htmlspecialchars($isEditing ? $updateBuyingPrice : '') ?>">

    <label>Selling Price</label>
    <input type="number" step="0.01" name="sellingPrice" required
           value="<?= htmlspecialchars($isEditing ? $updateSellingPrice : '') ?>">

    <label>Minimum Stock</label>
    <input type="number" name="minStock"
           value="<?= htmlspecialchars($isEditing ? $updateMinStock : '') ?>">

    <label>Tax Type</label>
    <select name="taxType">
        <option value="VAT16" <?= ($isEditing && $updateTaxType === 'VAT16') ? 'selected' : '' ?>>VAT 16%</option>
        <option value="ZERO"  <?= ($isEditing && $updateTaxType === 'ZERO')  ? 'selected' : '' ?>>Zero Rated</option>
        <option value="EXEMPT"<?= ($isEditing && $updateTaxType === 'EXEMPT')? 'selected' : '' ?>>Exempt</option>
    </select>

    <label>Barcode</label>
    <input type="text" name="barcode"
           value="<?= htmlspecialchars($isEditing ? $updateBarcode : '') ?>">

    <label>
        <!-- FIXED: outputs 'checked' keyword, not the raw value -->
        <input type="checkbox" name="purchaseExcluded" value="1"
               <?= ($isEditing && $updatePurchaseExcluded) ? 'checked' : '' ?>>
        Exclude from Purchase
    </label>

    <label>
        <input type="checkbox" name="salesExcluded" value="1"
               <?= ($isEditing && $updateSalesExcluded) ? 'checked' : '' ?>>
        Exclude from Sale
    </label>

    <label>Description</label>
    <!-- FIXED: textarea content goes between tags, not in value attribute -->
    <textarea name="description"><?= htmlspecialchars($isEditing ? $updateDescription : '') ?></textarea>

    <!-- FIXED: added $ before isEditing -->
    <?php if ($isEditing): ?>
        <button type="submit" name="Update-item">Save Changes</button>
        <a href="<?= $_SERVER['PHP_SELF'] ?>">Cancel</a>
    <?php else: ?>
        <button type="submit" name="create-item">Create Item</button>
    <?php endif; ?>

</form>

<table cellpadding="8" cellspacing="0" border="1">
    <thead>
        <tr>
            <th>SKU</th>
            <th>Item Name</th>
            <th>Category</th>
            <th>Unit</th>
            <th>Buying Price</th>
            <th>Selling Price</th>
            <th>Min Stock</th>
            <th>Tax</th>
            <th>Barcode</th>
            <th>Excl. Purchase</th>
            <th>Excl. Sales</th>
            <th>Status</th>
            <th>Created At</th>
            <th>Actions</th>
        </tr>
    </thead>
    <tbody>
        <?php foreach ($items as $item): ?>
        <tr>
            <td><?= htmlspecialchars($item['sku_code']) ?></td>
            <td><?= htmlspecialchars($item['item_name']) ?></td>
            <td><?= htmlspecialchars($item['category']) ?></td>
            <td><?= htmlspecialchars($item['unit']) ?></td>
            <td><?= htmlspecialchars($item['buying_price']) ?></td>
            <td><?= htmlspecialchars($item['selling_price']) ?></td>
            <td><?= htmlspecialchars($item['min_stock']) ?></td>
            <td><?= htmlspecialchars($item['tax_type']) ?></td>
            <td><?= htmlspecialchars($item['barcode']) ?></td>
            <td><?= $item['purchase_excluded'] ? 'Yes' : 'No' ?></td>
            <td><?= $item['sales_excluded']    ? 'Yes' : 'No' ?></td>
            <td><?= htmlspecialchars($item['status']) ?></td>
            <td><?= htmlspecialchars($item['created_at']) ?></td>
            <td>
                <a href="<?= $_SERVER['PHP_SELF'] ?>?editId=<?= (int) $item['id'] ?>">Edit</a>
                <form method="POST" style="display:inline;">
                    <input type="hidden" name="delete-item-id" value="<?= (int) $item['id'] ?>">
                    <button type="submit" name="delete-item"
                            onclick="return confirm('Delete <?= htmlspecialchars(addslashes($item['item_name'])) ?>?')">
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