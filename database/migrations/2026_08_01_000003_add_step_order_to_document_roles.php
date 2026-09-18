<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * เปลี่ยน `document_roles` จาก "จัดกลุ่มตาม Role" เป็น "สายโซ่ที่เรียงลำดับได้"
 *
 * เดิม: 1 แถว = คน + Role ; unique(id_thai_hash, role) ห้ามซ้ำ
 * ใหม่: 1 แถว = **1 ขั้นในเส้นทาง** เรียงด้วย `step_no` ; admin ต่อการ์ดเองได้ตามต้องการ
 *
 * ⚠️ ต้องทิ้ง unique เดิม เพราะเส้นทางจริงมี **Purchasing 2 รอบ**
 *    (ขั้น 1 สร้างเอกสาร · ขั้น 3 กลับมาต่อราคา — ดูข้อ 2.4 ใน PR_COMPARE_SYSTEM.md)
 *    ถ้าเก็บ unique ไว้ คนเดิมจะใส่เป็น Purchasing ได้แค่ขั้นเดียว
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('document_roles', function (Blueprint $table) {
            $table->dropUnique(['id_thai_hash', 'role']);
            $table->unsignedInteger('step_no')->default(0)->index()->after('id');
        });
    }

    public function down(): void
    {
        Schema::table('document_roles', function (Blueprint $table) {
            $table->dropIndex(['step_no']);
            $table->dropColumn('step_no');
            $table->unique(['id_thai_hash', 'role']);
        });
    }
};
