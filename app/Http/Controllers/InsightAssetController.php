<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * เสิร์ฟไฟล์รูปโปรไฟล์ที่เก็บอยู่ในสตอเรจของ Insight
 *
 * ปกติถ้าสร้าง symlink `public/insight-storage` ได้ (php artisan insight:link)
 * เว็บเซิร์ฟเวอร์จะเสิร์ฟไฟล์เองโดยไม่ผ่าน PHP เลย
 * แต่บน Windows การสร้าง symlink ต้องใช้สิทธิ์ Administrator — เมื่อไม่มีลิงก์
 * คำขอจะตกมาที่ index.php แล้วเข้า route นี้แทน (ผลลัพธ์เหมือนกัน แค่ช้ากว่าเล็กน้อย)
 *
 * ความปลอดภัย: อ่านได้เฉพาะไฟล์ภาพที่อยู่ใต้โฟลเดอร์สตอเรจของ Insight เท่านั้น
 */
class InsightAssetController extends Controller
{
    /** นามสกุลที่ยอมให้เสิร์ฟ */
    private const ALLOWED = ['jpg', 'jpeg', 'png', 'webp', 'gif', 'heic', 'heif'];

    public function show(Request $request, string $path): BinaryFileResponse
    {
        $base = rtrim((string) env('INSIGHT_BASE_PATH', 'C:/xampp/htdocs/Insight'), '\\/');
        $root = realpath($base.'/storage/app/public');

        abort_if($root === false, 404);

        // กัน path traversal: ต้องคลี่ path แล้วยังอยู่ใต้ $root เท่านั้น
        $full = realpath($root.DIRECTORY_SEPARATOR.str_replace('\\', '/', $path));

        abort_if($full === false || ! str_starts_with($full, $root), 404);
        abort_unless(is_file($full), 404);
        abort_unless(in_array(strtolower(pathinfo($full, PATHINFO_EXTENSION)), self::ALLOWED, true), 404);

        return response()->file($full, [
            'Cache-Control' => 'private, max-age=86400',
        ]);
    }
}
