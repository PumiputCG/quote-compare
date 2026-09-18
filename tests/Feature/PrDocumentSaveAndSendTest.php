<?php

namespace Tests\Feature;

use App\Http\Middleware\Authenticate;
use App\Models\AppUser;
use App\Models\DocumentRole;
use App\Models\Employee;
use App\Models\ItemCode;
use App\Models\PrDocument;
use App\Services\ResignationGuard;
use Carbon\Carbon;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class PrDocumentSaveAndSendTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['app.url' => 'http://localhost']);
        URL::forceRootUrl('http://localhost');

        // ตัดขาดจากระบบ PR เดิม — ไม่งั้นเลขที่ทดสอบจะวิ่งตามข้อมูลจริงบนเซิร์ฟเวอร์
        // (ItemCode::legacySeqMap ต่อไม่ได้ก็คืนค่าว่าง แล้วใช้ทะเบียนในเครื่องแทน)
        config(['database.connections.pr_legacy' => ['driver' => 'sqlite', 'database' => ':memory:']]);

        Schema::create('app_users', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('insight_id')->unique();
            $table->string('id_thai_hash')->nullable()->unique();
            $table->string('company');
            $table->string('employee_code');
            $table->json('companies')->nullable();
            $table->string('password')->nullable();
            $table->string('role')->default('user');
            $table->string('full_name_th')->nullable();
            $table->string('full_name_en')->nullable();
            $table->string('position')->nullable();
            $table->string('department')->nullable();
            $table->string('profile_picture')->nullable();
            $table->longText('signature')->nullable();
            $table->timestamps();
        });

        // ประวัติพนักงาน — ใช้ตรวจว่าคนในเส้นทางลาออกไปแล้วหรือยัง (ResignationGuard)
        Schema::create('employees', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('insight_id')->nullable();
            $table->string('company')->nullable();
            $table->string('employee_code')->nullable();
            $table->string('license_id')->nullable()->index();
            $table->string('title')->nullable();
            $table->string('name_th')->nullable();
            $table->string('surname_th')->nullable();
            $table->string('name_en')->nullable();
            $table->string('dept_th')->nullable();
            $table->string('dept_en')->nullable();
            $table->date('hire_date')->nullable();
            $table->date('probation_end_date')->nullable();
            $table->date('resign_date')->nullable();
            $table->string('emp_status')->nullable()->default('1');
            $table->timestamps();
        });

        Schema::create('document_roles', function (Blueprint $table): void {
            $table->id();
            $table->unsignedInteger('step_no');
            $table->string('role');
            $table->string('duty');
            $table->timestamps();
        });

        Schema::create('document_role_members', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('document_role_id');
            $table->string('id_thai_hash');
            $table->timestamps();
        });

        Schema::create('pr_documents', function (Blueprint $table): void {
            $table->id();
            $table->string('pr_number')->nullable();
            $table->date('document_date');
            $table->string('company')->default('SUPAVUT INDUSTRY');
            $table->string('currency', 3)->default('THB');
            $table->string('requester_id_thai_hash')->nullable();
            $table->string('requester_name')->nullable();
            $table->string('requester_department')->nullable();
            $table->text('purpose')->nullable();
            $table->unsignedTinyInteger('supplier_count')->default(3);
            $table->text('comment')->nullable();
            $table->boolean('has_negotiation_quote')->nullable();
            $table->string('status')->default('draft');
            $table->text('rejected_reason')->nullable();
            $table->string('rejected_by_name')->nullable();
            $table->timestamp('rejected_at')->nullable();
            $table->string('created_by_id_thai_hash')->nullable();
            $table->timestamps();
            $table->unique('pr_number');
        });

        Schema::create('pr_number_sequences', function (Blueprint $table): void {
            $table->string('series')->primary();
            $table->unsignedInteger('last_number')->default(0);
            $table->timestamps();
        });

        Schema::create('item_codes', function (Blueprint $table): void {
            $table->id();
            $table->string('code')->unique();
            $table->string('name')->nullable();
            $table->string('examples')->nullable();
            $table->string('family')->index();
            $table->string('sub')->nullable()->index();
            $table->string('prefix')->index();
            $table->unsignedInteger('seq')->nullable();
            $table->unsignedTinyInteger('seq_width')->default(3);
            $table->unsignedInteger('used_count')->default(0);
            $table->string('source')->default('legacy');
            $table->timestamps();
        });

        Schema::create('pr_suppliers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('pr_document_id');
            $table->unsignedTinyInteger('slot');
            $table->string('name')->nullable();
            $table->string('lead_time')->nullable();
            $table->string('term_of_payment')->nullable();
            $table->text('remark')->nullable();
            $table->boolean('is_selected')->default(false);
            $table->boolean('has_negotiation_quote')->nullable();
            $table->timestamps();
        });

        Schema::create('pr_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('pr_document_id');
            $table->unsignedInteger('row_no')->default(1);
            $table->string('item_code')->nullable();
            $table->text('description')->nullable();
            $table->decimal('qty', 14, 2)->nullable();
            $table->string('unit')->nullable();
            $table->timestamps();
        });

        Schema::create('pr_prices', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('pr_item_id');
            $table->foreignId('pr_supplier_id');
            $table->decimal('unit_price', 16, 2)->nullable();
            $table->decimal('unit_price_rev', 16, 2)->nullable();
            $table->boolean('rev_none')->default(false);
            $table->timestamps();
            $table->unique(['pr_item_id', 'pr_supplier_id']);
        });

        Schema::create('pr_attachments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('pr_supplier_id');
            $table->string('stage')->default('create');
            $table->string('original_name');
            $table->string('path');
            $table->string('mime')->nullable();
            $table->unsignedBigInteger('size')->default(0);
            $table->string('uploaded_by_id_thai_hash')->nullable();
            $table->timestamps();
        });

        Schema::create('pr_document_signatures', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('pr_document_id');
            $table->foreignId('document_role_id')->nullable();
            $table->unsignedInteger('step_no');
            $table->string('role');
            $table->string('duty');
            $table->string('signer_id_thai_hash');
            $table->string('signer_name');
            $table->longText('signature_data');
            $table->timestamp('signed_at');
            $table->timestamps();
        });
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_new_documents_reserve_pr_numbers_and_deleted_draft_number_is_reused(): void
    {
        Carbon::setTestNow('2026-08-03 09:00:00');

        $creator = AppUser::create([
            'insight_id' => 100,
            'id_thai_hash' => 'number-creator-hash',
            'company' => 'SUPAVUT INDUSTRY',
            'employee_code' => 'PUR100',
            'role' => 'user',
            'full_name_th' => 'ผู้สร้างเลขเอกสาร',
        ]);
        $step = DocumentRole::create([
            'step_no' => 1,
            'role' => 'purchasing',
            'duty' => 'create',
        ]);
        $step->addMember($creator->id_thai_hash);

        $this->withSession([Authenticate::SESSION_KEY => $creator->id])
            ->post('/documents')
            ->assertRedirect('/documents/1');
        $this->withSession([Authenticate::SESSION_KEY => $creator->id])
            ->post('/documents')
            ->assertRedirect('/documents/2');

        $this->assertDatabaseHas('pr_documents', ['id' => 1, 'pr_number' => 'PR26-0001', 'status' => 'draft']);
        $this->assertDatabaseHas('pr_documents', ['id' => 2, 'pr_number' => 'PR26-0002', 'status' => 'draft']);
        $this->assertDatabaseHas('pr_number_sequences', ['series' => 'PR26', 'last_number' => 2]);

        $this->withSession([Authenticate::SESSION_KEY => $creator->id])
            ->get('/documents/1')
            ->assertOk()
            ->assertSeeText('PR26-0001 #ใบร่าง');

        $this->withSession([Authenticate::SESSION_KEY => $creator->id])
            ->get('/work/create')
            ->assertOk()
            ->assertSeeText('PR26-0001 #ใบร่าง')
            ->assertSeeText('PR26-0002 #ใบร่าง')
            ->assertSee('pill pill-mute">ใบร่าง', false);

        $this->withSession([Authenticate::SESSION_KEY => $creator->id])
            ->post('/documents/2/delete')
            ->assertRedirect('/work/create');
        $this->withSession([Authenticate::SESSION_KEY => $creator->id])
            ->post('/documents')
            ->assertRedirect('/documents/3');

        $this->assertDatabaseHas('pr_documents', ['id' => 3, 'pr_number' => 'PR26-0002', 'status' => 'draft']);
    }

    /**
     * รหัสหมวดเดิมใช้ซ้ำได้ · ระบบห้ามรันเลขท้ายให้ (D-042)
     *
     * `FIX-IT-26-001` ในระบบเก่าถูกใช้กับ Zenbook / Intel i5 / จอมอนิเตอร์
     * เลือกซ้ำในเอกสารใหม่ต้องได้รหัสเดิม ไม่ใช่ `-002`
     */
    public function test_existing_item_code_is_reused_as_is(): void
    {
        $creator = AppUser::create([
            'insight_id' => 101,
            'id_thai_hash' => 'item-code-creator-hash',
            'company' => 'SUPAVUT INDUSTRY',
            'employee_code' => 'PUR101',
            'role' => 'user',
            'full_name_th' => 'ผู้เลือกรหัสสินค้า',
        ]);
        DocumentRole::create(['step_no' => 1, 'role' => 'purchasing', 'duty' => 'create'])
            ->addMember($creator->id_thai_hash);

        ItemCode::create(ItemCode::parse('FIX-IT-26-001') + [
            'name' => 'จอมอนิเตอร์',
            'source' => 'legacy',
            'used_count' => 4,
        ]);

        $document = PrDocument::create([
            'pr_number' => 'PR26-0001',
            'document_date' => '2026-08-03',
            'company' => 'SUPAVUT INDUSTRY',
            'supplier_count' => 3,
            'status' => 'draft',
            'created_by_id_thai_hash' => $creator->id_thai_hash,
        ]);
        $document->ensureSuppliers();
        $item = $document->items()->create(['row_no' => 1]);

        $this
            ->withSession([Authenticate::SESSION_KEY => $creator->id])
            ->post("/documents/{$document->id}", [
                'item_count' => 1,
                'supplier_count' => 3,
                'items' => [
                    $item->id => [
                        'item_code' => 'FIX-IT-26-001',
                        'description' => 'Zenbook 14 AMD',
                        'prices' => [],
                    ],
                ],
            ])
            ->assertRedirect();

        $this->assertSame('FIX-IT-26-001', $item->fresh()->item_code);
        $this->assertDatabaseMissing('item_codes', ['code' => 'FIX-IT-26-002']);
        // ทะเบียนจากระบบเก่าห้ามถูกเขียนทับด้วยรายละเอียดใบนี้
        $this->assertDatabaseHas('item_codes', ['code' => 'FIX-IT-26-001', 'name' => 'จอมอนิเตอร์']);
    }

    /** กรอกรหัสใหม่เองได้ กรณี ERP ออกรหัสมาแล้วยังไม่ได้ import (D-043) */
    public function test_typed_new_item_code_is_registered(): void
    {
        $creator = AppUser::create([
            'insight_id' => 105,
            'id_thai_hash' => 'item-code-typed-new-hash',
            'company' => 'SUPAVUT INDUSTRY',
            'employee_code' => 'PUR105',
            'role' => 'user',
            'full_name_th' => 'ผู้กรอกรหัสใหม่',
        ]);
        DocumentRole::create(['step_no' => 1, 'role' => 'purchasing', 'duty' => 'create'])
            ->addMember($creator->id_thai_hash);

        $document = PrDocument::create([
            'pr_number' => 'PR26-0012',
            'document_date' => '2026-08-03',
            'company' => 'SUPAVUT INDUSTRY',
            'supplier_count' => 3,
            'status' => 'draft',
            'created_by_id_thai_hash' => $creator->id_thai_hash,
        ]);
        $document->ensureSuppliers();
        $item = $document->items()->create(['row_no' => 1]);

        $this
            ->withSession([Authenticate::SESSION_KEY => $creator->id])
            ->post("/documents/{$document->id}", [
                'item_count' => 1,
                'supplier_count' => 3,
                'items' => [
                    $item->id => [
                        'item_code' => 'FIX-IT-27-001',
                        'description' => 'เครื่องพิมพ์เลเซอร์',
                        'prices' => [],
                    ],
                ],
            ])
            ->assertRedirect();

        $this->assertSame('FIX-IT-27-001', $item->fresh()->item_code);
        $this->assertDatabaseHas('item_codes', [
            'code' => 'FIX-IT-27-001',
            'source' => 'prcompare',
            // ตัวอย่างของหมวดมาจากที่ Purchasing พิมพ์จริง ไม่ลอกจากรหัสอื่น (D-035)
            'examples' => 'เครื่องพิมพ์เลเซอร์',
        ]);
    }

    /** ลบแถวสินค้าในใบร่างแล้วรหัสที่เพิ่งลงทะเบียนต้องไม่ค้างในทะเบียน */
    public function test_removing_a_draft_item_releases_the_registered_code(): void
    {
        $creator = AppUser::create([
            'insight_id' => 102,
            'id_thai_hash' => 'item-code-release-hash',
            'company' => 'SUPAVUT INDUSTRY',
            'employee_code' => 'PUR102',
            'role' => 'user',
            'full_name_th' => 'ผู้คืนรหัส',
        ]);
        DocumentRole::create(['step_no' => 1, 'role' => 'purchasing', 'duty' => 'create'])
            ->addMember($creator->id_thai_hash);

        ItemCode::create(ItemCode::parse('SIR-FRM-FAC-166') + ['source' => 'legacy']);

        $document = PrDocument::create([
            'pr_number' => 'PR26-0009',
            'document_date' => '2026-08-03',
            'company' => 'SUPAVUT INDUSTRY',
            'supplier_count' => 3,
            'status' => 'draft',
            'created_by_id_thai_hash' => $creator->id_thai_hash,
        ]);
        $document->ensureSuppliers();
        $keep = $document->items()->create(['row_no' => 1]);
        $drop = $document->items()->create(['row_no' => 2, 'item_code' => 'SIR-FRM-FAC-167']);

        ItemCode::create(ItemCode::parse('SIR-FRM-FAC-167') + ['source' => 'prcompare']);

        $this
            ->withSession([Authenticate::SESSION_KEY => $creator->id])
            ->post("/documents/{$document->id}", [
                'item_count' => 1,
                'supplier_count' => 3,
                'items' => [$keep->id => ['prices' => []]],
            ])
            ->assertRedirect();

        $this->assertModelMissing($drop);
        $this->assertDatabaseMissing('item_codes', ['code' => 'SIR-FRM-FAC-167']);
        // ทะเบียนจากระบบเก่าต้องไม่ถูกลบตาม
        $this->assertDatabaseHas('item_codes', ['code' => 'SIR-FRM-FAC-166']);
    }

    /** รหัสที่มีอักขระแปลกยังต้องถูกปฏิเสธ แม้จะกรอกเองได้แล้ว (D-043) */
    public function test_item_codes_with_invalid_characters_are_rejected(): void
    {
        $creator = AppUser::create([
            'insight_id' => 103,
            'id_thai_hash' => 'item-code-typed-hash',
            'company' => 'SUPAVUT INDUSTRY',
            'employee_code' => 'PUR103',
            'role' => 'user',
            'full_name_th' => 'ผู้พิมพ์รหัสเอง',
        ]);
        DocumentRole::create(['step_no' => 1, 'role' => 'purchasing', 'duty' => 'create'])
            ->addMember($creator->id_thai_hash);

        $document = PrDocument::create([
            'pr_number' => 'PR26-0010',
            'document_date' => '2026-08-03',
            'company' => 'SUPAVUT INDUSTRY',
            'supplier_count' => 3,
            'status' => 'draft',
            'created_by_id_thai_hash' => $creator->id_thai_hash,
        ]);
        $document->ensureSuppliers();
        $item = $document->items()->create(['row_no' => 1]);

        $this
            ->withSession([Authenticate::SESSION_KEY => $creator->id])
            ->post("/documents/{$document->id}", [
                'item_count' => 1,
                'supplier_count' => 3,
                'items' => [$item->id => ['item_code' => 'รหัสมั่ว-999', 'prices' => []]],
            ])
            ->assertSessionHasErrors();

        $this->assertNull($item->fresh()->item_code);
    }

    /** ลายเซ็น Purchase ขั้นจัดทำเอกสารยังลบได้ เพราะ Process เริ่มตอนผู้ขอซื้อลงนาม */
    public function test_purchase_signature_does_not_block_draft_deletion(): void
    {
        $creator = AppUser::create([
            'insight_id' => 104,
            'id_thai_hash' => 'delete-guard-hash',
            'company' => 'SUPAVUT INDUSTRY',
            'employee_code' => 'PUR104',
            'role' => 'user',
            'full_name_th' => 'ผู้ลบเอกสาร',
        ]);
        $step = DocumentRole::create(['step_no' => 1, 'role' => 'purchasing', 'duty' => 'create']);
        $step->addMember($creator->id_thai_hash);

        $document = PrDocument::create([
            'pr_number' => null,
            'document_date' => '2026-08-03',
            'company' => 'SUPAVUT INDUSTRY',
            'supplier_count' => 3,
            'status' => 'draft',
            'created_by_id_thai_hash' => $creator->id_thai_hash,
        ]);

        $this->assertTrue($document->canDelete());

        $document->signatures()->create([
            'document_role_id' => $step->id,
            'step_no' => 1,
            'role' => 'purchasing',
            'duty' => 'create',
            'signer_id_thai_hash' => $creator->id_thai_hash,
            'signer_name' => 'ผู้ลงนาม',
            'signature_data' => 'data:image/png;base64,AAAA',
            'signed_at' => now(),
        ]);

        $this->assertTrue($document->fresh()->canDelete());

        $this
            ->withSession([Authenticate::SESSION_KEY => $creator->id])
            ->post("/documents/{$document->id}/delete")
            ->assertRedirect('/work/create');

        $this->assertModelMissing($document);
    }

    /** ส่งให้ผู้ขอซื้อแล้วแต่ยังไม่ลงนาม Purchase ยังลบใบร่างได้ */
    public function test_sent_document_can_be_deleted_before_requester_signature(): void
    {
        $creator = AppUser::create([
            'insight_id' => 106,
            'id_thai_hash' => 'sent-delete-hash',
            'company' => 'SUPAVUT INDUSTRY',
            'employee_code' => 'PUR106',
            'role' => 'user',
            'full_name_th' => 'ผู้ลบใบที่ส่งแล้ว',
        ]);
        DocumentRole::create(['step_no' => 1, 'role' => 'purchasing', 'duty' => 'create'])
            ->addMember($creator->id_thai_hash);

        $document = PrDocument::create([
            'pr_number' => null,
            'document_date' => '2026-08-03',
            'company' => 'SUPAVUT INDUSTRY',
            'supplier_count' => 3,
            'status' => 'sent_user',
            'created_by_id_thai_hash' => $creator->id_thai_hash,
        ]);

        $this->assertTrue($document->canDelete());

        $this
            ->withSession([Authenticate::SESSION_KEY => $creator->id])
            ->post("/documents/{$document->id}/delete")
            ->assertRedirect('/work/create');

        $this->assertModelMissing($document);
    }

    /** ผู้ขอซื้อเลือกและลงนามก่อน แล้วจึงยืนยันส่งเอกสารไปแท็บต่อรองราคา */
    public function test_requester_signs_selection_before_sending_to_negotiation(): void
    {
        Carbon::setTestNow('2026-08-04 09:00:00');

        $creator = AppUser::create([
            'insight_id' => 107,
            'id_thai_hash' => 'selection-signer-hash',
            'company' => 'SUPAVUT INDUSTRY',
            'employee_code' => 'PUR107',
            'role' => 'user',
            'full_name_th' => 'ผู้คัดเลือกรายการ',
            'department' => 'ฝ่ายจัดซื้อ',
            'signature' => 'data:image/png;base64,dGVzdA==',
        ]);
        DocumentRole::create(['step_no' => 1, 'role' => 'purchasing', 'duty' => 'create'])
            ->addMember($creator->id_thai_hash);
        $selectStep = DocumentRole::create(['step_no' => 2, 'role' => 'user', 'duty' => 'select']);
        DocumentRole::create(['step_no' => 3, 'role' => 'purchasing', 'duty' => 'negotiate'])
            ->addMember($creator->id_thai_hash);

        $this->assertSame(['create', 'negotiate', 'select'], array_keys(DocumentRole::menuFor($creator)));

        PrDocument::create([
            'pr_number' => 'PR26-0001',
            'document_date' => '2026-08-03',
            'company' => 'SUPAVUT INDUSTRY',
            'supplier_count' => 3,
            'status' => 'negotiating',
            'created_by_id_thai_hash' => $creator->id_thai_hash,
        ]);

        $document = PrDocument::create([
            'pr_number' => 'PR26-0002',
            'document_date' => '2026-08-04',
            'company' => 'SUPAVUT INDUSTRY',
            'requester_id_thai_hash' => $creator->id_thai_hash,
            'requester_name' => $creator->displayName(),
            'supplier_count' => 1,
            'status' => 'sent_user',
            'created_by_id_thai_hash' => $creator->id_thai_hash,
        ]);
        $document->ensureSuppliers();
        $document->suppliers()->where('slot', 1)->update(['name' => 'JIB Computer Group']);
        $document->items()->create([
            'row_no' => 1,
            'item_code' => 'FIX-IT-26-001',
        ]);
        $document->items()->create([
            'row_no' => 2,
            'item_code' => 'FIX-IT-26-001',
        ]);

        $selectList = $this
            ->withSession([Authenticate::SESSION_KEY => $creator->id])
            ->get('/work/select');
        $selectList
            ->assertOk()
            ->assertSeeText('PR26-0002 #ใบร่าง')
            ->assertSeeText('Item Code')
            ->assertSeeText('ผู้จัดทำ')
            ->assertSeeText('ฝ่ายจัดซื้อ')
            ->assertSeeText('ดูรายละเอียด')
            ->assertSee('class="item-code-number">1.</span>', false)
            ->assertSee('class="item-code-number">2.</span>', false)
            ->assertSeeText('JIB Computer Group')
            ->assertSee('class="supplier-number">1.</span>', false)
            ->assertSeeText('รอคัดเลือกรายการ')
            ->assertSeeText('สถานะทั้งหมด')
            ->assertDontSeeText('Purpose');
        $this->assertSame(2, substr_count($selectList->getContent(), 'FIX-IT-26-001'));

        $this
            ->withSession([Authenticate::SESSION_KEY => $creator->id])
            ->get('/work/create')
            ->assertOk()
            ->assertSeeText('ดำเนินการแล้ว')
            ->assertSee('pill pill-ok">ดำเนินการแล้ว', false)
            ->assertSeeText('ดูรายละเอียด')
            ->assertSee('class="item-code-number">1.</span>', false)
            ->assertSee('class="item-code-number">2.</span>', false);
        $this
            ->withSession([Authenticate::SESSION_KEY => $creator->id])
            ->get('/work/select/'.$document->id)
            ->assertOk()
            ->assertSee('แนบไฟล์ใบเสนอราคา')
            // ผู้ขอซื้อไม่เห็นหัวข้อ 7 — ยังไม่ถึงขั้นต่อรองราคา (D-056)
            ->assertDontSee('attachment-title-negotiate', false)
            ->assertSee('selected_suppliers[]', false);

        $this
            ->withSession([Authenticate::SESSION_KEY => $creator->id])
            ->from('/work/select/'.$document->id)
            ->post('/work/select/'.$document->id.'/send')
            ->assertRedirect('/work/select/'.$document->id)
            ->assertSessionHas('error', 'กรุณาคัดเลือก Supplier และประทับลายเซ็นก่อนส่งต่อรองราคา');

        $this->assertDatabaseHas('pr_documents', [
            'id' => $document->id,
            'status' => 'sent_user',
        ]);

        $this
            ->withSession([Authenticate::SESSION_KEY => $creator->id])
            ->from('/work/select/'.$document->id)
            ->post('/work/select/'.$document->id, [
                'selected_suppliers' => [1],
            ])
            ->assertRedirect('/work/select/'.$document->id);

        $this->assertDatabaseHas('pr_documents', [
            'id' => $document->id,
            'pr_number' => 'PR26-0002',
            'status' => 'sent_user',
        ]);
        $this->assertDatabaseHas('pr_document_signatures', [
            'pr_document_id' => $document->id,
            'document_role_id' => $selectStep->id,
            'duty' => 'select',
            'signer_id_thai_hash' => $creator->id_thai_hash,
        ]);
        $this->assertDatabaseHas('pr_suppliers', [
            'pr_document_id' => $document->id,
            'slot' => 1,
            'is_selected' => true,
        ]);
        $this->assertFalse($document->fresh()->canDelete());
        $this->assertSame('PR26-0002', $document->fresh()->displayLabel());

        $this
            ->withSession([Authenticate::SESSION_KEY => $creator->id])
            ->get('/work/select/'.$document->id)
            ->assertOk()
            ->assertSeeText('ส่งต่อรองราคา')
            ->assertSee('class="btn btn-success" id="sendNegotiationBtn"', false)
            ->assertSeeText('ยืนยันการส่งเอกสาร')
            ->assertSee('sendNegotiationDialog', false)
            ->assertSee('supplier-selection-muted', false);

        $this
            ->withSession([Authenticate::SESSION_KEY => $creator->id])
            ->get('/work/negotiate')
            ->assertOk()
            ->assertDontSeeText('PR26-0002');

        $this
            ->withSession([Authenticate::SESSION_KEY => $creator->id])
            ->get('/work/select')
            ->assertOk()
            ->assertSeeText('PR26-0002')
            ->assertDontSeeText('PR26-0002 #ใบร่าง')
            // ลงนามแล้วแต่ยังไม่กดปุ่มส่งสีเขียว -> ยังไม่นับว่าดำเนินการแล้ว (D-051)
            ->assertSeeText('รอคัดเลือกรายการ')
            ->assertSee('status-badge waiting', false)
            ->assertDontSee('status-badge completed', false);

        $this
            ->withSession([Authenticate::SESSION_KEY => $creator->id])
            ->post('/work/select/'.$document->id.'/send')
            ->assertRedirect('/work/select');

        $this->assertDatabaseHas('pr_documents', [
            'id' => $document->id,
            'status' => 'negotiating',
        ]);

        // กดส่งแล้วขั้นผู้ขอซื้อถึงจะขึ้นดำเนินการแล้ว (D-051)
        $this
            ->withSession([Authenticate::SESSION_KEY => $creator->id])
            ->get('/work/select')
            ->assertOk()
            ->assertSeeText('ดำเนินการแล้ว')
            ->assertSee('status-badge completed', false);

        $this
            ->withSession([Authenticate::SESSION_KEY => $creator->id])
            ->get('/work/negotiate')
            ->assertOk()
            ->assertSeeText('PR26-0002')
            ->assertSeeText('ดูรายละเอียด')
            ->assertSee('class="item-code-number">1.</span>', false)
            ->assertSee('class="item-code-number">2.</span>', false)
            ->assertSeeText('รอต่อรองราคา')
            ->assertSee('status-badge waiting', false);

        $document->update(['status' => 'approved']);

        $this
            ->withSession([Authenticate::SESSION_KEY => $creator->id])
            ->get('/work/select')
            ->assertOk()
            ->assertSeeText('ดำเนินการแล้ว')
            ->assertSee('status-badge completed', false);

        $this
            ->withSession([Authenticate::SESSION_KEY => $creator->id])
            ->get('/work/create')
            ->assertOk()
            ->assertSeeText('ดำเนินการแล้ว')
            ->assertSee('pill pill-ok">ดำเนินการแล้ว', false);

        $this
            ->withSession([Authenticate::SESSION_KEY => $creator->id])
            ->post("/documents/{$document->id}/delete")
            ->assertForbidden();

        $this->assertModelExists($document);
    }

    public function test_negotiation_attachment_is_stored_in_its_own_section(): void
    {
        Storage::fake('local');

        $negotiator = AppUser::create([
            'insight_id' => 110,
            'id_thai_hash' => 'negotiator-hash',
            'company' => 'SUPAVUT INDUSTRY',
            'employee_code' => 'PUR110',
            'role' => 'user',
            'full_name_th' => 'ผู้ต่อรองราคา',
        ]);
        DocumentRole::create(['step_no' => 3, 'role' => 'purchasing', 'duty' => 'negotiate'])
            ->addMember($negotiator->id_thai_hash);

        $document = PrDocument::create([
            'pr_number' => 'PR26-0001',
            'document_date' => '2026-08-04',
            'company' => 'SUPAVUT INDUSTRY',
            'supplier_count' => 1,
            'status' => 'negotiating',
            'created_by_id_thai_hash' => 'another-purchase-hash',
        ]);
        $document->ensureSuppliers();
        $supplier = $document->suppliers->first();
        // แนบไฟล์ขั้นต่อรองได้เฉพาะบริษัทที่ผู้ขอซื้อเลือก และต้องตอบว่ามีใบเสนอราคาก่อน (D-054)
        // คำตอบ มี/ไม่มี อยู่ที่ผู้ขายรายนั้น ไม่ใช่ระดับเอกสารแล้ว
        $supplier->update(['name' => 'Supplier A', 'is_selected' => true, 'has_negotiation_quote' => true]);
        $document->items()->create(['row_no' => 1]);

        $this
            ->withSession([Authenticate::SESSION_KEY => $negotiator->id])
            ->post('/work/negotiate/suppliers/'.$supplier->id.'/files', [
                'files' => [UploadedFile::fake()->create('quotation-rev.pdf', 120, 'application/pdf')],
            ])
            ->assertRedirect();

        $attachment = $supplier->attachments()->firstOrFail();
        $this->assertSame('negotiate', $attachment->stage);
        Storage::disk('local')->assertExists($attachment->path);

        $this
            ->withSession([Authenticate::SESSION_KEY => $negotiator->id])
            ->get('/work/negotiate/'.$document->id)
            ->assertOk()
            ->assertSee('แนบไฟล์ใบเสนอราคา')
            ->assertSee('แนบไฟล์ใบเสนอราคา (ต่อรองราคา)')
            ->assertSee('quotation-rev.pdf');
    }

    /**
     * หัวข้อ 7 ตอบแยกทีละบริษัท — เจ้าหนึ่งส่งใบเสนอราคาใหม่มา อีกเจ้าไม่ส่ง เกิดขึ้นจริงเสมอ
     */
    public function test_negotiation_quote_answer_is_asked_per_supplier(): void
    {
        Storage::fake('local');

        $negotiator = AppUser::create([
            'insight_id' => 111,
            'id_thai_hash' => 'negotiator-per-supplier',
            'company' => 'SUPAVUT INDUSTRY',
            'employee_code' => 'PUR111',
            'role' => 'user',
            'full_name_th' => 'ผู้ต่อรองราคา',
        ]);
        DocumentRole::create(['step_no' => 3, 'role' => 'purchasing', 'duty' => 'negotiate'])
            ->addMember($negotiator->id_thai_hash);

        $document = PrDocument::create([
            'pr_number' => 'PR26-0777',
            'document_date' => '2026-08-07',
            'company' => 'SUPAVUT INDUSTRY',
            'supplier_count' => 2,
            'status' => 'negotiating',
            'created_by_id_thai_hash' => 'another-purchase-hash',
        ]);
        $document->ensureSuppliers();
        $document->suppliers()->where('slot', 1)->update(['name' => 'บริษัท เจ.ไอ.บี. คอมพิวเตอร์ กรุ๊ป จำกัด']);
        $document->suppliers()->where('slot', 2)->update(['name' => 'บริษัท แอดไวซ์ ไอที อินฟินิท จำกัด']);
        $document->items()->create(['row_no' => 1]);

        $first = $document->suppliers()->where('slot', 1)->firstOrFail();
        $second = $document->suppliers()->where('slot', 2)->firstOrFail();

        // ยังไม่ตอบ -> แนบไฟล์ไม่ได้
        $this
            ->withSession([Authenticate::SESSION_KEY => $negotiator->id])
            ->post('/work/negotiate/suppliers/'.$first->id.'/files', [
                'files' => [UploadedFile::fake()->create('quote.pdf', 100, 'application/pdf')],
            ])
            ->assertStatus(409);

        // เจ้าที่ 1 ตอบ "มี" — เปิดช่องแนบเฉพาะเจ้านี้ ไม่ลามไปเจ้าที่ 2
        $this
            ->withSession([Authenticate::SESSION_KEY => $negotiator->id])
            ->post('/work/negotiate/suppliers/'.$first->id.'/quote-flag', ['has_quote' => '1'])
            ->assertRedirect();

        $this->assertTrue($first->fresh()->has_negotiation_quote);
        $this->assertNull($second->fresh()->has_negotiation_quote);

        $this
            ->withSession([Authenticate::SESSION_KEY => $negotiator->id])
            ->post('/work/negotiate/suppliers/'.$first->id.'/files', [
                'files' => [UploadedFile::fake()->create('quote-rev.pdf', 100, 'application/pdf')],
            ])
            ->assertRedirect();

        $this->assertSame(1, $first->fresh()->attachments()->count());

        // เจ้าที่ 2 ยังไม่ตอบ -> แนบไม่ได้
        $this
            ->withSession([Authenticate::SESSION_KEY => $negotiator->id])
            ->post('/work/negotiate/suppliers/'.$second->id.'/files', [
                'files' => [UploadedFile::fake()->create('quote2.pdf', 100, 'application/pdf')],
            ])
            ->assertStatus(409);

        // เจ้าที่ 2 ตอบ "ไม่มี" -> หน้าจอขึ้นข้อความแทนช่องแนบ
        $this
            ->withSession([Authenticate::SESSION_KEY => $negotiator->id])
            ->post('/work/negotiate/suppliers/'.$second->id.'/quote-flag', ['has_quote' => '0'])
            ->assertRedirect();

        $this->assertFalse($second->fresh()->has_negotiation_quote);

        $this
            ->withSession([Authenticate::SESSION_KEY => $negotiator->id])
            ->get('/work/negotiate/'.$document->id)
            ->assertOk()
            ->assertSee('quote-rev.pdf')
            ->assertSeeText('ฝ่ายจัดซื้อระบุว่าไม่มีใบเสนอราคาเพิ่มเติมจากการต่อรอง');

        // ตอบ "ไม่มี" ทีหลังต้องล้างไฟล์ของเจ้านั้นทิ้ง แต่ไม่แตะไฟล์เจ้าอื่น
        $this
            ->withSession([Authenticate::SESSION_KEY => $negotiator->id])
            ->post('/work/negotiate/suppliers/'.$first->id.'/quote-flag', ['has_quote' => '0'])
            ->assertRedirect();

        $this->assertSame(0, $first->fresh()->attachments()->count());
    }

    public function test_send_action_saves_current_form_values_before_changing_status(): void
    {
        $creator = AppUser::create([
            'insight_id' => 1,
            'id_thai_hash' => 'creator-hash',
            'company' => 'SUPAVUT INDUSTRY',
            'employee_code' => 'PUR001',
            'role' => 'user',
            'full_name_th' => 'ผู้จัดทำเอกสาร',
            'signature' => 'data:image/png;base64,dGVzdA==',
        ]);

        $requester = AppUser::create([
            'insight_id' => 2,
            'id_thai_hash' => 'requester-hash',
            'company' => 'SUPAVUT INDUSTRY',
            'employee_code' => 'REQ001',
            'role' => 'user',
            'full_name_th' => 'ผู้ขอซื้อ',
            'department' => 'ฝ่ายผลิต',
        ]);

        $step = DocumentRole::create([
            'step_no' => 1,
            'role' => 'purchasing',
            'duty' => 'create',
        ]);
        $step->addMember($creator->id_thai_hash);

        $document = PrDocument::create([
            'pr_number' => 'PR26-0001',
            'document_date' => '2026-08-03',
            'supplier_count' => 3,
            'status' => 'draft',
            'created_by_id_thai_hash' => $creator->id_thai_hash,
        ]);
        $document->ensureSuppliers();
        $item = $document->items()->create(['row_no' => 1]);
        ItemCode::create(ItemCode::parse('ITEM-001') + [
            'name' => 'อะไหล่ทดสอบ',
            'source' => 'legacy',
        ]);
        $document->signatures()->create([
            'document_role_id' => $step->id,
            'step_no' => 1,
            'role' => 'purchasing',
            'duty' => 'create',
            'signer_id_thai_hash' => $creator->id_thai_hash,
            'signer_name' => $creator->displayName(),
            'signature_data' => $creator->signature,
            'signed_at' => now(),
        ]);

        // ทุกบริษัทที่เปิดใช้ต้องมีใบเสนอราคาแนบก่อนถึงจะส่งได้ (D-054)
        foreach ($document->suppliers as $supplier) {
            $supplier->attachments()->create([
                'stage' => 'create',
                'original_name' => 'quote-'.$supplier->slot.'.pdf',
                'path' => 'pr-attachments/quote-'.$supplier->slot.'.pdf',
                'mime' => 'application/pdf',
                'size' => 100,
            ]);
        }

        $response = $this
            ->withSession([Authenticate::SESSION_KEY => $creator->id])
            ->post('/documents/'.$document->id, [
                'intent' => 'send',
                'pr_number' => 'PR26-9999',
                'purpose' => 'อะไหล่สำหรับสายการผลิต',
                'comment' => 'ส่งพร้อมข้อมูลล่าสุด',
                'item_count' => 1,
                'supplier_count' => 3,
                'supplier_count' => 1,
                'requester_id_thai_hash' => $requester->id_thai_hash,
                'suppliers' => [
                    1 => [
                        'name' => 'Supplier A',
                        'lead_time' => '7 days',
                        'term_of_payment' => '30 days',
                        'remark' => 'พร้อมส่ง',
                    ],
                ],
                'items' => [
                    $item->id => [
                        'item_code' => 'ITEM-001',
                        'description' => 'อะไหล่ทดสอบ',
                        'qty' => 2,
                        'unit' => 'Ea',
                        'prices' => [1 => 125.50],
                    ],
                ],
            ]);

        $response->assertRedirect(route('documents.index'));
        $this->assertDatabaseHas('pr_documents', [
            'id' => $document->id,
            'pr_number' => 'PR26-0001',
            'purpose' => 'อะไหล่สำหรับสายการผลิต',
            'requester_id_thai_hash' => $requester->id_thai_hash,
            'status' => 'sent_user',
        ]);
        $this->assertDatabaseHas('pr_suppliers', [
            'pr_document_id' => $document->id,
            'slot' => 1,
            'name' => 'Supplier A',
        ]);
        $this->assertDatabaseHas('pr_items', [
            'id' => $item->id,
            'item_code' => 'ITEM-001',
            'qty' => 2,
        ]);
    }

    public function test_incomplete_purchase_document_stays_draft_and_returns_field_errors(): void
    {
        $creator = AppUser::create([
            'insight_id' => 108,
            'id_thai_hash' => 'incomplete-creator-hash',
            'company' => 'SUPAVUT INDUSTRY',
            'employee_code' => 'PUR108',
            'role' => 'user',
            'full_name_th' => 'ผู้จัดทำข้อมูลไม่ครบ',
        ]);
        $requester = AppUser::create([
            'insight_id' => 109,
            'id_thai_hash' => 'incomplete-requester-hash',
            'company' => 'SUPAVUT INDUSTRY',
            'employee_code' => 'REQ109',
            'role' => 'user',
            'full_name_th' => 'ผู้ขอซื้อทดสอบ',
        ]);
        DocumentRole::create(['step_no' => 1, 'role' => 'purchasing', 'duty' => 'create'])
            ->addMember($creator->id_thai_hash);

        $document = PrDocument::create([
            'pr_number' => 'PR26-0001',
            'document_date' => '2026-08-04',
            'supplier_count' => 1,
            'status' => 'draft',
            'created_by_id_thai_hash' => $creator->id_thai_hash,
        ]);
        $document->ensureSuppliers();
        $item = $document->items()->create(['row_no' => 1]);

        $this
            ->withSession([Authenticate::SESSION_KEY => $creator->id])
            ->from('/documents/'.$document->id)
            ->post('/documents/'.$document->id, [
                'intent' => 'send',
                'purpose' => '',
                'item_count' => 1,
                'supplier_count' => 1,
                'requester_id_thai_hash' => $requester->id_thai_hash,
                'suppliers' => [1 => ['name' => '', 'lead_time' => '', 'term_of_payment' => '', 'remark' => '']],
                'items' => [$item->id => ['item_code' => '', 'description' => '', 'qty' => '', 'unit' => '', 'prices' => []]],
            ])
            ->assertRedirect('/documents/'.$document->id)
            ->assertSessionHasErrors([
                'purpose',
                'purchase_signature',
                'suppliers.1.name',
                'suppliers.1.lead_time',
                'suppliers.1.term_of_payment',
                'suppliers.1.remark',
                "items.{$item->id}.item_code",
                "items.{$item->id}.description",
                "items.{$item->id}.qty",
                "items.{$item->id}.unit",
                "items.{$item->id}.prices.1",
            ]);

        $this->assertDatabaseHas('pr_documents', ['id' => $document->id, 'status' => 'draft']);
    }

    public function test_create_signature_stays_in_purchase_tab_and_negotiate_signature_uses_negotiate_tab(): void
    {
        $creator = AppUser::create([
            'insight_id' => 10,
            'id_thai_hash' => 'signer-hash',
            'company' => 'SUPAVUT INDUSTRY',
            'employee_code' => 'PUR010',
            'role' => 'user',
            'full_name_th' => 'ผู้ลงนามทดสอบ',
            'signature' => 'data:image/png;base64,dGVzdA==',
        ]);

        $createStep = DocumentRole::create([
            'step_no' => 1,
            'role' => 'purchasing',
            'duty' => 'create',
        ]);
        $createStep->addMember($creator->id_thai_hash);

        $negotiateStep = DocumentRole::create([
            'step_no' => 3,
            'role' => 'purchasing',
            'duty' => 'negotiate',
        ]);
        $negotiateStep->addMember($creator->id_thai_hash);

        $document = PrDocument::create([
            'pr_number' => '',
            'document_date' => '2026-08-03',
            'supplier_count' => 1,
            'status' => 'draft',
            'created_by_id_thai_hash' => $creator->id_thai_hash,
        ]);
        $document->ensureSuppliers();
        $item = $document->items()->create(['row_no' => 1]);

        $baseForm = [
            'item_count' => 1,
            'supplier_count' => 1,
            'pr_number' => '',
            'items' => [
                $item->id => [
                    'item_code' => '',
                    'description' => '',
                    'qty' => '',
                    'unit' => '',
                    'prices' => [],
                ],
            ],
        ];

        $this
            ->withSession([Authenticate::SESSION_KEY => $creator->id])
            ->from('/documents/'.$document->id)
            ->post('/documents/'.$document->id, $baseForm + [
                'intent' => 'stamp',
                'signature_step_id' => $createStep->id,
            ])
            ->assertRedirect('/documents/'.$document->id);

        $this->assertDatabaseHas('pr_document_signatures', [
            'pr_document_id' => $document->id,
            'document_role_id' => $createStep->id,
            'signer_id_thai_hash' => $creator->id_thai_hash,
            'signature_data' => $creator->signature,
        ]);

        $createSignatureId = $document->signatures()
            ->where('document_role_id', $createStep->id)
            ->value('id');

        $this
            ->withSession([Authenticate::SESSION_KEY => $creator->id])
            ->from('/documents/'.$document->id)
            ->post('/documents/'.$document->id, $baseForm + [
                'intent' => 'remove_signature',
                'signature_id' => $createSignatureId,
            ])
            ->assertRedirect('/documents/'.$document->id);

        $this->assertDatabaseMissing('pr_document_signatures', ['id' => $createSignatureId]);

        $this
            ->withSession([Authenticate::SESSION_KEY => $creator->id])
            ->from('/documents/'.$document->id)
            ->post('/documents/'.$document->id, $baseForm + [
                'intent' => 'stamp',
                'signature_step_id' => $negotiateStep->id,
            ])
            ->assertRedirect('/documents/'.$document->id)
            ->assertSessionHas('error', 'กรุณาลงนามขั้นต่อรองราคาจากแท็บต่อรองราคา');

        $this->assertDatabaseMissing('pr_document_signatures', [
            'pr_document_id' => $document->id,
            'document_role_id' => $negotiateStep->id,
        ]);

        $document->update(['status' => 'negotiating']);

        $this
            ->withSession([Authenticate::SESSION_KEY => $creator->id])
            ->get('/documents/'.$document->id)
            ->assertOk()
            ->assertDontSee('data-signature-step="'.$negotiateStep->id.'"', false);

        $this
            ->withSession([Authenticate::SESSION_KEY => $creator->id])
            ->get('/work/negotiate/'.$document->id)
            ->assertOk()
            ->assertSee('data-signature-step="'.$negotiateStep->id.'"', false);

        $this
            ->withSession([Authenticate::SESSION_KEY => $creator->id])
            ->from('/work/negotiate/'.$document->id)
            ->post('/work/negotiate/'.$document->id, [
                'intent' => 'stamp',
                'signature_step_id' => $negotiateStep->id,
            ])
            ->assertRedirect('/work/negotiate/'.$document->id);

        $this->assertDatabaseHas('pr_documents', [
            'id' => $document->id,
            'status' => 'negotiating',
        ]);

        $this
            ->withSession([Authenticate::SESSION_KEY => $creator->id])
            ->get('/work/negotiate/'.$document->id)
            ->assertOk()
            ->assertSeeText('ส่งลงนามอนุมัติ')
            ->assertSee('id="sendApprovalDialog"', false);

        // ประทับลายเซ็นแล้วแต่ยังไม่กดปุ่มส่งสีเขียว -> ยังไม่นับว่าดำเนินการแล้ว (D-051)
        $this
            ->withSession([Authenticate::SESSION_KEY => $creator->id])
            ->get('/work/negotiate')
            ->assertOk()
            ->assertSeeText('รอต่อรองราคา')
            ->assertSee('status-badge waiting', false)
            ->assertDontSee('status-badge completed', false);

        $this
            ->withSession([Authenticate::SESSION_KEY => $creator->id])
            ->from('/documents/'.$document->id)
            ->post('/documents/'.$document->id, $baseForm + [
                'intent' => 'stamp',
                'signature_step_id' => $negotiateStep->id,
            ])
            ->assertRedirect('/documents/'.$document->id)
            ->assertSessionHas('error', 'กรุณาลงนามขั้นต่อรองราคาจากแท็บต่อรองราคา');

        $this->assertDatabaseHas('pr_document_signatures', [
            'pr_document_id' => $document->id,
            'document_role_id' => $negotiateStep->id,
            'signer_id_thai_hash' => $creator->id_thai_hash,
            'signature_data' => $creator->signature,
        ]);

        $signatureId = $document->signatures()
            ->where('document_role_id', $negotiateStep->id)
            ->value('id');

        $this
            ->withSession([Authenticate::SESSION_KEY => $creator->id])
            ->from('/work/negotiate/'.$document->id)
            ->post('/work/negotiate/'.$document->id, [
                'intent' => 'remove_signature',
                'signature_id' => $signatureId,
            ])
            ->assertRedirect('/work/negotiate/'.$document->id);

        $this->assertDatabaseMissing('pr_document_signatures', ['id' => $signatureId]);
        $this->assertDatabaseHas('pr_documents', [
            'id' => $document->id,
            'status' => 'negotiating',
        ]);
    }

    public function test_revision_price_and_approval_signatures_follow_the_full_route(): void
    {
        $purchase = AppUser::create([
            'insight_id' => 201,
            'id_thai_hash' => 'purchase-approval-hash',
            'company' => 'SUPAVUT INDUSTRY',
            'employee_code' => 'PUR201',
            'role' => 'user',
            'full_name_th' => 'เจ้าหน้าที่จัดซื้อ',
            'signature' => 'data:image/png;base64,cHVyY2hhc2U=',
        ]);
        $manager = AppUser::create([
            'insight_id' => 202,
            'id_thai_hash' => 'manager-approval-hash',
            'company' => 'SUPAVUT INDUSTRY',
            'employee_code' => 'MGR202',
            'role' => 'user',
            'full_name_th' => 'ผู้จัดการฝ่ายจัดซื้อ',
            'signature' => 'data:image/png;base64,bWFuYWdlcg==',
        ]);
        $ceo = AppUser::create([
            'insight_id' => 203,
            'id_thai_hash' => 'ceo-approval-hash',
            'company' => 'SUPAVUT INDUSTRY',
            'employee_code' => 'CEO203',
            'role' => 'user',
            'full_name_th' => 'ผู้บริหาร',
            'signature' => 'data:image/png;base64,Y2Vv',
        ]);

        $createStep = DocumentRole::create(['step_no' => 1, 'role' => 'purchasing', 'duty' => 'create']);
        $createStep->addMember($purchase->id_thai_hash);
        DocumentRole::create(['step_no' => 2, 'role' => 'user', 'duty' => 'select']);
        $negotiateStep = DocumentRole::create(['step_no' => 3, 'role' => 'purchasing', 'duty' => 'negotiate']);
        $negotiateStep->addMember($purchase->id_thai_hash);
        $managerStep = DocumentRole::create(['step_no' => 4, 'role' => 'mgr_purchasing', 'duty' => 'sign']);
        $managerStep->addMember($manager->id_thai_hash);
        $ceoStep = DocumentRole::create(['step_no' => 5, 'role' => 'ceo', 'duty' => 'sign']);
        $ceoStep->addMember($ceo->id_thai_hash);

        $document = PrDocument::create([
            'pr_number' => 'PR26-0099',
            'document_date' => '2026-08-04',
            'supplier_count' => 1,
            'status' => 'negotiating',
            'created_by_id_thai_hash' => $purchase->id_thai_hash,
        ]);
        $document->ensureSuppliers();
        $supplier = $document->suppliers()->where('slot', 1)->firstOrFail();
        $supplier->update(['name' => 'บริษัททดสอบ', 'is_selected' => true]);
        $item = $document->items()->create([
            'row_no' => 1,
            'item_code' => 'FIX-IT-26-001',
            'description' => 'คอมพิวเตอร์สำนักงาน',
            'qty' => 2,
            'unit' => 'ชุด',
        ]);
        $item->prices()->create([
            'pr_supplier_id' => $supplier->id,
            'unit_price' => 28900,
        ]);

        $this
            ->withSession([Authenticate::SESSION_KEY => $purchase->id])
            ->get('/work/negotiate/'.$document->id)
            ->assertOk()
            ->assertSee('name="revision_prices['.$item->id.'][1]"', false)
            ->assertSee('data-amount-base="1"', false)
            ->assertSee('data-amount-rev="1"', false)
            ->assertSeeText('บันทึกราคา Rev.1');

        $this
            ->withSession([Authenticate::SESSION_KEY => $purchase->id])
            ->from('/work/negotiate/'.$document->id)
            ->post('/work/negotiate/'.$document->id, [
                'intent' => 'save_revision',
                'revision_prices' => [$item->id => [1 => 27000]],
            ])
            ->assertRedirect('/work/negotiate/'.$document->id);

        $this->assertDatabaseHas('pr_prices', [
            'pr_item_id' => $item->id,
            'pr_supplier_id' => $supplier->id,
            'unit_price' => 28900,
            'unit_price_rev' => 27000,
        ]);

        $this
            ->withSession([Authenticate::SESSION_KEY => $purchase->id])
            ->from('/work/negotiate/'.$document->id)
            ->post('/work/negotiate/'.$document->id, [
                'intent' => 'stamp',
                'signature_step_id' => $negotiateStep->id,
            ])
            ->assertRedirect('/work/negotiate/'.$document->id);

        $this->assertDatabaseHas('pr_documents', ['id' => $document->id, 'status' => 'negotiating']);
        $this
            ->withSession([Authenticate::SESSION_KEY => $purchase->id])
            ->get('/work/negotiate/'.$document->id)
            ->assertOk()
            ->assertSeeText('ส่งลงนามอนุมัติ')
            ->assertSeeText('ยืนยันการส่งลงนามอนุมัติ');

        // ยังไม่ตอบ มี/ไม่มี ในหัวข้อ 7 -> ส่งไม่ผ่าน (D-057)
        $this
            ->withSession([Authenticate::SESSION_KEY => $purchase->id])
            ->from('/work/negotiate/'.$document->id)
            ->post('/work/negotiate/'.$document->id.'/send')
            ->assertRedirect('/work/negotiate/'.$document->id)
            ->assertSessionHas('error');
        $this->assertDatabaseHas('pr_documents', ['id' => $document->id, 'status' => 'negotiating']);

        // ตอบ "ไม่มี" ให้ครบทุกบริษัทที่ใบนี้ใช้ แล้วจึงส่งได้
        $document->suppliers()->update(['has_negotiation_quote' => false]);

        $this
            ->withSession([Authenticate::SESSION_KEY => $purchase->id])
            ->post('/work/negotiate/'.$document->id.'/send')
            ->assertRedirect('/work/negotiate');
        $this->assertDatabaseHas('pr_documents', ['id' => $document->id, 'status' => 'waiting_mgr']);

        $this
            ->withSession([Authenticate::SESSION_KEY => $manager->id])
            ->get('/work/sign')
            ->assertOk()
            ->assertSeeText('PR26-0099')
            ->assertSeeText('ดูรายละเอียด')
            ->assertSee('class="item-code-number">1.</span>', false)
            ->assertSeeText('รอลงนามอนุมัติ');
        $this
            ->withSession([Authenticate::SESSION_KEY => $manager->id])
            ->get('/work/sign/'.$document->id)
            ->assertOk()
            ->assertSee('data-signature-step="'.$managerStep->id.'"', false);
        $this
            ->withSession([Authenticate::SESSION_KEY => $manager->id])
            ->post('/work/sign/'.$document->id, [
                'intent' => 'stamp',
                'signature_step_id' => $managerStep->id,
            ])
            ->assertRedirect();

        // ประทับลายเซ็นอย่างเดียวยังไม่ส่งต่อ ต้องกดปุ่มยืนยันอีกครั้ง (D-070)
        $this->assertDatabaseHas('pr_documents', ['id' => $document->id, 'status' => 'waiting_mgr']);
        $this
            ->withSession([Authenticate::SESSION_KEY => $manager->id])
            ->get('/work/sign/'.$document->id)
            ->assertOk()
            ->assertSeeText('ส่งให้ CEO ลงนาม');

        $this
            ->withSession([Authenticate::SESSION_KEY => $manager->id])
            ->post('/work/sign/'.$document->id.'/send')
            ->assertRedirect('/work/sign');
        $this->assertDatabaseHas('pr_documents', ['id' => $document->id, 'status' => 'waiting_ceo']);

        $this
            ->withSession([Authenticate::SESSION_KEY => $ceo->id])
            ->get('/work/sign')
            ->assertOk()
            ->assertSeeText('PR26-0099')
            ->assertSeeText('ดูรายละเอียด')
            ->assertSee('class="item-code-number">1.</span>', false)
            ->assertSeeText('รอลงนามอนุมัติ');
        $this
            ->withSession([Authenticate::SESSION_KEY => $ceo->id])
            ->post('/work/sign/'.$document->id, [
                'intent' => 'stamp',
                'signature_step_id' => $ceoStep->id,
            ])
            ->assertRedirect();

        // CEO ก็ต้องกดยืนยัน และหน้าจอต้องเตือนว่าเป็นผู้ลงนามคนสุดท้าย
        $this->assertDatabaseHas('pr_documents', ['id' => $document->id, 'status' => 'waiting_ceo']);
        $this
            ->withSession([Authenticate::SESSION_KEY => $ceo->id])
            ->get('/work/sign/'.$document->id)
            ->assertOk()
            ->assertSeeText('อนุมัติเอกสาร')
            ->assertSeeText('ลายเซ็นสุดท้ายของเอกสารนี้');

        $this
            ->withSession([Authenticate::SESSION_KEY => $ceo->id])
            ->post('/work/sign/'.$document->id.'/send')
            ->assertRedirect('/work/sign');

        $this->assertDatabaseHas('pr_documents', ['id' => $document->id, 'status' => 'approved']);
        $this->assertDatabaseHas('pr_document_signatures', [
            'pr_document_id' => $document->id,
            'document_role_id' => $managerStep->id,
            'signer_id_thai_hash' => $manager->id_thai_hash,
        ]);
        $this->assertDatabaseHas('pr_document_signatures', [
            'pr_document_id' => $document->id,
            'document_role_id' => $ceoStep->id,
            'signer_id_thai_hash' => $ceo->id_thai_hash,
        ]);
    }

    /**
     * เส้นทาง 6 ขั้น — ต่อรองราคาครบ 3 เจ้า แล้วผู้ขอซื้อยืนยันรายการก่อนส่งลงนาม (D-075)
     *
     * ครอบคลุม: Rev.1 เปิดครบทุกเจ้า · บังคับกรอกครบก่อนส่ง · เปลี่ยน Supplier ตอนยืนยันได้
     * · เลือกได้เจ้าเดียว · ลายเซ็นครบ 6 จุด · เทาเจ้าที่ตกรอบหลังยืนยันเท่านั้น
     */
    public function test_requester_confirms_revised_prices_before_approval(): void
    {
        $purchase = AppUser::create([
            'insight_id' => 301,
            'id_thai_hash' => 'confirm-purchase-hash',
            'company' => 'SUPAVUT INDUSTRY',
            'employee_code' => 'PUR301',
            'role' => 'user',
            'full_name_th' => 'เจ้าหน้าที่จัดซื้อ',
            'signature' => 'data:image/png;base64,cHVyY2hhc2U=',
        ]);
        $requester = AppUser::create([
            'insight_id' => 302,
            'id_thai_hash' => 'confirm-requester-hash',
            'company' => 'SUPAVUT INDUSTRY',
            'employee_code' => 'REQ302',
            'role' => 'user',
            'full_name_th' => 'ผู้ขอซื้อยืนยัน',
            'department' => 'ฝ่ายผลิต',
            'signature' => 'data:image/png;base64,cmVxdWVzdGVy',
        ]);
        $manager = AppUser::create([
            'insight_id' => 303,
            'id_thai_hash' => 'confirm-manager-hash',
            'company' => 'SUPAVUT INDUSTRY',
            'employee_code' => 'MGR303',
            'role' => 'user',
            'full_name_th' => 'ผู้จัดการฝ่ายจัดซื้อ',
            'signature' => 'data:image/png;base64,bWFuYWdlcg==',
        ]);

        DocumentRole::create(['step_no' => 1, 'role' => 'purchasing', 'duty' => 'create'])
            ->addMember($purchase->id_thai_hash);
        DocumentRole::create(['step_no' => 2, 'role' => 'user', 'duty' => 'select']);
        $negotiateStep = DocumentRole::create(['step_no' => 3, 'role' => 'purchasing', 'duty' => 'negotiate']);
        $negotiateStep->addMember($purchase->id_thai_hash);
        $confirmStep = DocumentRole::create(['step_no' => 4, 'role' => 'user', 'duty' => 'confirm']);
        DocumentRole::create(['step_no' => 5, 'role' => 'mgr_purchasing', 'duty' => 'sign'])
            ->addMember($manager->id_thai_hash);
        DocumentRole::create(['step_no' => 6, 'role' => 'ceo', 'duty' => 'sign']);

        // ขั้นของ Role user ขึ้นเมนูให้ทุกคน — ผู้ขอซื้อจึงมีทั้งคัดเลือกและยืนยันรายการ
        $this->assertSame(['select', 'confirm'], array_keys(DocumentRole::menuFor($requester)));

        $document = PrDocument::create([
            'pr_number' => 'PR26-0301',
            'document_date' => '2026-08-06',
            'company' => 'SUPAVUT INDUSTRY',
            'supplier_count' => 3,
            'status' => 'negotiating',
            'created_by_id_thai_hash' => $purchase->id_thai_hash,
            'requester_id_thai_hash' => $requester->id_thai_hash,
            'requester_name' => $requester->displayName(),
        ]);
        $document->ensureSuppliers();
        // ตอบ "ไม่มีใบเสนอราคาหลังต่อรอง" ครบทุกเจ้า (คำตอบอยู่ที่ผู้ขายรายตัว)
        $document->suppliers()->update(['has_negotiation_quote' => false]);
        $item = $document->items()->create([
            'row_no' => 1,
            'item_code' => 'FIX-IT-26-001',
            'description' => 'คอมพิวเตอร์สำนักงาน',
            'qty' => 1,
            'unit' => 'ชุด',
        ]);

        foreach ($document->suppliers as $supplier) {
            $supplier->update(['name' => 'ผู้ขาย '.$supplier->slot]);
            $item->prices()->create(['pr_supplier_id' => $supplier->id, 'unit_price' => 1000 * $supplier->slot]);
        }
        // ผู้ขอซื้อเลือกเจ้าที่ 1 ไว้ตั้งแต่ขั้นคัดเลือกรายการ
        $document->suppliers()->where('slot', 1)->update(['is_selected' => true]);

        $negotiatePage = $this
            ->withSession([Authenticate::SESSION_KEY => $purchase->id])
            ->get('/work/negotiate/'.$document->id);

        $negotiatePage->assertOk()
            // ต่อรองได้ครบทั้ง 3 เจ้า ไม่ใช่เฉพาะเจ้าที่ถูกเลือก
            ->assertSee('name="revision_prices['.$item->id.'][1]"', false)
            ->assertSee('name="revision_prices['.$item->id.'][2]"', false)
            ->assertSee('name="revision_prices['.$item->id.'][3]"', false)
            // ยังไม่ยืนยัน = ห้ามเทาเจ้าที่ตกรอบ ไม่งั้นเทียบราคาไม่ได้
            ->assertSee('var muteUnselectedSuppliers = false', false);
        // ช่องลายเซ็นในเอกสารครบ 6 จุดตามเส้นทาง
        $this->assertSame(6, substr_count($negotiatePage->getContent(), '<div class="signature"'));
        $this->assertStringContainsString('data-signature-duty="confirm"', $negotiatePage->getContent());

        $this
            ->withSession([Authenticate::SESSION_KEY => $purchase->id])
            ->post('/work/negotiate/'.$document->id, [
                'intent' => 'stamp',
                'signature_step_id' => $negotiateStep->id,
                'revision_prices' => [$item->id => [1 => '900.00']],
            ])
            ->assertRedirect();

        // ลงนามแล้วปุ่มส่งสีเขียวชี้ไปที่ผู้ขอซื้อ ไม่ใช่ผู้จัดการ
        $this
            ->withSession([Authenticate::SESSION_KEY => $purchase->id])
            ->get('/work/negotiate/'.$document->id)
            ->assertOk()
            ->assertSeeText('ส่งให้ผู้ขอซื้อยืนยัน');

        // ต่อรองไม่ครบทุกเจ้า -> ส่งไม่ผ่าน
        $this
            ->withSession([Authenticate::SESSION_KEY => $purchase->id])
            ->from('/work/negotiate/'.$document->id)
            ->post('/work/negotiate/'.$document->id.'/send')
            ->assertRedirect('/work/negotiate/'.$document->id)
            ->assertSessionHas('error');
        $this->assertDatabaseHas('pr_documents', ['id' => $document->id, 'status' => 'negotiating']);

        // เจ้าที่ 3 ไม่ต่อรองก็ใส่ `-` ได้ ถือว่าตอบแล้ว
        $this
            ->withSession([Authenticate::SESSION_KEY => $purchase->id])
            ->post('/work/negotiate/'.$document->id.'/send', [
                'revision_prices' => [$item->id => [1 => '900.00', 2 => '1,700.00', 3 => '-']],
            ])
            ->assertRedirect('/work/negotiate');

        $this->assertDatabaseHas('pr_documents', ['id' => $document->id, 'status' => 'confirming']);
        $this->assertDatabaseHas('pr_prices', ['pr_item_id' => $item->id, 'unit_price_rev' => 1700.00]);

        // ผู้ขอซื้อเห็นใบนี้ในแท็บยืนยันรายการ
        $this
            ->withSession([Authenticate::SESSION_KEY => $requester->id])
            ->get('/work/confirm')
            ->assertOk()
            ->assertSeeText('PR26-0301')
            ->assertSeeText('รอยืนยันรายการ')
            ->assertSeeText('ยืนยัน');

        $confirmPage = $this
            ->withSession([Authenticate::SESSION_KEY => $requester->id])
            ->get('/work/confirm/'.$document->id);

        $confirmPage->assertOk()
            ->assertSee('data-signature-step="'.$confirmStep->id.'"', false)
            // เลือกได้เจ้าเดียว จึงเป็น radio
            ->assertSee('type="radio" name="selected_suppliers[]"', false)
            // ราคาหลังต่อรองของทุกเจ้าต้องเห็นครบก่อนตัดสินใจ
            ->assertSee('var muteUnselectedSuppliers = false', false)
            // ผู้ขอซื้อขั้นนี้ต้องเห็นไฟล์ใบเสนอราคาหลังต่อรองด้วย
            ->assertSee('แนบไฟล์ใบเสนอราคา (ต่อรองราคา)');

        // ติ๊ก 2 เจ้าไม่ได้
        $this
            ->withSession([Authenticate::SESSION_KEY => $requester->id])
            ->from('/work/confirm/'.$document->id)
            ->post('/work/confirm/'.$document->id, ['selected_suppliers' => [1, 2]])
            ->assertSessionHasErrors('selected_suppliers');

        // ยังไม่ลงนาม ส่งต่อไม่ได้
        $this
            ->withSession([Authenticate::SESSION_KEY => $requester->id])
            ->from('/work/confirm/'.$document->id)
            ->post('/work/confirm/'.$document->id.'/send')
            ->assertRedirect('/work/confirm/'.$document->id)
            ->assertSessionHas('error');

        // ต่อรองแล้วเจ้าที่ 2 ถูกกว่า — เปลี่ยนเจ้าที่เลือกได้ในขั้นนี้
        $this
            ->withSession([Authenticate::SESSION_KEY => $requester->id])
            ->post('/work/confirm/'.$document->id, [
                'selected_suppliers' => [2],
                'comment' => 'ยืนยันตามราคาหลังต่อรอง',
            ])
            ->assertRedirect('/work/confirm/'.$document->id);

        $this->assertDatabaseHas('pr_suppliers', ['pr_document_id' => $document->id, 'slot' => 2, 'is_selected' => true]);
        $this->assertDatabaseHas('pr_suppliers', ['pr_document_id' => $document->id, 'slot' => 1, 'is_selected' => false]);
        $this->assertDatabaseHas('pr_document_signatures', [
            'pr_document_id' => $document->id,
            'document_role_id' => $confirmStep->id,
            'duty' => 'confirm',
            'signer_id_thai_hash' => $requester->id_thai_hash,
        ]);
        // ลงนามอย่างเดียวยังไม่เดินหน้า ต้องกดปุ่มส่งอีกครั้ง (D-051)
        $this->assertDatabaseHas('pr_documents', ['id' => $document->id, 'status' => 'confirming']);

        $this
            ->withSession([Authenticate::SESSION_KEY => $requester->id])
            ->get('/work/confirm/'.$document->id)
            ->assertOk()
            // ยืนยันแล้วถึงเทาเจ้าที่ตกรอบ
            ->assertSee('var muteUnselectedSuppliers = true', false)
            ->assertSeeText('ส่งลงนามอนุมัติ');

        $this
            ->withSession([Authenticate::SESSION_KEY => $requester->id])
            ->post('/work/confirm/'.$document->id.'/send')
            ->assertRedirect('/work/confirm');

        $this->assertDatabaseHas('pr_documents', ['id' => $document->id, 'status' => 'waiting_mgr']);

        // ส่งแล้วขั้นยืนยันขึ้นดำเนินการแล้ว และผู้จัดการเห็นใบนี้
        $this
            ->withSession([Authenticate::SESSION_KEY => $requester->id])
            ->get('/work/confirm')
            ->assertOk()
            ->assertSeeText('ดำเนินการแล้ว')
            ->assertSee('status-badge completed', false);
        $this
            ->withSession([Authenticate::SESSION_KEY => $manager->id])
            ->get('/work/sign')
            ->assertOk()
            ->assertSeeText('PR26-0301')
            ->assertSeeText('รอลงนามอนุมัติ');
    }

    /** ไม่พอใจราคาที่ต่อรองมา = ปฏิเสธจบใบนี้ที่ขั้นยืนยันรายการ (D-075) */
    public function test_requester_can_reject_at_the_confirmation_step(): void
    {
        $requester = AppUser::create([
            'insight_id' => 311,
            'id_thai_hash' => 'confirm-reject-hash',
            'company' => 'SUPAVUT INDUSTRY',
            'employee_code' => 'REQ311',
            'role' => 'user',
            'full_name_th' => 'ผู้ขอซื้อปฏิเสธ',
            'signature' => 'data:image/png;base64,cmVq',
        ]);

        DocumentRole::create(['step_no' => 1, 'role' => 'purchasing', 'duty' => 'create']);
        DocumentRole::create(['step_no' => 2, 'role' => 'user', 'duty' => 'select']);
        DocumentRole::create(['step_no' => 3, 'role' => 'purchasing', 'duty' => 'negotiate']);
        DocumentRole::create(['step_no' => 4, 'role' => 'user', 'duty' => 'confirm']);
        DocumentRole::create(['step_no' => 5, 'role' => 'mgr_purchasing', 'duty' => 'sign']);

        $document = PrDocument::create([
            'pr_number' => 'PR26-0311',
            'document_date' => '2026-08-06',
            'company' => 'SUPAVUT INDUSTRY',
            'supplier_count' => 3,
            'status' => 'confirming',
            'created_by_id_thai_hash' => 'someone-else-hash',
            'requester_id_thai_hash' => $requester->id_thai_hash,
            'requester_name' => $requester->displayName(),
        ]);

        $this
            ->withSession([Authenticate::SESSION_KEY => $requester->id])
            ->post('/documents/'.$document->id.'/reject', ['reason' => 'ราคาหลังต่อรองยังสูงเกินงบ'])
            ->assertRedirect('/work/confirm');

        $this->assertDatabaseHas('pr_documents', [
            'id' => $document->id,
            'status' => 'rejected_4',
            'rejected_reason' => 'ราคาหลังต่อรองยังสูงเกินงบ',
        ]);

        // ใบที่ปฏิเสธยังอยู่ในรายการของขั้นนี้ พร้อมปุ่มอ่านเหตุผล
        $this
            ->withSession([Authenticate::SESSION_KEY => $requester->id])
            ->get('/work/confirm')
            ->assertOk()
            ->assertSeeText('PR26-0311')
            ->assertSeeText('ปฏิเสธ')
            ->assertSeeText('ราคาหลังต่อรองยังสูงเกินงบ');
    }

    /**
     * ผู้ขอซื้อลาออก = เตือนแค่ขั้นเดียว ไม่ใช่ทุกขั้นที่เป็นคนคนนั้น (D-079)
     *
     * ผู้ขอซื้อคนเดียวกันอยู่ทั้งขั้นคัดเลือกรายการและขั้นยืนยันรายการ
     * ถ้าไม่จำกัดไว้ ลาออกทีเดียวจะขึ้นวงแดง 2 ขั้น ทั้งที่เอกสารสะดุดตั้งแต่ขั้นแรก
     */
    public function test_resignation_marks_only_the_first_stuck_step(): void
    {
        $creator = AppUser::create([
            'insight_id' => 320,
            'id_thai_hash' => 'block-creator-hash',
            'company' => 'SUPAVUT INDUSTRY',
            'employee_code' => 'PUR320',
            'role' => 'user',
            'full_name_th' => 'ผู้จัดทำเอกสาร',
        ]);

        DocumentRole::create(['step_no' => 1, 'role' => 'purchasing', 'duty' => 'create'])
            ->addMember($creator->id_thai_hash);
        DocumentRole::create(['step_no' => 2, 'role' => 'user', 'duty' => 'select']);
        DocumentRole::create(['step_no' => 3, 'role' => 'purchasing', 'duty' => 'negotiate'])
            ->addMember($creator->id_thai_hash);
        DocumentRole::create(['step_no' => 4, 'role' => 'user', 'duty' => 'confirm']);
        DocumentRole::create(['step_no' => 5, 'role' => 'mgr_purchasing', 'duty' => 'sign'])
            ->addMember($creator->id_thai_hash);

        // ผู้ขอซื้อที่ลาออกแล้ว — เหลือแต่ประวัติพนักงาน ไม่มีบัญชีล็อกอิน
        Employee::create([
            'employee_code' => 'REQ321',
            'license_id' => 'block-requester-hash',
            'title' => 'นาย',
            'name_th' => 'ผู้ขอซื้อ',
            'surname_th' => 'ลาออกแล้ว',
            'resign_date' => '2026-07-01',
            'emp_status' => '2',
        ]);

        $document = PrDocument::create([
            'pr_number' => 'PR26-0320',
            'document_date' => '2026-08-06',
            'company' => 'SUPAVUT INDUSTRY',
            'supplier_count' => 3,
            'status' => 'sent_user',
            'created_by_id_thai_hash' => $creator->id_thai_hash,
            'requester_id_thai_hash' => 'block-requester-hash',
            'requester_name' => 'นายผู้ขอซื้อ ลาออกแล้ว',
        ]);

        $progress = $document->routeProgress(DocumentRole::chain());

        // ขั้นคัดเลือกรายการ (ขั้นที่เอกสารค้างอยู่) เท่านั้นที่ถูกทำเครื่องหมาย
        $this->assertSame(
            [false, true, false, false, false],
            array_column($progress, 'blocked')
        );
        // ชื่อยังขึ้นว่าลาออกทุกขั้นที่เป็นคนคนนี้ — ตัดแค่วงแดงซ้ำเท่านั้น
        $this->assertTrue($progress[3]['people'][0]['resigned']);

        // ใบที่ถูกปฏิเสธไปแล้วไม่ต้องเตือนขั้นถัดไปอีก เพราะจบไปแล้ว
        $document->update(['status' => 'rejected_2']);
        $this->assertSame(
            [false, false, false, false, false],
            array_column($document->fresh()->routeProgress(DocumentRole::chain()), 'blocked')
        );

        /*
        | หน้าภาพรวม: สัญลักษณ์ "ลาออก" (วงกลมกรอบ) ขึ้นที่ขั้นที่เอกสารสะดุดจริงเท่านั้น (D-079)
        |   ขั้น 2 คัดเลือกรายการ = ใบตกที่ขั้นนี้เพราะผู้ขอซื้อลาออก -> วงกลมกรอบ
        |   ขั้น 4 ยืนยันรายการ  = คนเดียวกันแต่ยังไม่ถึงขั้น        -> สีเทาธรรมดา
        */
        $overview = $this->withSession([Authenticate::SESSION_KEY => $creator->id])->get('/overview');
        $overview->assertOk()->assertSeeText('PR26-0320');
        $this->assertSame(1, substr_count($overview->getContent(), 'route-node left'));
        // ขั้นในโมดัลใช้สัญลักษณ์เดียวกัน และยังขึ้นป้ายลาออกใต้ชื่อทุกขั้นที่เป็นคนนั้น
        $overview->assertSee('rejected resigned', false);
        $overview->assertDontSee('upcoming resigned', false);
        $overview->assertSee('route-resigned', false);
    }

    /** ทุกหน้ารายการมีแท็บกรองตามสถานะ และกรองที่ฐานข้อมูลจริง (D-074) */
    public function test_status_tabs_filter_each_list(): void
    {
        $purchasing = AppUser::create([
            'insight_id' => 950,
            'id_thai_hash' => 'tab-purchasing-hash',
            'company' => 'SUPAVUT INDUSTRY',
            'employee_code' => 'PUR950',
            'role' => 'user',
            'full_name_th' => 'ฝ่ายจัดซื้อแท็บ',
        ]);

        DocumentRole::create(['step_no' => 1, 'role' => 'purchasing', 'duty' => 'create'])
            ->addMember($purchasing->id_thai_hash);
        DocumentRole::create(['step_no' => 2, 'role' => 'user', 'duty' => 'select']);
        DocumentRole::create(['step_no' => 3, 'role' => 'purchasing', 'duty' => 'negotiate']);

        $base = [
            'document_date' => '2026-08-05',
            'company' => 'SUPAVUT INDUSTRY',
            'supplier_count' => 1,
            'created_by_id_thai_hash' => $purchasing->id_thai_hash,
            'requester_id_thai_hash' => $purchasing->id_thai_hash,
            'requester_name' => $purchasing->displayName(),
        ];

        PrDocument::create($base + ['pr_number' => 'PR26-1101', 'status' => 'draft']);
        PrDocument::create($base + ['pr_number' => 'PR26-1102', 'status' => 'sent_user']);
        PrDocument::create($base + ['pr_number' => 'PR26-1103', 'status' => 'rejected_2']);

        $session = [Authenticate::SESSION_KEY => $purchasing->id];

        // จัดทำเอกสาร — มีแท็บครบและกรองได้จริง
        $this->withSession($session)->get('/work/create')
            ->assertOk()
            ->assertSeeText('ทั้งหมด')
            ->assertSeeText('ใบร่าง')
            ->assertSeeText('ปฏิเสธ')
            ->assertSeeText('PR26-1101')
            ->assertSeeText('PR26-1103');

        $this->withSession($session)->get('/work/create?status=draft')
            ->assertOk()
            ->assertSeeText('PR26-1101')
            ->assertDontSeeText('PR26-1103');

        $this->withSession($session)->get('/work/create?status=rejected')
            ->assertOk()
            ->assertSeeText('PR26-1103')
            ->assertDontSeeText('PR26-1101');

        // ค่าที่ไม่รู้จักต้องตกกลับเป็น "ทั้งหมด" ไม่ใช่ error
        $this->withSession($session)->get('/work/create?status=มั่ว')
            ->assertOk()
            ->assertSeeText('PR26-1101')
            ->assertSeeText('PR26-1103');

        // ผู้ขอซื้อ — ใบร่างไม่นับ เหลือ 2 ใบ
        $this->withSession($session)->get('/work/select?status=waiting')
            ->assertOk()
            ->assertSeeText('PR26-1102')
            ->assertDontSeeText('PR26-1103');

        // ภาพรวม
        $this->withSession($session)->get('/overview?status=rejected')
            ->assertOk()
            ->assertSeeText('PR26-1103')
            ->assertDontSeeText('PR26-1102');
    }

    /** หน้าภาพรวมแสดงเอกสารที่ส่งแล้ว พร้อมสถานะทุกขั้นในแถวและปุ่มดู/ดาวน์โหลด */
    public function test_overview_lists_sent_documents_with_inline_route_and_actions(): void
    {
        $purchasing = AppUser::create([
            'insight_id' => 940,
            'id_thai_hash' => 'ov-purchasing-hash',
            'company' => 'SUPAVUT INDUSTRY',
            'employee_code' => 'PUR940',
            'role' => 'user',
            'full_name_th' => 'ฝ่ายจัดซื้อภาพรวม',
        ]);
        $outsider = AppUser::create([
            'insight_id' => 941,
            'id_thai_hash' => 'ov-outsider-hash',
            'company' => 'SUPAVUT INDUSTRY',
            'employee_code' => 'EMP941',
            'role' => 'user',
            'full_name_th' => 'พนักงานไม่เกี่ยวข้อง',
        ]);

        DocumentRole::create(['step_no' => 1, 'role' => 'purchasing', 'duty' => 'create'])
            ->addMember($purchasing->id_thai_hash);
        DocumentRole::create(['step_no' => 2, 'role' => 'user', 'duty' => 'select']);
        DocumentRole::create(['step_no' => 3, 'role' => 'purchasing', 'duty' => 'negotiate']);

        $base = [
            'document_date' => '2026-08-05',
            'company' => 'SUPAVUT INDUSTRY',
            'supplier_count' => 1,
            'created_by_id_thai_hash' => $purchasing->id_thai_hash,
            'requester_id_thai_hash' => $purchasing->id_thai_hash,
            'requester_name' => $purchasing->displayName(),
        ];

        $draft = PrDocument::create($base + ['pr_number' => 'PR26-0901', 'status' => 'draft']);
        $sent = PrDocument::create($base + ['pr_number' => 'PR26-0902', 'status' => 'sent_user']);
        $done = PrDocument::create($base + ['pr_number' => 'PR26-0903', 'status' => 'approved']);
        $sent->items()->create(['row_no' => 1, 'item_code' => 'SIR-OV-1', 'description' => 'ของ']);

        $page = $this->withSession([Authenticate::SESSION_KEY => $purchasing->id])->get('/overview');

        $page->assertOk()
            ->assertSeeText('PR26-0902')      // ส่งแล้ว = ขึ้นภาพรวมทันที
            ->assertSeeText('PR26-0903')
            ->assertDontSeeText('PR26-0901')  // ใบร่างยังไม่ขึ้น
            ->assertSeeText('ดู')
            ->assertSeeText('ดาวน์โหลด')      // เฉพาะใบที่อนุมัติแล้ว
            ->assertSee('class="route-track"', false)
            ->assertDontSeeText('แสดง')       // สถานะทุกขั้นอยู่ในแถวแล้ว ไม่มีปุ่ม "แสดง"
            ->assertSeeText('จัดการ')          // หัวคอลัมน์ปุ่ม
            ->assertSeeText('สัญลักษณ์สถานะ')  // คำอธิบายอยู่เหนือตาราง
            ->assertSee('data-route-dialog="routeDialog'.$sent->id.'"', false);  // กดแถบแล้วเปิดแบบเต็มได้

        // คนที่ไม่เกี่ยวข้องกับใบไหนเลย ต้องไม่เห็นอะไร
        $this->withSession([Authenticate::SESSION_KEY => $outsider->id])
            ->get('/overview')
            ->assertOk()
            ->assertDontSeeText('PR26-0902')
            ->assertSeeText('ยังไม่มีเอกสารที่ส่งออกไปแล้ว');

        // เปิดอ่านได้ · ใบร่างเปิดจากหน้านี้ไม่ได้ · คนนอกเปิดไม่ได้
        $this->withSession([Authenticate::SESSION_KEY => $purchasing->id])
            ->get('/overview/'.$done->id)->assertOk();
        $this->withSession([Authenticate::SESSION_KEY => $purchasing->id])
            ->get('/overview/'.$draft->id)->assertNotFound();
        $this->withSession([Authenticate::SESSION_KEY => $outsider->id])
            ->get('/overview/'.$done->id)->assertForbidden();

        // ปุ่มดาวน์โหลด Excel เห็นเฉพาะฝ่ายจัดซื้อขั้นจัดทำเอกสารและ admin (D-073)
        $page->assertSeeText('ดาวน์โหลด Excel');
        $this->withSession([Authenticate::SESSION_KEY => $outsider->id])
            ->get('/overview')
            ->assertDontSeeText('ดาวน์โหลด Excel');
        $this->withSession([Authenticate::SESSION_KEY => $outsider->id])
            ->get('/overview-export')
            ->assertForbidden();

        $excel = $this->withSession([Authenticate::SESSION_KEY => $purchasing->id])->get('/overview-export');
        $excel->assertOk();
        $this->assertStringContainsString('spreadsheetml', $excel->headers->get('content-type'));

        // ดาวน์โหลด PDF ได้เฉพาะใบที่อนุมัติครบ และเฉพาะคนที่เกี่ยวข้อง
        // (สองเคสนี้ตกด่านตรวจก่อนถึงขั้นเรียก Chrome จึงไม่ต้องมี Chrome ตอนรันเทสต์)
        $this->withSession([Authenticate::SESSION_KEY => $purchasing->id])
            ->get('/overview/'.$sent->id.'/pdf')->assertNotFound();
        $this->withSession([Authenticate::SESSION_KEY => $outsider->id])
            ->get('/overview/'.$done->id.'/pdf')->assertForbidden();
    }

    /** พิมพ์ `-` ในช่อง Rev.1 = ไม่ต่อรองราคา ต้องยังอยู่หลังบันทึกและหลังแปะลายเซ็น */
    public function test_dash_in_revision_price_is_remembered_as_no_negotiation(): void
    {
        $purchasing = AppUser::create([
            'insight_id' => 920,
            'id_thai_hash' => 'dash-purchasing-hash',
            'company' => 'SUPAVUT INDUSTRY',
            'employee_code' => 'PUR920',
            'role' => 'user',
            'full_name_th' => 'ฝ่ายจัดซื้อต่อรองราคา',
            'signature' => 'data:image/png;base64,AAA',
        ]);

        DocumentRole::create(['step_no' => 1, 'role' => 'purchasing', 'duty' => 'create']);
        DocumentRole::create(['step_no' => 2, 'role' => 'user', 'duty' => 'select']);
        $negotiateStep = DocumentRole::create(['step_no' => 3, 'role' => 'purchasing', 'duty' => 'negotiate']);
        $negotiateStep->addMember($purchasing->id_thai_hash);

        $document = PrDocument::create([
            'pr_number' => 'PR26-0701',
            'document_date' => '2026-08-05',
            'company' => 'SUPAVUT INDUSTRY',
            'supplier_count' => 2,
            'status' => 'negotiating',
            'created_by_id_thai_hash' => $purchasing->id_thai_hash,
            'requester_id_thai_hash' => $purchasing->id_thai_hash,
            'requester_name' => $purchasing->displayName(),
        ]);

        $document->ensureSuppliers();
        $document->suppliers()->where('slot', 1)->update(['is_selected' => true, 'name' => 'ผู้ขาย ก']);
        $item = $document->items()->create(['row_no' => 1, 'item_code' => 'SIR-001', 'description' => 'ของ', 'qty' => 2, 'unit' => 'ชิ้น']);
        $supplier = $document->suppliers()->where('slot', 1)->first();
        $item->prices()->create(['pr_supplier_id' => $supplier->id, 'unit_price' => 100]);

        // พิมพ์ "-" แล้วบันทึก
        $this->withSession([Authenticate::SESSION_KEY => $purchasing->id])
            ->post("/work/negotiate/{$document->id}", [
                'intent' => 'save_revision',
                'revision_prices' => [$item->id => [1 => '-']],
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('pr_prices', [
            'pr_item_id' => $item->id,
            'pr_supplier_id' => $supplier->id,
            'unit_price_rev' => null,
            'rev_none' => true,
        ]);

        // เปิดหน้าใหม่ (เท่ากับ refresh) ต้องยังเห็น "-" ในช่อง ไม่ใช่ช่องว่าง
        $this->withSession([Authenticate::SESSION_KEY => $purchasing->id])
            ->get("/work/negotiate/{$document->id}")
            ->assertOk()
            ->assertSee('value="-"', false);

        // แปะลายเซ็นแล้วต้องไม่ล้าง "-" ทิ้ง
        $this->withSession([Authenticate::SESSION_KEY => $purchasing->id])
            ->post("/work/negotiate/{$document->id}", [
                'intent' => 'stamp',
                'signature_step_id' => $negotiateStep->id,
                'revision_prices' => [$item->id => [1 => '-']],
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('pr_prices', ['pr_item_id' => $item->id, 'rev_none' => true]);

        // ใส่ราคาจริงทับ ต้องยกเลิกสถานะ "ไม่ต่อรอง"
        $this->withSession([Authenticate::SESSION_KEY => $purchasing->id])
            ->post("/work/negotiate/{$document->id}", [
                'intent' => 'save_revision',
                'revision_prices' => [$item->id => [1 => '90.00']],
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('pr_prices', [
            'pr_item_id' => $item->id,
            'unit_price_rev' => 90.00,
            'rev_none' => false,
        ]);

        // ลบลายเซ็นแล้วราคาที่พิมพ์ค้างไว้ต้องไม่หายไปด้วย
        $signature = $document->signatures()->where('duty', 'negotiate')->first();
        $this->withSession([Authenticate::SESSION_KEY => $purchasing->id])
            ->post("/work/negotiate/{$document->id}", [
                'intent' => 'remove_signature',
                'signature_id' => $signature->id,
                'revision_prices' => [$item->id => [1 => '85.00']],
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('pr_prices', ['pr_item_id' => $item->id, 'unit_price_rev' => 85.00]);
    }

    /** กดปุ่มเขียว "ส่งลงนามอนุมัติ" เลย โดยไม่กด "บันทึกราคา Rev.1" ก่อน ราคาต้องไม่หาย */
    public function test_send_to_approval_keeps_prices_typed_but_not_saved(): void
    {
        $purchasing = AppUser::create([
            'insight_id' => 930,
            'id_thai_hash' => 'carry-purchasing-hash',
            'company' => 'SUPAVUT INDUSTRY',
            'employee_code' => 'PUR930',
            'role' => 'user',
            'full_name_th' => 'ฝ่ายจัดซื้อส่งอนุมัติ',
            'signature' => 'data:image/png;base64,AAA',
        ]);

        DocumentRole::create(['step_no' => 1, 'role' => 'purchasing', 'duty' => 'create']);
        DocumentRole::create(['step_no' => 2, 'role' => 'user', 'duty' => 'select']);
        $negotiateStep = DocumentRole::create(['step_no' => 3, 'role' => 'purchasing', 'duty' => 'negotiate']);
        $negotiateStep->addMember($purchasing->id_thai_hash);
        DocumentRole::create(['step_no' => 4, 'role' => 'mgr_purchasing', 'duty' => 'sign']);

        $document = PrDocument::create([
            'pr_number' => 'PR26-0801',
            'document_date' => '2026-08-05',
            'company' => 'SUPAVUT INDUSTRY',
            'supplier_count' => 1,
            'status' => 'negotiating',
            'created_by_id_thai_hash' => $purchasing->id_thai_hash,
            'requester_id_thai_hash' => $purchasing->id_thai_hash,
            'requester_name' => $purchasing->displayName(),
        ]);

        $document->ensureSuppliers();
        $document->suppliers()->where('slot', 1)->update([
            'is_selected' => true,
            'name' => 'ผู้ขาย ก',
            'has_negotiation_quote' => false,
        ]);
        $item = $document->items()->create(['row_no' => 1, 'item_code' => 'SIR-002', 'description' => 'ของ', 'qty' => 1, 'unit' => 'ชิ้น']);
        $supplier = $document->suppliers()->where('slot', 1)->first();
        $item->prices()->create(['pr_supplier_id' => $supplier->id, 'unit_price' => 500]);

        // ลงนามก่อน (จำเป็นต่อการส่ง) โดยยังไม่ใส่ราคา Rev.1
        $this->withSession([Authenticate::SESSION_KEY => $purchasing->id])
            ->post("/work/negotiate/{$document->id}", [
                'intent' => 'stamp',
                'signature_step_id' => $negotiateStep->id,
            ])
            ->assertRedirect();

        // พิมพ์ราคาแล้วกดปุ่มเขียวเลย — หน้าเว็บแนบค่าปัจจุบันมากับฟอร์มส่ง
        $this->withSession([Authenticate::SESSION_KEY => $purchasing->id])
            ->post("/work/negotiate/{$document->id}/send", [
                'revision_prices' => [$item->id => [1 => '450.00']],
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('pr_documents', ['id' => $document->id, 'status' => 'waiting_mgr']);
        $this->assertDatabaseHas('pr_prices', ['pr_item_id' => $item->id, 'unit_price_rev' => 450.00]);
    }

    /** กดปฏิเสธแล้วเอกสารต้องยังอยู่ในรายการของขั้นนั้น แค่เปลี่ยนเป็นสถานะปฏิเสธ */
    public function test_rejected_document_stays_in_the_list_with_a_reason_button(): void
    {
        $purchasing = AppUser::create([
            'insight_id' => 910,
            'id_thai_hash' => 'reject-purchasing-hash',
            'company' => 'SUPAVUT INDUSTRY',
            'employee_code' => 'PUR910',
            'role' => 'user',
            'full_name_th' => 'ฝ่ายจัดซื้อต่อรอง',
            'signature' => 'data:image/png;base64,AAA',
        ]);

        DocumentRole::create(['step_no' => 1, 'role' => 'purchasing', 'duty' => 'create'])
            ->addMember($purchasing->id_thai_hash);
        DocumentRole::create(['step_no' => 2, 'role' => 'user', 'duty' => 'select']);
        $negotiateStep = DocumentRole::create(['step_no' => 3, 'role' => 'purchasing', 'duty' => 'negotiate']);
        $negotiateStep->addMember($purchasing->id_thai_hash);
        DocumentRole::create(['step_no' => 4, 'role' => 'mgr_purchasing', 'duty' => 'sign']);

        $document = PrDocument::create([
            'pr_number' => 'PR26-0601',
            'document_date' => '2026-08-05',
            'company' => 'SUPAVUT INDUSTRY',
            'supplier_count' => 3,
            'status' => 'negotiating',
            'created_by_id_thai_hash' => $purchasing->id_thai_hash,
            'requester_id_thai_hash' => $purchasing->id_thai_hash,
            'requester_name' => $purchasing->displayName(),
        ]);

        $this->withSession([Authenticate::SESSION_KEY => $purchasing->id])
            ->post("/documents/{$document->id}/reject", ['reason' => 'ราคาสูงเกินงบที่ตั้งไว้'])
            ->assertRedirect('/work/negotiate');

        $this->assertDatabaseHas('pr_documents', [
            'id' => $document->id,
            'status' => 'rejected_3',
            'rejected_reason' => 'ราคาสูงเกินงบที่ตั้งไว้',
        ]);

        // ยังอยู่ในรายการของขั้นต่อรองราคา · ขึ้นปฏิเสธ · มีปุ่มเหตุผลให้กดอ่าน
        $this->withSession([Authenticate::SESSION_KEY => $purchasing->id])
            ->get('/work/negotiate')
            ->assertOk()
            ->assertSeeText('PR26-0601')
            ->assertSeeText('ปฏิเสธ')
            ->assertSeeText('เหตุผล')
            ->assertSeeText('ราคาสูงเกินงบที่ตั้งไว้');

        // ขั้นจัดทำเอกสารก็เห็นเหมือนกัน เพราะใบนี้ผ่านขั้นนั้นมาแล้ว
        $this->withSession([Authenticate::SESSION_KEY => $purchasing->id])
            ->get('/work/create')
            ->assertOk()
            ->assertSeeText('PR26-0601')
            ->assertSeeText('เหตุผล');
    }

    /**
     * คนในเส้นทางลาออก = เอกสารเดินต่อไม่ได้ ระบบต้องปฏิเสธให้เอง
     *
     * ลาออกแล้วบัญชี app_users จะถูกลบ เหลือแต่แถวใน employees ที่ emp_status = 2
     */
    public function test_document_is_rejected_automatically_when_the_next_person_resigned(): void
    {
        $creator = AppUser::create([
            'insight_id' => 900,
            'id_thai_hash' => 'guard-creator-hash',
            'company' => 'SUPAVUT INDUSTRY',
            'employee_code' => 'PUR900',
            'role' => 'user',
            'full_name_th' => 'ผู้จัดทำเอกสาร',
        ]);

        $createStep = DocumentRole::create(['step_no' => 1, 'role' => 'purchasing', 'duty' => 'create']);
        $createStep->addMember($creator->id_thai_hash);
        $selectStep = DocumentRole::create(['step_no' => 2, 'role' => 'user', 'duty' => 'select']);
        $negotiateStep = DocumentRole::create(['step_no' => 3, 'role' => 'purchasing', 'duty' => 'negotiate']);

        // คนที่ลาออกแล้ว: ไม่มีบัญชีล็อกอิน เหลือแต่ประวัติพนักงาน
        Employee::create([
            'employee_code' => 'PUR901',
            'license_id' => 'guard-left-hash',
            'title' => 'น.ส.',
            'name_th' => 'ลาออก',
            'surname_th' => 'แล้วจริง',
            'resign_date' => '2026-06-20',
            'emp_status' => '2',
        ]);
        $negotiateStep->addMember('guard-left-hash');

        $running = PrDocument::create([
            'pr_number' => 'PR26-0501',
            'document_date' => '2026-08-05',
            'company' => 'SUPAVUT INDUSTRY',
            'supplier_count' => 3,
            'status' => 'sent_user',
            'created_by_id_thai_hash' => $creator->id_thai_hash,
            'requester_id_thai_hash' => $creator->id_thai_hash,
            'requester_name' => $creator->displayName(),
        ]);

        $draft = PrDocument::create([
            'pr_number' => 'PR26-0502',
            'document_date' => '2026-08-05',
            'company' => 'SUPAVUT INDUSTRY',
            'supplier_count' => 3,
            'status' => 'draft',
            'created_by_id_thai_hash' => $creator->id_thai_hash,
        ]);

        $rejected = ResignationGuard::sweep();

        $this->assertSame(['PR26-0501'], $rejected);
        $this->assertDatabaseHas('pr_documents', ['id' => $running->id, 'status' => 'rejected_3']);
        // ใบร่างยังอยู่ในมือคนทำ ห้ามปฏิเสธทิ้ง
        $this->assertDatabaseHas('pr_documents', ['id' => $draft->id, 'status' => 'draft']);

        $running->refresh();
        $this->assertStringContainsString('ลาออก', (string) $running->rejected_reason);
        $this->assertSame(ResignationGuard::REJECTED_BY, $running->rejected_by_name);

        /*
        | หน้ารายการต้องบอกครบ 3 อย่าง
        |   - ชื่อคนที่ลาออกเป็นตัวแดงใน modal "สถานะทั้งหมด"
        |   - คอลัมน์สถานะเป็น "ปฏิเสธ" ไม่ใช่ "ดำเนินการแล้ว" ของขั้นจัดทำเอกสาร
        |   - ไม่มีปุ่ม "ทำใหม่" แล้ว เหลือข้อความแดงบอกเหตุผลแทน
        */
        $list = $this->withSession([Authenticate::SESSION_KEY => $creator->id])->get('/work/create');
        $list->assertOk()
            ->assertSeeText('น.ส.ลาออก แล้วจริง')
            ->assertSee('route-resigned', false)
            ->assertSee('pill pill-off">ปฏิเสธ', false)
            ->assertDontSeeText('ทำใหม่')
            ->assertSeeText('ผู้ต่อรองราคาลาออก');

        $this->assertSame(
            ['state' => 'rejected', 'label' => 'ปฏิเสธ'],
            $running->workStatusForStep($createStep, DocumentRole::chain()),
        );

        // ขั้นที่ผ่านไปแล้วต้องยังขึ้นว่าดำเนินการแล้ว ไม่ใช่ย้อนกลับเป็น "ยังไม่ถึงขั้น"
        $states = array_column($running->routeProgress(DocumentRole::chain()), 'state');
        $this->assertSame(['completed', 'completed', 'rejected'], $states);
        $this->assertSame($selectStep->id, DocumentRole::chain()[1]->id);
    }
}
