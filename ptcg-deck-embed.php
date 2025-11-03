<?php
/**
 * Plugin Name: PTCG Deck Showcase
 * Description: Admin inventory management for WooCommerce decks.
 * Version: 1.0.0
 */
if (!defined('ABSPATH')) exit;

define('PTCGDM_DIR', plugin_dir_path(__FILE__));
define('PTCGDM_URL', plugin_dir_url(__FILE__));
define('PTCGDM_DATA_DIR', PTCGDM_DIR . 'pokemon-tcg-data');
define('PTCGDM_PRODUCT_IMAGE_SIZE', 512);
define('PTCGDM_INVENTORY_SUBDIR', 'card-inventory');
define('PTCGDM_INVENTORY_FILENAME', 'card-inventory.json');
define('PTCGDM_INVENTORY_VARIANTS', [
  'normal'      => 'Normal',
  'foil'        => 'Foil',
  'reverseFoil' => 'Reverse Foil',
  'stamped'     => 'Stamped',
]);


function ptcgdm_sanitize_json_filename($filename) {
  $filename = basename((string) $filename);
  if ($filename === '' || pathinfo($filename, PATHINFO_EXTENSION) !== 'json') {
    return '';
  }
  if (function_exists('validate_file') && validate_file($filename) !== 0) {
    return '';
  }
  return $filename;
}
function ptcgdm_get_inventory_dir() {
  return PTCGDM_DIR . PTCGDM_INVENTORY_SUBDIR;
}

function ptcgdm_get_inventory_url() {
  return PTCGDM_URL . PTCGDM_INVENTORY_SUBDIR;
}

function ptcgdm_ensure_inventory_directory() {
  $dir = ptcgdm_get_inventory_dir();
  if (!file_exists($dir)) {
    wp_mkdir_p($dir);
  }
  return $dir;
}

register_activation_hook(__FILE__, function () {
  if (!file_exists(PTCGDM_DATA_DIR)) {
    wp_mkdir_p(PTCGDM_DATA_DIR);
  }
  ptcgdm_ensure_inventory_directory();
});

add_action('admin_menu', function () {
  add_menu_page(
    'PTCG Inventory',
    'Add Inventory',
    'manage_options',
    'ptcg-add-inventory',
    'ptcgdm_render_inventory',
    'dashicons-archive',
    58
  );
});

function ptcgdm_collect_saved_entries($dir, $url_base, array $args = []) {
  $args = wp_parse_args($args, [
    'pattern'   => '*.json',
    'filenames' => [],
    'label_map' => [],
  ]);

  $dir = trailingslashit($dir);
  $url_base = trailingslashit($url_base);

  if (!file_exists($dir)) {
    return [];
  }

  $paths = [];

  if (!empty($args['filenames'])) {
    foreach ($args['filenames'] as $filename) {
      $sanitized = ptcgdm_sanitize_json_filename($filename);
      if (!$sanitized) {
        continue;
      }
      $path = $dir . $sanitized;
      if (file_exists($path)) {
        $paths[] = $path;
      }
    }
  } else {
    $glob = glob($dir . $args['pattern']);
    if ($glob) {
      $paths = $glob;
    }
  }

  $saved = [];

  foreach ($paths as $file) {
    $filename = basename($file);
    $content  = @file_get_contents($file);
    $data     = $content ? json_decode($content, true) : null;
    $title    = '';
    $format   = '';

    if (!empty($args['label_map'][$filename])) {
      $title = $args['label_map'][$filename];
    } elseif (is_array($data)) {
      if (!empty($data['name']) && is_string($data['name'])) {
        $title = $data['name'];
      }
      if (!empty($data['format']) && is_string($data['format'])) {
        $format = $data['format'];
      }
    }

    $saved[] = [
      'filename' => $filename,
      'title'    => $title,
      'format'   => $format,
      'mtime'    => @filemtime($file) ?: 0,
      'url'      => $url_base . rawurlencode($filename),
    ];
  }

  if ($saved) {
    usort($saved, function ($a, $b) {
      return $b['mtime'] <=> $a['mtime'];
    });
  }

  return $saved;
}

function ptcgdm_render_builder(){
  if (!current_user_can('manage_options')) return;

  $mode = 'inventory';

  $dir = ptcgdm_ensure_inventory_directory();
  $url_base = ptcgdm_get_inventory_url();
  $page_title = 'PTCG Deck – Card Inventory';
  $page_description = 'Maintain a single card inventory list using the local dataset.';
  $load_label = 'Load Saved Inventory';
  $select_placeholder = 'Choose saved inventory…';
  $load_button_label = 'Load Inventory';
  $save_button_label = 'Add to Inventory';
  $clear_button_label = 'Clear Buffer';
  $section_heading = 'Buffer';
  $saved_inventory_heading = 'Saved Inventory';
  $load_message_empty = 'No inventory saved yet. Save to create the inventory file.';
  $load_message_ready = 'Inventory found. Loading automatically…';
  $save_action = 'ptcgdm_save_inventory';
  $nonce_action = 'ptcgdm_save_inventory';
  $filename_pattern = '%s.json';
  $fixed_filename = PTCGDM_INVENTORY_FILENAME;
  $default_basename = 'card-inventory';
  $force_option_label = 'Card Inventory';
  $fallback_entity_label = 'inventory';
  $loading_message = 'Loading inventory…';
  $save_success_message = "Inventory updated!\nURL:\n";
  $default_entry_name = 'Untitled Inventory';
  $saved_args = [
    'filenames' => [PTCGDM_INVENTORY_FILENAME],
    'label_map' => [PTCGDM_INVENTORY_FILENAME => 'Card Inventory'],
  ];
  $inventory_path = trailingslashit($dir) . PTCGDM_INVENTORY_FILENAME;
  $auto_load_url = '';
  if (file_exists($inventory_path)) {
    $auto_load_url = trailingslashit($url_base) . rawurlencode(PTCGDM_INVENTORY_FILENAME);
  }

  $saved_decks = ptcgdm_collect_saved_entries($dir, $url_base, $saved_args);
  $load_message = empty($saved_decks) ? $load_message_empty : $load_message_ready;
  if ($mode === 'inventory' && empty($auto_load_url) && !empty($saved_decks[0]['url'])) {
    $auto_load_url = $saved_decks[0]['url'];
  }
  $nonce = wp_create_nonce($nonce_action);
  $delete_inventory_action = '';
  $delete_inventory_nonce  = '';
  $inventory_sort_default  = 'alpha';
  if ($mode === 'inventory') {
    $delete_inventory_action = 'ptcgdm_delete_inventory_card';
    $delete_inventory_nonce  = wp_create_nonce('ptcgdm_delete_inventory_card');
  }

  $data_base_url = esc_js(PTCGDM_URL . 'pokemon-tcg-data');
  $script_config = wp_json_encode([
    'saveAction'          => $save_action,
    'defaultBasename'     => $default_basename,
    'filenamePattern'     => $filename_pattern,
    'fixedFilename'       => $fixed_filename,
    'successMessage'      => $save_success_message,
    'loadButtonLabel'     => $load_button_label,
    'loadingMessage'      => $loading_message,
    'fallbackEntityLabel' => $fallback_entity_label,
    'forceOptionLabel'    => $force_option_label,
    'defaultEntryName'    => $default_entry_name,
    'autoLoadUrl'         => $auto_load_url,
    'mode'                => $mode,
    'deleteInventoryAction' => $delete_inventory_action,
    'deleteInventoryNonce'  => $delete_inventory_nonce,
    'inventorySortDefault'  => $inventory_sort_default,
  ], JSON_UNESCAPED_UNICODE);
  if (!is_string($script_config)) {
    $script_config = '{}';
  }
?>
  <div class="wrap">
    <h1><?php echo esc_html($page_title); ?></h1>
    <p class="description"><?php echo esc_html($page_description); ?></p>

    <style>
      :root { --bg:#0b0c10; --panel:#12151b; --ink:#e6e8ef; --muted:#9aa3b2; --chip:#1d2330; --line:#222734;}
      .ptcg * { box-sizing:border-box }
      .ptcg { background:var(--bg); color:var(--ink); margin-top:12px; padding:16px; border-radius:12px; border:1px solid var(--line) }
      .grid4{display:grid;grid-template-columns:1fr 1fr 1fr 1fr;gap:12px}
      label{display:block;font-size:12px;color:var(--muted);margin-bottom:6px}
      select,input,textarea{width:100%;background:#0f1218;border:1px solid #262c39;border-radius:12px;color:#e6e8ef;padding:10px 12px}
      .btn{background:linear-gradient(180deg,#1f6fee,#1748d0);border:0;border-radius:12px;padding:10px 14px;color:#fff;font-weight:700;cursor:pointer}
      .btn.secondary{background:#2a2f3e}
      .results{max-height:360px;overflow:auto;border:1px solid var(--line);border-radius:12px;background:#0f1218}
      .result{display:grid;grid-template-columns:1fr auto auto;gap:8px;align-items:center;padding:10px 12px;border-bottom:1px solid #1c2230}
      .result:last-child{border-bottom:none}
      .result .name{font-weight:700}
      table{width:100%;border-collapse:separate;border-spacing:0 8px}
      th,td{padding:10px 12px;background:#12151b;border:1px solid var(--line)}
      th:first-child,td:first-child{border-top-left-radius:10px;border-bottom-left-radius:10px}
      th:last-child,td:last-child{border-top-right-radius:10px;border-bottom-right-radius:10px}
      th{color:#cfd6e6;text-align:left;font-weight:700;background:#151925}
      .qtybox{display:flex;align-items:center;gap:6px}
      .qtybox input{width:64px;text-align:center}
      .priceInput{width:80px;text-align:right}
      .chip{display:inline-block;background:var(--chip);border:1px solid #2a3142;color:#cfd6e6;border-radius:999px;padding:4px 8px;font-size:12px}
      .row{display:flex;gap:10px;flex-wrap:wrap}
      .muted{color:var(--muted)}
      .bulk-input{min-height:180px;font-family:"Fira Code","SFMono-Regular",Consolas,monospace;font-size:13px;line-height:1.5;resize:vertical}
      .bulk-actions{display:flex;align-items:center;gap:12px;flex-wrap:wrap;margin-top:8px}
      .bulk-actions .spacer{flex:1 1 auto}
      .bulk-status{font-size:13px}
      .bulk-status.error{color:#f28b82}
      .btn.danger{background:linear-gradient(180deg,#ff4d4f,#d9363e)}
    </style>

    <div class="ptcg" id="ptcg-root">
      <div id="dbStatusRow"><strong>Status:</strong> <span id="dbStatus" class="muted">Loading local dataset…</span></div>

      <div class="grid4" style="margin-top:12px">
        <div><label>Deck Name</label><input id="deckName" placeholder="My Deck"></div>
        <div><label>Format</label><input id="deckFormat" placeholder="Standard"></div>
        <div>
          <label>Set</label>
          <select id="selSet" disabled><option value="">Loading…</option></select>
        </div>
        <div>
          <label>Supertype</label>
          <select id="selSupertype" disabled><option value="">Choose…</option><option>Pokémon</option><option>Trainer</option><option>Energy</option></select>
        </div>
      </div>

      <div class="row" style="margin-top:12px;align-items:flex-end">
        <div style="flex:1 1 420px">
          <p class="description" id="deckLoadStatus" data-default="<?php echo esc_attr($load_message); ?>"><?php echo esc_html($load_message); ?></p>
        </div>
      </div>

      <div style="margin-top:16px">
        <label for="bulkInput">Bulk Add by Code</label>
        <textarea id="bulkInput" class="bulk-input" rows="8" placeholder="4 Gholdengo ex PAR 139&#10;3 Pokégear 3.0 SVI 186&#10;2 Rare Candy SUM 129" spellcheck="false"></textarea>
        <p class="description">Enter one card per line using “Qty Name SET Number”. Examples: <code>4 Gholdengo ex PAR 139</code>, <code>2 Rare Candy SUM 129</code></p>
        <div class="bulk-actions">
          <button id="btnBulkAdd" class="btn secondary" disabled>Add cards</button>
          <button id="btnClearDeck" class="btn danger" disabled><?php echo esc_html($clear_button_label); ?></button>
          <div class="spacer"></div>
          <div id="bulkStatus" class="muted bulk-status"></div>
        </div>
      </div>

      <div class="row" style="margin-top:12px;align-items:flex-end">
        <div style="flex:1 1 600px"><label>Name (type to filter)</label><input id="nameInput" placeholder="e.g., Pikachu, Nest Ball"></div>
        <div><label>Qty</label><input id="selQty" type="number" min="1" max="60" value="1"></div>
        <div><button id="btnAdd" class="btn" disabled>Add</button></div>
      </div>

      <div class="muted" id="filterCount" style="margin:8px 0">Pick a set & supertype to begin.</div>
      <div id="results" class="results"></div>

      <h3 style="margin:16px 0 8px"><?php echo esc_html($section_heading); ?></h3>
      <div style="overflow:auto">
        <table id="deckTable"><thead><tr><th>#</th><th>Name</th><th>Set</th><th>No.</th><th>Supertype</th><th>Qty (Normal)</th><th>Price (Normal)</th><th>Qty (Foil)</th><th>Price (Foil)</th><th>Qty (Reverse Foil)</th><th>Price (Reverse Foil)</th><th>Qty (Stamped)</th><th>Price (Stamped)</th><th>Actions</th></tr></thead><tbody id="deckBody"><tr><td colspan="14" class="muted">No cards yet.</td></tr></tbody></table>
      </div>

      <div class="row" style="margin-top:12px;align-items:center;justify-content:space-between">
        <div>
          <button id="btnSaveDeck" class="btn secondary" disabled><?php echo esc_html($save_button_label); ?></button>
        </div>
        <div><strong>Buffer Total:</strong> <span id="deckTotals" class="chip">0 cards</span></div>
      </div>

      <h3 style="margin:24px 0 8px"><?php echo esc_html($saved_inventory_heading ?? 'Saved Inventory'); ?></h3>
      <div class="row" style="margin:0 0 8px;align-items:center;justify-content:flex-end">
        <div style="display:flex;align-items:center;gap:8px">
          <label for="inventorySortMode" style="margin:0;font-size:12px;color:var(--muted);">Sort saved cards</label>
          <select id="inventorySortMode">
            <option value="alpha">Name (A→Z)</option>
            <option value="number">Card No.</option>
          </select>
        </div>
      </div>
      <div style="overflow:auto">
        <table id="inventoryDataTable"><thead><tr><th>#</th><th>Name</th><th>Set</th><th>No.</th><th>Supertype</th><th>Qty (Normal)</th><th>Price (Normal)</th><th>Qty (Foil)</th><th>Price (Foil)</th><th>Qty (Reverse Foil)</th><th>Price (Reverse Foil)</th><th>Qty (Stamped)</th><th>Price (Stamped)</th><th>Actions</th></tr></thead><tbody id="inventoryDataBody"><tr><td colspan="14" class="muted">No inventory saved yet.</td></tr></tbody></table>
      </div>

      <div class="row" style="margin-top:12px;align-items:center;justify-content:flex-end">
        <div><strong>Saved Total:</strong> <span id="inventoryTotals" class="chip">0 cards</span></div>
      </div>
    </div>

    <script>
    (function(){
      const DATA_BASE = '<?php echo $data_base_url; ?>'; // plugin asset URL
      const SAVE_NONCE = '<?php echo esc_js($nonce); ?>';
      const AJAX_URL = '<?php echo admin_url('admin-ajax.php'); ?>';
      const SAVE_CONFIG = Object.assign({
        saveAction: 'ptcgdm_save_inventory',
        defaultBasename: 'card-inventory',
        filenamePattern: '%s.json',
        fixedFilename: '<?php echo esc_js(PTCGDM_INVENTORY_FILENAME); ?>',
        successMessage: 'Inventory updated!\nURL:\n',
        loadButtonLabel: 'Load Inventory',
        loadingMessage: 'Loading inventory…',
        fallbackEntityLabel: 'inventory',
        forceOptionLabel: 'Card Inventory',
        defaultEntryName: 'Untitled Inventory',
        autoLoadUrl: '',
        deleteInventoryAction: 'ptcgdm_delete_inventory_card',
        deleteInventoryNonce: '',
        inventorySortDefault: 'alpha',
      }, <?php echo $script_config; ?>);
      const MODE = 'inventory';
      const IS_INVENTORY = true;
      const INVENTORY_BUFFER_MIN = -999;
      const INVENTORY_BUFFER_MAX = 999;
      const INVENTORY_VARIANTS = [
        { key: 'normal', label: 'Normal' },
        { key: 'foil', label: 'Foil' },
        { key: 'reverseFoil', label: 'Reverse Foil' },
        { key: 'stamped', label: 'Stamped' },
      ];
      const INVENTORY_SAVED_EMPTY_COLSPAN = 5 + INVENTORY_VARIANTS.length * 2 + 1;
      const INVENTORY_NUMERIC_COLLATOR = (typeof Intl !== 'undefined' && typeof Intl.Collator === 'function')
        ? new Intl.Collator(undefined, { numeric: true, sensitivity: 'base' })
        : null;

      const SERIES_CONFIG = {
        'Sun & Moon': ['smp','sm1','sm2','sm3','sm35','sm4','sm5','sm6','sm7','sm75','sm8','sm9','det1','sm10','sm11','sm115','sma','sm12'],
        'Sword & Shield': ['swshp','swsh1','swsh2','swsh3','swsh35','swsh4','swsh45','swsh45sv','swsh5','swsh6','swsh7','swsh8','swsh9','swsh9tg','swsh10','swsh10tg','pgo','swsh11','swsh11tg','swsh12','swsh12tg','swsh12pt5','swsh12pt5gg'],
        'Scarlet & Violet': ['svp','sve','sv1','sv2','sv3','sv3pt5','sv4','sv4pt5','sv5','sv6','sv6pt5','sv7','sv8','sv8pt5','sv9','sv10','zsv10pt5','rsv10pt5'],
        'Mega Evolution': ['me1']
      };

      const ALLOWED_GROUPS = Object.fromEntries(
        Object.entries(SERIES_CONFIG).map(([series, ids]) => [
          series,
          ids.map(id => [id, ''])
        ])
      );
      const ALLOWED_SET_IDS = new Set(Object.values(ALLOWED_GROUPS).flat().map(([id])=>id));

      const byId = new Map();
      const setCodeLookup = new Map();
      const setNumberIndex = new Map();
      const setNameIndex = new Map();
      const BASIC_ENERGY_MAP = {
        D: { display: 'Dark', canonical: 'Darkness' },
        M: { display: 'Metal', canonical: 'Metal' },
        L: { display: 'Lightning', canonical: 'Lightning' },
        G: { display: 'Grass', canonical: 'Grass' },
        F: { display: 'Fighting', canonical: 'Fighting' },
        W: { display: 'Water', canonical: 'Water' },
        R: { display: 'Fire', canonical: 'Fire' },
        P: { display: 'Psychic', canonical: 'Psychic' }
      };
      let dataReady=false;
      const setMetadataCache = new Map();
      let setIndexPromise = null;

      const els = {
        dbStatusRow: document.getElementById('dbStatusRow'),
        dbStatus: document.getElementById('dbStatus'),
        deckName: document.getElementById('deckName'),
        deckFormat: document.getElementById('deckFormat'),
        savedDeckSelect: document.getElementById('savedDeckSelect'),
        btnLoadDeck: document.getElementById('btnLoadDeck'),
        deckLoadStatus: document.getElementById('deckLoadStatus'),
        selSet: document.getElementById('selSet'),
        selSupertype: document.getElementById('selSupertype'),
        nameInput: document.getElementById('nameInput'),
        selQty: document.getElementById('selQty'),
        btnAdd: document.getElementById('btnAdd'),
        btnClearDeck: document.getElementById('btnClearDeck'),
        filterCount: document.getElementById('filterCount'),
        results: document.getElementById('results'),
        deckBody: document.getElementById('deckBody'),
        deckTotals: document.getElementById('deckTotals'),
        btnSaveDeck: document.getElementById('btnSaveDeck'),
        bulkInput: document.getElementById('bulkInput'),
        btnBulkAdd: document.getElementById('btnBulkAdd'),
        bulkStatus: document.getElementById('bulkStatus'),
        inventoryBody: document.getElementById('inventoryDataBody'),
        inventoryTotals: document.getElementById('inventoryTotals'),
        inventorySortMode: document.getElementById('inventorySortMode')
      };
      const deck=[]; const deckMap=new Map();
      const inventoryData = [];
      const inventorySavedMap = new Map();
      let inventorySortMode = (SAVE_CONFIG.inventorySortDefault === 'number') ? 'number' : 'alpha';

      if (els.btnSaveDeck) {
        els.btnSaveDeck.dataset.defaultLabel = els.btnSaveDeck.textContent || '';
      }

      let isSavingDeck = false;

      function clampBufferQty(value){
        if(IS_INVENTORY){
          let qty = parseInt(value, 10);
          if (Number.isNaN(qty)) qty = 0;
          if (qty > INVENTORY_BUFFER_MAX) return INVENTORY_BUFFER_MAX;
          if (qty < INVENTORY_BUFFER_MIN) return INVENTORY_BUFFER_MIN;
          return qty;
        }
        let qty = parseInt(value, 10);
        if (Number.isNaN(qty) || qty <= 0) qty = 1;
        if (qty > 60) return 60;
        return qty;
      }

      function adjustBufferQty(current, delta){
        if(IS_INVENTORY){
          const base = Number.isFinite(current) ? current : 0;
          const next = base + delta;
          if (next > INVENTORY_BUFFER_MAX) return INVENTORY_BUFFER_MAX;
          if (next < INVENTORY_BUFFER_MIN) return INVENTORY_BUFFER_MIN;
          return next;
        }
        const base = Number.isFinite(current) && current > 0 ? current : 1;
        const next = base + delta;
        if (next > 60) return 60;
        if (next < 1) return 1;
        return next;
      }

      function parsePriceValue(value){
        if(value === null || value === undefined) return null;
        if(typeof value === 'number'){
          if(!Number.isFinite(value) || value < 0) return null;
          return Math.round(value * 100) / 100;
        }
        const trimmed = String(value).trim();
        if(!trimmed) return null;
        const normalised = trimmed.replace(',', '.');
        const num = Number.parseFloat(normalised);
        if(!Number.isFinite(num) || num < 0) return null;
        return Math.round(num * 100) / 100;
      }

      function parsePriceInput(value){
        if(value === null || value === undefined) return null;
        if(typeof value === 'string' && value.trim() === '') return null;
        return parsePriceValue(value);
      }

      function formatPriceInputValue(price){
        return Number.isFinite(price) && price >= 0 ? price.toFixed(2) : '';
      }

      function compareNumericStrings(a, b){
        const aString = (a ?? '').toString();
        const bString = (b ?? '').toString();
        if (INVENTORY_NUMERIC_COLLATOR) {
          return INVENTORY_NUMERIC_COLLATOR.compare(aString, bString);
        }
        return aString.localeCompare(bString, undefined, { numeric: true, sensitivity: 'base' });
      }

      function formatPriceDisplay(price){
        return Number.isFinite(price) && price >= 0 ? price.toFixed(2) : '—';
      }

      function ensureInventoryVariants(entry){
        if(!entry) return {};
        if(!entry.variants || typeof entry.variants !== 'object'){
          entry.variants = {};
        }
        return entry.variants;
      }

      function getInventoryVariant(entry, key){
        if(!entry || !key) return null;
        const variants = ensureInventoryVariants(entry);
        if(!Object.prototype.hasOwnProperty.call(variants, key) || typeof variants[key] !== 'object'){
          variants[key] = { qty: 0, price: null };
        }
        return variants[key];
      }

      function cloneInventoryVariantData(source){
        if(!source || typeof source !== 'object') return null;
        const out = {};
        INVENTORY_VARIANTS.forEach(({ key })=>{
          const value = source[key];
          if(!value || typeof value !== 'object') return;
          const qty = Number.isFinite(value.qty) ? value.qty : parseInt(value.qty, 10);
          const price = parsePriceValue(value.price);
          const includeQty = Number.isFinite(qty);
          const includePrice = Number.isFinite(price);
          if(!includeQty && !includePrice) return;
          out[key] = {
            qty: includeQty ? qty : 0,
          };
          if(includePrice){
            out[key].price = price;
          }
        });
        return out;
      }

      function sumInventoryVariantQuantities(entry){
        if(!entry) return 0;
        const variants = entry.variants && typeof entry.variants === 'object' ? entry.variants : {};
        let total = 0;
        INVENTORY_VARIANTS.forEach(({ key })=>{
          const value = variants[key];
          if(!value || typeof value !== 'object') return;
          const qty = Number.isFinite(value.qty) ? value.qty : parseInt(value.qty, 10);
          if(Number.isFinite(qty)){
            total += qty;
          }
        });
        entry.qty = total;
        return total;
      }

      function cleanInventoryVariant(entry, key){
        if(!entry || !entry.variants || typeof entry.variants !== 'object') return;
        const data = entry.variants[key];
        if(!data || typeof data !== 'object') return;
        const qty = Number.isFinite(data.qty) ? data.qty : parseInt(data.qty, 10);
        const price = parsePriceValue(data.price);
        if(!Number.isFinite(qty) || qty === 0){
          if(Number.isFinite(price)){
            entry.variants[key] = { qty: Number.isFinite(qty) ? qty : 0, price };
          }else{
            delete entry.variants[key];
          }
        }else{
          entry.variants[key] = { qty, price: Number.isFinite(price) ? price : null };
        }
      }

      function getSavedInventoryVariant(id, key){
        if(!id || !key) return null;
        const saved = inventorySavedMap.get(id);
        if(!saved || typeof saved !== 'object') return null;
        const variants = saved.variants && typeof saved.variants === 'object' ? saved.variants : {};
        const value = variants[key];
        if(!value || typeof value !== 'object') return null;
        const qty = Number.isFinite(value.qty) ? value.qty : parseInt(value.qty, 10);
        const price = parsePriceValue(value.price);
        const out = {};
        if(Number.isFinite(qty)) out.qty = qty;
        if(Number.isFinite(price)) out.price = price;
        return Object.keys(out).length ? out : null;
      }
      let deckJsonCache='';
      let defaultLoadMessage = els.deckLoadStatus ? (els.deckLoadStatus.dataset?.default || els.deckLoadStatus.textContent || '') : '';

      document.addEventListener('DOMContentLoaded', async ()=>{
        try {
          await populateSetDropdown();
        } catch (err) {
          console.error('Populate set dropdown failed', err);
        }
        loadDataset().then(()=>{
          dataReady=true;
          if (els.dbStatusRow) {
            els.dbStatusRow.hidden = true;
          } else if (els.dbStatus) {
            els.dbStatus.textContent='';
          }
          updateResults();
          if (deck.length) {
            renderDeckTable();
            updateJSON();
          }
          if (IS_INVENTORY) {
            renderInventoryDataTable();
          }
          updateBulkAddState();
        });
        els.selSet.addEventListener('change', updateResults);
        els.selSupertype.addEventListener('change', updateResults);
        els.nameInput.addEventListener('input', updateResults);
        els.nameInput.addEventListener('keydown', e=>{ if(e.key==='Enter'){ e.preventDefault(); addFirstMatch(); }});
        els.selQty.addEventListener('input', ()=>updateAddButton(0));
        els.btnAdd.addEventListener('click', addFirstMatch);
        els.btnClearDeck.addEventListener('click', clearDeck);
        els.btnSaveDeck.addEventListener('click', saveDeck);
        els.deckName.addEventListener('input', updateJSON);
        els.deckFormat.addEventListener('input', updateJSON);
        if (els.bulkInput) {
          els.bulkInput.addEventListener('input', updateBulkAddState);
        }
        if (els.btnBulkAdd) {
          els.btnBulkAdd.addEventListener('click', handleBulkAddInput);
        }
        if (IS_INVENTORY && els.inventoryBody) {
          els.inventoryBody.addEventListener('click', handleInventoryBodyClick);
        }
        if (IS_INVENTORY && els.inventorySortMode) {
          if (inventorySortMode !== 'number' && inventorySortMode !== 'alpha') {
            inventorySortMode = els.inventorySortMode.value === 'number' ? 'number' : 'alpha';
          }
          els.inventorySortMode.value = inventorySortMode;
          els.inventorySortMode.addEventListener('change', ()=>{
            const mode = els.inventorySortMode.value === 'number' ? 'number' : 'alpha';
            if (mode !== inventorySortMode) {
              inventorySortMode = mode;
              renderInventoryDataTable();
            }
          });
        }
        if (els.savedDeckSelect) {
          els.savedDeckSelect.addEventListener('change', ()=>updateLoadDeckButton(true));
        }
        if (els.btnLoadDeck) {
          els.btnLoadDeck.addEventListener('click', ()=>{
            const url = els.savedDeckSelect?.value;
            if (url) loadDeckFromUrl(url);
          });
        }
        if (defaultLoadMessage) setLoadStatus(defaultLoadMessage);
        updateLoadDeckButton(true);
        updateJSON();
        updateBulkAddState();
        if (SAVE_CONFIG.autoLoadUrl) {
          const loadOptions = IS_INVENTORY ? { updateInventory: true } : undefined;
          loadDeckFromUrl(SAVE_CONFIG.autoLoadUrl, loadOptions);
        }
      });

      async function populateSetDropdown(){
        const opt=['<option value="">Choose set…</option>'];
        for (const [group, arr] of Object.entries(ALLOWED_GROUPS)){
          const ids = arr.map(([id])=>id);
          const metas = await Promise.all(ids.map(id=>getSetMetadata(id)));
          const items = ids.map((id, idx)=>{
            const meta = metas[idx];
            const label = meta?.name || 'Unknown Set';
            return `<option value="${esc(id)}">${esc(label)}</option>`;
          }).join('');
          opt.push(`<optgroup label="${esc(group)}">${items}</optgroup>`);
        }
        els.selSet.innerHTML=opt.join('');
        els.selSet.disabled=false; els.selSupertype.disabled=false;
      }

      async function loadDataset(){
        const ids = Array.from(ALLOWED_SET_IDS); const q=[...ids]; let done=0,total=0;
        const worker=async()=>{ while(q.length){ const id=q.shift(); const n=await loadSet(id); total+=n; done++; } };
        await Promise.all(Array.from({length:8}, worker));
      }
      async function loadSet(setId){
        const paths=[`${DATA_BASE}/cards/en/${setId}.json`,`${DATA_BASE}/cards/${setId}.json`,`${DATA_BASE}/${setId}.json`];
        for(const url of paths){
          try{
            const r=await fetch(url,{cache:'no-cache'}); if(!r.ok) continue;
            const t=await r.text();
            let arr=null; try{ const j=JSON.parse(t); if(Array.isArray(j))arr=j; else if(Array.isArray(j?.data))arr=j.data; else if(Array.isArray(j?.cards))arr=j.cards; else { const vals=Object.values(j||{}).filter(v=>v&&typeof v==='object'); if(vals.length) arr=vals; } }catch{}
            if(!arr || !arr.length){ const lines=t.split(/\r?\n/).map(s=>s.trim()).filter(Boolean); if(lines.length>1 && !t.trim().startsWith('[')){ const parsed=[]; for(const ln of lines){ try{ parsed.push(JSON.parse(ln)); }catch{} } if(parsed.length) arr=parsed; } }
            if(!arr || !arr.length) continue;
            const setMeta = await getSetMetadata(setId);
            let added=0;
            for(const c of arr){
              if(!c?.id) continue;
              hydrateCardSet(c, setId, setMeta);
              registerCardNumberIndex(setId, c);
              registerCardNameIndex(setId, c);
              byId.set(c.id,c);
              added++;
            }
            return added;
          }catch{} }
        return 0;
      }

      async function getSetMetadata(setId){
        if(!setId) return null;
        const key = String(setId).toLowerCase();
        if(setMetadataCache.has(key)) return setMetadataCache.get(key);
        const index = await loadSetIndex();
        const match = index.find(item => String(item?.id || '').toLowerCase() === key) || null;
        registerSetMeta(match);
        setMetadataCache.set(key, match);
        return match;
      }

      async function loadSetIndex(){
        if(setIndexPromise) return setIndexPromise;
        const paths=[
          `${DATA_BASE}/sets/en.json`,
          `${DATA_BASE}/sets.json`,
          `${DATA_BASE}/sets/all.json`
        ];
        setIndexPromise = (async()=>{
          for(const url of paths){
            try{
              const r = await fetch(url,{cache:'no-cache'});
              if(!r.ok) continue;
              const text = await r.text();
              const sets = normaliseSetMetaJSON(text);
              if(sets.length){
                sets.forEach(registerSetMeta);
                return sets;
              }
            }catch(err){ console.warn('Set metadata load failed', url, err); }
          }
          console.warn('Set metadata index not found.');
          return [];
        })();
        return setIndexPromise;
      }

      function normaliseSetMetaJSON(text){
        try{
          const parsed = JSON.parse(text);
          if(Array.isArray(parsed)) return parsed;
          if(Array.isArray(parsed?.data)) return parsed.data;
          if(Array.isArray(parsed?.sets)) return parsed.sets;
          const values = Object.values(parsed || {}).filter(v=>Array.isArray(v));
          if(values.length) return values.flat();
        }catch(err){
          const lines=text.split(/\r?\n/).map(s=>s.trim()).filter(Boolean);
          const manual=[];
          for(const line of lines){
            try{ const item=JSON.parse(line); if(item && typeof item==='object') manual.push(item); }catch{}
          }
          if(manual.length) return manual;
        }
        return [];
      }

      function hydrateCardSet(card,setId,meta){
        if(!card) return;
        const current=card.set && typeof card.set==='object' ? { ...card.set } : {};
        const info=meta && typeof meta==='object' ? { ...meta } : {};
        const merged={ ...info, ...current };
        merged.id = merged.id || info.id || setId || '';
        if(info.images || current.images){
          merged.images = { ...(info.images || {}), ...(current.images || {}) };
        }
        if(info.legalities || current.legalities){
          merged.legalities = { ...(info.legalities || {}), ...(current.legalities || {}) };
        }
        card.set = merged;
      }

      function registerCardNumberIndex(setId, card){
        if(!setId || !card) return;
        const keyBase = makeSetKey(setId);
        if(!keyBase) return;
        const cardId = card.id;
        if(!cardId) return;
        const variants = normaliseNumberVariants(card.number);
        if(!variants.length) return;
        variants.forEach(variant => {
          const key = `${keyBase}|${variant}`;
          if(!setNumberIndex.has(key)){
            setNumberIndex.set(key, cardId);
          }
        });
      }

      function registerCardNameIndex(setId, card){
        if(!setId || !card?.id) return;
        const keyBase = makeSetKey(setId);
        if(!keyBase) return;
        const nameKey = makeNameKey(card.name);
        if(!nameKey) return;
        const key = `${keyBase}|${nameKey}`;
        if(!setNameIndex.has(key)){
          setNameIndex.set(key, card.id);
        }
      }

      function normaliseNumberVariants(value){
        const raw = String(value || '').trim();
        if(!raw) return [];
        const base = raw.replace(/\s+/g,'').toLowerCase();
        if(!base) return [];
        const variants = new Set([base]);
        if(/^\d+$/.test(base)){
          const intVal = parseInt(base, 10);
          if(!Number.isNaN(intVal)) variants.add(String(intVal));
        }
        const prefixMatch = base.match(/^([a-z]+)(\d+)$/);
        if(prefixMatch){
          const [, letters, digits] = prefixMatch;
          const intVal = parseInt(digits, 10);
          if(!Number.isNaN(intVal)) variants.add(letters + String(intVal));
        }
        const suffixMatch = base.match(/^(\d+)([a-z]+)$/);
        if(suffixMatch){
          const [, digits, letters] = suffixMatch;
          const intVal = parseInt(digits, 10);
          if(!Number.isNaN(intVal)) variants.add(String(intVal) + letters);
          const trimmedDigits = digits.replace(/^0+/, '');
          if(trimmedDigits) variants.add(trimmedDigits + letters);
        }
        return Array.from(variants).filter(Boolean);
      }

      function makeSetKey(id){
        return String(id || '').trim().toLowerCase();
      }

      function makeNameKey(name){
        const base = norm(name).replace(/\s+/g, ' ');
        return base;
      }

      function registerSetMeta(meta){
        if(!meta || typeof meta !== 'object') return;
        const setId = String(meta.id || '').trim();
        if(!setId) return;
        const variants = new Set();
        const upperId = setId.toUpperCase();
        variants.add(upperId);
        variants.add(upperId.replace(/\s+/g,''));
        variants.add(upperId.replace(/-/g,''));
        const lowerId = setId.toLowerCase();
        variants.add(lowerId.toUpperCase());
        variants.add(lowerId.replace(/\s+/g,'').toUpperCase());
        variants.add(lowerId.replace(/-/g,'').toUpperCase());
        variants.forEach(key => {
          if(key && !setCodeLookup.has(key)){
            setCodeLookup.set(key, setId);
          }
        });
        const codes = [];
        if(meta.ptcgoCode) codes.push(meta.ptcgoCode);
        if(meta.ptcgo_code) codes.push(meta.ptcgo_code);
        if(meta.code) codes.push(meta.code);
        if(meta.onlineCode) codes.push(meta.onlineCode);
        if(meta.tcgOnlineCode) codes.push(meta.tcgOnlineCode);
        if(Array.isArray(meta.abbreviations)) codes.push(...meta.abbreviations);
        if(typeof meta.abbreviation === 'string') codes.push(meta.abbreviation);
        codes.forEach(code => {
          const clean = String(code || '').trim();
          if(!clean) return;
          const upperCode = clean.toUpperCase();
          const codeVariants = new Set([upperCode, upperCode.replace(/\s+/g,''), upperCode.replace(/-/g,'')]);
          codeVariants.forEach(key => {
            if(key && !setCodeLookup.has(key)){
              setCodeLookup.set(key, setId);
            }
          });
        });
      }

      function getCardSetLabel(card){
        const name = (card?.set?.name || '').trim();
        return name || 'Unknown Set';
      }

      function updateResults(){
        if(!dataReady){ els.filterCount.textContent='Loading…'; els.results.innerHTML=''; els.btnAdd.disabled=true; return; }
        const setId=els.selSet.value, sup=els.selSupertype.value; const q=norm(els.nameInput.value);
        if(!setId || !sup){ els.filterCount.textContent='Pick set & supertype.'; els.results.innerHTML=''; els.btnAdd.disabled=true; return; }
        const supKey=norm(sup);
        const base=[]; for(const c of byId.values()){ if(c?.set?.id===setId && norm(c?.supertype)===supKey) base.push(c); }
        const list=q? base.filter(c=>norm(c.name).includes(q)) : base;
        list.sort((a,b)=> numKey(a)-numKey(b) || String(a.number||'').localeCompare(String(b.number||''), undefined, {numeric:true, sensitivity:'base'}));
        const MAX=200, shown=list.slice(0,MAX);
        els.filterCount.textContent=`${list.length} cards`+(list.length>MAX?` (showing ${MAX})`:'');
        const resultHTML = shown.map(c=>{
          const number = String(c.number||'').trim();
          const setLabel = getCardSetLabel(c);
          const metaParts = [];
          if(number) metaParts.push(`#${number}`);
          if(setLabel) metaParts.push(setLabel);
          const meta = metaParts.map(part=>esc(part)).join(' • ');
          return `
          <div class="result">
            <div><div class="name">${esc(c.name)}</div><div class="muted">${meta}</div></div>
            <div><span class="chip">${esc(c.supertype||'')}</span></div>
            <div><button class="btn secondary" data-id="${esc(c.id)}">Add</button></div>
          </div>`;
        }).join('');
        els.results.innerHTML= resultHTML || `<div class="result">No cards</div>`;
        els.results.querySelectorAll('button[data-id]').forEach(b=>b.addEventListener('click',()=>addCard(b.getAttribute('data-id'))));
        updateAddButton(list.length);
      }
      function numKey(c){ const m=String(c.number||'').match(/\d+/); return m?parseInt(m[0],10):1e9; }
      function updateAddButton(n=0){ els.btnAdd.disabled= !(Number(els.selQty.value)>0 && n===1); }
      function addFirstMatch(){
        const setId=els.selSet.value, sup=norm(els.selSupertype.value), q=norm(els.nameInput.value);
        const base=[]; for(const c of byId.values()){ if(c?.set?.id===setId && norm(c?.supertype)===sup) base.push(c); }
        const list=q? base.filter(c=>norm(c.name).includes(q)) : base;
        if(list.length===1) addCard(list[0].id);
        else if(list.length===0) alert('No match.');
        else alert('Multiple matches — click Add on the exact row.');
      }

      function handleBulkAddInput(){
        if(!els.bulkInput) return;
        if(!dataReady){
          setBulkStatus('Card database is still loading. Try again in a moment.', true);
          return;
        }
        const lines = String(els.bulkInput.value || '').split(/\r?\n/);
        const errors = [];
        const successes = [];
        let processed = 0;
        let totalAdded = 0;
        lines.forEach((line, idx)=>{
          const trimmed = line.trim();
          if(!trimmed) return;
          processed++;
          const parsed = parseBulkLine(trimmed);
          if(!parsed){
            errors.push(`Line ${idx+1}: Expected “Qty Name SET Number”.`);
            return;
          }
          if(parsed.error){
            errors.push(`Line ${idx+1}: ${parsed.error}`);
            return;
          }
          const setId = resolveSetIdFromCode(parsed.setCode);
          if(!setId){
            errors.push(`Line ${idx+1}: Unknown set code “${parsed.setCodeDisplay}”.`);
            return;
          }
          let cardId = '';
          let cardLookupError = '';
          if(parsed.searchByName){
            cardId = findCardIdBySetAndNames(setId, parsed.searchNames.length ? parsed.searchNames : [parsed.resolvedName]);
            if(!cardId){
              cardLookupError = `${parsed.resolvedName || parsed.name} not found in set ${parsed.setCodeDisplay}.`;
            }
          } else {
            cardId = findCardIdBySetAndNumber(setId, parsed.number);
            if(!cardId){
              cardLookupError = `Card ${parsed.setCodeDisplay} ${parsed.numberDisplay} not found.`;
            }
          }
          if(!cardId){
            errors.push(`Line ${idx+1}: ${cardLookupError || 'Card not found.'}`);
            return;
          }
          const card = byId.get(cardId);
          const delta = applyDeckQuantity(cardId, parsed.qty);
          if(delta === null){
            errors.push(`Line ${idx+1}: Unable to add card.`);
            return;
          }
          if(delta === 0){
            const maxLabel = parsed.searchByName ? (card?.name || parsed.resolvedName || parsed.name || parsed.setCodeDisplay) : `${parsed.setCodeDisplay} ${parsed.numberDisplay}`;
            errors.push(`Line ${idx+1}: ${maxLabel} already at maximum quantity.`);
            return;
          }
          totalAdded += delta;
          const cardName = card?.name || parsed.resolvedName || parsed.name || `${parsed.setCodeDisplay} ${parsed.numberDisplay}`;
          const numberDisplay = card?.number ? String(card.number).toUpperCase() : (parsed.searchByName ? '' : parsed.numberDisplay);
          successes.push({qty: delta, name: cardName, code: parsed.setCodeDisplay, number: numberDisplay});
        });
        if(totalAdded > 0){
          renderDeckTable();
          updateJSON();
        }
        if(processed === 0){
          setBulkStatus('Enter one card per line using “Qty Name SET Number”.', true);
          updateBulkAddState();
          return;
        }
        const messageParts = [];
        if(successes.length){
          let summary = successes.slice(0,3).map(entry=>{
            const identifier = entry.number ? `${entry.code} ${entry.number}` : entry.code;
            return `${entry.qty}× ${entry.name} (${identifier})`;
          }).join(', ');
          if(successes.length > 3){
            summary += `, +${successes.length-3} more`;
          }
          messageParts.push(`Added ${totalAdded} card${totalAdded===1?'':'s'}${summary ? `: ${summary}` : ''}.`);
        }
        if(errors.length){
          const errorSummary = errors.length === 1 ? errors[0] : `${errors[0]} (+${errors.length-1} more)`;
          messageParts.push(errorSummary);
        }
        if(!messageParts.length){
          messageParts.push('No cards were added.');
        }
        setBulkStatus(messageParts.join(' '), errors.length > 0);
        updateBulkAddState();
      }

      function parseBulkLine(line){
        if(!line) return null;
        const parts = line.trim().split(/\s+/);
        if(parts.length < 3) return { error: 'Expected quantity, card name, set code, and number.' };
        const qtyToken = parts.shift();
        if(!/^\d+$/.test(qtyToken || '')) return { error: 'Line must start with the quantity.' };
        const qty = parseInt(qtyToken, 10);
        if(!qty || Number.isNaN(qty) || qty <= 0) return { error: 'Quantity must be at least 1.' };
        if(parts.length < 2) return { error: 'Missing set code or card number.' };
        const numberTokenRaw = parts.pop();
        const setTokenRaw = parts.pop();
        const name = parts.join(' ').trim();
        const energyInfo = resolveBasicEnergyInfo(name);
        const setClean = String(setTokenRaw || '').trim();
        const numberClean = String(numberTokenRaw || '').trim();
        const sanitisedSet = setClean.replace(/[^0-9a-zA-Z-]+/g,'');
        const sanitisedNumber = numberClean.replace(/[^0-9a-zA-Z]+/g,'');
        if(!sanitisedSet) return { error: 'Missing set code.' };
        if(!sanitisedNumber) return { error: 'Missing card number.' };
        return {
          qty,
          name,
          resolvedName: energyInfo?.displayName || name,
          setCode: sanitisedSet,
          setCodeDisplay: sanitisedSet.toUpperCase(),
          number: sanitisedNumber,
          numberDisplay: sanitisedNumber.toUpperCase(),
          searchByName: !!energyInfo,
          searchNames: energyInfo?.searchNames || []
        };
      }

      function resolveBasicEnergyInfo(name){
        if(!name) return null;
        const original = String(name || '').trim();
        if(!original) return null;
        const lower = original.toLowerCase();
        if(!lower.includes('energy')) return null;
        const searchNames = new Set();
        const braceMatch = original.match(/\{\s*([DMGLFWPR])\s*\}/i);
        let displayName = original.replace(/\s+/g,' ').trim();
        if(braceMatch){
          const code = braceMatch[1].toUpperCase();
          const info = BASIC_ENERGY_MAP[code];
          if(info){
            if(info.display) searchNames.add(`Basic ${info.display} Energy`);
            if(info.canonical) searchNames.add(`Basic ${info.canonical} Energy`);
            if(code === 'D') searchNames.add('Basic Darkness Energy');
            displayName = `Basic ${info.display || info.canonical || ''} Energy`.trim();
          }
        }else{
          let matchedInfo = null;
          for(const [code, info] of Object.entries(BASIC_ENERGY_MAP)){
            const disp = String(info.display || '').toLowerCase();
            const canon = String(info.canonical || '').toLowerCase();
            if((disp && lower.includes(disp)) || (canon && lower.includes(canon))){
              matchedInfo = { code, info };
              break;
            }
          }
          if(matchedInfo){
            const { code, info } = matchedInfo;
            if(info.display) searchNames.add(`Basic ${info.display} Energy`);
            if(info.canonical) searchNames.add(`Basic ${info.canonical} Energy`);
            if(code === 'D') searchNames.add('Basic Darkness Energy');
            if(!/^basic\b/i.test(displayName)){
              displayName = `Basic ${info.display || info.canonical || displayName}`.replace(/\s+/g,' ').trim();
            }
          }
        }
        searchNames.add(displayName);
        searchNames.add(original);
        searchNames.add(original.replace(/\{\s*([DMGLFWPR])\s*\}/gi,'').replace(/\s+/g,' ').trim());
        return {
          displayName,
          searchNames: Array.from(searchNames).filter(Boolean)
        };
      }

      function resolveSetIdFromCode(code){
        if(!code) return '';
        const raw = String(code).trim();
        if(!raw) return '';
        const upper = raw.toUpperCase();
        const variants = [upper, upper.replace(/\s+/g,''), upper.replace(/-/g,'')];
        for(const variant of variants){
          if(!variant) continue;
          if(setCodeLookup.has(variant)) return setCodeLookup.get(variant);
        }
        const lower = raw.toLowerCase();
        if(ALLOWED_SET_IDS.has(lower)) return lower;
        for(const variant of variants){
          const lowerVariant = variant.toLowerCase();
          if(ALLOWED_SET_IDS.has(lowerVariant)) return lowerVariant;
          const upperVariant = lowerVariant.toUpperCase();
          if(setCodeLookup.has(upperVariant)) return setCodeLookup.get(upperVariant);
        }
        return '';
      }

      function findCardIdBySetAndNumber(setId, number){
        const keyBase = makeSetKey(setId);
        if(!keyBase) return '';
        const variants = normaliseNumberVariants(number);
        for(const variant of variants){
          const key = `${keyBase}|${variant}`;
          if(setNumberIndex.has(key)) return setNumberIndex.get(key);
        }
        return '';
      }

      function findCardIdBySetAndName(setId, name){
        const keyBase = makeSetKey(setId);
        if(!keyBase) return '';
        const nameKey = makeNameKey(name);
        if(!nameKey) return '';
        const key = `${keyBase}|${nameKey}`;
        if(setNameIndex.has(key)) return setNameIndex.get(key);
        return '';
      }

      function findCardIdBySetAndNames(setId, names){
        if(!Array.isArray(names)) names = [names];
        const cleaned = names.map(name=>String(name||'').trim()).filter(Boolean);
        for(const candidate of cleaned){
          const found = findCardIdBySetAndName(setId, candidate);
          if(found) return found;
        }
        if(cleaned.some(name=>/\benergy\b/i.test(name))){
          const fallback = findClosestCardIdBySimilarity(setId, cleaned);
          if(fallback) return fallback;
        }
        return '';
      }

      function findClosestCardIdBySimilarity(setId, names){
        if(!setId || !Array.isArray(names) || !names.length) return '';
        const targets = names.map(name=>({ raw: name, norm: normaliseSimilarityName(name) }))
          .filter(entry=>!!entry.norm);
        if(!targets.length) return '';
        let bestId='';
        let bestScore=Infinity;
        for(const card of byId.values()){
          if(!card || card?.set?.id !== setId) continue;
          const cardNorm = normaliseSimilarityName(card?.name);
          if(!cardNorm) continue;
          for(const target of targets){
            const score = computeNameSimilarityScore(cardNorm, target.norm);
            if(score < bestScore || (score === bestScore && !bestId)){
              bestScore = score;
              bestId = card.id;
              if(score === 0) return bestId;
            }
          }
        }
        return bestId;
      }

      function normaliseSimilarityName(value){
        return norm(value).replace(/[^a-z0-9\s]+/g,' ').replace(/\s+/g,' ').trim();
      }

      function computeNameSimilarityScore(cardNorm, targetNorm){
        if(!cardNorm || !targetNorm) return Number.POSITIVE_INFINITY;
        if(cardNorm === targetNorm) return 0;
        if(cardNorm.includes(targetNorm)) return 1;
        if(targetNorm.includes(cardNorm)) return 2;
        const cardTokens = Array.from(new Set(cardNorm.split(' ').filter(Boolean)));
        const targetTokens = Array.from(new Set(targetNorm.split(' ').filter(Boolean)));
        if(cardTokens.length === 0 || targetTokens.length === 0) return Number.POSITIVE_INFINITY;
        const overlap = targetTokens.filter(token=>cardTokens.includes(token)).length;
        if(overlap === 0) return Number.POSITIVE_INFINITY;
        const missing = targetTokens.length - overlap;
        const extra = Math.max(0, cardTokens.length - overlap);
        return 10 + (missing * 5) + extra;
      }

      function updateBulkAddState(){
        if(!els.btnBulkAdd) return;
        const hasInput = !!(els.bulkInput && els.bulkInput.value.trim());
        els.btnBulkAdd.disabled = !(hasInput && dataReady);
      }

      function setBulkStatus(message='', isError=false){
        if(!els.bulkStatus) return;
        els.bulkStatus.textContent = message;
        if(isError) els.bulkStatus.classList.add('error');
        else els.bulkStatus.classList.remove('error');
      }

      function addCard(id, qtyOverride){
        const source = qtyOverride !== undefined ? qtyOverride : els.selQty.value;
        const qty = Math.max(1, Math.min(60, parseInt(source, 10) || 1));
        const delta = applyDeckQuantity(id, qty);
        if(delta === null) return;
        if(qtyOverride === undefined) els.selQty.value=1;
        if(delta !== 0){ renderDeckTable(); updateJSON(); }
      }

      function applyDeckQuantity(id, qty){
        if(!id) return null;
        const safeQty = IS_INVENTORY ? clampBufferQty(qty) : Math.max(1, Math.min(60, parseInt(qty, 10) || 1));
        if(deckMap.has(id)){
          const idx = deckMap.get(id);
          if(idx === undefined) return null;
          if(IS_INVENTORY){
            const entry = deck[idx];
            const variant = getInventoryVariant(entry, 'normal');
            const current = Number.isFinite(variant.qty) ? variant.qty : parseInt(variant.qty, 10) || 0;
            const next = adjustBufferQty(current, safeQty);
            variant.qty = next;
            cleanInventoryVariant(entry, 'normal');
            sumInventoryVariantQuantities(entry);
            return safeQty;
          }
          const current = deck[idx].qty || 0;
          const next = Math.max(1, Math.min(60, current + safeQty));
          const delta = next - current;
          deck[idx].qty = next;
          return delta;
        }
        const initial = IS_INVENTORY ? clampBufferQty(safeQty) : Math.max(1, Math.min(60, safeQty));
        const index = deck.length;
        const entry = { id };
        if(IS_INVENTORY){
          entry.variants = {};
          INVENTORY_VARIANTS.forEach(({ key })=>{
            const saved = getSavedInventoryVariant(id, key);
            if(saved && Number.isFinite(saved.price)){
              entry.variants[key] = { qty: 0, price: saved.price };
            }
          });
          const variant = getInventoryVariant(entry, 'normal');
          variant.qty = initial;
          cleanInventoryVariant(entry, 'normal');
          sumInventoryVariantQuantities(entry);
        }else{
          entry.qty = initial;
        }
        deck.push(entry);
        deckMap.set(id, index);
        return initial;
      }
      function clearDeck(){ deck.length=0; deckMap.clear(); renderDeckTable(); updateJSON(); }
      function renderInventoryDataTable(){
        if(!IS_INVENTORY || !els.inventoryBody) return;
        const emptyRow = `<tr><td colspan="${INVENTORY_SAVED_EMPTY_COLSPAN}" class="muted">No inventory saved yet.</td></tr>`;
        if(!inventoryData.length){
          els.inventoryBody.innerHTML = emptyRow;
          if(els.inventoryTotals) els.inventoryTotals.textContent = '0 cards';
          return;
        }

        const metaList = inventoryData.map(entry=>{
          if(!entry || !entry.id) return null;
          const entryId = String(entry.id);
          const card = byId.get(entry.id);
          const name = (card?.name || entry.name || entryId || '').trim();
          const setName = getCardSetLabel(card);
          const number = card?.number ? String(card.number).toUpperCase() : '';
          const supertype = card?.supertype || entry.supertype || '';
          const setId = card?.set?.id ? String(card.set.id).toLowerCase() : '';
          const setNameKey = setName.toLowerCase();
          const setSortKey = setId || setNameKey;
          return {
            entry,
            entryId,
            card,
            name,
            nameKey: name.toLowerCase(),
            setName,
            setNameKey,
            setSortKey,
            number,
            numberKey: number.toUpperCase(),
            supertype,
          };
        }).filter(Boolean);

        if(!metaList.length){
          els.inventoryBody.innerHTML = emptyRow;
          if(els.inventoryTotals) els.inventoryTotals.textContent = '0 cards';
          return;
        }

        metaList.sort((a, b)=>{
          if(inventorySortMode === 'number'){
            const setCmp = a.setSortKey.localeCompare(b.setSortKey);
            if(setCmp !== 0) return setCmp;
            const numCmp = compareNumericStrings(a.numberKey, b.numberKey);
            if(numCmp !== 0) return numCmp;
            const nameCmp = a.nameKey.localeCompare(b.nameKey);
            if(nameCmp !== 0) return nameCmp;
            return a.entryId.localeCompare(b.entryId);
          }
          const nameCmp = a.nameKey.localeCompare(b.nameKey);
          if(nameCmp !== 0) return nameCmp;
          const setNameCmp = a.setNameKey.localeCompare(b.setNameKey);
          if(setNameCmp !== 0) return setNameCmp;
          const numberCmp = compareNumericStrings(a.numberKey, b.numberKey);
          if(numberCmp !== 0) return numberCmp;
          return a.entryId.localeCompare(b.entryId);
        });

        const rows=[];
        let total=0;
        let displayIndex = 0;

        metaList.forEach(meta=>{
          const { entry } = meta;
          const variants = entry.variants && typeof entry.variants === 'object' ? entry.variants : {};
          let entryQty = 0;
          let hasData = false;
          const variantCells = [];
          INVENTORY_VARIANTS.forEach(({ key })=>{
            const variant = variants[key];
            const qtyRaw = variant && variant.qty !== undefined ? variant.qty : 0;
            const qtyValue = Number.isFinite(qtyRaw) ? qtyRaw : parseInt(qtyRaw, 10) || 0;
            const priceRaw = variant && variant.price !== undefined ? variant.price : null;
            const priceValue = Number.isFinite(priceRaw) ? priceRaw : parsePriceValue(priceRaw);
            if(qtyValue !== 0 || Number.isFinite(priceValue)){
              hasData = true;
            }
            if(qtyValue > 0){
              entryQty += qtyValue;
            }
            variantCells.push(`<td>${qtyValue}</td><td>${formatPriceDisplay(priceValue)}</td>`);
          });
          if(!hasData){
            return;
          }
          displayIndex++;
          total += entryQty;
          rows.push(`<tr data-id="${esc(entry.id)}" data-card-name="${esc(meta.name)}"><td>${displayIndex}</td><td>${esc(meta.name)}</td><td>${esc(meta.setName)}</td><td>${esc(meta.number)}</td><td>${esc(meta.supertype)}</td>${variantCells.join('')}<td><button class="btn danger" data-action="delete-saved" data-name="${esc(meta.name)}">Delete</button></td></tr>`);
        });

        if(!rows.length){
          els.inventoryBody.innerHTML = emptyRow;
          if(els.inventoryTotals) els.inventoryTotals.textContent = '0 cards';
          return;
        }

        els.inventoryBody.innerHTML = rows.join('');
        if(els.inventoryTotals) els.inventoryTotals.textContent = `${total} card${total===1?'':'s'}`;
      }

      function removeInventoryEntryLocal(cardId){
        if(!cardId) return;
        const normalizedId = String(cardId);
        const index = inventoryData.findIndex(entry=>entry && entry.id === normalizedId);
        if(index !== -1){
          inventoryData.splice(index, 1);
        }
        inventorySavedMap.delete(normalizedId);
        if(deckMap.has(normalizedId)){
          const deckIndex = deckMap.get(normalizedId);
          if(deckIndex !== undefined){
            deck.splice(deckIndex, 1);
            deckMap.clear();
            deck.forEach((entry, idx)=>{
              if(entry && entry.id){
                deckMap.set(entry.id, idx);
              }
            });
            renderDeckTable();
          }
        }
        renderInventoryDataTable();
        updateJSON();
      }

      async function deleteSavedInventoryEntry(cardId, button, cardName){
        const action = SAVE_CONFIG.deleteInventoryAction || '';
        const nonce = SAVE_CONFIG.deleteInventoryNonce || '';
        if(!cardId || !action || !nonce){
          alert('Inventory delete action is unavailable.');
          return;
        }
        const labelFallback = cardName || cardId;
        let restoreLabel = 'Delete';
        if(button){
          if(!button.dataset.originalLabel){
            button.dataset.originalLabel = button.textContent || 'Delete';
          }
          restoreLabel = button.dataset.originalLabel;
          button.disabled = true;
          button.textContent = 'Deleting…';
        }
        const payload = new FormData();
        payload.append('action', action);
        payload.append('nonce', nonce);
        payload.append('cardId', cardId);
        try {
          const response = await fetch(AJAX_URL, { method: 'POST', body: payload });
          if(!response.ok){
            throw new Error(`HTTP ${response.status}`);
          }
          const result = await response.json();
          if(!result?.success){
            throw new Error(result?.data || 'Unknown error');
          }
          const data = result.data || {};
          removeInventoryEntryLocal(cardId);
          const resolvedName = data.cardName || labelFallback;
          const messages = [];
          if(data.message){
            messages.push(data.message);
          }else{
            messages.push(`${resolvedName} deleted from saved inventory.`);
          }
          if(data.syncQueued){
            messages.push('Inventory sync queued in background.');
          }
          alert(messages.join('\n'));
        }catch(err){
          alert(`Delete failed: ${err && err.message ? err.message : err}`);
        }finally{
          if(button){
            button.disabled = false;
            button.textContent = button.dataset.originalLabel || restoreLabel || 'Delete';
          }
        }
      }

      function handleInventoryBodyClick(event){
        const target = event.target instanceof Element ? event.target : null;
        if(!target) return;
        const button = target.closest('button[data-action="delete-saved"]');
        if(!button) return;
        event.preventDefault();
        const row = button.closest('tr[data-id]');
        const cardId = row?.getAttribute('data-id') || button.dataset.id || '';
        if(!cardId) return;
        const cardName = button.dataset.name || row?.getAttribute('data-card-name') || cardId;
        const confirmation = `Delete ${cardName} from saved inventory and remove its WooCommerce product?`;
        if(!window.confirm(confirmation)) return;
        deleteSavedInventoryEntry(cardId, button, cardName);
      }
      function setInventoryData(entries){
        if(!IS_INVENTORY) return;
        inventoryData.length = 0;
        inventorySavedMap.clear();
        if(Array.isArray(entries)){
          const normalizedEntries = aggregateInventoryEntries(entries, []);
          normalizedEntries.forEach(entry=>{
            if(!entry || !entry.id) return;
            const id = String(entry.id).trim();
            if(!id) return;
            const variants = entry.variants && typeof entry.variants === 'object' ? entry.variants : {};
            const record = { id, variants: {} };
            let totalQty = 0;
            INVENTORY_VARIANTS.forEach(({ key })=>{
              const variant = variants[key];
              if(!variant || typeof variant !== 'object') return;
              const qtyRaw = variant.qty !== undefined ? variant.qty : 0;
              const qty = Number.isFinite(qtyRaw) ? qtyRaw : parseInt(qtyRaw, 10) || 0;
              const priceValue = Number.isFinite(variant.price) ? variant.price : parsePriceValue(variant.price);
              if(qty > 0 || Number.isFinite(priceValue)){
                record.variants[key] = { qty };
                if(Number.isFinite(priceValue)){
                  record.variants[key].price = priceValue;
                }
                if(qty > 0){
                  totalQty += qty;
                }
              }
            });
            if(totalQty > 0){
              record.qty = totalQty;
            }
            if(!record.qty && Object.keys(record.variants).length === 0){
              return;
            }
            inventoryData.push(record);
            inventorySavedMap.set(id, {
              id,
              qty: record.qty || 0,
              variants: cloneInventoryVariantData(record.variants) || {},
            });
          });
        }
        renderInventoryDataTable();
      }
      function normalizeInventoryEntry(entry, options = {}){
        const { allowNegative = false } = options;
        if(!entry || typeof entry !== 'object') return null;
        const rawId = entry.id !== undefined ? entry.id : entry.cardId || entry.card_id || entry.cardID || entry.identifier;
        const id = String(rawId || '').trim();
        if(!id) return null;
        const result = { id, variants: {} };
        let hasData = false;
        const variants = entry.variants && typeof entry.variants === 'object' ? entry.variants : null;
        INVENTORY_VARIANTS.forEach(({ key })=>{
          const source = variants && typeof variants[key] === 'object' ? variants[key] : null;
          let qty = null;
          let price = null;
          if(source){
            if(typeof source.qty === 'number' && Number.isFinite(source.qty)){
              qty = source.qty;
            }else if(source.qty !== undefined){
              const parsedQty = parseInt(source.qty, 10);
              if(!Number.isNaN(parsedQty)) qty = parsedQty;
            }
            if(source.price !== undefined){
              const parsedPrice = parsePriceValue(source.price);
              if(Number.isFinite(parsedPrice)) price = parsedPrice;
            }
          }
          if(qty === null && entry[`${key}Qty`] !== undefined){
            const parsedQty = parseInt(entry[`${key}Qty`], 10);
            if(!Number.isNaN(parsedQty)) qty = parsedQty;
          }
          if(price === null && entry[`${key}Price`] !== undefined){
            const parsedPrice = parsePriceValue(entry[`${key}Price`]);
            if(Number.isFinite(parsedPrice)) price = parsedPrice;
          }
          const hasQty = Number.isFinite(qty);
          const hasPrice = Number.isFinite(price);
          if(allowNegative){
            if((hasQty && qty !== 0) || hasPrice){
              result.variants[key] = { qty: hasQty ? qty : 0 };
              if(hasPrice) result.variants[key].price = price;
              hasData = true;
            }
          }else{
            if((hasQty && qty > 0) || hasPrice){
              result.variants[key] = { qty: hasQty ? Math.max(0, qty) : 0 };
              if(hasPrice) result.variants[key].price = price;
              hasData = true;
            }
          }
        });
        if(!hasData){
          let qty = null;
          if(entry.qty !== undefined){
            if(typeof entry.qty === 'number' && Number.isFinite(entry.qty)){
              qty = entry.qty;
            }else{
              const parsedQty = parseInt(entry.qty, 10);
              if(!Number.isNaN(parsedQty)) qty = parsedQty;
            }
          }
          const price = parsePriceValue(entry.price);
          const hasQty = Number.isFinite(qty);
          const hasPrice = Number.isFinite(price);
          if(allowNegative){
            if((hasQty && qty !== 0) || hasPrice){
              result.variants.normal = { qty: hasQty ? qty : 0 };
              if(hasPrice) result.variants.normal.price = price;
              hasData = true;
            }
          }else{
            if((hasQty && qty > 0) || hasPrice){
              result.variants.normal = { qty: hasQty ? Math.max(0, qty) : 0 };
              if(hasPrice) result.variants.normal.price = price;
              hasData = true;
            }
          }
        }
        if(!hasData) return null;
        return result;
      }

      function aggregateInventoryEntries(baseEntries, additions){
        const totals = new Map();
        function getRecord(id){
          if(!totals.has(id)){
            totals.set(id, { id, variants: {} });
          }
          return totals.get(id);
        }
        if(Array.isArray(baseEntries)){
          baseEntries.forEach(entry=>{
            const normalized = normalizeInventoryEntry(entry, { allowNegative: false });
            if(!normalized) return;
            const record = getRecord(normalized.id);
            INVENTORY_VARIANTS.forEach(({ key })=>{
              const variant = normalized.variants[key];
              if(!variant) return;
              const current = record.variants[key] && typeof record.variants[key] === 'object' ? record.variants[key] : { qty: 0, price: null };
              const baseQty = Number.isFinite(current.qty) ? current.qty : parseInt(current.qty, 10) || 0;
              const addQtyRaw = Number.isFinite(variant.qty) ? variant.qty : parseInt(variant.qty, 10) || 0;
              const addQty = addQtyRaw > 0 ? addQtyRaw : 0;
              const nextQty = baseQty + addQty;
              const price = Number.isFinite(variant.price) ? variant.price : parsePriceValue(variant.price);
              const result = { qty: nextQty };
              if(Number.isFinite(price)){
                result.price = price;
              }else if(Number.isFinite(current.price)){
                result.price = current.price;
              }
              if(nextQty > 0 || Number.isFinite(result.price)){
                record.variants[key] = result;
              }else{
                delete record.variants[key];
              }
            });
          });
        }
        if(Array.isArray(additions)){
          additions.forEach(entry=>{
            const normalized = normalizeInventoryEntry(entry, { allowNegative: true });
            if(!normalized) return;
            const record = getRecord(normalized.id);
            INVENTORY_VARIANTS.forEach(({ key })=>{
              const variant = normalized.variants[key];
              if(!variant) return;
              const current = record.variants[key] && typeof record.variants[key] === 'object' ? record.variants[key] : { qty: 0, price: null };
              const baseQty = Number.isFinite(current.qty) ? current.qty : parseInt(current.qty, 10) || 0;
              const delta = Number.isFinite(variant.qty) ? variant.qty : parseInt(variant.qty, 10) || 0;
              let nextQty = baseQty + delta;
              if(nextQty <= 0){
                nextQty = 0;
              }
              const price = Number.isFinite(variant.price) ? variant.price : parsePriceValue(variant.price);
              const result = { qty: nextQty };
              if(Number.isFinite(price)){
                result.price = price;
              }else if(Number.isFinite(current.price)){
                result.price = current.price;
              }
              if(nextQty > 0 || Number.isFinite(result.price)){
                record.variants[key] = result;
              }else{
                delete record.variants[key];
              }
            });
          });
        }
        return Array.from(totals.values()).map(record=>{
          const variantsOut = {};
          let totalQty = 0;
          let firstPrice = null;
          INVENTORY_VARIANTS.forEach(({ key })=>{
            const data = record.variants[key];
            if(!data || typeof data !== 'object') return;
            const qty = Number.isFinite(data.qty) ? data.qty : parseInt(data.qty, 10) || 0;
            const price = Number.isFinite(data.price) ? data.price : parsePriceValue(data.price);
            if(qty > 0 || Number.isFinite(price)){
              variantsOut[key] = { qty };
              if(Number.isFinite(price)){
                variantsOut[key].price = price;
                if(firstPrice === null) firstPrice = price;
              }
              if(qty > 0){
                totalQty += qty;
              }
            }
          });
          if(totalQty <= 0 && Object.keys(variantsOut).length === 0){
            return null;
          }
          const entry = { id: record.id, variants: variantsOut };
          if(totalQty > 0){
            entry.qty = totalQty;
          }
          if(firstPrice !== null){
            entry.price = firstPrice;
          }
          return entry;
        }).filter(Boolean);
      }
      function buildSaveEntries(){
        if(!IS_INVENTORY){
          return deck.map(d=>({ id: d.id, qty: d.qty }));
        }
        return aggregateInventoryEntries(inventoryData, deck);
      }
      function renderDeckTable(){
        if(deck.length===0){
          const emptyColspan = IS_INVENTORY ? (5 + INVENTORY_VARIANTS.length * 2 + 1) : 7;
          els.deckBody.innerHTML=`<tr><td colspan="${emptyColspan}" class="muted">No cards yet.</td></tr>`;
          els.deckTotals.textContent='0 cards';
          updateDeckButtons();
          return;
        }
        const rows=[]; let total=0;
        deck.forEach((d,i)=>{
          const c=byId.get(d.id);
          const setName=getCardSetLabel(c);
          const num=c?.number||'';
          const sup=c?.supertype||'';
          if(IS_INVENTORY){
            const entryTotal = sumInventoryVariantQuantities(d);
            total += entryTotal;
            const variantCells = [];
            INVENTORY_VARIANTS.forEach(({ key, label })=>{
              const variantData = d.variants && typeof d.variants === 'object' ? d.variants[key] : null;
              const qtyValueRaw = variantData && variantData.qty !== undefined ? variantData.qty : 0;
              const qtyValue = Number.isFinite(qtyValueRaw) ? qtyValueRaw : parseInt(qtyValueRaw, 10) || 0;
              const priceValue = variantData ? formatPriceInputValue(variantData.price) : '';
              variantCells.push(`<td><input class="variantQtyInput" data-variant="${key}" type="number" min="${INVENTORY_BUFFER_MIN}" max="${INVENTORY_BUFFER_MAX}" value="${qtyValue}" placeholder="${label}"></td>`);
              variantCells.push(`<td><input class="variantPriceInput" data-variant="${key}" type="number" step="0.01" min="0" value="${priceValue}" placeholder="${label}"></td>`);
            });
            rows.push(`<tr data-id="${d.id}"><td>${i+1}</td><td>${esc(c?.name||d.id)}</td><td>${esc(setName)}</td><td>${esc(String(num))}</td><td>${esc(sup)}</td>${variantCells.join('')}<td><button class="btn secondary" data-act="remove">Remove</button></td></tr>`);
          }else{
            total += d.qty;
            const qtyInputAttrs = 'min="1" max="60"';
            rows.push(`<tr data-id="${d.id}"><td>${i+1}</td><td>${esc(c?.name||d.id)}</td><td>${esc(setName)}</td><td>${esc(String(num))}</td><td>${esc(sup)}</td>
            <td><div class="qtybox"><button class="btn secondary" data-act="minus">–</button><input class="qtyInput" type="number" value="${d.qty}" ${qtyInputAttrs}><button class="btn secondary" data-act="plus">+</button></div></td>
            <td><button class="btn secondary" data-act="remove">Remove</button></td></tr>`);
          }
        });
        els.deckBody.innerHTML=rows.join(''); els.deckTotals.textContent=`${total} cards`;
        els.deckBody.querySelectorAll('tr').forEach(tr=>{
          const id=tr.getAttribute('data-id');
          tr.addEventListener('click',(ev)=>{ const act=ev.target?.getAttribute?.('data-act'); if(!act) return; const i=deckMap.get(id); if(i===undefined) return;
            if(act==='remove'){ deck.splice(i,1); deckMap.clear(); deck.forEach((d,j)=>deckMap.set(d.id,j)); renderDeckTable(); updateJSON(); return; }
            if(!IS_INVENTORY){
              if(act==='plus') deck[i].qty=adjustBufferQty(deck[i].qty, 1);
              else if(act==='minus') deck[i].qty=adjustBufferQty(deck[i].qty, -1);
              renderDeckTable(); updateJSON();
            }
          });
          if(IS_INVENTORY){
            tr.querySelectorAll('.variantQtyInput').forEach(input=>{
              input.addEventListener('change',(ev)=>{
                const variantKey = ev.target?.dataset?.variant;
                if(!variantKey) return;
                const rowIndex = deckMap.get(id);
                if(rowIndex === undefined) return;
                const entry = deck[rowIndex];
                const variant = getInventoryVariant(entry, variantKey);
                let next = parseInt(ev.target.value, 10);
                if(Number.isNaN(next)) next = 0;
                if(next > INVENTORY_BUFFER_MAX) next = INVENTORY_BUFFER_MAX;
                if(next < INVENTORY_BUFFER_MIN) next = INVENTORY_BUFFER_MIN;
                variant.qty = next;
                cleanInventoryVariant(entry, variantKey);
                sumInventoryVariantQuantities(entry);
                ev.target.value = variant.qty;
                renderDeckTable();
                updateJSON();
              });
            });
            tr.querySelectorAll('.variantPriceInput').forEach(input=>{
              input.addEventListener('change',(ev)=>{
                const variantKey = ev.target?.dataset?.variant;
                if(!variantKey) return;
                const rowIndex = deckMap.get(id);
                if(rowIndex === undefined) return;
                const entry = deck[rowIndex];
                const variant = getInventoryVariant(entry, variantKey);
                const nextPrice = parsePriceInput(ev.target.value);
                variant.price = Number.isFinite(nextPrice) ? nextPrice : null;
                cleanInventoryVariant(entry, variantKey);
                ev.target.value = formatPriceInputValue(variant.price);
                updateJSON();
              });
            });
          }else{
            const qtyInput = tr.querySelector('.qtyInput');
            if(qtyInput){
              qtyInput.addEventListener('change',(ev)=>{ const v=clampBufferQty(ev.target.value); const rowIndex=deckMap.get(id); deck[rowIndex].qty=v; renderDeckTable(); updateJSON(); });
            }
          }
        });
        updateDeckButtons();
      }
      function updateDeckButtons(){
        const has = deck.length>0;
        if(els.btnSaveDeck){
          const shouldEnable = has && !isSavingDeck;
          els.btnSaveDeck.disabled = !shouldEnable;
        }
        if(els.btnClearDeck) els.btnClearDeck.disabled=!has;
      }
      function updateJSON(){
        const entries = buildSaveEntries();
        const out = {
          name: els.deckName.value.trim() || (SAVE_CONFIG.defaultEntryName || 'Untitled Deck'),
          format: els.deckFormat.value.trim() || 'Standard',
          cards: entries,
        };
        deckJsonCache = JSON.stringify(out, null, 2);
        return entries;
      }

      async function saveDeck(){
        if (isSavingDeck) {
          return;
        }
        isSavingDeck = true;
        const button = els.btnSaveDeck || null;
        const defaultLabel = button && button.dataset ? (button.dataset.defaultLabel || button.textContent || '') : '';
        if (button) {
          button.disabled = true;
          button.dataset.saving = '1';
          button.textContent = button.dataset.savingLabel || 'Saving…';
        }
        const rawName = els.deckName.value.trim();
        const fallbackBase = SAVE_CONFIG.defaultBasename || 'deck';
        let safeBase = (rawName || fallbackBase).replace(/[^\w-]+/g,'_');
        if (!safeBase) safeBase = fallbackBase.replace(/[^\w-]+/g,'_') || 'deck';
        let filename = SAVE_CONFIG.fixedFilename || '';
        if (!filename) {
          const pattern = SAVE_CONFIG.filenamePattern || '%s.json';
          if (pattern.includes('%s')) {
            filename = pattern.replace('%s', safeBase);
          } else {
            filename = safeBase + pattern;
          }
        }
        const body=new FormData();
        body.append('action', SAVE_CONFIG.saveAction || 'ptcgdm_save_inventory');
        body.append('nonce', SAVE_NONCE);
        body.append('filename', filename);
        const entriesForSave = updateJSON();
        body.append('content', deckJsonCache);
        try{
          const r = await fetch(AJAX_URL, { method:'POST', body });
          const j = await r.json();
          if (!j.success) throw new Error(j.data || 'Unknown error');
          const displayName = els.deckName.value.trim();
          if (!IS_INVENTORY) {
            ensureSavedDeckOption(j.data?.url || '', filename, displayName);
          }
          const success = SAVE_CONFIG.successMessage || 'Saved!\n';
          const url = j.data?.url || '';
          let extraNotice = '';
          if (IS_INVENTORY && j.data?.syncQueued) {
            extraNotice = '\nInventory sync queued in background.';
          }
          alert(success + url + extraNotice);
          if (IS_INVENTORY) {
            setInventoryData(entriesForSave);
            deck.length = 0;
            deckMap.clear();
            renderDeckTable();
            updateJSON();
            await refreshInventoryData();
            if (els.selQty) {
              els.selQty.value = '0';
              updateAddButton();
            }
          }
        }catch(e){ alert('Save failed: ' + e.message); }
        finally {
          isSavingDeck = false;
          if (button) {
            button.textContent = defaultLabel;
            button.removeAttribute('data-saving');
          }
          updateDeckButtons();
        }
      }

      function ensureSavedDeckOption(url, filename, deckName){
        if (!url || !els.savedDeckSelect) return;
        const options = Array.from(els.savedDeckSelect.options || []);
        let option = options.find(opt => opt.value === url);
        const label = SAVE_CONFIG.forceOptionLabel || makeDeckOptionLabel(deckName, filename);
        if (!option) {
          option = document.createElement('option');
          option.value = url;
          els.savedDeckSelect.appendChild(option);
          if (options.length <= 1) {
            if (els.deckLoadStatus) {
              const defaultMsg = els.deckLoadStatus.dataset?.default || defaultLoadMessage;
              if (defaultMsg) {
                defaultLoadMessage = defaultMsg;
                if (!els.savedDeckSelect.value) setLoadStatus(defaultLoadMessage);
              }
            }
          }
        }
        option.textContent = label;
        option.dataset.filename = filename;
      }
      async function refreshInventoryData(){
        if (!IS_INVENTORY || !SAVE_CONFIG.autoLoadUrl) return;
        try {
          await loadDeckFromUrl(SAVE_CONFIG.autoLoadUrl, { updateInventory: true });
        } catch (err) {
          console.error('Reload inventory failed', err);
        }
      }
      function makeDeckOptionLabel(name, fallback){
        return name ? name : fallback;
      }
      function setLoadStatus(message, isError=false){
        if (!els.deckLoadStatus) return;
        els.deckLoadStatus.textContent = message;
        els.deckLoadStatus.style.color = isError ? '#f28b82' : '';
      }
      function updateLoadDeckButton(updateMessage=true){
        if (!els.btnLoadDeck) return;
        const hasSelection = !!(els.savedDeckSelect && els.savedDeckSelect.value);
        els.btnLoadDeck.disabled = !hasSelection;
        if (updateMessage && els.deckLoadStatus) {
          if (hasSelection) {
            const label = els.savedDeckSelect.selectedOptions?.[0]?.textContent?.trim() || SAVE_CONFIG.fallbackEntityLabel || 'deck';
            const buttonLabel = SAVE_CONFIG.loadButtonLabel || 'Load Deck';
            setLoadStatus(`Click “${buttonLabel}” to load ${label}.`);
          } else if (defaultLoadMessage) {
            setLoadStatus(defaultLoadMessage);
          }
        }
      }
      async function loadDeckFromUrl(url, options = {}){
        if (!url) return;
        setLoadStatus(SAVE_CONFIG.loadingMessage || 'Loading…');
        if (els.btnLoadDeck) els.btnLoadDeck.disabled = true;
        try {
          const response = await fetch(url, { cache:'no-cache' });
          if (!response.ok) throw new Error(`HTTP ${response.status}`);
          const text = await response.text();
          const cleaned = text.replace(/^\uFEFF/, '').trim();
          if (!cleaned) throw new Error('File is empty');
          let data;
          try {
            data = JSON.parse(cleaned);
          } catch (err) {
            throw new Error('Invalid JSON');
          }
          applyDeckData(data, options);
          const label = els.savedDeckSelect?.selectedOptions?.[0]?.textContent?.trim() || data?.name || SAVE_CONFIG.fallbackEntityLabel || 'deck';
          setLoadStatus(`Loaded ${label}.`);
        } catch (err) {
          const fallback = SAVE_CONFIG.fallbackEntityLabel || 'deck';
          setLoadStatus(err.message || `Failed to load ${fallback}.`, true);
        } finally {
          updateLoadDeckButton(false);
        }
      }
      function applyDeckData(data, options = {}){
        if (!data || typeof data !== 'object') throw new Error('JSON missing data.');
        const entries = normalizeDeckEntries(data);
        if (typeof data.name === 'string') els.deckName.value = data.name;
        if (typeof data.format === 'string') els.deckFormat.value = data.format;
        const isInventorySnapshot = IS_INVENTORY && options.updateInventory === true;
        if (!entries.length) {
          deck.length = 0;
          deckMap.clear();
          renderDeckTable();
          if (IS_INVENTORY && options.updateInventory !== false) {
            setInventoryData([]);
          }
          updateJSON();
          if (!IS_INVENTORY) {
            throw new Error('No cards found in JSON.');
          }
          return;
        }
        deck.length = 0;
        deckMap.clear();
        if (!isInventorySnapshot) {
          entries.forEach((entryData)=>{
            if(!entryData || !entryData.id) return;
            const id = String(entryData.id).trim();
            if(!id) return;
            const idx = deck.length;
            if(IS_INVENTORY){
              const record = { id, variants: {} };
              const variants = entryData.variants && typeof entryData.variants === 'object' ? entryData.variants : {};
              INVENTORY_VARIANTS.forEach(({ key })=>{
                const variant = variants[key];
                if(!variant || typeof variant !== 'object') return;
                const qtyRaw = variant.qty !== undefined ? variant.qty : 0;
                let qty = Number.isFinite(qtyRaw) ? qtyRaw : parseInt(qtyRaw, 10) || 0;
                qty = clampBufferQty(qty);
                const priceValue = Number.isFinite(variant.price) ? variant.price : parsePriceValue(variant.price);
                const target = getInventoryVariant(record, key);
                target.qty = qty;
                if(Number.isFinite(priceValue)){
                  target.price = priceValue;
                }
                cleanInventoryVariant(record, key);
              });
              if(Object.keys(record.variants).length === 0){
                const fallbackQty = clampBufferQty(entryData.qty);
                if(fallbackQty !== 0){
                  const variant = getInventoryVariant(record, 'normal');
                  variant.qty = fallbackQty;
                  cleanInventoryVariant(record, 'normal');
                }
                const fallbackPrice = parsePriceValue(entryData.price);
                if(Number.isFinite(fallbackPrice)){
                  const variant = getInventoryVariant(record, 'normal');
                  variant.price = fallbackPrice;
                  cleanInventoryVariant(record, 'normal');
                }
              }
              if(Object.keys(record.variants).length === 0){
                return;
              }
              sumInventoryVariantQuantities(record);
              deck.push(record);
            }else{
              const safeQty = Math.max(1, Math.min(60, parseInt(entryData.qty, 10) || 1));
              const entry = { id, qty: safeQty };
              deck.push(entry);
            }
            deckMap.set(id, idx);
          });
          renderDeckTable();
          updateJSON();
          if (IS_INVENTORY && options.updateInventory !== false) {
            setInventoryData(entries);
          }
          return;
        }
        renderDeckTable();
        if (IS_INVENTORY && options.updateInventory !== false) {
          setInventoryData(entries);
        }
        updateJSON();
      }
      function normalizeDeckEntries(raw){
        const arrays = [];
        const seen = new WeakSet();
        function pushArray(arr){
          if (Array.isArray(arr) && arr.length && !seen.has(arr)) {
            seen.add(arr);
            arrays.push(arr);
          }
        }
        pushArray(raw.cards);
        pushArray(raw.deck);
        pushArray(raw.list);
        const sectionKeys = ['pokemon','pokémon','trainers','supporters','items','stadiums','energy','energies','tools','other'];
        for (const key of sectionKeys){
          if (raw && Object.prototype.hasOwnProperty.call(raw, key)) {
            pushArray(raw[key]);
          }
        }
        if (!arrays.length) {
          Object.values(raw).forEach(val => {
            if (Array.isArray(val) && val.length && typeof val[0] === 'object' && !seen.has(val)) {
              const sample = val[0];
              if ('id' in sample || 'cardId' in sample || 'card_id' in sample || 'cardID' in sample || 'card' in sample || 'identifier' in sample) {
                seen.add(val);
                arrays.push(val);
              }
            }
          });
        }
        const counts = new Map();
        const idKeys = ['id','cardId','card_id','cardID','card','identifier'];
        const qtyKeys = ['qty','quantity','count','q','amount','number','total'];
        const priceKeys = ['price','cost','value'];
        if(IS_INVENTORY){
          const collected = [];
          arrays.forEach(list=>{
            list.forEach(entry=>{
              if(entry && typeof entry === 'object'){
                collected.push(entry);
              }
            });
          });
          return aggregateInventoryEntries(collected, []);
        }
        arrays.forEach(list=>{
          list.forEach(entry=>{
            if (!entry || typeof entry !== 'object') return;
            let id = '';
            for (const key of idKeys){
              if (entry[key]) { id = String(entry[key]).trim(); break; }
            }
            if (!id) return;
            let qty = 0;
            for (const key of qtyKeys){
              if (entry[key] !== undefined) {
                const parsed = parseInt(entry[key], 10);
                if (!Number.isNaN(parsed)) { qty = parsed; break; }
              }
            }
            if (!qty || Number.isNaN(qty)) qty = 1;
            if (IS_INVENTORY && qty <= 0) return;
            let price = null;
            for (const key of priceKeys){
              if (entry[key] !== undefined) {
                const parsedPrice = parsePriceValue(entry[key]);
                if (Number.isFinite(parsedPrice)) { price = parsedPrice; break; }
              }
            }
            const current = counts.get(id) || { qty: 0, price: null };
            const addQty = IS_INVENTORY ? qty : Math.max(1, qty);
            const nextQty = IS_INVENTORY ? current.qty + addQty : Math.min(60, current.qty + addQty);
            let nextPrice = current.price;
            if (Number.isFinite(price)) {
              nextPrice = price;
            }
            counts.set(id, { qty: nextQty, price: nextPrice });
          });
        });
        return Array.from(counts.entries()).map(([id, info])=>{
          const entry = {id, qty: info.qty};
          if (Number.isFinite(info.price) && info.price >= 0) {
            entry.price = info.price;
          }
          return entry;
        });
      }
      function norm(s){ return (s||'').normalize('NFKD').replace(/[\u0300-\u036f]/g,'').toLowerCase().trim(); }
      function esc(s){ return (s||'').replace(/[&<>"']/g, m=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[m])); }
    })();
    </script>
  </div>
<?php }


add_action('wp_ajax_ptcgdm_save_inventory', function(){
  if (!current_user_can('manage_options')) wp_send_json_error('Permission denied', 403);
  check_ajax_referer('ptcgdm_save_inventory', 'nonce');

  $content = wp_unslash($_POST['content'] ?? '');
  if (!$content) wp_send_json_error('Empty content');

  $decoded = json_decode($content, true);
  if (!is_array($decoded)) wp_send_json_error('Invalid inventory data');

  $dir = trailingslashit(ptcgdm_get_inventory_dir());
  if (!file_exists($dir) && !wp_mkdir_p($dir)) wp_send_json_error('Unable to create inventory directory');
  if (!is_writable($dir)) wp_send_json_error('Inventory directory not writable');

  $path = $dir . PTCGDM_INVENTORY_FILENAME;
  if (file_put_contents($path, $content) === false) wp_send_json_error('Write failed');

  $syncQueued = ptcgdm_trigger_inventory_sync();

  $url = trailingslashit(ptcgdm_get_inventory_url()) . rawurlencode(PTCGDM_INVENTORY_FILENAME);
  $response = ['url' => $url, 'path' => $path];
  if ($syncQueued) {
    $response['syncQueued'] = true;
  }
  wp_send_json_success($response);
});

add_action('wp_ajax_ptcgdm_delete_inventory_card', function(){
  if (!current_user_can('manage_options')) {
    wp_send_json_error('Permission denied', 403);
  }

  check_ajax_referer('ptcgdm_delete_inventory_card', 'nonce');

  $card_id = '';
  foreach (['cardId', 'card_id', 'id'] as $key) {
    if (isset($_POST[$key])) {
      $card_id = sanitize_text_field(wp_unslash($_POST[$key]));
      break;
    }
  }

  $card_id = trim($card_id);
  if ($card_id === '') {
    wp_send_json_error('Missing card ID', 400);
  }

  $remove_result = ptcgdm_remove_inventory_card_entry($card_id);
  if (is_wp_error($remove_result)) {
    wp_send_json_error($remove_result->get_error_message());
  }

  if (empty($remove_result['removed'])) {
    wp_send_json_error('Card not found in inventory.', 404);
  }

  $product_result = ptcgdm_delete_inventory_product_by_card($card_id);
  $preview = ptcgdm_lookup_card_preview($card_id);
  $card_name = is_array($preview) && !empty($preview['name']) ? trim((string) $preview['name']) : '';

  $message = $card_name ? sprintf('%s removed from inventory.', $card_name) : 'Card removed from inventory.';
  if (!empty($product_result['deleted'])) {
    $message .= ' WooCommerce product deleted.';
  }

  $syncQueued = ptcgdm_trigger_inventory_sync();

  $response = [
    'cardId'         => $card_id,
    'cardName'       => $card_name,
    'removed'        => true,
    'productDeleted' => !empty($product_result['deleted']),
    'productId'      => isset($product_result['product_id']) ? (int) $product_result['product_id'] : 0,
    'message'        => $message,
  ];

  if ($syncQueued) {
    $response['syncQueued'] = true;
  }

  wp_send_json_success($response);
});

function ptcgdm_lookup_card_preview($card_id){
  static $cache = [];
  $key = trim((string) $card_id);
  if ($key === '') return ['id' => '', 'name' => '', 'image' => ''];
  if (isset($cache[$key])) return $cache[$key];

  $set_id = ptcgdm_extract_set_from_card($key);
  $number = ptcgdm_extract_card_number($key);
  $card = [];

  if ($set_id) {
    $map = ptcgdm_load_set_map($set_id);
    if ($map && isset($map[$key])) {
      $card = $map[$key];
    }
  }

  $name = '';
  $image = '';

  if (!empty($card['name']) && is_string($card['name'])) {
    $name = $card['name'];
  }

  if (!empty($card['images']['small']) && filter_var($card['images']['small'], FILTER_VALIDATE_URL)) {
    $image = $card['images']['small'];
  } elseif (!empty($card['images']['large']) && filter_var($card['images']['large'], FILTER_VALIDATE_URL)) {
    $image = $card['images']['large'];
  } elseif ($set_id && $number) {
    $image = sprintf('https://images.pokemontcg.io/%s/%s.png', strtolower($set_id), $number);
  }

  $supertype = '';
  if (!empty($card['supertype']) && is_string($card['supertype'])) {
    $supertype = $card['supertype'];
  }

  $cache[$key] = [
    'id'        => $key,
    'name'      => $name,
    'image'     => $image,
    'set'       => $set_id,
    'number'    => $number,
    'supertype' => $supertype,
  ];

  return $cache[$key];
}

function ptcgdm_lookup_card_data($card_id, $set_hint = null) {
  static $cache = [];
  $key = trim((string) $card_id);
  if ($key === '') {
    return [];
  }
  if (isset($cache[$key])) {
    return $cache[$key];
  }

  $set_id = $set_hint ? trim((string) $set_hint) : '';
  if ($set_id === '') {
    $set_id = ptcgdm_extract_set_from_card($key);
  }
  if ($set_id === '') {
    $cache[$key] = [];
    return [];
  }

  $map = ptcgdm_load_set_map($set_id);
  if ($map && isset($map[$key]) && is_array($map[$key])) {
    $cache[$key] = $map[$key];
    return $cache[$key];
  }

  $cache[$key] = [];
  return [];
}

function ptcgdm_lookup_set_info($set_id) {
  static $cache = [];
  $key = strtolower(trim((string) $set_id));
  if ($key === '') {
    return [];
  }
  if (isset($cache[$key])) {
    return $cache[$key];
  }

  if (!$cache) {
    $paths = [
      PTCGDM_DATA_DIR . '/sets/en.json',
      PTCGDM_DATA_DIR . '/sets.json',
    ];

    foreach ($paths as $path) {
      if (!file_exists($path)) {
        continue;
      }
      $text = @file_get_contents($path);
      if (!$text) {
        continue;
      }
      $decoded = json_decode($text, true);
      if (!is_array($decoded)) {
        continue;
      }
      $items = [];
      if (isset($decoded['data']) && is_array($decoded['data'])) {
        $items = $decoded['data'];
      } elseif (isset($decoded['sets']) && is_array($decoded['sets'])) {
        $items = $decoded['sets'];
      } else {
        $items = $decoded;
      }
      if (!is_array($items)) {
        continue;
      }
      foreach ($items as $item) {
        if (!is_array($item) || empty($item['id'])) {
          continue;
        }
        $id = strtolower(trim((string) $item['id']));
        if ($id === '') {
          continue;
        }
        if (!isset($cache[$id])) {
          $cache[$id] = $item;
        }
      }
      if ($cache) {
        break;
      }
    }
  }

  if (!isset($cache[$key])) {
    $paths = [
      PTCGDM_DATA_DIR . '/sets/' . $key . '.json',
      PTCGDM_DATA_DIR . '/sets/en/' . $key . '.json',
    ];
    foreach ($paths as $path) {
      if (!file_exists($path)) {
        continue;
      }
      $text = @file_get_contents($path);
      if (!$text) {
        continue;
      }
      $decoded = json_decode($text, true);
      if (is_array($decoded)) {
        if (isset($decoded['data']) && is_array($decoded['data'])) {
          $decoded = $decoded['data'];
        }
        if (isset($decoded['sets']) && is_array($decoded['sets'])) {
          $decoded = $decoded['sets'];
        }
        if (isset($decoded['id'])) {
          $cache[$key] = $decoded;
          break;
        }
        foreach ($decoded as $item) {
          if (!is_array($item) || empty($item['id'])) {
            continue;
          }
          $id = strtolower(trim((string) $item['id']));
          if ($id === '') {
            continue;
          }
          if (!isset($cache[$id])) {
            $cache[$id] = $item;
          }
        }
        if (isset($cache[$key])) {
          break;
        }
      }
    }
  }

  return $cache[$key] ?? [];
}

function ptcgdm_extract_ptcgo_code(array $card = [], array $set_info = []) {
  $candidates = [];

  if (!empty($card['set']) && is_array($card['set'])) {
    $set_block = $card['set'];
    foreach (['ptcgoCode', 'ptcgo_code', 'onlineCode', 'code'] as $field) {
      if (!empty($set_block[$field])) {
        $candidates[] = $set_block[$field];
      }
    }
  }

  foreach (['ptcgoCode', 'ptcgo_code', 'onlineCode', 'code'] as $field) {
    if (!empty($set_info[$field])) {
      $candidates[] = $set_info[$field];
    }
  }

  foreach ($candidates as $candidate) {
    if (is_string($candidate)) {
      $value = strtoupper(trim($candidate));
      if ($value !== '') {
        return $value;
      }
    }
  }

  return '';
}

function ptcgdm_build_card_display_name($card_name, $ptcgo_code, $card_number, $fallback = '') {
  $parts = [];

  $card_name = wp_strip_all_tags((string) $card_name);
  if ($card_name !== '') {
    $parts[] = $card_name;
  }

  $ptcgo_code = strtoupper(trim((string) $ptcgo_code));
  if ($ptcgo_code !== '') {
    $parts[] = $ptcgo_code;
  }

  $card_number = trim((string) $card_number);
  if ($card_number !== '') {
    $parts[] = $card_number;
  }

  if (!$parts) {
    $fallback = trim((string) $fallback);
    if ($fallback !== '') {
      $parts[] = $fallback;
    }
  }

  if (!$parts) {
    return '';
  }

  $display = implode(' ', $parts);
  if ($display === '') {
    return '';
  }

  return rtrim($display, '.') . '.';
}

function ptcgdm_format_card_string_list($values) {
  if (!is_array($values)) {
    return '';
  }
  $items = [];
  foreach ($values as $value) {
    if (is_string($value)) {
      $value = trim($value);
      if ($value !== '') {
        $items[] = esc_html($value);
      }
    }
  }
  if (!$items) {
    return '';
  }
  return implode(', ', $items);
}

function ptcgdm_build_card_description(array $card, array $set_info = [], $set_id = '') {
  if (!$card) {
    return '';
  }

  $sections = [];

  if (!empty($card['flavorText']) && is_string($card['flavorText'])) {
    $sections[] = '<p>' . esc_html($card['flavorText']) . '</p>';
  }

  $details = [];
  if (!empty($card['id'])) {
    $details[] = '<strong>' . esc_html__('Card ID', 'ptcgdm') . ':</strong> ' . esc_html($card['id']);
  }
  if (!empty($card['supertype'])) {
    $details[] = '<strong>' . esc_html__('Supertype', 'ptcgdm') . ':</strong> ' . esc_html($card['supertype']);
  }
  $subtypes = ptcgdm_format_card_string_list($card['subtypes'] ?? []);
  if ($subtypes !== '') {
    $details[] = '<strong>' . esc_html__('Subtypes', 'ptcgdm') . ':</strong> ' . $subtypes;
  }
  if (!empty($card['hp'])) {
    $details[] = '<strong>' . esc_html__('HP', 'ptcgdm') . ':</strong> ' . esc_html($card['hp']);
  }
  $types = ptcgdm_format_card_string_list($card['types'] ?? []);
  if ($types !== '') {
    $details[] = '<strong>' . esc_html__('Types', 'ptcgdm') . ':</strong> ' . $types;
  }
  if (!empty($card['evolvesFrom']) && is_string($card['evolvesFrom'])) {
    $details[] = '<strong>' . esc_html__('Evolves From', 'ptcgdm') . ':</strong> ' . esc_html($card['evolvesFrom']);
  }
  $evolvesTo = ptcgdm_format_card_string_list($card['evolvesTo'] ?? []);
  if ($evolvesTo !== '') {
    $details[] = '<strong>' . esc_html__('Evolves To', 'ptcgdm') . ':</strong> ' . $evolvesTo;
  }
  if (!empty($card['rarity'])) {
    $details[] = '<strong>' . esc_html__('Rarity', 'ptcgdm') . ':</strong> ' . esc_html($card['rarity']);
  }
  if (!empty($card['number'])) {
    $details[] = '<strong>' . esc_html__('Collector Number', 'ptcgdm') . ':</strong> ' . esc_html($card['number']);
  }
  if (!empty($card['artist'])) {
    $details[] = '<strong>' . esc_html__('Artist', 'ptcgdm') . ':</strong> ' . esc_html($card['artist']);
  }
  if (!empty($card['regulationMark'])) {
    $details[] = '<strong>' . esc_html__('Regulation Mark', 'ptcgdm') . ':</strong> ' . esc_html($card['regulationMark']);
  }

  if ($set_info) {
    $set_parts = [];
    if (!empty($set_info['name'])) {
      $set_parts[] = esc_html($set_info['name']);
    }
    if (!empty($set_info['series'])) {
      $set_parts[] = esc_html($set_info['series']);
    }
    $set_label = $set_parts ? implode(' — ', $set_parts) : '';
    if (!empty($set_info['releaseDate'])) {
      $set_label .= ($set_label ? ' ' : '') . '(' . esc_html($set_info['releaseDate']) . ')';
    }
    if ($set_label !== '') {
      $details[] = '<strong>' . esc_html__('Set', 'ptcgdm') . ':</strong> ' . $set_label;
    }
  } elseif ($set_id !== '') {
    $details[] = '<strong>' . esc_html__('Set', 'ptcgdm') . ':</strong> ' . esc_html(strtoupper($set_id));
  }

  if (!empty($card['nationalPokedexNumbers']) && is_array($card['nationalPokedexNumbers'])) {
    $numbers = [];
    foreach ($card['nationalPokedexNumbers'] as $number) {
      if (is_scalar($number)) {
        $numbers[] = esc_html((string) $number);
      }
    }
    if ($numbers) {
      $details[] = '<strong>' . esc_html__('National Pokédex', 'ptcgdm') . ':</strong> ' . implode(', ', $numbers);
    }
  }

  if ($details) {
    $sections[] = '<p><strong>' . esc_html__('Card Details', 'ptcgdm') . '</strong><br>' . implode('<br>', $details) . '</p>';
  }

  if (!empty($card['abilities']) && is_array($card['abilities'])) {
    $ability_items = [];
    foreach ($card['abilities'] as $ability) {
      if (!is_array($ability)) {
        continue;
      }
      $name = !empty($ability['name']) ? esc_html($ability['name']) : '';
      $type = !empty($ability['type']) ? esc_html($ability['type']) : '';
      $text = !empty($ability['text']) ? esc_html($ability['text']) : '';
      if ($name === '' && $text === '') {
        continue;
      }
      $content = '';
      if ($name !== '') {
        $content .= '<strong>' . $name . '</strong>';
      }
      if ($type !== '') {
        $content .= $content ? ' (' . $type . ')' : $type;
      }
      if ($text !== '') {
        $content .= ($content ? '<br>' : '') . $text;
      }
      if ($content !== '') {
        $ability_items[] = '<li>' . $content . '</li>';
      }
    }
    if ($ability_items) {
      $sections[] = '<h4>' . esc_html__('Abilities', 'ptcgdm') . '</h4><ul>' . implode('', $ability_items) . '</ul>';
    }
  }

  if (!empty($card['attacks']) && is_array($card['attacks'])) {
    $attack_items = [];
    foreach ($card['attacks'] as $attack) {
      if (!is_array($attack)) {
        continue;
      }
      $name = !empty($attack['name']) ? esc_html($attack['name']) : '';
      $cost = ptcgdm_format_card_string_list($attack['cost'] ?? []);
      $damage = !empty($attack['damage']) ? esc_html($attack['damage']) : '';
      $text = !empty($attack['text']) ? esc_html($attack['text']) : '';
      if ($name === '' && $text === '' && $damage === '') {
        continue;
      }
      $content = '';
      if ($name !== '') {
        $content .= '<strong>' . $name . '</strong>';
      }
      $meta = [];
      if ($cost !== '') {
        $meta[] = esc_html__('Cost', 'ptcgdm') . ': ' . $cost;
      }
      if ($damage !== '') {
        $meta[] = esc_html__('Damage', 'ptcgdm') . ': ' . $damage;
      }
      if ($meta) {
        $content .= $content ? ' — ' : '';
        $content .= implode(' · ', $meta);
      }
      if ($text !== '') {
        $content .= ($content ? '<br>' : '') . $text;
      }
      if ($content !== '') {
        $attack_items[] = '<li>' . $content . '</li>';
      }
    }
    if ($attack_items) {
      $sections[] = '<h4>' . esc_html__('Attacks', 'ptcgdm') . '</h4><ul>' . implode('', $attack_items) . '</ul>';
    }
  }

  if (!empty($card['rules']) && is_array($card['rules'])) {
    $rules = [];
    foreach ($card['rules'] as $rule) {
      if (is_string($rule)) {
        $rule = trim($rule);
        if ($rule !== '') {
          $rules[] = '<li>' . esc_html($rule) . '</li>';
        }
      }
    }
    if ($rules) {
      $sections[] = '<h4>' . esc_html__('Rules', 'ptcgdm') . '</h4><ul>' . implode('', $rules) . '</ul>';
    }
  }

  $weaknesses = [];
  if (!empty($card['weaknesses']) && is_array($card['weaknesses'])) {
    foreach ($card['weaknesses'] as $weakness) {
      if (!is_array($weakness)) {
        continue;
      }
      $type = !empty($weakness['type']) ? esc_html($weakness['type']) : '';
      $value = !empty($weakness['value']) ? esc_html($weakness['value']) : '';
      if ($type === '' && $value === '') {
        continue;
      }
      $weaknesses[] = $value !== '' ? $type . ' (' . $value . ')' : $type;
    }
  }
  if ($weaknesses) {
    $sections[] = '<p><strong>' . esc_html__('Weaknesses', 'ptcgdm') . ':</strong> ' . implode(', ', $weaknesses) . '</p>';
  }

  $resistances = [];
  if (!empty($card['resistances']) && is_array($card['resistances'])) {
    foreach ($card['resistances'] as $resistance) {
      if (!is_array($resistance)) {
        continue;
      }
      $type = !empty($resistance['type']) ? esc_html($resistance['type']) : '';
      $value = !empty($resistance['value']) ? esc_html($resistance['value']) : '';
      if ($type === '' && $value === '') {
        continue;
      }
      $resistances[] = $value !== '' ? $type . ' (' . $value . ')' : $type;
    }
  }
  if ($resistances) {
    $sections[] = '<p><strong>' . esc_html__('Resistances', 'ptcgdm') . ':</strong> ' . implode(', ', $resistances) . '</p>';
  }

  $retreat_cost = ptcgdm_format_card_string_list($card['retreatCost'] ?? []);
  if ($retreat_cost !== '') {
    $sections[] = '<p><strong>' . esc_html__('Retreat Cost', 'ptcgdm') . ':</strong> ' . $retreat_cost . '</p>';
  }

  if (!empty($card['legalities']) && is_array($card['legalities'])) {
    $legalities = [];
    foreach ($card['legalities'] as $format => $status) {
      if (!is_scalar($format) || !is_scalar($status)) {
        continue;
      }
      $legalities[] = esc_html(ucfirst((string) $format)) . ': ' . esc_html((string) $status);
    }
    if ($legalities) {
      $sections[] = '<p><strong>' . esc_html__('Legalities', 'ptcgdm') . ':</strong> ' . implode(' · ', $legalities) . '</p>';
    }
  }

  return implode("\n", $sections);
}

function ptcgdm_find_attachment_by_source_url($url) {
  if (!function_exists('get_posts')) {
    return 0;
  }
  $normalized = esc_url_raw((string) $url);
  if ($normalized === '') {
    return 0;
  }
  $attachments = get_posts([
    'post_type'      => 'attachment',
    'post_status'    => 'inherit',
    'posts_per_page' => 1,
    'fields'         => 'ids',
    'no_found_rows'  => true,
    'meta_key'       => '_ptcgdm_source_url',
    'meta_value'     => $normalized,
  ]);
  if (empty($attachments)) {
    return 0;
  }
  $attachment_id = (int) $attachments[0];
  return $attachment_id > 0 ? $attachment_id : 0;
}

function ptcgdm_pad_image_file_to_square($file_path) {
  $file_path = is_string($file_path) ? trim($file_path) : '';
  if ($file_path === '' || !file_exists($file_path) || !is_readable($file_path) || !is_writable($file_path)) {
    return false;
  }

  $image_info = @getimagesize($file_path);
  if (!is_array($image_info) || count($image_info) < 2) {
    return false;
  }

  $width  = isset($image_info[0]) ? (int) $image_info[0] : 0;
  $height = isset($image_info[1]) ? (int) $image_info[1] : 0;
  if ($width <= 0 || $height <= 0) {
    return false;
  }

  $target_size = defined('PTCGDM_PRODUCT_IMAGE_SIZE') ? (int) PTCGDM_PRODUCT_IMAGE_SIZE : 512;
  if ($target_size <= 0) {
    $target_size = 512;
  }

  if ($width === $target_size && $height === $target_size) {
    return false;
  }

  $scale = min($target_size / $width, $target_size / $height);
  if ($scale <= 0) {
    return false;
  }

  $scaled_width  = (int) round($width * $scale);
  $scaled_height = (int) round($height * $scale);
  $scaled_width  = max(1, min($target_size, $scaled_width));
  $scaled_height = max(1, min($target_size, $scaled_height));

  $updated = false;

  if (class_exists('Imagick')) {
    try {
      $imagick = new Imagick($file_path);
      if ($imagick) {
        $format = $imagick->getImageFormat();
        if (!is_string($format) || $format === '') {
          $format = 'PNG';
        }
        if (method_exists($imagick, 'setImageAlphaChannel')) {
          if (defined('Imagick::ALPHACHANNEL_SET')) {
            $imagick->setImageAlphaChannel(Imagick::ALPHACHANNEL_SET);
          } elseif (defined('Imagick::ALPHACHANNEL_ACTIVATE')) {
            $imagick->setImageAlphaChannel(Imagick::ALPHACHANNEL_ACTIVATE);
          }
        }
        if (method_exists($imagick, 'resizeImage')) {
          $resize_filter = defined('Imagick::FILTER_LANCZOS') ? Imagick::FILTER_LANCZOS : Imagick::FILTER_UNDEFINED;
          $imagick->resizeImage($scaled_width, $scaled_height, $resize_filter, 1, false);
        }
        $scaled_width  = max(1, (int) $imagick->getImageWidth());
        $scaled_height = max(1, (int) $imagick->getImageHeight());
        $scaled_width  = min($target_size, $scaled_width);
        $scaled_height = min($target_size, $scaled_height);
        $canvas = new Imagick();
        $background = class_exists('ImagickPixel') ? new ImagickPixel('transparent') : 'transparent';
        $canvas->newImage($target_size, $target_size, $background, $format);
        if (method_exists($canvas, 'setImageColorspace') && method_exists($imagick, 'getImageColorspace')) {
          $canvas->setImageColorspace($imagick->getImageColorspace());
        }
        if (method_exists($canvas, 'setImageAlphaChannel')) {
          if (defined('Imagick::ALPHACHANNEL_SET')) {
            $canvas->setImageAlphaChannel(Imagick::ALPHACHANNEL_SET);
          } elseif (defined('Imagick::ALPHACHANNEL_ACTIVATE')) {
            $canvas->setImageAlphaChannel(Imagick::ALPHACHANNEL_ACTIVATE);
          }
        }
        $offset_x = (int) floor(($target_size - $scaled_width) / 2);
        $offset_y = (int) floor(($target_size - $scaled_height) / 2);
        $composite_mode = defined('Imagick::COMPOSITE_OVER') ? Imagick::COMPOSITE_OVER : Imagick::COMPOSITE_DEFAULT;
        $canvas->compositeImage($imagick, $composite_mode, $offset_x, $offset_y);
        $updated = $canvas->writeImage($file_path);
        $canvas->clear();
        $canvas->destroy();
        $imagick->clear();
        $imagick->destroy();
      }
    } catch (Exception $e) {
      $updated = false;
    }
  }

  if (!$updated && function_exists('imagecreatetruecolor') && (function_exists('imagecopyresampled') || function_exists('imagecopy'))) {
    $image_type = isset($image_info[2]) ? (int) $image_info[2] : 0;
    $source     = null;
    $save_fn    = null;

    switch ($image_type) {
      case IMAGETYPE_JPEG:
        if (function_exists('imagecreatefromjpeg') && function_exists('imagejpeg')) {
          $source  = @imagecreatefromjpeg($file_path);
          $save_fn = static function ($resource, $path) {
            return imagejpeg($resource, $path, 90);
          };
        }
        break;
      case IMAGETYPE_PNG:
        if (function_exists('imagecreatefrompng') && function_exists('imagepng')) {
          $source  = @imagecreatefrompng($file_path);
          $save_fn = static function ($resource, $path) {
            return imagepng($resource, $path);
          };
        }
        break;
      case IMAGETYPE_GIF:
        if (function_exists('imagecreatefromgif') && function_exists('imagegif')) {
          $source  = @imagecreatefromgif($file_path);
          $save_fn = static function ($resource, $path) {
            return imagegif($resource, $path);
          };
        }
        break;
      case IMAGETYPE_WEBP:
        if (function_exists('imagecreatefromwebp') && function_exists('imagewebp')) {
          $source  = @imagecreatefromwebp($file_path);
          $save_fn = static function ($resource, $path) {
            return imagewebp($resource, $path, 90);
          };
        }
        break;
    }

    if ($source && $save_fn) {
      $canvas = imagecreatetruecolor($target_size, $target_size);
      if ($canvas) {
        $supports_alpha = function_exists('imagealphablending') && function_exists('imagesavealpha') && function_exists('imagecolorallocatealpha');
        if ($supports_alpha) {
          imagealphablending($canvas, false);
          $transparent = imagecolorallocatealpha($canvas, 255, 255, 255, 127);
          if ($transparent !== false) {
            imagefill($canvas, 0, 0, $transparent);
          }
          imagesavealpha($canvas, true);
        } else {
          $white = imagecolorallocate($canvas, 255, 255, 255);
          if ($white !== false) {
            imagefill($canvas, 0, 0, $white);
          }
        }
        $offset_x = (int) floor(($target_size - $scaled_width) / 2);
        $offset_y = (int) floor(($target_size - $scaled_height) / 2);
        $copy_fn = function_exists('imagecopyresampled') ? 'imagecopyresampled' : 'imagecopy';
        $copy_fn($canvas, $source, $offset_x, $offset_y, 0, 0, $scaled_width, $scaled_height, $width, $height);
        if ($supports_alpha) {
          imagealphablending($canvas, true);
        }
        $updated = (bool) $save_fn($canvas, $file_path);
        imagedestroy($canvas);
      }
      imagedestroy($source);
    }
  }

  if ($updated) {
    clearstatcache(true, $file_path);
  }

  return (bool) $updated;
}

function ptcgdm_pad_attachment_image_to_square($attachment_id) {
  if (!function_exists('get_attached_file')) {
    return false;
  }
  $attachment_id = (int) $attachment_id;
  if ($attachment_id <= 0) {
    return false;
  }

  $file_path = get_attached_file($attachment_id);
  if (!is_string($file_path) || $file_path === '') {
    return false;
  }

  $updated = ptcgdm_pad_image_file_to_square($file_path);
  if (!$updated) {
    return false;
  }

  if (function_exists('wp_generate_attachment_metadata') && function_exists('wp_update_attachment_metadata')) {
    $metadata = wp_generate_attachment_metadata($attachment_id, $file_path);
    if (!is_wp_error($metadata) && !empty($metadata)) {
      wp_update_attachment_metadata($attachment_id, $metadata);
    }
  }

  return true;
}

function ptcgdm_is_managed_product($product) {
  if (class_exists('WC_Product') && $product instanceof WC_Product) {
    $managed = $product->get_meta('_ptcgdm_managed');
    return (string) $managed === '1';
  }

  if (function_exists('wc_get_product')) {
    $product = wc_get_product($product);
    if ($product instanceof WC_Product) {
      $managed = $product->get_meta('_ptcgdm_managed');
      return (string) $managed === '1';
    }
  }

  return false;
}

function ptcgdm_refresh_product_image_cache($product) {
  if (!class_exists('WC_Product')) {
    return;
  }

  if (!($product instanceof WC_Product) && function_exists('wc_get_product')) {
    $product = wc_get_product($product);
  }

  if (!($product instanceof WC_Product)) {
    return;
  }

  $product_id = $product->get_id();
  if (!$product_id) {
    return;
  }

  if (function_exists('wc_delete_product_transients')) {
    wc_delete_product_transients($product_id);
  }

  if (function_exists('clean_post_cache')) {
    clean_post_cache($product_id);
  }

  if (function_exists('wp_cache_delete')) {
    wp_cache_delete($product_id, 'post_meta');
  }
}

function ptcgdm_prepare_variant_snapshot(array $variants) {
  $snapshot = [];
  foreach ($variants as $key => $data) {
    $variant_key = is_string($key) ? trim($key) : '';
    if ($variant_key === '') {
      continue;
    }
    $label = ptcgdm_inventory_variant_label($variant_key);
    $quantity = isset($data['qty']) ? max(0, (int) $data['qty']) : 0;
    $entry = [
      'key'   => $variant_key,
      'label' => $label,
      'qty'   => $quantity,
    ];
    if (array_key_exists('price', $data)) {
      $price = ptcgdm_normalize_inventory_price($data['price']);
      if ($price !== null) {
        $entry['price'] = $price;
      }
    }
    $snapshot[$variant_key] = $entry;
  }

  $ordered = [];
  foreach (ptcgdm_get_inventory_variant_labels() as $key => $_) {
    if (isset($snapshot[$key])) {
      $ordered[$key] = $snapshot[$key];
    }
  }

  foreach ($snapshot as $key => $entry) {
    if (!isset($ordered[$key])) {
      $ordered[$key] = $entry;
    }
  }

  return $ordered;
}

function ptcgdm_store_managed_product_snapshot($product, array $args = []) {
  if (!class_exists('WC_Product')) {
    return false;
  }

  if (!($product instanceof WC_Product) && function_exists('wc_get_product')) {
    $product = wc_get_product($product);
  }

  if (!($product instanceof WC_Product)) {
    return false;
  }

  $card_id = isset($args['card_id']) ? trim((string) $args['card_id']) : '';
  if ($card_id === '') {
    $card_id = trim((string) $product->get_meta('_ptcgdm_card_id'));
  }

  $display_name = isset($args['display_name']) ? trim((string) $args['display_name']) : '';
  if ($display_name === '') {
    $display_name = method_exists($product, 'get_name') ? trim((string) $product->get_name()) : '';
  }

  $variants = [];
  if (!empty($args['variants']) && is_array($args['variants'])) {
    $variants = ptcgdm_prepare_variant_snapshot($args['variants']);
  }

  $total_quantity = isset($args['total_quantity']) ? max(0, (int) $args['total_quantity']) : 0;
  if (!$total_quantity && $variants) {
    foreach ($variants as $variant) {
      $total_quantity += max(0, (int) ($variant['qty'] ?? 0));
    }
  }

  $primary_variant = isset($args['primary_variant']) ? trim((string) $args['primary_variant']) : '';
  if ($primary_variant === '' && !empty($args['primary_variant_key'])) {
    $primary_variant = trim((string) $args['primary_variant_key']);
  }

  $active_variants = [];
  if (!empty($args['active_variants']) && is_array($args['active_variants'])) {
    foreach ($args['active_variants'] as $key) {
      $key = is_string($key) ? trim($key) : '';
      if ($key !== '') {
        $active_variants[] = $key;
      }
    }
  }

  if (!$active_variants && $variants) {
    foreach ($variants as $key => $variant) {
      if (!empty($variant['qty'])) {
        $active_variants[] = $key;
      }
    }
  }

  $synced_at_gmt = isset($args['synced_at_gmt']) ? trim((string) $args['synced_at_gmt']) : '';
  if ($synced_at_gmt === '' && function_exists('current_time')) {
    $synced_at_gmt = current_time('mysql', true);
  }

  $synced_at_local = isset($args['synced_at']) ? trim((string) $args['synced_at']) : '';
  if ($synced_at_local === '' && function_exists('current_time')) {
    $synced_at_local = current_time('mysql');
  }

  $payload = [
    'card_id'         => $card_id,
    'display_name'    => $display_name,
    'total_quantity'  => $total_quantity,
    'variants'        => $variants,
    'active_variants' => $active_variants,
    'primary_variant' => $primary_variant,
    'synced_at'       => $synced_at_local,
    'synced_at_gmt'   => $synced_at_gmt,
  ];

  $encoded = function_exists('wp_json_encode') ? wp_json_encode($payload) : json_encode($payload);

  if (method_exists($product, 'update_meta_data')) {
    $product->update_meta_data('_ptcgdm_inventory_snapshot', $encoded);
    $product->update_meta_data('_ptcgdm_total_quantity', $total_quantity);
    if ($synced_at_gmt !== '') {
      $product->update_meta_data('_ptcgdm_last_sync_gmt', $synced_at_gmt);
    }
    if ($synced_at_local !== '') {
      $product->update_meta_data('_ptcgdm_last_sync', $synced_at_local);
    }
    if ($display_name !== '') {
      $product->update_meta_data('_ptcgdm_display_name', $display_name);
    }
  } else {
    $product_id = $product->get_id();
    if ($product_id) {
      if ($encoded !== null) {
        update_post_meta($product_id, '_ptcgdm_inventory_snapshot', $encoded);
      }
      update_post_meta($product_id, '_ptcgdm_total_quantity', $total_quantity);
      if ($synced_at_gmt !== '') {
        update_post_meta($product_id, '_ptcgdm_last_sync_gmt', $synced_at_gmt);
      }
      if ($synced_at_local !== '') {
        update_post_meta($product_id, '_ptcgdm_last_sync', $synced_at_local);
      }
      if ($display_name !== '') {
        update_post_meta($product_id, '_ptcgdm_display_name', $display_name);
      }
    }
  }

  return true;
}

function ptcgdm_get_managed_product_snapshot($product) {
  if (!class_exists('WC_Product')) {
    return [];
  }

  if (!($product instanceof WC_Product) && function_exists('wc_get_product')) {
    $product = wc_get_product($product);
  }

  if (!($product instanceof WC_Product)) {
    return [];
  }

  $raw = '';
  if (method_exists($product, 'get_meta')) {
    $raw = $product->get_meta('_ptcgdm_inventory_snapshot', true);
  }
  if (!is_string($raw) || $raw === '') {
    $raw = function_exists('get_post_meta') ? get_post_meta($product->get_id(), '_ptcgdm_inventory_snapshot', true) : '';
  }

  $decoded = is_string($raw) && $raw !== '' ? json_decode($raw, true) : [];
  if (!is_array($decoded)) {
    $decoded = [];
  }

  $card_id = isset($decoded['card_id']) ? trim((string) $decoded['card_id']) : '';
  if ($card_id === '') {
    $card_id = trim((string) $product->get_meta('_ptcgdm_card_id'));
  }

  $display_name = isset($decoded['display_name']) ? trim((string) $decoded['display_name']) : '';
  if ($display_name === '') {
    $display_name = method_exists($product, 'get_name') ? trim((string) $product->get_name()) : '';
  }

  $total_quantity = isset($decoded['total_quantity']) ? max(0, (int) $decoded['total_quantity']) : 0;
  if ($total_quantity === 0) {
    $meta_total = $product->get_meta('_ptcgdm_total_quantity', true);
    if ($meta_total !== '') {
      $total_quantity = max(0, (int) $meta_total);
    }
  }

  $variants = [];
  if (!empty($decoded['variants']) && is_array($decoded['variants'])) {
    $variants = ptcgdm_prepare_variant_snapshot($decoded['variants']);
  }

  $primary_variant = isset($decoded['primary_variant']) ? trim((string) $decoded['primary_variant']) : '';
  if ($primary_variant === '') {
    $primary_variant = trim((string) $product->get_meta('_ptcgdm_variant_key'));
  }

  $active_variants = [];
  if (!empty($decoded['active_variants']) && is_array($decoded['active_variants'])) {
    foreach ($decoded['active_variants'] as $key) {
      $key = is_string($key) ? trim($key) : '';
      if ($key !== '') {
        $active_variants[] = $key;
      }
    }
  }

  if (!$active_variants && $variants) {
    foreach ($variants as $key => $variant) {
      if (!empty($variant['qty'])) {
        $active_variants[] = $key;
      }
    }
  }

  $synced_at = isset($decoded['synced_at']) ? trim((string) $decoded['synced_at']) : '';
  if ($synced_at === '') {
    $meta = $product->get_meta('_ptcgdm_last_sync', true);
    if (is_string($meta) && $meta !== '') {
      $synced_at = $meta;
    }
  }

  $synced_at_gmt = isset($decoded['synced_at_gmt']) ? trim((string) $decoded['synced_at_gmt']) : '';
  if ($synced_at_gmt === '') {
    $meta = $product->get_meta('_ptcgdm_last_sync_gmt', true);
    if (is_string($meta) && $meta !== '') {
      $synced_at_gmt = $meta;
    }
  }

  return [
    'card_id'         => $card_id,
    'display_name'    => $display_name,
    'total_quantity'  => $total_quantity,
    'variants'        => $variants,
    'primary_variant' => $primary_variant,
    'active_variants' => $active_variants,
    'synced_at'       => $synced_at,
    'synced_at_gmt'   => $synced_at_gmt,
  ];
}

function ptcgdm_render_variant_snapshot_summary(array $snapshot) {
  if (empty($snapshot['variants']) || !is_array($snapshot['variants'])) {
    return '';
  }

  $parts = [];
  foreach ($snapshot['variants'] as $variant) {
    $label = isset($variant['label']) ? trim((string) $variant['label']) : '';
    if ($label === '') {
      $label = ptcgdm_inventory_variant_label($variant['key'] ?? '');
    }
    $qty = isset($variant['qty']) ? max(0, (int) $variant['qty']) : 0;
    $price = array_key_exists('price', $variant) ? ptcgdm_normalize_inventory_price($variant['price']) : null;
    $segment = $label !== '' ? $label : ucfirst((string) ($variant['key'] ?? ''));
    $segment .= ': ' . $qty;
    if ($price !== null) {
      if (function_exists('wc_price')) {
        $formatted_price = wc_price($price);
        $segment .= ' @ ' . trim(wp_strip_all_tags($formatted_price));
      } else {
        $segment .= ' @ ' . number_format((float) $price, 2, '.', '');
      }
    }
    $parts[] = $segment;
  }

  return implode(' | ', $parts);
}

function ptcgdm_format_snapshot_datetime($datetime) {
  $datetime = is_string($datetime) ? trim($datetime) : '';
  if ($datetime === '') {
    return '';
  }
  if (function_exists('mysql2date')) {
    $format = get_option('date_format', 'Y-m-d') . ' ' . get_option('time_format', 'H:i');
    $formatted = mysql2date($format, $datetime);
    if (is_string($formatted) && $formatted !== '') {
      return $formatted;
    }
  }
  return $datetime;
}

function ptcgdm_format_snapshot_relative_time($datetime) {
  $datetime = is_string($datetime) ? trim($datetime) : '';
  if ($datetime === '') {
    return '';
  }
  if (!function_exists('human_time_diff') || !function_exists('current_time')) {
    return $datetime;
  }
  $timestamp = strtotime($datetime);
  if (!$timestamp) {
    return $datetime;
  }
  $current = current_time('timestamp');
  if (!$current) {
    return $datetime;
  }
  return human_time_diff($timestamp, $current) . ' ago';
}

function ptcgdm_register_managed_product_metabox($post) {
  if (!is_object($post) || !function_exists('wc_get_product')) {
    return;
  }

  $post_id = isset($post->ID) ? (int) $post->ID : 0;
  if ($post_id <= 0) {
    return;
  }

  $product = wc_get_product($post_id);
  if (!ptcgdm_is_managed_product($product)) {
    return;
  }

  add_meta_box(
    'ptcgdm-managed-product-info',
    'Pokémon Inventory Sync',
    'ptcgdm_render_managed_product_metabox',
    'product',
    'side',
    'high'
  );
}

add_action('add_meta_boxes_product', 'ptcgdm_register_managed_product_metabox', 20, 1);

function ptcgdm_render_managed_product_metabox($post) {
  if (!function_exists('wc_get_product')) {
    echo '<p>' . esc_html__('WooCommerce is unavailable.', 'ptcgdm') . '</p>';
    return;
  }

  $product = wc_get_product($post->ID ?? 0);
  if (!($product instanceof WC_Product) || !ptcgdm_is_managed_product($product)) {
    echo '<p>' . esc_html__('This product is not currently managed by the deck inventory.', 'ptcgdm') . '</p>';
    return;
  }

  $snapshot = ptcgdm_get_managed_product_snapshot($product);

  echo '<p class="ptcgdm-managed-product-note">' . esc_html__('This product is kept in sync with the deck inventory. Update stock from the "Add Inventory" screen.', 'ptcgdm') . '</p>';

  $card_id = isset($snapshot['card_id']) ? $snapshot['card_id'] : '';
  if ($card_id !== '') {
    echo '<p><strong>' . esc_html__('Card ID', 'ptcgdm') . ':</strong> ' . esc_html($card_id) . '</p>';
  }

  $total_quantity = isset($snapshot['total_quantity']) ? (int) $snapshot['total_quantity'] : 0;
  echo '<p><strong>' . esc_html__('Total Quantity', 'ptcgdm') . ':</strong> ' . esc_html(number_format_i18n($total_quantity)) . '</p>';

  $synced_at = isset($snapshot['synced_at']) ? $snapshot['synced_at'] : '';
  if ($synced_at !== '') {
    $relative = ptcgdm_format_snapshot_relative_time($synced_at);
    $absolute = ptcgdm_format_snapshot_datetime($synced_at);
    $label = $relative !== '' ? $relative : $absolute;
    if ($label !== '') {
      echo '<p><strong>' . esc_html__('Last Sync', 'ptcgdm') . ':</strong> <span title="' . esc_attr($absolute) . '">' . esc_html($label) . '</span></p>';
    }
  }

  if (!empty($snapshot['variants'])) {
    echo '<table class="ptcgdm-variant-table"><thead><tr>';
    echo '<th scope="col">' . esc_html__('Variant', 'ptcgdm') . '</th>';
    echo '<th scope="col" class="ptcgdm-col-right">' . esc_html__('Qty', 'ptcgdm') . '</th>';
    echo '<th scope="col" class="ptcgdm-col-right">' . esc_html__('Price', 'ptcgdm') . '</th>';
    echo '</tr></thead><tbody>';

    foreach ($snapshot['variants'] as $variant) {
      $label = isset($variant['label']) ? $variant['label'] : ptcgdm_inventory_variant_label($variant['key'] ?? '');
      $qty = isset($variant['qty']) ? max(0, (int) $variant['qty']) : 0;
      $price = array_key_exists('price', $variant) ? ptcgdm_normalize_inventory_price($variant['price']) : null;
      echo '<tr>';
      echo '<td>' . esc_html($label) . '</td>';
      echo '<td class="ptcgdm-col-right">' . esc_html(number_format_i18n($qty)) . '</td>';
      if ($price !== null) {
        if (function_exists('wc_price')) {
          $display_price = wc_price($price);
          $display_price = trim(wp_strip_all_tags($display_price));
        } else {
          $display_price = number_format((float) $price, 2, '.', '');
        }
        echo '<td class="ptcgdm-col-right">' . esc_html($display_price) . '</td>';
      } else {
        echo '<td class="ptcgdm-col-right">—</td>';
      }
      echo '</tr>';
    }

    echo '</tbody></table>';
  } else {
    echo '<p>' . esc_html__('Variant details will appear after the next successful sync.', 'ptcgdm') . '</p>';
  }
}

function ptcgdm_output_inventory_admin_styles() {
  if (!function_exists('get_current_screen')) {
    return;
  }

  $screen = get_current_screen();
  if (!$screen || !in_array($screen->id, ['product', 'edit-product'], true)) {
    return;
  }

  echo '<style id="ptcgdm-admin-inventory-styles">';
  echo '.ptcgdm-managed-product-note{margin-top:0;margin-bottom:12px;font-size:13px;line-height:1.4;}';
  echo '.ptcgdm-variant-table{width:100%;border-collapse:collapse;margin-top:12px;}';
  echo '.ptcgdm-variant-table th,.ptcgdm-variant-table td{padding:4px 6px;border-bottom:1px solid #dcdcde;font-size:13px;line-height:1.4;}';
  echo '.ptcgdm-variant-table tbody tr:last-child td{border-bottom:0;}';
  echo '.ptcgdm-col-right{text-align:right;}';
  echo '.column-ptcgdm_inventory{width:180px;}';
  echo '.ptcgdm-inventory-column{font-size:12px;line-height:1.4;}';
  echo '.ptcgdm-inventory-column .ptcgdm-badge{display:inline-block;margin-bottom:2px;padding:2px 6px;background:#007cba;color:#fff;border-radius:3px;font-size:11px;line-height:1.2;text-transform:uppercase;}';
  echo '.ptcgdm-inventory-column .ptcgdm-qty{display:block;font-weight:600;}';
  echo '.ptcgdm-inventory-column .ptcgdm-summary{display:block;color:#50575e;margin-top:2px;}';
  echo '.ptcgdm-inventory-column .ptcgdm-synced{display:block;margin-top:4px;color:#646970;}';
  echo '</style>';
}

add_action('admin_head', 'ptcgdm_output_inventory_admin_styles');

function ptcgdm_register_inventory_status_column($columns) {
  if (!is_array($columns)) {
    return $columns;
  }

  $insertion_index = array_search('sku', array_keys($columns), true);
  $new_column = ['ptcgdm_inventory' => __('PTCG Inventory', 'ptcgdm')];

  if ($insertion_index === false) {
    return array_merge($columns, $new_column);
  }

  $before = array_slice($columns, 0, $insertion_index + 1, true);
  $after  = array_slice($columns, $insertion_index + 1, null, true);

  return $before + $new_column + $after;
}

add_filter('manage_edit-product_columns', 'ptcgdm_register_inventory_status_column');

function ptcgdm_render_inventory_status_column($column, $post_id) {
  if ($column !== 'ptcgdm_inventory' || !function_exists('wc_get_product')) {
    return;
  }

  $product = wc_get_product($post_id);
  if (!ptcgdm_is_managed_product($product)) {
    echo '—';
    return;
  }

  $snapshot = ptcgdm_get_managed_product_snapshot($product);
  $total    = isset($snapshot['total_quantity']) ? (int) $snapshot['total_quantity'] : 0;
  $summary  = ptcgdm_render_variant_snapshot_summary($snapshot);
  $synced   = isset($snapshot['synced_at']) ? $snapshot['synced_at'] : '';
  $relative = $synced !== '' ? ptcgdm_format_snapshot_relative_time($synced) : '';
  $absolute = $synced !== '' ? ptcgdm_format_snapshot_datetime($synced) : '';

  echo '<div class="ptcgdm-inventory-column">';
  echo '<span class="ptcgdm-badge">' . esc_html__('Managed', 'ptcgdm') . '</span>';
  echo '<span class="ptcgdm-qty">' . esc_html(sprintf(__('Total: %s', 'ptcgdm'), number_format_i18n($total))) . '</span>';

  if ($summary !== '') {
    echo '<span class="ptcgdm-summary" title="' . esc_attr($summary) . '">' . esc_html($summary) . '</span>';
  }

  if ($relative !== '') {
    $title = $absolute !== '' ? $absolute : $synced;
    echo '<span class="ptcgdm-synced" title="' . esc_attr($title) . '">' . esc_html(sprintf(__('Synced %s', 'ptcgdm'), $relative)) . '</span>';
  }

  echo '</div>';
}

add_action('manage_product_posts_custom_column', 'ptcgdm_render_inventory_status_column', 10, 2);

function ptcgdm_filter_managed_product_image($image, $product, $size, $attr, $placeholder) {
  if (!ptcgdm_is_managed_product($product)) {
    return $image;
  }

  static $ptcgdm_image_filter_active = false;
  if ($ptcgdm_image_filter_active) {
    return $image;
  }

  $ptcgdm_image_filter_active = true;

  $attr = is_array($attr) ? $attr : [];
  $existing_class = isset($attr['class']) ? (string) $attr['class'] : '';
  $attr['class'] = trim($existing_class . ' ptcgdm-managed-product-image');

  $replacement = '';
  if ($product instanceof WC_Product) {
    $replacement = $product->get_image('full', $attr, $placeholder);
  }

  $ptcgdm_image_filter_active = false;

  if (is_string($replacement) && $replacement !== '') {
    return $replacement;
  }

  if (is_array($attr) && !empty($attr['class'])) {
    $attr['class'] = trim((string) $attr['class']);
  }

  return $image;
}

add_filter('woocommerce_product_get_image', 'ptcgdm_filter_managed_product_image', 10, 5);

function ptcgdm_filter_managed_product_thumbnail($image, $post, $thumbnail_id, $size, $main_image) {
  if (!function_exists('wc_get_product')) {
    return $image;
  }

  $product = wc_get_product($post);
  if (!ptcgdm_is_managed_product($product) || !$thumbnail_id) {
    return $image;
  }

  $attr = [
    'class' => 'attachment-full size-full ptcgdm-managed-product-image',
  ];

  $replacement = wp_get_attachment_image($thumbnail_id, 'full', false, $attr);

  if (is_string($replacement) && $replacement !== '') {
    return $replacement;
  }

  return $image;
}

add_filter('woocommerce_get_product_thumbnail', 'ptcgdm_filter_managed_product_thumbnail', 10, 5);

function ptcgdm_filter_managed_product_image_attributes($attr, $attachment, $size) {
  if (!is_array($attr) || empty($attr['class']) || strpos((string) $attr['class'], 'ptcgdm-managed-product-image') === false) {
    return $attr;
  }

  $target = defined('PTCGDM_PRODUCT_IMAGE_SIZE') ? (int) PTCGDM_PRODUCT_IMAGE_SIZE : 512;
  if ($target <= 0) {
    return $attr;
  }

  $attr['width']  = $target;
  $attr['height'] = $target;

  $existing_style = isset($attr['style']) ? trim((string) $attr['style']) : '';
  $style_parts    = [];
  if ($existing_style !== '') {
    $style_parts[] = rtrim($existing_style, ';');
  }
  $style_parts[] = 'width:' . $target . 'px';
  $style_parts[] = 'height:' . $target . 'px';
  $attr['style']  = implode(';', $style_parts);
  if ($attr['style'] !== '' && substr($attr['style'], -1) !== ';') {
    $attr['style'] .= ';';
  }

  if (empty($attr['sizes'])) {
    $attr['sizes'] = $target . 'px';
  }

  return $attr;
}

add_filter('wp_get_attachment_image_attributes', 'ptcgdm_filter_managed_product_image_attributes', 10, 3);

function ptcgdm_output_managed_product_image_styles() {
  if (is_admin()) {
    return;
  }

  static $ptcgdm_styles_printed = false;
  if ($ptcgdm_styles_printed) {
    return;
  }
  $ptcgdm_styles_printed = true;

  echo '<style id="ptcgdm-managed-product-image-styles">.ptcgdm-managed-product-image{object-fit:contain !important;background-color:transparent !important;}</style>';
}

add_action('wp_head', 'ptcgdm_output_managed_product_image_styles');

function ptcgdm_is_inventory_syncing() {
  return !empty($GLOBALS['ptcgdm_inventory_sync_lock']);
}

function ptcgdm_set_inventory_syncing($flag) {
  if ($flag) {
    $GLOBALS['ptcgdm_inventory_sync_lock'] = true;
  } else {
    unset($GLOBALS['ptcgdm_inventory_sync_lock']);
  }
}

function ptcgdm_queue_inventory_sync() {
  $hook = 'ptcgdm_run_inventory_sync';
  $event_scheduled = false;
  $spawn_triggered = false;

  if (function_exists('wp_next_scheduled') && function_exists('wp_schedule_single_event')) {
    $existing = wp_next_scheduled($hook);
    if (!$existing) {
      $event_scheduled = (bool) wp_schedule_single_event(time() + 1, $hook);
    } else {
      $event_scheduled = true;
    }
  }

  if (!function_exists('spawn_cron')) {
    if (defined('ABSPATH')) {
      require_once ABSPATH . 'wp-includes/cron.php';
    }
  }

  if (function_exists('spawn_cron')) {
    spawn_cron();
    $spawn_triggered = true;
  }

  if ($event_scheduled || $spawn_triggered || ptcgdm_is_inventory_syncing()) {
    return true;
  }

  return false;
}

function ptcgdm_trigger_inventory_sync() {
  $queued = ptcgdm_queue_inventory_sync();
  ptcgdm_run_inventory_sync_event();
  return $queued;
}

function ptcgdm_run_inventory_sync_event() {
  if (ptcgdm_is_inventory_syncing()) {
    return;
  }

  $dir = trailingslashit(ptcgdm_get_inventory_dir());
  $path = $dir . PTCGDM_INVENTORY_FILENAME;

  if (!file_exists($path) || !is_readable($path)) {
    return;
  }

  $raw = @file_get_contents($path);
  if ($raw === false) {
    return;
  }

  $raw = trim((string) $raw);
  if ($raw === '') {
    ptcgdm_sync_inventory_products([]);
    return;
  }

  $data = json_decode($raw, true);
  if (!is_array($data)) {
    return;
  }

  $entries = [];
  if (!empty($data['cards']) && is_array($data['cards'])) {
    $entries = $data['cards'];
  }

  ptcgdm_sync_inventory_products($entries);
}

add_action('ptcgdm_run_inventory_sync', 'ptcgdm_run_inventory_sync_event');

function ptcgdm_set_product_image_from_url($product, $image_url, $title = '') {
  if (!($product instanceof WC_Product)) {
    return false;
  }
  $image_url = trim((string) $image_url);
  if ($image_url === '' || !filter_var($image_url, FILTER_VALIDATE_URL)) {
    return false;
  }
  $product_id = $product->get_id();
  if (!$product_id) {
    return false;
  }

  $attachment_id = ptcgdm_find_attachment_by_source_url($image_url);
  if ($attachment_id) {
    ptcgdm_pad_attachment_image_to_square($attachment_id);
    if (method_exists($product, 'get_image_id') && (int) $product->get_image_id() === (int) $attachment_id) {
      ptcgdm_refresh_product_image_cache($product);
      return false;
    }
    if (method_exists($product, 'set_image_id')) {
      $product->set_image_id($attachment_id);
      ptcgdm_refresh_product_image_cache($product);
      return true;
    }
    if (function_exists('set_post_thumbnail')) {
      set_post_thumbnail($product_id, $attachment_id);
      ptcgdm_refresh_product_image_cache($product);
    }
    return false;
  }

  if (!function_exists('media_sideload_image')) {
    if (defined('ABSPATH')) {
      if (!function_exists('download_url')) {
        require_once ABSPATH . 'wp-admin/includes/file.php';
      }
      require_once ABSPATH . 'wp-admin/includes/media.php';
      require_once ABSPATH . 'wp-admin/includes/image.php';
    } else {
      return false;
    }
  }

  $attachment_id = media_sideload_image($image_url, $product_id, $title, 'id');
  if (is_wp_error($attachment_id)) {
    return false;
  }
  $attachment_id = (int) $attachment_id;
  if ($attachment_id <= 0) {
    return false;
  }

  update_post_meta($attachment_id, '_ptcgdm_source_url', esc_url_raw($image_url));
  $title = trim((string) $title);
  if ($title !== '') {
    wp_update_post([
      'ID'           => $attachment_id,
      'post_title'   => $title,
      'post_excerpt' => $title,
    ]);
    update_post_meta($attachment_id, '_wp_attachment_image_alt', $title);
  }

  ptcgdm_pad_attachment_image_to_square($attachment_id);

  if (method_exists($product, 'set_image_id')) {
    $product->set_image_id($attachment_id);
    ptcgdm_refresh_product_image_cache($product);
    return true;
  }

  if (function_exists('set_post_thumbnail')) {
    set_post_thumbnail($product_id, $attachment_id);
    ptcgdm_refresh_product_image_cache($product);
  }
  return false;
}

function ptcgdm_normalize_inventory_price($value) {
  if ($value === null) {
    return null;
  }
  if (is_float($value) || is_int($value)) {
    $numeric = (float) $value;
    return ($numeric >= 0) ? $numeric : null;
  }
  if (is_string($value)) {
    $trimmed = trim($value);
    if ($trimmed === '') {
      return null;
    }
    $normalized = str_replace(',', '.', $trimmed);
    if (is_numeric($normalized)) {
      $numeric = (float) $normalized;
      return ($numeric >= 0) ? $numeric : null;
    }
  }
  return null;
}

function ptcgdm_get_inventory_variant_labels() {
  $variants = defined('PTCGDM_INVENTORY_VARIANTS') ? PTCGDM_INVENTORY_VARIANTS : [];
  return is_array($variants) ? $variants : [];
}

function ptcgdm_inventory_variant_label($key) {
  $labels = ptcgdm_get_inventory_variant_labels();
  $key = is_string($key) ? trim($key) : '';
  if ($key !== '' && isset($labels[$key])) {
    return $labels[$key];
  }
  if ($key === '') {
    return '';
  }
  return ucwords(str_replace(['_', '-'], ' ', $key));
}

function ptcgdm_inventory_variant_key_from_label($label) {
  $label = is_string($label) ? trim($label) : '';
  if ($label === '') {
    return '';
  }
  $labels = ptcgdm_get_inventory_variant_labels();
  foreach ($labels as $key => $name) {
    if (strcasecmp($name, $label) === 0) {
      return $key;
    }
  }
  $normalized_input = strtolower(preg_replace('/[^a-z0-9]+/', '', $label));
  foreach ($labels as $key => $name) {
    $normalized_label = strtolower(preg_replace('/[^a-z0-9]+/', '', $name));
    if ($normalized_label === $normalized_input) {
      return $key;
    }
  }
  return '';
}

function ptcgdm_normalize_inventory_quantity($value, $allow_negative = false) {
  if ($value === null) {
    $qty = 0;
  } elseif (is_int($value)) {
    $qty = $value;
  } elseif (is_float($value)) {
    $qty = (int) floor($value);
  } elseif (is_numeric($value)) {
    $qty = (int) $value;
  } else {
    $digits = preg_replace('/[^0-9-]/', '', (string) $value);
    $qty = (int) $digits;
  }
  if (!$allow_negative && $qty < 0) {
    return 0;
  }
  return $qty;
}

function ptcgdm_extract_inventory_variants_from_entry(array $entry) {
  $variants = [];
  $source = [];
  if (!empty($entry['variants']) && is_array($entry['variants'])) {
    $source = $entry['variants'];
  }
  foreach (ptcgdm_get_inventory_variant_labels() as $key => $label) {
    $data = [];
    if (isset($source[$key]) && is_array($source[$key])) {
      $data = $source[$key];
    }
    $qty = ptcgdm_normalize_inventory_quantity($data['qty'] ?? 0);
    if ($qty < 0) {
      $qty = 0;
    }
    $price = null;
    if (array_key_exists('price', $data)) {
      $price = ptcgdm_normalize_inventory_price($data['price']);
    }
    if ($qty > 0 || $price !== null) {
      $variants[$key] = ['qty' => $qty];
      if ($price !== null) {
        $variants[$key]['price'] = $price;
      }
    }
  }
  if (!$variants) {
    $qty = ptcgdm_normalize_inventory_quantity($entry['qty'] ?? 0);
    if ($qty < 0) {
      $qty = 0;
    }
    $price = array_key_exists('price', $entry) ? ptcgdm_normalize_inventory_price($entry['price']) : null;
    if ($qty > 0 || $price !== null) {
      $variants['normal'] = ['qty' => $qty];
      if ($price !== null) {
        $variants['normal']['price'] = $price;
      }
    }
  }
  return $variants;
}

function ptcgdm_delete_product_variations($product_id) {
  $product_id = (int) $product_id;
  if ($product_id <= 0) {
    return;
  }
  if (!function_exists('wc_get_products')) {
    return;
  }
  $variations = wc_get_products([
    'type'   => 'variation',
    'parent' => $product_id,
    'limit'  => -1,
    'return' => 'ids',
  ]);
  if (empty($variations)) {
    return;
  }
  foreach ($variations as $variation_id) {
    $variation_id = (int) $variation_id;
    if ($variation_id > 0) {
      wp_delete_post($variation_id, true);
    }
  }
}

function ptcgdm_sync_inventory_product_variations($product, array $active_variants, $sku) {
  if (!($product instanceof WC_Product_Variable)) {
    return null;
  }

  $product_id = $product->get_id();
  if ($product_id <= 0) {
    return null;
  }

  $attribute_name = 'Finish';
  $attribute_slug = sanitize_title($attribute_name);
  $options = [];
  foreach ($active_variants as $key => $data) {
    $options[] = ptcgdm_inventory_variant_label($key);
  }
  $options = array_values(array_unique(array_filter($options, 'strlen')));

  if (class_exists('WC_Product_Attribute')) {
    $attribute = new WC_Product_Attribute();
    $attribute->set_id(0);
    $attribute->set_name($attribute_name);
    $attribute->set_options($options);
    $attribute->set_position(0);
    $attribute->set_visible(true);
    $attribute->set_variation(true);
    $product->set_attributes([$attribute]);
  } else {
    $product->set_attributes([
      $attribute_name => [
        'name'         => $attribute_name,
        'value'        => implode(' | ', $options),
        'position'     => 0,
        'is_visible'   => 1,
        'is_variation' => 1,
        'is_taxonomy'  => 0,
      ],
    ]);
  }

  $existing_children = [];
  $existing_ids = [];
  if (method_exists($product, 'get_children')) {
    foreach ($product->get_children() as $child_id) {
      $child_id = (int) $child_id;
      if ($child_id <= 0) {
        continue;
      }
      $existing_ids[] = $child_id;
      $child = wc_get_product($child_id);
      if ($child instanceof WC_Product_Variation) {
        $variant_key = $child->get_meta('_ptcgdm_variant_key');
        if ($variant_key === '' || $variant_key === null) {
          $attr_value = $child->get_attribute($attribute_slug);
          if ($attr_value !== '') {
            $variant_key = ptcgdm_inventory_variant_key_from_label($attr_value);
          }
        }
        if ($variant_key !== '') {
          $existing_children[$variant_key] = $child;
        }
      }
    }
  }

  $used_ids = [];
  $min_price = null;

  foreach ($active_variants as $key => $data) {
    $qty = ptcgdm_normalize_inventory_quantity($data['qty'] ?? 0);
    if ($qty < 0) {
      $qty = 0;
    }
    $price = null;
    if (array_key_exists('price', $data)) {
      $price = ptcgdm_normalize_inventory_price($data['price']);
    }

    if (isset($existing_children[$key])) {
      $variation = $existing_children[$key];
    } else {
      $variation = new WC_Product_Variation();
      $variation->set_parent_id($product_id);
      $variation->set_status('publish');
    }

    $label = ptcgdm_inventory_variant_label($key);
    $attributes = [$attribute_slug => $label];
    if (method_exists($variation, 'set_attributes')) {
      $variation->set_attributes($attributes);
    }
    $variation->update_meta_data('_ptcgdm_variant_key', $key);
    if (method_exists($variation, 'set_manage_stock')) {
      $variation->set_manage_stock(true);
    }
    if (method_exists($variation, 'set_backorders')) {
      $variation->set_backorders('no');
    }
    $variation->set_stock_quantity($qty);
    $variation->set_stock_status($qty > 0 ? 'instock' : 'outofstock');

    if ($price !== null) {
      $formatted_price = function_exists('wc_format_decimal') ? wc_format_decimal($price) : number_format((float) $price, 2, '.', '');
      $variation->set_regular_price($formatted_price);
      $variation->set_price($formatted_price);
      if ($min_price === null || $price < $min_price) {
        $min_price = $price;
      }
    } else {
      $variation->set_regular_price('');
      $variation->set_price('');
    }

    $sku_suffix = strtoupper(preg_replace('/[^a-z0-9]+/i', '', (string) $key));
    if ($sku_suffix === '') {
      $sku_suffix = strtoupper(substr((string) $key, 0, 6));
    }
    $variation->set_sku($sku . '-' . $sku_suffix);
    $variation->save();
    $used_ids[] = $variation->get_id();
  }

  $to_delete = array_diff($existing_ids, $used_ids);
  foreach ($to_delete as $delete_id) {
    $delete_id = (int) $delete_id;
    if ($delete_id > 0) {
      wp_delete_post($delete_id, true);
    }
  }

  return $min_price;
}

function ptcgdm_ensure_product_category_path(array $names) {
  if (!function_exists('term_exists') || !function_exists('wp_insert_term')) {
    return [];
  }

  $parent = 0;
  $term_ids = [];

  foreach ($names as $name) {
    $name = trim((string) $name);
    if ($name === '') {
      continue;
    }

    $term = term_exists($name, 'product_cat', $parent);
    if (!$term) {
      $slug = function_exists('sanitize_title') ? sanitize_title($name) : sanitize_key($name);
      $term = term_exists($slug, 'product_cat', $parent);
    }

    if (is_array($term) && isset($term['term_id'])) {
      $term_id = (int) $term['term_id'];
    } elseif (is_numeric($term)) {
      $term_id = (int) $term;
    } else {
      $args = ['parent' => $parent];
      if (!isset($slug)) {
        $slug = function_exists('sanitize_title') ? sanitize_title($name) : sanitize_key($name);
      }
      if ($slug !== '') {
        $args['slug'] = $slug;
      }
      $inserted = wp_insert_term($name, 'product_cat', $args);
      if (is_wp_error($inserted)) {
        break;
      }
      $term_id = isset($inserted['term_id']) ? (int) $inserted['term_id'] : 0;
    }

    if ($term_id <= 0) {
      break;
    }

    $term_ids[] = $term_id;
    $parent = $term_id;
    unset($slug);
  }

  return $term_ids;
}

function ptcgdm_assign_product_categories($product, array $card_data, array $card_preview = []) {
  if (!($product instanceof WC_Product)) {
    return;
  }

  $supertype = '';
  if (!empty($card_data['supertype']) && is_string($card_data['supertype'])) {
    $supertype = $card_data['supertype'];
  } elseif (!empty($card_preview['supertype']) && is_string($card_preview['supertype'])) {
    $supertype = $card_preview['supertype'];
  }

  $normalized_supertype = trim((string) $supertype);
  if ($normalized_supertype !== '' && function_exists('remove_accents')) {
    $normalized_supertype = remove_accents($normalized_supertype);
  }
  $normalized_supertype = strtolower($normalized_supertype);

  $paths = [];
  $root_path = ['Pokemon TCG Playable Singles'];

  if ($normalized_supertype === 'pokemon') {
    $paths[] = array_merge($root_path, ['pokemon']);
  } elseif ($normalized_supertype === 'trainer') {
    $trainer_base = array_merge($root_path, ['trainer cards']);
    $paths[] = $trainer_base;

    $subtypes = [];
    if (!empty($card_data['subtypes'])) {
      if (is_array($card_data['subtypes'])) {
        $subtypes = $card_data['subtypes'];
      } elseif (is_string($card_data['subtypes'])) {
        $subtypes = array_map('trim', explode(',', $card_data['subtypes']));
      }
    } elseif (!empty($card_preview['subtypes'])) {
      if (is_array($card_preview['subtypes'])) {
        $subtypes = $card_preview['subtypes'];
      } elseif (is_string($card_preview['subtypes'])) {
        $subtypes = array_map('trim', explode(',', $card_preview['subtypes']));
      }
    }

    if ($subtypes) {
      $subtypes = array_filter(array_map(function ($subtype) {
        $value = is_string($subtype) ? trim($subtype) : '';
        return $value !== '' ? $value : null;
      }, $subtypes));

      foreach ($subtypes as $subtype) {
        $paths[] = array_merge($trainer_base, [$subtype]);
      }
    }
  }

  if (!$paths) {
    return;
  }

  $category_ids = [];
  foreach ($paths as $path) {
    $ids = ptcgdm_ensure_product_category_path($path);
    if ($ids) {
      $category_ids = array_merge($category_ids, $ids);
    }
  }

  if (!$category_ids) {
    return;
  }

  $category_ids = array_values(array_unique(array_map('intval', $category_ids), SORT_NUMERIC));

  if (method_exists($product, 'set_category_ids')) {
    $product->set_category_ids($category_ids);
  } else {
    $product_id = $product->get_id();
    if ($product_id && function_exists('wp_set_object_terms')) {
      wp_set_object_terms($product_id, $category_ids, 'product_cat', false);
    }
  }
}

function ptcgdm_sync_inventory_products(array $entries) {
  if (!function_exists('wc_get_product_id_by_sku') || !function_exists('wc_get_product') || !class_exists('WC_Product_Simple') || !class_exists('WC_Product_Variable') || !class_exists('WC_Product_Variation') || !class_exists('WC_Product')) {
    return;
  }

  $previous_sync_state = ptcgdm_is_inventory_syncing();
  ptcgdm_set_inventory_syncing(true);

  try {
    $synced_skus = [];

    foreach ($entries as $entry) {
      if (!is_array($entry)) {
        continue;
      }
      $card_id = isset($entry['id']) ? trim((string) $entry['id']) : '';
      if ($card_id === '') {
        continue;
      }

      $variants = ptcgdm_extract_inventory_variants_from_entry($entry);
      if (!$variants) {
        continue;
      }

      $active_variants = [];
      $total_qty = 0;
      foreach ($variants as $variant_key => $variant_data) {
        $qty = ptcgdm_normalize_inventory_quantity($variant_data['qty'] ?? 0);
        if ($qty < 0) {
          $qty = 0;
        }
        $variants[$variant_key]['qty'] = $qty;
        $price_value = array_key_exists('price', $variant_data) ? ptcgdm_normalize_inventory_price($variant_data['price']) : null;
        if ($price_value !== null) {
          $variants[$variant_key]['price'] = $price_value;
        } else {
          unset($variants[$variant_key]['price']);
        }
        if ($qty > 0) {
          $active_variants[$variant_key] = $variants[$variant_key];
          $total_qty += $qty;
        }
      }

      $variant_order = array_keys(ptcgdm_get_inventory_variant_labels());
      $primary_variant_key = '';
      foreach ($variant_order as $order_key) {
        if (isset($variants[$order_key]) && $variants[$order_key]['qty'] > 0) {
          $primary_variant_key = $order_key;
          break;
        }
      }
      if ($primary_variant_key === '') {
        foreach ($variant_order as $order_key) {
          if (isset($variants[$order_key])) {
            $primary_variant_key = $order_key;
            break;
          }
        }
        if ($primary_variant_key === '') {
          $variant_keys = array_keys($variants);
          if (isset($variant_keys[0])) {
            $primary_variant_key = $variant_keys[0];
          }
        }
      }
      if ($primary_variant_key === '') {
        continue;
      }

      $primary_variant = $variants[$primary_variant_key];
      $primary_qty = isset($primary_variant['qty']) ? (int) $primary_variant['qty'] : 0;
      $primary_price = isset($primary_variant['price']) ? $primary_variant['price'] : null;
      $requires_variable = count($active_variants) > 1;

      $card_preview = ptcgdm_lookup_card_preview($card_id);
      $set_id = isset($card_preview['set']) ? trim((string) $card_preview['set']) : '';
      if ($set_id === '') {
        $set_id = ptcgdm_extract_set_from_card($card_id);
      }
      $card_data = ptcgdm_lookup_card_data($card_id, $set_id);
      $set_info = $set_id !== '' ? ptcgdm_lookup_set_info($set_id) : [];
      $description = ptcgdm_build_card_description($card_data, $set_info, $set_id);

      $base_product_name = isset($card_preview['name']) && $card_preview['name'] !== ''
        ? $card_preview['name']
        : $card_id;
      $base_product_name = wp_strip_all_tags($base_product_name);
      if ($base_product_name === '') {
        $base_product_name = $card_id;
      }

      $ptcgo_code = ptcgdm_extract_ptcgo_code($card_data, $set_info);
      $card_number = '';
      if (!empty($card_preview['number'])) {
        $card_number = $card_preview['number'];
      } elseif (!empty($card_data['number'])) {
        $card_number = $card_data['number'];
      }
      $card_number = trim((string) $card_number);

      $display_name = ptcgdm_build_card_display_name($base_product_name, $ptcgo_code, $card_number, $card_id);
      if ($display_name === '') {
        $display_name = $base_product_name;
      }

      $image_url = '';
      if (!empty($card_data['images']['large']) && filter_var($card_data['images']['large'], FILTER_VALIDATE_URL)) {
        $image_url = $card_data['images']['large'];
      } elseif (!empty($card_data['images']['small']) && filter_var($card_data['images']['small'], FILTER_VALIDATE_URL)) {
        $image_url = $card_data['images']['small'];
      } elseif (!empty($card_preview['image']) && filter_var($card_preview['image'], FILTER_VALIDATE_URL)) {
        $image_url = $card_preview['image'];
      }

      $sku = $card_id;
      $product_id = wc_get_product_id_by_sku($sku);
      $product = null;

      if ($product_id) {
        $product = wc_get_product($product_id);
        if ($product instanceof WC_Product) {
          $current_type = method_exists($product, 'get_type') ? $product->get_type() : '';
          if ($requires_variable && $current_type !== 'variable') {
            $class_name = class_exists('WC_Product_Factory') ? WC_Product_Factory::get_product_classname($product_id, 'variable') : '';
            if ($class_name && class_exists($class_name)) {
              $product = new $class_name($product_id);
            } else {
              $product = new WC_Product_Variable($product_id);
            }
            if (method_exists($product, 'set_type')) {
              $product->set_type('variable');
            }
          } elseif (!$requires_variable && $current_type !== 'simple') {
            $class_name = class_exists('WC_Product_Factory') ? WC_Product_Factory::get_product_classname($product_id, 'simple') : '';
            if ($class_name && class_exists($class_name)) {
              $product = new $class_name($product_id);
            } else {
              $product = new WC_Product_Simple($product_id);
            }
            if (method_exists($product, 'set_type')) {
              $product->set_type('simple');
            }
          }
        }
      }

      if (!$product instanceof WC_Product) {
        $product = $requires_variable ? new WC_Product_Variable() : new WC_Product_Simple();
        if (method_exists($product, 'set_sku')) {
          $product->set_sku($sku);
        }
        if (method_exists($product, 'set_status')) {
          $product->set_status('publish');
        }
      }

      $product->set_name($display_name);
      if (method_exists($product, 'set_short_description')) {
        $product->set_short_description($display_name);
      }
      if (method_exists($product, 'set_slug')) {
        $slug = function_exists('sanitize_title') ? sanitize_title($display_name) : sanitize_key($display_name);
        if ($slug === '') {
          $slug = function_exists('sanitize_title') ? sanitize_title($card_id) : sanitize_key($card_id);
        }
        if ($slug !== '') {
          $product->set_slug($slug);
        }
      }

      if ($requires_variable) {
        $product->set_manage_stock(false);
        if (method_exists($product, 'set_stock_quantity')) {
          $product->set_stock_quantity(null);
        }
        $product->set_stock_status($total_qty > 0 ? 'instock' : 'outofstock');
      } else {
        $product->set_manage_stock(true);
        $product->set_stock_quantity($primary_qty);
        $product->set_stock_status($primary_qty > 0 ? 'instock' : 'outofstock');
        if (method_exists($product, 'set_attributes')) {
          $product->set_attributes([]);
        }
      }

      $product->set_catalog_visibility('visible');
      $product->update_meta_data('_ptcgdm_managed', '1');
      $product->update_meta_data('_ptcgdm_card_id', $card_id);
      if ($description !== '') {
        $product->set_description($description);
      }

      ptcgdm_assign_product_categories($product, is_array($card_data) ? $card_data : [], is_array($card_preview) ? $card_preview : []);

      if (!$product->get_id()) {
        $product->save();
      }

      if (!$requires_variable && $product->get_id()) {
        ptcgdm_delete_product_variations($product->get_id());
        $product->update_meta_data('_ptcgdm_variant_key', $primary_variant_key);
        if ($primary_price !== null) {
          $formatted_price = function_exists('wc_format_decimal') ? wc_format_decimal($primary_price) : number_format((float) $primary_price, 2, '.', '');
          $product->set_regular_price($formatted_price);
          $product->set_price($formatted_price);
        }
      } else {
        $product->update_meta_data('_ptcgdm_variant_key', 'variable');
        $min_price = ptcgdm_sync_inventory_product_variations($product, $active_variants, $sku);
        if ($min_price !== null) {
          $formatted_price = function_exists('wc_format_decimal') ? wc_format_decimal($min_price) : number_format((float) $min_price, 2, '.', '');
          $product->set_regular_price($formatted_price);
          $product->set_price($formatted_price);
        } else {
          $product->set_regular_price('');
          $product->set_price('');
        }
      }

      ptcgdm_store_managed_product_snapshot($product, [
        'card_id'         => $card_id,
        'display_name'    => $display_name,
        'variants'        => $variants,
        'total_quantity'  => $total_qty,
        'primary_variant' => $primary_variant_key,
        'active_variants' => array_keys($active_variants),
      ]);

      $product->save();

      if ($image_url !== '') {
        $image_updated = ptcgdm_set_product_image_from_url($product, $image_url, $display_name);
        if ($image_updated) {
          $product->save();
        } else {
          ptcgdm_refresh_product_image_cache($product);
        }
      } else {
        ptcgdm_refresh_product_image_cache($product);
      }

      $synced_skus[$sku] = true;
    }

    ptcgdm_zero_unlisted_inventory_products($synced_skus);
  } finally {
    ptcgdm_set_inventory_syncing($previous_sync_state);
  }
}

function ptcgdm_zero_unlisted_inventory_products(array $active_skus) {
  if (!function_exists('wc_get_products')) {
    return;
  }

  $statuses = function_exists('wc_get_product_statuses') ? array_keys(wc_get_product_statuses()) : ['publish'];
  $products = wc_get_products([
    'limit'      => -1,
    'return'     => 'objects',
    'status'     => $statuses,
    'meta_query' => [
      [
        'key'   => '_ptcgdm_managed',
        'value' => '1',
      ],
    ],
  ]);

  if (empty($products)) {
    return;
  }

  foreach ($products as $product) {
    if (!($product instanceof WC_Product)) {
      continue;
    }

    $sku = trim((string) $product->get_sku());
    if ($sku === '' || isset($active_skus[$sku])) {
      continue;
    }

    $is_variable = ($product instanceof WC_Product_Variable);
    if (!$is_variable && method_exists($product, 'is_type')) {
      $is_variable = $product->is_type('variable');
    }

    if ($is_variable) {
      $parent_changed = false;

      if (method_exists($product, 'get_manage_stock') && method_exists($product, 'set_manage_stock')) {
        if ($product->get_manage_stock()) {
          $product->set_manage_stock(false);
          $parent_changed = true;
        }
      }

      if (method_exists($product, 'get_stock_quantity') && method_exists($product, 'set_stock_quantity')) {
        $parent_qty = $product->get_stock_quantity();
        if ($parent_qty !== null) {
          $product->set_stock_quantity(null);
          $parent_changed = true;
        }
      }

      $parent_status = method_exists($product, 'get_stock_status') ? $product->get_stock_status() : '';
      if ($parent_status !== 'outofstock') {
        $product->set_stock_status('outofstock');
        $parent_changed = true;
      }

      $children = method_exists($product, 'get_children') ? $product->get_children() : [];
      if (!empty($children)) {
        $variant_entries = [];
        foreach ($children as $child_id) {
          $child_id = (int) $child_id;
          if ($child_id <= 0) {
            continue;
          }

          $variation = wc_get_product($child_id);
          if (!($variation instanceof WC_Product_Variation)) {
            continue;
          }

          $current_qty = (int) max(0, (int) $variation->get_stock_quantity());
          $current_status = method_exists($variation, 'get_stock_status') ? $variation->get_stock_status() : '';

          if ($current_qty === 0 && $current_status === 'outofstock') {
            continue;
          }

          $variant_key = trim((string) $variation->get_meta('_ptcgdm_variant_key'));
          if ($variant_key === '' && method_exists($variation, 'get_attribute')) {
            $finish = $variation->get_attribute('finish');
            if ($finish !== '') {
              $variant_key = ptcgdm_inventory_variant_key_from_label($finish);
            }
          }

          if (method_exists($variation, 'set_manage_stock')) {
            $variation->set_manage_stock(true);
          }
          if (method_exists($variation, 'set_backorders')) {
            $variation->set_backorders('no');
          }
          $variation->set_stock_quantity(0);
          $variation->set_stock_status('outofstock');
          $variation->save();

          if ($variant_key !== '') {
            $variant_entries[$variant_key] = ['qty' => 0];
          }
        }
        ptcgdm_store_managed_product_snapshot($product, [
          'variants'        => $variant_entries,
          'total_quantity'  => 0,
          'active_variants' => [],
        ]);
        $product->save();
      } else {
        ptcgdm_store_managed_product_snapshot($product, [
          'variants'        => [],
          'total_quantity'  => 0,
          'active_variants' => [],
        ]);
        $product->save();
      }

      continue;
    }

    $current_qty = (int) max(0, (int) $product->get_stock_quantity());
    $current_status = method_exists($product, 'get_stock_status') ? $product->get_stock_status() : '';

    if (method_exists($product, 'set_manage_stock')) {
      $product->set_manage_stock(true);
    }
    $product->set_stock_quantity(0);
    $product->set_stock_status('outofstock');
    ptcgdm_store_managed_product_snapshot($product, [
      'total_quantity'  => 0,
      'variants'        => [],
      'active_variants' => [],
    ]);
    $product->save();
  }
}

function ptcgdm_remove_inventory_card_entry($card_id) {
  $card_id = trim((string) $card_id);
  if ($card_id === '') {
    return new WP_Error('ptcgdm_invalid_card', __('Invalid card ID.', 'ptcgdm'));
  }

  $dir  = trailingslashit(ptcgdm_get_inventory_dir());
  $path = $dir . PTCGDM_INVENTORY_FILENAME;

  if (!file_exists($path)) {
    return ['removed' => false, 'path' => $path];
  }

  if (!is_readable($path)) {
    return new WP_Error('ptcgdm_inventory_unreadable', __('Inventory file is not readable.', 'ptcgdm'));
  }

  $raw = @file_get_contents($path);
  if ($raw === false) {
    return new WP_Error('ptcgdm_inventory_read_failed', __('Unable to read inventory file.', 'ptcgdm'));
  }

  $data = json_decode($raw, true);
  if (!is_array($data)) {
    return new WP_Error('ptcgdm_inventory_invalid', __('Inventory data is invalid.', 'ptcgdm'));
  }

  if (empty($data['cards']) || !is_array($data['cards'])) {
    $data['cards'] = [];
  }

  $removed_entry = null;
  $filtered      = [];

  foreach ($data['cards'] as $entry) {
    if (!is_array($entry)) {
      continue;
    }

    $entry_id = isset($entry['id']) ? trim((string) $entry['id']) : '';
    if ($entry_id === $card_id) {
      $removed_entry = $entry;
      continue;
    }

    $filtered[] = $entry;
  }

  if ($removed_entry === null) {
    return ['removed' => false, 'path' => $path];
  }

  if (!is_writable($path)) {
    return new WP_Error('ptcgdm_inventory_unwritable', __('Inventory file is not writable.', 'ptcgdm'));
  }

  $data['cards'] = array_values($filtered);

  $flags   = JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES;
  $encoded = function_exists('wp_json_encode') ? wp_json_encode($data, $flags) : json_encode($data, $flags);

  if ($encoded === false || $encoded === null) {
    return new WP_Error('ptcgdm_inventory_encode_failed', __('Unable to encode inventory data.', 'ptcgdm'));
  }

  if (file_put_contents($path, $encoded) === false) {
    return new WP_Error('ptcgdm_inventory_write_failed', __('Unable to update inventory file.', 'ptcgdm'));
  }

  return [
    'removed' => true,
    'entry'   => $removed_entry,
    'path'    => $path,
  ];
}

function ptcgdm_delete_inventory_product_by_card($card_id) {
  $card_id = trim((string) $card_id);
  if ($card_id === '') {
    return ['deleted' => false];
  }

  if (!function_exists('wc_get_product_id_by_sku') || !function_exists('wc_get_product') || !class_exists('WC_Product')) {
    return ['deleted' => false];
  }

  $product_id = (int) wc_get_product_id_by_sku($card_id);

  if (!$product_id && function_exists('wc_get_products')) {
    $matches = wc_get_products([
      'limit'      => 1,
      'return'     => 'ids',
      'status'     => ['publish', 'pending', 'draft', 'private'],
      'meta_query' => [[
        'key'   => '_ptcgdm_card_id',
        'value' => $card_id,
      ]],
    ]);
    if (!empty($matches)) {
      $product_id = (int) $matches[0];
    }
  }

  if (!$product_id) {
    return ['deleted' => false];
  }

  $product = wc_get_product($product_id);
  if (!($product instanceof WC_Product)) {
    return ['deleted' => false, 'product_id' => $product_id];
  }

  if (!ptcgdm_is_managed_product($product)) {
    $product_card_id = method_exists($product, 'get_meta') ? (string) $product->get_meta('_ptcgdm_card_id') : '';
    if ($product_card_id !== $card_id) {
      return ['deleted' => false, 'product_id' => $product_id];
    }
  }

  $is_variable = false;
  if (class_exists('WC_Product_Variable') && $product instanceof WC_Product_Variable) {
    $is_variable = true;
  } elseif (method_exists($product, 'is_type')) {
    $is_variable = $product->is_type('variable');
  }

  if ($is_variable) {
    ptcgdm_delete_product_variations($product_id);
  }

  if (function_exists('wc_delete_product_transients')) {
    wc_delete_product_transients($product_id);
  }

  if (function_exists('clean_post_cache')) {
    clean_post_cache($product_id);
  }

  if (function_exists('wp_cache_delete')) {
    wp_cache_delete($product_id, 'post_meta');
  }

  if (function_exists('wp_delete_post')) {
    wp_delete_post($product_id, true);
  }

  return ['deleted' => true, 'product_id' => $product_id];
}

function ptcgdm_update_inventory_card_quantity($card_id, $quantity, ?array $variant_quantities = null) {
  $card_id = trim((string) $card_id);
  if ($card_id === '') {
    return false;
  }

  $dir = trailingslashit(ptcgdm_get_inventory_dir());
  $path = $dir . PTCGDM_INVENTORY_FILENAME;
  if (!file_exists($path) || !is_readable($path)) {
    return false;
  }

  $raw = @file_get_contents($path);
  if ($raw === false || $raw === '') {
    return false;
  }

  $data = json_decode($raw, true);
  if (!is_array($data)) {
    return false;
  }

  if (empty($data['cards']) || !is_array($data['cards'])) {
    $data['cards'] = [];
  }

  $quantity = max(0, (int) $quantity);

  if ($variant_quantities === null) {
    $variant_quantities = ['normal' => $quantity];
  } else {
    $normalized = [];
    foreach ($variant_quantities as $key => $value) {
      $normalized_key = is_string($key) ? trim($key) : '';
      if ($normalized_key === '') {
        continue;
      }
      if (!array_key_exists($normalized_key, ptcgdm_get_inventory_variant_labels())) {
        $normalized_key = ptcgdm_inventory_variant_key_from_label($normalized_key);
      }
      if ($normalized_key === '' || !array_key_exists($normalized_key, ptcgdm_get_inventory_variant_labels())) {
        continue;
      }
      $normalized[$normalized_key] = max(0, ptcgdm_normalize_inventory_quantity($value));
    }
    if (!$normalized) {
      $normalized['normal'] = $quantity;
    }
    $variant_quantities = $normalized;
  }

  $found_index = null;
  foreach ($data['cards'] as $index => $card) {
    if (!is_array($card) || !isset($card['id'])) {
      continue;
    }
    if (trim((string) $card['id']) === $card_id) {
      $found_index = $index;
      break;
    }
  }

  $existing_entry = ($found_index !== null && isset($data['cards'][$found_index]) && is_array($data['cards'][$found_index]))
    ? $data['cards'][$found_index]
    : [];
  $existing_variants = ptcgdm_extract_inventory_variants_from_entry($existing_entry);

  $new_variants = [];
  foreach (ptcgdm_get_inventory_variant_labels() as $key => $label) {
    $qty = isset($variant_quantities[$key])
      ? max(0, (int) $variant_quantities[$key])
      : (isset($existing_variants[$key]['qty']) ? max(0, (int) $existing_variants[$key]['qty']) : 0);
    $price = isset($existing_variants[$key]['price']) ? ptcgdm_normalize_inventory_price($existing_variants[$key]['price']) : null;
    if ($qty > 0 || $price !== null) {
      $new_variants[$key] = ['qty' => $qty];
      if ($price !== null) {
        $new_variants[$key]['price'] = $price;
      }
    }
  }

  $total_qty = 0;
  $first_price = null;
  foreach ($new_variants as $variant) {
    $qty_value = max(0, (int) ($variant['qty'] ?? 0));
    $total_qty += $qty_value;
    if ($first_price === null && array_key_exists('price', $variant)) {
      $candidate = ptcgdm_normalize_inventory_price($variant['price']);
      if ($candidate !== null) {
        $first_price = $candidate;
      }
    }
  }

  $changed = false;

  if (!$new_variants) {
    if ($found_index !== null) {
      array_splice($data['cards'], $found_index, 1);
      $changed = true;
    }
  } else {
    $entry_payload = [
      'id'       => $card_id,
      'variants' => $new_variants,
    ];
    if ($total_qty > 0) {
      $entry_payload['qty'] = $total_qty;
    }
    if ($first_price !== null) {
      $entry_payload['price'] = $first_price;
    }

    if ($found_index !== null) {
      if ($data['cards'][$found_index] !== $entry_payload) {
        $data['cards'][$found_index] = $entry_payload;
        $changed = true;
      }
    } else {
      $data['cards'][] = $entry_payload;
      $changed = true;
    }
  }

  if (!$changed) {
    return false;
  }

  $flags = JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES;
  $encoded = function_exists('wp_json_encode') ? wp_json_encode($data, $flags) : json_encode($data, $flags);

  if ($encoded === false || $encoded === null) {
    return false;
  }

  return file_put_contents($path, $encoded) !== false;
}


function ptcgdm_handle_inventory_stock_change($product) {
  if (!($product instanceof WC_Product)) {
    return;
  }

  if (ptcgdm_is_inventory_syncing()) {
    return;
  }

  $parent_product = $product;
  if ($product instanceof WC_Product_Variation) {
    $parent_id = method_exists($product, 'get_parent_id') ? $product->get_parent_id() : 0;
    if ($parent_id) {
      $maybe_parent = wc_get_product($parent_id);
      if ($maybe_parent instanceof WC_Product) {
        $parent_product = $maybe_parent;
      }
    }
  }

  if (!($parent_product instanceof WC_Product)) {
    return;
  }

  $managed_flag = $parent_product->get_meta('_ptcgdm_managed');
  if ((string) $managed_flag !== '1') {
    return;
  }

  $card_id = trim((string) $parent_product->get_sku());
  if ($card_id === '') {
    $card_id = trim((string) $parent_product->get_meta('_ptcgdm_card_id'));
  }
  if ($card_id === '') {
    return;
  }

  $total_quantity = 0;
  $variant_quantities = null;

  $is_variable = ($parent_product instanceof WC_Product_Variable);
  if (!$is_variable && method_exists($parent_product, 'is_type')) {
    $is_variable = $parent_product->is_type('variable');
  }

  if ($is_variable) {
    $variant_quantities = [];
    foreach (ptcgdm_get_inventory_variant_labels() as $variant_key => $variant_label) {
      $variant_quantities[$variant_key] = 0;
    }

    $children = method_exists($parent_product, 'get_children') ? $parent_product->get_children() : [];
    $attribute_slug = function_exists('sanitize_title') ? sanitize_title('Finish') : 'finish';

    foreach ($children as $child_id) {
      $child_id = (int) $child_id;
      if ($child_id <= 0) {
        continue;
      }

      $variation = wc_get_product($child_id);
      if (!($variation instanceof WC_Product_Variation)) {
        continue;
      }

      $variant_key = $variation->get_meta('_ptcgdm_variant_key');
      if (!is_string($variant_key) || $variant_key === '') {
        $attr_value = method_exists($variation, 'get_attribute') ? $variation->get_attribute($attribute_slug) : '';
        if ($attr_value !== '') {
          $variant_key = ptcgdm_inventory_variant_key_from_label($attr_value);
        }
      }

      if (!is_string($variant_key) || $variant_key === '') {
        continue;
      }

      $quantity = $variation->get_stock_quantity();
      if ($quantity === null) {
        $quantity = 0;
      }
      $quantity = max(0, (int) $quantity);

      $variant_quantities[$variant_key] = $quantity;
    }

    $total_quantity = array_sum($variant_quantities);
  } else {
    $quantity = $parent_product->get_stock_quantity();
    if ($quantity === null) {
      $quantity = 0;
    }
    $total_quantity = max(0, (int) $quantity);
  }

  ptcgdm_update_inventory_card_quantity($card_id, $total_quantity, $variant_quantities);
}

if (function_exists('add_action')) {
  add_action('woocommerce_product_set_stock', 'ptcgdm_handle_inventory_stock_change', 10, 1);
}

function ptcgdm_render_inventory() {
  ptcgdm_render_builder();
}

function ptcgdm_extract_set_from_card($card_id){
  $card_id = trim((string) $card_id);
  if ($card_id === '') return '';
  if (strpos($card_id, '-') !== false) {
    return substr($card_id, 0, strpos($card_id, '-'));
  }
  $match = [];
  if (preg_match('/^[A-Za-z]+/', $card_id, $match)) {
    return $match[0];
  }
  return '';
}

function ptcgdm_extract_card_number($card_id){
  $card_id = trim((string) $card_id);
  if ($card_id === '') return '';
  if (strpos($card_id, '-') !== false) {
    return substr($card_id, strpos($card_id, '-') + 1);
  }
  $match = [];
  if (preg_match('/(\d+.*)$/', $card_id, $match)) {
    return $match[1];
  }
  return '';
}

function ptcgdm_load_set_map($set_id){
  static $cache = [];
  $original = trim((string) $set_id);
  if ($original === '') return [];
  $lookup_key = strtolower($original);
  if (array_key_exists($lookup_key, $cache)) {
    return $cache[$lookup_key];
  }

  $candidates = array_unique([
    strtolower($original),
    $original,
  ]);

  $paths = [];
  foreach ($candidates as $variant) {
    if ($variant === '') continue;
    $paths[] = PTCGDM_DATA_DIR . '/cards/en/' . $variant . '.json';
    $paths[] = PTCGDM_DATA_DIR . '/cards/' . $variant . '.json';
    $paths[] = PTCGDM_DATA_DIR . '/' . $variant . '.json';
  }

  foreach ($paths as $path) {
    if (!file_exists($path)) continue;
    $text = @file_get_contents($path);
    if (!$text) continue;
    $cards = ptcgdm_normalise_set_json($text);
    if (!$cards) continue;
    $map = [];
    foreach ($cards as $card) {
      if (is_array($card) && !empty($card['id'])) {
        $map[$card['id']] = $card;
      }
    }
    if ($map) {
      $cache[$lookup_key] = $map;
      return $cache[$lookup_key];
    }
  }

  $cache[$lookup_key] = [];
  return [];
}

function ptcgdm_normalise_set_json($text){
  $decoded = json_decode($text, true);
  if (is_array($decoded)) {
    if (ptcgdm_is_list($decoded)) {
      return $decoded;
    }
    if (!empty($decoded['data']) && is_array($decoded['data'])) {
      return $decoded['data'];
    }
    if (!empty($decoded['cards']) && is_array($decoded['cards'])) {
      return $decoded['cards'];
    }
    $flat = [];
    foreach ($decoded as $value) {
      if (is_array($value) && ptcgdm_is_list($value)) {
        $flat = array_merge($flat, $value);
      }
    }
    if ($flat) {
      return $flat;
    }
  }

  $lines = preg_split('/\r\n|\r|\n/', (string) $text);
  $manual = [];
  foreach ($lines as $line) {
    $line = trim($line);
    if ($line === '') continue;
    $item = json_decode($line, true);
    if (is_array($item)) {
      $manual[] = $item;
    }
  }
  return $manual;
}

function ptcgdm_is_list($array){
  if (!is_array($array)) return false;
  if ($array === []) return true;
  $index = 0;
  foreach ($array as $key => $_) {
    if ($key !== $index) return false;
    $index++;
  }
  return true;
}
