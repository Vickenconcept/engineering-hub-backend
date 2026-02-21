<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Central reference for milestone escrow payments: one ID (hold_ref) links
     * client (payer), company (payee when released), project, milestone, and
     * Paystack refs for both charge (hold) and transfer (release).
     */
    public function up(): void
    {
        Schema::create('escrow_hold_references', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('hold_ref', 32)->unique();
            $table->uuid('escrow_id')->unique();
            $table->uuid('project_id');
            $table->uuid('milestone_id');
            $table->uuid('client_id');
            $table->uuid('company_id');
            $table->string('paystack_charge_reference', 64)->nullable()->comment('Paystack ref when client paid (funds held)');
            $table->string('paystack_transfer_reference', 64)->nullable()->comment('Paystack ref when released to company');
            $table->enum('status', ['held', 'released', 'refunded'])->default('held');
            $table->timestamps();

            $table->foreign('escrow_id')->references('id')->on('escrows')->onDelete('cascade');
            $table->foreign('project_id')->references('id')->on('projects')->onDelete('cascade');
            $table->foreign('milestone_id')->references('id')->on('milestones')->onDelete('cascade');
            $table->foreign('client_id')->references('id')->on('users')->onDelete('cascade');
            $table->foreign('company_id')->references('id')->on('companies')->onDelete('cascade');

            $table->index('hold_ref');
            $table->index('status');
            $table->index('client_id');
            $table->index('company_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('escrow_hold_references');
    }
};
