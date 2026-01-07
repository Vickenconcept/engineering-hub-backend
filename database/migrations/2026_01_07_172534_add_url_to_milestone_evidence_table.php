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
        Schema::table('milestone_evidence', function (Blueprint $table) {
            $table->text('url')->nullable()->after('file_path'); // Cloudinary URL
            $table->string('public_id')->nullable()->after('url'); // Cloudinary public ID for deletion
            $table->string('thumbnail_url')->nullable()->after('public_id'); // For videos
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('milestone_evidence', function (Blueprint $table) {
            $table->dropColumn(['url', 'public_id', 'thumbnail_url']);
        });
    }
};
