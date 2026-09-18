<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ตารางมิเรอร์ข้อมูลพนักงานของ PR Compare
 *
 * เจ้าของข้อมูลจริงคือ **Supavut Insight** (DB `insight`) ซึ่งดึงจาก Bplus ทุก 15 นาที
 * PR Compare คัดลอกมาเก็บไว้เอง (mirror) เพื่อให้:
 *   - ล็อกอิน/แสดงผลเร็ว ไม่ต้อง join ข้าม DB ทุก request
 *   - ยังใช้งานต่อได้แม้ Insight DB ล่มชั่วคราว
 *
 * ⚠️ ตารางเหล่านี้เป็น "สำเนาทางเดียว" — ห้ามแก้ไขจากฝั่ง PR Compare
 *    แก้ที่ Insight ที่เดียว แล้วรอ insight:sync คัดลอกมาทับ (ดู App\Services\InsightMirror)
 *
 * ⚠️ PDPA — ห้ามเพิ่มคอลัมน์เงินเดือน/ภาษี/เลขผู้เสียภาษี ลงตารางเหล่านี้เด็ดขาด
 */
return new class extends Migration
{
    public function up(): void
    {
        // ── 1) employees : มิเรอร์ insight.employees (ตัด source_raw ที่ไม่ได้ใช้) ──
        Schema::create('employees', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('insight_id')->unique();   // id ต้นทางใน insight.employees
            $table->unsignedInteger('no')->nullable()->index();

            $table->string('company', 30)->index();
            $table->string('employee_code', 30);
            $table->string('license_id', 30)->nullable();         // เลขบัตรประชาชน (= รหัสผ่านเริ่มต้น)

            $table->string('title', 30)->nullable();
            $table->string('gender', 10)->nullable();
            $table->string('name_th', 100)->nullable();
            $table->string('surname_th', 100)->nullable();
            $table->string('name_en', 150)->nullable();

            $table->string('job_code', 30)->nullable();
            $table->string('job_th', 191)->nullable();
            $table->string('job_en', 191)->nullable();

            $table->string('dept_code', 20)->nullable()->index();
            $table->string('dept_th', 191)->nullable();
            $table->string('dept_en', 191)->nullable();

            $table->date('hire_date')->nullable();
            $table->date('probation_end_date')->nullable();
            $table->date('resign_date')->nullable();
            $table->string('emp_status', 10)->nullable()->index(); // 1=ทำงาน, 2=ลาออก

            $table->timestamp('mirrored_at')->nullable();          // เวลาที่คัดลอกมาล่าสุด
            $table->timestamps();

            $table->unique(['company', 'employee_code']);
        });

        // ── 2) app_users : มิเรอร์ insight.app_users (ตารางที่ใช้ล็อกอินจริง) ──────
        Schema::create('app_users', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('insight_id')->unique();   // id ต้นทางใน insight.app_users

            $table->string('id_thai_hash')->nullable()->unique(); // เลขบัตรประชาชน (ตัวตนหลัก) — plaintext
            $table->string('company')->index();                   // บริษัทที่คุม (CSV ได้หลายบริษัท)
            $table->string('employee_code', 30)->index();         // รหัสหลักไว้แสดงผล
            $table->json('companies')->nullable();                // map บริษัท -> รหัสพนักงาน (ใช้ resolve ตอนล็อกอิน)
            $table->string('password')->nullable();               // plaintext (ตาม D-007 ของ Insight)
            $table->string('role', 50)->default('user');          // admin / user

            $table->string('full_name_th', 255)->nullable();
            $table->string('full_name_en', 255)->nullable();
            $table->string('position', 255)->nullable();
            $table->string('department', 255)->nullable();
            $table->string('email', 255)->nullable();

            $table->string('profile_picture')->nullable();        // path ในสตอเรจของ Insight เช่น profiles/xxx.jpg
            $table->longText('signature')->nullable();            // data URL PNG (มากับ DB เลย)

            $table->timestamp('registered_at')->nullable();
            $table->timestamp('mirrored_at')->nullable();
            $table->rememberToken();
            $table->timestamps();
        });

        // ── 3) sync_logs : ประวัติการซิงค์จาก Insight ────────────────────────────
        Schema::create('sync_logs', function (Blueprint $table) {
            $table->id();
            $table->string('source', 20);            // manual = admin กดปุ่ม, schedule = อัตโนมัติ, login = ตอนล็อกอิน
            $table->boolean('ok')->default(true);
            $table->text('message')->nullable();
            $table->json('detail')->nullable();      // {employees:{...}, app_users:{...}}
            $table->timestamp('ran_at')->nullable()->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sync_logs');
        Schema::dropIfExists('app_users');
        Schema::dropIfExists('employees');
    }
};
