<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * สิทธิ์ล็อกอินแยกตามตำแหน่ง — **ตารางของ PR Compare เอง ไม่ได้มิเรอร์จาก Insight**
 *
 * admin ติ๊กเลือกในหน้า "ตั้งค่าระบบ" ว่าตำแหน่งไหนเข้าใช้ระบบนี้ได้
 * ตำแหน่งอ่านมาจาก `app_users.position` (ซึ่งมิเรอร์มาจาก Insight อีกที)
 */
class PositionAccess extends Model
{
    protected $table = 'position_access';

    protected $fillable = ['position', 'can_login'];

    protected $casts = ['can_login' => 'boolean'];

    /**
     * ตำแหน่งทั้งหมดที่มีในระบบ พร้อมจำนวนบัญชีของแต่ละตำแหน่ง
     *
     * @return Collection<string,int> ['ตำแหน่ง' => จำนวนบัญชี] ; '' = ไม่ได้ระบุตำแหน่ง
     */
    public static function allPositions(): Collection
    {
        return DB::table('app_users')
            ->pluck('position')
            ->map(static fn ($p): string => trim((string) $p))
            ->countBy()
            ->sortKeys();
    }

    /** ['ตำแหน่ง' => true/false] ของที่บันทึกไว้ */
    public static function map(): Collection
    {
        return static::query()->pluck('can_login', 'position');
    }

    /** ตั้งค่าไปแล้วหรือยัง — ยังไม่ตั้ง = อนุญาตทุกคน */
    public static function isConfigured(): bool
    {
        return static::query()->exists();
    }

    /**
     * บัญชีนี้ล็อกอินเข้า PR Compare ได้ไหม
     *
     * admin เข้าได้เสมอ (กัน admin ติ๊กพลาดแล้วล็อกตัวเองออกจากระบบ)
     */
    public static function allows(AppUser $user): bool
    {
        if ($user->isAdmin() || ! static::isConfigured()) {
            return true;
        }

        return (bool) static::query()
            ->where('position', trim((string) $user->position))
            ->value('can_login');
    }

    /**
     * บันทึกทับทั้งชุด — เขียนแถวให้ครบทุกตำแหน่งที่มีอยู่จริง
     * ตำแหน่งที่ไม่ได้ติ๊ก = แถวที่ can_login = false (ไม่ใช่ไม่มีแถว) จะได้แยกออกจาก "ยังไม่เคยตั้งค่า"
     *
     * @param  array<int,string>  $allowed  ตำแหน่งที่ติ๊กเลือก
     * @return int จำนวนตำแหน่งที่อนุญาต
     */
    public static function replaceAll(array $allowed): int
    {
        $allowed = array_map(static fn ($p): string => trim((string) $p), $allowed);

        $rows = static::allPositions()->keys()
            ->map(static fn (string $position): array => [
                'position' => $position,
                'can_login' => in_array($position, $allowed, true),
                'created_at' => now(),
                'updated_at' => now(),
            ])
            ->all();

        DB::transaction(static function () use ($rows): void {
            static::query()->delete();

            foreach (array_chunk($rows, 200) as $chunk) {
                DB::table('position_access')->insert($chunk);
            }
        });

        return count(array_filter($rows, static fn (array $r): bool => $r['can_login']));
    }
}
