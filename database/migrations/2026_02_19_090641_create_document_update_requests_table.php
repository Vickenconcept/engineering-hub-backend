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
        Schema::create('document_update_requests', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('project_id');
            $table->foreign('project_id')->references('id')->on('projects')->onDelete('cascade');
            $table->string('document_type'); // 'preview_image', 'drawing_architectural', 'drawing_structural', 'drawing_mechanical', 'drawing_technical', or 'extra_document'
            $table->uuid('extra_document_id')->nullable(); // For extra documents
            $table->foreign('extra_document_id')->references('id')->on('project_documents')->onDelete('cascade');
            $table->uuid('requested_by'); // Company user ID
            $table->foreign('requested_by')->references('id')->on('users')->onDelete('cascade');
            $table->enum('status', ['pending', 'granted', 'denied'])->default('pending');
            $table->uuid('granted_by')->nullable(); // Client user ID
            $table->foreign('granted_by')->references('id')->on('users')->onDelete('set null');
            $table->timestamp('granted_at')->nullable();
            $table->timestamp('denied_at')->nullable();
            $table->text('reason')->nullable(); // Optional reason for the request
            $table->timestamps();

            $table->index('project_id');
            $table->index('status');
            $table->index(['project_id', 'document_type', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('document_update_requests');
    }
};
