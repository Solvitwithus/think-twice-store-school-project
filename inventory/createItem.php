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
if (isset($_GET['success']))  $success = "Item created successfully!";
if (isset($_GET['updated']))  $success = "Item updated successfully!";
if (isset($_GET['deletion'])) $success = "Item deleted successfully!";

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
    <title>Items Management</title>
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
            max-width: 700px;
            margin-bottom: 40px;
        }

        label {
            display: block;
            margin: 12px 0 5px;
            font-weight: 600;
            color: #444;
        }

        input[type="text"],
        input[type="number"],
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
        }

        th {
            background-color: #2c3e50;
            color: white;
            padding: 12px 10px;
            text-align: left;
            font-size: 14px;
        }

        td {
            padding: 12px 10px;
            border-bottom: 1px solid #eee;
            font-size: 14px;
        }

        tr:hover {
            background-color: #f8f9fa;
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

        .readonly {
            background-color: #f8f9fa;
            cursor: not-allowed;
        }

        @media (max-width: 768px) {
            form, table {
                max-width: 100%;
            }
        }
    </style>
</head>
<body>

    <?php include __DIR__ . '/../navbar.php'; ?>

    <h1>Items Management</h1>

    <?php if ($success): ?>
        <p class="msg-success"><?= htmlspecialchars($success) ?></p>
    <?php endif; ?>

    <?php if ($error): ?>
        <p class="msg-error"><?= htmlspecialchars($error) ?></p>
    <?php endif; ?>

    <!-- Form -->
    <form method="POST">
        <?php if ($isEditing): ?>
            <input type="hidden" name="update-id-form" value="<?= $editId ?>">
        <?php endif; ?>

        <label>Item Name</label>
        <input type="text" name="itemName" required
               value="<?= htmlspecialchars($isEditing ? $updateItemName : '') ?>">

        <label>SKU Code</label>
        <input type="text" name="sku_code" 
               value="<?= htmlspecialchars($isEditing ? $updateCode : $code) ?>" 
               readonly class="readonly">

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

        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px;">
            <div>
                <label>Buying Price (KSh)</label>
                <input type="number" step="0.01" name="buyingPrice"
                       value="<?= htmlspecialchars($isEditing ? $updateBuyingPrice : '') ?>">
            </div>
            <div>
                <label>Selling Price (KSh)</label>
                <input type="number" step="0.01" name="sellingPrice" required
                       value="<?= htmlspecialchars($isEditing ? $updateSellingPrice : '') ?>">
            </div>
        </div>

        <label>Minimum Stock Level</label>
        <input type="number" name="minStock"
               value="<?= htmlspecialchars($isEditing ? $updateMinStock : '') ?>">

        <label>Tax Type</label>
        <select name="taxType">
            <option value="">-- Select Tax Type --</option>
            <option value="VAT16"  <?= ($isEditing && $updateTaxType === 'VAT16')  ? 'selected' : '' ?>>VAT 16%</option>
            <option value="ZERO"   <?= ($isEditing && $updateTaxType === 'ZERO')   ? 'selected' : '' ?>>Zero Rated</option>
            <option value="EXEMPT" <?= ($isEditing && $updateTaxType === 'EXEMPT') ? 'selected' : '' ?>>Exempt</option>
        </select>

        <label>Barcode</label>
        <input type="text" name="barcode"
               value="<?= htmlspecialchars($isEditing ? $updateBarcode : '') ?>">

        <label>
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
        <textarea name="description" rows="4"><?= htmlspecialchars($isEditing ? $updateDescription : '') ?></textarea>

        <?php if ($isEditing): ?>
            <button type="submit" name="Update-item">Save Changes</button>
            <a href="<?= $_SERVER['PHP_SELF'] ?>" class="cancel-link">Cancel</a>
        <?php else: ?>
            <button type="submit" name="create-item">Create Item</button>
        <?php endif; ?>
    </form>

    <!-- Items Table -->
    <h2>All Items (<?= count($items) ?>)</h2>

    <table>
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
                <th>Excl. Sale</th>
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
                <td>KSh <?= number_format($item['buying_price'], 2) ?></td>
                <td>KSh <?= number_format($item['selling_price'], 2) ?></td>
                <td><?= htmlspecialchars($item['min_stock']) ?></td>
                <td><?= htmlspecialchars($item['tax_type']) ?></td>
                <td><?= htmlspecialchars($item['barcode'] ?? '') ?></td>
                <td><?= $item['purchase_excluded'] ? 'Yes' : 'No' ?></td>
                <td><?= $item['sales_excluded']    ? 'Yes' : 'No' ?></td>
                <td class="actions">
                    <a href="<?= $_SERVER['PHP_SELF'] ?>?editId=<?= (int) $item['id'] ?>">Edit</a>
                    <form method="POST" style="display:inline;" 
                          onsubmit="return confirm('Delete <?= htmlspecialchars(addslashes($item['item_name'])) ?>?')">
                        <input type="hidden" name="delete-item-id" value="<?= (int) $item['id'] ?>">
                        <button type="submit" name="delete-item" class="delete-btn">Delete</button>
                    </form>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>

</body>
</html>