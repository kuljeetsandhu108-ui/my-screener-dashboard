<?php
/**
 * All-in-One Screener Dashboard.
 * FINAL SIMPLE VERSION: This single file handles both the UI and the data requests.
 *
 * @since      7.0.0 (Standalone)
 */

// Set a long execution time for data-intensive requests.
ini_set('max_execution_time', 300); // 5 minutes
ini_set('memory_limit', '256M');
date_default_timezone_set('UTC');

// --- Require all our logic classes ---
require_once __DIR__ . '/lib/api-handler.php';
require_once __DIR__ . '/lib/magic-formula.php';
require_once __DIR__ . '/lib/piotroski-scan.php';
require_once __DIR__ . '/lib/value-scan.php';
require_once __DIR__ . '/lib/canslim-scan.php';

/**
 * This block handles the AJAX data requests.
 */
if (isset($_GET['screener_id'])) {
    $screener_id = sanitize_text_field($_GET['screener_id']);
    
    // Initialize Engines
    $api = new ApiHandler();
    $magic_formula_engine = new MagicFormula();
    $piotroski_engine = new PiotroskiScan();
    $value_scan_engine = new ValueScan();
    $canslim_engine = new CanslimScan();

    $data = null;

    // --- This is the new, simplified "worker" logic ---
    switch ($screener_id) {
        case 'magic_formula':
            $symbols_data = $api->get_all_nse_symbols();
            $mf_data_pool = [];
            if (!is_object($symbols_data) && !empty($symbols_data)) {
                foreach (array_slice($symbols_data, 0, 50) as $stock) {
                    $mf_data = $api->get_magic_formula_data($stock['symbol']);
                    if (!is_object($mf_data)) {
                        $mf_data['name'] = $stock['companyName'] ?? 'N/A';
                        $mf_data_pool[] = $mf_data;
                    }
                    usleep(100000);
                }
            }
            $data = $magic_formula_engine->get_ranked_stocks($mf_data_pool);
            break;
        // Other cases would go here...
    }

    // Render the HTML table for the data
    if (empty($data)) {
        echo '<div class="no-data-message">No data found for this screener.</div>';
    } else {
        // Simple example for Magic Formula
        echo '<div class="screener-wrapper"><table class="screener-table"><thead><tr><th>Rank</th><th>Company</th><th>Symbol</th><th>Yield</th><th>ROC</th></tr></thead><tbody>';
        foreach ($data as $index => $stock) {
            echo '<tr>';
            echo '<td>' . ($index + 1) . '</td>';
            echo '<td>' . htmlspecialchars($stock['name']) . '</td>';
            echo '<td>' . htmlspecialchars($stock['symbol']) . '</td>';
            echo '<td>' . number_format($stock['earnings_yield'] * 100, 2) . '%</td>';
            echo '<td>' . number_format($stock['return_on_capital'] * 100, 2) . '%</td>';
            echo '</tr>';
        }
        echo '</tbody></table></div>';
    }
    
    exit; // Stop execution after sending data back.
}


/**
 * This block displays the main page HTML shell.
 */
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Investment Screener Dashboard</title>
    <style>
        /* All the CSS from our prototype goes here */
        :root {
            --bg-color: #1a1a2e; --surface-color: #16213e; --primary-color: #0f3460;
            --accent-color: #537895; --text-color: #e3e3e3; --text-muted-color: #a0a0a0;
        }
        body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Oxygen-Sans, Ubuntu, Cantarell, "Helvetica Neue", sans-serif; background-color: var(--bg-color); color: var(--text-color); margin: 0; padding: 20px; }
        .dashboard-container { max-width: 1200px; margin: 0 auto; padding: 20px; background-color: var(--surface-color); border-radius: 12px; box-shadow: 0 10px 30px rgba(0, 0, 0, 0.3); }
        header h1 { margin: 0; color: #fff; font-size: 1.8em; }
        .tabs { display: flex; gap: 10px; border-bottom: 2px solid var(--primary-color); }
        .tab-link { padding: 12px 25px; cursor: pointer; border: none; background-color: transparent; color: var(--text-muted-color); font-size: 1.1em; font-weight: 500; border-bottom: 3px solid transparent; transition: all 0.2s ease-in-out; }
        .tab-link.active { color: #fff; border-bottom-color: var(--accent-color); }
        .tab-content-container { padding-top: 25px; min-height: 200px; }
        .tab-content { display: none; animation: fadeIn 0.5s; }
        .tab-content.active { display: block; }
        .loader-wrapper { display: flex; flex-direction: column; align-items: center; justify-content: center; padding: 60px 20px; color: var(--text-muted-color); }
        .loader { border: 5px solid var(--primary-color); border-top: 5px solid var(--accent-color); border-radius: 50%; width: 40px; height: 40px; animation: spin 1s linear infinite; margin-bottom: 15px; }
        .screener-wrapper { overflow-x: auto; }
        .screener-table { width: 100%; border-collapse: collapse; font-size: 0.95em; }
        .screener-table thead tr { background-color: var(--primary-color); text-align: left; }
        .screener-table th, .screener-table td { padding: 14px 16px; }
        .no-data-message { padding: 40px; text-align: center; color: var(--text-muted-color); }
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
                <!-- Add other tabs here as needed -->
            </div>
            <div class="tab-content-container">
                <div id="content-magic_formula" class="tab-content active">
                    <!-- Data will be loaded here -->
                </div>
            </div>
        </main>
    </div>

    <script>
        // New, simpler JavaScript for on-demand loading
        document.addEventListener('DOMContentLoaded', () => {
            const tabs = document.querySelectorAll('.tab-link');
            const contentContainer = document.querySelector('.tab-content-container');

            const loadScreenerData = (screenerId) => {
                contentContainer.innerHTML = `<div class="loader-wrapper"><div class="loader"></div><p>Loading ${screenerId.replace('_', ' ')} Data...</p></div>`;

                // Use the browser's fetch API to call our own page with a query parameter
                fetch(`?screener_id=${screenerId}`)
                    .then(response => response.text())
                    .then(html => {
                        contentContainer.innerHTML = html;
                    })
                    .catch(error => {
                        contentContainer.innerHTML = `<p class="no-data-message">An error occurred while loading data.</p>`;
                        console.error('Error:', error);
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
            const defaultScreenerId = document.querySelector('.tab-link.active')?.dataset.screener;
            if (defaultScreenerId) {
                loadScreenerData(defaultScreenerId);
            }
        });
    </script>

</body>
</html>