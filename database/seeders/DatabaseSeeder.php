<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    private const OPERATORS = [
        ['key' => 'telkomsel', 'label' => 'Telkomsel', 'markup' => 1500, 'data_offset' => 5000],
        ['key' => 'indosat', 'label' => 'Indosat', 'markup' => 1200, 'data_offset' => 4000],
        ['key' => 'xl', 'label' => 'XL', 'markup' => 1200, 'data_offset' => 3000],
        ['key' => 'axis', 'label' => 'Axis', 'markup' => 1300, 'data_offset' => 2000],
        ['key' => 'tri', 'label' => 'Tri', 'markup' => 1400, 'data_offset' => 1000],
        ['key' => 'smartfren', 'label' => 'Smartfren', 'markup' => 1400, 'data_offset' => 0],
    ];

    private const PULSA_NOMINALS = [5000, 10000, 20000, 25000, 50000, 100000];

    private const DATA_PACKAGES = [
        ['2 GB / 30 hari', 25000],
        ['5 GB / 30 hari', 45000],
        ['10 GB / 30 hari', 75000],
        ['25 GB / 30 hari', 125000],
    ];

    private const PLN_TOKEN_NOMINALS = [20000, 50000, 100000, 200000, 500000];

    private const EWALLETS = ['GoPay', 'OVO', 'DANA', 'ShopeePay'];

    private const EWALLET_NOMINALS = [10000, 25000, 50000, 100000];

    private const GAMES = [
        'Mobile Legends' => [['86 Diamonds', 22000], ['172 Diamonds', 44000], ['257 Diamonds', 65000], ['706 Diamonds', 175000]],
        'Free Fire' => [['70 Diamonds', 10000], ['140 Diamonds', 19000], ['355 Diamonds', 47000]],
        'PUBG Mobile' => [['60 UC', 15000], ['325 UC', 75000]],
    ];

    private const VOUCHERS = [
        ['Google Play', 'Voucher Google Play Rp50.000', 52000],
        ['Steam', 'Voucher Steam Wallet Rp60.000', 62000],
        ['Netflix', 'Voucher Netflix 1 Bulan', 54000],
        ['Spotify', 'Voucher Spotify Premium 1 Bulan', 55000],
    ];

    public function run(): void
    {
        $demoId = $this->seedUsers();
        $this->seedProducts();
        $this->seedDemoHistory($demoId);
    }

    private function seedUsers(): int
    {
        $accounts = [
            ['name' => 'Budi Santoso', 'email' => 'demo@sesmartpulsa.id', 'phone' => '081234567890', 'password' => 'demo12345', 'role' => 'user'],
            ['name' => 'Admin SE SMART', 'email' => 'admin@sesmartpulsa.id', 'phone' => '081200000000', 'password' => 'admin12345', 'role' => 'admin'],
        ];

        foreach ($accounts as $account) {
            $exists = DB::table('users')->where('email', $account['email'])->exists();

            if (! $exists) {
                DB::table('users')->insert([
                    'name' => $account['name'],
                    'email' => $account['email'],
                    'phone' => $account['phone'],
                    'password_hash' => Hash::make($account['password']),
                    'role' => $account['role'],
                    'balance' => 0,
                    'email_verified_at' => now(),
                    'phone_verified_at' => now(),
                    'created_at' => now(),
                ]);
            }
        }

        // Akun demo & admin dianggap sudah terverifikasi supaya tetap bisa masuk
        // tanpa OTP setelah verifikasi diaktifkan.
        DB::table('users')
            ->whereIn('email', array_column($accounts, 'email'))
            ->whereNull('email_verified_at')
            ->update(['email_verified_at' => now(), 'phone_verified_at' => now()]);

        return (int) DB::table('users')->where('email', 'demo@sesmartpulsa.id')->value('id');
    }

    private function seedProducts(): void
    {
        foreach ($this->buildProducts() as $product) {
            $product['retail_price'] = $this->retailPrice((int) $product['price']);

            DB::table('products')->updateOrInsert(
                ['sku' => $product['sku']],
                $product + ['active' => true, 'is_demo' => true],
            );
        }
    }

    /**
     * Harga publik untuk pembayaran langsung via Midtrans: 5% di atas harga
     * saldo/reseller, dibulatkan ke atas per Rp100.
     */
    private function retailPrice(int $price): int
    {
        return (int) (ceil($price * 1.05 / 100) * 100);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function buildProducts(): array
    {
        $products = [];
        $push = function (array $product) use (&$products): void {
            $products[] = $product;
        };

        foreach (self::OPERATORS as $operator) {
            foreach (self::PULSA_NOMINALS as $index => $nominal) {
                $push([
                    'sku' => 'PULSA-'.strtoupper($operator['key']).'-'.$nominal,
                    'category' => 'pulsa',
                    'operator' => $operator['label'],
                    'name' => 'Pulsa '.$this->thousands($nominal),
                    'price' => $nominal + $operator['markup'],
                    'sort_order' => $index,
                ]);
            }

            foreach (self::DATA_PACKAGES as $index => [$label, $price]) {
                $push([
                    'sku' => 'DATA-'.strtoupper($operator['key']).'-'.($index + 1),
                    'category' => 'data',
                    'operator' => $operator['label'],
                    'name' => $label,
                    'price' => $price + $operator['data_offset'],
                    'sort_order' => $index,
                ]);
            }
        }

        foreach (self::PLN_TOKEN_NOMINALS as $index => $nominal) {
            $push([
                'sku' => 'PLN-TOKEN-'.$nominal,
                'category' => 'pln_token',
                'operator' => 'PLN',
                'name' => 'Token Listrik '.$this->thousands($nominal),
                'price' => $nominal + 1600,
                'sort_order' => $index,
            ]);
        }

        $push([
            'sku' => 'PLN-BILL-ADMIN',
            'category' => 'pln_bill',
            'operator' => 'PLN',
            'name' => 'Tagihan Listrik PLN (biaya admin)',
            'price' => 2500,
            'sort_order' => 0,
        ]);

        $ewalletIndex = 0;
        foreach (self::EWALLETS as $wallet) {
            foreach (self::EWALLET_NOMINALS as $nominal) {
                $push([
                    'sku' => 'EWALLET-'.strtoupper($wallet).'-'.$nominal,
                    'category' => 'ewallet',
                    'operator' => $wallet,
                    'name' => 'Saldo '.$wallet.' '.$this->thousands($nominal),
                    'price' => $nominal + 2000,
                    'sort_order' => $ewalletIndex++,
                ]);
            }
        }

        foreach (self::GAMES as $game => $items) {
            foreach (array_values($items) as $index => [$name, $price]) {
                $push([
                    'sku' => 'GAME-'.strtoupper(preg_replace('/\s+/', '', $game)).'-'.($index + 1),
                    'category' => 'game',
                    'operator' => $game,
                    'name' => $name,
                    'price' => $price,
                    'sort_order' => $index,
                ]);
            }
        }

        foreach (self::VOUCHERS as $index => [$operator, $name, $price]) {
            $push([
                'sku' => 'VOUCHER-'.strtoupper(preg_replace('/\s+/', '', $operator)),
                'category' => 'voucher',
                'operator' => $operator,
                'name' => $name,
                'price' => $price,
                'sort_order' => $index,
            ]);
        }

        return $products;
    }

    private function seedDemoHistory(int $userId): void
    {
        if ($userId === 0) {
            return;
        }

        if (DB::table('transactions')->where('user_id', $userId)->exists()) {
            return;
        }

        $balance = 0;

        $move = function (int $userId, string $direction, int $amount, string $reference, string $description, int $daysAgo) use (&$balance): void {
            $balance += $direction === 'credit' ? $amount : -$amount;

            DB::update('UPDATE users SET balance = balance + ? WHERE id = ?', [
                $direction === 'credit' ? $amount : -$amount,
                $userId,
            ]);

            DB::table('balance_entries')->insert([
                'user_id' => $userId,
                'direction' => $direction,
                'amount' => $amount,
                'balance_after' => $balance,
                'reference' => $reference,
                'description' => $description,
                'created_at' => now()->subDays($daysAgo),
            ]);
        };

        $addDeposit = function (string $orderNumber, int $amount, string $status, int $daysAgo) use ($userId, $move): void {
            DB::table('deposits')->insert([
                'user_id' => $userId,
                'order_number' => $orderNumber,
                'amount' => $amount,
                'status' => $status,
                'created_at' => now()->subDays($daysAgo),
                'paid_at' => $status === 'paid' ? now()->subDays($daysAgo)->addMinutes(3) : null,
            ]);

            if ($status === 'paid') {
                $move($userId, 'credit', $amount, $orderNumber, 'Deposit saldo berhasil', $daysAgo);
            }
        };

        $addTransaction = function (string $sku, string $orderNumber, string $customerNumber, string $status, int $daysAgo) use ($userId, $move): void {
            $product = DB::table('products')->where('sku', $sku)->first(['id', 'name', 'price']);

            if (! $product) {
                return;
            }

            DB::table('transactions')->insert([
                'user_id' => $userId,
                'product_id' => $product->id,
                'order_number' => $orderNumber,
                'customer_number' => $customerNumber,
                'amount' => $product->price,
                'status' => $status,
                'created_at' => now()->subDays($daysAgo),
            ]);

            if ($status === 'success') {
                $move($userId, 'debit', (int) $product->price, $orderNumber, $product->name, $daysAgo);
            }
        };

        $addDeposit('DEP-20261019-0001', 100000, 'paid', 8);
        $addDeposit('DEP-20261020-0007', 20000, 'expired', 7);
        $addTransaction('PULSA-TELKOMSEL-10000', 'TRX-20261022-1042', '081234567890', 'success', 5);
        $addTransaction('GAME-MOBILELEGENDS-1', 'TRX-20261023-2210', '123456789', 'failed', 4);
        $addTransaction('PLN-TOKEN-50000', 'TRX-20261024-0815', '51234567890', 'success', 3);
        $addDeposit('DEP-20261025-0042', 150000, 'paid', 2);
        $addTransaction('DATA-XL-2', 'TRX-20261026-1501', '081298765432', 'pending', 1);
        $addDeposit('DEP-20261027-0011', 50000, 'pending', 0);
    }

    private function thousands(int $value): string
    {
        return number_format($value, 0, ',', '.');
    }
}
