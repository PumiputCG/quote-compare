<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * สิทธิ์เข้าใช้งาน PR Compare แยกตาม "ตำแหน่ง"
 *
 * ต่างจากตาราง employees / app_users ตรงที่ตารางนี้ **เป็นของ PR Compare เอง**
 * ไม่ได้มิเรอร์มาจาก Insight — admin ติ๊กเลือกเองว่าตำแหน่งไหนล็อกอินเข้าระบบนี้ได้
 *
 * กติกา (ดู App\Models\PositionAccess::allows)
 *   - ตารางว่าง = ยังไม่เคยตั้งค่า -> อนุญาตทุกคน (กันล็อกตัวเองตอนเพิ่งติดตั้ง)
 *   - ตั้งค่าแล้ว -> ตำแหน่งต้องมีแถวและ can_login = true เท่านั้น
 *   - role = admin เข้าได้เสมอไม่ว่าตั้งค่าอย่างไร (กันล็อกตัวเองออกจากระบบ)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('position_access', function (Blueprint $table) {
            $table->id();
            $table->string('position', 255)->unique();   // '' = พนักงานที่ไม่ได้ระบุตำแหน่ง
            $table->boolean('can_login')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('position_access');
    }
};
