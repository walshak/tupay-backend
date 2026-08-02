<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('ledger_entries', function (Blueprint $table) {
            $table->id();
            $table->uuid('transaction_reference')->index(); //this will link the legs of tx
            $table->foreignId('wallet_id')->constrained()->cascadeOnDelete(); //the account affected
            $table->enum('type', ['credit', 'debit']);
            $table->unsignedBigInteger('amount'); //kobo or fen
            $table->string('description')->nullable();
            $table->timestamps();

            //constraints
            $table->index(['wallet_id', 'created_at']); //for efficient pagination and balance calculation


            //a mysql trigger to prevent overdraft
            DB::unprepared("
            CREATE TRIGGER prevent_wallet_overdraft
                BEFORE INSERT ON ledger_entries
                FOR EACH ROW
                BEGIN
                    DECLARE current_balance BIGINT DEFAULT 0;
                    
                    -- Only check if we are debiting (subtracting) money
                    IF NEW.type = 'debit' THEN
                        SELECT COALESCE(
                            SUM(CASE WHEN type = 'credit' THEN amount ELSE -amount END), 0
                        ) INTO current_balance
                        FROM ledger_entries
                        WHERE wallet_id = NEW.wallet_id;
                        
                        -- If the debit amount exceeds current balance, abort the insert
                        IF (current_balance - NEW.amount) < 0 THEN
                            SIGNAL SQLSTATE '45000' 
                            SET MESSAGE_TEXT = 'Insufficient funds: Wallet balance cannot drop below zero.';
                        END IF;
                    END IF;
                END;
            ");
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::unprepared("DROP TRIGGER IF EXISTS prevent_wallet_overdraft");
        Schema::dropIfExists('ledger_entries');
    }
};
