<?php
// ============================================================
//  auth_guard.php  — drop this at the TOP of every protected page
//
//  Usage:
//      require __DIR__ . '/../config/auth_guard.php';
//      // Then check a specific permission on this page:
//      requirePermission('inventory');
// ============================================================

session_start();

// ── requireLogin() ───────────────────────────────────────────
// Call this on any page that just needs a logged-in user,
// regardless of their role or permissions.
function requireLogin(): void {
    if (empty($_SESSION['user_id'])) {
        header('Location: /think-twice');
        exit;
    }
}

// ── requirePermission($perm) ─────────────────────────────────
// Call this on pages that need a SPECIFIC permission.
// It automatically calls requireLogin() first, so you only
// need one line at the top of each page.
//
// Example usage:
//   requirePermission('inventory');   // only users with inventory access
//   requirePermission('roles');       // only admins managing roles
//   requirePermission('reports');     // only users allowed to see reports
function requirePermission(string $perm): void {
    requireLogin(); // redirect to login if not authenticated at all

    $permissions = $_SESSION['permissions'] ?? [];

    // in_array() checks whether $perm exists in the stored permissions array.
    // e.g. $_SESSION['permissions'] = ['pos', 'inventory']
    //      in_array('inventory', ...) → true   ✅ allowed
    //      in_array('roles',     ...) → false  ❌ redirect
    if (!in_array($perm, $permissions)) {
        // User is logged in but doesn't have the required permission.
        // Send them back to a safe page (dashboard or home).
        header('Location: /think-twice/dashboard.php?error=unauthorized');
        exit;
    }
}

// ── hasPermission($perm) ─────────────────────────────────────
// Non-redirecting helper — use this inside a page to SHOW or HIDE
// UI elements based on the user's permissions.
//
// Example:
//   <?php if (hasPermission('roles')): ?>
//     <a href="roles.php">Manage Roles</a>
//   <?php endif; ?>
function hasPermission(string $perm): bool {
    return in_array($perm, $_SESSION['permissions'] ?? []);
}