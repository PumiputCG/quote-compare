<?php

use App\Models\PrDocument;
use App\Services\PrNumberGenerator;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('pr_documents') || ! Schema::hasTable('pr_number_sequences')) {
            return;
        }

        DB::transaction(function (): void {
            PrDocument::query()
                ->whereNull('pr_number')
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->each(function (PrDocument $document): void {
                    $document->update([
                        'pr_number' => app(PrNumberGenerator::class)->next(),
                    ]);
                });
        });
    }

    public function down(): void
    {
        // เลขที่จองอาจถูกยืนยันเป็นเลขทางการแล้ว จึงไม่ล้างเลขเมื่อ rollback
    }
};
