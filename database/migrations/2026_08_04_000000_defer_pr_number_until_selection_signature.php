<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('pr_documents') || ! Schema::hasTable('pr_document_signatures')) {
            return;
        }

        $unofficialIds = DB::table('pr_documents as documents')
            ->whereIn('documents.status', ['draft', 'sent_user'])
            ->whereNotExists(function ($query): void {
                $query->selectRaw('1')
                    ->from('pr_document_signatures as signatures')
                    ->whereColumn('signatures.pr_document_id', 'documents.id')
                    ->whereNotNull('signatures.signed_at')
                    ->where('signatures.duty', '!=', 'create');
            })
            ->pluck('documents.id');

        DB::table('pr_documents')
            ->whereIn('id', $unofficialIds)
            ->update(['pr_number' => null]);

        $this->recalculateSequences();
    }

    public function down(): void
    {
        // เลขเดิมของใบร่างไม่ใช่เลขทางการ จึงไม่สามารถและไม่ควรนำกลับมาใส่เมื่อ rollback
    }

    private function recalculateSequences(): void
    {
        if (! Schema::hasTable('pr_number_sequences')) {
            return;
        }

        $maximums = [];

        foreach (DB::table('pr_documents')->whereNotNull('pr_number')->pluck('pr_number') as $number) {
            if (preg_match('/^(PR\d{2})-(\d{4})$/', trim((string) $number), $matches) !== 1) {
                continue;
            }

            $maximums[$matches[1]] = max($maximums[$matches[1]] ?? 0, (int) $matches[2]);
        }

        $knownSeries = DB::table('pr_number_sequences')->pluck('series')->all();

        foreach ($knownSeries as $series) {
            DB::table('pr_number_sequences')
                ->where('series', $series)
                ->update([
                    'last_number' => $maximums[$series] ?? 0,
                    'updated_at' => now(),
                ]);
        }

        foreach (array_diff(array_keys($maximums), $knownSeries) as $series) {
            DB::table('pr_number_sequences')->insert([
                'series' => $series,
                'last_number' => $maximums[$series],
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }
};
