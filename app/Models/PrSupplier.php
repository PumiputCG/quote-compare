<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * ผู้ขาย 1 เจ้าในใบเปรียบเทียบ = 1 คอลัมน์ในฟอร์ม
 *
 * `slot` 1-3 คือตำแหน่งคอลัมน์ในฟอร์ม Excel — เก็บครบ 3 แถวเสมอ
 * แต่โชว์เฉพาะที่ `slot <= pr_documents.supplier_count`
 */
class PrSupplier extends Model
{
    protected $table = 'pr_suppliers';

    protected $fillable = [
        'pr_document_id', 'slot', 'name',
        'lead_time', 'term_of_payment', 'remark', 'is_selected',
        'has_negotiation_quote',
    ];

    /**
     * `has_negotiation_quote` — ฝ่ายจัดซื้อตอบทีละเจ้าว่าต่อรองแล้วได้ใบเสนอราคาใหม่ไหม
     *   null = ยังไม่ตอบ · true = มี (เปิดช่องแนบไฟล์) · false = ไม่มี (ขึ้นข้อความแทน)
     */
    protected $casts = [
        'slot' => 'integer',
        'is_selected' => 'boolean',
        'has_negotiation_quote' => 'boolean',
    ];

    public function document(): BelongsTo
    {
        return $this->belongsTo(PrDocument::class, 'pr_document_id');
    }

    public function prices(): HasMany
    {
        return $this->hasMany(PrPrice::class, 'pr_supplier_id');
    }

    /** ใบเสนอราคาที่แนบมาของเจ้านี้ */
    public function attachments(): HasMany
    {
        return $this->hasMany(PrAttachment::class, 'pr_supplier_id')->orderBy('id');
    }

    /** ยังไม่ได้กรอกอะไรเลย */
    public function isBlank(): bool
    {
        return trim((string) $this->name) === '';
    }

    public function displayName(): string
    {
        return trim((string) $this->name) ?: '—';
    }
}
