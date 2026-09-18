<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
| ❌ เลิกซิงค์ตามรอบแล้ว (2026-08-03)
|
| เดิมรัน `insight:sync` ทุก 15 นาทีผ่าน Windows Task ซึ่ง
|   - ข้อมูลอัปเดตช้า ต้องรอถึง 15 นาที หรือให้ admin กดปุ่มซิงค์เอง
|   - เปิดหน้าต่าง CMD เด้งขึ้นมาบนจอทุกรอบ
|
| ตอนนี้ใช้ push แทน: Insight ยิง webhook มาบอกทันทีที่ข้อมูลเปลี่ยน
| (ดู App\Http\Controllers\InsightWebhookController)
|
| คำสั่ง `php artisan insight:sync` ยังใช้ได้อยู่ — เก็บไว้กู้คืนด้วยมือเวลามีปัญหา
*/
