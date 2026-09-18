<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * พนักงาน — **มิเรอร์จาก insight.employees** (ซึ่ง Insight mirror มาจาก Bplus EMP_MAIN อีกที)
 *
 * Bplus → Insight → PR Compare : ข้อมูลไหลทางเดียวเท่านั้น ห้ามเขียนย้อนกลับ
 * เก็บพนักงานทุกคนรวมคนลาออก (emp_status = 2) เพื่อดูย้อนหลังได้
 */
class Employee extends Model
{
    protected $table = 'employees';

    protected $fillable = [
        'insight_id', 'no',
        'company', 'employee_code', 'license_id',
        'title', 'gender', 'name_th', 'surname_th', 'name_en',
        'job_code', 'job_th', 'job_en',
        'dept_code', 'dept_th', 'dept_en',
        'hire_date', 'probation_end_date', 'resign_date', 'emp_status',
        'mirrored_at',
    ];

    protected $casts = [
        'no' => 'integer',
        'hire_date' => 'date',
        'probation_end_date' => 'date',
        'resign_date' => 'date',
        'mirrored_at' => 'datetime',
    ];

    /** บัญชีล็อกอินของพนักงานคนนี้ (มีเฉพาะคน active) */
    public function appUser(): HasOne
    {
        return $this->hasOne(AppUser::class, 'employee_code', 'employee_code');
    }

    /** URL รูปโปรไฟล์ — ไม่มีบัญชีหรือยังไม่ได้อัปโหลดรูป ก็ได้รูป default */
    public function avatarUrl(): string
    {
        return $this->appUser?->avatarUrl() ?? AppUser::defaultAvatarUrl();
    }

    /** ลาออกแล้วหรือไม่ (ตาม Bplus PRI_STATUS) */
    public function isResigned(): bool
    {
        return (string) $this->emp_status === '2';
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('emp_status', '!=', '2');
    }

    public function scopeResigned(Builder $query): Builder
    {
        return $query->where('emp_status', '2');
    }

    public function fullNameTh(): string
    {
        $sur = trim((string) $this->surname_th);
        if ($sur === '*' || $sur === '-') {
            $sur = '';   // placeholder ใน Bplus = ไม่มีนามสกุล
        }

        return trim(($this->title ?? '').($this->name_th ?? '').' '.$sur);
    }

    /**
     * ชื่อ-สกุลอังกฤษ — ใช้ name_en ถ้ามี ; ถ้าว่างเติมจากชื่อ Latin ใน name_th/surname_th
     * (แรงงานต่างชาติมักกรอกชื่ออังกฤษไว้ในช่องชื่อไทย ทำให้ name_en ว่าง)
     */
    public function fullNameEn(): string
    {
        $en = trim((string) $this->name_en);
        if ($en !== '') {
            return $en;
        }

        $parts = [];
        foreach ([$this->name_th, $this->surname_th] as $p) {
            $p = trim((string) $p);
            if ($p !== '' && $p !== '*' && $p !== '-') {
                $parts[] = $p;
            }
        }

        return trim(implode(' ', $parts));
    }

    /** ชื่อแผนกไทยแบบตัดวงเล็บตัวย่อท้ายออก เช่น "เทคโนโลยีสารสนเทศ (IT)" → "เทคโนโลยีสารสนเทศ" */
    public function deptThClean(): string
    {
        return trim(preg_replace('/\s*\([^()]*\)\s*$/u', '', (string) ($this->dept_th ?? '')));
    }
}
