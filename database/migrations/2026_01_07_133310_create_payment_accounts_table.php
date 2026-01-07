<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('payment_accounts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('user_id');
            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
            $table->string('account_name');
            $table->string('account_number');
            $table->string('bank_code');
            $table->string('bank_name')->nullable();
            $table->string('account_type')->default('nuban'); // nuban, mobile_money, etc.
            $table->string('currency')->default('NGN');
            $table->boolean('is_default')->default(false);
            $table->boolean('is_verified')->default(false);
            $table->string('recipient_code')->nullable(); // Paystack recipient code
            $table->timestamps();

            $table->index('user_id');
            $table->index('is_default');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('payment_accounts');
    }
};
