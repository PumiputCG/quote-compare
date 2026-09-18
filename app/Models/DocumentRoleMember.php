<?php

namespace App\Models;

use App\Services\ResignationGuard;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * พนักงานหนึ่งคนที่ถูกกำหนดไว้ในขั้นหนึ่งของเส้นทางเอกสาร
 *
 * หนึ่งขั้น (DocumentRole) มีได้หลายคน — เช่นฝ่ายจัดซื้อ 2 คนช่วยกันจัดทำเอกสาร
 * หรือผู้จัดการหลายคนที่เซ็นแทนกันได้
 *
 * ผูกกับพนักงานด้วย `id_thai_hash` ไม่ใช่ `app_users.id` เพราะ `insight:sync --fresh`
 * ล้าง app_users แล้วออก id ใหม่ทั้งชุด (ดู App\Models\DocumentRole)
 */
class DocumentRoleMember extends Model
{
    protected $table = 'document_role_members';

    protected $fillable = ['document_role_id', 'id_thai_hash'];

    public function step(): BelongsTo
    {
        return $this->belongsTo(DocumentRole::class, 'document_role_id');
    }

    public function appUser(): BelongsTo
    {
        return $this->belongsTo(AppUser::class, 'id_thai_hash', 'id_thai_hash');
    }

    /**
     * ประวัติพนักงาน — ยังอยู่แม้ลาออกแล้ว
     *
     * Insight ลบบัญชี `app_users` ทิ้งตอนพนักงานลาออก แต่แถวใน `employees` ยังอยู่
     * (emp_status = 2) จึงยังตามหาชื่อได้ · `id_thai_hash` = เลขบัตรประชาชน = `license_id`
     */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'id_thai_hash', 'license_id');
    }

    /** ชื่อที่เอาไปแสดง — บัญชีถูกลบแล้วก็ยังได้ชื่อจากประวัติพนักงาน */
    public function displayName(): string
    {
        return $this->appUser?->displayName()
            ?: ($this->employee?->fullNameTh() ?: 'บัญชีถูกลบแล้ว');
    }

    /** รหัสพนักงาน — แสดงคู่กับชื่อเสมอ เพราะชื่อซ้ำกันได้ */
    public function employeeCode(): string
    {
        return trim((string) ($this->appUser?->employee_code ?: $this->employee?->employee_code)) ?: '—';
    }

    /** ลาออกไปแล้วหรือยัง — คนที่ลาออกทำงานในเส้นทางเอกสารต่อไม่ได้ */
    public function hasResigned(): bool
    {
        return ResignationGuard::isResigned($this->id_thai_hash);
    }

    public function avatarUrl(): string
    {
        return $this->appUser?->avatarUrl() ?? AppUser::defaultAvatarUrl();
    }
}
