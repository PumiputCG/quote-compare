<?php

namespace App\Console\Commands;

use App\Models\ItemCode;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * นำเข้าทะเบียนรหัสสินค้าจากระบบ PR เดิม
 *
 *   php artisan itemcode:import
 *
 * ดึงรหัสหมวดที่ฝ่ายจัดซื้อใช้จริงมาให้เลือกในหน้าเอกสาร พร้อม 3 ตัวอย่างของที่เคยซื้อ
 * ไว้เป็นคำอธิบายในเมนู เพราะหนึ่งรหัสใช้กับของหลายแบบ (D-042)
 * รันซ้ำได้ — รหัสเดิมจะถูกอัปเดตชื่อ/ตัวอย่าง/จำนวนครั้งที่ใช้ ไม่สร้างซ้ำ
 *
 * ⚠️ รหัสที่ PR Compare ออกเอง (source = prcompare) จะไม่ถูกแตะ
 */
class ItemCodeImport extends Command
{
    protected $signature = 'itemcode:import';

    protected $description = 'นำเข้าทะเบียนรหัสสินค้า (Item Code) จากระบบ PR เดิม';

    public function handle(): int
    {
        $this->newLine();
        $this->info('===== itemcode:import '.now()->format('Y-m-d H:i:s').' =====');

        try {
            $legacy_rows = DB::connection('pr_legacy')
                ->table('prline_manager')
                ->selectRaw('TRIM(ItemCode) AS code, ItemName AS name, recid')
                ->whereRaw("TRIM(COALESCE(ItemCode, '')) <> ''")
                ->orderByDesc('recid')
                ->get();
        } catch (Throwable $e) {
            $this->error('ต่อระบบ PR เดิมไม่ได้: '.$e->getMessage());
            $this->line('ตรวจ PR_LEGACY_DB_* ใน .env');

            return self::FAILURE;
        }

        // เรียงจาก recid ใหม่ไปเก่า -> ชื่อแรกที่เจอคือรายการล่าสุดของรหัสนั้น
        $grouped = [];
        foreach ($legacy_rows as $row) {
            $code = trim((string) $row->code);
            $name = trim(preg_replace('/\s+/u', ' ', (string) $row->name));

            if (! isset($grouped[$code])) {
                $grouped[$code] = (object) [
                    'code' => $code,
                    'name' => $name,
                    'used' => 0,
                    'examples' => [],
                ];
            }

            $grouped[$code]->used++;

            // หนึ่งรหัสใช้กับของหลายแบบ (D-042) เก็บ 3 ตัวอย่างไว้เป็นคำอธิบายในเมนู
            if ($name !== '' && count($grouped[$code]->examples) < 3) {
                $sample = Str::limit($name, 30);

                if (! in_array($sample, $grouped[$code]->examples, true)) {
                    $grouped[$code]->examples[] = $sample;
                }
            }
        }

        $rows = collect(array_values($grouped));

        $created = 0;
        $updated = 0;

        foreach ($rows as $row) {
            $parsed = ItemCode::parse((string) $row->code);

            if ($parsed['code'] === '') {
                continue;
            }

            $examples = implode(' · ', $row->examples) ?: null;
            $existing = ItemCode::where('code', $parsed['code'])->first();

            if ($existing) {
                if ($existing->source === 'prcompare') {
                    continue;
                }

                $existing->fill($parsed + [
                    'name' => trim((string) $row->name) ?: $existing->name,
                    'examples' => $examples,
                    'used_count' => (int) $row->used,
                ])->save();
                $updated++;

                continue;
            }

            ItemCode::create($parsed + [
                'name' => trim((string) $row->name) ?: null,
                'examples' => $examples,
                'used_count' => (int) $row->used,
                'source' => 'legacy',
            ]);
            $created++;
        }

        $this->line("  รหัสใหม่ {$created} · อัปเดต {$updated} · รวมในทะเบียน ".ItemCode::count());

        $this->newLine();
        $this->line('  หมวดหลักที่นำเข้าได้:');
        foreach (ItemCode::families() as $family => $total) {
            $label = ItemCode::FAMILIES[$family] ?? '—';
            $this->line('    '.str_pad($family, 10).str_pad((string) $total, 6, ' ', STR_PAD_LEFT).' รหัส   '.$label);
        }

        $this->newLine();
        $this->info('เสร็จแล้ว');

        return self::SUCCESS;
    }
}
