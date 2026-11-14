<?php

namespace App\Modules\PersonalApp\Services;

use Illuminate\Support\Facades\Http;

class YahooFinanceService
{
    /**
     * Daftar populer saham BEI (IDX) sebagai fallback cepat & saran instan.
     */
    private const POPULAR_IDX_STOCKS = [
        ['ticker' => 'BBCA', 'yahoo_symbol' => 'BBCA.JK', 'company_name' => 'PT Bank Central Asia Tbk'],
        ['ticker' => 'BBRI', 'yahoo_symbol' => 'BBRI.JK', 'company_name' => 'PT Bank Rakyat Indonesia (Persero) Tbk'],
        ['ticker' => 'BMRI', 'yahoo_symbol' => 'BMRI.JK', 'company_name' => 'PT Bank Mandiri (Persero) Tbk'],
        ['ticker' => 'BBNI', 'yahoo_symbol' => 'BBNI.JK', 'company_name' => 'PT Bank Negara Indonesia (Persero) Tbk'],
        ['ticker' => 'TLKM', 'yahoo_symbol' => 'TLKM.JK', 'company_name' => 'PT Telkom Indonesia (Persero) Tbk'],
        ['ticker' => 'ASII', 'yahoo_symbol' => 'ASII.JK', 'company_name' => 'PT Astra International Tbk'],
        ['ticker' => 'PSAT', 'yahoo_symbol' => 'PSAT.JK', 'company_name' => 'PT Pancaran Samudera Transport Tbk'],
        ['ticker' => 'BREN', 'yahoo_symbol' => 'BREN.JK', 'company_name' => 'PT Barito Renewables Energy Tbk'],
        ['ticker' => 'AMMN', 'yahoo_symbol' => 'AMMN.JK', 'company_name' => 'PT Amman Mineral Internasional Tbk'],
        ['ticker' => 'ADRO', 'yahoo_symbol' => 'ADRO.JK', 'company_name' => 'PT Alamtri Resources Indonesia Tbk'],
        ['ticker' => 'ANTM', 'yahoo_symbol' => 'ANTM.JK', 'company_name' => 'PT Aneka Tambang Tbk'],
        ['ticker' => 'BRIS', 'yahoo_symbol' => 'BRIS.JK', 'company_name' => 'PT Bank Syariah Indonesia Tbk'],
        ['ticker' => 'ICBP', 'yahoo_symbol' => 'ICBP.JK', 'company_name' => 'PT Indofood CBP Sukses Makmur Tbk'],
        ['ticker' => 'INDF', 'yahoo_symbol' => 'INDF.JK', 'company_name' => 'PT Indofood Sukses Makmur Tbk'],
        ['ticker' => 'UNTR', 'yahoo_symbol' => 'UNTR.JK', 'company_name' => 'PT United Tractors Tbk'],
        ['ticker' => 'ITMG', 'yahoo_symbol' => 'ITMG.JK', 'company_name' => 'PT Indo Tambangraya Megah Tbk'],
        ['ticker' => 'PTBA', 'yahoo_symbol' => 'PTBA.JK', 'company_name' => 'PT Bukit Asam Tbk'],
        ['ticker' => 'GOTO', 'yahoo_symbol' => 'GOTO.JK', 'company_name' => 'PT GoTo Gojek Tokopedia Tbk'],
        ['ticker' => 'UNVR', 'yahoo_symbol' => 'UNVR.JK', 'company_name' => 'PT Unilever Indonesia Tbk'],
        ['ticker' => 'KLBF', 'yahoo_symbol' => 'KLBF.JK', 'company_name' => 'PT Kalbe Farma Tbk'],
        ['ticker' => 'PGEO', 'yahoo_symbol' => 'PGEO.JK', 'company_name' => 'PT Pertamina Geothermal Energy Tbk'],
        ['ticker' => 'MDKA', 'yahoo_symbol' => 'MDKA.JK', 'company_name' => 'PT Merdeka Copper Gold Tbk'],
    ];

    /**
     * Normalisasi input kode saham menjadi pasangan [ticker, yahoo_symbol].
     * Contoh: 'bbca' -> ['ticker' => 'BBCA', 'yahoo_symbol' => 'BBCA.JK']
     */
    public function normalizeSymbol(string $rawTicker): array
    {
        $clean = strtoupper(trim($rawTicker));
        $clean = preg_replace('/[^A-Z0-9.\-]/', '', $clean) ?: 'UNKNOWN';

        if (str_ends_with($clean, '.JK')) {
            $ticker = substr($clean, 0, -3);
            $yahooSymbol = $clean;
        } elseif (str_contains($clean, '.')) {
            $parts = explode('.', $clean, 2);
            $ticker = $parts[0];
            $yahooSymbol = $clean;
        } else {
            $ticker = $clean;
            $yahooSymbol = $clean . '.JK';
        }

        return [
            'ticker' => $ticker,
            'yahoo_symbol' => $yahooSymbol,
        ];
    }

    /**
     * Mengambil harga terakhir (last_price), previous_close, dan nama perusahaan dari Yahoo Finance.
     */
    public function fetchQuote(string $rawTicker): ?array
    {
        $norm = $this->normalizeSymbol($rawTicker);
        $yahooSymbol = $norm['yahoo_symbol'];
        $ticker = $norm['ticker'];

        try {
            $response = Http::withoutVerifying()
                ->timeout(6)
                ->withHeaders([
                    'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/125.0.0.0 Safari/537.36',
                    'Accept' => 'application/json',
                ])
                ->get("https://query1.finance.yahoo.com/v8/finance/chart/{$yahooSymbol}", [
                    'interval' => '1d',
                    'range' => '2d',
                ]);

            if (! $response->successful()) {
                return null;
            }

            $json = $response->json();
            $meta = $json['chart']['result'][0]['meta'] ?? null;

            if (! $meta || ! isset($meta['regularMarketPrice'])) {
                return null;
            }

            $lastPrice = (float) ($meta['regularMarketPrice'] ?? 0);
            $prevClose = (float) ($meta['chartPreviousClose'] ?? $meta['previousClose'] ?? $lastPrice);
            $companyName = $meta['longName'] ?? $meta['shortName'] ?? $this->findDefaultName($ticker);

            return [
                'ticker' => $ticker,
                'yahoo_symbol' => $yahooSymbol,
                'company_name' => $companyName,
                'last_price' => $lastPrice,
                'previous_close' => $prevClose > 0 ? $prevClose : $lastPrice,
                'currency' => $meta['currency'] ?? 'IDR',
            ];
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Mencari kode saham BEI / IDX dari Yahoo Finance beserta daftar populer lokal.
     */
    public function searchTickers(string $query = ''): array
    {
        $q = strtoupper(trim($query));
        $results = [];
        $seen = [];

        // 1. Filter dari daftar populer lokal terlebih dahulu
        foreach (self::POPULAR_IDX_STOCKS as $stock) {
            if (
                $q === '' ||
                str_contains($stock['ticker'], $q) ||
                str_contains(strtoupper($stock['company_name']), $q)
            ) {
                $results[] = $stock;
                $seen[$stock['ticker']] = true;
            }
        }

        if ($q === '') {
            return array_slice($results, 0, 12);
        }

        // 2. Cari ke Yahoo Finance Search API dengan prioritas .JK (Jakarta Exchange)
        try {
            $searchQuery = str_ends_with($q, '.JK') ? $q : "{$q}.JK";
            $response = Http::withoutVerifying()
                ->timeout(5)
                ->withHeaders([
                    'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64)',
                    'Accept' => 'application/json',
                ])
                ->get('https://query1.finance.yahoo.com/v1/finance/search', [
                    'q' => $searchQuery,
                    'quotesCount' => 10,
                    'newsCount' => 0,
                ]);

            if ($response->successful()) {
                $quotes = $response->json('quotes') ?? [];
                foreach ($quotes as $item) {
                    $symbol = (string) ($item['symbol'] ?? '');
                    if ($symbol === '') {
                        continue;
                    }
                    $isIdx = str_ends_with($symbol, '.JK') || ($item['exchange'] ?? '') === 'JKT';
                    if (! $isIdx) {
                        continue;
                    }

                    $norm = $this->normalizeSymbol($symbol);
                    if (isset($seen[$norm['ticker']])) {
                        continue;
                    }

                    $seen[$norm['ticker']] = true;
                    $results[] = [
                        'ticker' => $norm['ticker'],
                        'yahoo_symbol' => $norm['yahoo_symbol'],
                        'company_name' => $item['longname'] ?? $item['shortname'] ?? $norm['ticker'],
                    ];
                }
            }
        } catch (\Throwable) {
            // Fallback ke hasil lokal bila offline
        }

        // 3. Jika ketikan 4 huruf kapital belum ada di hasil, sertakan sebagai opsi langsung
        if (preg_match('/^[A-Z]{4}$/', $q) && ! isset($seen[$q])) {
            array_unshift($results, [
                'ticker' => $q,
                'yahoo_symbol' => "{$q}.JK",
                'company_name' => "Saham BEI ({$q}.JK)",
            ]);
        }

        return array_slice($results, 0, 12);
    }

    public function findDefaultName(string $ticker): string
    {
        foreach (self::POPULAR_IDX_STOCKS as $item) {
            if ($item['ticker'] === strtoupper($ticker)) {
                return $item['company_name'];
            }
        }

        return strtoupper($ticker);
    }
}
