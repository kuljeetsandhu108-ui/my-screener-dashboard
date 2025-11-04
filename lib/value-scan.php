<?php
/**
 * Standalone Value Scan (Graham) Logic Engine.
 *
 * @since      1.0.0 (Standalone)
 */
class ValueScan {

    public function get_value_stocks(array $ratios_data) {
        $value_stocks = [];
        foreach ($ratios_data as $stock) {
            $ratios = $stock['ratios'][0] ?? null;
            if (!$ratios) continue;

            $pe_ratio = $ratios['priceEarningsRatio'] ?? null;
            $pb_ratio = $ratios['priceToBookRatio'] ?? null;
            $current_ratio = $ratios['currentRatio'] ?? null;
            $debt_equity_ratio = $ratios['debtEquityRatio'] ?? null;
            $net_income_positive = ($ratios['netProfitMargin'] ?? -1) > 0;

            if (
                !is_null($pe_ratio) && $pe_ratio > 0 && $pe_ratio < 15 &&
                !is_null($pb_ratio) && $pb_ratio > 0 && $pb_ratio < 1.5 &&
                !is_null($current_ratio) && $current_ratio > 2 &&
                !is_null($debt_equity_ratio) && $debt_equity_ratio < 0.5 &&
                $net_income_positive
            ) {
                $value_stocks[] = [
                    'symbol' => $stock['symbol'],
                    'name' => $stock['name'],
                    'pe_ratio' => $pe_ratio,
                    'pb_ratio' => $pb_ratio,
                    'current_ratio' => $current_ratio,
                    'debt_equity_ratio' => $debt_equity_ratio
                ];
            }
        }

        usort($value_stocks, fn($a, $b) => ($a['pe_ratio'] ?? 999) <=> ($b['pe_ratio'] ?? 999));
        return $value_stocks;
    }
}