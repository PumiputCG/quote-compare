<?php

namespace App\Http\Controllers;

use App\Models\AppUser;
use App\Models\DocumentRole;
use App\Models\ItemCode;
use App\Models\PrAttachment;
use App\Models\PrDocument;
use App\Models\PrPrice;
use App\Models\PrSupplier;
use App\Services\DocumentFilter;
use App\Services\PrNumberGenerator;
use App\Services\ResignationGuard;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View as ViewContract;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * หน้า "จัดทำเอกสาร" ของ Purchasing — ใบเปรียบเทียบราคาผู้ขาย
 *
 * เข้าได้เฉพาะคนที่ admin ใส่ชื่อไว้ในขั้นที่มีการดำเนินการ `create` (ดู DocumentRole::menuFor)
 * ฟอร์มทำหน้าตาให้เหมือนฟอร์ม Excel เดิม กรอกแล้วประทับลงช่องทันที
 */
class PrDocumentController extends Controller
{
    /** ต้องอยู่ในขั้น "จัดทำเอกสาร" ของเส้นทางถึงจะเข้าหน้านี้ได้ */
    private function guard(): AppUser
    {
        $me = app('current_user');

        abort_unless(
            array_key_exists('create', DocumentRole::menuFor($me)),
            403,
            'คุณไม่ได้อยู่ในขั้นจัดทำเอกสารของเส้นทาง — ติดต่อผู้ดูแลระบบ',
        );

        return $me;
    }

    /** รายการเอกสารที่ตัวเองจัดทำ */
    public function index(Request $request): ViewContract
    {
        $me = $this->guard();
        ResignationGuard::sweepThrottled();
        $workflowSteps = DocumentRole::chain();

        $tabs = DocumentFilter::tabs('create');
        $active = DocumentFilter::activeKey($tabs, $request->query('status'));
        $scope = PrDocument::query()->where('created_by_id_thai_hash', $me->id_thai_hash);

        return view('pr.index', [
            'me' => $me,
            'workflow_steps' => $workflowSteps,
            'status_tabs' => $tabs,
            'status_counts' => DocumentFilter::counts($scope, $tabs),
            'active_status' => $active,
            'documents' => DocumentFilter::apply($scope, $tabs, $active)
                ->with([
                    'items:id,pr_document_id,item_code',
                    'signatures:id,pr_document_id,document_role_id,step_no,duty,signer_name,signed_at',
                    // ชื่อแผนกยึดจาก Insight เป็นหลัก ไม่ใช่ค่าที่ประทับไว้ตอนสร้าง
                    'requester:id,id_thai_hash,department',
                ])
                ->withCount('items')
                ->orderByDesc('id')
                ->paginate(20)
                ->withQueryString(),
        ]);
    }

    /** สร้างใบใหม่ทันทีแล้วเข้าหน้าแก้ไข — ผู้ใช้ไม่ต้องเจอฟอร์มเปล่าซ้อนฟอร์ม */
    public function store(): RedirectResponse
    {
        $me = $this->guard();

        $doc = DB::transaction(function () use ($me): PrDocument {
            $document = PrDocument::create([
                // จองเลขไว้ตั้งแต่สร้าง แต่ยังแสดงป้าย "ใบร่าง" จนผู้ขอซื้อลงนาม
                'pr_number' => app(PrNumberGenerator::class)->next(),
                'document_date' => now()->toDateString(),
                'company' => $me->companyList()[0] ?? 'SUPAVUT INDUSTRY',
                'supplier_count' => 3,
                'status' => 'draft',
                'created_by_id_thai_hash' => $me->id_thai_hash,
            ]);

            $document->ensureSuppliers();
            $document->items()->create(['row_no' => 1]);

            return $document;
        });

        return redirect()->route('documents.edit', $doc);
    }

    public function edit(PrDocument $document): ViewContract
    {
        $me = $this->guard();
        $this->mine($document, $me);

        $document->ensureSuppliers();
        $document->load(['suppliers.attachments', 'items.prices', 'signatures']);
        $workflowSteps = DocumentRole::chain();
        $requester = $document->requester_id_thai_hash
            ? AppUser::where('id_thai_hash', $document->requester_id_thai_hash)->first()
            : null;

        return view('pr.form', [
            'me' => $me,
            'doc' => $document,
            'workflow_steps' => $workflowSteps,
            'requester_user' => $requester,
            'signature_by_step' => $document->signatures->keyBy('document_role_id'),
            // รายการเลือก Item Code — กลุ่มที่รันเลขได้ + รหัสที่ใช้ซ้ำ (ดู ItemCode::pickerOptions)
            'item_code_options' => ItemCode::pickerOptions(),
            'signable_step_ids' => $workflowSteps
                ->filter(fn (DocumentRole $step): bool => $step->duty === 'create'
                    && $this->canSignStep($document, $step, $me))
                ->pluck('id')
                ->all(),
            // แท็บจัดทำเอกสารไม่มีปุ่มปฏิเสธ — คนทำเองปฏิเสธของตัวเองไม่ได้ ถ้าไม่เอาก็กดลบ
            // ปุ่มปฏิเสธอยู่ที่แท็บผู้ขอซื้อ · ต่อรองราคา · ลงนามอนุมัติ เท่านั้น
            'can_reject' => false,
        ]);
    }

    /**
     * บันทึกทั้งใบในครั้งเดียว — หัวเอกสาร · ผู้ขาย · รายการ · ราคา
     *
     * รายการที่ถูกลบออกจากฟอร์มจะถูกลบจริงใน DB ด้วย (ราคาหายตาม cascade)
     */
    public function update(Request $request, PrDocument $document): RedirectResponse
    {
        $me = $this->guard();
        $this->mine($document, $me);

        // ส่งให้ผู้ขอซื้อแล้ว = แก้เนื้อเอกสารไม่ได้ · เหลือแค่คำสั่งเรื่องลายเซ็นที่ยังผ่านเข้ามาได้
        $editable = $document->status === 'draft';
        abort_if(
            ! $editable && in_array($request->input('intent', 'draft'), ['draft', 'send'], true),
            409,
            'เอกสารส่งให้ผู้ขอซื้อแล้ว แก้ไขไม่ได้',
        );

        $this->stripThousandSeparators($request);

        $data = $request->validate([
            'purpose' => ['nullable', 'string', 'max:2000'],
            'comment' => ['nullable', 'string', 'max:2000'],
            'intent' => ['nullable', 'in:draft,send,stamp,remove_signature'],
            'item_count' => ['required', 'integer', 'min:1', 'max:50'],
            'signature_step_id' => ['nullable', 'integer', 'exists:document_roles,id'],
            'signature_id' => ['nullable', 'integer', 'exists:pr_document_signatures,id'],
            'supplier_count' => ['required', 'integer', 'min:1', 'max:'.PrDocument::MAX_SUPPLIERS],
            'currency' => ['nullable', 'string', Rule::in(array_keys(PrDocument::CURRENCIES))],
            'requester_id_thai_hash' => ['nullable', 'string', 'exists:app_users,id_thai_hash'],

            'suppliers' => ['array'],
            'suppliers.*.name' => ['nullable', 'string', 'max:255'],
            'suppliers.*.lead_time' => ['nullable', 'string', 'max:120'],
            'suppliers.*.term_of_payment' => ['nullable', 'string', 'max:191'],
            'suppliers.*.remark' => ['nullable', 'string', 'max:2000'],

            'items' => ['required', 'array', 'min:1', 'max:50'],
            // เลือกรหัสหมวดที่มีอยู่แล้ว หรือกรอกรหัสใหม่จาก ERP ที่ยังไม่ได้ import (D-043)
            // ไม่บังคับ exists แต่บังคับรูปแบบ — กันขยะเข้าทะเบียนโดยไม่บล็อกรหัสใหม่ของจริง
            'items.*.item_code' => ['nullable', 'string', 'max:60', 'regex:/^[A-Za-z0-9][A-Za-z0-9._\/-]{1,59}$/'],
            'items.*.description' => ['nullable', 'string', 'max:2000'],
            'items.*.qty' => ['nullable', 'numeric', 'min:0'],
            'items.*.unit' => ['nullable', 'string', 'max:20'],
            'items.*.prices' => ['array'],
            'items.*.prices.*' => ['nullable', 'numeric', 'min:0'],
        ], [
            'items.*.item_code.regex' => 'Item Code ใส่ได้เฉพาะตัวอักษร ตัวเลข และ - . _ /',
        ]);

        if (count($data['items']) !== (int) $data['item_count']) {
            throw ValidationException::withMessages([
                'item_count' => 'จำนวนรายการสินค้าไม่ตรงกับแถวในเอกสาร กรุณาลองใหม่อีกครั้ง',
            ]);
        }

        // เขียนข้อมูลเฉพาะตอนยังเป็นใบร่าง ส่งแล้วปล่อยผ่านไปจัดการเรื่องลายเซ็นอย่างเดียว
        $editable && DB::transaction(function () use ($request, $document, $data) {
            // ── หัวเอกสาร ──
            // ⚠️ เขียนทับเฉพาะช่องที่ส่งมาจริง — ถ้าใช้ ?? '' ช่องที่ไม่ได้ส่งจะโดนล้างทิ้ง
            $document->supplier_count = $data['supplier_count'];

            if ($request->has('currency')) {
                $document->currency = $data['currency'] ?? 'THB';
            }

            // 'comment' ย้ายไปเป็นช่องของผู้ขอซื้อแล้ว (D-055)
            foreach (['purpose'] as $field) {
                if ($request->has($field)) {
                    $document->{$field} = trim((string) ($data[$field] ?? '')) ?: null;
                }
            }

            if ($request->has('requester_id_thai_hash')) {
                $requester = empty($data['requester_id_thai_hash'])
                    ? null
                    : AppUser::where('id_thai_hash', $data['requester_id_thai_hash'])->first();

                $document->requester_id_thai_hash = $requester?->id_thai_hash;
                $document->requester_name = $requester?->displayName();
                // ประทับ "แผนก" ของคนที่เลือก ไม่ใช่ชื่อคน (ตามฟอร์มเดิม)
                $document->requester_department = $requester?->department;
            }

            $document->save();

            // ── ผู้ขาย (มีครบ 3 ช่องเสมอ อัปเดตตาม slot) ──
            foreach ($data['suppliers'] ?? [] as $slot => $row) {
                $document->suppliers()
                    ->where('slot', (int) $slot)
                    ->update([
                        'name' => trim((string) ($row['name'] ?? '')) ?: null,
                        'lead_time' => $row['lead_time'] ?? null,
                        'term_of_payment' => $row['term_of_payment'] ?? null,
                        'remark' => $row['remark'] ?? null,
                        'updated_at' => now(),
                    ]);
            }

            $document->load('suppliers');
            $slotToId = $document->suppliers->pluck('id', 'slot');

            // ── รายการสินค้า ──
            $keep = [];
            $rowNo = 0;

            foreach ($data['items'] ?? [] as $key => $row) {
                $rowNo++;

                // key เป็นตัวเลข = แถวเดิมที่มี id ; ขึ้นต้นด้วย new = แถวที่เพิ่งเพิ่ม
                $item = is_numeric($key)
                    ? $document->items()->find((int) $key)
                    : null;

                $code = trim((string) ($row['item_code'] ?? '')) ?: null;
                $previous_code = $item?->item_code;

                // รหัสใหม่ที่ Purchasing กรอกเอง -> ลงทะเบียนไว้ให้เลือกซ้ำได้ครั้งหน้า (D-043)
                if ($code) {
                    ItemCode::registerTyped($code);
                }

                $payload = [
                    'row_no' => $rowNo,
                    'item_code' => $code,
                    'description' => $row['description'] ?? null,
                    'qty' => ($row['qty'] ?? '') === '' ? null : $row['qty'],
                    'unit' => trim((string) ($row['unit'] ?? '')) ?: null,
                ];

                $item = $item ? tap($item)->update($payload) : $document->items()->create($payload);
                $keep[] = $item->id;

                // เปลี่ยนรหัสในใบร่าง -> คืนเลขเดิมถ้าไม่มีใครใช้ จะได้ไม่เป็นรูโหว่
                if ($document->status === 'draft' && $previous_code && $previous_code !== $code) {
                    ItemCode::releaseIfUnused($previous_code);
                }

                // ชื่อในทะเบียนรหัสมาจากสิ่งที่ Purchasing พิมพ์จริงเท่านั้น ไม่ลอกจากรหัสก่อนหน้า
                if ($code) {
                    ItemCode::rememberName($code, $payload['description']);
                }

                // ── ราคาของแต่ละผู้ขาย ──
                foreach ($row['prices'] ?? [] as $slot => $price) {
                    $supplierId = $slotToId[(int) $slot] ?? null;

                    if (! $supplierId) {
                        continue;
                    }

                    PrPrice::updateOrCreate(
                        ['pr_item_id' => $item->id, 'pr_supplier_id' => $supplierId],
                        ['unit_price' => $price === '' || $price === null ? null : $price],
                    );
                }
            }

            // แถวที่หายไปจากฟอร์ม = ผู้ใช้ลบทิ้ง
            $dropped = $document->items()->whereNotIn('id', $keep ?: [0])->pluck('item_code');
            $document->items()->whereNotIn('id', $keep ?: [0])->delete();

            // ลบแถวในใบร่างแล้วคืนเลขที่ระบบออกให้ ถ้าไม่มีใบไหนใช้อยู่
            if ($document->status === 'draft') {
                $dropped->filter()->each(fn (string $code) => ItemCode::releaseIfUnused($code));
            }
        });

        if (($data['intent'] ?? 'draft') === 'send') {
            return $this->send($document->fresh());
        }

        if (($data['intent'] ?? 'draft') === 'stamp') {
            return $this->stampSignature($document->fresh(), (int) ($data['signature_step_id'] ?? 0), $me);
        }

        if (($data['intent'] ?? 'draft') === 'remove_signature') {
            return $this->removeSignature($document->fresh(), (int) ($data['signature_id'] ?? 0), $me);
        }

        return back()->with('success', 'บันทึกแล้ว');
    }

    /**
     * ค้นรหัสที่มีอยู่แล้วในทะเบียน — ค้นทั้งรหัสและชื่อของที่เคยซื้อ
     *
     * ทะเบียนมีพันกว่ารหัส ส่งไปทั้งก้อนทำให้หน้าหนักโดยใช่เหตุ
     * หน้าเว็บจึงฝังไว้แค่ชุดแรก แล้วยิงมาที่นี่ตอนพิมพ์ค้น
     */
    public function searchItemCodes(Request $request): JsonResponse
    {
        $this->guard();

        return response()->json([
            'options' => ItemCode::searchExisting((string) $request->query('q', ''), 60)->all(),
        ]);
    }

    /** ส่งเอกสารต่อไปยังผู้ขอซื้อตามเส้นทาง */
    public function send(PrDocument $document): RedirectResponse
    {
        $me = $this->guard();
        $this->mine($document, $me);

        if (! $document->requester_id_thai_hash) {
            return back()->with('error', 'ยังไม่ได้เลือกผู้ขอซื้อ (Department Request)');
        }

        $missing = $this->missingPurchaseFields($document);
        if ($missing !== []) {
            return back()
                ->withErrors($missing)
                ->with('error', 'กรอกข้อมูลที่แสดงกรอบสีแดงให้ครบก่อนส่งเอกสาร');
        }

        $document->update(['status' => 'sent_user']);

        return redirect()
            ->route('documents.index')
            ->with('success', "ส่ง {$document->referenceLabel()} ให้ {$document->requester_name} แล้ว");
    }

    /**
     * ลบเอกสาร — ได้จนกว่าผู้ขอซื้อจะลงนามคัดเลือกรายการ
     *
     * ลายเซ็น Purchasing ขั้นจัดทำเอกสารยังลบได้ เพราะเลขที่เห็นยังเป็นเลขใบร่าง
     * หลังลายเซ็นคัดเลือกรายการ เลข PR ถูกยืนยันและต้องใช้ "ปฏิเสธ" แทนการลบ
     */
    public function destroy(PrDocument $document): RedirectResponse
    {
        $me = $this->guard();
        $this->mine($document, $me);

        abort_unless(
            $document->canDelete(),
            403,
            'ผู้ขอซื้อลงนามคัดเลือกรายการแล้ว เอกสารเริ่ม Process และลบไม่ได้',
        );

        $number = $document->referenceLabel();

        // ลบไฟล์แนบออกจากดิสก์ก่อน แถวใน DB หายตาม cascade
        foreach ($document->suppliers as $supplier) {
            foreach ($supplier->attachments as $file) {
                Storage::delete($file->path);
            }
        }

        $codes = $document->items()->pluck('item_code');
        $document->delete();

        // คืนเลข Item Code ที่ระบบออกให้แต่ไม่ได้ใช้จริง
        $codes->filter()->each(fn (string $code) => ItemCode::releaseIfUnused($code));

        return redirect()->route('documents.index')->with('success', "ลบ {$number} แล้ว");
    }

    /**
     * ปฏิเสธเอกสาร — ไม่ลบ แต่ปิดใบไว้เป็นหลักฐาน
     *
     * ตรงกับระบบ PR เดิมที่ใช้สถานะ `1:Cancel` แล้วเก็บแถวไว้ทั้งหมด
     */
    public function reject(Request $request, PrDocument $document): RedirectResponse
    {
        $me = app('current_user');

        $data = $request->validate([
            'reason' => ['required', 'string', 'max:500'],
        ], [
            'reason.required' => 'ต้องระบุเหตุผลที่ปฏิเสธ',
        ]);

        $step = $this->rejectableStep($document, $me);

        abort_unless($step, 403, 'คุณปฏิเสธเอกสารในขั้นนี้ไม่ได้');

        $document->update([
            'status' => 'rejected_'.$step['order'],
            'rejected_reason' => trim($data['reason']),
            'rejected_by_name' => $me->displayName(),
            'rejected_at' => now(),
        ]);

        // กลับไปหน้ารายการของขั้นที่ตัวเองอยู่ — ผู้ขอซื้อ/CEO เข้า /work/create ไม่ได้
        $back = match ($step['step']->duty) {
            'select' => route('work.select'),
            'negotiate' => route('work.negotiate'),
            'confirm' => route('work.confirm'),
            'sign' => route('work', 'sign'),
            default => route('documents.index'),
        };

        return redirect($back)->with('success', "ปฏิเสธ {$document->referenceLabel()} แล้ว");
    }

    // ── ไฟล์แนบ (ผูกกับผู้ขายแต่ละเจ้า) ────────────────────────────────

    public function uploadAttachment(Request $request, PrSupplier $supplier): RedirectResponse
    {
        $me = $this->guard();
        $this->mine($supplier->document, $me);
        $this->stillEditable($supplier->document);

        $request->validate([
            'files' => ['required', 'array'],
            'files.*' => ['file', 'max:'.PrAttachment::MAX_KB, 'mimes:'.implode(',', PrAttachment::ALLOWED)],
        ], [], ['files.*' => 'ไฟล์แนบ']);

        foreach ($request->file('files', []) as $file) {
            $path = $file->store('pr-attachments/'.$supplier->pr_document_id.'/'.PrAttachment::STAGE_CREATE);

            $supplier->attachments()->create([
                'stage' => PrAttachment::STAGE_CREATE,
                'original_name' => $file->getClientOriginalName(),
                'path' => $path,
                'mime' => $file->getClientMimeType(),
                'size' => $file->getSize(),
                'uploaded_by_id_thai_hash' => $me->id_thai_hash,
            ]);
        }

        return back()->with('success', 'แนบไฟล์แล้ว');
    }

    public function downloadAttachment(PrAttachment $attachment): StreamedResponse
    {
        $this->canAccess($attachment->supplier->document, app('current_user'));

        abort_unless(Storage::exists($attachment->path), 404);

        return Storage::download($attachment->path, $attachment->original_name);
    }

    /**
     * เปิดไฟล์ในแท็บใหม่ (inline) — ไม่บังคับดาวน์โหลด
     *
     * ใช้ 2 อย่าง: แสดงรูปย่อในการ์ดไฟล์แนบ และเปิดดู PDF ในเบราว์เซอร์
     * ไฟล์ที่เบราว์เซอร์เปิดเองไม่ได้ (docx/xlsx) จะกลายเป็นดาวน์โหลดตามปกติ
     */
    public function viewAttachment(PrAttachment $attachment): StreamedResponse
    {
        $this->canAccess($attachment->supplier->document, app('current_user'));

        abort_unless(Storage::exists($attachment->path), 404);

        return Storage::response($attachment->path, $attachment->original_name, [
            'Content-Type' => $attachment->mime ?: 'application/octet-stream',
            'Cache-Control' => 'private, max-age=3600',
        ]);
    }

    public function deleteAttachment(PrAttachment $attachment): RedirectResponse
    {
        $me = $this->guard();
        $this->mine($attachment->supplier->document, $me);
        abort_unless($attachment->stage === PrAttachment::STAGE_CREATE, 403, 'ลบได้เฉพาะไฟล์ขั้นจัดทำเอกสาร');
        $this->stillEditable($attachment->supplier->document);

        Storage::delete($attachment->path);
        $name = $attachment->original_name;
        $attachment->delete();

        return back()->with('success', "ลบไฟล์ {$name} แล้ว");
    }

    /** ค้นหาพนักงานสำหรับช่อง Department Request (JSON) */
    public function searchPeople(Request $request): JsonResponse
    {
        $this->guard();

        $keyword = trim((string) $request->query('q', ''));

        $people = AppUser::query()
            ->whereNotNull('id_thai_hash')
            ->when($keyword !== '', function ($q) use ($keyword) {
                $like = '%'.$keyword.'%';
                $q->where(fn ($w) => $w
                    ->where('full_name_th', 'like', $like)
                    ->orWhere('full_name_en', 'like', $like)
                    ->orWhere('employee_code', 'like', $like)
                    ->orWhere('department', 'like', $like)
                    ->orWhere('position', 'like', $like));
            })
            ->orderBy('full_name_th')
            ->limit(25)
            ->get()
            ->map(fn (AppUser $u): array => [
                'id_thai_hash' => $u->id_thai_hash,
                'name' => $u->displayName(),
                'code' => (string) $u->employee_code,
                'department' => (string) ($u->department ?: '—'),
                'position' => (string) ($u->position ?: ''),
                'avatar' => $u->avatarUrl(),
            ]);

        return response()->json(['people' => $people]);
    }

    /** เอกสารต้องเป็นของคนที่กำลังเปิดอยู่ */
    /**
     * ส่งให้ผู้ขอซื้อแล้วห้ามแก้เอกสารและห้ามแตะไฟล์แนบ
     *
     * เอกสารออกจากมือ Purchasing ไปแล้ว ถ้ายังแก้ได้ ผู้ขอซื้อจะเห็นข้อมูลคนละชุดกับตอนกดเลือก
     * (ลบทั้งใบยังทำได้จนกว่าผู้ขอซื้อจะลงนาม ตาม D-044 — คนละเรื่องกับการแก้เนื้อเอกสาร)
     */
    private function stillEditable(PrDocument $document): void
    {
        abort_unless($document->status === 'draft', 409, 'เอกสารส่งให้ผู้ขอซื้อแล้ว แก้ไขไม่ได้');
    }

    /**
     * ถอดลูกน้ำคั่นหลักพันออกจากช่องราคาก่อน validate
     *
     * หน้าเว็บถอดให้ตอน submit อยู่แล้ว อันนี้เป็นชั้นกันพลาดเผื่อ JS ไม่ทำงาน
     * และแปลง `-` (= ไม่มีการต่อรองราคา) ให้เป็นค่าว่าง เพื่อให้ผ่าน rule `numeric`
     */
    public static function stripThousandSeparators(Request $request): void
    {
        $clean = static function ($value) use (&$clean) {
            if (is_array($value)) {
                return array_map($clean, $value);
            }

            if (! is_string($value)) {
                return $value;
            }

            $value = str_replace(',', '', $value);

            return trim($value) === '-' ? null : $value;
        };

        foreach (['items', 'revision_prices'] as $field) {
            if ($field === 'items' && is_array($request->input('items'))) {
                $items = $request->input('items');

                foreach ($items as $key => $row) {
                    if (isset($row['prices'])) {
                        $items[$key]['prices'] = $clean($row['prices']);
                    }
                }

                $request->merge(['items' => $items]);

                continue;
            }

            if (is_array($request->input($field))) {
                $request->merge([$field => $clean($request->input($field))]);
            }
        }
    }

    private function mine(PrDocument $document, AppUser $me): void
    {
        abort_unless(
            $document->created_by_id_thai_hash === $me->id_thai_hash,
            403,
            'เอกสารใบนี้ไม่ใช่ของคุณ',
        );
    }

    /** ผู้จัดทำและผู้ขอซื้อที่ระบุในใบเปิดดูเอกสารแนบได้ */
    private function canAccess(PrDocument $document, AppUser $me): void
    {
        $canNegotiate = array_key_exists('negotiate', DocumentRole::menuFor($me));

        abort_unless(
            $canNegotiate || in_array($me->id_thai_hash, [
                $document->created_by_id_thai_hash,
                $document->requester_id_thai_hash,
            ], true),
            403,
            'คุณไม่มีสิทธิ์เปิดเอกสารใบนี้',
        );
    }

    private function stampSignature(PrDocument $document, int $stepId, AppUser $me): RedirectResponse
    {
        $step = DocumentRole::with('members')->find($stepId);

        if (! $step) {
            return back()->with('error', 'คุณไม่ได้เป็นผู้ลงนามในขั้นนี้');
        }

        if ($step->duty !== 'create') {
            return back()->with('error', 'กรุณาลงนามขั้นต่อรองราคาจากแท็บต่อรองราคา');
        }

        if (! $step->requiresSignature()) {
            return back()->with('error', 'ขั้นนี้ไม่ใช่จุดประทับลายเซ็น');
        }

        if (! $step->canSignInStatus($document->status)) {
            return back()->with('error', "เอกสารยังไม่ถึงขั้น{$step->dutyLabel()}");
        }

        if (! $this->canSignStep($document, $step, $me)) {
            return back()->with('error', 'คุณไม่ได้เป็นผู้ลงนามในขั้นนี้');
        }

        if (trim((string) $me->signature) === '') {
            return back()->with('error', 'ยังไม่พบลายเซ็นของคุณใน Insight');
        }

        $existing = $document->signatures()
            ->where('document_role_id', $step->id)
            ->first();

        if ($existing && $existing->signer_id_thai_hash !== $me->id_thai_hash) {
            return back()->with('error', "ขั้นนี้ลงนามโดย {$existing->signer_name} แล้ว");
        }

        DB::transaction(function () use ($document, $step, $me): void {
            $lockedDocument = PrDocument::query()->lockForUpdate()->findOrFail($document->id);

            $lockedDocument->signatures()->updateOrCreate(
                ['document_role_id' => $step->id],
                [
                    'step_no' => $step->step_no,
                    'role' => $step->role,
                    'duty' => $step->duty,
                    'signer_id_thai_hash' => $me->id_thai_hash,
                    'signer_name' => $me->displayName(),
                    'signature_data' => $me->signature,
                    'signed_at' => now(),
                ],
            );

            if ($step->duty === 'select') {
                $lockedDocument->status = 'negotiating';
                $lockedDocument->save();
            }
        });

        return back()->with('success', "ประทับลายเซ็น {$step->roleLabel()} แล้ว");
    }

    /**
     * ช่องที่ Purchase ต้องกรอกก่อนส่ง — Comment และไฟล์แนบเป็นข้อมูลเสริม
     *
     * @return array<string,string>
     */
    private function missingPurchaseFields(PrDocument $document): array
    {
        $document->loadMissing(['suppliers', 'items.prices', 'signatures']);
        $errors = [];

        if (trim((string) $document->purpose) === '') {
            $errors['purpose'] = 'กรุณากรอก Purpose of the Purchase';
        }

        if (trim((string) $document->requester_id_thai_hash) === '') {
            $errors['requester_id_thai_hash'] = 'กรุณาเลือกผู้ขอซื้อ';
        }

        if ($document->signatures->where('duty', 'create')->whereNotNull('signed_at')->isEmpty()) {
            $errors['purchase_signature'] = 'กรุณาประทับลายเซ็น Purchasing จัดทำเอกสาร';
        }

        foreach ($document->activeSuppliers() as $supplier) {
            $slot = $supplier->slot;
            foreach ([
                'name' => 'ชื่อ Supplier',
                'lead_time' => 'Lead Time',
                'term_of_payment' => 'Term of Payment',
                'remark' => 'Remark',
            ] as $field => $label) {
                if (trim((string) $supplier->{$field}) === '') {
                    $errors["suppliers.{$slot}.{$field}"] = "กรุณากรอก {$label} ของผู้ขายที่ {$slot}";
                }
            }

            // ต้องมีใบเสนอราคาแนบมาด้วยทุกบริษัท ไม่งั้นผู้ขอซื้อไม่มีอะไรให้เทียบ
            $quotes = $supplier->relationLoaded('attachments')
                ? $supplier->attachments
                : $supplier->attachments()->get();

            if ($quotes->where('stage', PrAttachment::STAGE_CREATE)->isEmpty()) {
                $errors["attachments.{$slot}"] = "กรุณาแนบไฟล์ใบเสนอราคาของผู้ขายที่ {$slot}";
            }
        }

        foreach ($document->items as $item) {
            $key = $item->id;
            $row = $item->row_no;

            foreach ([
                'item_code' => 'Item Code',
                'description' => 'Item Description',
                'unit' => 'หน่วย',
            ] as $field => $label) {
                if (trim((string) $item->{$field}) === '') {
                    $errors["items.{$key}.{$field}"] = "กรุณากรอก {$label} รายการที่ {$row}";
                }
            }

            if ($item->qty === null || (float) $item->qty <= 0) {
                $errors["items.{$key}.qty"] = "กรุณากรอกจำนวนมากกว่า 0 รายการที่ {$row}";
            }

            foreach ($document->activeSuppliers() as $supplier) {
                $price = $item->prices->firstWhere('pr_supplier_id', $supplier->id);
                if ($price?->unit_price === null) {
                    $errors["items.{$key}.prices.{$supplier->slot}"] = "กรุณากรอก Unit Price ผู้ขายที่ {$supplier->slot} รายการที่ {$row}";
                }
            }
        }

        return $errors;
    }

    private function removeSignature(PrDocument $document, int $signatureId, AppUser $me): RedirectResponse
    {
        $signature = $document->signatures()->find($signatureId);

        if (! $signature) {
            return back()->with('error', 'ไม่พบลายเซ็นที่ต้องการลบ');
        }

        if ($signature->duty !== 'create') {
            return back()->with('error', 'กรุณาจัดการลายเซ็นขั้นต่อรองราคาจากแท็บต่อรองราคา');
        }

        if ($signature->signer_id_thai_hash !== $me->id_thai_hash) {
            return back()->with('error', 'ลบได้เฉพาะลายเซ็นของตัวเอง');
        }

        $role = DocumentRole::ROLES[$signature->role] ?? $signature->role;
        $signature->delete();

        return back()->with('success', "ลบลายเซ็น {$role} แล้ว");
    }

    /**
     * ขั้นที่คนนี้ปฏิเสธเอกสารใบนี้ได้ — ต้องไม่ใช่ขั้นแรก
     *
     * ขั้นแรกคือ Purchasing ที่จัดทำเอกสารเอง ปฏิเสธของตัวเองไม่มีความหมาย
     * ถ้าไม่เอาแล้วก็กดลบทิ้งไปเลย (ยังไม่มีลายเซ็นใครอยู่แล้ว)
     *
     * @return array{step:DocumentRole,order:int}|null
     */
    private function rejectableStep(PrDocument $document, AppUser $me): ?array
    {
        foreach (DocumentRole::chain()->values() as $index => $step) {
            // ขั้นแรกปฏิเสธไม่ได้ · ขั้นที่ยังไม่ถึงคิวก็ปฏิเสธไม่ได้
            if ($index === 0 || ! $step->requiresSignature() || ! $step->canSignInStatus($document->status)) {
                continue;
            }

            // ไม่ใช้ canSignStep เพราะขั้น User ถูกกันไว้ให้ลงนามจากแท็บผู้ขอซื้อเท่านั้น
            // แต่ "ปฏิเสธ" ผู้ขอซื้อต้องทำได้ (D-048)
            $mine = $step->isUserStep()
                ? $document->requester_id_thai_hash === $me->id_thai_hash
                : $step->members->contains('id_thai_hash', $me->id_thai_hash);

            if ($mine) {
                return ['step' => $step, 'order' => $index + 1];
            }
        }

        return null;
    }

    private function canSignStep(PrDocument $document, DocumentRole $step, AppUser $me): bool
    {
        if (! $step->requiresSignature()) {
            return false;
        }

        if (! $step->canSignInStatus($document->status)) {
            return false;
        }

        if ($step->isUserStep()) {
            // ขั้น User ต้องเลือก Supplier และลงนามจากแท็บผู้ขอซื้อเท่านั้น
            return false;
        }

        return $step->members->contains('id_thai_hash', $me->id_thai_hash);
    }
}
