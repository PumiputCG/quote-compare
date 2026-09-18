<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * เปิดให้ขั้นในเส้นทางไม่ต้องระบุตัวคนได้
 *
 * ขั้นที่เป็น Role `user` คือ **ช่องว่างที่เว้นไว้** — Purchasing เป็นคนเลือกว่าจะส่งให้ใคร
 * ตอนสร้างเอกสารจริง admin จึงกำหนดแค่ว่า "ตรงนี้มีขั้น User" ไม่ได้ระบุชื่อคน
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('document_roles', function (Blueprint $table) {
            $table->string('id_thai_hash')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('document_roles', function (Blueprint $table) {
            $table->string('id_thai_hash')->nullable(false)->change();
        });
    }
};
