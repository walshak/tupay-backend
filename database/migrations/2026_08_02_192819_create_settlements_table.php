<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('settlements', function (Blueprint $table) {
            $table->id();
            // The unique ID from the third party (SQL Unique Constraint for idempotency)
            $table->string('provider_reference')->unique();
            $table->foreignId('wallet_id')->constrained();

            $table->unsignedBigInteger('amount');
            $table->enum('status', ['INITIATED', 'COMPLETED', 'FAILED'])->default('INITIATED');
            $table->timestamps();
        });
    }
    public function down(): void
    {
        Schema::dropIfExists('settlements');
    }
};
