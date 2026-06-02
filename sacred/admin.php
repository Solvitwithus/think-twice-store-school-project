<?php
session_start();
require __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/authGuard.php';

requireLogin();

$success = '';
$error = '';

// Create role
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_role'])) {
    $roleName = strtolower(trim($_POST['role_name'] ?? ''));
    $perms = $_POST['permissions'] ?? [];

    if (!$roleName || empty($perms)) {
        $error = 'Role name and at least one permission are required.';
    } else {
        try {
            $stmt = $conn->prepare("INSERT INTO roles (name, permissions) VALUES (:name, :perms)");
            $stmt->execute([
                'name'  => $roleName,
                'perms' => json_encode($perms)
            ]);
            $success = "Role '$roleName' created successfully.";
        } catch (PDOException $e) {
            $error = $e->getCode() == 23000
                ? "Role '$roleName' already exists."
                : $e->getMessage();
        }
    }
}

// Assign role
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['assign_role'])) {
    $userId = $_POST['user_id'] ?? '';
    $roleId = $_POST['role_id'] ?? '';

    if ($userId && $roleId) {
        try {
            $stmt = $conn->prepare("UPDATE users SET role_id = :role_id WHERE id = :id");
            $stmt->execute(['role_id' => $roleId, 'id' => $userId]);
            $success = 'Role assigned successfully.';
        } catch (PDOException $e) {
            $error = $e->getMessage();
        }
    }
}

$roles = $conn->query("SELECT * FROM roles ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
$users = $conn->query("
    SELECT u.id, u.name, u.username, u.email, r.name AS role_name, u.role_id
    FROM users u
    LEFT JOIN roles r ON u.role_id = r.id
    ORDER BY u.name
")->fetchAll(PDO::FETCH_ASSOC);

$allPermissions = ['pos', 'inventory', 'suppliers', 'reports', 'roles'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Role Management - Think Twice</title>
  <link rel="stylesheet" href="/think-twice/public/theme.css">
  <style>
    .perm-item { display: flex; align-items: center; gap: 8px; font-size: 13px; background: var(--surface-hover); padding: 10px; border-radius: 6px; cursor: pointer; margin-bottom: 6px; }
    .perm-item input { width: auto; margin: 0; }
    .role-card { background: var(--surface-hover); border-radius: 8px; padding: 14px; margin-bottom: 12px; border: 1px solid var(--border); }
    .role-name { font-weight: 600; font-size: 14px; text-transform: capitalize; margin-bottom: 6px; }
    .role-perms { display: flex; flex-wrap: wrap; gap: 6px; }
    .perm-badge { background: rgba(0, 229, 160, 0.15); color: var(--primary); font-size: 11px; padding: 4px 10px; border-radius: 12px; }
    .assign-form { display: flex; align-items: center; gap: 8px; }
    select { padding: 8px 12px; border: 1px solid var(--border); background: var(--bg); color: var(--text); border-radius: 6px; }
  </style>
</head>
<body class="page-container">
  
  <?php include __DIR__ . '/../navbar.php'; ?>

  <div class="page-header">
    <h1 class="page-title">⚙️ Role Management</h1>
    <p class="page-subtitle">Create roles and assign permissions to users</p>
  </div>

  <div class="page-content">

    <?php if ($success): ?>
      <div class="alert alert-success">✓ <?= htmlspecialchars($success) ?></div>
    <?php endif; ?>

    <?php if ($error): ?>
      <div class="alert alert-danger">⚠ <?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <div class="grid grid-2 gap-lg mb-lg">

      <!-- CREATE ROLE FORM -->
      <div class="card">
        <div class="card-header">
          <div class="card-title">Create New Role</div>
        </div>
        
        <form method="POST" class="card-body">
          <div class="form-group">
            <label>Role Name</label>
            <input type="text" name="role_name" placeholder="e.g. manager" required>
          </div>

          <label style="margin-bottom: 12px; font-weight: 600;">Permissions</label>
          
          <?php foreach ($allPermissions as $perm): ?>
            <label class="perm-item">
              <input type="checkbox" name="permissions[]" value="<?= $perm ?>">
              <?= ucfirst($perm) ?>
            </label>
          <?php endforeach; ?>

          <button type="submit" name="create_role" class="btn btn-primary btn-block mt">Create Role</button>
        </form>
      </div>

      <!-- EXISTING ROLES -->
      <div class="card">
        <div class="card-header">
          <div class="card-title">Existing Roles (<?= count($roles) ?>)</div>
        </div>
        
        <div class="card-body">
          <?php if (empty($roles)): ?>
            <p class="text-muted text-center">No roles created yet</p>
          <?php else: ?>
            <?php foreach ($roles as $role): ?>
              <div class="role-card">
                <div class="role-name"><?= htmlspecialchars($role['name']) ?></div>
                <div class="role-perms">
                  <?php foreach (json_decode($role['permissions'], true) as $perm): ?>
                    <span class="perm-badge"><?= ucfirst($perm) ?></span>
                  <?php endforeach; ?>
                </div>
              </div>
            <?php endforeach; ?>
          <?php endif; ?>
        </div>
      </div>

    </div>

    <!-- USERS TABLE -->
    <div class="card">
      <div class="card-header">
        <div class="card-title">Assign Roles to Users</div>
      </div>
      
      <div class="card-body" style="overflow-x: auto;">
        <table class="table">
          <thead>
            <tr>
              <th>Name</th>
              <th>Username</th>
              <th>Email</th>
              <th>Current Role</th>
              <th>Assign New Role</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($users as $user): ?>
              <tr>
                <td><?= htmlspecialchars($user['name']) ?></td>
                <td class="font-mono"><?= htmlspecialchars($user['username']) ?></td>
                <td class="text-muted"><?= htmlspecialchars($user['email']) ?></td>
                <td>
                  <span class="badge badge-primary">
                    <?= htmlspecialchars($user['role_name'] ?? 'No role') ?>
                  </span>
                </td>
                <td>
                  <form method="POST" class="assign-form">
                    <input type="hidden" name="user_id" value="<?= $user['id'] ?>">
                    <select name="role_id" required>
                      <?php foreach ($roles as $role): ?>
                        <option value="<?= $role['id'] ?>" <?= $role['id'] == $user['role_id'] ? 'selected' : '' ?>>
                          <?= ucfirst(htmlspecialchars($role['name'])) ?>
                        </option>
                      <?php endforeach; ?>
                    </select>
                    <button type="submit" name="assign_role" class="btn btn-secondary btn-sm">Save</button>
                  </form>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>

  </div>

</body>
</html>
