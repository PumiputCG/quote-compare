<?php

namespace App\Services;

use App\Models\AppUser;
use App\Models\Employee;
use Illuminate\Support\Facades\DB;

/**
 * คัดลอกข้อมูลพนักงานจาก Supavut Insight (DB `insight`) มาเก็บเป็นมิเรอร์ใน PR Compare
 *
 *   Bplus ──(Insight ดึงเองทุก 15 นาที)──> insight.employees / insight.app_users
 *                                                    │
 *                                          InsightMirror (ตัวนี้)
 *                                                    ▼
 *                                    pr_compare.employees / pr_compare.app_users
 *
 * หลักการ:
 *   - ทางเดียวเท่านั้น : อ่านจาก connection `insight` ห้ามเขียนกลับเด็ดขาด
 *   - จับคู่ด้วย `insight_id` (id ของแถวต้นทาง) → แถวไหนหายจาก Insight ก็ลบทิ้งจากมิเรอร์
 *   - รหัสผ่าน/รูป/ลายเซ็น คัดลอกมาทั้งหมด → ผู้ใช้ล็อกอิน PR Compare ด้วยรหัสเดียวกับ Insight
 *   - ไฟล์รูปไม่ได้ก๊อป (เปิดจาก public storage ของ Insight ผ่าน HTTP) ; ลายเซ็นเป็น data URL ในคอลัมน์ DB
 */
class InsightMirror
{
    /** connection ต้นทาง (config/database.php) */
    private const SOURCE = 'insight';

    /** คอลัมน์ที่คัดลอกจาก insight.employees (ตัด source_raw ที่ไม่ได้ใช้) */
    private const EMPLOYEE_COLUMNS = [
        'no', 'company', 'employee_code', 'license_id',
        'title', 'gender', 'name_th', 'surname_th', 'name_en',
        'job_code', 'job_th', 'job_en',
        'dept_code', 'dept_th', 'dept_en',
        'hire_date', 'probation_end_date', 'resign_date', 'emp_status',
    ];

    /** คอลัมน์ที่คัดลอกจาก insight.app_users (ตัด token ต่างๆ ที่เป็นของ session ฝั่ง Insight) */
    private const APP_USER_COLUMNS = [
        'id_thai_hash', 'company', 'employee_code', 'companies', 'password', 'role',
        'full_name_th', 'full_name_en', 'position', 'department', 'email',
        'profile_picture', 'signature', 'registered_at',
    ];

    /** ขนาด chunk — app_users เล็กกว่าเพราะ signature เป็น base64 ที่อาจใหญ่หลาย MB ต่อแถว */
    private const CHUNK_EMPLOYEES = 500;

    private const CHUNK_APP_USERS = 100;

    /**
     * ซิงค์ทั้งสองตาราง
     *
     * @param  bool  $fresh  ล้างมิเรอร์ทิ้งก่อนดึงใหม่ทั้งหมด
     * @return array{employees:array<string,mixed>,app_users:array<string,mixed>}
     */
    public function sync(bool $fresh = false): array
    {
        if ($fresh) {
            $this->wipe();
        }

        return [
            'employees' => $this->mirrorEmployees(),
            'app_users' => $this->mirrorAppUsers(),
        ];
    }

    /**
     * ล้างมิเรอร์ทิ้งทั้งหมด
     *
     * ต้องใช้เมื่อ **เปลี่ยนต้นทาง** (เช่นย้าย INSIGHT_DB_HOST จากเครื่องตัวเองไปเซิร์ฟเวอร์)
     * เพราะมิเรอร์จับคู่แถวด้วย `insight_id` ของ DB ต้นทางเดิม พอเปลี่ยน DB แล้ว id ชุดใหม่
     * จะไม่ตรงกับของเก่า ทำให้ upsert พยายาม INSERT ทับคนเดิมแล้วชน unique (id_thai_hash / company+employee_code)
     *
     * ปลอดภัยเพราะสองตารางนี้เป็นสำเนาล้วน ไม่มีข้อมูลที่ PR Compare เป็นเจ้าของ
     * ⚠️ ผู้ที่ล็อกอินค้างไว้จะหลุด session เพราะ `app_users.id` ถูกออกใหม่
     */
    public function wipe(): void
    {
        DB::transaction(static function (): void {
            DB::table('app_users')->delete();
            DB::table('employees')->delete();
        });
    }

    /** เช็คว่าต่อ DB ของ Insight ได้ไหม (ใช้แสดงสถานะในหน้า admin) */
    public function connectionOk(): bool
    {
        try {
            DB::connection(self::SOURCE)->select('SELECT 1');

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    /** จำนวนแถวฝั่งต้นทาง ไว้เทียบกับมิเรอร์ว่าตรงกันไหม */
    public function sourceCounts(): array
    {
        return [
            'employees' => (int) DB::connection(self::SOURCE)->table('employees')->count(),
            'app_users' => (int) DB::connection(self::SOURCE)->table('app_users')->count(),
        ];
    }

    /**
     * ดึงบัญชีเดียวจาก Insight ทันที (ใช้ตอนล็อกอินแล้วยังไม่มีในมิเรอร์ เช่น พนักงานเพิ่งเข้าใหม่)
     * resolve จากรหัสหลัก หรือรหัสในบริษัทใดบริษัทหนึ่งใน map `companies`
     */
    public function pullUserByCode(string $code): ?AppUser
    {
        $row = DB::connection(self::SOURCE)->table('app_users')
            ->where('employee_code', $code)
            ->orWhereRaw("JSON_SEARCH(companies, 'one', ?) IS NOT NULL", [$code])
            ->first();

        if (! $row) {
            return null;
        }

        $row = (array) $row;
        $payload = ['insight_id' => $row['id'], 'mirrored_at' => now()];
        foreach (self::APP_USER_COLUMNS as $col) {
            $payload[$col] = $row[$col] ?? null;
        }

        DB::table('app_users')->upsert(
            [$payload + ['created_at' => now(), 'updated_at' => now()]],
            ['insight_id'],
            array_merge(self::APP_USER_COLUMNS, ['mirrored_at', 'updated_at'])
        );

        return AppUser::where('insight_id', $row['id'])->first();
    }

    /**
     * ดึงเฉพาะแถวที่ Insight บอกว่าเปลี่ยน (ใช้กับ webhook — ไม่ต้องกวาดทั้งตาราง)
     *
     * id ที่ส่งมาแต่หาไม่เจอในต้นทาง = ถูกลบไปแล้ว จึงลบออกจากมิเรอร์ด้วย
     *
     * @param  array<int,int>  $ids  id ของแถวใน insight.app_users
     * @return array{updated:int,removed:int}
     */
    public function pullUsers(array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));

        if ($ids === []) {
            return ['updated' => 0, 'removed' => 0];
        }

        $rows = DB::connection(self::SOURCE)->table('app_users')
            ->select(array_merge(['id'], self::APP_USER_COLUMNS))
            ->whereIn('id', $ids)
            ->get();

        $payload = [];
        foreach ($rows as $row) {
            $row = (array) $row;
            $line = ['insight_id' => $row['id'], 'mirrored_at' => now()];

            foreach (self::APP_USER_COLUMNS as $col) {
                $line[$col] = $row[$col] ?? null;
            }

            $payload[] = $line + ['created_at' => now(), 'updated_at' => now()];
        }

        foreach (array_chunk($payload, self::CHUNK_APP_USERS) as $chunk) {
            DB::table('app_users')->upsert(
                $chunk,
                ['insight_id'],
                array_merge(self::APP_USER_COLUMNS, ['mirrored_at', 'updated_at'])
            );
        }

        // id ที่ส่งมาแต่ต้นทางไม่มีแล้ว = ลาออก/ถูกลบ
        $gone = array_diff($ids, $rows->pluck('id')->map('intval')->all());
        $removed = $gone === [] ? 0 : AppUser::whereIn('insight_id', $gone)->delete();

        return ['updated' => count($payload), 'removed' => $removed];
    }

    /**
     * ดึงเฉพาะพนักงานที่เปลี่ยน (คู่กับ pullUsers)
     *
     * @param  array<int,int>  $ids  id ของแถวใน insight.employees
     * @return array{updated:int,removed:int}
     */
    public function pullEmployees(array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));

        if ($ids === []) {
            return ['updated' => 0, 'removed' => 0];
        }

        $rows = DB::connection(self::SOURCE)->table('employees')
            ->select(array_merge(['id'], self::EMPLOYEE_COLUMNS))
            ->whereIn('id', $ids)
            ->get();

        $payload = [];
        foreach ($rows as $row) {
            $row = (array) $row;
            $line = ['insight_id' => $row['id'], 'mirrored_at' => now()];

            foreach (self::EMPLOYEE_COLUMNS as $col) {
                $line[$col] = $row[$col] ?? null;
            }

            $payload[] = $line + ['created_at' => now(), 'updated_at' => now()];
        }

        foreach (array_chunk($payload, self::CHUNK_EMPLOYEES) as $chunk) {
            DB::table('employees')->upsert(
                $chunk,
                ['insight_id'],
                array_merge(self::EMPLOYEE_COLUMNS, ['mirrored_at', 'updated_at'])
            );
        }

        $gone = array_diff($ids, $rows->pluck('id')->map('intval')->all());
        $removed = $gone === [] ? 0 : Employee::whereIn('insight_id', $gone)->delete();

        return ['updated' => count($payload), 'removed' => $removed];
    }

    /**
     * มิเรอร์ตาราง employees
     *
     * @return array{created:int,updated:int,removed:int,total:int,removed_list:array<int,array<string,string>>}
     */
    private function mirrorEmployees(): array
    {
        $existing = Employee::pluck('insight_id')->flip();   // insight_id => index
        $created = 0;
        $updated = 0;
        $seen = [];

        DB::connection(self::SOURCE)->table('employees')
            ->select(array_merge(['id'], self::EMPLOYEE_COLUMNS))
            ->orderBy('id')
            ->chunk(self::CHUNK_EMPLOYEES, function ($rows) use (&$created, &$updated, &$seen, $existing) {
                $payload = [];

                foreach ($rows as $row) {
                    $row = (array) $row;
                    $seen[] = $row['id'];
                    $existing->has($row['id']) ? $updated++ : $created++;

                    $line = ['insight_id' => $row['id'], 'mirrored_at' => now()];
                    foreach (self::EMPLOYEE_COLUMNS as $col) {
                        $line[$col] = $row[$col] ?? null;
                    }
                    $payload[] = $line + ['created_at' => now(), 'updated_at' => now()];
                }

                DB::table('employees')->upsert(
                    $payload,
                    ['insight_id'],
                    array_merge(self::EMPLOYEE_COLUMNS, ['mirrored_at', 'updated_at'])
                );
            });

        // แถวที่ไม่อยู่ใน Insight แล้ว → ลบออกจากมิเรอร์
        $goneQuery = Employee::whereNotIn('insight_id', $seen ?: [0]);
        $removedList = $goneQuery->get(['employee_code', 'name_th', 'surname_th', 'title', 'company'])
            ->map(fn (Employee $e): array => [
                'code' => (string) $e->employee_code,
                'name' => $e->fullNameTh() ?: '—',
                'company' => (string) $e->company,
            ])->all();
        $removed = $goneQuery->delete();

        return [
            'created' => $created,
            'updated' => $updated,
            'removed' => $removed,
            'total' => count($seen),
            'removed_list' => $removedList,
        ];
    }

    /**
     * มิเรอร์ตาราง app_users
     *
     * @return array{created:int,updated:int,removed:int,total:int,created_list:array<int,array<string,string>>,removed_list:array<int,array<string,string>>}
     */
    private function mirrorAppUsers(): array
    {
        $existing = AppUser::pluck('insight_id')->flip();
        $created = 0;
        $updated = 0;
        $seen = [];
        $createdList = [];

        DB::connection(self::SOURCE)->table('app_users')
            ->select(array_merge(['id'], self::APP_USER_COLUMNS))
            ->orderBy('id')
            ->chunk(self::CHUNK_APP_USERS, function ($rows) use (&$created, &$updated, &$seen, &$createdList, $existing) {
                $payload = [];

                foreach ($rows as $row) {
                    $row = (array) $row;
                    $seen[] = $row['id'];

                    if ($existing->has($row['id'])) {
                        $updated++;
                    } else {
                        $created++;
                        $createdList[] = [
                            'code' => (string) ($row['employee_code'] ?? ''),
                            'name' => (string) ($row['full_name_th'] ?: $row['full_name_en'] ?: '—'),
                            'company' => (string) ($row['company'] ?? ''),
                        ];
                    }

                    $line = ['insight_id' => $row['id'], 'mirrored_at' => now()];
                    foreach (self::APP_USER_COLUMNS as $col) {
                        $line[$col] = $row[$col] ?? null;
                    }
                    $payload[] = $line + ['created_at' => now(), 'updated_at' => now()];
                }

                DB::table('app_users')->upsert(
                    $payload,
                    ['insight_id'],
                    array_merge(self::APP_USER_COLUMNS, ['mirrored_at', 'updated_at'])
                );
            });

        $goneQuery = AppUser::whereNotIn('insight_id', $seen ?: [0]);
        $removedList = $goneQuery->get(['employee_code', 'full_name_th', 'full_name_en', 'company'])
            ->map(fn (AppUser $u): array => [
                'code' => (string) $u->employee_code,
                'name' => $u->displayName(),
                'company' => (string) $u->company,
            ])->all();
        $removed = $goneQuery->delete();

        return [
            'created' => $created,
            'updated' => $updated,
            'removed' => $removed,
            'total' => count($seen),
            'created_list' => $createdList,
            'removed_list' => $removedList,
        ];
    }
}
