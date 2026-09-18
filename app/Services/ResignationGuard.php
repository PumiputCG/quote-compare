<?php

namespace App\Services;

use App\Models\AppUser;
use App\Models\DocumentRole;
use App\Models\Employee;
use App\Models\PrDocument;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Facades\Cache;

/**
 * กันเอกสารค้างเพราะคนในเส้นทางลาออก
 *
 * ตอนพนักงานลาออก Bplus → Insight จะทำสองอย่างพร้อมกัน:
 *   - `employees` แถวยังอยู่ แต่ `emp_status` = 2 และมี `resign_date`
 *   - `app_users` (บัญชีล็อกอิน) **ถูกลบทิ้ง** → มิเรอร์ฝั่งนี้ลบตาม
 *
 * เอกสารอ้างถึงคนด้วย `id_thai_hash` (= เลขบัตรประชาชน = `employees.license_id`)
 * จึงยังตามหาชื่อได้แม้บัญชีหายไปแล้ว
 *
 * ถ้าขั้นที่ยังไม่ผ่าน "ไม่เหลือใครทำได้เลย" เอกสารจะเดินต่อไม่ได้ตลอดกาล
 * ระบบจึงปฏิเสธให้อัตโนมัติ เพื่อให้ฝ่ายจัดซื้อทำใบใหม่ได้ทันทีโดยไม่ต้องรอ
 */
class ResignationGuard
{
    /** จำผลไว้ทั้ง request เพราะหน้ารายการเรียกซ้ำหลายรอบต่อเอกสารหนึ่งใบ */
    private static array $employees = [];

    private static ?array $activeHashes = null;

    /** ผู้ปฏิเสธที่บันทึกลงเอกสาร — ไม่ใช่คน จึงใช้ชื่อนี้ให้ชัดว่าเป็นระบบทำเอง */
    public const REJECTED_BY = 'ระบบ (อัตโนมัติ)';

    public static function forget(): void
    {
        self::$employees = [];
        self::$activeHashes = null;
    }

    public static function employeeOf(?string $hash): ?Employee
    {
        $hash = trim((string) $hash);

        if ($hash === '') {
            return null;
        }

        if (! array_key_exists($hash, self::$employees)) {
            self::$employees[$hash] = Employee::where('license_id', $hash)->first();
        }

        return self::$employees[$hash];
    }

    /**
     * ลาออกแล้วหรือยัง
     *
     * ยึด `emp_status` เป็นหลัก · ถ้าไม่มีประวัติพนักงานเลยและไม่มีบัญชีด้วย
     * แปลว่าคนคนนี้หายไปจากระบบต้นทางแล้ว ก็ถือว่าทำงานต่อไม่ได้เหมือนกัน
     */
    public static function isResigned(?string $hash): bool
    {
        $hash = trim((string) $hash);

        if ($hash === '') {
            return false;
        }

        $employee = self::employeeOf($hash);

        if ($employee) {
            return $employee->isResigned();
        }

        if (self::$activeHashes === null) {
            self::$activeHashes = AppUser::pluck('id_thai_hash')
                ->filter()
                ->flip()
                ->all();
        }

        return ! array_key_exists($hash, self::$activeHashes);
    }

    /** วันที่ลาออก (ถ้ามีบันทึกไว้) — เอาไว้แสดงในหน้าจอ */
    public static function resignedOn(?string $hash): ?string
    {
        $employee = self::employeeOf($hash);

        return $employee?->resign_date?->format('d/m/Y');
    }

    /**
     * ขั้นแรกที่เดินต่อไม่ได้เพราะคนที่ต้องทำลาออกหมดแล้ว
     *
     * ขั้นที่ผ่านไปแล้วไม่นับ — คนเซ็นไปแล้วลาออกทีหลังไม่กระทบเอกสาร
     */
    public static function blockedNode(PrDocument $document, EloquentCollection $steps): ?array
    {
        foreach ($document->routeProgress($steps) as $index => $node) {
            if ($node['blocked'] ?? false) {
                return $node + ['index' => $index];
            }
        }

        return null;
    }

    /**
     * ไล่ปฏิเสธเอกสารทุกใบที่เดินต่อไม่ได้แล้ว
     *
     * เรียกหลังซิงค์ข้อมูลพนักงานทุกครั้ง (webhook จาก Insight และตัวตั้งเวลา)
     * และเรียกซ้ำตอนเปิดหน้ารายการ เผื่อซิงค์รอบนั้นพลาด
     *
     * @return array<int, string> เลขที่เอกสารที่ถูกปฏิเสธรอบนี้
     */
    /**
     * กวาดซ้ำแบบเบาๆ ตอนเปิดหน้ารายการ — เผื่อ webhook จาก Insight พลาดไปรอบหนึ่ง
     *
     * จำกัดไม่ให้ทำถี่กว่า 5 นาที เพราะหน้ารายการถูกเปิดบ่อย
     */
    public static function sweepThrottled(): void
    {
        if (Cache::has('resign_sweep_at')) {
            return;
        }

        Cache::put('resign_sweep_at', now()->toDateTimeString(), 300);

        self::sweep();
    }

    public static function sweep(): array
    {
        self::forget();

        $steps = DocumentRole::chain();

        if ($steps->isEmpty()) {
            return [];
        }

        /*
        | เอาเฉพาะใบที่ "เดินอยู่ในเส้นทางแล้ว"
        |
        | ใบร่างยังอยู่ในมือคนทำ ยังไม่ได้ส่งให้ใคร — ถ้าไปปฏิเสธทิ้งจะเสียงานที่กำลังพิมพ์อยู่
        | และ admin ยังมีเวลาใส่คนใหม่ในขั้นนั้นก่อนกดส่ง
        */
        $documents = PrDocument::query()
            ->whereNotIn('status', ['draft', 'approved'])
            ->where('status', 'not like', 'rejected%')
            ->with('signatures')
            ->get();

        $rejected = [];

        foreach ($documents as $document) {
            $node = self::blockedNode($document, $steps);

            if (! $node) {
                continue;
            }

            $names = collect($node['people'])
                ->where('resigned', true)
                ->pluck('name')
                ->filter()
                ->join(', ');

            $document->update([
                'status' => 'rejected_'.($node['index'] + 1),
                'rejected_reason' => trim(sprintf(
                    'ผู้รับผิดชอบขั้น "%s — %s" ลาออกจากบริษัทแล้ว (%s) เอกสารจึงดำเนินการต่อไม่ได้ กรุณาทำใบใหม่',
                    $node['step']->roleLabel(),
                    $node['step']->dutyLabel(),
                    $names !== '' ? $names : 'ไม่พบผู้รับผิดชอบ',
                )),
                'rejected_by_name' => self::REJECTED_BY,
                'rejected_at' => now(),
            ]);

            $rejected[] = $document->referenceLabel();
        }

        return $rejected;
    }
}
