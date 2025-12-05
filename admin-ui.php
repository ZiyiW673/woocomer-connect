<?php
/**
 * Front-end Admin UI page for inventory management.
 */

if (!defined('ABSPATH')) exit;

/**
 * Strip headings/descriptions that shouldn't appear on the public Admin UI page.
 *
 * @param string $content
 * @return string
 */
function ptcgdm_strip_admin_ui_headings($content) {
  $content = preg_replace('~<h1[^>]*>\s*PTCG Deck \xe2\x80\x93 Card Inventory\s*</h1>~ui', '', $content);
  $content = preg_replace('~<h1[^>]*>\s*One Piece TCG \xe2\x80\x93 Card Inventory\s*</h1>~ui', '', $content);
  $content = preg_replace('~<p[^>]*class="description"[^>]*>\s*Maintain a single card inventory list using the local dataset\.\s*</p>~ui', '', $content);
  $content = preg_replace('~<p[^>]*class="description"[^>]*>\s*Track One Piece TCG card inventory using the local dataset\.\s*</p>~ui', '', $content);

  return $content;
}

/**
 * Render a specific dataset inventory and return the sanitized markup.
 *
 * @param callable $callback
 * @return string
 */
function ptcgdm_capture_admin_ui_panel($callback) {
  if (!is_callable($callback)) {
    return '<div class="wrap"><p>Inventory UI is unavailable.</p></div>';
  }

  ob_start();
  call_user_func($callback);
  $content = ob_get_clean();

  return ptcgdm_strip_admin_ui_headings((string) $content);
}

/**
 * Render a simple WooCommerce orders list for the Admin UI page.
 */
function ptcgdm_render_admin_orders_panel() {
  echo '<div class="wrap"><h2>Orders</h2>';

  if (!function_exists('wc_get_orders')) {
    echo '<p class="ptcgdm-orders__empty">WooCommerce is not available.</p>';
    echo '</div>';
    return;
  }

  $orders = wc_get_orders([
    'limit'   => 20,
    'orderby' => 'date',
    'order'   => 'DESC',
  ]);

  if (empty($orders)) {
    echo '<p class="ptcgdm-orders__empty">No orders found.</p>';
    echo '</div>';
    return;
  }

  $statuses       = wc_get_order_statuses();
  $status_options = '';
  foreach ($statuses as $key => $label) {
    $status_options .= '<option value="' . esc_attr($key) . '">' . esc_html($label) . '</option>';
  }

  $nonce    = wp_create_nonce('ptcgdm_update_order_status');
  $ajax_url = esc_url(admin_url('admin-ajax.php'));

  echo '<div class="ptcgdm-orders" data-ajax-url="' . $ajax_url . '" data-nonce="' . esc_attr($nonce) . '">';

  echo '<div class="ptcgdm-orders__list is-active">';
  echo '<table class="ptcgdm-orders__table">';
  echo '<thead><tr>';
  echo '<th scope="col">Order</th>';
  echo '<th scope="col">Date</th>';
  echo '<th scope="col">Status</th>';
  echo '<th scope="col">Total</th>';
  echo '<th scope="col">Customer</th>';
  echo '<th scope="col">Actions</th>';
  echo '</tr></thead>';
  echo '<tbody>';

  foreach ($orders as $order) {
    if (!$order instanceof WC_Order) {
      continue;
    }

    $order_id    = $order->get_id();
    $order_number = esc_html($order->get_order_number());
    $status_value = $order->get_status();
    $status_label = esc_html(wc_get_order_status_name($status_value));

    $date_created = $order->get_date_created();
    $date_label   = $date_created ? esc_html($date_created->date_i18n(get_option('date_format') . ' ' . get_option('time_format'))) : '-';

    $customer_name = esc_html($order->get_formatted_billing_full_name() ?: __('Guest', 'woocommerce'));

    $total = $order->get_formatted_order_total();
    if (!is_string($total)) {
      $total = wc_price($order->get_total());
    }

    echo '<tr id="ptcgdm-order-row-' . esc_attr($order_id) . '">';
    echo '<td>#' . $order_number . '</td>';
    echo '<td>' . $date_label . '</td>';
    echo '<td class="ptcgdm-order-status">' . $status_label . '</td>';
    echo '<td>' . wp_kses_post($total) . '</td>';
    echo '<td>' . $customer_name . '</td>';
    echo '<td><button type="button" class="ptcgdm-order-detail-btn" data-order-id="' . esc_attr($order_id) . '">View details</button></td>';
    echo '</tr>';
  }

  echo '</tbody>';
  echo '</table>';
  echo '</div>';

  foreach ($orders as $order) {
    if (!$order instanceof WC_Order) {
      continue;
    }

    $order_id = $order->get_id();

    $date_created = $order->get_date_created();
    $date_label   = $date_created ? esc_html($date_created->date_i18n(get_option('date_format') . ' ' . get_option('time_format'))) : '-';
    $status_value = $order->get_status();
    $status_label = esc_html(wc_get_order_status_name($status_value));

    echo '<div class="ptcgdm-orders__detail-panel" id="ptcgdm-order-detail-' . esc_attr($order_id) . '">';
    echo '<button type="button" class="ptcgdm-orders__back" data-order-id="' . esc_attr($order_id) . '">&larr; Back to orders</button>';
    echo '<h3>Order #' . esc_html($order->get_order_number()) . '</h3>';
    echo '<p class="ptcgdm-orders__meta">Date: <strong>' . $date_label . '</strong></p>';
    echo '<p class="ptcgdm-orders__meta">Status: <strong class="ptcgdm-order-detail-status">' . $status_label . '</strong></p>';

    echo '<h4>Products</h4>';
    echo '<table class="ptcgdm-orders__table ptcgdm-orders__table--nested">';
    echo '<thead><tr><th scope="col">Product</th><th scope="col">Qty</th><th scope="col">Line total</th></tr></thead><tbody>';
    foreach ($order->get_items() as $item) {
      if (!$item instanceof WC_Order_Item_Product) {
        continue;
      }

      $product      = $item->get_product();
      $image_url    = '';
      if ($product instanceof WC_Product) {
        $image_id  = $product->get_image_id();
        $image_url = $image_id ? wp_get_attachment_image_url($image_id, 'thumbnail') : '';
      }
      if (empty($image_url)) {
        $placeholder = function_exists('wc_placeholder_img_src') ? wc_placeholder_img_src() : '';
        $image_url   = $placeholder ?: '';
      }

      $product_name = esc_html($item->get_name());
      $quantity     = absint($item->get_quantity());
      $line_total   = $item->get_total();
      $line_total   = is_numeric($line_total) ? wc_price((float) $line_total, ['currency' => $order->get_currency()]) : wc_price($order->get_total());

      echo '<tr>';
      echo '<td><button type="button" class="ptcgdm-product-link" data-image-url="' . esc_url($image_url) . '">' . $product_name . '</button></td>';
      echo '<td>' . $quantity . '</td>';
      echo '<td>' . wp_kses_post($line_total) . '</td>';
      echo '</tr>';
    }
    echo '</tbody></table>';

    echo '<div class="ptcgdm-orders__totals">';
    echo '<p><strong>Subtotal:</strong> ' . wp_kses_post(wc_price($order->get_subtotal(), ['currency' => $order->get_currency()])) . '</p>';
    echo '<p><strong>Discount:</strong> ' . wp_kses_post(wc_price($order->get_discount_total(), ['currency' => $order->get_currency()])) . '</p>';
    echo '<p><strong>Shipping:</strong> ' . wp_kses_post(wc_price($order->get_shipping_total(), ['currency' => $order->get_currency()])) . '</p>';
    echo '<p><strong>Tax:</strong> ' . wp_kses_post(wc_price($order->get_total_tax(), ['currency' => $order->get_currency()])) . '</p>';
    echo '<p><strong>Total:</strong> ' . wp_kses_post($order->get_formatted_order_total()) . '</p>';
    echo '</div>';

    echo '<form class="ptcgdm-order-status-form" data-order-id="' . esc_attr($order_id) . '">';
    echo '<label for="ptcgdm-status-' . esc_attr($order_id) . '"><strong>Update status</strong></label><br />';
    echo '<select id="ptcgdm-status-' . esc_attr($order_id) . '" name="status">';
    echo str_replace('value="' . esc_attr($status_value) . '"', 'value="' . esc_attr($status_value) . '" selected', $status_options);
    echo '</select> ';
    echo '<button type="submit">Save</button>';
    echo '<span class="ptcgdm-order-status__message" aria-live="polite"></span>';
    echo '</form>';

    echo '</div>';
  }

  echo '</div>';
  echo '</div>';
}

/**
 * Build the inventory management markup for the public page/shortcode.
 *
 * @return string
 */
function ptcgdm_get_admin_ui_content() {
  $sections = [
    'pokemon'   => [
      'label'   => 'Pokémon Inventory',
      'content' => ptcgdm_capture_admin_ui_panel('ptcgdm_render_pokemon_inventory'),
    ],
    'one_piece' => [
      'label'   => 'One Piece Inventory',
      'content' => ptcgdm_capture_admin_ui_panel('ptcgdm_render_one_piece_inventory'),
    ],
    'orders' => [
      'label'   => 'Orders',
      'content' => ptcgdm_capture_admin_ui_panel('ptcgdm_render_admin_orders_panel'),
    ],
  ];

  ob_start();
  ?>
  <div class="ptcgdm-admin-ui">
    <style>
      .ptcgdm-admin-ui .wrap > h1,
      .ptcgdm-admin-ui .wrap > p.description { display: none; }
      .ptcgdm-admin-ui__shell { display: grid; grid-template-columns: 240px 1fr; gap: 16px; align-items: flex-start; }
      .ptcgdm-admin-ui__sidebar { background: #0f1218; border: 1px solid #1f2533; border-radius: 12px; padding: 12px; position: sticky; top: 16px; }
      .ptcgdm-admin-ui__tab { width: 100%; text-align: left; border: 1px solid #1f2533; background: #111725; color: #cfd6e6; padding: 10px 12px; border-radius: 10px; cursor: pointer; margin-bottom: 8px; font-weight: 600; }
      .ptcgdm-admin-ui__tab.is-active { background: linear-gradient(180deg, #28304a, #1b2034); border-color: #324061; color: #fff; }
      .ptcgdm-admin-ui__tab:last-child { margin-bottom: 0; }
      .ptcgdm-admin-ui__content { min-height: 360px; }
      .ptcgdm-admin-ui__panel { display: none; }
      .ptcgdm-admin-ui__panel.is-active { display: block; }
      .ptcgdm-orders__list { display: none; }
      .ptcgdm-orders__list.is-active, .ptcgdm-orders__detail-panel.is-active { display: block; }
      .ptcgdm-orders__detail-panel { display: none; background: #0f1218; border: 1px solid #1f2533; border-radius: 12px; padding: 16px; color: #cfd6e6; }
      .ptcgdm-orders__back { background: none; border: none; color: #7ea6ff; cursor: pointer; margin-bottom: 12px; font-weight: 600; }
      .ptcgdm-orders__table { width: 100%; border-collapse: collapse; background: #0f1218; border: 1px solid #1f2533; border-radius: 12px; overflow: hidden; }
      .ptcgdm-orders__table th, .ptcgdm-orders__table td { padding: 10px 12px; text-align: left; border-bottom: 1px solid #1f2533; color: #cfd6e6; }
      .ptcgdm-orders__table th { background: #111725; font-weight: 700; }
      .ptcgdm-orders__table tr:last-child td { border-bottom: none; }
      .ptcgdm-orders__table--nested { margin-top: 8px; }
      .ptcgdm-orders__meta { margin: 4px 0; }
      .ptcgdm-orders__totals p { margin: 4px 0; }
      .ptcgdm-product-link { background: none; border: none; color: #7ea6ff; cursor: pointer; padding: 0; text-align: left; text-decoration: underline; font: inherit; }
      .ptcgdm-order-detail-btn { background: #1b2034; border: 1px solid #324061; color: #fff; padding: 6px 10px; border-radius: 8px; cursor: pointer; }
      .ptcgdm-order-status-form { margin-top: 12px; }
      .ptcgdm-order-status__message { margin-left: 8px; }
      .ptcgdm-orders__empty { padding: 12px; background: #0f1218; border: 1px solid #1f2533; border-radius: 12px; color: #cfd6e6; }
      .ptcgdm-product-popover { position: absolute; display: none; background: #0f1218; border: 1px solid #1f2533; border-radius: 8px; padding: 6px; box-shadow: 0 10px 30px rgba(0,0,0,0.35); z-index: 9999; }
      .ptcgdm-product-popover img { display: block; width: 180px; height: auto; }
      @media (max-width: 900px) {
        .ptcgdm-admin-ui__shell { grid-template-columns: 1fr; }
        .ptcgdm-admin-ui__sidebar { position: static; }
        .ptcgdm-admin-ui__tab { display: inline-block; width: auto; margin-right: 8px; }
      }
    </style>

    <div class="ptcgdm-admin-ui__shell">
      <aside class="ptcgdm-admin-ui__sidebar" aria-label="Inventory navigation">
        <?php $first = true; foreach ($sections as $slug => $section) : ?>
          <button type="button" class="ptcgdm-admin-ui__tab<?php echo $first ? ' is-active' : ''; ?>" data-panel="<?php echo esc_attr($slug); ?>">
            <?php echo esc_html($section['label']); ?>
          </button>
        <?php $first = false; endforeach; ?>
      </aside>

      <main class="ptcgdm-admin-ui__content">
        <?php $first = true; foreach ($sections as $slug => $section) : ?>
          <div id="ptcgdm-panel-<?php echo esc_attr($slug); ?>" class="ptcgdm-admin-ui__panel<?php echo $first ? ' is-active' : ''; ?>">
            <?php echo $section['content']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
          </div>
        <?php $first = false; endforeach; ?>
      </main>
    </div>

    <script>
      (function() {
        const wrapper = document.currentScript.closest('.ptcgdm-admin-ui');
        if (!wrapper) return;

        const tabs = Array.from(wrapper.querySelectorAll('.ptcgdm-admin-ui__tab'));
        const panels = Array.from(wrapper.querySelectorAll('.ptcgdm-admin-ui__panel'));

        const activate = (slug) => {
          tabs.forEach((btn) => {
            const isActive = btn.dataset.panel === slug;
            btn.classList.toggle('is-active', isActive);
          });

          panels.forEach((panel) => {
            const isActive = panel.id === 'ptcgdm-panel-' + slug;
            panel.classList.toggle('is-active', isActive);
          });
        };

        tabs.forEach((btn) => {
          btn.addEventListener('click', () => {
            const slug = btn.dataset.panel;
            if (!slug) return;
            activate(slug);
          });
        });

        const ordersWrapper = wrapper.querySelector('.ptcgdm-orders');
        if (ordersWrapper) {
          const listView = ordersWrapper.querySelector('.ptcgdm-orders__list');
          const detailPanels = Array.from(ordersWrapper.querySelectorAll('.ptcgdm-orders__detail-panel'));
          const viewButtons = Array.from(ordersWrapper.querySelectorAll('.ptcgdm-order-detail-btn'));
          const backButtons = Array.from(ordersWrapper.querySelectorAll('.ptcgdm-orders__back'));
          const statusForms = Array.from(ordersWrapper.querySelectorAll('.ptcgdm-order-status-form'));
          const productLinks = Array.from(ordersWrapper.querySelectorAll('.ptcgdm-product-link'));

          let productPopover = null;
          const ensurePopover = () => {
            if (productPopover) return productPopover;
            productPopover = document.createElement('div');
            productPopover.className = 'ptcgdm-product-popover';

            const img = document.createElement('img');
            img.alt = 'Product image';
            productPopover.appendChild(img);

            document.body.appendChild(productPopover);
            return productPopover;
          };

          const hidePopover = () => {
            if (productPopover) {
              productPopover.style.display = 'none';
            }
          };

          const showList = () => {
            if (listView) {
              listView.classList.add('is-active');
            }
            detailPanels.forEach((panel) => panel.classList.remove('is-active'));
            hidePopover();
          };

          const showDetail = (orderId) => {
            if (listView) {
              listView.classList.remove('is-active');
            }
            detailPanels.forEach((panel) => {
              const isMatch = panel.id === 'ptcgdm-order-detail-' + orderId;
              panel.classList.toggle('is-active', isMatch);
            });
            hidePopover();
          };

          viewButtons.forEach((btn) => {
            btn.addEventListener('click', () => {
              const id = btn.dataset.orderId;
              if (!id) return;
              showDetail(id);
            });
          });

          backButtons.forEach((btn) => {
            btn.addEventListener('click', () => {
              showList();
            });
          });

          const ajaxUrl = ordersWrapper.dataset.ajaxUrl;
          const nonce = ordersWrapper.dataset.nonce;

          const updateStatusLabel = (orderId, label) => {
            const row = ordersWrapper.querySelector('#ptcgdm-order-row-' + orderId + ' .ptcgdm-order-status');
            if (row && label) {
              row.textContent = label;
            }

            const detailStatus = ordersWrapper.querySelector('#ptcgdm-order-detail-' + orderId + ' .ptcgdm-order-detail-status');
            if (detailStatus && label) {
              detailStatus.textContent = label;
            }
          };

          statusForms.forEach((form) => {
            form.addEventListener('submit', async (event) => {
              event.preventDefault();

              if (!ajaxUrl || !nonce) return;

              const orderId = form.dataset.orderId;
              const select = form.querySelector('select[name="status"]');
              const message = form.querySelector('.ptcgdm-order-status__message');
              const setMessage = (text) => { if (message) message.textContent = text; };

              if (!orderId || !select) return;

              setMessage('Saving...');

              try {
                const response = await fetch(ajaxUrl, {
                  method: 'POST',
                  headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
                  body: new URLSearchParams({
                    action: 'ptcgdm_update_order_status',
                    nonce,
                    order_id: orderId,
                    status: select.value,
                  }),
                });

                const data = await response.json();
                if (data && data.success && data.data && data.data.label) {
                  updateStatusLabel(orderId, data.data.label);
                  setMessage('Updated.');
                } else {
                  setMessage(data && data.data ? data.data : 'Unable to update status.');
                }
              } catch (err) {
                setMessage('Request failed.');
              }
            });
          });

          productLinks.forEach((link) => {
            link.addEventListener('click', (event) => {
              event.stopPropagation();

              const url = link.dataset.imageUrl;
              if (!url) {
                hidePopover();
                return;
              }

              const pop = ensurePopover();
              const img = pop.querySelector('img');
              if (img) {
                img.src = url;
              }

              const rect = link.getBoundingClientRect();
              const top = rect.top + window.scrollY + rect.height + 6;
              const left = rect.left + window.scrollX;

              pop.style.top = top + 'px';
              pop.style.left = left + 'px';
              pop.style.display = 'block';
            });
          });

          document.addEventListener('click', (event) => {
            if (!productPopover) return;

            const target = event.target;
            if (target && (target.closest('.ptcgdm-product-popover') || target.closest('.ptcgdm-product-link'))) {
              return;
            }

            hidePopover();
          });
        }
      })();
    </script>
  </div>
  <?php

  return ob_get_clean();
}

/**
 * Echo the inventory UI within a dedicated wrapper for the front-end page.
 */
function ptcgdm_render_admin_ui_page() {
  echo ptcgdm_get_admin_ui_content();
}

/**
 * Shortcode handler to embed the admin UI on a standard WordPress page.
 *
 * @return string
 */
function ptcgdm_render_admin_ui_shortcode() {
  ob_start();
  ptcgdm_render_admin_ui_page();
  return ob_get_clean();
}

/**
 * Register the shortcode for the Admin UI page.
 */
function ptcgdm_register_admin_ui_shortcode() {
  add_shortcode('ptcg_admin_ui', 'ptcgdm_render_admin_ui_shortcode');
}
add_action('init', 'ptcgdm_register_admin_ui_shortcode');

/**
 * Handle order status updates from the Admin UI page.
 */
function ptcgdm_handle_update_order_status() {
  check_ajax_referer('ptcgdm_update_order_status', 'nonce');

  $order_id = isset($_POST['order_id']) ? absint(wp_unslash($_POST['order_id'])) : 0;
  $status   = isset($_POST['status']) ? sanitize_key(wp_unslash($_POST['status'])) : '';

  if ($order_id <= 0 || empty($status)) {
    wp_send_json_error(__('Invalid order or status.', 'woocommerce'));
  }

  if (strpos($status, 'wc-') !== 0) {
    $status = 'wc-' . $status;
  }

  $valid_statuses = array_keys(wc_get_order_statuses());
  if (!in_array($status, $valid_statuses, true)) {
    wp_send_json_error(__('Status not allowed.', 'woocommerce'));
  }

  $order = wc_get_order($order_id);
  if (!$order instanceof WC_Order) {
    wp_send_json_error(__('Order not found.', 'woocommerce'));
  }

  $order->set_status($status);
  $order->save();

  wp_send_json_success([
    'status' => $status,
    'label'  => wc_get_order_status_name($status),
  ]);
}
add_action('wp_ajax_ptcgdm_update_order_status', 'ptcgdm_handle_update_order_status');

/**
 * Ensure a public page exists that renders the Admin UI via shortcode.
 */
function ptcgdm_ensure_admin_ui_page_exists() {
  $stored_id = (int) get_option('ptcgdm_admin_ui_page_id', 0);

  if ($stored_id > 0) {
    $existing_page = get_post($stored_id);
    if ($existing_page instanceof WP_Post && $existing_page->post_status !== 'trash') {
      return;
    }
  }

  $page = get_page_by_path('ptcg-admin-ui');
  if ($page instanceof WP_Post && $page->post_status !== 'trash') {
    update_option('ptcgdm_admin_ui_page_id', $page->ID);
    return;
  }

  $page_id = wp_insert_post([
    'post_title'   => 'Admin UI',
    'post_name'    => 'ptcg-admin-ui',
    'post_status'  => 'publish',
    'post_type'    => 'page',
    'post_content' => '[ptcg_admin_ui]',
  ], true);

  if (!is_wp_error($page_id) && $page_id > 0) {
    update_option('ptcgdm_admin_ui_page_id', $page_id);
  }
}
