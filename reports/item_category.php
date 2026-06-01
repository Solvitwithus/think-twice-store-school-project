<?php
// 🔹 Load database connection
require __DIR__ . '/../config/db.php';

try {
    // 🔹 Prepare SQL query to fetch all item categories
    $query = $conn->prepare("SELECT * FROM itemCategory");

    // 🔹 Execute query (required for PDO)
    $query->execute();

    // 🔹 Fetch all results as associative array
    $categories = $query->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    // 🔴 Stop execution if database fails
    die("Database error: " . $e->getMessage());
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Item Categories</title>

    <!-- ================= PRINT STYLES ================= -->
    <style>

        /* 🔹 Normal screen styles (optional enhancements) */
        table {
            width: 100%;
            border-collapse: collapse;
        }

        th, td {
            padding: 8px;
            border: 1px solid #ddd;
            text-align: left;
        }

        th {
            background-color: #f4f4f4;
        }

        /* ================= PRINT MODE ================= */
        @media print {

            /* 🔹 Hide everything except table when printing */
            body * {
                visibility: hidden;
            }

            table, table * {
                visibility: visible;
            }

            /* 🔹 Position table at top for clean print */
            table {
                position: absolute;
                left: 0;
                top: 0;
                width: 100%;
                font-size: 12px;
            }

            /* 🔹 Clean borders for print */
            th, td {
                border: 1px solid #000;
                padding: 6px;
            }

            /* 🔹 Repeat table header on every printed page */
            thead {
                display: table-header-group;
            }

            /* 🔹 Prevent row splitting across pages */
            tr {
                page-break-inside: avoid;
            }

            /* 🔹 Hide elements not needed in print */
            .no-print {
                display: none !important;
            }

            /* 🔹 Page margin control */
            @page {
                margin: 10mm;
            }
        }

        /* 🔹 Hide print title on screen */
        .print-title {
            display: none;
        }

        /* 🔹 Show print title only when printing */
        @media print {
            .print-title {
                display: block;
                text-align: center;
                font-size: 18px;
                margin-bottom: 10px;
            }
        }

    </style>
</head>

<body>

<!-- ================= NAVBAR (HIDDEN IN PRINT) ================= -->
<div class="no-print">
    <?php include __DIR__ . "/../navbar.php"; ?>
</div>

<!-- ================= PRINT TITLE ================= -->
<h2 class="print-title">Item Categories Report</h2>

<!-- ================= PAGE TITLE ================= -->
<h2 class="no-print">Item Categories</h2>

<!-- ================= PRINT BUTTON ================= -->
<div class="no-print" style="margin-bottom: 10px;">
    <!-- 🔹 Triggers browser print dialog -->
    <button onclick="window.print()">Print Report</button>
</div>

<!-- ================= DATA TABLE ================= -->
<table>
    <thead>
        <tr>
            <th>ID</th>
            <th>Category Name</th>
            <th>Unit</th>
            <th>Type</th>
            <th>Purchase Excluded</th>
            <th>Sales Excluded</th>
            <th>Tax Type</th>
            <th>Description</th>
            <th>Created At</th>
        </tr>
    </thead>

    <tbody>
        <?php foreach ($categories as $category): ?>
            <tr>
                <td><?= htmlspecialchars($category['id']) ?></td>
                <td><?= htmlspecialchars($category['category_name']) ?></td>
                <td><?= htmlspecialchars($category['unit']) ?></td>
                <td><?= htmlspecialchars($category['category_type']) ?></td>
                <td><?= htmlspecialchars($category['purchase_excluded']) ?></td>
                <td><?= htmlspecialchars($category['sales_excluded']) ?></td>
                <td><?= htmlspecialchars($category['tax_type']) ?></td>
                <td><?= htmlspecialchars($category['description']) ?></td>
                <td><?= htmlspecialchars($category['created_at']) ?></td>
            </tr>
        <?php endforeach; ?>
    </tbody>
</table>

</body>
</html>