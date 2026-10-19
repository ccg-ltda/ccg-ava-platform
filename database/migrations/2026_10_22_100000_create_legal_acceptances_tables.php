<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Evidence of what each account accepted (service documents, by version) and, apart, of its choice about commercial
     * communications. Both are append-only records of the person's own decision: nothing here is a permission.
     */
    public function up(): void
    {
        Schema::create('legal_acceptances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('document', 40);
            $table->string('version', 40);
            $table->timestamp('accepted_at');
            $table->string('ip_address', 45)->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'document', 'version']);
        });

        Schema::create('communication_consents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('purpose', 40)->default('marketing');
            $table->boolean('granted');
            $table->string('source', 40);
            $table->string('privacy_notice_version', 40)->nullable();
            $table->timestamp('recorded_at');
            $table->string('ip_address', 45)->nullable();
            $table->timestamps();

            $table->index(['user_id', 'purpose', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('communication_consents');
        Schema::dropIfExists('legal_acceptances');
    }
};
