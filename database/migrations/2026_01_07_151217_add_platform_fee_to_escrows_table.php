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
        Schema::table('escrows', function (Blueprint $table) {
            $table->decimal('platform_fee', 12, 2)->default(0)->after('amount');
            $table->decimal('net_amount', 12, 2)->nullable()->after('platform_fee'); // Amount after platform fee deduction
            $table->decimal('platform_fee_percentage', 5, 2)->nullable()->after('net_amount'); // Store the percentage used at time of calculation
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('escrows', function (Blueprint $table) {
            $table->dropColumn(['platform_fee', 'net_amount', 'platform_fee_percentage']);
        });
    }
};
