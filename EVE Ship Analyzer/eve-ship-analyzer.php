<?php
/**
 * Plugin Name:       EVE Ship Analyzer
 * Description:       Analyzes character PvP history to find ships flown using zKillboard and ESI.
 * Version:           1.0
 * Author:            Surama Badasaz
 */

// USE Shortcode "[eve_ship_analyzer]" on a page to be able to use the plugin
// Prevent direct file access
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// 1. Create the Shortcode to display the analyzer tool
function esa_shortcode_handler() {
    // This is the HTML structure that will be placed on the page.
    // It's just the shell; JavaScript will handle the interaction.
    ob_start(); // Start output buffering to capture the HTML
    ?>
    <div id="eve-ship-analyzer-app">
        <h1>EVE Online Ship Analyzer (ESI Verified)</h1>
        <p>This tool finds all the ships involved in any pvp interaction. It uses zKillboard to find kills, then verifies each one with the official EVE ESI for maximum accuracy. This process can be slow.</p>
        <p>It analyses only the last active zkill esi page so 200 kill Id's</p>
        <textarea id="charNames" rows="10" placeholder="StainGuy"></textarea>
        <br>
        <button id="fetchButton">Fetch Ship Data</button>

        <div id="status"></div>
        <div id="results"></div>
    </div>
    <?php
    return ob_get_clean(); // Return the captured HTML
}
add_shortcode('eve_ship_analyzer', 'esa_shortcode_handler');


// 2. Enqueue the CSS and JavaScript files
function esa_enqueue_scripts( $hook ) {
    // Only load our scripts on pages where the shortcode might be.
    // For simplicity, we can just load it, but a better check could be implemented.
    wp_enqueue_style(
        'esa-style',
        plugin_dir_url(__FILE__) . 'ship-analyzer-style.css',
        [],
        '1.0'
    );

    wp_enqueue_script(
        'esa-script',
        plugin_dir_url(__FILE__) . 'ship-analyzer-script.js',
        ['jquery'], // WordPress includes jQuery by default
        '1.0',
        true // Load in the footer
    );

    // Pass data to JavaScript, like the AJAX URL and a security nonce
    wp_localize_script('esa-script', 'esa_ajax_obj', [
        'ajax_url' => admin_url('admin-ajax.php'),
        'nonce'    => wp_create_nonce('esa_nonce'),
    ]);
}
add_action('wp_enqueue_scripts', 'esa_enqueue_scripts');


// 3. The Backend AJAX Handler (The "Hidden" Logic)
// This function runs on the server when called by our JavaScript.
function esa_handle_fetch_data() {
    // Security check
    check_ajax_referer('esa_nonce', 'nonce');

    // Get character names from the POST request, sanitize them
    $char_names = isset($_POST['char_names']) ? sanitize_textarea_field($_POST['char_names']) : '';
    $char_names_array = array_filter(array_map('trim', explode("\n", $char_names)));

    if (empty($char_names_array)) {
        wp_send_json_error(['message' => 'Please enter at least one character name.']);
        return;
    }

    $all_character_data = [];
    $all_ship_ids = [];
    $error_log = [];

    // --- REPLICATE THE ORIGINAL JAVASCRIPT LOGIC IN PHP ---

    // Get Character IDs
    $id_payload = ['body' => json_encode($char_names_array)];
    $id_response = wp_remote_post('https://esi.evetech.net/latest/universe/ids/?datasource=tranquility', $id_payload);
    $id_data = json_decode(wp_remote_retrieve_body($id_response), true);
    $char_id_map = [];
    if (!empty($id_data['characters'])) {
        foreach ($id_data['characters'] as $char) {
            $char_id_map[strtolower($char['name'])] = $char['id'];
        }
    }
    
    foreach ($char_names_array as $char_name) {
        $char_id = isset($char_id_map[strtolower($char_name)]) ? $char_id_map[strtolower($char_name)] : null;

        if (!$char_id) {
            $error_log[$char_name] = 'Could not resolve to a valid character ID.';
            continue;
        }

        // Get Killmail Metadata from zKillboard
        $zkill_url = "https://zkillboard.com/api/kills/characterID/{$char_id}/";
        $zkill_response = wp_remote_get($zkill_url, ['headers' => ['Accept' => 'application/json']]);
        
        if (is_wp_error($zkill_response) || wp_remote_retrieve_response_code($zkill_response) !== 200) {
            $error_log[$char_name] = 'Failed to fetch data from zKillboard.';
            continue;
        }

        $killmail_metas = json_decode(wp_remote_retrieve_body($zkill_response), true);

        if (empty($killmail_metas)) {
            $error_log[$char_name] = 'No killmails found in the zKillboard index.';
            continue;
        }

        $ship_counts = [];
        foreach ($killmail_metas as $meta) {
            if (empty($meta['killmail_id']) || empty($meta['zkb']['hash'])) continue;

            $kill_id = $meta['killmail_id'];
            $hash = $meta['zkb']['hash'];
            $esi_killmail_url = "https://esi.evetech.net/latest/killmails/{$kill_id}/{$hash}/?datasource=tranquility";
            $esi_response = wp_remote_get($esi_killmail_url);

            if (is_wp_error($esi_response) || wp_remote_retrieve_response_code($esi_response) !== 200) {
                continue; // Skip if ESI verification fails
            }

            $esi_killmail = json_decode(wp_remote_retrieve_body($esi_response), true);

            if (isset($esi_killmail['attackers']) && is_array($esi_killmail['attackers'])) {
                foreach ($esi_killmail['attackers'] as $attacker) {
                    if (isset($attacker['character_id']) && $attacker['character_id'] === $char_id && isset($attacker['ship_type_id'])) {
                        $ship_id = $attacker['ship_type_id'];
                        $ship_counts[$ship_id] = ($ship_counts[$ship_id] ?? 0) + 1;
                        $all_ship_ids[] = $ship_id;
                        break; // Found the pilot, move to next killmail
                    }
                }
            }
        }
        $all_character_data[$char_name] = $ship_counts;
    }

    // Get Ship Names for all collected IDs
    $ship_names_map = [];
    $unique_ship_ids = array_unique($all_ship_ids);
    if (!empty($unique_ship_ids)) {
        // ESI /names/ endpoint can handle up to 1000 IDs at a time.
        $id_chunks = array_chunk($unique_ship_ids, 900);
        foreach($id_chunks as $chunk) {
            $names_payload = ['body' => json_encode(array_values($chunk))];
            $names_response = wp_remote_post('https://esi.evetech.net/latest/universe/names/?datasource=tranquility', $names_payload);
            $names_data = json_decode(wp_remote_retrieve_body($names_response), true);
            if (!empty($names_data)) {
                foreach ($names_data as $item) {
                    $ship_names_map[$item['id']] = $item['name'];
                }
            }
        }
    }
    
    // Prepare the final response data
    $final_data = [
        'results' => $all_character_data,
        'ship_names' => $ship_names_map,
        'errors' => $error_log
    ];

    wp_send_json_success($final_data);
}
// Hook for logged-in users
add_action('wp_ajax_esa_fetch_data', 'esa_handle_fetch_data'); 
// Hook for non-logged-in users
add_action('wp_ajax_nopriv_esa_fetch_data', 'esa_handle_fetch_data');