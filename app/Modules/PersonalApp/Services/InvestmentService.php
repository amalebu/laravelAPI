<?php

namespace App\Modules\PersonalApp\Services;

use App\Modules\PersonalApp\Models\InvestmentCashAccount;
use App\Modules\PersonalApp\Models\InvestmentCashMutation;
use App\Modules\PersonalApp\Models\InvestmentStock;
use App\Modules\PersonalApp\Models\InvestmentStockLot;
use App\Modules\PersonalApp\Models\InvestmentStockSale;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class InvestmentService
{
    public const SHARES_PER_LOT = 100;
    public const DEFAULT_BUY_FEE_RATE = 0.001513; // 0.1513%
    public const DEFAULT_SELL_FEE_RATE = 0.002513; // 0.2513%
    public const STAMP_DUTY_THRESHOLD = 10000000; // Rp 10.000.000
    public const STAMP_DUTY_AMOUNT = 10000; // Rp 10.000
    public const STAMP_DUTY_DESCRIPTION = 'Fee materai';

    public function __construct(
        private readonly YahooFinanceService $yahooFinanceService
    ) {}

    /**
     * Memastikan akun Cash RDN milik user sudah tersedia.
     */
    public function getOrCreateCashAccount(int $userId): InvestmentCashAccount
    {
        return InvestmentCashAccount::firstOrCreate(
            ['user_id' => $userId],
            [
                'balance' => 0,
                'total_topup' => 0,
                'total_withdrawn' => 0,
            ]
        );
    }

    /**
     * Mengambil seluruh data portofolio: Ringkasan, Akun Cash RDN, Daftar Saham & Lot,
     * Riwayat Penjualan (Realized P/L), dan Mutasi Cash.
     */
    public function getPortfolio(int $userId, bool $forceRefreshPrices = false): array
    {
        $cashAccount = $this->getOrCreateCashAccount($userId);

        $stocks = InvestmentStock::where('user_id', $userId)
            ->with([
                'lots' => fn ($q) => $q->orderByDesc('opened_at')->orderByDesc('id'),
            ])
            ->orderBy('ticker')
            ->get();

        // Auto-refresh harga untuk saham yang memiliki lot terbuka jika stale (> 5 menit) atau dipaksa
        $now = Carbon::now('Asia/Jakarta');
        foreach ($stocks as $stock) {
            $hasOpenLots = $stock->lots->contains(fn ($lot) => $lot->remaining_lots > 0);
            if (! $hasOpenLots) {
                continue;
            }

            $isStale = ! $stock->price_updated_at ||
                $stock->price_updated_at->diffInMinutes($now) >= 5 ||
                (float) $stock->last_price <= 0;

            if ($forceRefreshPrices || $isStale) {
                $this->syncStockQuote($stock);
            }
        }

        // Bangun daftar emiten beserta rincian lot aktif
        $activeStocksPayload = [];
        $totalStockMarketValue = 0.0;
        $totalStockCostBasis = 0.0;
        $totalDailyPl = 0.0;
        $totalOpenLotsCount = 0;

        foreach ($stocks as $stock) {
            $openLots = $stock->lots->filter(fn ($lot) => $lot->remaining_lots > 0)->values();
            if ($openLots->isEmpty()) {
                continue;
            }

            $lastPrice = (float) $stock->last_price;
            $prevClose = (float) ($stock->previous_close > 0 ? $stock->previous_close : $lastPrice);

            $sumLots = 0;
            $weightedPriceNumerator = 0.0;
            $stockCostBasis = 0.0;
            $stockTotalFee = 0.0;
            $lotsFormatted = [];

            foreach ($openLots as $lot) {
                $remLots = (int) $lot->remaining_lots;
                $initLots = max(1, (int) $lot->initial_lots);
                $remShares = $remLots * self::SHARES_PER_LOT;
                $buyPrice = (float) $lot->buy_price;
                $allocatedFee = round(((float) $lot->buy_fee) * ($remLots / $initLots), 2);

                $lotGrossCost = $remShares * $buyPrice;
                $lotTotalCost = $lotGrossCost + $allocatedFee;
                $lotMarketValue = $remShares * $lastPrice;
                $lotDailyPl = $remShares * ($lastPrice - $prevClose);
                $lotNetPl = $lotMarketValue - $lotTotalCost;
                $lotNetPlPercent = $lotTotalCost > 0
                    ? round(($lotNetPl / $lotTotalCost) * 100, 2)
                    : 0.0;

                $sumLots += $remLots;
                $weightedPriceNumerator += ($remLots * $buyPrice);
                $stockCostBasis += $lotTotalCost;
                $stockTotalFee += $allocatedFee;

                $lotsFormatted[] = [
                    'id' => $lot->id,
                    'stock_id' => $stock->id,
                    'ticker' => $stock->ticker,
                    'opened_at' => $lot->opened_at?->format('Y-m-d'),
                    'initial_lots' => (int) $lot->initial_lots,
                    'remaining_lots' => $remLots,
                    'remaining_shares' => $remShares,
                    'buy_price' => round($buyPrice, 2),
                    'buy_fee' => round((float) $lot->buy_fee, 2),
                    'allocated_fee' => $allocatedFee,
                    'total_cost' => round($lotTotalCost, 2),
                    'market_value' => round($lotMarketValue, 2),
                    'daily_pl' => round($lotDailyPl, 2),
                    'net_pl' => round($lotNetPl, 2),
                    'net_pl_percent' => $lotNetPlPercent,
                    'deducted_from_cash' => (bool) $lot->deducted_from_cash,
                    'notes' => $lot->notes,
                    'status' => $lot->status,
                ];
            }

            $totalShares = $sumLots * self::SHARES_PER_LOT;
            $avgBuyPrice = $sumLots > 0 ? round($weightedPriceNumerator / $sumLots, 2) : 0.0;
            $stockMarketValue = $totalShares * $lastPrice;
            $stockDailyPl = $totalShares * ($lastPrice - $prevClose);
            $stockDailyPlPercent = $prevClose > 0
                ? round((($lastPrice - $prevClose) / $prevClose) * 100, 2)
                : 0.0;
            $stockNetPl = $stockMarketValue - $stockCostBasis;
            $stockNetPlPercent = $stockCostBasis > 0
                ? round(($stockNetPl / $stockCostBasis) * 100, 2)
                : 0.0;

            $totalStockMarketValue += $stockMarketValue;
            $totalStockCostBasis += $stockCostBasis;
            $totalDailyPl += $stockDailyPl;
            $totalOpenLotsCount += $sumLots;

            $activeStocksPayload[] = [
                'id' => $stock->id,
                'ticker' => $stock->ticker,
                'yahoo_symbol' => $stock->yahoo_symbol,
                'company_name' => $stock->company_name ?: $stock->ticker,
                'total_lots' => $sumLots,
                'total_shares' => $totalShares,
                'avg_buy_price' => $avgBuyPrice,
                'last_price' => round($lastPrice, 2),
                'previous_close' => round($prevClose, 2),
                'total_fee' => round($stockTotalFee, 2),
                'total_cost' => round($stockCostBasis, 2),
                'market_value' => round($stockMarketValue, 2),
                'daily_pl' => round($stockDailyPl, 2),
                'daily_pl_percent' => $stockDailyPlPercent,
                'net_pl' => round($stockNetPl, 2),
                'net_pl_percent' => $stockNetPlPercent,
                'price_updated_at' => $stock->price_updated_at?->timezone('Asia/Jakarta')->toIso8601String(),
                'lots_count' => count($lotsFormatted),
                'lots' => $lotsFormatted,
            ];
        }

        // Riwayat penjualan (Realized P/L)
        $sales = InvestmentStockSale::where('user_id', $userId)
            ->with('stock:id,ticker,company_name')
            ->orderByDesc('sold_at')
            ->orderByDesc('id')
            ->limit(50)
            ->get();

        // Akumulasi Realized P/L dari penjualan saham dikurangi total biaya Fee Materai yang dikenakan
        $stockSalesRealizedPl = (float) InvestmentStockSale::where('user_id', $userId)->sum('realized_pl');
        $totalStampDuty = abs((float) InvestmentCashMutation::where('user_id', $userId)
            ->where('type', 'fee')
            ->where('description', self::STAMP_DUTY_DESCRIPTION)
            ->sum('amount'));
        $totalRealizedPl = $stockSalesRealizedPl - $totalStampDuty;

        // 1. Ambil seluruh lot yang pernah terlibat dalam penjualan untuk rincian buy breakdown
        $allLotIds = [];
        foreach ($sales as $sale) {
            if (! empty($sale->consumed_lots) && is_array($sale->consumed_lots)) {
                foreach ($sale->consumed_lots as $item) {
                    if (! empty($item['lot_id'])) {
                        $allLotIds[] = (int) $item['lot_id'];
                    }
                }
            }
            if ($sale->stock_lot_id) {
                $allLotIds[] = (int) $sale->stock_lot_id;
            }
        }
        $lotsMap = ! empty($allLotIds)
            ? InvestmentStockLot::where('user_id', $userId)
                ->whereIn('id', array_unique($allLotIds))
                ->get()
                ->keyBy('id')
            : collect();

        // 2. Mapping tanggal-tanggal yang dikenakan bea meterai
        $stampDutyDates = InvestmentCashMutation::where('user_id', $userId)
            ->where('type', 'fee')
            ->where('description', self::STAMP_DUTY_DESCRIPTION)
            ->get()
            ->map(fn ($m) => $m->transacted_at?->format('Y-m-d'))
            ->filter()
            ->unique()
            ->flip()
            ->all();

        $salesFormatted = $sales->map(function (InvestmentStockSale $sale) use ($lotsMap, $stampDutyDates) {
            $saleDate = $sale->sold_at?->format('Y-m-d');
            $soldLots = (int) $sale->sold_lots;
            $soldShares = $soldLots * self::SHARES_PER_LOT;
            $hasStampDuty = isset($stampDutyDates[$saleDate]);

            $buyBreakdown = [];
            $totalBuyGross = 0.0;
            $totalBuyFee = 0.0;

            if (! empty($sale->consumed_lots) && is_array($sale->consumed_lots)) {
                foreach ($sale->consumed_lots as $item) {
                    $lotId = $item['lot_id'] ?? null;
                    $itemLots = (int) ($item['lots'] ?? 0);
                    if ($itemLots <= 0) {
                        continue;
                    }

                    $lot = $lotId ? $lotsMap->get($lotId) : null;
                    $buyPrice = $lot ? (float) $lot->buy_price : (float) $sale->buy_avg_price;
                    $itemShares = $itemLots * self::SHARES_PER_LOT;
                    $grossCost = $itemShares * $buyPrice;

                    $initLots = $lot ? max(1, (int) $lot->initial_lots) : $itemLots;
                    $allocatedFee = $lot
                        ? round(((float) $lot->buy_fee) * ($itemLots / $initLots), 2)
                        : 0.0;
                    $netCost = round($grossCost + $allocatedFee, 2);

                    $totalBuyGross += $grossCost;
                    $totalBuyFee += $allocatedFee;

                    $buyBreakdown[] = [
                        'lot_id' => $lotId,
                        'opened_at' => $lot?->opened_at?->format('Y-m-d') ?? '-',
                        'lots' => $itemLots,
                        'shares' => $itemShares,
                        'buy_price' => round($buyPrice, 2),
                        'gross_cost' => round($grossCost, 2),
                        'buy_fee' => round($allocatedFee, 2),
                        'net_cost' => $netCost,
                    ];
                }
            } elseif ($sale->stock_lot_id && ($lot = $lotsMap->get($sale->stock_lot_id))) {
                $buyPrice = (float) $lot->buy_price;
                $grossCost = $soldShares * $buyPrice;
                $initLots = max(1, (int) $lot->initial_lots);
                $allocatedFee = round(((float) $lot->buy_fee) * ($soldLots / $initLots), 2);
                $netCost = round($grossCost + $allocatedFee, 2);

                $totalBuyGross += $grossCost;
                $totalBuyFee += $allocatedFee;

                $buyBreakdown[] = [
                    'lot_id' => $lot->id,
                    'opened_at' => $lot->opened_at?->format('Y-m-d') ?? '-',
                    'lots' => $soldLots,
                    'shares' => $soldShares,
                    'buy_price' => round($buyPrice, 2),
                    'gross_cost' => round($grossCost, 2),
                    'buy_fee' => round($allocatedFee, 2),
                    'net_cost' => $netCost,
                ];
            } else {
                // Fallback untuk historical sales
                $buyPrice = (float) $sale->buy_avg_price;
                $grossCost = $soldShares * $buyPrice;
                $allocatedFee = max(0, round((float) $sale->net_proceeds - (float) $sale->realized_pl - $grossCost, 2));
                if ($allocatedFee <= 0) {
                    $allocatedFee = round($grossCost * 0.001513, 2);
                }
                $netCost = round($grossCost + $allocatedFee, 2);

                $totalBuyGross += $grossCost;
                $totalBuyFee += $allocatedFee;

                $buyBreakdown[] = [
                    'lot_id' => null,
                    'opened_at' => '-',
                    'lots' => $soldLots,
                    'shares' => $soldShares,
                    'buy_price' => round($buyPrice, 2),
                    'gross_cost' => round($grossCost, 2),
                    'buy_fee' => round($allocatedFee, 2),
                    'net_cost' => $netCost,
                ];
            }

            $totalBuyNet = round($totalBuyGross + $totalBuyFee, 2);

            return [
                'id' => $sale->id,
                'stock_id' => $sale->stock_id,
                'stock_lot_id' => $sale->stock_lot_id,
                'ticker' => $sale->stock?->ticker ?? '-',
                'company_name' => $sale->stock?->company_name ?? '-',
                'sold_at' => $saleDate,
                'sold_lots' => $soldLots,
                'sold_shares' => $soldShares,
                'buy_avg_price' => round((float) $sale->buy_avg_price, 2),
                'buy_gross_cost' => round($totalBuyGross, 2),
                'buy_fee_total' => round($totalBuyFee, 2),
                'buy_net_cost' => $totalBuyNet,
                'buy_breakdown' => $buyBreakdown,
                'sell_price' => round((float) $sale->sell_price, 2),
                'sell_fee' => round((float) $sale->sell_fee, 2),
                'gross_proceeds' => round((float) $sale->gross_proceeds, 2),
                'net_proceeds' => round((float) $sale->net_proceeds, 2),
                'daily_stamp_duty' => $hasStampDuty ? self::STAMP_DUTY_AMOUNT : 0,
                'has_daily_stamp_duty' => $hasStampDuty,
                'realized_pl' => round((float) $sale->realized_pl, 2),
                'realized_pl_percent' => round((float) $sale->realized_pl_percent, 2),
                'added_to_cash' => (bool) $sale->added_to_cash,
                'notes' => $sale->notes,
            ];
        })->values()->all();

        // Riwayat mutasi Cash RDN
        $cashMutations = InvestmentCashMutation::where('user_id', $userId)
            ->orderByDesc('transacted_at')
            ->orderByDesc('id')
            ->limit(50)
            ->get()
            ->map(fn (InvestmentCashMutation $m) => [
                'id' => $m->id,
                'type' => $m->type,
                'amount' => round((float) $m->amount, 2),
                'balance_after' => round((float) $m->balance_after, 2),
                'description' => $m->description,
                'transacted_at' => $m->transacted_at?->format('Y-m-d'),
                'created_at' => $m->created_at?->timezone('Asia/Jakarta')->toIso8601String(),
            ])->values()->all();

        $cashBalance = round((float) $cashAccount->balance, 2);
        $totalNetPl = round($totalStockMarketValue - $totalStockCostBasis, 2);
        $totalNetPlPercent = $totalStockCostBasis > 0
            ? round(($totalNetPl / $totalStockCostBasis) * 100, 2)
            : 0.0;
        $totalPortfolioValue = round($cashBalance + $totalStockMarketValue, 2);

        return [
            'summary' => [
                'total_portfolio_value' => $totalPortfolioValue,
                'cash_balance' => $cashBalance,
                'stock_market_value' => round($totalStockMarketValue, 2),
                'stock_cost_basis' => round($totalStockCostBasis, 2),
                'daily_pl' => round($totalDailyPl, 2),
                'unrealized_pl' => $totalNetPl,
                'unrealized_pl_percent' => $totalNetPlPercent,
                'realized_pl' => round($totalRealizedPl, 2),
                'stock_sales_realized_pl' => round($stockSalesRealizedPl, 2),
                'total_stamp_duty' => round($totalStampDuty, 2),
                'active_tickers_count' => count($activeStocksPayload),
                'total_open_lots' => $totalOpenLotsCount,
            ],
            'cash' => [
                'balance' => $cashBalance,
                'total_topup' => round((float) $cashAccount->total_topup, 2),
                'total_withdrawn' => round((float) $cashAccount->total_withdrawn, 2),
                'mutations' => $cashMutations,
            ],
            'stocks' => $activeStocksPayload,
            'sales' => $salesFormatted,
        ];
    }

    /**
     * Top-Up, Withdraw (Tarik), atau Set Saldo langsung pada Cash RDN.
     */
    public function adjustCash(int $userId, array $data): array
    {
        return DB::connection('personal_app')->transaction(function () use ($userId, $data) {
            $cash = $this->getOrCreateCashAccount($userId);
            $type = $data['type']; // topup | withdraw | adjustment
            $amount = round((float) $data['amount'], 2);
            $date = $data['transacted_at'] ?? Carbon::now('Asia/Jakarta')->format('Y-m-d');
            $description = $data['description'] ?? null;

            $currentBalance = (float) $cash->balance;

            if ($type === 'topup') {
                $newBalance = $currentBalance + $amount;
                $cash->balance = $newBalance;
                $cash->total_topup = (float) $cash->total_topup + $amount;
                $cash->save();

                $mutationAmount = $amount;
                $defaultDesc = 'Top-Up Saldo Cash RDN';
            } elseif ($type === 'withdraw') {
                $newBalance = $currentBalance - $amount;
                $cash->balance = $newBalance;
                $cash->total_withdrawn = (float) $cash->total_withdrawn + $amount;
                $cash->save();

                $mutationAmount = -$amount;
                $defaultDesc = 'Penarikan Saldo Cash RDN';
            } else {
                // adjustment: set balance langsung ke nominal $amount
                $diff = $amount - $currentBalance;
                $newBalance = $amount;
                $cash->balance = $newBalance;
                $cash->save();

                $mutationAmount = $diff;
                $defaultDesc = 'Penyesuaian Saldo Cash RDN';
            }

            InvestmentCashMutation::create([
                'user_id' => $userId,
                'type' => $type,
                'amount' => $mutationAmount,
                'balance_after' => $newBalance,
                'description' => $description ?: $defaultDesc,
                'transacted_at' => $date,
            ]);

            return $this->getPortfolio($userId, false);
        });
    }

    /**
     * Mencatat pembelian saham baru (Lot Pembelian).
     */
    public function buyStockLot(int $userId, array $data): array
    {
        $norm = $this->yahooFinanceService->normalizeSymbol($data['ticker']);
        $ticker = $norm['ticker'];
        $yahooSymbol = $norm['yahoo_symbol'];

        // Coba ambil quote dari Yahoo Finance di luar transaksi DB agar tidak mengunci SQLite
        $quote = $this->yahooFinanceService->fetchQuote($ticker);

        return DB::connection('personal_app')->transaction(function () use ($userId, $data, $ticker, $yahooSymbol, $quote) {
            $lots = (int) $data['lots'];
            $buyPrice = round((float) $data['buy_price'], 2);
            $grossCost = $lots * self::SHARES_PER_LOT * $buyPrice;

            if (! array_key_exists('buy_fee', $data) || $data['buy_fee'] === null) {
                $buyFee = round($grossCost * self::DEFAULT_BUY_FEE_RATE, 2);
            } else {
                $buyFee = round((float) $data['buy_fee'], 2);
            }

            $openedAt = $data['opened_at'] ?? Carbon::now('Asia/Jakarta')->format('Y-m-d');
            $deductFromCash = (bool) ($data['deduct_from_cash'] ?? true);
            $notes = $data['notes'] ?? null;

            $totalPurchaseCost = $grossCost + $buyFee;

            $cash = $this->getOrCreateCashAccount($userId);

            $stock = InvestmentStock::firstOrNew([
                'user_id' => $userId,
                'ticker' => $ticker,
            ]);

            $stock->yahoo_symbol = $yahooSymbol;
            if ($quote) {
                $stock->company_name = $data['company_name'] ?? $quote['company_name'] ?? $stock->company_name ?? $ticker;
                $stock->last_price = $quote['last_price'] > 0 ? $quote['last_price'] : $buyPrice;
                $stock->previous_close = $quote['previous_close'] > 0 ? $quote['previous_close'] : $stock->last_price;
                $stock->price_updated_at = Carbon::now('Asia/Jakarta');
            } else {
                $stock->company_name = $data['company_name'] ?? $stock->company_name ?? $this->yahooFinanceService->findDefaultName($ticker);
                if ((float) $stock->last_price <= 0) {
                    $stock->last_price = $buyPrice;
                    $stock->previous_close = $buyPrice;
                }
            }
            $stock->save();

            $lot = InvestmentStockLot::create([
                'user_id' => $userId,
                'stock_id' => $stock->id,
                'opened_at' => $openedAt,
                'initial_lots' => $lots,
                'remaining_lots' => $lots,
                'buy_price' => $buyPrice,
                'buy_fee' => $buyFee,
                'deducted_from_cash' => $deductFromCash,
                'notes' => $notes,
                'status' => 'open',
            ]);

            if ($deductFromCash) {
                $newBalance = (float) $cash->balance - $totalPurchaseCost;
                $cash->balance = $newBalance;
                $cash->save();

                InvestmentCashMutation::create([
                    'user_id' => $userId,
                    'type' => 'buy_stock',
                    'amount' => -$totalPurchaseCost,
                    'balance_after' => $newBalance,
                    'description' => sprintf(
                        'Beli %s %d Lot @ Rp %s',
                        $ticker,
                        $lots,
                        number_format($buyPrice, 0, ',', '.')
                    ),
                    'reference_id' => $lot->id,
                    'transacted_at' => $openedAt,
                ]);
            }

            // Sinkronisasi Bea Meterai harian (> Rp 10 Juta)
            $this->syncDailyStampDuty($userId, $openedAt);

            return $this->getPortfolio($userId, false);
        });
    }

    /**
     * Edit Posisi lot pembelian spesifik (Tanggal Pembukaan, Jumlah Lot, Harga Pembukaan, Komisi).
     */
    public function updateStockLot(int $userId, int $lotId, array $data): array
    {
        return DB::connection('personal_app')->transaction(function () use ($userId, $lotId, $data) {
            $lot = InvestmentStockLot::where('user_id', $userId)
                ->with('stock')
                ->findOrFail($lotId);

            $oldOpenedAt = $lot->opened_at?->format('Y-m-d');
            $oldRemainingLots = (int) $lot->remaining_lots;
            $oldInitialLots = max(1, (int) $lot->initial_lots);
            $oldAllocatedFee = round(((float) $lot->buy_fee) * ($oldRemainingLots / $oldInitialLots), 2);
            $oldCost = ($oldRemainingLots * self::SHARES_PER_LOT * (float) $lot->buy_price) + $oldAllocatedFee;

            $newLots = isset($data['lots']) ? (int) $data['lots'] : $oldRemainingLots;
            $newBuyPrice = isset($data['buy_price']) ? round((float) $data['buy_price'], 2) : (float) $lot->buy_price;
            $newBuyFee = isset($data['buy_fee']) ? round((float) $data['buy_fee'], 2) : (float) $lot->buy_fee;
            $newOpenedAt = $data['opened_at'] ?? $lot->opened_at?->format('Y-m-d');
            $newNotes = array_key_exists('notes', $data) ? $data['notes'] : $lot->notes;

            $newCost = ($newLots * self::SHARES_PER_LOT * $newBuyPrice) + $newBuyFee;

            if ($lot->deducted_from_cash && abs($newCost - $oldCost) > 0.009) {
                $cash = $this->getOrCreateCashAccount($userId);
                $diff = $newCost - $oldCost; // positif = butuh tambahan cash, negatif = refund cash

                if ($diff > 0 && (float) $cash->balance < $diff) {
                    throw ValidationException::withMessages([
                        'lots' => ['Saldo Cash RDN tidak mencukupi untuk penambahan nilai posisi ini.'],
                    ]);
                }

                $newBalance = (float) $cash->balance - $diff;
                $cash->balance = $newBalance;
                $cash->save();

                InvestmentCashMutation::create([
                    'user_id' => $userId,
                    'type' => 'adjustment',
                    'amount' => -$diff,
                    'balance_after' => $newBalance,
                    'description' => sprintf(
                        ' Koreksi Posisi %s (%d Lot @ Rp %s)',
                        $lot->stock?->ticker ?? 'Saham',
                        $newLots,
                        number_format($newBuyPrice, 0, ',', '.')
                    ),
                    'reference_id' => $lot->id,
                    'transacted_at' => Carbon::now('Asia/Jakarta')->format('Y-m-d'),
                ]);
            }

            $soldAlready = max(0, (int) $lot->initial_lots - $oldRemainingLots);
            $lot->opened_at = $newOpenedAt;
            $lot->remaining_lots = $newLots;
            $lot->initial_lots = $newLots + $soldAlready;
            $lot->buy_price = $newBuyPrice;
            $lot->buy_fee = $newBuyFee;
            $lot->notes = $newNotes;
            $lot->status = $soldAlready > 0 ? 'partial' : 'open';
            $lot->save();

            // Sinkronisasi Bea Meterai harian (cek tanggal baru dan lama jika tanggal diubah)
            $this->syncDailyStampDuty($userId, $newOpenedAt);
            if ($oldOpenedAt && $oldOpenedAt !== $newOpenedAt) {
                $this->syncDailyStampDuty($userId, $oldOpenedAt);
            }

            return $this->getPortfolio($userId, false);
        });
    }

    /**
     * Tutup Posisi (Jual Saham) baik dari baris lot spesifik maupun gabungan emiten (FIFO).
     */
    public function closeStockPosition(int $userId, array $data): array
    {
        return DB::connection('personal_app')->transaction(function () use ($userId, $data) {
            $soldLotsTarget = (int) $data['sold_lots'];
            $sellPrice = round((float) $data['sell_price'], 2);
            $grossProceeds = $soldLotsTarget * self::SHARES_PER_LOT * $sellPrice;

            if (! array_key_exists('sell_fee', $data) || $data['sell_fee'] === null) {
                $sellFee = round($grossProceeds * self::DEFAULT_SELL_FEE_RATE, 2);
            } else {
                $sellFee = round((float) $data['sell_fee'], 2);
            }

            $soldAt = $data['sold_at'] ?? Carbon::now('Asia/Jakarta')->format('Y-m-d');
            $addToCash = (bool) ($data['add_to_cash'] ?? true);
            $notes = $data['notes'] ?? null;

            $lotId = $data['lot_id'] ?? null;
            $stockId = $data['stock_id'] ?? null;

            if ($lotId) {
                $targetLot = InvestmentStockLot::where('user_id', $userId)
                    ->with('stock')
                    ->findOrFail($lotId);

                if ($soldLotsTarget > (int) $targetLot->remaining_lots) {
                    throw ValidationException::withMessages([
                        'sold_lots' => ["Jumlah lot yang ditutup melebihi sisa lot pada posisi ini ({$targetLot->remaining_lots} lot)."],
                    ]);
                }

                $stock = $targetLot->stock;
                $lotsToConsume = collect([$targetLot]);
            } else {
                $stock = InvestmentStock::where('user_id', $userId)->findOrFail($stockId);
                $lotsToConsume = InvestmentStockLot::where('user_id', $userId)
                    ->where('stock_id', $stock->id)
                    ->where('remaining_lots', '>', 0)
                    ->orderBy('opened_at')
                    ->orderBy('id')
                    ->get();

                $totalAvailable = (int) $lotsToConsume->sum('remaining_lots');
                if ($soldLotsTarget > $totalAvailable) {
                    throw ValidationException::withMessages([
                        'sold_lots' => ["Jumlah lot yang dijual melebihi total lot {$stock->ticker} yang tersedia ({$totalAvailable} lot)."],
                    ]);
                }
            }

            $remainingToSell = $soldLotsTarget;
            $weightedBuyNumerator = 0.0;
            $allocatedBuyFeeTotal = 0.0;
            $primaryLotId = $lotId ? (int) $lotId : null;
            $consumedLots = [];

            foreach ($lotsToConsume as $lot) {
                if ($remainingToSell <= 0) {
                    break;
                }

                $available = (int) $lot->remaining_lots;
                $take = min($available, $remainingToSell);
                $initLots = max(1, (int) $lot->initial_lots);

                $weightedBuyNumerator += ($take * (float) $lot->buy_price);
                $allocatedBuyFeeTotal += ((float) $lot->buy_fee) * ($take / $initLots);

                $lot->remaining_lots = $available - $take;
                $lot->status = $lot->remaining_lots === 0 ? 'closed' : 'partial';
                $lot->save();

                $consumedLots[] = [
                    'lot_id' => $lot->id,
                    'lots' => $take,
                ];

                $remainingToSell -= $take;
            }

            $buyAvgPrice = round($weightedBuyNumerator / $soldLotsTarget, 2);
            $soldShares = $soldLotsTarget * self::SHARES_PER_LOT;
            $costBasis = ($soldShares * $buyAvgPrice) + $allocatedBuyFeeTotal;
            $grossProceeds = $soldShares * $sellPrice;
            $netProceeds = $grossProceeds - $sellFee;
            $realizedPl = round($netProceeds - $costBasis, 2);
            $realizedPlPercent = $costBasis > 0
                ? round(($realizedPl / $costBasis) * 100, 2)
                : 0.0;

            $sale = InvestmentStockSale::create([
                'user_id' => $userId,
                'stock_id' => $stock->id,
                'stock_lot_id' => $primaryLotId,
                'sold_at' => $soldAt,
                'sold_lots' => $soldLotsTarget,
                'buy_avg_price' => $buyAvgPrice,
                'sell_price' => $sellPrice,
                'sell_fee' => $sellFee,
                'gross_proceeds' => round($grossProceeds, 2),
                'net_proceeds' => round($netProceeds, 2),
                'realized_pl' => $realizedPl,
                'realized_pl_percent' => $realizedPlPercent,
                'added_to_cash' => $addToCash,
                'notes' => $notes,
                'consumed_lots' => $consumedLots,
            ]);

            if ($addToCash) {
                $cash = $this->getOrCreateCashAccount($userId);
                $newBalance = (float) $cash->balance + $netProceeds;
                $cash->balance = $newBalance;
                $cash->save();

                InvestmentCashMutation::create([
                    'user_id' => $userId,
                    'type' => 'sell_stock',
                    'amount' => round($netProceeds, 2),
                    'balance_after' => $newBalance,
                    'description' => sprintf(
                        'Tutup Posisi %s %d Lot @ Rp %s (P/L: %sRp %s)',
                        $stock->ticker,
                        $soldLotsTarget,
                        number_format($sellPrice, 0, ',', '.'),
                        $realizedPl >= 0 ? '+' : '-',
                        number_format(abs($realizedPl), 0, ',', '.')
                    ),
                    'reference_id' => $sale->id,
                    'transacted_at' => $soldAt,
                ]);
            }

            // Sinkronisasi Bea Meterai harian (> Rp 10 Juta)
            $this->syncDailyStampDuty($userId, $soldAt);

            return $this->getPortfolio($userId, false);
        });
    }

    /**
     * Menghapus baris pembelian lot saham (dan mengembalikan saldo Cash RDN bila dulunya dipotong).
     */
    public function deleteStockLot(int $userId, int $lotId): array
    {
        return DB::connection('personal_app')->transaction(function () use ($userId, $lotId) {
            $lot = InvestmentStockLot::where('user_id', $userId)
                ->with('stock')
                ->findOrFail($lotId);

            $lotOpenedAt = $lot->opened_at?->format('Y-m-d');

            if ($lot->deducted_from_cash && $lot->remaining_lots > 0) {
                $initLots = max(1, (int) $lot->initial_lots);
                $allocatedFee = ((float) $lot->buy_fee) * ($lot->remaining_lots / $initLots);
                $refundAmount = round(($lot->remaining_lots * self::SHARES_PER_LOT * (float) $lot->buy_price) + $allocatedFee, 2);

                $cash = $this->getOrCreateCashAccount($userId);
                $newBalance = (float) $cash->balance + $refundAmount;
                $cash->balance = $newBalance;
                $cash->save();

                InvestmentCashMutation::create([
                    'user_id' => $userId,
                    'type' => 'adjustment',
                    'amount' => $refundAmount,
                    'balance_after' => $newBalance,
                    'description' => sprintf(
                        'Batal/Hapus Posisi %s (%d Lot)',
                        $lot->stock?->ticker ?? 'Saham',
                        $lot->remaining_lots
                    ),
                    'reference_id' => $lot->id,
                    'transacted_at' => Carbon::now('Asia/Jakarta')->format('Y-m-d'),
                ]);
            }

            $lot->delete();

            // Sinkronisasi Bea Meterai harian jika penghapusan lot membuat total turnover <= Rp 10 Jt
            if ($lotOpenedAt) {
                $this->syncDailyStampDuty($userId, $lotOpenedAt);
            }

            return $this->getPortfolio($userId, false);
        });
    }

    /**
     * Membatalkan / menghapus transaksi penjualan saham (Tutup Posisi),
     * mengembalikan lot saham yang telah terpotong kembali ke portofolio aktif,
     * serta menarik kembali mutasi kas hasil penjualan dari Cash RDN.
     */
    public function deleteStockSale(int $userId, int $saleId): array
    {
        return DB::connection('personal_app')->transaction(function () use ($userId, $saleId) {
            $sale = InvestmentStockSale::where('user_id', $userId)
                ->with('stock')
                ->findOrFail($saleId);

            $soldLots = (int) $sale->sold_lots;
            $soldAt = $sale->sold_at?->format('Y-m-d');
            $netProceeds = (float) $sale->net_proceeds;
            $addedToCash = (bool) $sale->added_to_cash;

            // 1. Pulihkan lot-lot saham yang dikonsumsi
            if (! empty($sale->consumed_lots) && is_array($sale->consumed_lots)) {
                foreach ($sale->consumed_lots as $item) {
                    $lot = InvestmentStockLot::where('user_id', $userId)->find($item['lot_id']);
                    if ($lot) {
                        $restoreLots = (int) $item['lots'];
                        $lot->remaining_lots = min((int) $lot->initial_lots, (int) $lot->remaining_lots + $restoreLots);
                        $lot->status = $lot->remaining_lots === (int) $lot->initial_lots ? 'open' : 'partial';
                        $lot->save();
                    }
                }
            } elseif ($sale->stock_lot_id) {
                // Jika berasal dari 1 lot tertentu (lot_id)
                $lot = InvestmentStockLot::where('user_id', $userId)->find($sale->stock_lot_id);
                if ($lot) {
                    $lot->remaining_lots = min((int) $lot->initial_lots, (int) $lot->remaining_lots + $soldLots);
                    $lot->status = $lot->remaining_lots === (int) $lot->initial_lots ? 'open' : 'partial';
                    $lot->save();
                }
            } else {
                // Fallback untuk historical sales tanpa consumed_lots (reverse FIFO)
                $remainingToRestore = $soldLots;
                $lotsToRestore = InvestmentStockLot::where('user_id', $userId)
                    ->where('stock_id', $sale->stock_id)
                    ->whereColumn('remaining_lots', '<', 'initial_lots')
                    ->where('opened_at', '<=', $soldAt)
                    ->orderByDesc('opened_at')
                    ->orderByDesc('id')
                    ->get();

                foreach ($lotsToRestore as $lot) {
                    if ($remainingToRestore <= 0) {
                        break;
                    }
                    $consumedInThisLot = (int) $lot->initial_lots - (int) $lot->remaining_lots;
                    $restore = min($consumedInThisLot, $remainingToRestore);

                    $lot->remaining_lots = (int) $lot->remaining_lots + $restore;
                    $lot->status = $lot->remaining_lots === (int) $lot->initial_lots ? 'open' : 'partial';
                    $lot->save();

                    $remainingToRestore -= $restore;
                }
            }

            // 2. Revert mutasi cash RDN hasil penjualan
            if ($addedToCash) {
                $mutation = InvestmentCashMutation::where('user_id', $userId)
                    ->where('type', 'sell_stock')
                    ->where('reference_id', $sale->id)
                    ->first();

                if ($mutation) {
                    $mutation->delete();
                } else {
                    $cash = $this->getOrCreateCashAccount($userId);
                    $newBalance = (float) $cash->balance - $netProceeds;
                    $cash->balance = $newBalance;
                    $cash->save();
                }
            }

            // 3. Hapus record penjualan
            $sale->delete();

            // 4. Sinkronisasi Bea Meterai & Recalculate Cash Ledger
            if ($soldAt) {
                $this->syncDailyStampDuty($userId, $soldAt, false);
            }
            $this->recalculateCashLedger($userId);

            return $this->getPortfolio($userId, false);
        });
    }

    /**
     * Sinkronisasi harga 1 emiten saham dari Yahoo Finance.
     */
    public function syncStockQuote(InvestmentStock $stock): bool
    {
        $quote = $this->yahooFinanceService->fetchQuote($stock->ticker);
        if (! $quote || $quote['last_price'] <= 0) {
            return false;
        }

        $stock->yahoo_symbol = $quote['yahoo_symbol'];
        if (! empty($quote['company_name'])) {
            $stock->company_name = $quote['company_name'];
        }
        $stock->last_price = $quote['last_price'];
        $stock->previous_close = $quote['previous_close'] > 0 ? $quote['previous_close'] : $quote['last_price'];
        $stock->price_updated_at = Carbon::now('Asia/Jakarta');
        $stock->save();

        return true;
    }

    /**
     * Sinkronisasi Bea Meterai harian (Rp 10.000) jika akumulasi transaksi kotor
     * beli + jual (realized) dalam 1 hari melebihi Rp 10.000.000.
     * Jika transaksi diedit/dihapus sehingga kembali <= Rp 10 Juta, fee otomatis dihapus.
     */
    public function syncDailyStampDuty(int $userId, string $date, bool $recalculateLedger = true): void
    {
        $dateFormatted = Carbon::parse($date)->format('Y-m-d');

        // Total nilai kotor pembelian saham pada tanggal ini
        $buyLots = InvestmentStockLot::where('user_id', $userId)
            ->where('opened_at', $dateFormatted)
            ->get();
        $buyTurnover = 0.0;
        foreach ($buyLots as $lot) {
            $buyTurnover += ((int) $lot->initial_lots * self::SHARES_PER_LOT * (float) $lot->buy_price);
        }

        // Total nilai kotor penjualan saham (realized) pada tanggal ini
        $sales = InvestmentStockSale::where('user_id', $userId)
            ->where('sold_at', $dateFormatted)
            ->get();
        $sellTurnover = 0.0;
        foreach ($sales as $sale) {
            $sellTurnover += ((int) $sale->sold_lots * self::SHARES_PER_LOT * (float) $sale->sell_price);
        }

        $totalTurnover = $buyTurnover + $sellTurnover;

        $existingDuty = InvestmentCashMutation::where('user_id', $userId)
            ->where('transacted_at', $dateFormatted)
            ->where('description', self::STAMP_DUTY_DESCRIPTION)
            ->first();

        if ($totalTurnover > self::STAMP_DUTY_THRESHOLD) {
            if (! $existingDuty) {
                InvestmentCashMutation::create([
                    'user_id' => $userId,
                    'type' => 'fee',
                    'amount' => -self::STAMP_DUTY_AMOUNT,
                    'balance_after' => 0,
                    'description' => self::STAMP_DUTY_DESCRIPTION,
                    'transacted_at' => $dateFormatted,
                ]);
                if ($recalculateLedger) {
                    $this->recalculateCashLedger($userId);
                }
            }
        } else {
            if ($existingDuty) {
                $existingDuty->delete();
                if ($recalculateLedger) {
                    $this->recalculateCashLedger($userId);
                }
            }
        }
    }

    /**
     * Menghitung ulang seluruh running balance (balance_after) mutasi Cash RDN
     * secara kronologis beserta total top-up, total penarikan, dan saldo akun.
     */
    public function recalculateCashLedger(int $userId): void
    {
        $mutations = InvestmentCashMutation::where('user_id', $userId)
            ->orderBy('transacted_at', 'asc')
            ->orderBy('id', 'asc')
            ->get();

        $runningBalance = 0.0;
        $totalTopup = 0.0;
        $totalWithdrawn = 0.0;

        foreach ($mutations as $m) {
            $runningBalance += (float) $m->amount;
            $m->balance_after = round($runningBalance, 2);
            $m->save();

            if ($m->type === 'topup' && (float) $m->amount > 0) {
                $totalTopup += (float) $m->amount;
            } elseif ($m->type === 'withdraw' && (float) $m->amount < 0) {
                $totalWithdrawn += abs((float) $m->amount);
            }
        }

        $cash = $this->getOrCreateCashAccount($userId);
        $cash->balance = round($runningBalance, 2);
        $cash->total_topup = round($totalTopup, 2);
        $cash->total_withdrawn = round($totalWithdrawn, 2);
        $cash->save();
    }

    /**
     * Menghitung ulang Fee materai untuk seluruh transaksi historis dan
     * merekonsiliasi mutasi saldo Cash RDN.
     */
    public function recalculateAllHistoricalStampDuty(int $userId): array
    {
        return DB::connection('personal_app')->transaction(function () use ($userId) {
            $lots = InvestmentStockLot::where('user_id', $userId)->get();
            $sales = InvestmentStockSale::where('user_id', $userId)->get();

            $dates = [];
            foreach ($lots as $lot) {
                if ($lot->opened_at) {
                    $dates[$lot->opened_at->format('Y-m-d')] = true;
                }
            }
            foreach ($sales as $sale) {
                if ($sale->sold_at) {
                    $dates[$sale->sold_at->format('Y-m-d')] = true;
                }
            }

            // Sertakan juga tanggal mutasi Fee materai yang mungkin sudah pernah tercatat
            $existingFees = InvestmentCashMutation::where('user_id', $userId)
                ->where('description', self::STAMP_DUTY_DESCRIPTION)
                ->get();
            foreach ($existingFees as $ef) {
                if ($ef->transacted_at) {
                    $dates[$ef->transacted_at->format('Y-m-d')] = true;
                }
            }

            ksort($dates);

            foreach (array_keys($dates) as $date) {
                $this->syncDailyStampDuty($userId, $date, false);
            }

            $this->recalculateCashLedger($userId);

            return $this->getPortfolio($userId, false);
        });
    }
}
