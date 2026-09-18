<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * ประวัติการซิงค์ข้อมูลพนักงานจาก Insight
 *
 * source: manual   = admin กดปุ่มในหน้า "ข้อมูลพนักงาน"
 *         schedule = ตัวตั้งเวลา (ทุก 15 นาที)
 *         login    = ดึงบัญชีเดี่ยวตอนมีคนล็อกอินแต่ยังไม่มีในมิเรอร์
 */
class SyncLog extends Model
{
    protected $table = 'sync_logs';

    /** เก็บย้อนหลังกี่รอบ (รอบเก่ากว่านี้ถูกตัดทิ้งอัตโนมัติ) */
    private const KEEP = 50;

    protected $fillable = ['source', 'ok', 'message', 'detail', 'ran_at'];

    protected $casts = [
        'ok' => 'boolean',
        'detail' => 'array',
        'ran_at' => 'datetime',
    ];

    public static function latestRun(): ?self
    {
        return self::latest('ran_at')->first();
    }

    /** บันทึกผล 1 รอบ แล้วตัดประวัติเก่าให้เหลือ KEEP รอบล่าสุด */
    public static function record(string $source, bool $ok, string $message, array $detail = []): self
    {
        $log = self::create([
            'source' => $source,
            'ok' => $ok,
            'message' => $message,
            'detail' => $detail,
            'ran_at' => now(),
        ]);

        $keep = self::latest('id')->limit(self::KEEP)->pluck('id');
        self::whereNotIn('id', $keep)->delete();

        return $log;
    }
}
