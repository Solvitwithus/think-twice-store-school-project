<?php 
require __DIR__ . '/../config/db.php';

$error = '';
$success = '';

// ===================== HANDLE FORM =====================
if($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST['update-stock'])){

    $item_id = $_POST['itemSelectedId'] ?? null;
    $quantity = (int)($_POST['quantity'] ?? 0);
    $movementType = $_POST['movementType'] ?? null;

    // checkboxes
    $isIroned = isset($_POST['isIroned']) ? 1 : 0;
    $isSteamed = isset($_POST['isSteamed']) ? 1 : 0;
    $isHanged = isset($_POST['isHanged']) ? 1 : 0;

    // validation
    if(!$item_id || !$movementType || $quantity <= 0){
        $error = "All fields are required";
    } else {

        $allowed = ['IN','OUT','ADJUSTMENT'];
        if(!in_array($movementType, $allowed)){
            $error = "Invalid movement type";
        } else {

            try{
                $conn->beginTransaction();

                // 🔹 validate item exists
                $stmt = $conn->prepare("SELECT id FROM items WHERE id = :id");
                $stmt->execute(['id' => $item_id]);

                if(!$stmt->fetch()){
                    throw new Exception("Item not found");
                }

                // 🔹 get current stock from stock_movements
                $stmt = $conn->prepare("
                    SELECT 
                        SUM(
                            CASE 
                                WHEN movement_type = 'IN' THEN quantity
                                WHEN movement_type = 'OUT' THEN -quantity
                                ELSE 0
                            END
                        ) as stock
                    FROM stock_movements
                    WHERE item_id = :id
                ");
                $stmt->execute(['id' => $item_id]);
                $result = $stmt->fetch(PDO::FETCH_ASSOC);

                $currentQty = (int)($result['stock'] ?? 0);

                // 🔹 validate for OUT
                if($movementType === "OUT" && $quantity > $currentQty){
                    throw new Exception("Not enough stock");
                }

                // 🔹 handle ADJUSTMENT (optional: insert as difference)
                if($movementType === "ADJUSTMENT"){
                    $quantity = $quantity - $currentQty; // store difference
                    if($quantity === 0){
                        throw new Exception("Adjustment equals current stock, nothing to do");
                    }
                    $movementType = $quantity > 0 ? "IN" : "OUT";
                    $quantity = abs($quantity);
                }

                // 🔹 insert into stock_movements
                $insert = $conn->prepare("
                    INSERT INTO stock_movements 
                    (item_id, quantity, movement_type, is_ironed, is_steamed, is_hanged)
                    VALUES (:item_id, :qty, :type, :ironed, :steamed, :hanged)
                ");

                $insert->execute([
                    'item_id' => $item_id,
                    'qty' => $quantity,
                    'type' => $movementType,
                    'ironed' => $isIroned,
                    'steamed' => $isSteamed,
                    'hanged' => $isHanged
                ]);

                $conn->commit();
                $success = "Stock updated successfully";

            } catch(Exception $e){
                $conn->rollBack();
                $error = $e->getMessage();
            }
        }
    }
}

// ===================== FETCH ITEMS =====================
$items = [];

try{
    $query = $conn->prepare("SELECT * FROM items ORDER BY item_name");
    $query->execute();
    $items = $query->fetchAll(PDO::FETCH_ASSOC);
}catch(PDOException $e){
    $error = "Error fetching items";
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Stock Movement</title>
</head>
<body>

<h2>Warehouse Stock Management</h2>

<?php if($error): ?>
    <p style="color:red;"><?= htmlspecialchars($error) ?></p>
<?php endif; ?>

<?php if($success): ?>
    <p style="color:green;"><?= htmlspecialchars($success) ?></p>
<?php endif; ?>

<form method="POST">

    <!-- ITEM -->
    <label>Item</label>
    <select name="itemSelectedId" required>
        <option value="">-- Select Item --</option>
        <?php foreach($items as $item ): ?>
            <option value="<?= $item['id'] ?>">
                <?= htmlspecialchars($item['item_name']) ?>
            </option>
        <?php endforeach; ?>
    </select>

    <br><br>

    <!-- QUANTITY -->
    <label>Quantity</label>
    <input type="number" name="quantity" min="1" required>

    <br><br>

    <!-- MOVEMENT TYPE -->
    <label>Movement Type</label>
    <select name="movementType" required>
        <option value="">-- Select --</option>
        <option value="IN">Stock In</option>
        <option value="OUT">Stock Out</option>
        <option value="ADJUSTMENT">Adjustment</option>
    </select>

    <br><br>

    <!-- ATTRIBUTES -->
    <label>
        <input type="checkbox" name="isIroned" value="1"> Ironed
    </label>

    <label>
        <input type="checkbox" name="isSteamed" value="1"> Steamed
    </label>

    <label>
        <input type="checkbox" name="isHanged" value="1"> Hanged
    </label>

    <br><br>

    <button type="submit" name="update-stock">Update Stock</button>

</form>

</body>
</html>


<!-- remove duplicate in the table and resolve the selected item barcode and name to put in the item_movement table 
current structure
d
item_id
quantity
movement_type
is_ironed
is_steamed
is_hanged
created_at

Edit Edit
Copy Copy
Delete Delete
1
2
100
IN
1
1
1
2026-03-26 21:48:56

Edit Edit
Copy Copy
Delete Delete
2
2
100
IN
1
1
1
2026-03-26 21:49:56

-->