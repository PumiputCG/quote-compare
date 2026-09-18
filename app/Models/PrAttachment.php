<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ไฟล์แนบของผู้ขายรายหนึ่ง (ใบเสนอราคา) — แนบได้หลายไฟล์ หลายนามสกุล
 *
 * ไฟล์เก็บใน `storage/app/pr-attachments/` ซึ่งอยู่นอกเขตเว็บ
 * เสิร์ฟผ่าน route ที่ต้องล็อกอินเท่านั้น (ห้ามวางใน public/)
 */
class PrAttachment extends Model
{
    protected $table = 'pr_attachments';

    public const STAGE_CREATE = 'create';

    public const STAGE_NEGOTIATE = 'negotiate';

    /** นามสกุลที่ยอมให้แนบ */
    public const ALLOWED = ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'csv', 'png', 'jpg', 'jpeg', 'webp', 'gif'];

    /** ขนาดสูงสุดต่อไฟล์ (KB) */
    public const MAX_KB = 10240;

    protected $fillable = [
        'pr_supplier_id', 'stage', 'original_name', 'path', 'mime', 'size', 'uploaded_by_id_thai_hash',
    ];

    protected $casts = ['size' => 'integer'];

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(PrSupplier::class, 'pr_supplier_id');
    }

    public function extension(): string
    {
        return strtolower(pathinfo($this->original_name, PATHINFO_EXTENSION));
    }

    public function isImage(): bool
    {
        return in_array($this->extension(), ['png', 'jpg', 'jpeg', 'webp', 'gif'], true);
    }

    /**
     * ชนิดไฟล์แบบหยาบ — ใช้เลือกไอคอนในหน้าจอ
     *
     * นามสกุลที่ใช้ไอคอนร่วมกันได้ก็รวมเป็นชนิดเดียว
     * (doc/docx = Word · xls/xlsx/csv = Excel)
     *
     * image | pdf | word | excel | file
     */
    public function kind(): string
    {
        return match ($this->extension()) {
            'png', 'jpg', 'jpeg', 'webp', 'gif' => 'image',
            'pdf' => 'pdf',
            'doc', 'docx' => 'word',
            'xls', 'xlsx', 'csv' => 'excel',
            default => 'file',
        };
    }

    /**
     * ไอคอนของชนิดไฟล์ที่มีไฟล์ภาพจริง (public/img/filetype)
     *
     * คืน null เมื่อไม่มีไอคอนเฉพาะ — หน้าจอจะใช้ไอคอนกลางแทน
     * ส่วนรูปภาพไม่ใช้ไอคอน เพราะแสดงภาพย่อของไฟล์เองอยู่แล้ว
     */
    public function iconUrl(): ?string
    {
        $kind = $this->kind();

        return in_array($kind, ['pdf', 'word', 'excel'], true)
            ? asset('img/filetype/'.$kind.'.png')
            : null;
    }

    public function sizeText(): string
    {
        if ($this->size >= 1048576) {
            return number_format($this->size / 1048576, 1).' MB';
        }

        return number_format(max($this->size, 1) / 1024, 0).' KB';
    }
}
