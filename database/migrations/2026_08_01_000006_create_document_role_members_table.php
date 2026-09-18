<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ให้หนึ่งขั้นในเส้นทางมีพนักงานได้หลายคน
 *
 * เดิม `document_roles.id_thai_hash` เก็บได้คนเดียวต่อขั้น
 * ของจริงจุดเดียวมีได้หลายคน (เช่น ฝ่ายจัดซื้อ 2 คนช่วยกันจัดทำเอกสาร
 * หรือมีผู้จัดการเซ็นแทนกันได้) จึงแยกออกเป็นตารางลูก
 *
 * ขั้น Role `user` ไม่มีสมาชิก — Purchasing เลือกคนเองตอนสร้างเอกสาร
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_role_members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('document_role_id')->constrained('document_roles')->cascadeOnDelete();
            $table->string('id_thai_hash')->index();   // ตัวตนของพนักงาน (= app_users.id_thai_hash)
            $table->timestamps();

            $table->unique(['document_role_id', 'id_thai_hash']);   // คนเดิมในขั้นเดิม ใส่ซ้ำไม่ได้
        });

        // ย้ายคนเดิม (1 คนต่อขั้น) เข้าตารางใหม่ก่อนทิ้งคอลัมน์
        $rows = DB::table('document_roles')
            ->whereNotNull('id_thai_hash')
            ->where('id_thai_hash', '!=', '')
            ->get(['id', 'id_thai_hash']);

        foreach ($rows as $row) {
            DB::table('document_role_members')->insert([
                'document_role_id' => $row->id,
                'id_thai_hash' => $row->id_thai_hash,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        Schema::table('document_roles', function (Blueprint $table) {
            $table->dropColumn('id_thai_hash');
        });
    }

    public function down(): void
    {
        Schema::table('document_roles', function (Blueprint $table) {
            $table->string('id_thai_hash')->nullable()->index()->after('duty');
        });

        // คืนค่าได้แค่คนแรกของแต่ละขั้น เพราะโครงเดิมเก็บได้คนเดียว
        $rows = DB::table('document_role_members')->orderBy('id')->get();

        foreach ($rows as $row) {
            DB::table('document_roles')
                ->where('id', $row->document_role_id)
                ->whereNull('id_thai_hash')
                ->update(['id_thai_hash' => $row->id_thai_hash]);
        }

        Schema::dropIfExists('document_role_members');
    }
};
