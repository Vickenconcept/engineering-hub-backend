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
        Schema::create('platform_settings', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique(); // e.g., 'platform_fee_percentage'
            $table->text('value'); // JSON or string value
            $table->text('description')->nullable();
            $table->timestamps();
        });

        // Insert default platform fee (6.5% - middle of 5-8% range)
        DB::table('platform_settings')->insert([
            'key' => 'platform_fee_percentage',
            'value' => '6.5',
            'description' => 'Platform fee percentage (5-8% of escrow amount)',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('platform_settings');
    }
};
