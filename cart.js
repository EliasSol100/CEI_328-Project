// cart.js
// UI logic for cart.php page
// Calls the JSON API endpoint: cart_api.php

const API = "cart_api.php";

function euro(n) {
  const x = Number(n || 0);
  return "€" + x.toFixed(2);
}

async function fetchCart() {
  const res = await fetch(API, { method: "GET" });
  const data = await res.json();
  if (!data.success) throw new Error(data.message || "Failed to load cart");
  return data.cart;
}

async function addToCart(payload) {
  const res = await fetch(API, {
    method: "POST",
    headers: { "Content-Type": "application/json" },
    body: JSON.stringify(payload),
  });
  const data = await res.json();
  if (!data.success) throw new Error(data.message || "Add failed");
  return data;
}

function renderCart(cart) {
  const itemsEl = document.getElementById("cartItems");
  itemsEl.innerHTML = "";

  if (!cart.items || cart.items.length === 0) {
    itemsEl.innerHTML = `<div class="empty">Το καλάθι είναι άδειο.</div>`;
  } else {
    for (const item of cart.items) {
      const name = item.product?.nameGR || item.product?.nameEN || "Product";
      const qty = item.quantity || 0;

      const varText = item.variation
        ? `Variation: ${item.variation.size || "-"} / ${item.variation.yarnType || "-"} / ${
            item.variation.colorName || item.variation.colorID || "-"
          }`
        : "No variation";

      const addonsText = item.addons
        ? `Add-ons: wrap=${item.addons.giftWrapping ? "yes" : "no"}, bag=${
            item.addons.giftBagFlag ? "yes" : "no"
          }`
        : "";

      const msg = item.addons?.giftMessage ? `Message: "${item.addons.giftMessage}"` : "";

      const lineTotal = item.pricing?.lineTotal ?? 0;

      const div = document.createElement("div");
      div.className = "item";
      div.innerHTML = `
        <div class="item-main">
          <div class="item-title">${escapeHtml(name)}</div>
          <div class="item-sub">${escapeHtml(varText)}</div>
          <div class="item-sub">${escapeHtml(addonsText)}</div>
          ${msg ? `<div class="item-sub">${escapeHtml(msg)}</div>` : ""}
        </div>
        <div class="item-right">
          <div class="pill">Qty: ${qty}</div>
          <div class="price">${euro(lineTotal)}</div>
        </div>
      `;
      itemsEl.appendChild(div);
    }
  }

  document.getElementById("subtotal").textContent = euro(cart.totals?.subtotal);
  document.getElementById("addonsTotal").textContent = euro(cart.totals?.addons_total);
  document.getElementById("grandTotal").textContent = euro(cart.totals?.grand_total);
}

function escapeHtml(str) {
  return String(str)
    .replaceAll("&", "&amp;")
    .replaceAll("<", "&lt;")
    .replaceAll(">", "&gt;")
    .replaceAll('"', "&quot;")
    .replaceAll("'", "&#039;");
}

async function refresh() {
  const cart = await fetchCart();
  renderCart(cart);
}

document.addEventListener("DOMContentLoaded", () => {
  const btnRefresh = document.getElementById("btnRefresh");
  const addForm = document.getElementById("addForm");
  const debug = document.getElementById("debug");

  if (btnRefresh) {
    btnRefresh.addEventListener("click", () => {
      refresh().catch((err) => alert(err.message));
    });
  }

  // The "Quick Test: Add Item" form (optional)
  if (addForm) {
    addForm.addEventListener("submit", async (e) => {
      e.preventDefault();

      const fd = new FormData(addForm);

      const product_id = Number(fd.get("product_id"));
      const quantity = Number(fd.get("quantity"));

      const variation_id_raw = (fd.get("variation_id") || "").toString().trim();
      const variation_id = variation_id_raw ? Number(variation_id_raw) : null;

      const payload = {
        product_id,
        quantity,
        variation: variation_id ? { variation_id } : {}, // if product has no variants, keep empty
        addons: {
          gift_wrapping: fd.get("gift_wrapping") === "on",
          gift_bag: fd.get("gift_bag") === "on",
          message: (fd.get("message") || "").toString(),
        },
      };

      try {
        const data = await addToCart(payload);
        if (debug) debug.textContent = JSON.stringify(data, null, 2);
        renderCart(data.cart);
      } catch (err) {
        const msg = err?.message || String(err);
        if (debug) debug.textContent = msg;
        alert(msg);
      }
    });
  }

  // Initial load
  refresh().catch((err) => {
    alert(err.message);
    if (debug) debug.textContent = err.message;
  });
});