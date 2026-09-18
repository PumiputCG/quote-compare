<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * เก็บเหตุผลการปฏิเสธไว้กับใบ — ไม่ลบเอกสารทิ้ง
 *
 * ระบบ PR เดิมใช้สถานะ `1:Cancel` แล้วเก็บทุกแถวไว้ครบ ไม่เคยลบ
 * ที่นี่ก็เหมือนกัน: `status` เป็น `rejected_{ขั้นที่ปฏิเสธ}` แล้วจดว่าใครปฏิเสธเพราะอะไร
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pr_documents', function (Blueprint $table): void {
            $table->string('rejected_reason', 500)->nullable()->after('status');
            $table->string('rejected_by_name')->nullable()->after('rejected_reason');
            $table->timestamp('rejected_at')->nullable()->after('rejected_by_name');
        });
    }

    public function down(): void
    {
        Schema::table('pr_documents', function (Blueprint $table): void {
            $table->dropColumn(['rejected_reason', 'rejected_by_name', 'rejected_at']);
        });
    }
};
