<?php

namespace App\Services;

use App\Models\PrDocument;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * สร้างไฟล์ PDF ของเอกสารเปรียบเทียบราคา
 *
 * ใช้ **Chrome ที่ติดตั้งอยู่ในเครื่องอยู่แล้ว** สั่งโหมด headless แปลง HTML เป็น PDF
 * ไม่ต้องลง package เพิ่ม และได้หน้าตาตรงกับที่เห็นบนเว็บเป๊ะ เพราะเป็นตัว render เดียวกัน
 * (dompdf/mPDF ทำไม่ได้ เพราะฟอร์มนี้ใช้ CSS grid/flex และฟอนต์ไทยเยอะ)
 *
 * ขั้นตอน: เขียน HTML ที่ render แล้วลงไฟล์ชั่วคราว → ให้ Chrome เปิดแบบ `file://`
 * จึงไม่ต้องเปิด URL สาธารณะให้ใครเข้าถึงเอกสารได้เลย
 */
class DocumentPdf
{
    /** ที่อยู่ Chrome/Edge ที่เป็นไปได้ — เครื่อง dev กับเซิร์ฟเวอร์คนละที่กัน */
    private const BROWSERS = [
        'C:/Program Files/Google/Chrome/Application/chrome.exe',
        'C:/Program Files (x86)/Google/Chrome/Application/chrome.exe',
        'C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe',
        'C:/Program Files/Microsoft/Edge/Application/msedge.exe',
    ];

    /** รอ Chrome ไม่เกินกี่วินาที */
    private const TIMEOUT = 45;

    public static function browserPath(): ?string
    {
        foreach (self::BROWSERS as $path) {
            if (is_file($path)) {
                return $path;
            }
        }

        return null;
    }

    /**
     * แปลง HTML เป็นไฟล์ PDF แล้วคืน path ของไฟล์ที่สร้าง
     *
     * @throws RuntimeException เมื่อหา Chrome ไม่เจอ หรือสร้างไฟล์ไม่สำเร็จ
     */
    public static function render(string $html, PrDocument $document): string
    {
        $browser = self::browserPath();

        if ($browser === null) {
            throw new RuntimeException('ไม่พบ Chrome หรือ Edge ในเครื่องนี้ จึงสร้างไฟล์ PDF ไม่ได้');
        }

        $work = storage_path('app/pdf-work');
        File::ensureDirectoryExists($work);

        $token = Str::random(24);
        $htmlPath = $work.'/'.$token.'.html';
        $pdfPath = $work.'/'.$token.'.pdf';
        $profile = $work.'/profile-'.$token;

        file_put_contents($htmlPath, self::useLocalAssets($html));

        /*
        | ส่ง argument เป็น array ให้ proc_open — Windows จะได้ไม่ต้องผ่าน cmd
        | (path ของ Chrome มีช่องว่าง ถ้าต่อเป็นสตริงเดียวแล้วให้ cmd แกะ จะพังเรื่องเครื่องหมายคำพูด)
        |
        | --headless=new          โหมด headless รุ่นใหม่ของ Chrome
        | --virtual-time-budget   รอ JS ในหน้า (คำนวณยอดรวม/ซูม) ทำงานจบก่อนพิมพ์
        | --no-pdf-header-footer  ไม่เอาหัว/ท้ายกระดาษที่เบราว์เซอร์ใส่ให้ (URL, เลขหน้า)
        | ขนาดกระดาษยึดตาม `@page { size: A4 landscape }` ในตัวเอกสารเอง
        */
        $arguments = [
            $browser,
            '--headless=new',
            '--disable-gpu',
            '--no-sandbox',
            '--disable-extensions',
            '--no-first-run',
            '--no-default-browser-check',
            '--run-all-compositor-stages-before-draw',
            '--virtual-time-budget=4000',
            '--no-pdf-header-footer',
            '--print-to-pdf-no-header',
            '--user-data-dir='.$profile,
            '--print-to-pdf='.$pdfPath,
            'file:///'.str_replace('\\', '/', $htmlPath),
        ];

        /*
        | ⚠️ ห้ามใช้ pipe แล้ว stream_get_contents รอ — มันบล็อกจนกว่า Chrome จะปิด stderr
        |    ถ้า Chrome ไม่ยอมจบ (เคยเจอ) คำขอจะค้างตลอดกาล หน้าเว็บหมุนไม่หยุด
        |    เขียนลงไฟล์แทน แล้ววนเช็คสถานะเองพร้อมกำหนดเวลาตาย
        */
        $logPath = $work.'/'.$token.'.log';
        $pipes = [];
        $process = proc_open($arguments, [1 => ['file', $logPath, 'w'], 2 => ['file', $logPath, 'a']], $pipes);
        $timedOut = false;

        if (is_resource($process)) {
            $deadline = microtime(true) + self::TIMEOUT;

            while (proc_get_status($process)['running']) {
                if (microtime(true) > $deadline) {
                    $timedOut = true;
                    break;
                }

                usleep(120_000);
            }

            proc_terminate($process);
            proc_close($process);
        }

        $error = trim((string) @file_get_contents($logPath));

        if ($timedOut) {
            $error = 'Chrome ทำงานเกิน '.self::TIMEOUT.' วินาที จึงถูกปิดไป · '.$error;
        }

        @unlink($htmlPath);
        @unlink($logPath);
        File::deleteDirectory($profile);

        if (! is_file($pdfPath) || filesize($pdfPath) === 0) {
            @unlink($pdfPath);

            throw new RuntimeException('สร้างไฟล์ PDF ไม่สำเร็จ '.mb_strimwidth(trim($error), 0, 160, '…'));
        }

        return $pdfPath;
    }

    /**
     * เปลี่ยน URL รูป/ไอคอนในหน้า ให้ Chrome อ่านจากดิสก์แทนการยิงกลับมาที่เว็บตัวเอง
     *
     * ⚠️ สำคัญมาก — ถ้าปล่อยให้ Chrome โหลดรูปผ่าน http จะเกิด **เดดล็อก**:
     *    PHP ค้างรอ Chrome อยู่ ส่วน Chrome ก็ค้างรอ Apache ตอบรูป
     *    ซึ่ง Apache ตอบไม่ได้เพราะยังติดคำขอเดิมที่รอ Chrome อยู่ · หน้าเว็บจะหมุนไม่หยุด
     */
    private static function useLocalAssets(string $html): string
    {
        $publicPath = 'file:///'.str_replace([' ', '\\'], ['%20', '/'], public_path()).'/';

        $bases = array_unique(array_filter([
            rtrim((string) asset(''), '/').'/',
            rtrim((string) config('app.url'), '/').'/',
            rtrim((string) url('/'), '/').'/',
        ]));

        return str_replace($bases, $publicPath, $html);
    }

    /** ชื่อไฟล์ที่ผู้ใช้จะได้ */
    public static function fileName(PrDocument $document): string
    {
        return $document->referenceLabel().'.pdf';
    }

    /** เก็บกวาดไฟล์ที่ค้างเกิน 1 ชั่วโมง เผื่อมีรอบไหนดาวน์โหลดไม่สำเร็จ */
    public static function sweepOldFiles(): void
    {
        $work = storage_path('app/pdf-work');

        if (! is_dir($work)) {
            return;
        }

        foreach (glob($work.'/*') as $path) {
            if (filemtime($path) < now()->subHour()->getTimestamp()) {
                is_dir($path) ? File::deleteDirectory($path) : @unlink($path);
            }
        }
    }
}
