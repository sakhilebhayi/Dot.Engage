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
        Schema::table('contract_signatures', function (Blueprint $table) {
            // A signature now belongs to either a User (existing team-member
            // flow) OR a ContractExternalSigner (new guest-signer flow) --
            // never both, never neither. Enforced in application code.
            $table->foreignId('user_id')->nullable()->change();
            $table->foreignId('contract_external_signer_id')->nullable()
                ->after('user_id')
                ->constrained('contract_external_signers')
                ->cascadeOnDelete();

            // Snapshotted at signing time so the certificate/PDF still shows
            // who signed even if the external-signer invite row is later
            // pruned -- the same reason ContractSignature doesn't join out
            // to User for its own name/email today.
            $table->string('signer_name')->nullable()->after('contract_external_signer_id');
            $table->string('signer_email')->nullable()->after('signer_name');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('contract_signatures', function (Blueprint $table) {
            $table->dropConstrainedForeignId('contract_external_signer_id');
            $table->dropColumn(['signer_name', 'signer_email']);
            $table->foreignId('user_id')->nullable(false)->change();
        });
    }
};
