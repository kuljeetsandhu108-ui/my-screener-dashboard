<?php
/**
 * Standalone Magic Formula Logic Engine.
 *
 * @since      1.0.0 (Standalone)
 */
class MagicFormula {

    public function get_ranked_stocks(array $stocks_data) {
        $stocks_with_ratios = [];
        foreach ( $stocks_data as $stock ) {
            // Basic validation to ensure data exists before calculation.
            if (empty($stock['enterpriseValue']) || empty($stock['ebit'])) continue;

            $capital = ($stock['netFixedAssets'] ?? 0) + ($stock['workingCapital'] ?? 0);
            if ( $capital <= 0 ) continue;

            $stock['earnings_yield'] = $stock['ebit'] / $stock['enterpriseValue'];
            $stock['return_on_capital'] = $stock['ebit'] / $capital;
            $stocks_with_ratios[] = $stock;
        }
        
        if (empty($stocks_with_ratios)) return [];

        usort($stocks_with_ratios, fn($a, $b) => ($b['earnings_yield'] ?? 0) <=> ($a['earnings_yield'] ?? 0));
        $ranked_stocks = [];
        foreach ($stocks_with_ratios as $rank => $stock) {
            $ranked_stocks[$stock['symbol']] = $stock;
            $ranked_stocks[$stock['symbol']]['ey_rank'] = $rank + 1;
        }

        usort($stocks_with_ratios, fn($a, $b) => ($b['return_on_capital'] ?? 0) <=> ($a['return_on_capital'] ?? 0));
        foreach ($stocks_with_ratios as $rank => $stock) {
            if (isset($ranked_stocks[$stock['symbol']])) {
                $ranked_stocks[$stock['symbol']]['roc_rank'] = $rank + 1;
            }
        }
        
        foreach ($ranked_stocks as $symbol => $stock) {
            $ranked_stocks[$symbol]['combined_rank'] = ($stock['ey_rank'] ?? 9999) + ($stock['roc_rank'] ?? 9999);
        }
        
        usort($ranked_stocks, fn($a, $b) => ($a['combined_rank'] ?? 9999) <=> ($b['combined_rank'] ?? 9999));

        return $ranked_stocks;
    }
}