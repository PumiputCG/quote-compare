<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ราคาที่ผู้ขายรายหนึ่งเสนอสำหรับสินค้าแถวหนึ่ง — จุดตัดของ item × supplier
 *
 * `unit_price`      ราคาเสนอครั้งแรก (Purchasing กรอกตอนจัดทำเอกสาร)
 * `unit_price_rev`  ราคาหลังต่อรอง = ช่อง "Unit Price Rev.1" ในฟอร์ม
 *                   (Purchasing กรอกตอนขั้น "ต่อรองราคา" ไม่ใช่ตอนจัดทำ)
 * `rev_none`        ผู้ใช้พิมพ์ `-` = ยืนยันว่าไม่มีการต่อรองราคา
 *                   ต่างจาก NULL เฉยๆ ที่แปลว่ายังไม่ได้กรอก
 */
class PrPrice extends Model
{
    protected $table = 'pr_prices';

    protected $fillable = ['pr_item_id', 'pr_supplier_id', 'unit_price', 'unit_price_rev', 'rev_none'];

    protected $casts = [
        'unit_price' => 'decimal:2',
        'unit_price_rev' => 'decimal:2',
        'rev_none' => 'boolean',
    ];

    /** ช่อง Rev.1 กรอกแล้วหรือยัง — `-` ก็ถือว่ากรอกแล้ว */
    public function revisionAnswered(): bool
    {
        return $this->unit_price_rev !== null || $this->rev_none;
    }

    /** ค่าที่ต้องแสดงในช่อง Rev.1 */
    public function revisionInputValue(): string
    {
        if ($this->unit_price_rev !== null) {
            return number_format((float) $this->unit_price_rev, 2);
        }

        return $this->rev_none ? '-' : '';
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(PrItem::class, 'pr_item_id');
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(PrSupplier::class, 'pr_supplier_id');
    }

    /** ราคาที่ใช้คำนวณจริง — ต่อรองแล้วใช้ราคาใหม่ */
    public function effectivePrice(): ?float
    {
        if ($this->unit_price_rev !== null) {
            return (float) $this->unit_price_rev;
        }

        return $this->unit_price === null ? null : (float) $this->unit_price;
    }
}
