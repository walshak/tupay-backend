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
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ledger_entries');
    }
};
