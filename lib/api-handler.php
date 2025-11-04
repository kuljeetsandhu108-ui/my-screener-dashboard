<?php
/**
 * Standalone API Handler for Financial Modeling Prep (FMP).
 * This class is independent of WordPress and designed for a modern PHP environment.
 * It uses standard cURL for requests and reads the API key from a secure environment variable.
 *
 * @since      1.0.0 (Standalone)
 */
class ApiHandler {

    private const API_BASE_URL = 'https://financialmodelingprep.com/api/v3/';
    private $api_key;

    public function __construct() {
        // Read the API key from a secure environment variable.
        $this->api_key = getenv('VFM2HVdMGlCCVMK6e5GXtI5WoZNKsQtO');
        //$this->api_key = "VFM2HVdMGlCCVMK6e5GXtI5WoZNKsQtO"; // TEMPORARY FOR LOCAL TESTING

    }

    /**
     * A robust, centralized function for making all API requests using cURL.
     *
     * @param string $endpoint The specific API endpoint to call.
     * @return array|object An array of data or an object with an error message.
     */
    public function perform_request( $endpoint ) {
        if ( empty($this->api_key) ) {
            return (object)['error' => 'API Key environment variable is not set.'];
        }

        $separator = strpos($endpoint, '?') === false ? '?' : '&';
        $request_url = self::API_BASE_URL . $endpoint . $separator . 'apikey=' . $this->api_key;
        
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $request_url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30); // Set a 30-second timeout
        
        $response_body = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        
        if (curl_errno($ch)) {
            $error_message = curl_error($ch);
            curl_close($ch);
            return (object)['error' => "cURL Error: " . $error_message];
        }
        
        curl_close($ch);

        if ($http_code !== 200) {
            return (object)['error' => "The API returned a non-200 response. Code: {$http_code}"];
        }

        $data = json_decode($response_body, true);

        if ( !empty($data) && (isset($data['Error Message']) || isset($data[0]['Error Message'])) ) {
             $error_message = $data['Error Message'] ?? $data[0]['Error Message'];
             return (object)['error' => $error_message];
        }

        return $data;
    }
    
    // The following methods are identical in logic to our WordPress plugin,
    // but now they use the standalone perform_request function.

    public function get_all_nse_symbols() {
        return $this->perform_request('stock-screener?exchange=NSE&isActivelyTrading=true&limit=2000');
    }

    public function get_magic_formula_data($symbol) {
        $key_metrics = $this->perform_request("key-metrics/{$symbol}?limit=1");
        if (isset($key_metrics->error)) return $key_metrics;

        $income_statement = $this->perform_request("income-statement/{$symbol}?limit=1");
        if (isset($income_statement->error)) return $income_statement;

        $balance_sheet = $this->perform_request("balance-sheet-statement/{$symbol}?limit=1");
        if (isset($balance_sheet->error)) return $balance_sheet;

        if (empty($key_metrics) || empty($income_statement) || empty($balance_sheet)) {
            return (object)['error' => 'Incomplete financial statements for ' . $symbol];
        }

        $enterprise_value = $key_metrics[0]['enterpriseValue'] ?? 0;
        $ebit = ($income_statement[0]['ebitda'] ?? 0) - ($income_statement[0]['depreciationAndAmortization'] ?? 0);
        $working_capital = ($balance_sheet[0]['totalCurrentAssets'] ?? 0) - ($balance_sheet[0]['totalCurrentLiabilities'] ?? 0);
        $net_fixed_assets = $balance_sheet[0]['propertyPlantEquipmentNet'] ?? 0;

        return ['symbol' => $symbol, 'enterpriseValue' => $enterprise_value, 'ebit' => $ebit, 'netFixedAssets' => $net_fixed_assets, 'workingCapital' => $working_capital];
    }
    
    public function get_live_quotes(array $symbols) {
        $symbol_string = implode(',', $symbols);
        return $this->perform_request("quote/{$symbol_string}");
    }
}