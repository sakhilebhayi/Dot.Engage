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
        Schema::table('contract_external_signers', function (Blueprint $table) {
            // Null = unordered (may sign any time, the pre-existing default
            // behavior). A set value means this signer must wait for every
            // other external signer on the same contract with a lower
            // sign_order to have signed first.
            $table->unsignedInteger('sign_order')->nullable()->after('status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('contract_external_signers', function (Blueprint $table) {
            $table->dropColumn('sign_order');
        });
    }
};
