<?php

// session_start();
// if (!in_array('roles', $_SESSION['permissions'] ?? [])) {
//     header('Location: /think-twice');
//     exit;
// }
/*
 * ============================================================
 *  LESSON: How foreach works in PHP
 * ============================================================
 *  foreach is used to loop through an ARRAY — meaning it goes
 *  through each item one by one until it reaches the end.
 *
 *  Basic syntax:
 *      foreach ($array as $item) {
 *          // do something with $item
 *      }
 *
 *  Example:
 *      $fruits = ['apple', 'banana', 'mango'];
 *      foreach ($fruits as $fruit) {
 *          echo $fruit; // prints apple, then banana, then mango
 *      }
 *
 *  You can also get the KEY (index) alongside the value:
 *      foreach ($fruits as $index => $fruit) {
 *          echo $index . ': ' . $fruit;
 *          // prints: 0: apple, 1: banana, 2: mango
 *      }
 *
 *  When your array contains other arrays (like database rows),
 *  each $item is itself an array of column => value pairs:
 *      $users = [
 *          ['name' => 'John', 'email' => 'john@x.com'],
 *          ['name' => 'Jane', 'email' => 'jane@x.com'],
 *      ];
 *      foreach ($users as $user) {
 *          echo $user['name']; // John, then Jane
 *      }
 *
 *  That last example is exactly what we do below with database results.
 * ============================================================
 *
 *  LESSON: ucfirst() and htmlspecialchars()
 * ============================================================
 *  ucfirst($string)
 *      → Capitalizes the FIRST letter of a string.
 *      → ucfirst('admin')   gives  'Admin'
 *      → ucfirst('seller')  gives  'Seller'
 *      → We use it so role names look nice in the UI.
 *
 *  htmlspecialchars($string)
 *      → SAFETY FUNCTION. Converts dangerous characters into
 *        harmless HTML entities before displaying user data.
 *      → Why? If a user stored <script>alert('hacked')</script>
 *        as their name, without this function that JavaScript
 *        would actually RUN in the browser (XSS attack).
 *      → With htmlspecialchars() it becomes:
 *        &lt;script&gt;alert(&#039;hacked&#039;)&lt;/script&gt;
 *        which the browser shows as plain text — harmless.
 *      → RULE: Always wrap user-supplied data in htmlspecialchars()
 *        before echoing it into HTML.
 * ============================================================
 */

// Pull in the database connection. $conn will be available after this.
require __DIR__ . '/../config/db.php';

// Start the session so we can later read $_SESSION['permissions']
// (sessions let you remember data about the logged-in user across pages)
session_start();

// -----------------------------------------------------------------
// TODO: Protect this page — only users with the 'roles' permission
// should be allowed here. We'll add this guard once login is wired up:
//
//   if (!in_array('roles', $_SESSION['permissions'] ?? [])) {
//       header('Location: /think-twice');
//       exit;
//   }
// -----------------------------------------------------------------

// These two variables will hold feedback messages shown to the user.
// We start them as empty strings — if nothing happens, nothing shows.
$success = '';
$error = '';


// =================================================================
// HANDLE: Create a new role (form submitted with name="create_role")
// =================================================================
// $_SERVER['REQUEST_METHOD'] tells us HOW the page was requested.
// 'GET'  = user just visited the page (normal load)
// 'POST' = user submitted a form
// isset($_POST['create_role']) checks that the "Create Role" button
// was the one that triggered the submit (not the "Assign" button).
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_role'])) {

    // strtolower() makes it all lowercase  → 'Admin' becomes 'admin'
    // trim() removes accidental spaces     → ' admin ' becomes 'admin'
    // ?? '' means: if the field wasn't sent at all, use empty string
    $roleName = strtolower(trim($_POST['role_name'] ?? ''));

    // Checkboxes come back as an array e.g. ['pos', 'inventory']
    // If no checkboxes were ticked, $_POST['permissions'] won't exist,
    // so ?? [] gives us an empty array instead of an error.
    $perms = $_POST['permissions'] ?? [];

    // Validate — both fields must have a value before we touch the DB
    if (!$roleName || empty($perms)) {
        $error = 'Role name and at least one permission are required.';
    } else {
        try {
            // Prepare a parameterized query.
            // :name and :perms are placeholders — PDO replaces them
            // safely, preventing SQL injection attacks.
            $stmt = $conn->prepare("INSERT INTO roles (name, permissions) VALUES (:name, :perms)");

            $stmt->execute([
                'name'  => $roleName,
                // json_encode turns the PHP array into a JSON string
                // e.g. ['pos','inventory'] → '["pos","inventory"]'
                // This is what gets stored in the database column.
                'perms' => json_encode($perms)
            ]);

            $success = "Role '$roleName' created.";

        } catch (PDOException $e) {
            // Error code 23000 = duplicate entry (role name already exists)
            // getCode() returns the database error number
            $error = $e->getCode() == 23000
                ? "Role '$roleName' already exists."
                : $e->getMessage(); // For any other DB error, show the message
        }
    }
}


// =================================================================
// HANDLE: Assign a role to a user (form submitted with name="assign_role")
// =================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['assign_role'])) {

    // These come from hidden input + select dropdown in the users table form
    $userId = $_POST['user_id'] ?? '';
    $roleId = $_POST['role_id'] ?? '';

    // Only proceed if both values actually exist
    if ($userId && $roleId) {
        try {
            // UPDATE the users table — set this user's role_id to the chosen role
            $stmt = $conn->prepare("UPDATE users SET role_id = :role_id WHERE id = :id");
            $stmt->execute(['role_id' => $roleId, 'id' => $userId]);

            $success = 'Role assigned successfully.';

        } catch (PDOException $e) {
            $error = $e->getMessage();
        }
    }
}


// =================================================================
// FETCH DATA from the database to display on the page
// =================================================================

// Fetch all roles, sorted alphabetically by name.
// fetchAll(PDO::FETCH_ASSOC) returns an array of associative arrays:
// [
//   ['id' => 1, 'name' => 'admin',  'permissions' => '["pos","reports"]'],
//   ['id' => 2, 'name' => 'seller', 'permissions' => '["pos"]'],
// ]
$roles = $conn->query("SELECT * FROM roles ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);

// Fetch all users with their current role name using a JOIN.
// LEFT JOIN means: even if a user has no role_id, still include them
// (their role_name will just be NULL).
$users = $conn->query("
    SELECT u.id, u.name, u.username, u.email, r.name AS role_name, u.role_id
    FROM users u
    LEFT JOIN roles r ON u.role_id = r.id
    ORDER BY u.name
")->fetchAll(PDO::FETCH_ASSOC);

// This is the master list of all possible permissions in the system.
// We loop through this to generate the checkboxes in the "Create Role" form.
$allPermissions = ['pos', 'inventory', 'suppliers', 'reports', 'roles'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Role Management</title>
     <link rel="stylesheet" href="/think-twice/styles.css">
  <style>
    * { box-sizing: border-box; font-family: Arial, sans-serif; margin: 0; padding: 0; }
    body { background: #f4f6f9; padding: 30px; }
    h1 { font-size: 22px; margin-bottom: 24px; color: #333; }
    h2 { font-size: 16px; margin-bottom: 14px; color: #444; }
    .grid { display: grid; grid-template-columns: 1fr 1fr; gap: 24px; margin-bottom: 24px; }
    .card { background: #fff; border-radius: 10px; padding: 24px; box-shadow: 0 2px 10px rgba(0,0,0,0.07); }
    .alert { padding: 10px 14px; border-radius: 6px; margin-bottom: 18px; font-size: 14px; }
    .alert.success { background: #d4edda; color: #155724; }
    .alert.error   { background: #f8d7da; color: #721c24; }
    label { font-size: 13px; color: #555; display: block; margin-bottom: 4px; }
    input[type="text"] { width: 100%; padding: 9px 12px; border: 1px solid #ccc; border-radius: 6px; font-size: 14px; margin-bottom: 14px; }
    input[type="text"]:focus { border-color: #007bff; outline: none; }
    .perms-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 8px; margin-bottom: 16px; }
    .perm-item { display: flex; align-items: center; gap: 8px; font-size: 13px; color: #333; background: #f8f9fa; padding: 8px 10px; border-radius: 6px; cursor: pointer; }
    .perm-item input { width: auto; margin: 0; }
    .btn { padding: 9px 18px; background: #007bff; color: #fff; border: none; border-radius: 6px; font-size: 14px; cursor: pointer; }
    .btn:hover { background: #0056b3; }
    table { width: 100%; border-collapse: collapse; font-size: 14px; }
    thead th { text-align: left; padding: 10px 12px; background: #f1f3f5; color: #555; font-weight: 600; border-bottom: 2px solid #e9ecef; }
    tbody tr:hover { background: #f8f9fa; }
    tbody td { padding: 10px 12px; border-bottom: 1px solid #e9ecef; vertical-align: middle; }
    .badge { display: inline-block; padding: 3px 10px; border-radius: 20px; font-size: 11px; font-weight: 600; text-transform: uppercase; background: #e2e8f0; color: #555; }
    .badge.admin  { background: #fde8e8; color: #c53030; }
    .badge.seller { background: #e6f4ea; color: #276749; }
    select { padding: 6px 10px; border: 1px solid #ccc; border-radius: 6px; font-size: 13px; background: #fff; }
    .assign-form { display: flex; align-items: center; gap: 8px; }
    .roles-list { display: flex; flex-direction: column; gap: 10px; }
    .role-card { background: #f8f9fa; border-radius: 8px; padding: 12px 14px; display: flex; justify-content: space-between; align-items: center; }
    .role-card .role-name { font-weight: 600; font-size: 14px; text-transform: capitalize; }
    .role-perms { display: flex; flex-wrap: wrap; gap: 6px; margin-top: 4px; }
    .perm-tag { background: #007bff18; color: #007bff; font-size: 11px; padding: 2px 8px; border-radius: 20px; }
  </style>
</head>
<body>
<?php include 'navbar.php';?>
<h1>⚙️ Role Management</h1>

<?php
/*
 * These two blocks only render HTML if $success or $error are not empty.
 * The 'if' in PHP inside HTML works just like normal — if the condition
 * is true, everything between <?php if(): ?> and <?php endif; ?> prints.
 */
?>

<?php if ($success): ?>
  <div class="alert success"><?= htmlspecialchars($success) ?></div>
<?php endif; ?>

<?php if ($error): ?>
  <div class="alert error"><?= htmlspecialchars($error) ?></div>
<?php endif; ?>

<div class="grid">

  <!-- ===================== CREATE ROLE FORM ===================== -->
  <div class="card">
    <h2>Create New Role</h2>
    <form method="POST">
      <label>Role Name</label>
      <input type="text" name="role_name" placeholder="e.g. manager" required>

      <label style="margin-bottom: 10px;">Permissions</label>
      <div class="perms-grid">

        <?php
        /*
         * FOREACH #1 — Loop through $allPermissions
         * --------------------------------------------
         * $allPermissions = ['pos', 'inventory', 'suppliers', 'reports', 'roles']
         *
         * Each time the loop runs, $perm holds one value:
         *   1st pass: $perm = 'pos'
         *   2nd pass: $perm = 'inventory'
         *   ... and so on
         *
         * For each permission we print a checkbox so the admin can
         * tick which permissions to include in the new role.
         */
        foreach ($allPermissions as $perm): ?>

          <label class="perm-item">
            <input type="checkbox" name="permissions[]" value="<?= $perm ?>">
            <?php
            /*
             * ucfirst($perm) capitalizes the first letter for display:
             * 'pos'       → 'Pos'
             * 'inventory' → 'Inventory'
             * We don't use htmlspecialchars here because $allPermissions
             * is hardcoded by us — it's not user input so it's safe.
             */
            echo ucfirst($perm);
            ?>
          </label>

        <?php endforeach; // End of FOREACH #1 ?>

      </div>

      <!-- The name="create_role" on this button is how PHP knows
           which form was submitted (we check isset($_POST['create_role'])) -->
      <button class="btn" name="create_role">Create Role</button>
    </form>
  </div>


  <!-- ===================== EXISTING ROLES LIST ===================== -->
  <div class="card">
    <h2>Existing Roles</h2>
    <div class="roles-list">

      <?php
      /*
       * FOREACH #2 — Loop through $roles (fetched from the database)
       * --------------------------------------------------------------
       * $roles is an array of associative arrays. Each $role looks like:
       * [
       *   'id'          => 1,
       *   'name'        => 'admin',
       *   'permissions' => '["pos","reports","roles"]',  ← JSON string
       *   'created_at'  => '2026-03-22 10:00:00'
       * ]
       *
       * Each pass of the loop gives us one role to display.
       */
      foreach ($roles as $role): ?>

        <div class="role-card">
          <div>
            <!-- htmlspecialchars() protects against XSS.
                 Even though WE created these roles, it's good habit
                 to always sanitize before echoing into HTML. -->
            <div class="role-name"><?= htmlspecialchars($role['name']) ?></div>

            <div class="role-perms">
              <?php
              /*
               * FOREACH #3 — Loop through this role's permissions
               * ---------------------------------------------------
               * $role['permissions'] is stored as a JSON string in the DB:
               *   '["pos","inventory","reports"]'
               *
               * json_decode(..., true) converts it back to a PHP array:
               *   ['pos', 'inventory', 'reports']
               *
               * Then we loop through that array to print each permission
               * as a small coloured tag/badge.
               *
               * Each pass: $perm = 'pos', then 'inventory', then 'reports'
               */
              foreach (json_decode($role['permissions'], true) as $perm): ?>

                <span class="perm-tag"><?= ucfirst($perm) ?></span>

              <?php endforeach; // End of FOREACH #3 ?>
            </div>

          </div>
        </div>

      <?php endforeach; // End of FOREACH #2 ?>

    </div>
  </div>

</div><!-- end .grid -->


<!-- ===================== USERS TABLE ===================== -->
<div class="card">
  <h2>Assign Roles to Users</h2>
  <table>
    <thead>
      <tr>
        <th>Name</th>
        <th>Username</th>
        <th>Email</th>
        <th>Current Role</th>
        <th>Assign Role</th>
      </tr>
    </thead>
    <tbody>

      <?php
      /*
       * FOREACH #4 — Loop through $users (fetched from the database)
       * --------------------------------------------------------------
       * $users is an array of associative arrays. Each $user looks like:
       * [
       *   'id'        => 1,
       *   'name'      => 'Green Garden',
       *   'username'  => 'john',
       *   'email'     => 'john@digisoft.co.ke',
       *   'role_name' => 'seller',   ← came from the JOIN with roles table
       *   'role_id'   => 2
       * ]
       *
       * For each user we print one <tr> (table row).
       */
      foreach ($users as $user): ?>

        <tr>
          <!-- htmlspecialchars() on EVERY piece of user data before echoing it.
               User data comes from the database (originally typed by humans),
               so it could contain anything — always sanitize it. -->
          <td><?= htmlspecialchars($user['name']) ?></td>
          <td><?= htmlspecialchars($user['username']) ?></td>
          <td><?= htmlspecialchars($user['email']) ?></td>

          <td>
            <?php
            /*
             * The badge CSS class is set to the role name (e.g. "admin" or "seller")
             * so the stylesheet can colour it differently per role.
             * ?? 'none' means: if role_name is NULL (user has no role), show 'none'
             */
            ?>
            <span class="badge <?= htmlspecialchars($user['role_name'] ?? '') ?>">
              <?= htmlspecialchars($user['role_name'] ?? 'none') ?>
            </span>
          </td>

          <td>
            <!-- Each row has its own mini-form.
                 The hidden input carries the user's ID so PHP knows
                 WHICH user to update when the form is submitted. -->
            <form method="POST" class="assign-form">
              <input type="hidden" name="user_id" value="<?= $user['id'] ?>">

              <select name="role_id">
                <?php
                /*
                 * FOREACH #5 — Loop through $roles again to build the dropdown
                 * --------------------------------------------------------------
                 * For every role in the system, we print an <option>.
                 * The currently assigned role gets the 'selected' attribute
                 * so the dropdown shows the user's current role by default.
                 *
                 * $role['id'] == $user['role_id']  ← loose comparison (== not ===)
                 * because one might be a string and the other an integer
                 * depending on how PDO returns the value.
                 */
                foreach ($roles as $role): ?>

                  <option
                    value="<?= $role['id'] ?>"
                    <?= $role['id'] == $user['role_id'] ? 'selected' : '' ?>
                  >
                    <?= ucfirst(htmlspecialchars($role['name'])) ?>
                    <?php
                    /*
                     * ucfirst() + htmlspecialchars() chained together:
                     * 1. htmlspecialchars('admin') → 'admin'  (safe to display)
                     * 2. ucfirst('admin')          → 'Admin'  (nice capitalisation)
                     *
                     * Order matters: sanitize first, then format.
                     */
                    ?>
                  </option>

                <?php endforeach; // End of FOREACH #5 ?>
              </select>

              <!-- name="assign_role" tells PHP this is the assign form,
                   not the create-role form -->
              <button class="btn" name="assign_role">Save</button>
            </form>
          </td>

        </tr>

      <?php endforeach; // End of FOREACH #4 ?>

    </tbody>
  </table>
</div>

</body>
</html>