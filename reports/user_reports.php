<?php
session_start();
require __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/authGuard.php';
requireLogin();

try {
    $stmt = $conn->prepare("
        SELECT u.id, u.name, u.username, u.email, u.created_at,
               r.name AS role_name, r.permissions
        FROM users u
        LEFT JOIN roles r ON u.role_id = r.id
        ORDER BY u.name
    ");
    $stmt->execute();
    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $roleStmt = $conn->prepare("SELECT name, COUNT(*) AS user_count FROM users u LEFT JOIN roles r ON u.role_id = r.id GROUP BY r.name ORDER BY user_count DESC");
    $roleStmt->execute();
    $roleCounts = $roleStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $users      = [];
    $roleCounts = [];
    $error      = $e->getMessage();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>User Report - Think Twice</title>
  <link rel="stylesheet" href="/think-twice/public/theme.css?v=2">
  <style>
    .perm-chip { display: inline-flex; align-items: center; padding: 2px 8px; border-radius: 10px;
                 font-size: 11px; font-weight: 600; background: rgba(0,153,94,0.12); color: var(--primary);
                 margin: 2px; }
    @media print {
      .navigation-header, .no-print { display: none !important; }
      body { background: #fff; color: #000; }
    }
  </style>
</head>
<body class="page-container">

  <div class="no-print">
    <?php include __DIR__ . '/../navbar.php'; ?>
  </div>

  <div class="page-header">
    <h1 class="page-title">User Report</h1>
    <p class="page-subtitle"><?= count($users) ?> user<?= count($users) !== 1 ? 's' : '' ?> registered</p>
  </div>

  <div class="page-content">

    <?php if (!empty($error)): ?>
      <div class="alert alert-danger">⚠ <?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <!-- Role distribution -->
    <?php if (!empty($roleCounts)): ?>
    <div class="grid grid-4 mb-lg no-print">
      <?php foreach ($roleCounts as $rc): ?>
      <div class="stat-box">
        <div class="stat-value"><?= $rc['user_count'] ?></div>
        <div class="stat-label"><?= ucfirst(htmlspecialchars($rc['role_name'] ?? 'No Role')) ?></div>
      </div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <div class="flex-between mb-lg no-print">
      <a href="/think-twice/sacred/admin.php" class="btn btn-primary btn-sm">Manage Roles</a>
      <button onclick="window.print()" class="btn btn-secondary btn-sm">Print</button>
    </div>

    <div class="card">
      <div class="card-header">
        <div class="card-title">All Users</div>
        <span class="text-muted text-sm"><?= count($users) ?> accounts</span>
      </div>

      <?php if (empty($users)): ?>
        <div class="text-center text-muted" style="padding: var(--space-xl);">No users found.</div>
      <?php else: ?>
        <div style="overflow-x: auto;">
          <table class="table">
            <thead>
              <tr>
                <th>#</th>
                <th>Full Name</th>
                <th>Username</th>
                <th>Email</th>
                <th>Role</th>
                <th>Permissions</th>
                <th>Joined</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($users as $i => $u): ?>
              <tr>
                <td class="text-muted font-mono"><?= $i + 1 ?></td>
                <td class="font-semibold"><?= htmlspecialchars($u['name']) ?></td>
                <td class="font-mono text-muted"><?= htmlspecialchars($u['username']) ?></td>
                <td class="text-muted text-sm"><?= htmlspecialchars($u['email']) ?></td>
                <td>
                  <span class="badge badge-primary"><?= ucfirst(htmlspecialchars($u['role_name'] ?? 'None')) ?></span>
                </td>
                <td>
                  <?php
                    $perms = json_decode($u['permissions'] ?? '[]', true) ?? [];
                    foreach ($perms as $p):
                  ?><span class="perm-chip"><?= ucfirst(htmlspecialchars($p)) ?></span><?php endforeach; ?>
                  <?php if (empty($perms)): ?><span class="text-muted">None</span><?php endif; ?>
                </td>
                <td class="text-muted text-sm font-mono">
                  <?= isset($u['created_at']) ? date('d M Y', strtotime($u['created_at'])) : '—' ?>
                </td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    </div>

  </div>

</body>
</html>
