<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * สกุลเงินของใบเปรียบเทียบราคา — เลือกตอนจัดทำเอกสาร
 *
 * ใช้กำกับ Total Amount ทั้งคอลัมน์รายแถวและยอดรวมท้ายตาราง
 * ค่าเริ่มต้นเป็นบาท เพราะซื้อในประเทศเป็นส่วนใหญ่
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pr_documents', function (Blueprint $table): void {
            $table->string('currency', 3)->default('THB')->after('company');
        });
    }

    public function down(): void
    {
        Schema::table('pr_documents', function (Blueprint $table): void {
            $table->dropColumn('currency');
        });
    }
};
