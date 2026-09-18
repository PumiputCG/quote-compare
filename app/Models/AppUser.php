<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Auth\User as Authenticatable;

/**
 * บัญชีล็อกอินของ PR Compare — **มิเรอร์จาก insight.app_users**
 *
 * เจ้าของข้อมูลคือ Insight ; ตารางนี้เป็นสำเนาทางเดียว
 *   - แก้ชื่อ/ตำแหน่ง/รหัสผ่าน/รูป/ลายเซ็น ให้ไปทำที่ Insight แล้วรอ insight:sync
 *   - PR Compare ห้ามเขียนทับข้อมูลเหล่านี้เอง (จะโดน sync รอบถัดไปทับอยู่ดี)
 *
 * กติกาที่สืบทอดมาจาก Insight:
 *   - ตัวตน = เลขบัตรประชาชน (id_thai_hash) : 1 คน = 1 บัญชี แม้อยู่หลายบริษัท
 *   - login = รหัสพนักงาน + password ; resolve ได้ทั้งรหัสหลักและรหัสในแต่ละบริษัท (map `companies`)
 *   - password เก็บ plaintext ไม่ hash (decision D-007 ของเจ้าของ) จึงไม่มี $hidden
 *   - system admin: employee_code = Admin
 */
class AppUser extends Authenticatable
{
    protected $table = 'app_users';

    protected $fillable = [
        'insight_id', 'id_thai_hash', 'company', 'employee_code', 'companies', 'password', 'role',
        'full_name_th', 'full_name_en', 'position', 'department', 'email',
        'profile_picture', 'signature', 'registered_at', 'mirrored_at',
    ];

    protected $casts = [
        'companies' => 'array',
        'registered_at' => 'datetime',
        'mirrored_at' => 'datetime',
    ];

    /** role: บังคับให้เป็น admin หรือ user เท่านั้น */
    protected function role(): Attribute
    {
        return Attribute::make(
            set: static fn (?string $value): string => strtolower(trim((string) $value)) === 'admin' ? 'admin' : 'user',
        );
    }

    /** พนักงานต้นทางในตาราง employees (อ้างด้วยรหัสหลัก) */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'employee_code', 'employee_code');
    }

    /** บริษัททั้งหมดที่บัญชีนี้คุม (จาก company CSV) */
    public function companyList(): array
    {
        return array_values(array_filter(array_map('trim', explode(',', (string) $this->company))));
    }

    public function isAdmin(): bool
    {
        return $this->role === 'admin';
    }

    public function displayName(): string
    {
        return trim((string) ($this->full_name_th ?: $this->full_name_en ?: $this->employee_code));
    }

    /**
     * URL รูปโปรไฟล์ — ไฟล์จริงอยู่ใน public storage ของ Insight
     *
     * คืน null เมื่อบัญชีนี้ยังไม่ได้อัปโหลดรูป — ถ้าต้องการ URL ที่ใช้แสดงผลได้เสมอ ให้ใช้ avatarUrl()
     */
    public function profilePictureUrl(): ?string
    {
        $path = ltrim(str_replace('\\', '/', trim((string) $this->profile_picture)), '/');

        if ($path === '') {
            return null;
        }

        $encoded_path = implode('/', array_map(
            static fn (string $segment): string => rawurlencode(rawurldecode($segment)),
            explode('/', $path)
        ));

        return rtrim((string) config('app.insight_asset_url'), '/').'/'.$encoded_path;
    }

    /** รูปสำรองเมื่อยังไม่ได้อัปโหลดรูปโปรไฟล์ */
    public static function defaultAvatarUrl(): string
    {
        return asset('img/avatar-default.png');
    }

    /** URL รูปที่เอาไปใส่ <img> ได้เลย — ไม่มีรูปจริงก็ได้รูป default */
    public function avatarUrl(): string
    {
        return $this->profilePictureUrl() ?? static::defaultAvatarUrl();
    }
}
