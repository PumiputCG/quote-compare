<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * เก็บ "หน้าที่" ของแต่ละขั้นแยกจาก Role
 *
 * Role เดียวกันทำคนละหน้าที่ได้ — Purchasing เข้ามา 2 รอบ รอบแรก `create` (สร้างเอกสาร)
 * รอบสอง `negotiate` (ต่อราคา) ; admin เป็นคนเลือกเองตอนเพิ่มการ์ด
 *
 * เดิมเดาจากลำดับ (Purchasing ใบแรก = สร้างเอกสาร) ซึ่งผิดทันทีถ้า admin สลับลำดับการ์ด
 * ค่าที่ใช้ได้ของแต่ละ Role ดูที่ App\Models\DocumentRole::DUTIES
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('document_roles', function (Blueprint $table) {
            $table->string('duty', 20)->default('sign')->after('role');
        });
    }

    public function down(): void
    {
        Schema::table('document_roles', function (Blueprint $table) {
            $table->dropColumn('duty');
        });
    }
};
