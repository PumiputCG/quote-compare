<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ใบเปรียบเทียบราคาผู้ขาย — โครงข้อมูลหลักของระบบ
 *
 * หัวใจ: แยก **รายการสินค้า (แถว) × ผู้ขาย (คอลัมน์) → ราคา (จุดตัด)**
 * ต่างจาก Excel ที่คอลัมน์ผู้ขายตายตัว — แบบนี้เพิ่มผู้ขายหรือเพิ่มรอบต่อราคาได้โดยไม่ต้องแก้ตาราง
 *
 *   pr_documents ──┬── pr_suppliers ──┬── pr_attachments   (ใบเสนอราคาแนบตามผู้ขาย)
 *                  │                  └── pr_prices ───┐
 *                  └── pr_items ──────────────────────┘   (ราคา = item × supplier)
 *
 * อ้างพนักงานด้วย `id_thai_hash` ไม่ใช่ `app_users.id` เพราะ `insight:sync --fresh`
 * ล้าง app_users แล้วออก id ใหม่ทั้งชุด (กติกาเดียวกับ document_roles)
 */
return new class extends Migration
{
    public function up(): void
    {
        // ── 1) หัวเอกสาร ────────────────────────────────────────────────
        Schema::create('pr_documents', function (Blueprint $table) {
            $table->id();
            $table->string('pr_number', 40)->index();          // พิมพ์เอง เช่น PR26-2200
            $table->date('document_date');                     // ระบบประทับตอนสร้าง
            $table->string('company', 30)->default('SUPAVUT INDUSTRY');

            // ผู้ขอซื้อ — Purchasing เลือกตัวคน แต่ที่ประทับลงเอกสารคือ "แผนก" ของเขา
            $table->string('requester_id_thai_hash')->nullable()->index();
            $table->string('requester_name', 255)->nullable();  // snapshot กันชื่อเปลี่ยนทีหลัง
            $table->string('requester_department', 255)->nullable();

            $table->text('purpose')->nullable();
            $table->unsignedTinyInteger('supplier_count')->default(3);   // 1-3 ตามฟอร์ม
            $table->text('comment')->nullable();                          // ช่อง Comment ท้ายฟอร์ม

            $table->string('status', 20)->default('draft')->index();      // draft / sent_user / ...
            $table->string('created_by_id_thai_hash')->nullable()->index();
            $table->timestamps();
        });

        // ── 2) ผู้ขาย (1-3 เจ้าต่อใบ ตามฟอร์ม) ──────────────────────────
        Schema::create('pr_suppliers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pr_document_id')->constrained('pr_documents')->cascadeOnDelete();
            $table->unsignedTinyInteger('slot');               // 1-3 = ตำแหน่งคอลัมน์ในฟอร์ม

            $table->string('name', 255)->nullable();
            $table->string('lead_time', 120)->nullable();
            $table->string('term_of_payment', 191)->nullable();
            $table->text('remark')->nullable();
            $table->boolean('is_selected')->default(false);    // ☐ ในฟอร์ม — ขั้น User ติ๊ก

            $table->timestamps();
            $table->unique(['pr_document_id', 'slot']);
        });

        // ── 3) รายการสินค้า ─────────────────────────────────────────────
        Schema::create('pr_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pr_document_id')->constrained('pr_documents')->cascadeOnDelete();
            $table->unsignedInteger('row_no')->default(1);     // เลข No. ในฟอร์ม

            $table->string('item_code', 60)->nullable();       // เลือกจากระบบ PR เดิม หรือพิมพ์เอง
            $table->text('description')->nullable();
            $table->decimal('qty', 14, 2)->nullable();
            $table->string('unit', 20)->nullable();            // Ea · Pcs · Box · เส้น (ต่อท้ายเลข)

            $table->timestamps();
            $table->index(['pr_document_id', 'row_no']);
        });

        // ── 4) ราคา = จุดตัดของ item × supplier ─────────────────────────
        Schema::create('pr_prices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pr_item_id')->constrained('pr_items')->cascadeOnDelete();
            $table->foreignId('pr_supplier_id')->constrained('pr_suppliers')->cascadeOnDelete();

            $table->decimal('unit_price', 16, 2)->nullable();       // ราคาเสนอครั้งแรก
            $table->decimal('unit_price_rev', 16, 2)->nullable();   // Rev.1 — กรอกตอน "ต่อรองราคา"

            $table->timestamps();
            $table->unique(['pr_item_id', 'pr_supplier_id']);
        });

        // ── 5) ไฟล์แนบ — ผูกกับผู้ขายแต่ละเจ้า (ใบเสนอราคา) ────────────
        Schema::create('pr_attachments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pr_supplier_id')->constrained('pr_suppliers')->cascadeOnDelete();

            $table->string('original_name', 255);
            $table->string('path', 255);                       // เก็บใน storage/app/pr-attachments
            $table->string('mime', 120)->nullable();
            $table->unsignedBigInteger('size')->default(0);
            $table->string('uploaded_by_id_thai_hash')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pr_attachments');
        Schema::dropIfExists('pr_prices');
        Schema::dropIfExists('pr_items');
        Schema::dropIfExists('pr_suppliers');
        Schema::dropIfExists('pr_documents');
    }
};
