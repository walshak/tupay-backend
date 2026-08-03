<?php

namespace Database\Seeders;

use App\Models\User;
use App\Models\Wallet;
use App\Models\LedgerEntry;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use PragmaRX\Google2FA\Google2FA;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $google2fa = new Google2FA();

        // ---------------------------------------------------------
        // 1. THE SYSTEM MASTER ACCOUNT
        // ---------------------------------------------------------
        // We need a "Bank" user to act as the counter-party for deposits and fees.
        $systemUser = User::firstOrCreate(
            ['email' => 'system@tupay.com'],
            [
                'name' => 'Tupay System Master',
                'password' => Hash::make(Str::random(32)),
                'totp_secret' => null,
            ]
        );

        $systemWalletNgn = Wallet::firstOrCreate([
            'user_id' => $systemUser->id,
            'currency' => 'NGN'
        ]);

        $systemWalletCny = Wallet::firstOrCreate([
            'user_id' => $systemUser->id,
            'currency' => 'CNY'
        ]);

        //---------------------------------------------------------
        //2.FUND SYSTEM WALLETS
        //---------------------------------------------------------
        $initialDepositAmount = 2_000_000_000; // 2 Billion kobo

        // Check if we already funded them so we don't accidentally double-fund on re-seeds
        if (!LedgerEntry::where('transaction_reference', 'seed-funding-NGN-' . $systemUser->id)->exists()) {

            // Note: Since the system wallet starts at 0, debiting it will throw our 
            // overdraft exception unless we fund the system first, 
            // and we are doing so here with this initial deposit

            LedgerEntry::create([
                'transaction_reference' => 'seed-funding-' . $systemUser->id,
                'wallet_id' => $systemWalletNgn->id,
                'type' => 'credit',
                'amount' => $initialDepositAmount,
                'description' => 'Initial Test Capital Injection'
            ]);


            $this->command->info('Successfully pre-funded NGN wallet with 2,000,000,000 kobo.');
        }

        if (!LedgerEntry::where('transaction_reference', 'seed-funding-CNY-' . $systemUser->id)->exists()) {

            //seed system cny wallet too
            LedgerEntry::create([
                'transaction_reference' => 'seed-funding-CNY-' . $systemUser->id,
                'wallet_id' => $systemWalletCny->id,
                'type' => 'credit',
                'amount' => $initialDepositAmount,
                'description' => 'Initial Test Capital Injection'
            ]);

            $this->command->info('Successfully pre-funded CNY wallet with 2,000,000,000 fen.');
        }

        // 3. MY TEST USER
        $testUserTotpSecret = $google2fa->generateSecretKey();

        $testUser = User::firstOrCreate(
            ['email' => 'walshak1999@gmail.com'],
            [
                'name' => 'Walshak',
                'password' => Hash::make('12345678'),
                'totp_secret' => $testUserTotpSecret,
            ]
        );

        // I print the TOTP Secret to the terminal so that i can add it to Google Auth App
        $this->command->info('--------------------------------------------------');
        $this->command->info('Test User Created: walshak1999@gmail.com');
        $this->command->info('Password:          12345678');
        $this->command->info('TOTP Secret:       ' . $testUserTotpSecret);
        $this->command->info('--------------------------------------------------');

        // Create Wallets for my Test User
        $testWalletNgn = Wallet::firstOrCreate([
            'user_id' => $testUser->id,
            'currency' => 'NGN'
        ]);

        $testWalletCny = Wallet::firstOrCreate([
            'user_id' => $testUser->id,
            'currency' => 'CNY'
        ]);

        // ---------------------------------------------------------
        // 3. PRE-FUND THE TEST USER'S NGN WALLET
        // ---------------------------------------------------------
        // Let's give my test user 5,000,000 NGN (which is 500,000,000 kobo).
        // To make sure there is enough liquidity for the swap engine.
        // This is done via strict double-entry

        $initialDepositAmount = 500_000_000; // 500 million kobo

        // Check if we already funded them so we don't accidentally double-fund on re-seeds
        if (!LedgerEntry::where('transaction_reference', 'seed-funding-NGN-' . $testUser->id)->exists()) {

            //debit system's NGN wallet with the amount of money 
            LedgerEntry::create([
                'transaction_reference' => 'seed-funding-user-NGN-' . $testUser->id,
                'wallet_id' => $systemWalletNgn->id,
                'type' => 'debit',
                'amount' => $initialDepositAmount,
                'description' => 'Initial Test Capital Injection'
            ]);

            //credit test user's NGN wallet with the amount of money
            LedgerEntry::create([
                'transaction_reference' => 'seed-funding-user-NGN-' . $testUser->id,
                'wallet_id' => $testWalletNgn->id,
                'type' => 'credit',
                'amount' => $initialDepositAmount,
                'description' => 'Initial Test Capital Injection'
            ]);

            $this->command->info('Successfully pre-funded NGN wallet with 5,000,000 NGN.');
        }

        //fund user CNY wallet too
        if (!LedgerEntry::where('transaction_reference', 'seed-funding-user-CNY-' . $testUser->id)->exists()) {

            //debit system's CNY wallet with the amount of money 
            LedgerEntry::create([
                'transaction_reference' => 'seed-funding-user-CNY-' . $testUser->id,
                'wallet_id' => $systemWalletCny->id,
                'type' => 'debit',
                'amount' => $initialDepositAmount,
                'description' => 'Initial Test Capital Injection'
            ]);

            //credit test user's CNY wallet with the amount of money
            LedgerEntry::create([
                'transaction_reference' => 'seed-funding-user-CNY-' . $testUser->id,
                'wallet_id' => $testWalletCny->id,
                'type' => 'credit',
                'amount' => $initialDepositAmount,
                'description' => 'Initial Test Capital Injection'
            ]);

            $this->command->info('Successfully pre-funded CNY wallet with 5,000,000 CNY.');
        }
    }
}
