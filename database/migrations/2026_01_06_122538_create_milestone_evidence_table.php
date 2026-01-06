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
        Schema::create('milestone_evidence', function (Blueprint $table) {
            $table->id();
            $table->foreignId('milestone_id')->constrained()->onDelete('cascade');
            $table->enum('type', ['image', 'video', 'text']);
            $table->string('file_path')->nullable(); // For image/video files
            $table->text('description')->nullable();
            $table->foreignId('uploaded_by')->constrained('users')->onDelete('cascade');
            $table->timestamps();

            $table->index('milestone_id');
            $table->index('type');
            $table->index('uploaded_by');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('milestone_evidence');
    }
};
