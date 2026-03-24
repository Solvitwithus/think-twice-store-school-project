<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Inventory Dashboard</title>

    <link rel="stylesheet" href="/think-twice/styles.css">
    <style>
        body {
            font-family: Arial, sans-serif;
            background-color: #f5f5f5;
            margin: 0;
            padding: 0;
        }

        h1 {
            text-align: center;
            margin-top: 20px;
            color: #333;
        }

        .inventory-links {
            display: flex;
            flex-wrap: wrap;
            justify-content: center;
            gap: 15px;
            margin: 30px auto;
            max-width: 900px;
        }

        .inventory-links a {
            display: block;
            padding: 15px 25px;
            background-color: #4CAF50;
            color: white;
            text-decoration: none;
            font-weight: bold;
            border-radius: 6px;
            transition: background-color 0.3s, transform 0.2s;
        }

        .inventory-links a:hover {
            background-color: #45a049;
            transform: scale(1.05);
        }

        .inventory-links a:active {
            background-color: #3e8e41;
        }
    </style>
</head>
<body>

<?php include 'navbar.php'; ?>

<h1>Inventory Management</h1>

<div class="inventory-links">
    <a href="/think-twice/inventory/createCategory.php">Create Category</a>
    <a href="/think-twice/inventory/createItem.php">Create Item</a>
    <a href="/think-twice/inventory/cycleManagement.php">Cycle Management</a>
    <a href="/think-twice/inventory/goodsReceiveNote.php">Goods Receive Note</a>
    <a href="/think-twice/inventory/invoicing.php">Invoicing</a>
    <a href="/think-twice/inventory/orderEntry.php">Order Entry</a>
    <a href="/think-twice/inventory/orderEntryApproval.php">Order Entry Approval</a>
    <a href="/think-twice/inventory/priceSetting.php">Price Setting</a>
    <a href="/think-twice/inventory/viewSuppliers.php">View Suppliers</a>
    <a href="/think-twice/inventory/wareHousing.php">Warehousing</a>
     <a href="/think-twice/inventory/unitofMeasure.php">Units of Measure</a>
</div>

</body>
</html>