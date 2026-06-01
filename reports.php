<?php
$reports = [
    ["name" => "User Reports", "path" => "/think-twice/reports/user_reports.php"],
    ["name" => "Sales Report", "path" => "/think-twice/reports/sales_report.php"],
    ["name" => "Item Category", "path" => "/think-twice/reports/item_category.php"],
    ["name" => "Item Listing", "path" => "/think-twice/reports/item_listing.php"],
    ["name" => "Price Cycles", "path" => "/think-twice/reports/price_cycles.php"],
    ["name" => "Item Requisition", "path" => "/think-twice/reports/item_requisition.php"],
    ["name" => "Stock Movements", "path" => "/think-twice/reports/stock_movements.php"],
    ["name" => "Suppliers", "path" => "/think-twice/reports/suppliers.php"],
    ["name" => "Unit of Measure", "path" => "/think-twice/reports/unit_of_measure.php"]
];
?>

<!DOCTYPE html>
<html>
<head>
    <title>Reports</title>
</head>
<body>

<?php include 'navbar.php'; ?>

<h2>Reports</h2>

<div class="reports">
    <?php foreach ($reports as $report): ?>
        <a href="<?= $report['path'] ?>">
            <?= htmlspecialchars($report['name']) ?>
        </a>
    <?php endforeach; ?>
</div>

</body>
</html>