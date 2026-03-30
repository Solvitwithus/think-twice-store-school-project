
<?php
session_start();
require __DIR__ . '/config/db.php';
$error = "";
$success = "";

if(!isset($_SESSION['cart'])){
    $_SESSION['cart'] = [];
}
if($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST['find-item'])){
    // find the item based on barcode from the stock_movements table

    $code = $_POST['code'];
    $resolvedItem = [];
    try{
  $query= $conn->prepare("SELECT * FROM stock_movements WHERE barcode = :barcode");
    $query->execute([
        'barcode' => $code
    ]);
   $item = $query->fetch(PDO::FETCH_ASSOC);

if($item){
    // check if item already exists in cart
    $found = false;

    foreach($_SESSION['cart'] as &$cartItem){
        if($cartItem['barcode'] === $item['barcode']){
            $cartItem['quantity'] += 1; // increment qty
            $found = true;
            break;
        }
    }

    if(!$found){
        $item['quantity'] = 1; // default qty in cart
        $_SESSION['cart'][] = $item;
    }
}
    // now the resolved item is stored in a variable
    }catch(PDOException $e){
$error = "failed to resolve barcode" . $e->getMessage();
    }
  if(isset($_POST['clear-cart'])){
     $_SESSION['cart'] = [];
  }

// if $remainingAmount if 0 then insert sale make a post and close the session ['cart']
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Document</title>
    <style>
        .pos-container{
display:flex;
flex-direction:row;
width:100%;
        }
        .pos-left-section{
display:flex;
flex-direction:column;
width:80%;
background:#dddddd;
        }
   .pos-right-section{
    display: grid;
    grid-template-columns: repeat(2, 1fr); /* 2 columns */
    gap: 10px;
    background: #666666;
    width: 20%;
    padding: 10px;
}
        .search-section{
            display:flex;
flex-direction:row;
width: 80%;
gap:10px;
margin:0 auto;
        }
    </style>
</head>
<body >
    <!-- container -->
     <div class="pos-container">
    <!--left content  -->
    <div class="pos-left-section">

    <form method="POST" class="search-section">
        <input type="text" placeholder="search item by code" name="code" class="input-field">
        <button type="submit" name="find-item">Look up</button>
    </form>

    <!-- display the searched items -->

<?php if(!empty($_SESSION['cart'])): ?>
<table>
    <thead>
        <tr>
            <th>No</th>
            <th>item name</th>
            <th>item code</th>
            <th>quantity</th>
            <th>Unit price</th>
            <th>total</th>
        </tr>
    </thead>
  <tbody>
<?php $grandTotal = 0; ?>
<?php foreach($_SESSION['cart'] as $index => $item): ?>
<?php 
    $total = $item['quantity'] * $item['price'];
    $grandTotal += $total;
if($_SERVER["REQUEST_METHOD"]==="POST" && isset($_POST['check-balance'])){
$postedCash=$_POST['cashed'];

$remainingAmount = $grandTotal - $postedCash;
}

?>
<tr>
    <td><?= $index + 1 ?></td>
    <td><?= htmlspecialchars($item['item_name']) ?></td>
    <td><?= htmlspecialchars($item['barcode']) ?></td>
    <td>
        <input 
            type="number" 
            value="<?= $item['quantity'] ?>" 
            name="quantities[<?= $index ?>]" 
            min="1"
        >
    </td>
    <td><?= htmlspecialchars($item['price']) ?></td>
    <td><?= $total ?></td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
<?php endif; ?>
<h3>Total: <?= $grandTotal ?></h3>
    </div>
    <!-- right content -->
     <div class="pos-right-section">
<!-- control cards -->

<div style="background:red; width:fit-content; height:fit-content; cursor:pointer; background:red;">
    <h6>SDK PUSH</h6>
</div>

<div onclick="openHoldModal()" style="background:red; width:fit-content; height:fit-content; cursor:pointer; background:red;">
    <h6>Pause cart</h6>
</div>

<div onclick="opencartmodal()" style="background:red; width:fit-content; height:fit-content; cursor:pointer; background:red;">
    <h6>CASH PAYMENTS</h6>
</div>

<div onclick="openHeldModal()" style="background:red; width:fit-content; height:fit-content; cursor:pointer; background:red;">
    <h6>PAUSED CARTS</h6>
</div>

<div style="background:red; width:fit-content; height:fit-content; cursor:pointer; background:red;">
    <h6>VIEW INVENTORY</h6>
</div>
<form method="POST">
    <button type="submit" name="clear-cart" style="background:red; cursor:pointer;">
        CLEAR CURRENT
    </button>
</form>
<!-- session will be used to pause a cart when resume is clicked then the items are restored -->
     </div>
     </div>


     <!-- cash popup -->
      <div style=" display: none;
        position: fixed; inset: 0;
        background: rgba(26,26,46,0.45);
        backdrop-filter: blur(3px);
        z-index: 100;
        align-items: center;
        justify-content: center;" id="cashmodal">
<form method="POST">
    <input type="number" name="cashed">
    <button type="submit" name="check-balance">submit</button>
<h6>Total Due: <?= htmlspecialchars($grandTotal)?></h6>
    <h6>Balance: <?= htmlspecialchars($remainingAmount)?></h6>
</form>
<button type="button" onclick="closeModal('cashmodal')">close</button>
      </div>


      <!-- pause cart -->

      <div style=" display: none;
        position: fixed; inset: 0;
        background: rgba(26,26,46,0.45);
        backdrop-filter: blur(3px);
        z-index: 100;
        align-items: center;
        justify-content: center;" id="hold">
<form method="POST">
    <input type="text" name="hold">
    <button type="submit" name="hold">Hold</button>

</form>
<button type="button" onclick="closeModal('hold')">close</button>
      </div>




      <!-- held carts -->

      <div style=" display: none;
        position: fixed; inset: 0;
        background: rgba(26,26,46,0.45);
        backdrop-filter: blur(3px);
        z-index: 100;
        align-items: center;
        justify-content: center;" id="held">
<p>held carts</p>
<button type="button" onclick="closeModal('held')">close</button>
      </div>
<script>
    
    function opencartmodal(){
document.getElementById('cashmodal').style.display="flex"; 
    }
    function openHoldModal(){
document.getElementById('hold').style.display="flex"; 
    }
      function openHeldModal(){
document.getElementById('held').style.display="flex"; 
    }
    
   function closeModal(id){
    document.getElementById(id).style.display = 'none';
}


</script>
    
</body>
</html>