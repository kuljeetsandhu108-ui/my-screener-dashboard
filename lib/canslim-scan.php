<?php
/**
 * Standalone CANSLIM Logic Engine.
 *
 * @since      1.1.0 (Standalone)
 */
class CanslimScan {

    /**
     * This is the main orchestrator for the CANSLIM scan.
     * It takes a list of stocks with their full financial data and filters them.
     *
     * @param array $stocks_with_full_data An array of stocks, each containing historical prices, financials, etc.
     * @return array A list of stocks that pass the CANSLIM criteria.
     */
    public function get_canslim_stocks(array $stocks_with_full_data) {
        $canslim_stocks = [];

        foreach ($stocks_with_full_data as $stock) {
            $score = 0;
            $criteria_met = [];

            // C: Current Quarterly EPS Growth (> 25%)
            if ($this->check_quarterly_eps_growth($stock['quarterly_income'])) {
                $score++;
                $criteria_met['C'] = true;
            }

            // A: Annual EPS Growth (> 25% for 3 years)
            if ($this->check_annual_eps_growth($stock['annual_income'])) {
                $score++;
                $criteria_met['A'] = true;
            }

            // N: New Highs (within 15% of 52-week high)
            if ($this->check_new_highs($stock['historical_price'])) {
                $score++;
                $criteria_met['N'] = true;
            }
            
            // For simplicity in this first version, we will score based on the first 3 criteria.
            // A stock that passes C, A, and N is a very strong growth candidate.
            // We can add S, L, I in a future iteration.

            if ($score >= 3) { // Require all three to pass
                $stock_data = [
                    'symbol' => $stock['symbol'],
                    'name' => $stock['name'],
                    'score' => $score,
                    'price' => $stock['historical_price'][0]['close'] ?? 0,
                    'criteria' => implode(', ', array_keys($criteria_met))
                ];
                $canslim_stocks[] = $stock_data;
            }
        }

        return $canslim_stocks;
    }

    private function check_quarterly_eps_growth(array $quarterly_income) {
        if (count($quarterly_income) < 5) return false; // Need at least 5 quarters for year-over-year comparison
        
        $latest_quarter_eps = $quarterly_income[0]['eps'] ?? 0;
        $year_ago_quarter_eps = $quarterly_income[4]['eps'] ?? 0;

        if ($year_ago_quarter_eps <= 0) return false; // Avoid division by zero and compare against positive earnings

        $growth = (($latest_quarter_eps - $year_ago_quarter_eps) / $year_ago_quarter_eps);
        return $growth > 0.25; // Greater than 25% growth
    }

    private function check_annual_eps_growth(array $annual_income) {
        if (count($annual_income) < 4) return false; // Need at least 4 years for 3 years of growth

        for ($i = 0; $i < 3; $i++) {
            $current_year_eps = $annual_income[$i]['eps'] ?? 0;
            $previous_year_eps = $annual_income[$i+1]['eps'] ?? 0;
            if ($previous_year_eps <= 0) return false;

            $growth = (($current_year_eps - $previous_year_eps) / $previous_year_eps);
            if ($growth < 0.25) {
                return false; // If any of the last 3 years' growth is less than 25%, fail
            }
        }
        return true; // All 3 years had > 25% growth
    }

    private function check_new_highs(array $historical_price) {
        if (empty($historical_price)) return false;
        
        $current_price = $historical_price[0]['close'];
        $prices_last_year = array_column($historical_price, 'high');
        $high_52_week = max($prices_last_year);

        if ($high_52_week == 0) return false;

        // Check if the current price is within 15% of the 52-week high
        return ($current_price / $high_52_week) >= 0.85;
    }
}