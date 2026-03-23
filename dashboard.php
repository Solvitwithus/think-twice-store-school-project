<?php
echo "hello world"
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard</title>
    <style>

        .navigation-header{
            display:flex;
            flex-direction:row;
            

        }
        .common-link{
            text-decoration:none;
            margin-right:8px;
        }

        .common-link:hover{
            text-decoration:underline;
            color:red;

        }
         .common-link:active{
            text-decoration:underline;
            color:green;
font-weight:bold;
        }
    </style>
</head>
<body>
    <!-- this is the navigation bar -->
   <header class="navigation-header">
    <a href="/think-twice/dashboard.php" class="common-link">Dashboard</a>
    <a href="/think-twice/pos.php" class="common-link">Point of Sale</a>
     <a href="/think-twice/suppliers.php" class="common-link">Suppliers</a>
     <a href="/think-twice/reports.php" class="common-link">Reports</a>
<a href="/think-twice/itemsandInventory.php" class="common-link">Inventory</a>
<a href="/think-twice/sacred/admin.php" class="common-link">Admin-panel</a>
   </header>


    <p>this is the fucking dashboard</p>
   
</body>
</html>