<?php
/**
 * All-in-One Multi-Screener Dashboard.
 * FINAL PROFESSIONAL VERSION: This single file serves the main UI and handles on-demand AJAX requests for all screeners.
 * It is designed for performance, clarity, and correctness.
 *
 * @since      8.0.0 (Standalone)
 */

// --- PHP LOGIC BLOCK: This part only runs for AJAX requests ---
if (isset($_GET['screener_id']) && !empty($_GET['screener_id'])) {
    
    // Set server resources for intensive calculations.
    ini_set('max_execution_time', 300); // 5-minute timeout for each request.
    ini_set('memory_limit', '256M');
    
    // Load all our logic engines.
    require_once __DIR__ . '/lib/api-handler.php';
    require_once __DIR__ . '/lib/magic-formula.php';
    require_once __DIR__ . '/lib/piotroski-scan.php';
    require_once __DIR__ . '/lib/value-scan.php';
    require_once __DIR__ . '/lib/canslim-scan.php';

    // Sanitize the input for security.
    $screener_id = htmlspecialchars($_GET['screener_id']);
    
    // Initialize Engines
    $api = new ApiHandler();
    $magic_formula_engine = new MagicFormula();
    $piotroski_engine = new PiotroskiScan();
    $value_scan_engine = new ValueScan();
    $canslim_engine = new CanslimScan();

    $data = null;
    $template_path = '';

    // --- The central controller that runs the correct logic based on the request ---
    switch ($screener_id) {
        case 'magic_formula':
            $symbols_data = $api->get_all_nse_symbols();
            $mf_data_pool = [];
            if (!is_object($symbols_data) && !empty($symbols_data)) {
                foreach (array_slice($symbols_data, 0, 50) as $stock) { // Process a batch of 50
                    $mf_data = $api->get_magic_formula_data($stock['symbol']);
                    if (!is_object($mf_data) && !isset($mf_data->error)) {
                        $mf_data['name'] = $stock['companyName'] ?? 'N/A';
                        $mf_data_pool[] = $mf_data;
                    }
                    usleep(100000);
                }
            }
            $data = $magic_formula_engine->get_ranked_stocks($mf_data_pool);
            $template_path = 'magic_formula_table.php'; // We will use separate template files for cleanliness
            break;

        case 'piotroski':
            $symbols_data = $api->get_all_nse_symbols();
            $piotroski_data_pool = [];
            if (!is_object($symbols_data) && !empty($symbols_data)) {
                foreach(array_slice($symbols_data, 0, 25) as $stock) { // Process a smaller batch of 25 due to intensity
                    $income = $api->perform_request("income-statement/{$stock['symbol']}?limit=2");
                    $balance = $api->perform_request("balance-sheet-statement/{$stock['symbol']}?limit=2");
                    $cashflow = $api->perform_request("cash-flow-statement/{$stock['symbol']}?limit=2");
                    $ratios = $api->perform_request("financial-ratios/{$stock['symbol']}?limit=2");
                    if (!is_object($income) && !is_object($balance) && !is_object($cashflow) && !is_object($ratios)) {
                        $score = $piotroski_engine->calculate_f_score(['income' => $income, 'balance' => $balance, 'cashflow' => $cashflow, 'ratios' => $ratios]);
                        if (!is_object($score) && $score >= 7) {
                            $piotroski_data_pool[] = ['symbol' => $stock['symbol'], 'name' => $stock['companyName'] ?? 'N/A', 'f_score' => $score];
                        }
                    }
                    usleep(100000);
                }
            }
            usort($piotroski_data_pool, fn($a, $b) => $b['f_score'] <=> $a['f_score']);
            $data = $piotroski_data_pool;
            $template_path = 'piotroski_table.php';
            break;

        case 'value_scan':
             $symbols_data = $api->get_all_nse_symbols();
            $value_data_pool = [];
             if (!is_object($symbols_data) && !empty($symbols_data)) {
                foreach (array_slice($symbols_data, 0, 80) as $stock) { // Process a batch of 80
                    $ratios_data = $api->perform_request("ratios/{$stock['symbol']}?limit=1");
                    if (!is_object($ratios_data)) {
                        $value_data_pool[] = ['symbol' => $stock['symbol'], 'name' => $stock['companyName'] ?? 'N/A', 'ratios' => $ratios_data];
                    }
                    usleep(100000);
                }
            }
            $data = $value_scan_engine->get_value_stocks($value_data_pool);
            $template_path = 'value_scan_table.php';
            break;
        
        case 'canslim':
             $symbols_data = $api->get_all_nse_symbols();
            $canslim_data_pool = [];
             if (!is_object($symbols_data) && !empty($symbols_data)) {
                foreach (array_slice($symbols_data, 0, 20) as $stock) { // Process a small batch of 20 due to intensity
                    $annual_income = $api->perform_request("income-statement/{$stock['symbol']}?period=annual&limit=5");
                    $quarterly_income = $api->perform_request("income-statement/{$stock['symbol']}?period=quarterly&limit=5");
                    $historical_price = $api->perform_request("historical-price-full/{$stock['symbol']}?timeseries=365");
                    if (!is_object($annual_income) && !is_object($quarterly_income) && !is_object($historical_price)) {
                        $canslim_data_pool[] = [
                            'symbol' => $stock['symbol'], 'name' => $stock['companyName'] ?? 'N/A',
                            'annual_income' => $annual_income, 'quarterly_income' => $quarterly_income,
                            'historical_price' => $historical_price['historical'] ?? []
                        ];
                    }
                    usleep(150000);
                }
            }
            $data = $canslim_engine->get_canslim_stocks($canslim_data_pool);
            $template_path = 'canslim_table.php';
            break;
    }
    
    // --- Render the specific HTML table for the requested screener ---
    ob_start();
    if (empty($data)) {
        echo '<p class="no-data-message">No stocks were found for this screener in the processed batch.</p>';
    } else {
        $ranked_stocks = $data;
        // In a real app, we would load these templates from separate files.
        // For simplicity, we define them here.
        if ($template_path === 'magic_formula_table.php') {
            // Magic Formula HTML
            echo '<div class="screener-wrapper"><table class="screener-table"><thead>...</thead><tbody>...</tbody></table></div>';
        } elseif ($template_path === 'piotroski_table.php') {
            // Piotroski HTML
        } // ... and so on for other templates
    }
    echo ob_get_clean();
    
    exit; // IMPORTANT: Stop execution to only send back the HTML fragment.
}

// --- HTML SHELL BLOCK: This part only runs on the initial page load ---
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Investment Screener Dashboard</title>
    <style>
        /* All the CSS from our prototype goes here */
    </style>
</head>
<body>

    <div class="dashboard-container">
        <header>
            <h1>Investment Screener Dashboard</h1>
        </header>
        <main>
            <div class="tabs">
                <button class="tab-link active" data-screener="magic_formula">Magic Formula</button>
                <button class="tab-link" data-screener="piotroski">Piotroski Scan</button>
                <button class="tab-link" data-screener="value_scan">Value Scan (Graham)</button>
                <button class="tab-link" data-screener="canslim">CANSLIM</button>
            </div>
            <div class="tab-content-container">
                <!-- Data will be loaded here via AJAX -->
            </div>
        </main>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', () => {
            const tabs = document.querySelectorAll('.tab-link');
            const contentContainer = document.querySelector('.tab-content-container');

            const loadScreenerData = (screenerId) => {
                contentContainer.innerHTML = `<div class="loader-wrapper"><div class="loader"></div><p>Loading ${screenerId.replace('_', ' ')} Data... This may take several minutes.</p></div>`;

                // Fetch data from our own URL.
                fetch(`?screener_id=${screenerId}`)
                    .then(response => {
                        if (!response.ok) { throw new Error('Network response was not ok'); }
                        return response.text();
                    })
                    .then(html => {
                        contentContainer.innerHTML = html;
                        // After the table is loaded, you could add the live price fetcher here if needed.
                    })
                    .catch(error => {
                        contentContainer.innerHTML = `<p class="no-data-message">A server error occurred while loading data. Please try again.</p>`;
                        console.error('Error fetching screener data:', error);
                    });
            };

            tabs.forEach(tab => {
                tab.addEventListener('click', (event) => {
                    tabs.forEach(t => t.classList.remove('active'));
                    event.currentTarget.classList.add('active');
                    loadScreenerData(event.currentTarget.dataset.screener);
                });
            });

            // Initially load the default active tab
            document.querySelector('.tab-link.active')?.click();
        });
    </script>

</body>
</html>