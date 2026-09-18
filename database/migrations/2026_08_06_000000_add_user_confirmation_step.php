<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * เพิ่มขั้น "User · ยืนยันรายการ" เข้าเส้นทางเอกสาร ต่อจากขั้นต่อรองราคา (D-075)
 *
 * เส้นทางเปลี่ยนจาก 5 ขั้นเป็น 6 ขั้น:
 *   1 จัดทำเอกสาร · 2 คัดเลือกรายการ · 3 ต่อรองราคา · **4 ยืนยันรายการ** · 5-6 ลงนามอนุมัติ
 *
 * ที่ต้องแก้ข้อมูลเดิมด้วยมี 2 อย่าง:
 *   - เอกสารที่ค้างรอผู้บริหารลงนาม ถูกดึงกลับมาขั้นยืนยันรายการตามที่ Manager สั่ง
 *   - `rejected_{ลำดับขั้น}` ของขั้นที่อยู่หลังจุดแทรก ต้องเลื่อนเลขตาม
 *     ไม่งั้นใบที่ปฏิเสธตอนลงนามจะกลายเป็นปฏิเสธตอนยืนยันรายการ
 *
 * ⚠️ ไม่ลบลายเซ็นของใบที่ถูกดึงกลับ — ลายเซ็นเป็นหลักฐาน
 *    ผู้ลงนามคนเดิมจะประทับทับของตัวเองได้เมื่อเอกสารเดินมาถึงอีกครั้ง
 */
return new class extends Migration
{
    public function up(): void
    {
        $negotiate = DB::table('document_roles')->where('duty', 'negotiate')->orderBy('step_no')->first();
        $exists = DB::table('document_roles')->where('duty', 'confirm')->exists();

        // เส้นทางที่ admin ตั้งไว้ไม่ตรงรูปแบบมาตรฐาน ปล่อยให้ admin เพิ่มการ์ดเอง
        if ($exists || ! $negotiate) {
            return;
        }

        $position = DB::table('document_roles')->where('step_no', '<=', $negotiate->step_no)->count() + 1;

        DB::transaction(function () use ($negotiate, $position): void {
            DB::table('document_roles')
                ->where('step_no', '>', $negotiate->step_no)
                ->update(['step_no' => DB::raw('step_no + 1')]);

            DB::table('document_roles')->insert([
                'step_no' => $negotiate->step_no + 1,
                'role' => 'user',
                'duty' => 'confirm',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            // เลขลำดับขั้นในสถานะปฏิเสธ — เลื่อนจากหลังมาหน้าเพื่อไม่ให้เลขชนกันเอง
            for ($step = 20; $step >= $position; $step--) {
                DB::table('pr_documents')
                    ->where('status', 'rejected_'.$step)
                    ->update(['status' => 'rejected_'.($step + 1)]);
            }

            // ใบที่ค้างรอผู้บริหารลงนาม ต้องผ่านการยืนยันของผู้ขอซื้อก่อน
            DB::table('pr_documents')
                ->whereIn('status', ['waiting_mgr', 'waiting_ceo'])
                ->update(['status' => 'confirming']);

            /*
            | `pr_document_signatures.step_no` เป็น snapshot ตอนลงนาม
            | ขั้นลงนามเลื่อนจาก 4-5 เป็น 5-6 แล้ว ค่าเก่าจึงชี้ลำดับผิด
            | (ตัวผูกจริงคือ document_role_id — อันนี้แค่ซิงก์เลขให้เรียงถูก)
            */
            foreach (DB::table('document_roles')->get() as $role) {
                DB::table('pr_document_signatures')
                    ->where('document_role_id', $role->id)
                    ->update([
                        'step_no' => $role->step_no,
                        // เขียนค่าเดิมทับตัวเอง กัน ON UPDATE CURRENT_TIMESTAMP ของ MySQL
                        // เผื่อฐานข้อมูลที่ยังไม่ได้แก้สคีมา (ดู migration 2026_08_05_235959)
                        'signed_at' => DB::raw('signed_at'),
                    ]);
            }
        });
    }

    public function down(): void
    {
        $confirm = DB::table('document_roles')->where('duty', 'confirm')->orderBy('step_no')->first();

        if (! $confirm) {
            return;
        }

        $position = DB::table('document_roles')->where('step_no', '<=', $confirm->step_no)->count();

        DB::transaction(function () use ($confirm, $position): void {
            DB::table('pr_documents')->where('status', 'confirming')->update(['status' => 'waiting_mgr']);
            DB::table('pr_document_signatures')->where('duty', 'confirm')->delete();
            DB::table('document_roles')->where('id', $confirm->id)->delete();

            DB::table('document_roles')
                ->where('step_no', '>', $confirm->step_no)
                ->orderBy('step_no')
                ->update(['step_no' => DB::raw('step_no - 1')]);

            for ($step = $position; $step <= 20; $step++) {
                DB::table('pr_documents')
                    ->where('status', 'rejected_'.($step + 1))
                    ->update(['status' => 'rejected_'.$step]);
            }
        });
    }
};
