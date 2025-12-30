# agents.md — Patch: write encrypted inventory delta on order stock reduction (WooCommerce)

## Goal
Ensure a delta file (pending adjustments) is created when an order reduces stock **while inventory is encrypted**, even if `woocommerce_product_set_stock` does not fire during checkout.

This patch adds an **order-level** hook (`woocommerce_reduce_order_stock`) to capture line-item quantity deltas and append them via the existing pending-adjustment writer (e.g., `ptcgdm_append_pending_inventory_adjustment()`), which later applies after unlock.

---

## Files to edit
- `ptcg-deck-embed.php` (or the plugin bootstrap file that loads on both frontend + admin)

---

## Patch steps

### 1) Add an order-level hook
In `ptcg-deck-embed.php`, in your hooks/boot section (near other `add_action` registrations), add:

- `add_action('woocommerce_reduce_order_stock', 'ptcgdm_capture_order_delta_on_stock_reduce', 10, 1);`

This ensures the handler runs when WooCommerce reduces stock for an order.

---

### 2) Implement `ptcgdm_capture_order_delta_on_stock_reduce()`
Add the function below (place near other WooCommerce-related handlers). It:

- exits unless encryption is active (`encrypted_v1`)
- avoids double-writing by marking the order once processed
- iterates order line items and creates negative deltas by SKU
- appends pending adjustments via your existing writer

```php
// Capture deltas when WooCommerce reduces stock for an order.
// This is more reliable than woocommerce_product_set_stock for checkout flows.
add_action('woocommerce_reduce_order_stock', 'ptcgdm_capture_order_delta_on_stock_reduce', 10, 1);

function ptcgdm_capture_order_delta_on_stock_reduce($order) {
  if (!function_exists('wc_get_order')) return;

  // Woo may pass WC_Order or order_id depending on version/callsite.
  if (is_numeric($order)) {
    $order = wc_get_order((int)$order);
  }
  if (!$order) return;

  // Only write deltas while encrypted.
  $meta = function_exists('ptcgdm_get_encryption_meta') ? ptcgdm_get_encryption_meta() : null;
  $status = is_array($meta) && isset($meta['status']) ? (string)$meta['status'] : '';
  if ($status !== 'encrypted_v1') {
    return;
  }

  // Idempotency: do not write twice for the same order.
  $flag_key = '_ptcgdm_delta_captured';
  $already = $order->get_meta($flag_key, true);
  if ($already) return;

  $ts = time();
  $order_id = (int)$order->get_id();

  foreach ($order->get_items() as $item_id => $item) {
    if (!is_object($item) || !method_exists($item, 'get_product')) continue;

    $product = $item->get_product();
    if (!$product) continue;

    // Qty purchased
    $qty = method_exists($item, 'get_quantity') ? (int)$item->get_quantity() : 0;
    if ($qty <= 0) continue;

    // SKU: prefer explicit product SKU; fall back to product/variation id string to avoid dropping adjustments.
    $sku = '';
    if (method_exists($product, 'get_sku')) {
      $sku = (string)$product->get_sku();
    }
    if ($sku === '') {
      $sku = 'product_id:' . (int)$product->get_id();
    }

    // Build a minimal adjustment payload. Keep it compatible with your existing delta/apply logic.
    $adjustment = array(
      'sku'       => $sku,
      'delta'     => -$qty,
      'order_id'  => $order_id,
      'item_id'   => is_numeric($item_id) ? (int)$item_id : 0,
      'ts'        => $ts,
      'source'    => 'woocommerce_reduce_order_stock',
    );

    if (function_exists('ptcgdm_append_pending_inventory_adjustment')) {
      ptcgdm_append_pending_inventory_adjustment($adjustment);
    } else {
      error_log('[PTCGDM] Missing ptcgdm_append_pending_inventory_adjustment(); cannot write delta.');
      break;
    }
  }

  // Mark captured (even if some items had no SKU/qty) to prevent duplicates on retries.
  $order->update_meta_data($flag_key, 1);
  $order->save_meta_data();
}
```

**Notes**
- If your pending adjustment format requires different keys (e.g., `dataset`, `card_id`, etc.), adjust the `$adjustment` array to match.
- If you have a SKU normalization helper (e.g., `ptcgdm_normalize_inventory_sku()`), apply it before writing.

---

### 3) Keep existing stock-change hook (optional)
Do **not** remove your current `woocommerce_product_set_stock` hook; it can still cover:
- manual stock edits
- admin adjustments
- API updates

This patch only ensures orders are reliably captured.

---

## Verification checklist

1) Enable encryption (`status` must be exactly `encrypted_v1`).
2) Place a WooCommerce test order for a product with stock management enabled.
3) Confirm PHP error log contains **no** “[PTCGDM] Missing …” errors.
4) Confirm your delta/pending-adjustment file is created/updated after checkout.
5) Unlock inventory and verify pending adjustments are applied exactly once (order meta flag prevents duplicates).

---

## Troubleshooting

- If no delta is written:
  - confirm hook file is loaded on frontend checkout (must be in plugin bootstrap, not admin-only include)
  - ensure “Manage stock” is enabled on the product and “Reduce stock on checkout” is enabled in Woo settings
  - add temporary logging at the top of `ptcgdm_capture_order_delta_on_stock_reduce()`:
    `error_log('[PTCGDM] reduce_order_stock fired for order ' . $order_id);`

