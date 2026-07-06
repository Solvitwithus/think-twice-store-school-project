<?php
session_start();
require __DIR__ . '/config/authGuard.php';
requireLogin();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Inventory Management - Think Twice</title>
  <link rel="stylesheet" href="/think-twice/public/theme.css?v=2">
</head>
<body class="page-container">

  <?php include 'navbar.php'; ?>

  <div class="page-header">
    <h1 class="page-title">Inventory Management</h1>
    <p class="page-subtitle">Organize your stock, items, and suppliers</p>
  </div>

  <div class="page-content">

    <div class="grid grid-2 gap-lg">

      <!-- ITEM MANAGEMENT SECTION -->
      <div class="card">
        <div class="card-header">
          <div class="card-title">Item Management</div>
        </div>
        <div class="card-body" style="display: flex; flex-direction: column; gap: 12px;">
          <a href="/think-twice/inventory/createItem.php" class="btn btn-primary btn-block">Create New Item</a>
          <a href="/think-twice/inventory/viewSuppliers.php" class="btn btn-secondary btn-block">View Items</a>
          <a href="/think-twice/inventory/unitofMeasure.php" class="btn btn-secondary btn-block">Units of Measure</a>
        </div>
      </div>

      <!-- CATEGORY MANAGEMENT SECTION -->
      <div class="card">
        <div class="card-header">
          <div class="card-title">Category Management</div>
        </div>
        <div class="card-body" style="display: flex; flex-direction: column; gap: 12px;">
          <a href="/think-twice/inventory/createCategory.php" class="btn btn-primary btn-block">Create Category</a>
          <a href="/think-twice/inventory/priceSetting.php" class="btn btn-secondary btn-block">Price Setting</a>
        </div>
      </div>

      <!-- STOCK MANAGEMENT SECTION -->
      <div class="card">
        <div class="card-header">
          <div class="card-title">Stock Management</div>
        </div>
        <div class="card-body" style="display: flex; flex-direction: column; gap: 12px;">
          <a href="/think-twice/inventory/goodsReceiveNote.php" class="btn btn-primary btn-block">Goods Receive Note</a>
          <a href="/think-twice/inventory/wareHousing.php" class="btn btn-secondary btn-block">Warehousing</a>
          <a href="/think-twice/inventory/cycleManagement.php" class="btn btn-secondary btn-block">Cycle Management</a>
        </div>
      </div>

      <!-- PURCHASE ORDER SECTION -->
      <div class="card">
        <div class="card-header">
          <div class="card-title">Purchase Orders</div>
        </div>
        <div class="card-body" style="display: flex; flex-direction: column; gap: 12px;">
          <a href="/think-twice/inventory/orderEntry.php" class="btn btn-primary btn-block">Create Requisition</a>
          <a href="/think-twice/inventory/orderEntryApproval.php" class="btn btn-secondary btn-block">Approve Requisition</a>
        </div>
      </div>

    </div>

  </div>

</body>
</html>
