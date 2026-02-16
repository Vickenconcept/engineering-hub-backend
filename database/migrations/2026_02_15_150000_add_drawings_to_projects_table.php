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
        Schema::table('projects', function (Blueprint $table) {
            $table->string('preview_image_url')->nullable()->after('location_address');
            $table->string('drawing_architectural_url')->nullable()->after('preview_image_url');
            $table->string('drawing_structural_url')->nullable()->after('drawing_architectural_url');
            $table->string('drawing_mechanical_url')->nullable()->after('drawing_structural_url');
            $table->string('drawing_technical_url')->nullable()->after('drawing_mechanical_url');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->dropColumn([
                'preview_image_url',
                'drawing_architectural_url',
                'drawing_structural_url',
                'drawing_mechanical_url',
                'drawing_technical_url',
            ]);
        });
    }
};
