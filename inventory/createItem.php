<?php
require __DIR__ . '/../config/db.php';

$error = "";
$success = "";

// Generate SKU (8-digit)
$code = str_pad(random_int(0, 99999999), 8, '0', STR_PAD_LEFT);

// Handle POST
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST['create-item'])) {
    try {

        $itemName = $_POST['itemName'] ?? '';
        $category = $_POST['category'] ?? '';
        $unit = $_POST['unit'] ?? '';
        $buyingPrice = $_POST['buyingPrice'] ?? 0;
        $sellingPrice = $_POST['sellingPrice'] ?? 0;
        $stockQuantity = $_POST['stockQuantity'] ?? 0;
        $minStock = $_POST['minStock'] ?? 0;
        $taxType = $_POST['taxType'] ?? '';
        $barcode = $_POST['barcode'] ?? '';
        $purchaseExcluded = $_POST['purchaseExcluded'] ?? 0;
        $salesExcluded = $_POST['salesExcluded'] ?? 0;
        $description = $_POST['description'] ?? '';

        $query = $conn->prepare("
            INSERT INTO items 
            (sku_code, item_name, category, unit, buying_price, selling_price, stock_quantity, min_stock, tax_type, barcode, purchase_excluded, sales_excluded, description)
            VALUES 
            (:sku_code, :item_name, :category, :unit, :buying_price, :selling_price, :stock_quantity, :min_stock, :tax_type, :barcode, :purchase_excluded, :sales_excluded, :description)
        ");

        $query->execute([
            'sku_code' => $code,
            'item_name' => $itemName,
            'category' => $category,
            'unit' => $unit,
            'buying_price' => $buyingPrice,
            'selling_price' => $sellingPrice,
            'stock_quantity' => $stockQuantity,
            'min_stock' => $minStock,
            'tax_type' => $taxType,
            'barcode' => $barcode,
            'purchase_excluded' => $purchaseExcluded,
            'sales_excluded' => $salesExcluded,
            'description' => $description
        ]);

        header("Location: " . $_SERVER['PHP_SELF'] . "?success=1");
        exit;

    } catch (PDOException $e) {
        $error = "Error creating item: " . $e->getMessage();
    }
}

// Messages
if (isset($_GET['success'])) {
    $success = "Item created successfully!";
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Create Item</title>

<style>
body {
    font-family: Arial, sans-serif;
    padding: 20px;
}

.form-container {
    max-width: 500px;
    display: flex;
    flex-direction: column;
    gap: 10px;
}

.form-container input,
.form-container select,
.form-container textarea {
    padding: 8px;
    font-size: 14px;
}

.form-container button {
    padding: 10px;
    background: black;
    color: white;
    border: none;
    cursor: pointer;
}

.msg-success {
    color: green;
}
.msg-error {
    color: red;
}
</style>
</head>

<body>

<?php if ($success): ?>
    <p class="msg-success"><?= htmlspecialchars($success) ?></p>
<?php endif; ?>

<?php if ($error): ?>
    <p class="msg-error"><?= htmlspecialchars($error) ?></p>
<?php endif; ?>

<form method="POST" class="form-container">

    <label>Item Name</label>
    <input type="text" name="itemName" required>

    <label>SKU Code</label>
    <input type="text" value="<?= htmlspecialchars($code) ?>" readonly>

    <label>Category</label>
    <input type="text" name="category">

    <label>Unit</label>
    <input type="text" name="unit">

    <label>Buying Price</label>
    <input type="number" step="0.01" name="buyingPrice">

    <label>Selling Price</label>
    <input type="number" step="0.01" name="sellingPrice" required>

    <label>Stock Quantity</label>
    <input type="number" name="stockQuantity">

    <label>Minimum Stock</label>
    <input type="number" name="minStock">

    <label>Tax Type</label>
    <select name="taxType">
        <option value="VAT16">VAT 16%</option>
        <option value="ZERO">Zero Rated</option>
        <option value="EXEMPT">Exempt</option>
    </select>

    <label>Barcode</label>
    <input type="text" name="barcode">

    <label>
        <input type="checkbox" name="purchaseExcluded" value="1">
        Exclude from Purchase
    </label>

    <label>
        <input type="checkbox" name="salesExcluded" value="1">
        Exclude from Sale
    </label>

    <label>Description</label>
    <textarea name="description"></textarea>

    <button type="submit" name="create-item">Create Item</button>
</form>

</body>
</html>