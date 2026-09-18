<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ขั้นต่อรองราคามีใบเสนอราคาใหม่หรือไม่
 *
 * `null`  = ฝ่ายจัดซื้อยังไม่ได้ตอบ
 * `true`  = มี -> เปิดฟอร์มแนบไฟล์เฉพาะบริษัทที่ผู้ขอซื้อเลือก
 * `false` = ไม่มี -> หน้าผู้ลงนามขึ้นข้อความแทนช่องแนบไฟล์
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pr_documents', function (Blueprint $table): void {
            $table->boolean('has_negotiation_quote')->nullable()->after('comment');
        });
    }

    public function down(): void
    {
        Schema::table('pr_documents', function (Blueprint $table): void {
            $table->dropColumn('has_negotiation_quote');
        });
    }
};
