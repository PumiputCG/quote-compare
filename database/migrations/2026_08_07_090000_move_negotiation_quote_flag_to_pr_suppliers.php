<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ย้ายคำตอบ "มีใบเสนอราคาหลังต่อรองไหม" จากระดับเอกสาร → ระดับผู้ขาย
 *
 * เดิมตอบครั้งเดียวทั้งใบ (`pr_documents.has_negotiation_quote`) แล้วเปิดช่องแนบพร้อมกัน 3 เจ้า
 * แต่ของจริงต่อรองแล้วบางเจ้าส่งใบเสนอราคาใหม่มา บางเจ้าไม่ส่ง — ต้องแยกตอบทีละบริษัท
 *
 *   null  = ยังไม่ตอบ · true = มี (เปิดช่องแนบของเจ้านั้น) · false = ไม่มี (ขึ้นข้อความแทน)
 *
 * คอลัมน์เดิมฝั่ง `pr_documents` **ยังเก็บไว้** เผื่อต้องย้อนกลับ — โค้ดเลิกอ่านแล้ว
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pr_suppliers', function (Blueprint $table) {
            $table->boolean('has_negotiation_quote')->nullable()->after('is_selected');
        });

        // เอกสารที่ตอบไว้แล้วก่อนแยกรายบริษัท — ให้ทุกเจ้าในใบนั้นได้คำตอบเดิมไปก่อน
        DB::table('pr_documents')
            ->whereNotNull('has_negotiation_quote')
            ->orderBy('id')
            ->chunkById(200, function ($documents): void {
                foreach ($documents as $document) {
                    DB::table('pr_suppliers')
                        ->where('pr_document_id', $document->id)
                        ->update(['has_negotiation_quote' => $document->has_negotiation_quote]);
                }
            });
    }

    public function down(): void
    {
        Schema::table('pr_suppliers', function (Blueprint $table) {
            $table->dropColumn('has_negotiation_quote');
        });
    }
};
