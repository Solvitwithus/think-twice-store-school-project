<?php
// ============================================================
//  auth_guard.php  — drop this at the TOP of every protected page
//
//  Usage:
//      require __DIR__ . '/../config/auth_guard.php';
//      // Then check a specific permission on this page:
//      requirePermission('inventory');
// ============================================================

// Do NOT call session_start() here — every page calls it before requiring this file.
// Calling it twice causes blank pages on strict PHP configs.
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// ── requireLogin() ───────────────────────────────────────────
function requireLogin(): void {
    if (empty($_SESSION['user_id'])) {
        header('Location: /think-twice');
        exit;
    }
}

// ── requirePermission($perm) ─────────────────────────────────
// ROLES BYPASSED FOR TESTING — remove the early return to re-enable.
function requirePermission(string $perm): void {
    requireLogin(); // still require the user to be logged in

    // ── TESTING MODE: role checks disabled ───────────────────
    // To re-enable, delete the next line.
    return;

    $permissions = $_SESSION['permissions'] ?? [];
    if (!in_array($perm, $permissions)) {
        header('Location: /think-twice/dashboard.php?error=unauthorized');
        exit;
    }
}

// ── hasPermission($perm) ─────────────────────────────────────
// Also bypassed during testing so all nav links stay visible.
function hasPermission(string $perm): bool {
    // ── TESTING MODE: always return true ─────────────────────
    // To re-enable, delete the next line.
    return true;

    return in_array($perm, $_SESSION['permissions'] ?? []);
}