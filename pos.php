<?php
require __DIR__ . '/../config/db.php';
// TODO after i'm all done with this page ill release this check!
// session_start();
// if (!in_array('pos', $_SESSION['permissions'] ?? [])) {
//     header('Location: /think-twice');
//     exit;
// }
$cartItems = [];
$error ="";
$success = "";


// if($_SERVER["REQUEST_METHOD"]==="POST" && isset($_POST['resolve-item'])){
//     $code = $_POST['item-code'];

//     $items = [];
// try{
// $query = $conn->prepare("SELECT * FROM stock_movements where barcode = :barcode");
// // TODO in the stock_movement i have changed the column item_id to item name and havent changed the logic in warehouse manaagement remember to go back there fot the pos to work

// $query->execute(['barcode' => $code]);
// $items = $query->fetch(PDO::FETCH_ASSOC);

// // $cartItems= ...$items
// }catch(PDOException $e){
// $error = "this is haram" . $e->getMessage();
// }
// }

// fetching all the items in the inventory


?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8"><link rel="stylesheet" href="/think-twice/styles.css">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Document</title>
    <style>
        .pos-container{
            display:flex;
            flex:1;
            padding:10px;
            border:2px solid black;
         

            background:red;

        }
        .left-section{
            width:50%;
            background:#f2f2f2;
            height:80%
        }
        .right-section{
            width:50%;
            background:green;
        }
       .item-searchbar {
    width: 80%;
    margin: 0 auto;
}
.pos-item-displayer{
    width:100%;
    margin:10px 0 0 0;
    border:1px solid black;
}
.pos-controll-button{
display:flex;
flex-direction:column;
background:#dddddd;
width:15%;
height:fit;
}
    </style>
</head>
<body>
    <?php include 'navbar.php';?>
    
    <div class="pos-container">
        <!-- the display section  -->
         <div class="left-section">
            <form class="item-searchbar">
            <input type="text" name="item-code" placeholder="enter the item code ❔" >
            <button type="submit" name="resolve-item">look item up</button>
            </form>

                <table class="pos-item-displayer">
<thead>
    <tr>
        <th>No:</th>
        <th>Item name</th>
        <th>code</th>
        <th>quantity</th>
    <th>price</th>
    <th>action</th>
    </tr>
</thead>
                </table>
             
          </div>
        <!-- the control panel part  -->
         <div class="right-section">
            <div class="pos-controll-button" onclick="handleInventory()">
🗠
<h6>inventory</h6>
            </div>
         </div>
    </div>

    <script>
        functionhandleInventory(){
            window.location.href = "/think-twice/inventory/wareHousing.php";
        }
    </script>
</body>
</html>