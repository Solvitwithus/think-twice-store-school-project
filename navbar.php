<header class="navigation-header">
    <a href="/think-twice/dashboard.php" class="common-link">Dashboard</a>
    <a href="/think-twice/pos.php" class="common-link">Point of Sale</a>
    <a href="/think-twice/suppliers.php" class="common-link">Suppliers</a>
    <a href="/think-twice/reports.php" class="common-link">Reports</a>
    <a href="/think-twice/itemsandInventory.php" class="common-link">Inventory</a>
    <a href="/think-twice/sacred/admin.php" class="common-link">Admin-panel</a>
</header>

<script>
    document.querySelectorAll('.common-link').forEach(link => {
        if (link.getAttribute('href') === window.location.pathname) {
            link.classList.add('current');
        }
    });
</script>