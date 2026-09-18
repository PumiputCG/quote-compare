<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ทะเบียนรหัสสินค้า (Item Code) — ใช้ให้ Purchasing เลือกหมวดแล้วรันเลขต่อจากของเดิม
 *
 * โครงรหัสที่องค์กรใช้จริง: `[หมวดหลัก]-[หมวดย่อย]-[กลุ่ม]-[ลำดับ]`
 *
 *      SIR - FRM - FAC - 090
 *       │     │     │     └── seq    เลขลำดับ (ท่อนสุดท้าย)
 *       │     │     └──────── group  แผนก/ปี เช่น FAC · ADM · LAB · 25 · 26
 *       │     └────────────── sub    หมวดย่อย
 *       └──────────────────── family หมวดหลัก (SIR ของสิ้นเปลือง · FIX ทรัพย์สินถาวร ...)
 *
 * `prefix` = ทุกท่อนยกเว้นท่อนสุดท้าย → ใช้เป็นกุญแจว่าจะรันเลขต่อจากอะไร
 * รหัสที่ไม่มีเลขท้าย (เช่น `MODIFY` · `Freight-Export`) เก็บได้ โดย seq = null
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('item_codes', function (Blueprint $table) {
            $table->id();
            $table->string('code', 80)->unique();          // รหัสเต็ม เช่น SIR-FRM-FAC-090
            $table->string('name', 500)->nullable();       // ชื่อสินค้าล่าสุดที่ใช้รหัสนี้

            $table->string('family', 20)->index();         // SIR · FIX · MV · SIM · TP ...
            $table->string('sub', 30)->nullable()->index(); // FRM · UNF · IT ...
            $table->string('prefix', 70)->index();          // ทุกท่อนยกเว้นท่อนท้าย
            $table->unsignedInteger('seq')->nullable();     // ท่อนท้ายที่เป็นตัวเลข
            $table->unsignedTinyInteger('seq_width')->default(3);  // 090 -> กว้าง 3 หลัก

            $table->unsignedInteger('used_count')->default(0);     // เคยถูกใช้กี่ครั้ง
            $table->string('source', 20)->default('legacy');       // legacy = นำเข้าจากระบบเดิม
            $table->timestamps();

            $table->index(['prefix', 'seq']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('item_codes');
    }
};
