<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Role ในเส้นทางเอกสาร PR — **ตารางของ PR Compare เอง** ไม่ได้มิเรอร์จาก Insight
 *
 * เส้นทางที่ Manager กำหนด (2026-08-01) :
 *   1) Purchasing        สร้างเอกสาร + ใส่รายละเอียด -> ส่งให้ User ที่เลือก
 *   2) User              เลือกของที่ Purchasing จัดหามา -> ส่งกลับ Purchasing
 *   3) Purchasing        ต่อราคา -> ส่งต่อ Asst.Mgr./Mgr. Purchasing
 *   4) Mgr. Purchasing   เซ็น -> ส่งต่อ CEO
 *   5) CEO               เซ็น -> เอกสารสำเร็จ ทุก Role เห็นและดาวน์โหลดได้
 *
 * ⚠️ Role `user` **ไม่ต้องเก็บในตารางนี้** — พนักงานทุกคนเป็น User อยู่แล้วโดยอัตโนมัติ
 *
 * ทำไมอ้างด้วย `id_thai_hash` ไม่ใช่ `app_users.id`:
 *   `insight:sync --fresh` ล้าง `app_users` แล้วออก id ใหม่ทั้งชุด ถ้าผูกด้วย id จะหลุดหมด
 *   ส่วนเลขบัตรประชาชนเป็นตัวตนหลักที่คงที่ (ดูกติกาใน App\Models\AppUser)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_roles', function (Blueprint $table) {
            $table->id();
            $table->string('id_thai_hash')->index();   // ตัวตนของพนักงาน (= app_users.id_thai_hash)
            $table->string('role', 40)->index();       // purchasing | mgr_purchasing | ceo
            $table->timestamps();

            $table->unique(['id_thai_hash', 'role']);  // คนเดิม role เดิม ใส่ซ้ำไม่ได้
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_roles');
    }
};
