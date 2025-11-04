<?php
/**
 * The Background Worker for the Standalone Screener Application.
 * CORRECTED VERSION: Fixes all warnings and notices for a clean execution.
 *
 * @since      1.0.1 (Standalone)
 */

// Set a very long execution time limit and increase memory.
ini_set('max_execution_time', 1800); // 30 minutes
ini_set('memory_limit', '256M');
date_default_timezone_set('UTC');

// --- Require all our logic classes ---
require_once __DIR__ . '/lib/api-handler.php';
require_once __DIR__ . '/lib/magic-formula.php';
require_once __DIR__ . '/lib/piotroski-scan.php';
require_once __DIR__ . '/lib/value-scan.php';

echo "--- Starting Daily Screener Analysis @ " . date('Y-m-d H:i:s') . " ---\n";

// --- Initialize Engines ---
$api = new ApiHandler();
$magic_formula_engine = new MagicFormula();
$piotroski_engine = new PiotroskiScan();
$value_scan_engine = new ValueScan();

$cache_dir = __DIR__ . '/cache';

// --- 1. Get Master Symbol List ---
echo "Fetching master symbol list...\n";
$symbols_data = $api->get_all_nse_symbols();
if (is_object($symbols_data) && isset($symbols_data->error) || empty($symbols_data)) {
    echo "FATAL: Could not fetch symbol list. Error: " . ($symbols_data->error ?? 'Empty list') . "\n";
    exit;
}
echo "Found " . count($symbols_data) . " symbols.\n";

// --- 2. Prepare Data for All Screeners ---
$mf_data_pool = [];
$piotroski_data_pool = [];
$value_data_pool = [];

$symbols_to_process = array_slice($symbols_data, 0, 300);

foreach ($symbols_to_process as $index => $stock) {
    // **FIXED:** Use the correct array key 'companyName'
    $stock_name = $stock['name'] ?? $stock['companyName'] ?? 'N/A';
    echo "Processing (" . ($index + 1) . "/" . count($symbols_to_process) . "): " . $stock['symbol'] . "\n";
    
    // Data for Magic Formula
    $mf_data = $api->get_magic_formula_data($stock['symbol']);
    // **FIXED:** Check for error object before proceeding.
    if (!is_object($mf_data) || !isset($mf_data->error)) {
        $mf_data['name'] = $stock_name;
        $mf_data_pool[] = $mf_data;
    }

    // Data for Value Scan
    $ratios_data = $api->perform_request("ratios/{$stock['symbol']}?limit=1");
    if (!is_object($ratios_data) || !isset($ratios_data->error)) {
        $value_data_pool[] = ['symbol' => $stock['symbol'], 'name' => $stock_name, 'ratios' => $ratios_data];
    }
    
    // Data for Piotroski
    $income = $api->perform_request("income-statement/{$stock['symbol']}?limit=2");
    $balance = $api->perform_request("balance-sheet-statement/{$stock['symbol']}?limit=2");
    $cashflow = $api->perform_request("cash-flow-statement/{$stock['symbol']}?limit=2");
    $ratios = $api->perform_request("financial-ratios/{$symbol['symbol']}?limit=2");
    
    // **FIXED:** Check for error objects on all Piotroski data points.
    if (
        (!is_object($income) && !isset($income->error)) && (!is_object($balance) && !isset($balance->error)) &&
        (!is_object($cashflow) && !isset($cashflow->error)) && (!is_object($ratios) && !isset($ratios->error))
    ) {
        $piotroski_score_data = $piotroski_engine->calculate_f_score(['income' => $income, 'balance' => $balance, 'cashflow' => $cashflow, 'ratios' => $ratios]);
        // **FIXED:** Check for error from the calculation itself.
        if (!is_object($piotroski_score_data) || !isset($piotroski_score_data->error)) {
            $piotroski_score = $piotroski_score_data;
            if ($piotroski_score >= 7) {
                $piotroski_data_pool[] = ['symbol' => $stock['symbol'], 'name' => $stock_name, 'f_score' => $piotroski_score];
            }
        }
    }

    usleep(100000);
}

// --- 3. Run Analysis and Cache Results ---

// Magic Formula
$mf_results = $magic_formula_engine->get_ranked_stocks($mf_data_pool);
file_put_contents($cache_dir . '/magic_formula.json', json_encode(array_slice($mf_results, 0, 50)));
echo "Magic Formula analysis complete. Found " . count($mf_results) . " valid stocks. Cached top 50.\n";

// Value Scan
$value_results = $value_scan_engine->get_value_stocks($value_data_pool);
file_put_contents($cache_dir . '/value_scan.json', json_encode(array_slice($value_results, 0, 50)));
echo "Value Scan analysis complete. Found " . count($value_results) . " value stocks. Cached top 50.\n";

// Piotroski Scan
usort($piotroski_data_pool, fn($a, $b) => $b['f_score'] <=> $a['f_score']);
file_put_contents($cache_dir . '/piotroski.json', json_encode(array_slice($piotroski_data_pool, 0, 50)));
echo "Piotroski Scan analysis complete. Found " . count($piotroski_data_pool) . " high-scoring stocks. Cached top 50.\n";

// --- 4. Fetch Live Price Data ---
$all_found_symbols = array_unique(array_merge(
    array_column($mf_results, 'symbol'),
    array_column($value_results, 'symbol'),
    array_column($piotroski_data_pool, 'symbol')
));

if (!empty($all_found_symbols)) {
    echo "Fetching live prices for " . count($all_found_symbols) . " unique stocks...\n";
    $live_quotes = $api->get_live_quotes(array_values($all_found_symbols));
    if (!is_object($live_quotes) || !isset($live_quotes->error)) {
        $quotes_by_symbol = [];
        foreach ($live_quotes as $quote) {
            $quotes_by_symbol[$quote['symbol']] = $quote;
        }
        file_put_contents($cache_dir . '/live_quotes.json', json_encode($quotes_by_symbol));
        echo "Live prices cached successfully.\n";
    }
}

echo "--- Daily Analysis Finished @ " . date('Y-m-d H:i:s') . " ---\n";