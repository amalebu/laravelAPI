<?php

namespace Tests\Feature;

use App\Modules\PersonalApp\Database\Seeders\PersonalAppDatabaseSeeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PersonalAppInvestmentTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Artisan::call('migrate:fresh', [
            '--database' => 'personal_app',
            '--path' => 'app/Modules/PersonalApp/Database/Migrations',
            '--force' => true,
        ]);

        // Fake Yahoo Finance API responses so tests are fast and deterministic
        Http::fake([
            'query1.finance.yahoo.com/v8/finance/chart/BBCA.JK*' => Http::response([
                'chart' => [
                    'result' => [
                        [
                            'meta' => [
                                'symbol' => 'BBCA.JK',
                                'longName' => 'PT Bank Central Asia Tbk',
                                'regularMarketPrice' => 5500,
                                'chartPreviousClose' => 5400,
                            ],
                        ],
                    ],
                ],
            ], 200),
        ]);
    }

    private function authenticateUser(string $phone = '81277778888', string $pin = '123456'): string
    {
        $res = $this->postJson('/api/v1/personal-app/auth/phone/pin', [
            'phone' => $phone,
            'pin' => $pin,
            'pin_confirmation' => $pin,
        ]);

        $res->assertOk();

        return $res->json('data.token');
    }

    public function test_investment_portfolio_cash_rdn_average_price_per_lot_and_close_position(): void
    {
        $token = $this->authenticateUser();

        // 1. Top-Up Cash RDN Rp 10.000.000
        $cashRes = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/personal-app/investments/cash', [
                'type' => 'topup',
                'amount' => 10000000,
                'description' => 'Setoran modal awal RDN',
                'transacted_at' => '2026-11-01',
            ]);

        $cashRes->assertOk()
            ->assertJsonPath('data.summary.cash_balance', 10000000);

        // 2. Beli Pertama: BBCA 5 lot x Rp 5.000 (1 lot = 100 lembar -> Rp 2.500.000)
        $buy1 = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/personal-app/investments/lots', [
                'ticker' => 'BBCA',
                'company_name' => 'PT Bank Central Asia Tbk',
                'lots' => 5,
                'buy_price' => 5000,
                'buy_fee' => 0,
                'opened_at' => '2026-11-02',
                'deduct_from_cash' => true,
            ]);

        $buy1->assertCreated()
            ->assertJsonPath('data.summary.cash_balance', 7500000)
            ->assertJsonPath('data.stocks.0.ticker', 'BBCA')
            ->assertJsonPath('data.stocks.0.total_lots', 5)
            ->assertJsonPath('data.stocks.0.total_shares', 500)
            ->assertJsonPath('data.stocks.0.avg_buy_price', 5000);

        // 3. Beli Kedua: BBCA 5 lot x Rp 5.200 (Rp 2.600.000)
        // Ekspektasi sesuai request user: BBCA 10 lot x 5.100 (avg harga), dengan 2 rincian lot pembelian
        $buy2 = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/personal-app/investments/lots', [
                'ticker' => 'BBCA',
                'company_name' => 'PT Bank Central Asia Tbk',
                'lots' => 5,
                'buy_price' => 5200,
                'buy_fee' => 0,
                'opened_at' => '2026-11-05',
                'deduct_from_cash' => true,
            ]);

        $buy2->assertCreated()
            ->assertJsonPath('data.summary.cash_balance', 4900000)
            ->assertJsonPath('data.stocks.0.ticker', 'BBCA')
            ->assertJsonPath('data.stocks.0.total_lots', 10)
            ->assertJsonPath('data.stocks.0.total_shares', 1000)
            ->assertJsonPath('data.stocks.0.avg_buy_price', 5100)
            ->assertJsonPath('data.stocks.0.total_cost', 5100000)
            ->assertJsonPath('data.stocks.0.last_price', 5500)
            ->assertJsonPath('data.stocks.0.market_value', 5500000)
            ->assertJsonPath('data.stocks.0.net_pl', 400000)
            ->assertJsonPath('data.stocks.0.lots_count', 2);

        $lots = $buy2->json('data.stocks.0.lots');
        $this->assertCount(2, $lots);

        // Ambil lot pertama (yang dibuka tanggal 2026-11-02 dengan harga 5000)
        $lot5000 = collect($lots)->firstWhere('buy_price', 5000);
        $this->assertNotNull($lot5000);
        $this->assertEquals(5, $lot5000['remaining_lots']);

        // 4. Tutup Posisi sebagian (jual 2 lot dari lot 5000 di harga jual 5.600)
        // Hasil bersih = 2 * 100 * 5600 = Rp 1.120.000
        // Modal pokok 2 lot = 2 * 100 * 5000 = Rp 1.000.000 -> Realized P/L = +Rp 120.000
        $closeRes = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/personal-app/investments/positions/close', [
                'stock_id' => $buy2->json('data.stocks.0.id'),
                'lot_id' => $lot5000['id'],
                'sold_lots' => 2,
                'sell_price' => 5600,
                'sell_fee' => 0,
                'sold_at' => '2026-11-05',
                'add_to_cash' => true,
            ]);

        $closeRes->assertOk()
            ->assertJsonPath('data.summary.cash_balance', 6020000) // 4.900.000 + 1.120.000
            ->assertJsonPath('data.summary.realized_pl', 120000)
            ->assertJsonPath('data.stocks.0.total_lots', 8)
            ->assertJsonPath('data.sales.0.sold_lots', 2)
            ->assertJsonPath('data.sales.0.realized_pl', 120000);

        // 5. Edit Posisi Lot (update sisa 3 lot di lot pertama menjadi 4 lot dan buy_price 4900)
        $editRes = $this->withHeader('Authorization', "Bearer {$token}")
            ->putJson("/api/v1/personal-app/investments/lots/{$lot5000['id']}", [
                'opened_at' => '2026-11-03',
                'lots' => 4,
                'buy_price' => 4900,
                'buy_fee' => 5000,
            ]);

        $editRes->assertOk()
            ->assertJsonPath('data.stocks.0.total_lots', 9); // 4 lot + 5 lot

        // 6. Test Search Ticker & Quote endpoint
        $searchRes = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/v1/personal-app/investments/tickers/search?q=BBCA');
        $searchRes->assertOk();

        $quoteRes = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/v1/personal-app/investments/tickers/quote?ticker=BBCA');
        $quoteRes->assertOk()
            ->assertJsonPath('data.ticker', 'BBCA')
            ->assertJsonPath('data.last_price', 5500);
    }

    public function test_default_buy_and_sell_fees_calculation_and_manual_override(): void
    {
        $token = $this->authenticateUser('81299990000');

        // Top up Cash RDN
        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/personal-app/investments/cash', [
                'type' => 'topup',
                'amount' => 50000000,
            ]);

        // 1. Buy without specifying buy_fee -> auto defaults to 0.1513%
        // Gross: 10 lot * 100 * 10.000 = Rp 10.000.000
        // Expected Fee: 10.000.000 * 0.001513 = Rp 15.130
        $buyDefault = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/personal-app/investments/lots', [
                'ticker' => 'BBCA',
                'company_name' => 'PT Bank Central Asia Tbk',
                'lots' => 10,
                'buy_price' => 10000,
                'deduct_from_cash' => true,
            ]);

        $buyDefault->assertCreated()
            ->assertJsonPath('data.stocks.0.lots.0.buy_fee', 15130)
            ->assertJsonPath('data.summary.cash_balance', 50000000 - 10000000 - 15130);

        $stockId = $buyDefault->json('data.stocks.0.id');
        $lotId = $buyDefault->json('data.stocks.0.lots.0.id');

        // 2. Close position without specifying sell_fee -> auto defaults to 0.2513%
        // Gross sell: 5 lot * 100 * 12.000 = Rp 6.000.000
        // Expected Sell Fee: 6.000.000 * 0.002513 = Rp 15.078
        $closeDefault = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/personal-app/investments/positions/close', [
                'stock_id' => $stockId,
                'lot_id' => $lotId,
                'sold_lots' => 5,
                'sell_price' => 12000,
                'add_to_cash' => true,
            ]);

        $closeDefault->assertOk()
            ->assertJsonPath('data.sales.0.sell_fee', 15078)
            ->assertJsonPath('data.sales.0.net_proceeds', 6000000 - 15078);
    }

    public function test_investment_cash_rdn_supports_negative_balance(): void
    {
        $token = $this->authenticateUser();

        // 1. Initial cash is 0, withdraw Rp 1.000.000 -> cash balance becomes -1.000.000
        $withdrawRes = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/personal-app/investments/cash', [
                'type' => 'withdraw',
                'amount' => 1000000,
                'description' => 'Penarikan melebihi saldo kas',
            ]);

        $withdrawRes->assertOk()
            ->assertJsonPath('data.summary.cash_balance', -1000000);

        // 2. Buy lot with deduct_from_cash = true while cash is already -1.000.000
        // Purchase cost = 5 lot * 100 * 5000 = 2.500.000 + buy_fee (0)
        // Cash balance should become -3.500.000
        $buyRes = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/personal-app/investments/lots', [
                'ticker' => 'BBCA',
                'company_name' => 'PT Bank Central Asia Tbk',
                'lots' => 5,
                'buy_price' => 5000,
                'buy_fee' => 0,
                'deduct_from_cash' => true,
            ]);

        $buyRes->assertCreated()
            ->assertJsonPath('data.summary.cash_balance', -3500000);

        // 3. Set adjustment to -5.000.000
        $adjustRes = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/personal-app/investments/cash', [
                'type' => 'adjustment',
                'amount' => -5000000,
                'description' => 'Penyesuaian saldo ke defisit',
            ]);

        $adjustRes->assertOk()
            ->assertJsonPath('data.summary.cash_balance', -5000000);
    }
}
