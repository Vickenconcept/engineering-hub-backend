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
        Schema::table('milestones', function (Blueprint $table) {
            $table->timestamp('verified_at')->nullable()->after('status');
            $table->uuid('verified_by')->nullable()->after('verified_at');
            $table->text('client_notes')->nullable()->after('verified_by');
            $table->text('company_notes')->nullable()->after('client_notes');
            
            $table->foreign('verified_by')->references('id')->on('users')->onDelete('set null');
            $table->index('verified_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('milestones', function (Blueprint $table) {
            $table->dropForeign(['verified_by']);
            $table->dropIndex(['verified_at']);
            $table->dropColumn(['verified_at', 'verified_by', 'client_notes', 'company_notes']);
        });
    }
};
