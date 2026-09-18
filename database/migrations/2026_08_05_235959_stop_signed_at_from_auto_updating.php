<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ตัด `ON UPDATE CURRENT_TIMESTAMP` ออกจาก `pr_document_signatures.signed_at`
 *
 * 🔴 กับดักของ MySQL/MariaDB: คอลัมน์ `TIMESTAMP NOT NULL` **ตัวแรกของตาราง**
 *    จะได้ `DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP` แถมมาเอง
 *    (เมื่อ `explicit_defaults_for_timestamp = OFF`) แปลว่าแก้คอลัมน์ไหนก็ตามในแถวนั้น
 *    **วันที่ลงนามจะถูกเขียนทับด้วยเวลาปัจจุบันเงียบๆ** — ลายเซ็นเป็นหลักฐาน ห้ามเพี้ยน
 *
 * เจอตอนซิงก์ `step_no` หลังแทรกขั้นยืนยันรายการ (D-075) : อัปเดตแค่เลขลำดับ
 * แต่วันที่ลงนามของ Mgr./CEO กระโดดมาเป็นวันนี้
 *
 * ต้องรันก่อน migration ที่แทรกขั้นยืนยันรายการเสมอ ชื่อไฟล์จึงตั้งให้มาก่อน
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            return;   // sqlite (เทสต์) ไม่มีพฤติกรรมนี้
        }

        /*
        | เปลี่ยนชนิดเป็น DATETIME ไม่ใช่แค่ถอด attribute ทิ้ง
        |
        | `MODIFY ... TIMESTAMP NOT NULL` เฉยๆ ไม่พอ — MariaDB ใส่ DEFAULT/ON UPDATE
        | กลับมาให้ใหม่ทุกครั้ง (กติกาเดียวกับตอน CREATE TABLE)
        | ส่วน DATETIME ไม่มีพฤติกรรมอัตโนมัตินี้เลย ค่าที่เก็บไว้แล้วไม่เปลี่ยน
        */
        DB::statement('ALTER TABLE pr_document_signatures MODIFY signed_at DATETIME NOT NULL');
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql' || ! Schema::hasTable('pr_document_signatures')) {
            return;
        }

        DB::statement(
            'ALTER TABLE pr_document_signatures MODIFY signed_at TIMESTAMP NOT NULL '.
            'DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP'
        );
    }
};
