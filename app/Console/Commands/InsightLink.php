<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

/**
 * สร้าง symlink public/insight-storage -> {INSIGHT_BASE_PATH}/storage/app/public
 *
 *   php artisan insight:link
 *
 * ทำให้ PR Compare แสดงรูปโปรไฟล์พนักงานจากสตอเรจของ Insight ได้โดยไม่ต้องคัดลอกไฟล์
 * (รูปเปลี่ยนที่ Insight แล้วเห็นผลทันที และไม่กินพื้นที่ซ้ำ)
 *
 * บน Windows ต้องรันด้วยสิทธิ์ Administrator หรือเปิด Developer Mode
 */
class InsightLink extends Command
{
    protected $signature = 'insight:link {--force : ลบลิงก์เดิมแล้วสร้างใหม่}';

    protected $description = 'สร้าง symlink ไปยังสตอเรจรูปโปรไฟล์ของ Insight';

    public function handle(): int
    {
        $base = rtrim((string) env('INSIGHT_BASE_PATH', 'C:\\xampp\\htdocs\\Insight'), '\\/');
        $target = $base.DIRECTORY_SEPARATOR.'storage'.DIRECTORY_SEPARATOR.'app'.DIRECTORY_SEPARATOR.'public';
        $link = public_path('insight-storage');

        if (! is_dir($target)) {
            $this->error("ไม่พบสตอเรจของ Insight ที่: {$target}");
            $this->line('ตรวจค่า INSIGHT_BASE_PATH ใน .env');

            return self::FAILURE;
        }

        if (file_exists($link) || is_link($link)) {
            if (! $this->option('force')) {
                $this->info("มีลิงก์อยู่แล้ว: {$link}");

                return self::SUCCESS;
            }

            is_link($link) ? unlink($link) : $this->deleteDirectory($link);
        }

        if (! @symlink($target, $link)) {
            $this->warn('สร้าง symlink ไม่สำเร็จ (บน Windows ต้องใช้สิทธิ์ Administrator)');
            $this->line('ไม่เป็นไร — รูปโปรไฟล์ยังแสดงได้ผ่าน route insight.asset (ช้ากว่าเล็กน้อยเพราะผ่าน PHP)');
            $this->line('ถ้าอยากให้เร็วขึ้น เปิด Command Prompt แบบ Run as Administrator แล้วรัน:');
            $this->line("  mklink /D \"{$link}\" \"".str_replace('/', '\\', $target).'"');

            return self::FAILURE;
        }

        $this->info("สร้างลิงก์แล้ว: {$link}  ->  {$target}");

        return self::SUCCESS;
    }

    private function deleteDirectory(string $dir): void
    {
        foreach (array_diff(scandir($dir) ?: [], ['.', '..']) as $item) {
            $path = $dir.DIRECTORY_SEPARATOR.$item;
            is_dir($path) ? $this->deleteDirectory($path) : unlink($path);
        }

        rmdir($dir);
    }
}
