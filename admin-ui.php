<?php
/**
 * Additional admin UI for inventory management.
 */
if (!defined('ABSPATH')) exit;

/**
 * Render the cloned inventory management interface on a dedicated admin page.
 */
function ptcgdm_render_admin_ui_page() {
  if (function_exists('ptcgdm_render_inventory')) {
    ptcgdm_render_inventory();
    return;
  }

  echo '<div class="wrap"><h1>Inventory Management</h1><p>Inventory UI is unavailable.</p></div>';
}

add_action('admin_menu', function () {
  add_menu_page(
    'Admin UI',
    'Admin UI',
    'manage_options',
    'ptcg-admin-ui',
    'ptcgdm_render_admin_ui_page',
    'dashicons-layout',
    59
  );
});
