<?php

namespace App\Http\Controllers;

use App\Models\SyncLog;
use App\Services\InsightMirror;
use App\Services\ResignationGuard;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

/**
 * รับสัญญาณจาก Insight ว่ามีข้อมูลเปลี่ยน แล้วดึงเฉพาะแถวนั้นมาอัปเดตมิเรอร์ทันที
 *
 *   POST /insight-changed
 *   { "secret": "...", "app_users": [12,45], "employees": [301], "all": false }
 *
 * ทำไมส่งมาแค่ id ไม่ส่งข้อมูลทั้งแถว:
 *   - payload เล็ก ไม่ต้องแบกข้อมูลพนักงานผ่าน HTTP
 *   - PR Compare อ่านต้นทางเองอยู่แล้วผ่าน connection `insight` จึงไม่ต้องเขียน mapping ซ้ำ 2 ที่
 *
 * ⚠️ เส้นทางนี้อยู่นอก middleware ล็อกอิน — กันด้วย shared secret ใน .env (`INSIGHT_WEBHOOK_SECRET`)
 *    ถ้าไม่ได้ตั้ง secret ไว้ จะปิดรับทั้งหมด (fail closed)
 */
class InsightWebhookController extends Controller
{
    /** ถ้ารายการที่เปลี่ยนเยอะกว่านี้ ให้ซิงค์ทั้งชุดแทน จะเร็วกว่าไล่ทีละ id */
    private const BULK_THRESHOLD = 400;

    public function handle(Request $request, InsightMirror $mirror): JsonResponse
    {
        $secret = (string) config('app.insight_webhook_secret');

        // ไม่ได้ตั้ง secret = ไม่เปิดใช้งาน (กันเผลอเปิดช่องไว้เฉยๆ)
        if ($secret === '') {
            return response()->json(['ok' => false, 'message' => 'webhook ยังไม่ได้ตั้งค่า'], 503);
        }

        if (! hash_equals($secret, (string) $request->input('secret'))) {
            return response()->json(['ok' => false, 'message' => 'secret ไม่ถูกต้อง'], 401);
        }

        $users = array_filter((array) $request->input('app_users', []));
        $employees = array_filter((array) $request->input('employees', []));
        $all = (bool) $request->boolean('all');

        // เปลี่ยนทีเดียวเยอะมาก (เช่น Insight เพิ่งดึงจาก Bplus) -> ซิงค์ทั้งชุดคุ้มกว่า
        if ($all || count($users) + count($employees) > self::BULK_THRESHOLD) {
            return $this->fullSync($mirror);
        }

        if ($users === [] && $employees === []) {
            return response()->json(['ok' => true, 'message' => 'ไม่มีอะไรให้อัปเดต']);
        }

        try {
            $u = $mirror->pullUsers($users);
            $e = $mirror->pullEmployees($employees);
        } catch (Throwable $ex) {
            SyncLog::record('push', false, 'ผิดพลาด: '.$ex->getMessage());

            return response()->json(['ok' => false, 'message' => $ex->getMessage()], 500);
        }

        // คนลาออกจะหายจาก app_users และติดสถานะ 2 ใน employees — เอกสารที่รอคนนั้นต้องปฏิเสธทันที
        $rejected = ResignationGuard::sweep();

        SyncLog::record('push', true, sprintf(
            'อัปเดตจาก Insight · บัญชี %d (ลบ %d) · พนักงาน %d (ลบ %d)%s',
            $u['updated'], $u['removed'], $e['updated'], $e['removed'],
            $rejected === [] ? '' : ' · ปฏิเสธเพราะผู้รับผิดชอบลาออก: '.implode(', ', $rejected),
        ));

        return response()->json([
            'ok' => true,
            'app_users' => $u,
            'employees' => $e,
            'rejected_documents' => $rejected,
        ]);
    }

    /** เปลี่ยนเยอะเกินไป — กวาดทั้งตารางทีเดียว */
    private function fullSync(InsightMirror $mirror): JsonResponse
    {
        try {
            $result = $mirror->sync();
        } catch (Throwable $ex) {
            SyncLog::record('push', false, 'ซิงค์ทั้งชุดล้มเหลว: '.$ex->getMessage());

            return response()->json(['ok' => false, 'message' => $ex->getMessage()], 500);
        }

        $rejected = ResignationGuard::sweep();

        SyncLog::record('push', true, sprintf(
            'ซิงค์ทั้งชุดจาก Insight · พนักงาน %d · บัญชี %d%s',
            $result['employees']['total'], $result['app_users']['total'],
            $rejected === [] ? '' : ' · ปฏิเสธเพราะผู้รับผิดชอบลาออก: '.implode(', ', $rejected),
        ));

        return response()->json([
            'ok' => true,
            'mode' => 'full',
            'result' => $result,
            'rejected_documents' => $rejected,
        ]);
    }
}
