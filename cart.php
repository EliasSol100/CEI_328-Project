<?php
session_start();
require_once "authentication/get_config.php"; // αν το χρησιμοποιείτε
?>
<!doctype html>
<html lang="el">

<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width,initial-scale=1" />
  <title>Shopping Cart</title>
  <link rel="stylesheet" href="cart.css" />
</head>

<body>

  <?php
  // Αν έχετε header.php, βάλ’το εδώ:  xD 
  if (file_exists("header.php")) {
    include "header.php";
  }
  ?>

  <main class="container">
    <section class="card">
      <div class="row space">
        <h2>Shopping Cart</h2>
        <button id="btnRefresh" class="btn">Refresh</button>
      </div>

      <div id="cartItems" class="items"></div>

      <div class="totals">
        <div class="totals-row"><span>Subtotal</span><span id="subtotal">€0.00</span></div>
        <div class="totals-row"><span>Add-ons</span><span id="addonsTotal">€0.00</span></div>
        <div class="totals-row grand"><span>Total</span><span id="grandTotal">€0.00</span></div>
      </div>
    </section>

    <section class="card">
      <h2>Quick Test: Add Item</h2>
      <p class="muted">Αυτό είναι για δοκιμή ότι δουλεύει το add-to-cart.</p>

      <form id="addForm" class="form">
        <label>Product ID <input name="product_id" type="number" value="2" min="1" required></label>
        <label>Quantity <input name="quantity" type="number" value="1" min="1" required></label>
        <label>Variation ID (αν έχει variants) <input name="variation_id" type="number" placeholder="π.χ. 1"></label>

        <label class="checkbox">
          <input name="gift_wrapping" type="checkbox" />
          Gift wrapping
        </label>
        <label class="checkbox">
          <input name="gift_bag" type="checkbox" />
          Gift bag
        </label>
        <label>Message (optional)
          <input name="message" type="text" maxlength="255" placeholder="Χρόνια πολλά!">
        </label>

        <button class="btn primary" type="submit">Add to cart</button>
      </form>

      <pre id="debug" class="debug"></pre>
    </section>
  </main>

  <script src="cart.js"></script>
</body>

</html>