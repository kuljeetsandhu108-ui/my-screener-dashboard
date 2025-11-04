<?php
/**
 * The Background Worker for the Standalone Screener Application.
 * UPGRADED: Now fetches and processes data for the new CANSLIM screener.
 *
 * @since      1.1.0 (Standalone)
 */

ini_set('max_execution_time', 3600); // Increase to 60 minutes for the intensive CANSLIM data fetch
ini_set('memory_limit', '512M');
date_default_timezone_set('UTC');

// --- Require all our logic classes ---
require_once __DIR__ . '/lib/api-handler.php';
require_once __DIR__ . '/lib/magic-formula.php';
require_once __DIR__ . '/lib/piotroski-scan.php';
require_once __DIR__ . '/lib/value-scan.php';
require_once __DIR__ . '/lib/canslim-scan.php'; // <-- NEW

echo "--- Starting Daily Screener Analysis @ " . date('Y-m-d H:i:s') . " ---\n";

// --- Initialize Engines ---
$api = new ApiHandler();
$magic_formula_engine = new MagicFormula();
$piotroski_engine = new PiotroskiScan();
$value_scan_engine = new ValueScan();
$canslim_engine = new CanslimScan(); // <-- NEW

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
$canslim_data_pool = []; // <-- NEW

// CANSLIM is very data-intensive, so we'll start with a focused batch size.
$symbols_to_process = array_slice($symbols_data, 0, 100); 

foreach ($symbols_to_process as $index => $stock) {
    $symbol = $stock['symbol'];
    $stock_name = $stock['companyName'] ?? 'N/A';
    echo "Processing (" . ($index + 1) . "/" . count($symbols_to_process) . "): " . $symbol . "\n";
    
    // --- Data for CANSLIM (Most Intensive) ---
    $annual_income = $api->perform_request("income-statement/{$symbol}?period=annual&limit=5");
    $quarterly_income = $api->perform_request("income-statement/{$symbol}?period=quarterly&limit=5");
    $historical_price = $api->perform_request("historical-price-full/{$symbol}?timeseries=365");

    if (
        (!is_object($annual_income) && !isset($annual_income->error)) &&
        (!is_object($quarterly_income) && !isset($quarterly_income->error)) &&
        (!is_object($historical_price) && !isset($historical_price->error))
    ) {
        $canslim_data_pool[] = [
            'symbol' => $symbol,
            'name' => $stock_name,
            'annual_income' => $annual_income,
            'quarterly_income' => $quarterly_income,
            'historical_price' => $historical_price['historical'] ?? []
        ];
    }
    
    // --- Data for Other Screeners (More Efficient) ---
    $mf_data = $api->get_magic_formula_data($symbol);
    if (!is_object($mf_data) || !isset($mf_data->error)) {
        $mf_data['name'] = $stock_name;
        $mf_data_pool[] = $mf_data;
    }

    $ratios_data = $api->perform_request("ratios/{$symbol}?limit=1");
    if (!is_object($ratios_data) || !isset($ratios_data->error)) {
        $value_data_pool[] = ['symbol' => $symbol, 'name' => $stock_name, 'ratios' => $ratios_data];
    }
    
    // Data for Piotroski still requires its own calls
    // (This part is unchanged, we can optimize later if needed)
    $income = $api->perform_request("income-statement/{$symbol}?limit=2");
    $balance = $api->perform_request("balance-sheet-statement/{$symbol}?limit=2");
    $cashflow = $api->perform_request("cash-flow-statement/{$symbol}?limit=2");
    $ratios = $api->perform_request("financial-ratios/{$symbol}?limit=2");
    if (
        (!is_object($income) && !isset($income->error)) && (!is_object($balance) && !isset($balance->error)) &&
        (!is_object($cashflow) && !isset($cashflow->error)) && (!is_object($ratios) && !isset($ratios->error))
    ) {
        $piotroski_score = $piotroski_engine->calculate_f_score(['income' => $income, 'balance' => $balance, 'cashflow' => $cashflow, 'ratios' => $ratios]);
        if (!is_object($piotroski_score) && $piotroski_score >= 7) {
            $piotroski_data_pool[] = ['symbol' => $symbol, 'name' => $stock_name, 'f_score' => $piotroski_score];
        }
    }

    usleep(150000); // Increased delay to 0.15s due to the higher number of API calls per stock.
}

// --- 3. Run Analysis and Cache Results ---

// CANSLIM
$canslim_results = $canslim_engine->get_canslim_stocks($canslim_data_pool);
file_put_contents($cache_dir . '/canslim.json', json_encode($canslim_results));
echo "CANSLIM analysis complete. Found " . count($canslim_results) . " stocks. Cached results.\n";

// Other screeners (unchanged)
$mf_results = $magic_formula_engine->get_ranked_stocks($mf_data_pool);
file_put_contents($cache_dir . '/magic_formula.json', json_encode(array_slice($mf_results, 0, 50)));
echo "Magic Formula analysis complete. Cached top 50.\n";

$value_results = $value_scan_engine->get_value_stocks($value_data_pool);
file_put_contents($cache_dir . '/value_scan.json', json_encode(array_slice($value_results, 0, 50)));
echo "Value Scan analysis complete. Cached top 50.\n";

usort($piotroski_data_pool, fn($a, $b) => $b['f_score'] <=> $a['f_score']);
file_put_contents($cache_dir . '/piotroski.json', json_encode(array_slice($piotroski_data_pool, 0, 50)));
echo "Piotroski Scan analysis complete. Cached top 50.\n";


// --- 4. Fetch Live Price Data for All Found Stocks ---
$all_found_symbols = array_unique(array_merge(
    array_column($mf_results, 'symbol'),
    array_column($value_results, 'symbol'),
    array_column($piotroski_data_pool, 'symbol'),
    array_column($canslim_results, 'symbol') // <-- NEW
));

if (!empty($all_found_symbols)) {
    echo "Fetching live prices for " . count($all_found_symbols) . " unique stocks...\n";
    $live_quotes = $api->get_live_quotes(array_values($all_found_symbols));
    if (!is_object($live_quotes) || !isset($live_quotes->error)) {
        $quotes_by_symbol = [];
        foreach ($live_quotes as $quote) {
            if(isset($quote['symbol'])) $quotes_by_symbol[$quote['symbol']] = $quote;
        }
        file_put_contents($cache_dir . '/live_quotes.json', json_encode($quotes_by_symbol));
        echo "Live prices cached successfully.\n";
    }
}

echo "--- Daily Analysis Finished @ " . date('Y-m-d H:i:s') . " ---\n";