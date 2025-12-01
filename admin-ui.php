<?php
/**
 * Front-end Admin UI page for inventory management.
 */

if (!defined('ABSPATH')) exit;

/**
 * Build the inventory management markup for the public page/shortcode.
 *
 * @return string
 */
function ptcgdm_get_admin_ui_content() {
  if (function_exists('ptcgdm_render_inventory')) {
    ob_start();
    ptcgdm_render_inventory();
    return ob_get_clean();
  }

  return '<div class="wrap"><h1>Inventory Management</h1><p>Inventory UI is unavailable.</p></div>';
}

/**
 * Echo the inventory UI within a dedicated wrapper for the front-end page.
 */
function ptcgdm_render_admin_ui_page() {
  echo '<div class="ptcgdm-admin-ui">' . ptcgdm_get_admin_ui_content() . '</div>';
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
