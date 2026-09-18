<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * รายการสินค้า 1 แถวในใบเปรียบเทียบ
 *
 * `unit` เป็นหน่วยนับที่ต่อท้ายจำนวน (เช่น "20 Ea") — ฟอร์ม Excel เดิมไม่มีช่องนี้
 * แต่ระบบ PR เดิมใช้จริงหนักมาก (Ea · Pcs · Box · Roll · เส้น) เลยเก็บไว้
 */
class PrItem extends Model
{
    protected $table = 'pr_items';

    protected $fillable = ['pr_document_id', 'row_no', 'item_code', 'description', 'qty', 'unit'];

    protected $casts = [
        'row_no' => 'integer',
        'qty' => 'decimal:2',
    ];

    public function document(): BelongsTo
    {
        return $this->belongsTo(PrDocument::class, 'pr_document_id');
    }

    public function prices(): HasMany
    {
        return $this->hasMany(PrPrice::class, 'pr_item_id');
    }

    /** ราคาของผู้ขายรายนี้สำหรับแถวนี้ */
    public function priceFor(PrSupplier $supplier): ?PrPrice
    {
        return $this->prices->firstWhere('pr_supplier_id', $supplier->id);
    }

    /**
     * Total Amount ของช่องนี้ = Qty × Unit Price
     *
     * ใช้ราคาหลังต่อรอง (Rev.1) ก่อนถ้ามี เพราะเป็นราคาที่ใช้ตัดสินใจจริง
     */
    public function amountFor(PrSupplier $supplier): float
    {
        $price = $this->priceFor($supplier);
        $unit = $price?->effectivePrice();

        if ($unit === null || $this->qty === null) {
            return 0.0;
        }

        return (float) $this->qty * $unit;
    }

    /** จำนวน + หน่วย ไว้ประทับลงเอกสาร เช่น "20 Ea" */
    public function qtyText(): string
    {
        if ($this->qty === null) {
            return '';
        }

        $qty = rtrim(rtrim(number_format((float) $this->qty, 2, '.', ','), '0'), '.');

        return trim($qty.' '.trim((string) $this->unit));
    }

    public function isBlank(): bool
    {
        return trim((string) $this->description) === '' && $this->qty === null;
    }
}
