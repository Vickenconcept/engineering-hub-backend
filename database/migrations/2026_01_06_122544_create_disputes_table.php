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
        Schema::create('disputes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('project_id');
            $table->foreign('project_id')->references('id')->on('projects')->onDelete('cascade');
            $table->uuid('milestone_id')->nullable();
            $table->foreign('milestone_id')->references('id')->on('milestones')->onDelete('cascade');
            $table->uuid('raised_by');
            $table->foreign('raised_by')->references('id')->on('users')->onDelete('cascade');
            $table->text('reason');
            $table->enum('status', ['open', 'resolved', 'escalated'])->default('open');
            $table->text('resolution_notes')->nullable();
            $table->timestamps();

            $table->index('project_id');
            $table->index('milestone_id');
            $table->index('raised_by');
            $table->index('status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('disputes');
    }
};
