<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * จำไว้ว่าผู้ใช้พิมพ์ `-` ในช่อง Unit Price Rev.1 = "ไม่มีการต่อรองราคา"
 *
 * เดิม `-` ถูกแปลงเป็น NULL ตอนบันทึก ระบบจึงแยกไม่ออกระหว่าง
 * "ยังไม่ได้กรอก" กับ "กรอกแล้วว่าไม่ต่อรอง" · พอ refresh หรือแปะลายเซ็น
 * ช่องก็กลับมาว่างเหมือนไม่เคยกรอก
 *
 * ยอดรวมยังคิดเหมือนเดิม (ไม่มีราคา Rev.1 = ใช้ราคาเดิม) แค่จำการกรอกไว้
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pr_prices', function (Blueprint $table): void {
            $table->boolean('rev_none')->default(false)->after('unit_price_rev');
        });
    }

    public function down(): void
    {
        Schema::table('pr_prices', function (Blueprint $table): void {
            $table->dropColumn('rev_none');
        });
    }
};
