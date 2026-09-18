<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pr_number_sequences', function (Blueprint $table): void {
            $table->string('series', 10)->primary();
            $table->unsignedInteger('last_number')->default(0);
            $table->timestamps();
        });

        Schema::table('pr_documents', function (Blueprint $table): void {
            $table->string('pr_number', 40)->nullable()->change();
        });

        DB::table('pr_documents')
            ->whereRaw("TRIM(COALESCE(pr_number, '')) = ''")
            ->update(['pr_number' => null]);

        Schema::table('pr_documents', function (Blueprint $table): void {
            $table->unique('pr_number', 'pr_documents_pr_number_unique');
        });

        $now = now();
        $existing_codes = DB::table('pr_items')
            ->selectRaw('TRIM(item_code) AS code, MAX(description) AS name, COUNT(*) AS used')
            ->whereRaw("TRIM(COALESCE(item_code, '')) <> ''")
            ->groupBy(DB::raw('TRIM(item_code)'))
            ->get();

        foreach ($existing_codes as $row) {
            $parts = explode('-', $row->code);
            $last = end($parts);
            $has_sequence = count($parts) > 1 && $last !== '' && ctype_digit($last);

            DB::table('item_codes')->insertOrIgnore([
                'code' => $row->code,
                'name' => trim((string) $row->name) ?: null,
                'family' => $parts[0] ?? $row->code,
                'sub' => $parts[1] ?? null,
                'prefix' => $has_sequence ? implode('-', array_slice($parts, 0, -1)) : $row->code,
                'seq' => $has_sequence ? (int) $last : null,
                'seq_width' => $has_sequence ? strlen($last) : 3,
                'used_count' => (int) $row->used,
                'source' => 'prcompare',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        Schema::table('pr_documents', function (Blueprint $table): void {
            $table->dropUnique('pr_documents_pr_number_unique');
        });

        DB::table('pr_documents')->whereNull('pr_number')->update(['pr_number' => '']);

        Schema::table('pr_documents', function (Blueprint $table): void {
            $table->string('pr_number', 40)->nullable(false)->change();
        });

        Schema::dropIfExists('pr_number_sequences');
    }
};
