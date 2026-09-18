<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ตัวอย่างของที่เคยซื้อด้วยรหัสนี้ — เอาไว้เป็นคำอธิบายในเมนูเลือกรหัส
 *
 * ทำไมไม่ใช้ `name`: หนึ่งรหัสคือ "หมวด" ที่ใช้กับของหลายแบบ (D-042)
 * `FIX-IT-26-001` ใช้กับ Zenbook · Intel i5 · จอมอนิเตอร์ — โชว์ชื่อเดียวจะเข้าใจผิด
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('item_codes', function (Blueprint $table): void {
            $table->string('examples', 255)->nullable()->after('name');
        });
    }

    public function down(): void
    {
        Schema::table('item_codes', function (Blueprint $table): void {
            $table->dropColumn('examples');
        });
    }
};
