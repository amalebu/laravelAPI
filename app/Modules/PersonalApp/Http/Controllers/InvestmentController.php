<?php

namespace App\Modules\PersonalApp\Http\Controllers;

use App\Core\Http\Responses\ApiResponse;
use App\Modules\PersonalApp\Http\Requests\Investment\AdjustCashRequest;
use App\Modules\PersonalApp\Http\Requests\Investment\BuyStockLotRequest;
use App\Modules\PersonalApp\Http\Requests\Investment\ClosePositionRequest;
use App\Modules\PersonalApp\Http\Requests\Investment\UpdateStockLotRequest;
use App\Modules\PersonalApp\Services\InvestmentService;
use App\Modules\PersonalApp\Services\YahooFinanceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class InvestmentController
{
    public function __construct(
        private readonly InvestmentService $investmentService,
        private readonly YahooFinanceService $yahooFinanceService
    ) {}

    public function index(Request $request): JsonResponse
    {
        $forceRefresh = filter_var($request->query('refresh', false), FILTER_VALIDATE_BOOLEAN);
        $portfolio = $this->investmentService->getPortfolio($request->user()->id, $forceRefresh);

        return ApiResponse::success($portfolio, 'Data portofolio investasi berhasil dimuat.');
    }

    public function refreshPrices(Request $request): JsonResponse
    {
        $portfolio = $this->investmentService->getPortfolio($request->user()->id, true);

        return ApiResponse::success($portfolio, 'Harga pasar saham berhasil diperbarui dari Yahoo Finance.');
    }

    public function searchTickers(Request $request): JsonResponse
    {
        $q = (string) $request->query('q', '');
        $tickers = $this->yahooFinanceService->searchTickers($q);

        return ApiResponse::success($tickers, 'Daftar kode saham berhasil diambil.');
    }

    public function quoteTicker(Request $request): JsonResponse
    {
        $ticker = (string) $request->query('ticker', '');
        if (trim($ticker) === '') {
            return ApiResponse::error('Parameter ticker wajib diisi.', 422);
        }

        $quote = $this->yahooFinanceService->fetchQuote($ticker);
        if (! $quote) {
            $norm = $this->yahooFinanceService->normalizeSymbol($ticker);

            return ApiResponse::success([
                'ticker' => $norm['ticker'],
                'yahoo_symbol' => $norm['yahoo_symbol'],
                'company_name' => $this->yahooFinanceService->findDefaultName($norm['ticker']),
                'last_price' => 0,
                'previous_close' => 0,
                'currency' => 'IDR',
            ], 'Quote menggunakan fallback lokal.');
        }

        return ApiResponse::success($quote, 'Harga saham berhasil diambil dari Yahoo Finance.');
    }

    public function adjustCash(AdjustCashRequest $request): JsonResponse
    {
        $portfolio = $this->investmentService->adjustCash($request->user()->id, $request->validated());

        return ApiResponse::success($portfolio, 'Saldo Cash RDN berhasil diperbarui.');
    }

    public function buyLot(BuyStockLotRequest $request): JsonResponse
    {
        $portfolio = $this->investmentService->buyStockLot($request->user()->id, $request->validated());

        return ApiResponse::created($portfolio, 'Pembelian saham berhasil dicatat ke portofolio.');
    }

    public function updateLot(UpdateStockLotRequest $request, int $lotId): JsonResponse
    {
        $portfolio = $this->investmentService->updateStockLot($request->user()->id, $lotId, $request->validated());

        return ApiResponse::success($portfolio, 'Posisi pembelian saham berhasil diperbarui.');
    }

    public function closePosition(ClosePositionRequest $request): JsonResponse
    {
        $portfolio = $this->investmentService->closeStockPosition($request->user()->id, $request->validated());

        return ApiResponse::success($portfolio, 'Posisi saham berhasil ditutup.');
    }

    public function destroyLot(Request $request, int $lotId): JsonResponse
    {
        $portfolio = $this->investmentService->deleteStockLot($request->user()->id, $lotId);

        return ApiResponse::success($portfolio, 'Baris pembelian saham berhasil dihapus.');
    }

    public function destroySale(Request $request, int $saleId): JsonResponse
    {
        $portfolio = $this->investmentService->deleteStockSale($request->user()->id, $saleId);

        return ApiResponse::success($portfolio, 'Penjualan berhasil dibatalkan dan posisi saham telah dipulihkan.');
    }

    public function recalculateFees(Request $request): JsonResponse
    {
        $portfolio = $this->investmentService->recalculateAllHistoricalStampDuty($request->user()->id);

        return ApiResponse::success($portfolio, 'Perhitungan ulang Fee materai harian (> Rp 10 Jt) berhasil diperbarui.');
    }
}
