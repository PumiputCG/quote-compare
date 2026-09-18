<?php

namespace App\Services;

use App\Models\DocumentRole;
use App\Models\PrDocument;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

/**
 * รายงานสรุปเอกสารที่ส่งแล้ว เป็นไฟล์ Excel (.xlsx)
 *
 * เห็นปุ่มดาวน์โหลดเฉพาะฝ่ายจัดซื้อขั้นจัดทำเอกสาร (ขั้นที่ 1) และ admin (D-073)
 *
 * รูปแบบตามที่ Manager สั่ง:
 *   - Item Code ของทั้งใบอยู่ใน **ช่องเดียว** ไม่แตกเป็นหลายบรรทัดของ Excel
 *   - สถานะแยกเป็น **คอลัมน์ละขั้น** ตามเส้นทางจริง (จัดทำเอกสาร · คัดเลือกรายการ · ต่อรองราคา · ลงนามอนุมัติ × 2)
 */
class OverviewExport
{
    /** ดาวน์โหลดรายงานได้ไหม — ฝ่ายจัดซื้อขั้นจัดทำเอกสาร หรือ admin เท่านั้น */
    public static function allowed($me, EloquentCollection $workflowSteps): bool
    {
        if ($me->isAdmin()) {
            return true;
        }

        return $workflowSteps
            ->where('duty', 'create')
            ->contains(fn (DocumentRole $step): bool => $step->members->contains('id_thai_hash', $me->id_thai_hash));
    }

    /**
     * สร้างไฟล์แล้วคืน path
     *
     * @param  EloquentCollection<int,PrDocument>  $documents
     * @param  EloquentCollection<int,DocumentRole>  $workflowSteps
     */
    public static function build(EloquentCollection $documents, EloquentCollection $workflowSteps): string
    {
        $headers = ['No.', 'PR Number', 'Item Code', 'รหัสพนักงาน', 'ผู้ขอซื้อ', 'แผนก', 'จำนวนรายการ', 'สถานะ'];

        foreach ($workflowSteps as $step) {
            $headers[] = $step->dutyLabel();
        }

        $headers[] = 'วันที่เอกสาร';

        $rows = [];

        foreach ($documents as $index => $document) {
            $codes = $document->items
                ->pluck('item_code')
                ->filter(fn ($code): bool => trim((string) $code) !== '')
                ->values();

            /*
            | รหัสพนักงานของผู้ขอซื้อ — ดึงจากบัญชีก่อน
            | ถ้าลาออกไปแล้วบัญชีจะถูกลบ จึงตกไปหาในประวัติพนักงานด้วย `id_thai_hash` (D-062)
            */
            $requesterCode = trim((string) $document->requester?->employee_code)
                ?: trim((string) ResignationGuard::employeeOf($document->requester_id_thai_hash)?->employee_code);

            $row = [
                $index + 1,
                $document->referenceLabel(),
                // ขึ้นบรรทัดในเซลล์เดียวกัน (ตั้ง wrapText ไว้ที่คอลัมน์นี้) ไม่แตกเป็นหลายแถว
                $codes->join("\n"),
                $requesterCode,
                $document->requester_name ?: '',
                $document->requesterDepartmentLabel() ?: '',
                $document->items->count(),
                $document->workStatusLabel(),
            ];

            /*
            | แต่ละขั้นแสดง 2 บรรทัดในเซลล์เดียว: สถานะ · เว้นบรรทัด · ชื่อคน
            | ขั้นที่ยังไม่ถึงใส่ `-` เฉยๆ เพราะยังไม่มีอะไรให้บอก
            | ชื่อคน = คนที่ลงนามจริง ถ้ายังไม่ลงนามก็ใช้ผู้รับผิดชอบของขั้นนั้น
            */
            foreach ($document->routeProgress($workflowSteps) as $node) {
                if ($node['state'] === 'upcoming') {
                    $row[] = '-';

                    continue;
                }

                $left = collect($node['people'])->where('resigned', true)->isNotEmpty();
                $label = $node['state_label'].($left && $node['state'] !== 'completed' ? ' (ลาออก)' : '');

                $name = trim((string) $node['signature']?->signer_name)
                    ?: collect($node['people'])->pluck('name')->filter()->join(', ');

                $row[] = $name !== '' ? $label."\n\n".$name : $label;
            }

            $row[] = $document->document_date?->format('d/m/Y') ?: '';

            $rows[] = $row;
        }

        $widths = [6, 15, 26, 14, 24, 26, 11, 15];

        /*
        | สไตล์รายคอลัมน์
        |   Item Code      ตัดคำ ชิดบนซ้าย — รหัสอ่านง่ายกว่าเมื่อเรียงชิดซ้าย
        |   สถานะรายขั้น   ตัดคำ + จัดกึ่งกลาง ให้สถานะกับชื่อคนอยู่กลางช่องทั้งคู่
        */
        $columnStyles = [2 => SimpleXlsx::WRAP];
        $stepColumn = count($widths);

        foreach ($workflowSteps as $step) {
            $widths[] = 22;
            $columnStyles[$stepColumn++] = SimpleXlsx::WRAP_CENTER;
        }

        $widths[] = 14;

        $work = storage_path('app/pdf-work');
        File::ensureDirectoryExists($work);
        $path = $work.'/'.Str::random(20).'.xlsx';

        return SimpleXlsx::write($path, $headers, $rows, $widths, $columnStyles, 'เอกสารที่ส่งแล้ว');
    }

    /** ชื่อไฟล์ที่ผู้ใช้จะได้ */
    public static function fileName(): string
    {
        return 'QuoteCompare-รายงานเอกสาร-'.now()->format('Ymd-His').'.xlsx';
    }
}
