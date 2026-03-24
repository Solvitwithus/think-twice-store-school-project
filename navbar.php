<header class="navigation-header">
    <a href="/think-twice/dashboard.php" class="common-link">Dashboard</a>
    <a href="/think-twice/pos.php" class="common-link">Point of Sale</a>
    <a href="/think-twice/suppliers.php" class="common-link">Suppliers</a>
    <a href="/think-twice/reports.php" class="common-link">Reports</a>
    <a href="/think-twice/itemsandInventory.php" class="common-link">Inventory</a>
    <a href="/think-twice/sacred/admin.php" class="common-link">Admin-panel</a>
</header>

<script>
    const path = window.location.pathname;

    document.querySelectorAll('.common-link').forEach(link => {
        const href = link.getAttribute('href');

        // Inventory link: active on the main page OR anything inside /inventory/
        if (href === '/think-twice/itemsandInventory.php') {
            if (path === href || path.startsWith('/think-twice/inventory/')) {
                link.classList.add('current');
            }

        // All other links: exact match only
        } else if (path === href) {
            link.classList.add('current');
        }
    });
</script>