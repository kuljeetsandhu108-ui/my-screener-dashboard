<?php
/**
 * Standalone Piotroski F-Score Logic Engine.
 *
 * @since      1.0.0 (Standalone)
 */
class PiotroskiScan {

    public function calculate_f_score(array $financial_data) {
        if (count($financial_data['income']) < 2 || count($financial_data['balance']) < 2 || count($financial_data['cashflow']) < 2 || count($financial_data['ratios']) < 2) {
            return (object)['error' => 'Insufficient multi-year data provided.'];
        }

        $cy = 0; // Current Year index
        $py = 1; // Previous Year index
        $score = 0;

        // Profitability
        if (($financial_data['income'][$cy]['netIncome'] ?? 0) > 0) $score++;
        if (($financial_data['cashflow'][$cy]['operatingCashFlow'] ?? 0) > 0) $score++;
        if (($financial_data['ratios'][$cy]['returnOnAssets'] ?? 0) > ($financial_data['ratios'][$py]['returnOnAssets'] ?? 0)) $score++;
        if (($financial_data['cashflow'][$cy]['operatingCashFlow'] ?? 0) > ($financial_data['income'][$cy]['netIncome'] ?? 0)) $score++;

        // Leverage & Liquidity
        $debt_ratio_cy = (($financial_data['balance'][$cy]['longTermDebt'] ?? 0) / ($financial_data['balance'][$cy]['totalAssets'] ?? 1));
        $debt_ratio_py = (($financial_data['balance'][$py]['longTermDebt'] ?? 0) / ($financial_data['balance'][$py]['totalAssets'] ?? 1));
        if ($debt_ratio_cy < $debt_ratio_py) $score++;

        if (($financial_data['ratios'][$cy]['currentRatio'] ?? 0) > ($financial_data['ratios'][$py]['currentRatio'] ?? 0)) $score++;
        if (($financial_data['balance'][$cy]['commonStock'] ?? 0) <= ($financial_data['balance'][$py]['commonStock'] ?? 0)) $score++;
        
        // Efficiency
        if (($financial_data['ratios'][$cy]['grossProfitMargin'] ?? 0) > ($financial_data['ratios'][$py]['grossProfitMargin'] ?? 0)) $score++;
        if (($financial_data['ratios'][$cy]['assetTurnover'] ?? 0) > ($financial_data['ratios'][$py]['assetTurnover'] ?? 0)) $score++;

        return $score;
    }
}