<?php
/**
 * All-in-One Multi-Screener Dashboard.
 * FINAL PROFESSIONAL VERSION: This single file serves the UI and handles on-demand AJAX requests.
 * It features increased batch sizes and full HTML rendering for all screeners.
 *
 * @since      8.2.0 (Standalone)
 */

// --- PHP LOGIC BLOCK: This part only runs for AJAX data requests ---
if (isset($_GET['screener_id']) && !empty($_GET['screener_id'])) {
    
    // Set server resources for intensive calculations.
    ini_set('max_execution_time', 480); // 8-minute timeout, essential for larger batches.
    ini_set('memory_limit', '256M');
    
    // Load all our logic engines.
    require_once __DIR__ . '/lib/api-handler.php';
    require_once __DIR__ . '/lib/magic-formula.php';
    require_once __DIR__ . '/lib/piotroski-scan.php';
    require_once __DIR__ . '/lib/value-scan.php';
    require_once __DIR__ . '/lib/canslim-scan.php';

    // Sanitize the input for security.
    $screener_id = htmlspecialchars($_GET['screener_id']);
    
    $api = new ApiHandler();
    $data = null;
    
    // --- The central controller that runs the correct logic for the requested screener ---
    switch ($screener_id) {
        case 'magic_formula':
            $engine = new MagicFormula();
            $symbols_data = $api->get_all_nse_symbols();
            $data_pool = [];
            if (!is_object($symbols_data) && !empty($symbols_data)) {
                foreach (array_slice($symbols_data, 0, 100) as $stock) { // Increased batch size
                    $api_data = $api->get_magic_formula_data($stock['symbol']);
                    if (!is_object($api_data)) {
                        $api_data['name'] = $stock['companyName'] ?? 'N/A';
                        $data_pool[] = $api_data;
                    }
                    usleep(100000);
                }
            }
            $data = $engine->get_ranked_stocks($data_pool);
            break;

        case 'piotroski':
            $engine = new PiotroskiScan();
            $symbols_data = $api->get_all_nse_symbols();
            $data_pool = [];
            if (!is_object($symbols_data) && !empty($symbols_data)) {
                foreach(array_slice($symbols_data, 0, 80) as $stock) { // Increased batch size
                    $income = $api->perform_request("income-statement/{$stock['symbol']}?limit=2");
                    $balance = $api->perform_request("balance-sheet-statement/{$stock['symbol']}?limit=2");
                    $cashflow = $api->perform_request("cash-flow-statement/{$stock['symbol']}?limit=2");
                    $ratios = $api->perform_request("financial-ratios/{$stock['symbol']}?limit=2");
                    if (!is_object($income) && !is_object($balance) && !is_object($cashflow) && !is_object($ratios)) {
                        $score = $engine->calculate_f_score(['income' => $income, 'balance' => $balance, 'cashflow' => $cashflow, 'ratios' => $ratios]);
                        if (!is_object($score) && $score >= 7) {
                            $data_pool[] = ['symbol' => $stock['symbol'], 'name' => $stock['companyName'] ?? 'N/A', 'f_score' => $score];
                        }
                    }
                    usleep(100000);
                }
            }
            usort($data_pool, fn($a, $b) => $b['f_score'] <=> $a['f_score']);
            $data = $data_pool;
            break;

        case 'value_scan':
            $engine = new ValueScan();
            $symbols_data = $api->get_all_nse_symbols();
            $data_pool = [];
             if (!is_object($symbols_data) && !empty($symbols_data)) {
                foreach (array_slice($symbols_data, 0, 200) as $stock) { // Increased batch size
                    $ratios_data = $api->perform_request("ratios/{$stock['symbol']}?limit=1");
                    if (!is_object($ratios_data)) {
                        $data_pool[] = ['symbol' => $stock['symbol'], 'name' => $stock['companyName'] ?? 'N/A', 'ratios' => $ratios_data];
                    }
                    usleep(100000);
                }
            }
            $data = $engine->get_value_stocks($data_pool);
            break;
        
        case 'canslim':
            $engine = new CanslimScan();
            $symbols_data = $api->get_all_nse_symbols();
            $data_pool = [];
             if (!is_object($symbols_data) && !empty($symbols_data)) {
                foreach (array_slice($symbols_data, 0, 50) as $stock) { // Increased batch size
                    $annual_income = $api->perform_request("income-statement/{$stock['symbol']}?period=annual&limit=5");
                    $quarterly_income = $api->perform_request("income-statement/{$stock['symbol']}?period=quarterly&limit=5");
                    $historical_price = $api->perform_request("historical-price-full/{$stock['symbol']}?timeseries=365");
                    if (!is_object($annual_income) && !is_object($quarterly_income) && !is_object($historical_price)) {
                        $data_pool[] = [
                            'symbol' => $stock['symbol'], 'name' => $stock['companyName'] ?? 'N/A',
                            'annual_income' => $annual_income, 'quarterly_income' => $quarterly_income,
                            'historical_price' => $historical_price['historical'] ?? []
                        ];
                    }
                    usleep(150000);
                }
            }
            $data = $engine->get_canslim_stocks($data_pool);
            break;
    }
    
    // --- RENDER THE HTML RESPONSE ---
    if (empty($data)) {
        echo '<p class="no-data-message">No stocks were found for this screener in the processed batch. Try again later or consider upgrading the batch size.</p>';
    } else {
        // Since we need live prices for all tables, we fetch them here.
        $all_symbols = array_column($data, 'symbol');
        $live_quotes_raw = $api->get_live_quotes($all_symbols);
        $live_quotes = [];
        if (!is_object($live_quotes_raw)) {
            foreach($live_quotes_raw as $quote) {
                $live_quotes[$quote['symbol']] = $quote;
            }
        }
        
        ob_start();
        switch($screener_id) {
            case 'magic_formula': ?>
                <div class="screener-wrapper"><table class="screener-table">
                    <thead><tr><th>Rank</th><th>Company</th><th>Symbol</th><th>Price</th><th>Change</th><th>Earnings Yield</th><th>Return on Capital</th></tr></thead>
                    <tbody>
                        <?php foreach ($data as $index => $stock): 
                            $quote = $live_quotes[$stock['symbol']] ?? null;
                            $price_html = $quote ? number_format($quote['price'], 2) : '--';
                            $change = $quote ? $quote['change'] : 0;
                            $change_p = $quote ? $quote['changesPercentage'] : 0;
                            $change_class = $change > 0 ? 'positive-change' : ($change < 0 ? 'negative-change' : '');
                            $change_html = $quote ? number_format($change, 2) . ' (' . number_format($change_p, 2) . '%)' : '--';
                        ?>
                        <tr>
                            <td><?php echo $index + 1; ?></td>
                            <td><?php echo htmlspecialchars($stock['name']); ?></td>
                            <td><?php echo htmlspecialchars($stock['symbol']); ?></td>
                            <td><?php echo $price_html; ?></td>
                            <td class="<?php echo $change_class; ?>"><?php echo $change_html; ?></td>
                            <td><?php echo number_format($stock['earnings_yield'] * 100, 2); ?>%</td>
                            <td><?php echo number_format($stock['return_on_capital'] * 100, 2); ?>%</td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table></div>
                <?php break;
            
            case 'piotroski': ?>
                 <div class="screener-wrapper"><table class="screener-table">
                    <thead><tr><th>Rank</th><th>Company</th><th>Symbol</th><th>Price</th><th>Change</th><th>F-Score</th></tr></thead>
                    <tbody>
                        <?php foreach ($data as $index => $stock): 
                            $quote = $live_quotes[$stock['symbol']] ?? null;
                            $price_html = $quote ? number_format($quote['price'], 2) : '--';
                            $change = $quote ? $quote['change'] : 0;
                            $change_p = $quote ? $quote['changesPercentage'] : 0;
                            $change_class = $change > 0 ? 'positive-change' : ($change < 0 ? 'negative-change' : '');
                            $change_html = $quote ? number_format($change, 2) . ' (' . number_format($change_p, 2) . '%)' : '--';
                        ?>
                        <tr>
                            <td><?php echo $index + 1; ?></td>
                            <td><?php echo htmlspecialchars($stock['name']); ?></td>
                            <td><?php echo htmlspecialchars($stock['symbol']); ?></td>
                            <td><?php echo $price_html; ?></td>
                            <td class="<?php echo $change_class; ?>"><?php echo $change_html; ?></td>
                            <td><?php echo htmlspecialchars($stock['f_score']); ?> / 9</td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table></div>
                <?php break;

            case 'value_scan': ?>
                <div class="screener-wrapper"><table class="screener-table">
                    <thead><tr><th>Rank</th><th>Company</th><th>Symbol</th><th>Price</th><th>Change</th><th>P/E Ratio</th><th>P/B Ratio</th><th>Current Ratio</th></tr></thead>
                    <tbody>
                        <?php foreach ($data as $index => $stock):
                            $quote = $live_quotes[$stock['symbol']] ?? null;
                            $price_html = $quote ? number_format($quote['price'], 2) : '--';
                            $change = $quote ? $quote['change'] : 0;
                            $change_p = $quote ? $quote['changesPercentage'] : 0;
                            $change_class = $change > 0 ? 'positive-change' : ($change < 0 ? 'negative-change' : '');
                            $change_html = $quote ? number_format($change, 2) . ' (' . number_format($change_p, 2) . '%)' : '--';
                        ?>
                        <tr>
                            <td><?php echo $index + 1; ?></td>
                            <td><?php echo htmlspecialchars($stock['name']); ?></td>
                            <td><?php echo htmlspecialchars($stock['symbol']); ?></td>
                            <td><?php echo $price_html; ?></td>
                            <td class="<?php echo $change_class; ?>"><?php echo $change_html; ?></td>
                            <td><?php echo number_format($stock['pe_ratio'], 2); ?></td>
                            <td><?php echo number_format($stock['pb_ratio'], 2); ?></td>
                            <td><?php echo number_format($stock['current_ratio'], 2); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table></div>
                <?php break;
            
            case 'canslim': ?>
                <div class="screener-wrapper"><table class="screener-table">
                    <thead><tr><th>Rank</th><th>Company</th><th>Symbol</th><th>Price</th><th>Change</th><th>Criteria Met</th></tr></thead>
                    <tbody>
                        <?php foreach ($data as $index => $stock):
                            $quote = $live_quotes[$stock['symbol']] ?? null;
                            $price_html = $quote ? number_format($quote['price'], 2) : '--';
                            $change = $quote ? $quote['change'] : 0;
                            $change_p = $quote ? $quote['changesPercentage'] : 0;
                            $change_class = $change > 0 ? 'positive-change' : ($change < 0 ? 'negative-change' : '');
                            $change_html = $quote ? number_format($change, 2) . ' (' . number_format($change_p, 2) . '%)' : '--';
                        ?>
                        <tr>
                            <td><?php echo $index + 1; ?></td>
                            <td><?php echo htmlspecialchars($stock['name']); ?></td>
                            <td><?php echo htmlspecialchars($stock['symbol']); ?></td>
                            <td><?php echo $price_html; ?></td>
                            <td class="<?php echo $change_class; ?>"><?php echo $change_html; ?></td>
                            <td><?php echo htmlspecialchars($stock['criteria']); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table></div>
                <?php break;
        }
        echo ob_get_clean();
    }
    
    exit; // Stop execution after sending data back.
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
        :root { --bg-color: #1a1a2e; --surface-color: #16213e; --primary-color: #0f3460; --accent-color: #537895; --text-color: #e3e3e3; --text-muted-color: #a0a0a0; --positive-color: #26a69a; --negative-color: #ef5350; }
        body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Oxygen-Sans, Ubuntu, Cantarell, "Helvetica Neue", sans-serif; background-color: var(--bg-color); color: var(--text-color); margin: 0; padding: 20px; }
        .dashboard-container { max-width: 1200px; margin: 0 auto; padding: 20px; background-color: var(--surface-color); border-radius: 12px; box-shadow: 0 10px 30px rgba(0, 0, 0, 0.3); }
        header h1 { margin: 0; color: #fff; font-size: 1.8em; }
        .tabs { display: flex; flex-wrap: wrap; gap: 10px; border-bottom: 2px solid var(--primary-color); }
        .tab-link { padding: 12px 25px; cursor: pointer; border: none; background-color: transparent; color: var(--text-muted-color); font-size: 1.1em; font-weight: 500; border-bottom: 3px solid transparent; transition: all 0.2s ease-in-out; }
        .tab-link:hover { color: #fff; }
        .tab-link.active { color: #fff; border-bottom-color: var(--accent-color); }
        .tab-content-container { padding-top: 25px; min-height: 300px; }
        .loader-wrapper { display: flex; flex-direction: column; align-items: center; justify-content: center; padding: 60px 20px; color: var(--text-muted-color); }
        .loader { border: 5px solid var(--primary-color); border-top: 5px solid var(--accent-color); border-radius: 50%; width: 40px; height: 40px; animation: spin 1s linear infinite; margin-bottom: 15px; }
        .screener-wrapper { overflow-x: auto; }
        .screener-table { width: 100%; border-collapse: collapse; font-size: 0.95em; }
        .screener-table thead tr { background-color: var(--primary-color); text-align: left; }
        .screener-table th, .screener-table td { padding: 14px 16px; border-bottom: 1px solid var(--primary-color); }
        .screener-table tbody tr:last-of-type td { border-bottom: none; }
        .screener-table tbody tr:hover { background-color: rgba(15, 52, 96, 0.5); }
        .positive-change { color: var(--positive-color); }
        .negative-change { color: var(--negative-color); }
        .no-data-message { padding: 40px; text-align: center; color: var(--text-muted-color); border: 1px dashed var(--primary-color); border-radius: 8px; }
        @keyframes spin { 0% { transform: rotate(0deg); } 100% { transform: rotate(360deg); } }
        @keyframes fadeIn { from { opacity: 0; } to { opacity: 1; } }
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
                contentContainer.innerHTML = `<div class="loader-wrapper"><div class="loader"></div><p>Loading & Analyzing ${screenerId.replace('_', ' ')} Data... This may take several minutes.</p></div>`;

                fetch(`?screener_id=${screenerId}`)
                    .then(response => {
                        if (!response.ok) { throw new Error('Server responded with an error.'); }
                        return response.text();
                    })
                    .then(html => {
                        contentContainer.innerHTML = html;
                    })
                    .catch(error => {
                        contentContainer.innerHTML = `<p class="no-data-message">A server error occurred while loading data. The analysis may have timed out. Please try refreshing the page.</p>`;
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

            // Automatically load the data for the default active tab on page load.
            document.querySelector('.tab-link.active')?.click();
        });
    </script>
</body>
</html>