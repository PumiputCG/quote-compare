<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pr_document_signatures', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('pr_document_id')->constrained('pr_documents')->cascadeOnDelete();
            $table->foreignId('document_role_id')->nullable()->constrained('document_roles')->nullOnDelete();
            $table->unsignedInteger('step_no');
            $table->string('role', 60);
            $table->string('duty', 60);
            $table->string('signer_id_thai_hash')->index();
            $table->string('signer_name');
            $table->longText('signature_data');
            $table->timestamp('signed_at');
            $table->timestamps();

            $table->unique(['pr_document_id', 'document_role_id'], 'pr_doc_signature_step_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pr_document_signatures');
    }
};
