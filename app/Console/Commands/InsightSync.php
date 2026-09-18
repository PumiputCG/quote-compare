<?php

namespace App\Console\Commands;

use App\Models\SyncLog;
use App\Services\InsightMirror;
use App\Services\ResignationGuard;
use Illuminate\Console\Command;
use Throwable;

/**
 * ซิงค์ข้อมูลพนักงานจาก Supavut Insight เข้ามิเรอร์ของ PR Compare
 *
 *   php artisan insight:sync [--source=schedule]
 *
 * เรียกอัตโนมัติทุก 15 นาทีผ่าน Scheduler (routes/console.php)
 * และเรียกได้จากปุ่ม "ซิงค์ตอนนี้" ในหน้า ข้อมูลพนักงาน (เฉพาะ admin)
 */
class InsightSync extends Command
{
    protected $signature = 'insight:sync
        {--source=cli : ที่มาของการรัน (cli|manual|schedule)}
        {--fresh : ล้างมิเรอร์ทิ้งก่อนดึงใหม่ — ใช้ตอนเปลี่ยนต้นทาง INSIGHT_DB_HOST}';

    protected $description = 'ดึงข้อมูลพนักงาน (employees + app_users) จาก Insight มาเก็บเป็นมิเรอร์';

    public function handle(InsightMirror $mirror): int
    {
        $startedAt = microtime(true);
        $source = (string) $this->option('source');

        $this->newLine();
        $this->info('===== insight:sync '.now()->format('Y-m-d H:i:s').' =====');

        if (! $mirror->connectionOk()) {
            $message = 'ต่อฐานข้อมูล Insight ไม่ได้ — ตรวจ INSIGHT_DB_* ใน .env และเช็คว่า MySQL ทำงานอยู่';
            $this->error($message);
            SyncLog::record($source, false, $message);

            return self::FAILURE;
        }

        $fresh = (bool) $this->option('fresh');

        if ($fresh) {
            $this->warn('  --fresh : ล้างมิเรอร์เดิมทิ้งก่อนดึงใหม่ (ผู้ที่ล็อกอินค้างจะหลุด session)');
        }

        try {
            $result = $mirror->sync($fresh);
        } catch (Throwable $e) {
            $message = 'ผิดพลาด: '.$e->getMessage();
            $this->error($message);
            SyncLog::record($source, false, $message);

            return self::FAILURE;
        }

        $e = $result['employees'];
        $u = $result['app_users'];

        $message = sprintf(
            'ซิงค์สำเร็จ · พนักงาน %d คน (ใหม่ %d / อัปเดต %d / ลบ %d) · บัญชีล็อกอิน %d บัญชี (ใหม่ %d / อัปเดต %d / ลบ %d)',
            $e['total'], $e['created'], $e['updated'], $e['removed'],
            $u['total'], $u['created'], $u['updated'], $u['removed'],
        );

        $this->line("  employees : รวม {$e['total']} · ใหม่ {$e['created']} · อัปเดต {$e['updated']} · ลบ {$e['removed']}");
        $this->line("  app_users : รวม {$u['total']} · ใหม่ {$u['created']} · อัปเดต {$u['updated']} · ลบ {$u['removed']}");

        // ข้อมูลพนักงานเปลี่ยนแล้ว — เอกสารที่รอคนที่ลาออกไปแล้วต้องปิดทิ้ง
        $rejected = ResignationGuard::sweep();

        if ($rejected !== []) {
            $message .= ' · ปฏิเสธเพราะผู้รับผิดชอบลาออก: '.implode(', ', $rejected);
            $this->line('  ปฏิเสธอัตโนมัติ : '.implode(', ', $rejected));
        }

        SyncLog::record($source, true, $message, $result);

        $this->info($message);
        $this->line('ใช้เวลา '.number_format(microtime(true) - $startedAt, 1).' วินาที');

        return self::SUCCESS;
    }
}
