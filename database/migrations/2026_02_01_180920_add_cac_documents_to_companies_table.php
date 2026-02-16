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
        Schema::table('companies', function (Blueprint $table) {
            $table->string('cac_certificate')->nullable()->after('license_documents');
            $table->string('memart')->nullable()->after('cac_certificate');
            $table->string('application_for_registration')->nullable()->after('memart');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn(['cac_certificate', 'memart', 'application_for_registration']);
        });
    }
};
