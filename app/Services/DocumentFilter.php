<?php

namespace App\Services;

use App\Models\DocumentRole;
use App\Models\PrDocument;
use Illuminate\Database\Eloquent\Builder;

/**
 * แท็บกรองเอกสารตามสถานะ ใช้ร่วมกันทุกหน้ารายการ (D-074)
 *
 * กรองที่ฐานข้อมูล ไม่ใช่กรองหลังดึงมาแล้ว เลขหน้าและจำนวนหน้าจึงถูกต้อง
 *
 * แต่ละหน้าเห็นสถานะไม่เหมือนกัน เพราะมองจาก "ขั้นของตัวเอง"
 * เช่นใบที่ส่งให้ผู้ขอซื้อแล้ว ในสายตาฝ่ายจัดซื้อคือ "ดำเนินการแล้ว"
 * แต่ในสายตาผู้ขอซื้อคือ "รอคัดเลือกรายการ"
 */
class DocumentFilter
{
    public const ALL = 'all';

    /** สถานะที่เอกสารเดินผ่านตามลำดับ (ไม่รวมใบร่างและใบที่ถูกปฏิเสธ) */
    private const FLOW = ['sent_user', 'negotiating', 'confirming', 'waiting_mgr', 'waiting_ceo', 'approved'];

    /**
     * แท็บของหน้าหนึ่ง
     *
     * @param  string  $mode  overview | create | select | negotiate | confirm | approval
     * @param  DocumentRole|null  $approvalStep  ขั้นลงนามของคนที่เปิดดู (Mgr. หรือ CEO)
     * @return array<string, array{label: string, statuses?: array<int,string>, rejected?: bool}>
     */
    public static function tabs(string $mode, ?DocumentRole $approvalStep = null): array
    {
        $done = ['label' => 'ดำเนินการแล้ว'];
        $rejected = ['label' => 'ปฏิเสธ', 'rejected' => true];

        return match ($mode) {
            'overview' => [
                self::ALL => ['label' => 'ทั้งหมด'],
                'active' => ['label' => 'กำลังดำเนินการ', 'statuses' => ['sent_user', 'negotiating', 'confirming', 'waiting_mgr', 'waiting_ceo']],
                'approved' => ['label' => 'อนุมัติแล้ว', 'statuses' => ['approved']],
                'rejected' => $rejected,
            ],

            'create' => [
                self::ALL => ['label' => 'ทั้งหมด'],
                'draft' => ['label' => 'ใบร่าง', 'statuses' => ['draft']],
                'done' => $done + ['statuses' => self::FLOW],
                'rejected' => $rejected,
            ],

            'select' => [
                self::ALL => ['label' => 'ทั้งหมด'],
                'waiting' => ['label' => 'รอคัดเลือกรายการ', 'statuses' => ['sent_user']],
                'done' => $done + ['statuses' => ['negotiating', 'confirming', 'waiting_mgr', 'waiting_ceo', 'approved']],
                'rejected' => $rejected,
            ],

            'negotiate' => [
                self::ALL => ['label' => 'ทั้งหมด'],
                'waiting' => ['label' => 'รอต่อรองราคา', 'statuses' => ['negotiating']],
                'done' => $done + ['statuses' => ['confirming', 'waiting_mgr', 'waiting_ceo', 'approved']],
                'rejected' => $rejected,
            ],

            // ขั้นยืนยันรายการของผู้ขอซื้อ — หลังฝ่ายจัดซื้อต่อรองราคาครบทุกเจ้า (D-075)
            'confirm' => [
                self::ALL => ['label' => 'ทั้งหมด'],
                'waiting' => ['label' => 'รอยืนยันรายการ', 'statuses' => ['confirming']],
                'done' => $done + ['statuses' => ['waiting_mgr', 'waiting_ceo', 'approved']],
                'rejected' => $rejected,
            ],

            // ขั้นลงนามมี 2 ระดับ — Mgr. รอที่ waiting_mgr ส่วน CEO รอที่ waiting_ceo
            'approval' => [
                self::ALL => ['label' => 'ทั้งหมด'],
                'waiting' => ['label' => 'รอลงนามอนุมัติ', 'statuses' => [
                    ($approvalStep?->role === 'ceo') ? 'waiting_ceo' : 'waiting_mgr',
                ]],
                'done' => $done + ['statuses' => ($approvalStep?->role === 'ceo')
                    ? ['approved']
                    : ['waiting_ceo', 'approved']],
                'rejected' => $rejected,
            ],

            default => [self::ALL => ['label' => 'ทั้งหมด']],
        };
    }

    /** แท็บที่เลือกอยู่ — ค่าที่ไม่รู้จักให้ตกกลับเป็น "ทั้งหมด" */
    public static function activeKey(array $tabs, ?string $requested): string
    {
        $requested = trim((string) $requested);

        return array_key_exists($requested, $tabs) ? $requested : self::ALL;
    }

    /**
     * ใส่เงื่อนไขกรองลงใน query ตามแท็บที่เลือก
     *
     * @param  Builder<PrDocument>  $query
     */
    public static function apply(Builder $query, array $tabs, string $active): Builder
    {
        $tab = $tabs[$active] ?? [];

        if ($tab['rejected'] ?? false) {
            return $query->where('status', 'like', 'rejected%');
        }

        if (! empty($tab['statuses'])) {
            return $query->whereIn('status', $tab['statuses']);
        }

        return $query;
    }

    /**
     * จำนวนเอกสารของแต่ละแท็บ — นับด้วย query เดียวแล้วแจกเข้าแท็บในฝั่ง PHP
     *
     * @param  Builder<PrDocument>  $base  query ที่กรองสิทธิ์/ขอบเขตของหน้านั้นแล้ว แต่ยังไม่กรองสถานะ
     * @return array<string,int>
     */
    public static function counts(Builder $base, array $tabs): array
    {
        $rows = (clone $base)
            ->getQuery()
            ->select('status')
            ->selectRaw('COUNT(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        $counts = array_fill_keys(array_keys($tabs), 0);

        foreach ($rows as $status => $total) {
            $counts[self::ALL] += $total;

            foreach ($tabs as $key => $tab) {
                if ($key === self::ALL) {
                    continue;
                }

                $matched = ($tab['rejected'] ?? false)
                    ? str_starts_with((string) $status, 'rejected')
                    : in_array($status, $tab['statuses'] ?? [], true);

                if ($matched) {
                    $counts[$key] += $total;
                }
            }
        }

        return $counts;
    }
}
