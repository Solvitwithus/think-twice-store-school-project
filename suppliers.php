<?php
// ============================================================
// SUPPLIERS PAGE
// Handles: Create, Edit, Delete suppliers
// Pattern: All POST actions come first, SELECT fetch comes last
// ============================================================

require __DIR__ . '/config/db.php';

$error   = "";
$success = "";

// Pre-fill variables used by both the create form and edit form.
// Defaults to empty so the create form starts blank.
$editing        = false;  // flag to switch form between "create" and "edit" mode
$edit_id        = null;
$company_name   = $contact_name = $email = $phone = $secondary_phone = "";
$website        = $address_line1 = $address_line2 = $city = $state = "";
$postal_code    = $country = $billing_address = $tax_id = $bank_name = "";
$account_number = $payment_terms = $currency = $preferred_payment_method = "";
$products_supplied = $notes = $status = "";
$min_order_qty  = $max_order_qty = $discount_rate = "";


// ============================================================
// ACTION 1 — DELETE
// Must come before the SELECT so the deleted row is gone
// when we re-fetch the table below.
// Using POST (not GET) to prevent accidental deletion.
// ============================================================
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST['delete_supplier'])) {
    // Cast to int immediately — never trust raw user input in a query
    $supplier_id = (int) $_POST['delete_supplier_id'];

    try {
        $stmt = $conn->prepare("DELETE FROM suppliers WHERE id = :id");
        $stmt->execute(['id' => $supplier_id]);

        // Redirect so a page refresh won't re-send the DELETE request
        header("Location: " . $_SERVER['PHP_SELF'] . "?deleted=1");
        exit;

    } catch (PDOException $e) {
        $error = "Delete failed: " . $e->getMessage();
    }
}


// ============================================================
// ACTION 2 — LOAD SUPPLIER INTO FORM FOR EDITING
// When user clicks "Edit", we GET ?edit_id=X.
// We fetch that supplier's data and pre-fill the form.
// The form then submits to ACTION 3 (update) below.
// ============================================================
if ($_SERVER["REQUEST_METHOD"] === "GET" && isset($_GET['edit_id'])) {
    $edit_id = (int) $_GET['edit_id'];

    try {
        $stmt = $conn->prepare("SELECT * FROM suppliers WHERE id = :id");
        $stmt->execute(['id' => $edit_id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($row) {
            // Flip the flag so the form renders in edit mode
            $editing = true;

            // Pre-fill every variable from the database row
            $company_name            = $row['company_name'];
            $contact_name            = $row['contact_name'];
            $email                   = $row['email'];
            $phone                   = $row['phone'];
            $secondary_phone         = $row['secondary_phone'];
            $website                 = $row['website'];
            $address_line1           = $row['address_line1'];
            $address_line2           = $row['address_line2'];
            $city                    = $row['city'];
            $state                   = $row['state'];
            $postal_code             = $row['postal_code'];
            $country                 = $row['country'];
            $billing_address         = $row['billing_address'];
            $tax_id                  = $row['tax_id'];
            $bank_name               = $row['bank_name'];
            $account_number          = $row['account_number'];
            $payment_terms           = $row['payment_terms'];
            $currency                = $row['currency'];
            $preferred_payment_method = $row['preferred_payment_method'];
            $products_supplied       = $row['products_supplied'];
            $min_order_qty           = $row['min_order_qty'];
            $max_order_qty           = $row['max_order_qty'];
            $discount_rate           = $row['discount_rate'];
            $notes                   = $row['notes'];
            $status                  = $row['status'];
        } else {
            $error = "Supplier not found.";
        }

    } catch (PDOException $e) {
        $error = "Could not load supplier: " . $e->getMessage();
    }
}


// ============================================================
// ACTION 3 — UPDATE (save edits)
// Triggered when the edit form is submitted.
// The hidden field 'edit_supplier_id' tells us which row to update.
// ============================================================
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST['update_supplier'])) {
    $edit_id = (int) $_POST['edit_supplier_id'];

    try {
        $stmt = $conn->prepare("
            UPDATE suppliers SET
                company_name             = :company_name,
                contact_name             = :contact_name,
                email                    = :email,
                phone                    = :phone,
                secondary_phone          = :secondary_phone,
                website                  = :website,
                address_line1            = :address_line1,
                address_line2            = :address_line2,
                city                     = :city,
                state                    = :state,
                postal_code              = :postal_code,
                country                  = :country,
                billing_address          = :billing_address,
                tax_id                   = :tax_id,
                bank_name                = :bank_name,
                account_number           = :account_number,
                payment_terms            = :payment_terms,
                currency                 = :currency,
                preferred_payment_method = :preferred_payment_method,
                products_supplied        = :products_supplied,
                min_order_qty            = :min_order_qty,
                max_order_qty            = :max_order_qty,
                discount_rate            = :discount_rate,
                notes                    = :notes,
                status                   = :status
            WHERE id = :id
        ");

        $stmt->execute([
            'company_name'             => $_POST['company_name'],
            'contact_name'             => $_POST['contact_name'],
            'email'                    => $_POST['email'],
            'phone'                    => $_POST['phone'],
            'secondary_phone'          => $_POST['secondary_phone'],
            'website'                  => $_POST['website'],
            'address_line1'            => $_POST['address_line1'],
            'address_line2'            => $_POST['address_line2'],
            'city'                     => $_POST['city'],
            'state'                    => $_POST['state'],
            'postal_code'              => $_POST['postal_code'],
            'country'                  => $_POST['country'],
            'billing_address'          => $_POST['billing_address'],
            'tax_id'                   => $_POST['tax_id'],
            'bank_name'                => $_POST['bank_name'],
            'account_number'           => $_POST['account_number'],
            'payment_terms'            => $_POST['payment_terms'],
            'currency'                 => $_POST['currency'],
            'preferred_payment_method' => $_POST['preferred_payment_method'],
            'products_supplied'        => $_POST['products_supplied'],
            'min_order_qty'            => $_POST['min_order_qty'],
            'max_order_qty'            => $_POST['max_order_qty'],
            'discount_rate'            => $_POST['discount_rate'],
            'notes'                    => $_POST['notes'],
            'status'                   => $_POST['status'],
            'id'                       => $edit_id
        ]);

        // Redirect after update to prevent re-submission on refresh
        header("Location: " . $_SERVER['PHP_SELF'] . "?updated=1");
        exit;

    } catch (PDOException $e) {
        $error = "Update failed: " . $e->getMessage();
    }
}


// ============================================================
// ACTION 4 — CREATE NEW SUPPLIER
// ============================================================
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST['create_supplier'])) {
    try {
        $stmt = $conn->prepare("
            INSERT INTO suppliers 
                (company_name, contact_name, email, phone, secondary_phone,
                 website, address_line1, address_line2, city, state,
                 postal_code, country, billing_address, tax_id, bank_name,
                 account_number, payment_terms, currency, preferred_payment_method,
                 products_supplied, min_order_qty, max_order_qty, discount_rate,
                 notes, status)
            VALUES 
                (:company_name, :contact_name, :email, :phone, :secondary_phone,
                 :website, :address_line1, :address_line2, :city, :state,
                 :postal_code, :country, :billing_address, :tax_id, :bank_name,
                 :account_number, :payment_terms, :currency, :preferred_payment_method,
                 :products_supplied, :min_order_qty, :max_order_qty, :discount_rate,
                 :notes, :status)
        ");

        $stmt->execute([
            'company_name'             => $_POST['company_name'],
            'contact_name'             => $_POST['contact_name'],
            'email'                    => $_POST['email'],
            'phone'                    => $_POST['phone'],
            'secondary_phone'          => $_POST['secondary_phone'],
            'website'                  => $_POST['website'],
            'address_line1'            => $_POST['address_line1'],
            'address_line2'            => $_POST['address_line2'],
            'city'                     => $_POST['city'],
            'state'                    => $_POST['state'],
            'postal_code'              => $_POST['postal_code'],
            'country'                  => $_POST['country'],
            'billing_address'          => $_POST['billing_address'],
            'tax_id'                   => $_POST['tax_id'],
            'bank_name'                => $_POST['bank_name'],
            'account_number'           => $_POST['account_number'],
            'payment_terms'            => $_POST['payment_terms'],
            'currency'                 => $_POST['currency'],
            'preferred_payment_method' => $_POST['preferred_payment_method'],
            'products_supplied'        => $_POST['products_supplied'],
            'min_order_qty'            => $_POST['min_order_qty'],
            'max_order_qty'            => $_POST['max_order_qty'],
            'discount_rate'            => $_POST['discount_rate'],
            'notes'                    => $_POST['notes'],
            'status'                   => $_POST['status'],
        ]);

        // Redirect after create — prevents duplicate row on refresh
        header("Location: " . $_SERVER['PHP_SELF'] . "?success=1");
        exit;

    } catch (PDOException $e) {
        $error = "Could not create supplier: " . $e->getMessage();
    }
}


// ============================================================
// FEEDBACK MESSAGES
// Read from query string set by redirects above.
// This runs on the GET request after every redirect.
// ============================================================
if (isset($_GET['success']))  $success = "Supplier created successfully!";
if (isset($_GET['updated']))  $success = "Supplier updated successfully!";
if (isset($_GET['deleted']))  $success = "Supplier deleted.";


// ============================================================
// FETCH ALL SUPPLIERS FOR THE TABLE
// Always runs last so it reflects any changes made above.
// ============================================================
$suppliers = [];
try {
    $stmt = $conn->prepare("SELECT * FROM suppliers ORDER BY company_name");
    $stmt->execute();
    $suppliers = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $error = "Could not load suppliers: " . $e->getMessage();
}
?>


<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="/think-twice/styles.css">
    <title>Suppliers</title>
    <style>
        form {
            max-width: 800px;
            margin: 20px auto;
            padding: 20px;
            background-color: #f9f9f9;
            border-radius: 8px;
            font-family: Arial, sans-serif;
            box-shadow: 0 0 10px rgba(0,0,0,0.1);
        }
        form h2 { text-align: center; margin-bottom: 20px; color: #333; }
        form label { display: block; margin-top: 10px; font-weight: bold; }
        form input[type="text"],
        form input[type="email"],
        form input[type="tel"],
        form input[type="number"],
        form select,
        form textarea {
            width: 100%;
            padding: 8px 10px;
            margin-top: 5px;
            border: 1px solid #ccc;
            border-radius: 4px;
            box-sizing: border-box;
        }
        form textarea { resize: vertical; }
        form button[type="submit"] {
            margin-top: 20px;
            padding: 10px 15px;
            background-color: #4CAF50;
            color: white;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-size: 16px;
        }
        form button[type="submit"]:hover { background-color: #45a049; }

        /* Edit mode: give the form a blue accent so it's visually distinct */
        form.edit-mode { border-top: 4px solid #2196F3; }
        form.edit-mode h2 { color: #2196F3; }
        form.edit-mode button[type="submit"] { background-color: #2196F3; }
        form.edit-mode button[type="submit"]:hover { background-color: #1976D2; }

        .cancel-link {
            display: inline-block;
            margin-top: 10px;
            margin-left: 10px;
            color: #999;
            text-decoration: none;
        }
        .cancel-link:hover { text-decoration: underline; }

        table { width: 100%; border-collapse: collapse; margin: 20px 0; }
        th, td { padding: 10px; border: 1px solid #ddd; text-align: left; }
        th { background-color: #4CAF50; color: white; }
        tr:nth-child(even) { background-color: #f2f2f2; }
        .btn-edit {
            padding: 4px 10px;
            background: #2196F3;
            color: white;
            border-radius: 4px;
            text-decoration: none;
            font-size: 13px;
        }
        .btn-delete {
            padding: 4px 10px;
            background: #e53935;
            color: white;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-size: 13px;
        }
   
    </style>
</head>
<body>

<?php include 'navbar.php'; ?>


<!-- ============================================================
     FEEDBACK MESSAGES
     Shown at top of page after any create / update / delete.
     ============================================================ -->
<?php if ($error):   ?><p class="msg-error"><?= htmlspecialchars($error) ?></p><?php endif; ?>
<?php if ($success): ?><p class="msg-success"><?= htmlspecialchars($success) ?></p><?php endif; ?>


<!-- ============================================================
     FORM — switches between Create and Edit mode.

     $editing is set to true when user clicks an Edit link.
     When editing:
       - The form title changes to "Edit Supplier"
       - A hidden field carries the supplier ID to the update handler
       - The submit button triggers update_supplier, not create_supplier
       - A Cancel link lets the user go back without saving
     When creating:
       - All fields are empty
       - The submit button triggers create_supplier
     ============================================================ -->
<form method="POST" class="<?= $editing ? 'edit-mode' : '' ?>">

    <h2><?= $editing ? 'Edit Supplier' : 'Create Supplier' ?></h2>

    <?php if ($editing): ?>
        <!--
            Hidden field: carries the ID of the supplier being edited.
            The update handler reads this with $_POST['edit_supplier_id'].
        -->
        <input type="hidden" name="edit_supplier_id" value="<?= (int) $edit_id ?>">
    <?php endif; ?>

    <label for="company_name">Company Name *</label>
    <input type="text" name="company_name" required
           value="<?= htmlspecialchars($company_name) ?>">

    <label for="contact_name">Contact Name *</label>
    <input type="text" name="contact_name" required
           value="<?= htmlspecialchars($contact_name) ?>">

    <label for="email">Email *</label>
    <input type="email" name="email" required
           value="<?= htmlspecialchars($email) ?>">

    <label for="phone">Phone *</label>
    <input type="tel" name="phone" required
           value="<?= htmlspecialchars($phone) ?>">

    <label for="secondary_phone">Secondary Phone</label>
    <input type="tel" name="secondary_phone"
           value="<?= htmlspecialchars($secondary_phone) ?>">

    <label for="website">Website</label>
    <input type="text" name="website"
           value="<?= htmlspecialchars($website) ?>">

    <label for="address_line1">Address Line 1 *</label>
    <input type="text" name="address_line1" required
           value="<?= htmlspecialchars($address_line1) ?>">

    <label for="address_line2">Address Line 2</label>
    <input type="text" name="address_line2"
           value="<?= htmlspecialchars($address_line2) ?>">

    <label for="city">City *</label>
    <input type="text" name="city" required
           value="<?= htmlspecialchars($city) ?>">

    <label for="state">State / Province</label>
    <input type="text" name="state"
           value="<?= htmlspecialchars($state) ?>">

    <label for="postal_code">Postal Code *</label>
    <input type="text" name="postal_code" required
           value="<?= htmlspecialchars($postal_code) ?>">

    <label for="country">Country *</label>
    <input type="text" name="country" required
           value="<?= htmlspecialchars($country) ?>">

    <label for="billing_address">Billing Address</label>
    <input type="text" name="billing_address"
           value="<?= htmlspecialchars($billing_address) ?>">

    <label for="tax_id">Tax ID</label>
    <input type="text" name="tax_id"
           value="<?= htmlspecialchars($tax_id) ?>">

    <label for="bank_name">Bank Name</label>
    <input type="text" name="bank_name"
           value="<?= htmlspecialchars($bank_name) ?>">

    <label for="account_number">Account Number</label>
    <input type="text" name="account_number"
           value="<?= htmlspecialchars($account_number) ?>">

    <label for="payment_terms">Payment Terms</label>
    <input type="text" name="payment_terms"
           value="<?= htmlspecialchars($payment_terms) ?>">

    <label for="currency">Currency</label>
    <input type="text" name="currency"
           value="<?= htmlspecialchars($currency) ?>">

    <label>Preferred Payment Method</label>
    <div class="radio-group">
        <!--
            checked="..." uses a ternary to pre-select the right radio
            when editing. Has no effect when creating (all unchecked).
        -->
        <input type="radio" name="preferred_payment_method" id="cash" value="cash"
               <?= $preferred_payment_method === 'cash'  ? 'checked' : '' ?>>
        <label for="cash">Cash</label>

        <input type="radio" name="preferred_payment_method" id="mpesa" value="mpesa"
               <?= $preferred_payment_method === 'mpesa' ? 'checked' : '' ?>>
        <label for="mpesa">M-Pesa</label>

        <input type="radio" name="preferred_payment_method" id="bank" value="bank"
               <?= $preferred_payment_method === 'bank'  ? 'checked' : '' ?>>
        <label for="bank">Bank</label>
    </div>

    <label for="products_supplied">Products Supplied</label>
    <textarea name="products_supplied"><?= htmlspecialchars($products_supplied) ?></textarea>

    <label for="min_order_qty">Minimum Order Quantity</label>
    <input type="number" name="min_order_qty"
           value="<?= htmlspecialchars($min_order_qty) ?>">

    <label for="max_order_qty">Maximum Order Quantity</label>
    <input type="number" name="max_order_qty"
           value="<?= htmlspecialchars($max_order_qty) ?>">

    <label for="discount_rate">Discount Rate (%)</label>
    <input type="number" step="0.01" name="discount_rate"
           value="<?= htmlspecialchars($discount_rate) ?>">

    <label for="notes">Notes</label>
    <textarea name="notes"><?= htmlspecialchars($notes) ?></textarea>

    <label for="status">Status</label>
    <select name="status">
        <!--
            selected="..." compares the saved status to each option value.
            When creating, $status is empty so nothing is pre-selected.
        -->
        <option value="active"      <?= $status === 'active'      ? 'selected' : '' ?>>Active</option>
        <option value="inactive"    <?= $status === 'inactive'    ? 'selected' : '' ?>>Inactive</option>
        <option value="blacklisted" <?= $status === 'blacklisted' ? 'selected' : '' ?>>Blacklisted</option>
    </select>

    <?php if ($editing): ?>
        <!-- Edit mode: submit triggers update_supplier handler -->
        <button type="submit" name="update_supplier">Save Changes</button>
        <!-- Cancel discards edits and returns to the clean page -->
        <a class="cancel-link" href="<?= $_SERVER['PHP_SELF'] ?>">Cancel</a>
    <?php else: ?>
        <!-- Create mode: submit triggers create_supplier handler -->
        <button type="submit" name="create_supplier">Submit</button>
    <?php endif; ?>

</form>


<!-- ============================================================
     SUPPLIERS TABLE
     Each row has:
       - An Edit link  → GET ?edit_id=X  → loads the form in edit mode
       - A Delete form → POST with supplier ID → deletes and redirects
     ============================================================ -->
<div>
    <?php if (empty($suppliers)): ?>
        <p style="text-align:center; color:#999;">No suppliers yet. Create one above.</p>
    <?php else: ?>
    <table>
        <thead>
            <tr>
                <th>Company Name</th>
                <th>Email</th>
                <th>Status</th>
                <th>Currency</th>
                <th>Website</th>
                <th>Payment Method</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($suppliers as $supplier): ?>
            <tr>
                <td><?= htmlspecialchars($supplier['company_name']) ?></td>
                <td><?= htmlspecialchars($supplier['email']) ?></td>
                <td><?= htmlspecialchars($supplier['status']) ?></td>
                <td><?= htmlspecialchars($supplier['currency']) ?></td>
                <td><?= htmlspecialchars($supplier['website']) ?></td>
                <td><?= htmlspecialchars($supplier['preferred_payment_method']) ?></td>
                <td>
                    <!--
                        Edit: simple GET link with the supplier ID.
                        PHP reads ?edit_id=X at the top and pre-fills the form.
                    -->
                    <a class="btn-edit"
                       href="<?= $_SERVER['PHP_SELF'] ?>?edit_id=<?= (int) $supplier['id'] ?>">
                        Edit
                    </a>

                    &nbsp;

                    <!--
                        Delete: wrapped in its own small POST form.
                        The hidden field carries the ID to the delete handler.
                        onclick confirm prevents accidental clicks.
                    -->
                    <form method="POST" style="display:inline;">
                        <input type="hidden" name="delete_supplier_id"
                               value="<?= (int) $supplier['id'] ?>">
                        <button class="btn-delete" type="submit" name="delete_supplier"
                                onclick="return confirm('Delete <?= htmlspecialchars(addslashes($supplier['company_name'])) ?>?')">
                            Delete
                        </button>
                    </form>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>
</div>

</body>
</html>