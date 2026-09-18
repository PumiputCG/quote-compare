<?php

namespace App\Http\Controllers;

use App\Models\DocumentRole;
use App\Models\PrDocument;
use App\Services\DocumentFilter;
use App\Services\DocumentPdf;
use App\Services\OverviewExport;
use App\Services\ResignationGuard;
use Illuminate\Http\Request;
use Illuminate\View\View as ViewContract;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

/**
 * หน้า "ภาพรวม" — เห็นได้ทุกคน ไม่ผูกกับขั้นใดในเส้นทาง
 *
 * แสดงเอกสารทุกใบที่ **ส่งออกจากมือผู้จัดทำแล้ว** (พ้นใบร่าง) เฉพาะใบที่ตัวเองมีสิทธิ์เห็น
 * พร้อมสถานะครบทุกขั้นในแถวเดียว ไม่ต้องกดเปิด modal (D-071)
 *
 * ใบที่ลงนามครบทั้ง 5 ขั้นแล้วจะมีปุ่มดาวน์โหลดเอกสารเพิ่มขึ้นมา
 */
class OverviewController extends Controller
{
    public function index(Request $request): ViewContract
    {
        $me = app('current_user');

        // เผื่อคนในเส้นทางลาออกระหว่างทาง — กวาดก่อนแสดงผล จะได้ไม่เห็นสถานะค้าง
        ResignationGuard::sweepThrottled();

        $workflowSteps = DocumentRole::chain();

        $tabs = DocumentFilter::tabs('overview');
        $active = DocumentFilter::activeKey($tabs, $request->query('status'));
        $scope = PrDocument::query()
            ->where('status', '!=', 'draft')
            ->visibleTo($me, $workflowSteps);

        return view('overview', [
            'me' => $me,
            'workflow_steps' => $workflowSteps,
            // ปุ่มดาวน์โหลด Excel เห็นเฉพาะฝ่ายจัดซื้อขั้นจัดทำเอกสาร และ admin (D-073)
            'can_export' => OverviewExport::allowed($me, $workflowSteps),
            'status_tabs' => $tabs,
            'status_counts' => DocumentFilter::counts($scope, $tabs),
            'active_status' => $active,
            'documents' => DocumentFilter::apply($scope, $tabs, $active)
                ->with([
                    'items:id,pr_document_id,item_code',
                    'signatures',
                    'requester:id,id_thai_hash,department',
                ])
                ->withCount('items')
                ->orderByDesc('id')
                ->paginate(20)
                ->withQueryString(),
        ]);
    }

    /**
     * เปิดอ่านเอกสารจากหน้าภาพรวม — อ่านอย่างเดียว แก้อะไรไม่ได้
     *
     * ปุ่ม "ดู" ในหน้าภาพรวม — เปิดดูเอกสารเต็มใบพร้อมลายเซ็นทุกขั้น
     */
    public function show(PrDocument $document): ViewContract
    {
        return view('pr.form', $this->documentView($document));
    }

    /**
     * ดาวน์โหลดเอกสารเป็นไฟล์ PDF เข้าเครื่องเลย
     *
     * ใช้ Chrome headless ที่ติดตั้งอยู่แล้วเป็นตัว render จึงได้หน้าตาตรงกับบนเว็บ
     * เปิดได้เฉพาะใบที่ลงนามครบทุกขั้นแล้ว (D-072)
     */
    public function pdf(PrDocument $document): Response   // ครอบทั้งไฟล์ดาวน์โหลดและหน้า error
    {
        abort_unless($document->status === 'approved', 404, 'ดาวน์โหลดได้เฉพาะเอกสารที่อนุมัติครบทุกขั้นแล้ว');

        $data = $this->documentView($document);

        DocumentPdf::sweepOldFiles();

        try {
            $path = DocumentPdf::render(view('pr.form', $data)->render(), $document);
        } catch (RuntimeException $e) {
            report($e);

            return response()->make(
                'ดาวน์โหลดไฟล์ PDF ไม่สำเร็จ: '.$e->getMessage(),
                500,
                ['Content-Type' => 'text/plain; charset=utf-8'],
            );
        }

        return response()->download($path, DocumentPdf::fileName($document))->deleteFileAfterSend();
    }

    /** ดาวน์โหลดรายงานสรุปเอกสารที่ส่งแล้วเป็นไฟล์ Excel (D-073) */
    public function export(): Response
    {
        $me = app('current_user');
        $workflowSteps = DocumentRole::chain();

        abort_unless(OverviewExport::allowed($me, $workflowSteps), 403, 'คุณไม่มีสิทธิ์ดาวน์โหลดรายงานนี้');

        DocumentPdf::sweepOldFiles();

        $documents = PrDocument::query()
            ->where('status', '!=', 'draft')
            ->visibleTo($me, $workflowSteps)
            ->with(['items:id,pr_document_id,item_code', 'signatures', 'requester:id,id_thai_hash,department'])
            ->orderByDesc('id')
            ->get();

        $path = OverviewExport::build($documents, $workflowSteps);

        return response()->download($path, OverviewExport::fileName())->deleteFileAfterSend();
    }

    /**
     * ข้อมูลสำหรับ render เอกสารแบบอ่านอย่างเดียว — ใช้ร่วมกันทั้งหน้า "ดู" และไฟล์ PDF
     *
     * @return array<string,mixed>
     */
    private function documentView(PrDocument $document): array
    {
        $me = app('current_user');
        $workflowSteps = DocumentRole::chain();

        abort_if($document->status === 'draft', 404);
        abort_unless(
            PrDocument::query()->visibleTo($me, $workflowSteps)->whereKey($document->getKey())->exists(),
            403,
            'คุณไม่ได้เกี่ยวข้องกับเอกสารใบนี้',
        );

        $document->ensureSuppliers();
        $document->load(['suppliers.attachments', 'items.prices', 'signatures']);

        return [
            'me' => $me,
            'doc' => $document,
            'mode' => 'download',
            'workflow_steps' => $workflowSteps,
            'requester_user' => $document->requester,
            'signature_by_step' => $document->signatures->keyBy('document_role_id'),
            'item_code_options' => [],
            'signable_step_ids' => [],
            'can_reject' => false,
        ];
    }
}
