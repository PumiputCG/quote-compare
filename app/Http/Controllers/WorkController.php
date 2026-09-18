<?php

namespace App\Http\Controllers;

use App\Models\AppUser;
use App\Models\DocumentRole;
use App\Models\PrAttachment;
use App\Models\PrDocument;
use App\Models\PrPrice;
use App\Models\PrSupplier;
use App\Services\DocumentFilter;
use App\Services\ResignationGuard;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View as ViewContract;

/**
 * หน้าทำงานของแต่ละขั้นในเส้นทางเอกสาร (`/work/{duty}`)
 *
 * เมนูและสิทธิ์เข้าหน้าไม่ได้ hardcode — อ่านจากเส้นทางที่ admin กำหนดใน "ตั้งค่าระบบ"
 * ใครไม่ได้ถูกใส่ชื่อไว้ในขั้นนั้นจะเข้าไม่ได้ (403)
 *
 * ตอนนี้ยังเป็นหน้าเปล่า รอเฟสฟอร์มเปรียบเทียบราคา
 */
class WorkController extends Controller
{
    public function selectIndex(Request $request): ViewContract
    {
        $me = $this->guardDuty('select');
        ResignationGuard::sweepThrottled();
        $workflowSteps = DocumentRole::chain();

        $tabs = DocumentFilter::tabs('select');
        $active = DocumentFilter::activeKey($tabs, $request->query('status'));
        $scope = PrDocument::query()
            ->where('requester_id_thai_hash', $me->id_thai_hash)
            ->where('status', '!=', 'draft');

        return view('work.select', [
            'me' => $me,
            'page_title' => 'ผู้ขอซื้อ',
            'list_mode' => 'select',
            'open_route_name' => 'work.select.edit',
            'empty_message' => 'ยังไม่มีเอกสารส่งถึงคุณ',
            'workflow_steps' => $workflowSteps,
            'status_tabs' => $tabs,
            'status_counts' => DocumentFilter::counts($scope, $tabs),
            'active_status' => $active,
            'documents' => DocumentFilter::apply($scope, $tabs, $active)
                ->with([
                    'creator:id,id_thai_hash,department',
                    'requester:id,id_thai_hash,department',
                    'suppliers',
                    'items:id,pr_document_id,item_code',
                    'signatures',
                ])
                ->withCount('items')
                ->orderByRaw("CASE WHEN status = 'sent_user' THEN 0 ELSE 1 END")
                ->orderByDesc('id')
                ->paginate(20)
                ->withQueryString(),
        ]);
    }

    public function selectEdit(PrDocument $document): ViewContract
    {
        $me = $this->guardDuty('select');
        $this->assignedTo($document, $me);

        $document->ensureSuppliers();
        $document->load(['suppliers.attachments', 'items.prices', 'signatures']);
        $workflowSteps = DocumentRole::chain();
        $selectStep = $workflowSteps->first(fn (DocumentRole $step): bool => $step->duty === 'select');
        $requester = $document->requester_id_thai_hash
            ? AppUser::where('id_thai_hash', $document->requester_id_thai_hash)->first()
            : null;

        return view('pr.form', [
            'me' => $me,
            'doc' => $document,
            'mode' => 'select',
            'workflow_steps' => $workflowSteps,
            'requester_user' => $requester,
            'signature_by_step' => $document->signatures->keyBy('document_role_id'),
            'item_code_options' => [],
            'signable_step_ids' => $document->status === 'sent_user' && $selectStep
                ? [$selectStep->id]
                : [],
            // ผู้ขอซื้อปฏิเสธได้ตอนที่เอกสารยังรอคัดเลือก (D-048)
            'can_reject' => $document->status === 'sent_user' && ! $document->isRejected(),
        ]);
    }

    public function selectUpdate(Request $request, PrDocument $document): RedirectResponse
    {
        $me = $this->guardDuty('select');
        $this->assignedTo($document, $me);

        $intent = (string) $request->input('intent', 'stamp');
        if ($intent === 'remove_signature') {
            $data = $request->validate([
                'signature_id' => ['required', 'integer', 'exists:pr_document_signatures,id'],
            ]);

            abort_unless($document->status === 'sent_user', 409, 'เอกสารถูกส่งไปขั้นต่อรองราคาแล้ว');

            $signature = $document->signatures()->find((int) $data['signature_id']);
            abort_unless(
                $signature
                    && $signature->duty === 'select'
                    && $signature->signer_id_thai_hash === $me->id_thai_hash,
                403,
                'ลบได้เฉพาะลายเซ็นคัดเลือกรายการของตนเอง',
            );

            $signature->delete();

            return redirect()
                ->route('work.select.edit', $document)
                ->with('success', 'ลบลายเซ็นคัดเลือกรายการแล้ว');
        }

        $data = $request->validate([
            'intent' => ['nullable', 'in:stamp'],
            // เลือกได้เจ้าเดียวเท่านั้น (D-075)
            'selected_suppliers' => ['required', 'array', 'size:1'],
            'selected_suppliers.*' => ['required', 'integer', 'distinct', 'min:1', 'max:'.PrDocument::MAX_SUPPLIERS],
            // Comment เป็นช่องของผู้ขอซื้อ ไม่ใช่ฝ่ายจัดซื้อ (D-055)
            'comment' => ['nullable', 'string', 'max:2000'],
        ], [
            'selected_suppliers.required' => 'กรุณาเลือก Supplier 1 บริษัทก่อนลงนาม',
            'selected_suppliers.size' => 'เลือก Supplier ได้เพียง 1 บริษัท',
        ]);

        if (trim((string) $me->signature) === '') {
            return back()->with('error', 'ยังไม่พบลายเซ็นของคุณใน Insight');
        }

        $selectStep = DocumentRole::query()
            ->where('role', DocumentRole::ROLE_USER)
            ->where('duty', 'select')
            ->orderBy('step_no')
            ->first();

        abort_unless($selectStep, 409, 'ยังไม่ได้กำหนดขั้น User คัดเลือกรายการ');

        DB::transaction(function () use ($document, $me, $selectStep, $data): void {
            $lockedDocument = PrDocument::query()->lockForUpdate()->findOrFail($document->id);
            $this->assignedTo($lockedDocument, $me);

            abort_unless($lockedDocument->status === 'sent_user', 409, 'เอกสารไม่ได้อยู่ในขั้นคัดเลือกรายการแล้ว');

            $allowedSlots = range(1, $lockedDocument->supplier_count);
            $selectedSlots = array_map('intval', $data['selected_suppliers']);
            abort_unless(array_diff($selectedSlots, $allowedSlots) === [], 422, 'Supplier ที่เลือกไม่อยู่ในเอกสาร');

            if (array_key_exists('comment', $data)) {
                $lockedDocument->comment = trim((string) $data['comment']) ?: null;
                $lockedDocument->save();
            }

            $lockedDocument->suppliers()->update(['is_selected' => false]);
            $lockedDocument->suppliers()->whereIn('slot', $selectedSlots)->update(['is_selected' => true]);
            $lockedDocument->signatures()->updateOrCreate(
                ['document_role_id' => $selectStep->id],
                [
                    'step_no' => $selectStep->step_no,
                    'role' => $selectStep->role,
                    'duty' => $selectStep->duty,
                    'signer_id_thai_hash' => $me->id_thai_hash,
                    'signer_name' => $me->displayName(),
                    'signature_data' => $me->signature,
                    'signed_at' => now(),
                ],
            );
        });

        return redirect()
            ->route('work.select.edit', $document)
            ->with('success', "บันทึกการคัดเลือกและลงนาม {$document->referenceLabel()} แล้ว กรุณาตรวจสอบก่อนส่งต่อรองราคา");
    }

    public function sendToNegotiation(PrDocument $document): RedirectResponse
    {
        $me = $this->guardDuty('select');
        $this->assignedTo($document, $me);

        $error = DB::transaction(function () use ($document, $me): ?string {
            $lockedDocument = PrDocument::query()->lockForUpdate()->findOrFail($document->id);
            $this->assignedTo($lockedDocument, $me);

            if ($lockedDocument->status !== 'sent_user') {
                return 'เอกสารถูกส่งไปขั้นต่อรองราคาแล้ว';
            }

            $hasSelectionSignature = $lockedDocument->signatures()
                ->where('duty', 'select')
                ->whereNotNull('signed_at')
                ->exists();
            if (! $hasSelectionSignature) {
                return 'กรุณาคัดเลือก Supplier และประทับลายเซ็นก่อนส่งต่อรองราคา';
            }

            if (! $lockedDocument->suppliers()->where('is_selected', true)->exists()) {
                return 'กรุณาเลือก Supplier 1 บริษัทก่อนส่งต่อรองราคา';
            }

            $lockedDocument->update(['status' => 'negotiating']);

            return null;
        });

        if ($error !== null) {
            return back()->with('error', $error);
        }

        return redirect()
            ->route('work.select')
            ->with('success', "ส่ง {$document->referenceLabel()} ไปยังขั้นต่อรองราคาแล้ว");
    }

    public function negotiateIndex(Request $request): ViewContract
    {
        $me = $this->guardDuty('negotiate');
        ResignationGuard::sweepThrottled();
        $workflowSteps = DocumentRole::chain();

        $tabs = DocumentFilter::tabs('negotiate');
        $active = DocumentFilter::activeKey($tabs, $request->query('status'));
        $scope = PrDocument::query()
            ->whereIn('status', array_merge(
                ['negotiating', 'confirming', 'waiting_mgr', 'waiting_ceo', 'approved'],
                PrDocument::rejectedStatusesFrom(
                    $this->stepPosition($workflowSteps->first(fn (DocumentRole $step): bool => $step->duty === 'negotiate'), $workflowSteps),
                    $workflowSteps->count(),
                ),
            ));

        return view('work.select', [
            'status_tabs' => $tabs,
            'status_counts' => DocumentFilter::counts($scope, $tabs),
            'active_status' => $active,
            'me' => $me,
            'page_title' => 'ต่อรองราคา',
            'list_mode' => 'negotiate',
            'open_route_name' => 'work.negotiate.edit',
            'empty_message' => 'ยังไม่มีเอกสารรอต่อรองราคา',
            'workflow_steps' => $workflowSteps,
            // ใบที่ถูกปฏิเสธยังอยู่ในรายการ แค่ขึ้นสถานะปฏิเสธ ไม่หายไปเฉยๆ
            'documents' => DocumentFilter::apply($scope, $tabs, $active)
                ->with(['suppliers', 'items:id,pr_document_id,item_code', 'signatures', 'creator', 'requester'])
                ->withCount('items')
                ->orderByRaw("CASE WHEN status = 'negotiating' THEN 0 ELSE 1 END")
                ->orderByDesc('id')
                ->paginate(20)
                ->withQueryString(),
        ]);
    }

    public function negotiateEdit(PrDocument $document): ViewContract
    {
        $me = $this->guardDuty('negotiate');
        abort_unless(
            in_array($document->status, ['negotiating', 'confirming', 'waiting_mgr', 'waiting_ceo', 'approved'], true),
            409,
            'เอกสารยังไม่ถึงขั้นต่อรองราคา',
        );

        $document->ensureSuppliers();
        $document->load(['suppliers.attachments', 'items.prices', 'signatures']);
        $workflowSteps = DocumentRole::chain();
        $negotiateStep = $workflowSteps->first(fn (DocumentRole $step): bool => $step->duty === 'negotiate');
        $requester = $document->requester_id_thai_hash
            ? AppUser::where('id_thai_hash', $document->requester_id_thai_hash)->first()
            : null;

        return view('pr.form', [
            'me' => $me,
            'doc' => $document,
            'mode' => 'negotiate',
            'workflow_steps' => $workflowSteps,
            'requester_user' => $requester,
            'signature_by_step' => $document->signatures->keyBy('document_role_id'),
            'item_code_options' => [],
            'signable_step_ids' => $document->status === 'negotiating'
                && $negotiateStep
                && $negotiateStep->members->contains('id_thai_hash', $me->id_thai_hash)
                    ? [$negotiateStep->id]
                    : [],
            // ต่อรองราคาปฏิเสธได้ตอนที่เอกสารอยู่ขั้นนี้ (D-048)
            'can_reject' => $document->status === 'negotiating'
                && ! $document->isRejected()
                && $negotiateStep
                && $negotiateStep->members->contains('id_thai_hash', $me->id_thai_hash),
        ]);
    }

    /**
     * ฝ่ายจัดซื้อตอบ "ทีละบริษัท" ว่าต่อรองแล้วได้ใบเสนอราคาใหม่จากเจ้านี้ไหม
     *
     * ต่อรองครบทุกเจ้าแต่ไม่ใช่ทุกเจ้าที่ส่งใบเสนอราคาใหม่มา จึงตอบแยกกันได้
     * ตอบ "ไม่มี" แล้วไฟล์ของเจ้านั้นที่แนบไว้ก่อนหน้าจะถูกลบทิ้ง
     * เพื่อไม่ให้ผู้ลงนามเห็นข้อมูลขัดกัน (เจ้าอื่นไม่โดนด้วย)
     */
    public function setNegotiationQuoteFlag(Request $request, PrSupplier $supplier): RedirectResponse
    {
        $me = $this->guardDuty('negotiate');
        abort_unless($supplier->document->status === 'negotiating', 409, 'เอกสารไม่ได้อยู่ในขั้นต่อรองราคา');
        abort_unless(
            $supplier->slot <= $supplier->document->supplier_count,
            403,
            'ตอบได้เฉพาะผู้ขายที่อยู่ในเอกสารนี้',
        );

        $hasQuote = $request->boolean('has_quote');

        DB::transaction(function () use ($supplier, $hasQuote): void {
            $supplier->update(['has_negotiation_quote' => $hasQuote]);

            if ($hasQuote) {
                return;
            }

            // ตอบว่าไม่มีแล้วต้องล้างไฟล์เดิมของเจ้านี้ทิ้ง ไม่งั้นผู้ลงนามเห็นข้อมูลขัดกันเอง
            foreach ($supplier->attachments()->where('stage', PrAttachment::STAGE_NEGOTIATE)->get() as $file) {
                Storage::delete($file->path);
                $file->delete();
            }
        });

        $name = $supplier->displayName();

        return back()->with('success', $hasQuote
            ? "เปิดช่องแนบใบเสนอราคาหลังต่อรองของ {$name} แล้ว"
            : "บันทึกว่า {$name} ไม่มีใบเสนอราคาเพิ่มเติมจากการต่อรอง");
    }

    public function negotiateUpdate(Request $request, PrDocument $document): RedirectResponse
    {
        $me = $this->guardDuty('negotiate');
        abort_unless(
            in_array($document->status, ['negotiating', 'confirming'], true),
            409,
            'เอกสารพ้นขั้นต่อรองราคาแล้ว',
        );

        // ต้องอ่าน `-` ไว้ก่อน เพราะ stripThousandSeparators แปลงเป็นค่าว่างเพื่อให้ผ่าน rule numeric
        $noRevision = $this->markedAsNoRevision($request->input('revision_prices', []));

        PrDocumentController::stripThousandSeparators($request);

        $data = $request->validate([
            'intent' => ['required', 'in:save_revision,stamp,remove_signature'],
            'signature_step_id' => ['nullable', 'integer', 'exists:document_roles,id'],
            'signature_id' => ['nullable', 'integer', 'exists:pr_document_signatures,id'],
            'revision_prices' => ['nullable', 'array'],
            'revision_prices.*' => ['nullable', 'array'],
            'revision_prices.*.*' => ['nullable', 'numeric', 'min:0'],
        ]);

        // เก็บราคาที่พิมพ์ค้างไว้ก่อนเสมอ ไม่ว่าจะกดปุ่มไหน — ลบลายเซ็นแล้วราคาต้องไม่หายไปด้วย
        $this->saveNegotiationPrices($document, $data['revision_prices'] ?? [], $noRevision);

        if ($data['intent'] === 'remove_signature') {
            return $this->removeNegotiationSignature($document, (int) ($data['signature_id'] ?? 0), $me);
        }

        if ($data['intent'] === 'save_revision') {
            return back()->with('success', 'บันทึกราคา Unit Price Rev.1 แล้ว');
        }

        return $this->stampNegotiationSignature($document, (int) ($data['signature_step_id'] ?? 0), $me);
    }

    /**
     * ช่อง Rev.1 ที่ผู้ใช้พิมพ์ `-` = ยืนยันว่าไม่ต่อรองราคา
     *
     * @param  array<int|string,mixed>  $revisionPrices  ค่าดิบก่อนถูกล้าง
     * @return array<int|string,array<int|string,bool>>
     */
    private function markedAsNoRevision(array $revisionPrices): array
    {
        $marked = [];

        foreach ($revisionPrices as $itemId => $prices) {
            foreach ((array) $prices as $slot => $value) {
                $marked[(int) $itemId][(int) $slot] = is_string($value) && trim($value) === '-';
            }
        }

        return $marked;
    }

    /**
     * @param  array<int|string,array<int|string,int|float|string|null>>  $revisionPrices
     * @param  array<int|string,array<int|string,bool>>  $noRevision
     */
    private function saveNegotiationPrices(PrDocument $document, array $revisionPrices, array $noRevision = []): void
    {
        // ประทับลายเซ็นแล้วยังแก้ราคาได้ ล็อกเมื่อกดปุ่มส่งสีเขียวเท่านั้น (D-058)
        abort_unless($document->status === 'negotiating', 409, 'เอกสารไม่ได้อยู่ในขั้นต่อรองราคา');

        $items = $document->items()->get()->keyBy('id');
        // ต่อรองราคาทั้ง 3 เจ้าที่ใบนี้เปิดใช้ ไม่ใช่เฉพาะเจ้าที่ผู้ขอซื้อติ๊กไว้ (D-075)
        // ผู้ขอซื้อต้องเห็นราคาหลังต่อรองครบทุกเจ้าก่อนยืนยันในขั้นที่ 4
        $supplierIds = $document->suppliers()
            ->where('slot', '<=', $document->supplier_count)
            ->pluck('id', 'slot');

        DB::transaction(function () use ($revisionPrices, $items, $supplierIds, $noRevision): void {
            foreach ($revisionPrices as $itemId => $prices) {
                $item = $items->get((int) $itemId);
                abort_unless($item, 422, 'รายการสินค้าไม่อยู่ในเอกสารนี้');

                foreach ($prices ?? [] as $slot => $price) {
                    $supplierId = $supplierIds->get((int) $slot);
                    abort_unless($supplierId, 422, 'แก้ราคา Rev.1 ได้เฉพาะผู้ขายที่อยู่ในเอกสารนี้');

                    $empty = $price === '' || $price === null;

                    $item->prices()->updateOrCreate(
                        ['pr_supplier_id' => $supplierId],
                        [
                            'unit_price_rev' => $empty ? null : $price,
                            // พิมพ์ `-` = ไม่ต่อรอง · ใส่ราคาแล้วถือว่ายกเลิกการ `-`
                            'rev_none' => $empty && ($noRevision[(int) $itemId][(int) $slot] ?? false),
                        ],
                    );
                }
            }
        });
    }

    private function stampNegotiationSignature(PrDocument $document, int $stepId, AppUser $me): RedirectResponse
    {
        $step = DocumentRole::with('members')->find($stepId);
        abort_unless(
            $step
                && $step->duty === 'negotiate'
                && $step->members->contains('id_thai_hash', $me->id_thai_hash),
            403,
            'คุณไม่ได้เป็นผู้ลงนามในขั้นต่อรองราคา',
        );

        if ($document->status !== 'negotiating') {
            return back()->with('error', 'เอกสารไม่ได้อยู่ในขั้นต่อรองราคา');
        }

        if (trim((string) $me->signature) === '') {
            return back()->with('error', 'ยังไม่พบลายเซ็นของคุณใน Insight');
        }

        $existing = $document->signatures()->where('document_role_id', $step->id)->first();
        if ($existing && $existing->signer_id_thai_hash !== $me->id_thai_hash) {
            return back()->with('error', "ขั้นนี้ลงนามโดย {$existing->signer_name} แล้ว");
        }

        DB::transaction(function () use ($document, $step, $me): void {
            $lockedDocument = PrDocument::query()->lockForUpdate()->findOrFail($document->id);
            abort_unless($lockedDocument->status === 'negotiating', 409, 'เอกสารไม่ได้อยู่ในขั้นต่อรองราคา');

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
        });

        return redirect()
            ->route('work.negotiate.edit', $document)
            ->with('success', 'ประทับลายเซ็นขั้นต่อรองราคาแล้ว กรุณาตรวจสอบก่อนส่งลงนามอนุมัติ');
    }

    private function removeNegotiationSignature(PrDocument $document, int $signatureId, AppUser $me): RedirectResponse
    {
        abort_unless($document->status === 'negotiating', 409, 'เอกสารถูกส่งลงนามอนุมัติแล้ว');

        $signature = $document->signatures()->find($signatureId);
        abort_unless(
            $signature
                && $signature->duty === 'negotiate'
                && $signature->signer_id_thai_hash === $me->id_thai_hash,
            403,
            'ลบได้เฉพาะลายเซ็นต่อรองราคาของตนเอง',
        );

        $hasLaterSignature = $document->signatures()
            ->where('step_no', '>', $signature->step_no)
            ->whereNotNull('signed_at')
            ->exists();
        if ($hasLaterSignature) {
            return back()->with('error', 'ไม่สามารถลบลายเซ็นได้ เนื่องจากเอกสารถูกลงนามในขั้นถัดไปแล้ว');
        }

        DB::transaction(function () use ($signature): void {
            $signature->delete();
        });

        return redirect()
            ->route('work.negotiate.edit', $document)
            ->with('success', 'ลบลายเซ็นขั้นต่อรองราคาแล้ว');
    }

    /**
     * ต่อรองราคาเสร็จแล้วส่งกลับให้ผู้ขอซื้อยืนยันรายการ (ขั้นที่ 4 · D-075)
     *
     * เดิมส่งตรงไปให้ Mgr. Purchasing ลงนาม — ตอนนี้ผู้ขอซื้อต้องเห็นราคาหลังต่อรอง
     * ของทั้ง 3 เจ้าก่อน แล้วยืนยัน (หรือเปลี่ยนเจ้า) ด้วยลายเซ็นตัวเอง
     */
    public function sendToConfirmation(Request $request, PrDocument $document): RedirectResponse
    {
        $this->guardDuty('negotiate');

        /*
        | เก็บราคา Rev.1 ที่ยังค้างอยู่ในหน้าจอก่อน แล้วค่อยส่ง
        |
        | ผู้ใช้พิมพ์ราคาแล้วกดปุ่มเขียวเลยโดยไม่กด "บันทึกราคา Rev.1" เป็นเรื่องปกติ
        | หน้าเว็บจึงแนบค่าปัจจุบันมากับฟอร์มส่งด้วย (ดู sendApprovalForm ใน pr/form.blade.php)
        */
        if ($document->status === 'negotiating' && $request->has('revision_prices')) {
            $noRevision = $this->markedAsNoRevision($request->input('revision_prices', []));
            PrDocumentController::stripThousandSeparators($request);

            $prices = $request->validate([
                'revision_prices' => ['nullable', 'array'],
                'revision_prices.*' => ['nullable', 'array'],
                'revision_prices.*.*' => ['nullable', 'numeric', 'min:0'],
            ]);

            $this->saveNegotiationPrices($document, $prices['revision_prices'] ?? [], $noRevision);
        }

        /*
        | เส้นทางที่ admin ตั้งไว้ไม่มีขั้นยืนยันรายการ ก็ส่งตรงไปลงนามเหมือนเดิม
        | (เส้นทางเป็นข้อมูล ไม่ใช่ค่าตายตัวในโค้ด — ดูข้อ 2.4 ในเอกสารกลาง)
        */
        $nextStatus = DocumentRole::query()->where('duty', 'confirm')->exists()
            ? 'confirming'
            : 'waiting_mgr';

        $error = DB::transaction(function () use ($document, $nextStatus): ?string {
            $lockedDocument = PrDocument::query()->lockForUpdate()->findOrFail($document->id);
            if ($lockedDocument->status !== 'negotiating') {
                return $nextStatus === 'confirming'
                    ? 'เอกสารถูกส่งให้ผู้ขอซื้อยืนยันแล้ว'
                    : 'เอกสารถูกส่งลงนามอนุมัติแล้ว';
            }

            if (! $lockedDocument->signatures()->where('duty', 'negotiate')->whereNotNull('signed_at')->exists()) {
                return 'กรุณาบันทึกราคา Rev.1 และประทับลายเซ็นต่อรองราคาก่อนส่งให้ผู้ขอซื้อยืนยัน';
            }

            // ต้องต่อรองครบทุกเจ้าที่ใบนี้ใช้ ไม่งั้นผู้ขอซื้อเทียบราคาไม่ได้ (D-075)
            if ($this->unansweredRevisionCount($lockedDocument) > 0) {
                return 'กรุณากรอก Unit Price Rev.1 ให้ครบทุกผู้ขายในเอกสาร (เจ้าที่ไม่ได้ต่อรองให้พิมพ์ -)';
            }

            // หัวข้อ 7 ต้องตอบให้ครบทุกบริษัท ไม่งั้นผู้ลงนามไม่รู้ว่าไม่มีไฟล์ หรือแค่ลืมแนบ
            $negotiationSuppliers = $lockedDocument->suppliers()
                ->where('slot', '<=', $lockedDocument->supplier_count)
                ->with(['attachments' => fn ($q) => $q->where('stage', PrAttachment::STAGE_NEGOTIATE)])
                ->orderBy('slot')
                ->get();

            foreach ($negotiationSuppliers as $supplier) {
                if ($supplier->has_negotiation_quote === null) {
                    return 'กรุณาเลือก "มี" หรือ "ไม่มี" ในหัวข้อแนบไฟล์ใบเสนอราคา (ต่อรองราคา) ให้ครบทุกบริษัทก่อนส่งเอกสาร';
                }

                if ($supplier->has_negotiation_quote === true && $supplier->attachments->isEmpty()) {
                    return "เลือก \"มี\" ที่ {$supplier->displayName()} แล้วต้องแนบไฟล์ใบเสนอราคาหลังต่อรองอย่างน้อย 1 ไฟล์";
                }
            }

            $lockedDocument->update(['status' => $nextStatus]);

            return null;
        });

        if ($error !== null) {
            return back()->with('error', $error);
        }

        return redirect()->route('work.negotiate')->with('success', $nextStatus === 'confirming'
            ? "ส่ง {$document->referenceLabel()} ให้ {$document->requester_name} ยืนยันรายการแล้ว"
            : "ส่ง {$document->referenceLabel()} ให้ Mgr. Purchasing ลงนามอนุมัติแล้ว");
    }

    /**
     * ช่อง Rev.1 ที่ยังไม่ได้ตอบ — นับทุกคู่ (รายการ × ผู้ขายที่ใบนี้ใช้)
     *
     * ตอบแล้ว = มีราคา หรือพิมพ์ `-` (rev_none) · ยังไม่ได้กรอกเลยก็ไม่มีแถวใน pr_prices
     */
    private function unansweredRevisionCount(PrDocument $document): int
    {
        $supplierIds = $document->suppliers()
            ->where('slot', '<=', $document->supplier_count)
            ->pluck('id');
        $itemIds = $document->items()->pluck('id');

        if ($supplierIds->isEmpty() || $itemIds->isEmpty()) {
            return 0;
        }

        $answered = PrPrice::query()
            ->whereIn('pr_item_id', $itemIds)
            ->whereIn('pr_supplier_id', $supplierIds)
            ->where(fn ($q) => $q->whereNotNull('unit_price_rev')->orWhere('rev_none', true))
            ->count();

        return max(0, $supplierIds->count() * $itemIds->count() - $answered);
    }

    public function uploadNegotiationAttachment(Request $request, PrSupplier $supplier): RedirectResponse
    {
        $me = $this->guardDuty('negotiate');
        abort_unless($supplier->document->status === 'negotiating', 409, 'เอกสารไม่ได้อยู่ในขั้นต่อรองราคา');
        abort_unless(
            $supplier->has_negotiation_quote === true,
            409,
            'ต้องเลือก "มี" ที่บริษัทนี้ในหัวข้อใบเสนอราคาหลังต่อรองก่อน',
        );
        // ต่อรองทั้ง 3 เจ้า จึงแนบใบเสนอราคาหลังต่อรองได้ทุกเจ้าที่ใบนี้ใช้ (D-075)
        abort_unless(
            $supplier->slot <= $supplier->document->supplier_count,
            403,
            'แนบไฟล์ได้เฉพาะผู้ขายที่อยู่ในเอกสารนี้',
        );

        $request->validate([
            'files' => ['required', 'array'],
            'files.*' => ['file', 'max:'.PrAttachment::MAX_KB, 'mimes:'.implode(',', PrAttachment::ALLOWED)],
        ], [], ['files.*' => 'ไฟล์แนบ']);

        foreach ($request->file('files', []) as $file) {
            $path = $file->store('pr-attachments/'.$supplier->pr_document_id.'/'.PrAttachment::STAGE_NEGOTIATE);

            $supplier->attachments()->create([
                'stage' => PrAttachment::STAGE_NEGOTIATE,
                'original_name' => $file->getClientOriginalName(),
                'path' => $path,
                'mime' => $file->getClientMimeType(),
                'size' => $file->getSize(),
                'uploaded_by_id_thai_hash' => $me->id_thai_hash,
            ]);
        }

        return back()->with('success', 'แนบไฟล์ขั้นต่อรองราคาแล้ว');
    }

    public function deleteNegotiationAttachment(PrAttachment $attachment): RedirectResponse
    {
        $this->guardDuty('negotiate');
        abort_unless($attachment->stage === PrAttachment::STAGE_NEGOTIATE, 403, 'ลบได้เฉพาะไฟล์ขั้นต่อรองราคา');
        abort_unless($attachment->supplier->document->status === 'negotiating', 409, 'เอกสารพ้นขั้นต่อรองราคาแล้ว');

        Storage::delete($attachment->path);
        $name = $attachment->original_name;
        $attachment->delete();

        return back()->with('success', "ลบไฟล์ {$name} แล้ว");
    }

    /*
    |──────────────────────────────────────────────────────────────
    | ยืนยันรายการ — ขั้นที่ 4 ของเส้นทาง (ผู้ขอซื้อคนเดิมกับขั้นคัดเลือก)
    |
    | ผู้ขอซื้อเห็นราคา Rev.1 ครบทั้ง 3 เจ้า แล้วเลือกเจ้าที่จะซื้อจริงอีกครั้ง
    | (เปลี่ยนจากที่เลือกไว้ขั้นที่ 2 ได้) แล้วประทับลายเซ็นยืนยัน — D-075
    |──────────────────────────────────────────────────────────────
    */

    public function confirmIndex(Request $request): ViewContract
    {
        $me = $this->guardDuty('confirm');
        ResignationGuard::sweepThrottled();
        $workflowSteps = DocumentRole::chain();
        $confirmStep = $workflowSteps->first(fn (DocumentRole $step): bool => $step->duty === 'confirm');

        $tabs = DocumentFilter::tabs('confirm');
        $active = DocumentFilter::activeKey($tabs, $request->query('status'));
        $scope = PrDocument::query()
            ->where('requester_id_thai_hash', $me->id_thai_hash)
            ->whereIn('status', array_merge(
                ['confirming', 'waiting_mgr', 'waiting_ceo', 'approved'],
                PrDocument::rejectedStatusesFrom(
                    $this->stepPosition($confirmStep, $workflowSteps),
                    $workflowSteps->count(),
                ),
            ));

        return view('work.select', [
            'me' => $me,
            'page_title' => 'ยืนยันรายการ',
            'list_mode' => 'confirm',
            'open_route_name' => 'work.confirm.edit',
            'empty_message' => 'ยังไม่มีเอกสารรอยืนยันรายการ',
            'workflow_steps' => $workflowSteps,
            'work_step' => $confirmStep,
            'status_tabs' => $tabs,
            'status_counts' => DocumentFilter::counts($scope, $tabs),
            'active_status' => $active,
            'documents' => DocumentFilter::apply($scope, $tabs, $active)
                ->with([
                    'creator:id,id_thai_hash,department',
                    'requester:id,id_thai_hash,department',
                    'suppliers',
                    'items:id,pr_document_id,item_code',
                    'signatures',
                ])
                ->withCount('items')
                ->orderByRaw("CASE WHEN status = 'confirming' THEN 0 ELSE 1 END")
                ->orderByDesc('id')
                ->paginate(20)
                ->withQueryString(),
        ]);
    }

    public function confirmEdit(PrDocument $document): ViewContract
    {
        $me = $this->guardDuty('confirm');
        $this->assignedTo($document, $me);
        abort_unless(
            $document->isRejected()
                || in_array($document->status, ['confirming', 'waiting_mgr', 'waiting_ceo', 'approved'], true),
            409,
            'เอกสารยังไม่ถึงขั้นยืนยันรายการ',
        );

        $document->ensureSuppliers();
        $document->load(['suppliers.attachments', 'items.prices', 'signatures']);
        $workflowSteps = DocumentRole::chain();
        $confirmStep = $workflowSteps->first(fn (DocumentRole $step): bool => $step->duty === 'confirm');
        $requester = $document->requester_id_thai_hash
            ? AppUser::where('id_thai_hash', $document->requester_id_thai_hash)->first()
            : null;

        return view('pr.form', [
            'me' => $me,
            'doc' => $document,
            'mode' => 'confirm',
            'workflow_steps' => $workflowSteps,
            'requester_user' => $requester,
            'signature_by_step' => $document->signatures->keyBy('document_role_id'),
            'item_code_options' => [],
            'signable_step_ids' => $document->status === 'confirming' && $confirmStep
                ? [$confirmStep->id]
                : [],
            // ไม่พอใจราคาที่ต่อรองมา = ปฏิเสธจบใบนี้ ฝ่ายจัดซื้อขึ้นใบใหม่เอง (D-075)
            'can_reject' => $document->status === 'confirming' && ! $document->isRejected(),
        ]);
    }

    public function confirmUpdate(Request $request, PrDocument $document): RedirectResponse
    {
        $me = $this->guardDuty('confirm');
        $this->assignedTo($document, $me);

        if ((string) $request->input('intent', 'stamp') === 'remove_signature') {
            $data = $request->validate([
                'signature_id' => ['required', 'integer', 'exists:pr_document_signatures,id'],
            ]);

            abort_unless($document->status === 'confirming', 409, 'เอกสารถูกส่งลงนามอนุมัติแล้ว');

            $signature = $document->signatures()->find((int) $data['signature_id']);
            abort_unless(
                $signature
                    && $signature->duty === 'confirm'
                    && $signature->signer_id_thai_hash === $me->id_thai_hash,
                403,
                'ลบได้เฉพาะลายเซ็นยืนยันรายการของตนเอง',
            );

            $signature->delete();

            return redirect()
                ->route('work.confirm.edit', $document)
                ->with('success', 'ลบลายเซ็นยืนยันรายการแล้ว');
        }

        $data = $request->validate([
            'intent' => ['nullable', 'in:stamp'],
            // เลือกได้เจ้าเดียวเท่านั้น (D-075)
            'selected_suppliers' => ['required', 'array', 'size:1'],
            'selected_suppliers.*' => ['required', 'integer', 'min:1', 'max:'.PrDocument::MAX_SUPPLIERS],
            'comment' => ['nullable', 'string', 'max:2000'],
        ], [
            'selected_suppliers.required' => 'กรุณาเลือก Supplier 1 บริษัทก่อนลงนาม',
            'selected_suppliers.size' => 'เลือก Supplier ได้เพียง 1 บริษัท',
        ]);

        if (trim((string) $me->signature) === '') {
            return back()->with('error', 'ยังไม่พบลายเซ็นของคุณใน Insight');
        }

        $confirmStep = DocumentRole::query()
            ->where('role', DocumentRole::ROLE_USER)
            ->where('duty', 'confirm')
            ->orderBy('step_no')
            ->first();

        abort_unless($confirmStep, 409, 'ยังไม่ได้กำหนดขั้น User ยืนยันรายการ');

        DB::transaction(function () use ($document, $me, $confirmStep, $data): void {
            $lockedDocument = PrDocument::query()->lockForUpdate()->findOrFail($document->id);
            $this->assignedTo($lockedDocument, $me);

            abort_unless($lockedDocument->status === 'confirming', 409, 'เอกสารไม่ได้อยู่ในขั้นยืนยันรายการแล้ว');

            $selectedSlots = array_map('intval', $data['selected_suppliers']);
            abort_unless(
                array_diff($selectedSlots, range(1, $lockedDocument->supplier_count)) === [],
                422,
                'Supplier ที่เลือกไม่อยู่ในเอกสาร',
            );

            if (array_key_exists('comment', $data)) {
                $lockedDocument->comment = trim((string) $data['comment']) ?: null;
                $lockedDocument->save();
            }

            // เปลี่ยนเจ้าที่เลือกได้ในขั้นนี้ — ราคาที่ใช้จริงคือ Rev.1 ของเจ้าที่ยืนยัน
            $lockedDocument->suppliers()->update(['is_selected' => false]);
            $lockedDocument->suppliers()->whereIn('slot', $selectedSlots)->update(['is_selected' => true]);
            $lockedDocument->signatures()->updateOrCreate(
                ['document_role_id' => $confirmStep->id],
                [
                    'step_no' => $confirmStep->step_no,
                    'role' => $confirmStep->role,
                    'duty' => $confirmStep->duty,
                    'signer_id_thai_hash' => $me->id_thai_hash,
                    'signer_name' => $me->displayName(),
                    'signature_data' => $me->signature,
                    'signed_at' => now(),
                ],
            );
        });

        return redirect()
            ->route('work.confirm.edit', $document)
            ->with('success', "ยืนยันรายการและลงนาม {$document->referenceLabel()} แล้ว กรุณาตรวจสอบก่อนส่งลงนามอนุมัติ");
    }

    /** ผู้ขอซื้อยืนยันแล้ว ส่งต่อให้ Mgr. Purchasing ลงนาม */
    public function sendToApproval(PrDocument $document): RedirectResponse
    {
        $me = $this->guardDuty('confirm');
        $this->assignedTo($document, $me);

        $error = DB::transaction(function () use ($document, $me): ?string {
            $lockedDocument = PrDocument::query()->lockForUpdate()->findOrFail($document->id);
            $this->assignedTo($lockedDocument, $me);

            if ($lockedDocument->status !== 'confirming') {
                return 'เอกสารถูกส่งลงนามอนุมัติแล้ว';
            }

            $signed = $lockedDocument->signatures()
                ->where('duty', 'confirm')
                ->whereNotNull('signed_at')
                ->exists();
            if (! $signed) {
                return 'กรุณายืนยัน Supplier และประทับลายเซ็นก่อนส่งลงนามอนุมัติ';
            }

            if (! $lockedDocument->suppliers()->where('is_selected', true)->exists()) {
                return 'กรุณาเลือก Supplier 1 บริษัทก่อนส่งลงนามอนุมัติ';
            }

            $lockedDocument->update(['status' => 'waiting_mgr']);

            return null;
        });

        if ($error !== null) {
            return back()->with('error', $error);
        }

        return redirect()
            ->route('work.confirm')
            ->with('success', "ส่ง {$document->referenceLabel()} ให้ Mgr. Purchasing ลงนามอนุมัติแล้ว");
    }

    public function approvalIndex(Request $request): ViewContract
    {
        $me = $this->guardDuty('sign');
        ResignationGuard::sweepThrottled();
        $workflowSteps = DocumentRole::chain();
        $approvalStep = $this->approvalStepFor($me, $workflowSteps);
        $statuses = array_merge(
            $approvalStep->role === 'ceo'
                ? ['waiting_ceo', 'approved']
                : ['waiting_mgr', 'waiting_ceo', 'approved'],
            // ใบที่ถูกปฏิเสธยังอยู่ในรายการ แค่ขึ้นสถานะปฏิเสธ
            PrDocument::rejectedStatusesFrom($this->stepPosition($approvalStep, $workflowSteps), $workflowSteps->count()),
        );

        $tabs = DocumentFilter::tabs('approval', $approvalStep);
        $active = DocumentFilter::activeKey($tabs, $request->query('status'));
        $scope = PrDocument::query()->whereIn('status', $statuses);

        return view('work.select', [
            'status_tabs' => $tabs,
            'status_counts' => DocumentFilter::counts($scope, $tabs),
            'active_status' => $active,
            'me' => $me,
            'page_title' => 'ลงนามอนุมัติ',
            'list_mode' => 'approval',
            'work_step' => $approvalStep,
            'open_route_name' => 'work.approval.edit',
            'empty_message' => 'ยังไม่มีเอกสารรอลงนามอนุมัติ',
            'workflow_steps' => $workflowSteps,
            'documents' => DocumentFilter::apply($scope, $tabs, $active)
                ->with(['suppliers', 'items:id,pr_document_id,item_code', 'signatures', 'creator', 'requester'])
                ->withCount('items')
                ->orderByRaw("CASE WHEN status IN ('waiting_mgr', 'waiting_ceo') THEN 0 ELSE 1 END")
                ->orderByDesc('id')
                ->paginate(20)
                ->withQueryString(),
        ]);
    }

    public function approvalEdit(PrDocument $document): ViewContract
    {
        $me = $this->guardDuty('sign');
        $workflowSteps = DocumentRole::chain();
        $approvalStep = $this->approvalStepFor($me, $workflowSteps, $document);
        $expectedStatus = $this->approvalStatusFor($approvalStep);

        abort_unless(
            in_array($document->status, [$expectedStatus, 'waiting_ceo', 'approved'], true),
            409,
            'เอกสารยังไม่ถึงขั้นลงนามของคุณ',
        );

        $document->ensureSuppliers();
        $document->load(['suppliers.attachments', 'items.prices', 'signatures']);
        $requester = $document->requester_id_thai_hash
            ? AppUser::where('id_thai_hash', $document->requester_id_thai_hash)->first()
            : null;

        return view('pr.form', [
            'me' => $me,
            'doc' => $document,
            'mode' => 'approval',
            'workflow_steps' => $workflowSteps,
            'requester_user' => $requester,
            'signature_by_step' => $document->signatures->keyBy('document_role_id'),
            'item_code_options' => [],
            'signable_step_ids' => $document->status === $expectedStatus ? [$approvalStep->id] : [],
            // ขั้นลงนามอนุมัติปฏิเสธได้ตอนที่เอกสารรอลายเซ็นของตัวเอง (D-048)
            'can_reject' => $document->status === $expectedStatus && ! $document->isRejected(),
            // ประทับลายเซ็นแล้วถึงจะโผล่ปุ่มยืนยัน (D-070)
            'can_send_approval' => $document->status === $expectedStatus
                && $document->signatures->contains(
                    fn ($signature): bool => (int) $signature->document_role_id === (int) $approvalStep->id
                        && $signature->signer_id_thai_hash === $me->id_thai_hash
                ),
            'approval_is_final' => $approvalStep->role === 'ceo',
        ]);
    }

    public function approvalUpdate(Request $request, PrDocument $document): RedirectResponse
    {
        $me = $this->guardDuty('sign');
        $data = $request->validate([
            'intent' => ['required', 'in:stamp,remove_signature'],
            'signature_step_id' => ['nullable', 'integer', 'exists:document_roles,id'],
            'signature_id' => ['nullable', 'integer', 'exists:pr_document_signatures,id'],
        ]);

        if ($data['intent'] === 'remove_signature') {
            return $this->removeApprovalSignature($document, (int) ($data['signature_id'] ?? 0), $me);
        }

        abort_unless($data['signature_step_id'] ?? null, 422, 'ไม่ทราบขั้นที่จะลงนาม');

        $step = DocumentRole::with('members')->findOrFail((int) $data['signature_step_id']);
        abort_unless(
            $step->duty === 'sign' && $step->members->contains('id_thai_hash', $me->id_thai_hash),
            403,
            'คุณไม่ได้เป็นผู้ลงนามในขั้นนี้',
        );

        $expectedStatus = $this->approvalStatusFor($step);
        abort_unless($document->status === $expectedStatus, 409, 'เอกสารไม่ได้อยู่ในขั้นลงนามของคุณ');

        if (trim((string) $me->signature) === '') {
            return back()->with('error', 'ยังไม่พบลายเซ็นของคุณใน Insight');
        }

        /*
        | ประทับลายเซ็นอย่างเดียว **ยังไม่ส่งต่อ** (D-070)
        |
        | ต้องกดปุ่มสีเขียวยืนยันอีกครั้งเอกสารถึงจะเดินหน้า — กติกาเดียวกับทุกขั้นตาม D-051
        | ผู้ลงนามจึงมีจังหวะตรวจเอกสารซ้ำ และถอนลายเซ็นได้ถ้ายังไม่ได้กดส่ง
        */
        DB::transaction(function () use ($document, $step, $me): void {
            $lockedDocument = PrDocument::query()->lockForUpdate()->findOrFail($document->id);
            abort_unless(
                $lockedDocument->status === $this->approvalStatusFor($step),
                409,
                'เอกสารไม่ได้อยู่ในขั้นลงนามของคุณ',
            );

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
        });

        return back()->with('success', sprintf(
            'ประทับลายเซ็น %s แล้ว กรุณาตรวจสอบเอกสารแล้วกดปุ่ม "%s" เพื่อยืนยัน',
            $document->referenceLabel(),
            $step->role === 'ceo' ? 'อนุมัติเอกสาร' : 'ส่งให้ CEO ลงนาม',
        ));
    }

    /** ถอนลายเซ็นอนุมัติของตัวเอง — ทำได้จนกว่าจะกดปุ่มยืนยัน (D-070) */
    private function removeApprovalSignature(PrDocument $document, int $signatureId, AppUser $me): RedirectResponse
    {
        $signature = $document->signatures()->find($signatureId);

        abort_unless(
            $signature
                && $signature->duty === 'sign'
                && $signature->signer_id_thai_hash === $me->id_thai_hash,
            403,
            'ลบได้เฉพาะลายเซ็นอนุมัติของตนเอง',
        );

        $step = DocumentRole::find($signature->document_role_id);
        abort_unless(
            $step && $document->status === $this->approvalStatusFor($step),
            409,
            'เอกสารพ้นขั้นลงนามของคุณแล้ว',
        );

        $signature->delete();

        return back()->with('success', 'ลบลายเซ็นอนุมัติแล้ว');
    }

    /**
     * ยืนยันการลงนามอนุมัติ — จังหวะที่เอกสารเดินหน้าจริง
     *
     * Mgr. Purchasing : รอลงนาม CEO ต่อ
     * CEO             : อนุมัติครบทุกขั้น จบกระบวนการ
     */
    public function sendApproval(PrDocument $document): RedirectResponse
    {
        $me = $this->guardDuty('sign');
        $workflowSteps = DocumentRole::chain();
        $step = $this->approvalStepFor($me, $workflowSteps, $document);

        $expectedStatus = $this->approvalStatusFor($step);
        $nextStatus = $step->role === 'ceo' ? 'approved' : 'waiting_ceo';

        $error = DB::transaction(function () use ($document, $step, $me, $expectedStatus, $nextStatus): ?string {
            $lockedDocument = PrDocument::query()->lockForUpdate()->findOrFail($document->id);

            if ($lockedDocument->status !== $expectedStatus) {
                return 'เอกสารไม่ได้อยู่ในขั้นลงนามของคุณแล้ว';
            }

            $signed = $lockedDocument->signatures()
                ->where('document_role_id', $step->id)
                ->where('signer_id_thai_hash', $me->id_thai_hash)
                ->whereNotNull('signed_at')
                ->exists();

            if (! $signed) {
                return 'กรุณาประทับลายเซ็นก่อนยืนยัน';
            }

            $lockedDocument->update(['status' => $nextStatus]);

            return null;
        });

        if ($error) {
            return back()->with('error', $error);
        }

        return redirect()->route('work.approval')->with('success', $nextStatus === 'approved'
            ? "อนุมัติ {$document->referenceLabel()} แล้ว เอกสารดำเนินการครบทุกขั้น"
            : "ลงนาม {$document->referenceLabel()} แล้ว ส่งต่อ CEO ลงนามอนุมัติแล้ว");
    }

    /**
     * ลำดับของขั้นหนึ่งในเส้นทาง เริ่มที่ 1 (ไม่เจอถือว่าขั้นแรก)
     *
     * @param  Collection<int,DocumentRole>  $workflowSteps
     */
    private function stepPosition(?DocumentRole $step, $workflowSteps): int
    {
        if (! $step) {
            return 1;
        }

        $index = $workflowSteps->values()->search(
            fn (DocumentRole $item): bool => (int) $item->id === (int) $step->id
        );

        return $index === false ? 1 : $index + 1;
    }

    /** @param Collection<int,DocumentRole> $workflowSteps */
    private function approvalStepFor(AppUser $me, $workflowSteps, ?PrDocument $document = null): DocumentRole
    {
        $steps = $workflowSteps
            ->filter(fn (DocumentRole $step): bool => $step->duty === 'sign'
                && $step->members->contains('id_thai_hash', $me->id_thai_hash));

        abort_if($steps->isEmpty(), 403, 'คุณไม่ได้อยู่ในขั้นลงนามอนุมัติ');

        $preferredRole = match ($document?->status) {
            'waiting_mgr' => 'mgr_purchasing',
            'waiting_ceo' => 'ceo',
            default => null,
        };

        return $steps->first(fn (DocumentRole $step): bool => $step->role === $preferredRole)
            ?? $steps->first();
    }

    private function approvalStatusFor(DocumentRole $step): string
    {
        return $step->role === 'ceo' ? 'waiting_ceo' : 'waiting_mgr';
    }

    public function show(string $duty): ViewContract
    {
        $me = $this->guardDuty($duty);
        $menu = DocumentRole::menuFor($me);

        return view('work.blank', [
            'me' => $me,
            'duty' => $duty,
            'title' => $menu[$duty],
        ]);
    }

    private function guardDuty(string $duty): AppUser
    {
        $me = app('current_user');

        abort_unless(
            array_key_exists($duty, DocumentRole::menuFor($me)),
            403,
            'คุณไม่ได้อยู่ในขั้นนี้ของเส้นทางเอกสาร — ติดต่อผู้ดูแลระบบ',
        );

        return $me;
    }

    private function assignedTo(PrDocument $document, AppUser $me): void
    {
        abort_unless(
            $document->requester_id_thai_hash === $me->id_thai_hash,
            403,
            'เอกสารใบนี้ไม่ได้ส่งถึงคุณ',
        );
    }
}
