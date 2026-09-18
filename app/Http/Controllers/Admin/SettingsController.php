<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AppUser;
use App\Models\DocumentRole;
use App\Models\DocumentRoleMember;
use App\Models\Employee;
use App\Models\PositionAccess;
use App\Models\SyncLog;
use App\Services\InsightMirror;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View as ViewContract;
use Throwable;

/**
 * หน้า "ตั้งค่าระบบ" (เฉพาะ admin) — รวม 3 ส่วนไว้ในหน้าเดียว แบบพับเก็บได้
 *
 *   1) กำหนดเส้นทาง — ใครเป็น Purchasing / Mgr. Purchasing / CEO ในเส้นทางเอกสาร PR
 *   2) สิทธิ์เข้าใช้งานระบบ — ติ๊กเลือกว่าตำแหน่งไหนล็อกอิน PR Compare ได้
 *   3) พนักงาน — รายชื่อที่มิเรอร์จาก Insight + ปุ่มซิงค์ (**อ่านอย่างเดียว** แก้ที่ Insight ที่เดียว)
 *
 * ข้อ 1-2 เป็นตารางของ PR Compare เอง ; ข้อ 3 เป็นสำเนาจาก Insight
 */
class SettingsController extends Controller
{
    /** จำนวนแถวต่อหน้าของตารางพนักงาน */
    private const PER_PAGE = 50;

    public function index(Request $request, InsightMirror $mirror): ViewContract
    {
        $keyword = trim((string) $request->query('q', ''));
        $company = trim((string) $request->query('company', ''));
        $status = trim((string) $request->query('status', ''));

        // with('appUser') กัน N+1 — ตารางเรียกใช้ทั้งรูปโปรไฟล์และป้าย admin ทุกแถว
        $query = Employee::query()->with('appUser')->orderBy('no');

        if ($keyword !== '') {
            $query->where(function ($q) use ($keyword) {
                $like = '%'.$keyword.'%';
                $q->where('employee_code', 'like', $like)
                    ->orWhere('name_th', 'like', $like)
                    ->orWhere('surname_th', 'like', $like)
                    ->orWhere('name_en', 'like', $like)
                    ->orWhere('dept_th', 'like', $like)
                    ->orWhere('job_th', 'like', $like);
            });
        }

        if ($company !== '') {
            $query->where('company', $company);
        }

        if ($status === 'active') {
            $query->active();
        } elseif ($status === 'resigned') {
            $query->resigned();
        }

        // สถานะการเชื่อมต่อ Insight — ถ้าต่อไม่ได้ยังเปิดหน้าดูมิเรอร์เดิมได้อยู่
        $connected = $mirror->connectionOk();

        return view('admin.settings', [
            'me' => app('current_user'),
            'employees' => $query->paginate(self::PER_PAGE)->withQueryString(),
            'filters' => ['q' => $keyword, 'company' => $company, 'status' => $status],
            'companies' => Employee::query()->distinct()->orderBy('company')->pluck('company'),
            'connected' => $connected,
            'counts' => [
                'employees' => Employee::count(),
                'active' => Employee::active()->count(),
                'resigned' => Employee::resigned()->count(),
                'accounts' => AppUser::count(),
            ],
            'source' => $connected ? $mirror->sourceCounts() : null,
            'lastSync' => SyncLog::latestRun(),

            // ── ส่วนกำหนดเส้นทางเอกสาร ──
            'chain' => $chain = DocumentRole::chain(),
            'flow' => DocumentRole::flowOf($chain),

            // ── ส่วนสิทธิ์เข้าใช้งาน ──
            'positions' => PositionAccess::allPositions(),
            'access' => PositionAccess::map(),
            'accessConfigured' => PositionAccess::isConfigured(),
        ]);
    }

    /**
     * ต่อการ์ดใหม่ท้ายเส้นทาง — เลือก Role ก่อน แล้วค่อยเลือกคน
     *
     * Role `user` ไม่ต้องส่งตัวคนมา เพราะเป็นช่องว่างที่ Purchasing เลือกเองตอนสร้างเอกสาร
     */
    public function addRole(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'role' => ['required', 'string', Rule::in(array_keys(DocumentRole::ROLES))],
            'duty' => ['nullable', 'string'],
            'id_thai_hash' => [
                Rule::requiredIf(fn (): bool => $request->input('role') !== DocumentRole::ROLE_USER),
                'nullable', 'string', Rule::exists('app_users', 'id_thai_hash'),
            ],
        ]);

        $step = DocumentRole::addStep($data['role'], $data['duty'] ?? null, $data['id_thai_hash'] ?? null);

        return $this->backToRoutes("เพิ่มขั้น {$step->roleLabel()} · {$step->dutyLabel()} แล้ว");
    }

    /** แก้ไขการ์ด — เปลี่ยน Role หรือเปลี่ยนตัวคน (ส่งมาอย่างใดอย่างหนึ่งหรือทั้งคู่) */
    public function updateRole(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'id' => ['required', 'integer'],
            'role' => ['nullable', 'string', Rule::in(array_keys(DocumentRole::ROLES))],
            'duty' => ['nullable', 'string'],
        ]);

        $row = DocumentRole::find($data['id']);

        if (! $row) {
            return $this->backToRoutes('ไม่พบการ์ดนี้แล้ว');
        }

        if (! empty($data['role'])) {
            $row->role = $data['role'];
        }

        // เปลี่ยน Role แล้วหน้าที่เดิมอาจใช้ไม่ได้กับ Role ใหม่ -> ถอยไปหน้าที่แรกของ Role นั้น
        // ⚠️ ต้องเช็ค empty ไม่ใช่ ?? เพราะฟอร์มบางตัวส่ง duty มาเป็นค่าว่าง
        $row->duty = DocumentRole::resolveDuty(
            $row->role,
            empty($data['duty']) ? $row->duty : $data['duty'],
        );

        $row->save();

        // เปลี่ยนเป็นขั้น User เมื่อไหร่ รายชื่อที่เคยใส่ไว้ไม่มีความหมายแล้ว
        if ($row->isUserStep()) {
            $row->members()->delete();
        }

        return $this->backToRoutes("แก้ไขแล้ว — {$row->roleLabel()} · {$row->dutyLabel()}");
    }

    /** เพิ่มพนักงานอีกคนเข้าขั้นเดิม */
    public function addMember(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'id' => ['required', 'integer'],
            'id_thai_hash' => ['required', 'string', Rule::exists('app_users', 'id_thai_hash')],
        ]);

        $step = DocumentRole::find($data['id']);

        if (! $step) {
            return $this->backToRoutes('ไม่พบการ์ดนี้แล้ว');
        }

        if ($step->isUserStep()) {
            return $this->backToRoutes('ขั้น User ไม่ต้องระบุตัวคน');
        }

        $step->addMember($data['id_thai_hash']);
        $name = AppUser::where('id_thai_hash', $data['id_thai_hash'])->value('full_name_th');

        return $this->backToRoutes("เพิ่ม {$name} เข้าขั้น {$step->roleLabel()} แล้ว");
    }

    /** เอาพนักงานออกจากขั้น */
    public function removeMember(Request $request): RedirectResponse
    {
        $data = $request->validate(['member' => ['required', 'integer']]);

        $member = DocumentRoleMember::with('appUser')->find($data['member']);

        if (! $member) {
            return $this->backToRoutes('ไม่พบรายชื่อนี้แล้ว');
        }

        $name = $member->displayName();
        $member->delete();

        return $this->backToRoutes("เอา {$name} ออกจากขั้นนี้แล้ว");
    }

    /** เลื่อนการ์ดซ้าย/ขวาในเส้นทาง */
    public function moveRole(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'id' => ['required', 'integer'],
            'direction' => ['required', Rule::in(['up', 'down'])],
        ]);

        DocumentRole::find($data['id'])?->move($data['direction']);

        return $this->backToRoutes('สลับลำดับแล้ว');
    }

    /** ลบการ์ดออกจากเส้นทาง */
    public function removeRole(Request $request): RedirectResponse
    {
        $data = $request->validate(['id' => ['required', 'integer']]);

        $row = DocumentRole::find($data['id']);

        if (! $row) {
            return $this->backToRoutes('ไม่พบการ์ดนี้แล้ว');
        }

        $label = $row->roleLabel().' · '.$row->dutyLabel();
        $row->delete();   // สมาชิกในขั้นถูกลบตามด้วย cascade

        return $this->backToRoutes("ลบขั้น {$label} ออกจากเส้นทางแล้ว");
    }

    /**
     * ค้นหาพนักงานสำหรับกล่องเลือกคน (JSON)
     *
     * ไม่ส่งรายชื่อทั้ง 1,500 บัญชีลงไปในหน้า เพราะหน้าตั้งค่าจะอืด — ค้นทีละ 25 รายการพอ
     * ไม่ตัดคนที่อยู่ในเส้นทางแล้วออก เพราะคนเดิมอยู่ได้หลายขั้น (Purchasing เข้ามา 2 รอบ)
     */
    public function searchPeople(Request $request): JsonResponse
    {
        $keyword = trim((string) $request->query('q', ''));

        $query = AppUser::query()
            ->whereNotNull('id_thai_hash')
            ->orderBy('full_name_th');

        if ($keyword !== '') {
            $like = '%'.$keyword.'%';
            $query->where(fn ($q) => $q
                ->where('full_name_th', 'like', $like)
                ->orWhere('full_name_en', 'like', $like)
                ->orWhere('employee_code', 'like', $like)
                ->orWhere('position', 'like', $like)
                ->orWhere('department', 'like', $like));
        }

        $people = $query->limit(25)->get()->map(fn (AppUser $u): array => [
            'id_thai_hash' => $u->id_thai_hash,
            'name' => $u->displayName(),
            'code' => (string) $u->employee_code,
            'position' => (string) ($u->position ?: '—'),
            'department' => (string) ($u->department ?: ''),
            'avatar' => $u->avatarUrl(),
        ]);

        return response()->json(['people' => $people]);
    }

    /**
     * กลับหน้าตั้งค่าพร้อมข้อความยืนยัน และกางกล่องที่เพิ่งทำงานค้างไว้ให้เห็นผล
     *
     * ใช้ flash session ไม่ใช่ query string ตั้งใจ — flash อยู่ได้แค่ request เดียว
     * กด refresh ทีหลังกล่องจะพับกลับเอง (ถ้าใช้ `?open=` จะค้างเปิดทุกครั้งที่ refresh)
     */
    private function backToRoutes(string $message): RedirectResponse
    {
        return redirect()
            ->route('admin.settings')
            ->with('success', $message)
            ->with('open', 'route');
    }

    /** บันทึกสิทธิ์เข้าใช้งานตามตำแหน่ง */
    public function saveAccess(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'positions' => ['array'],
            'positions.*' => ['string'],
        ]);

        $count = PositionAccess::replaceAll($data['positions'] ?? []);

        return back()
            ->with('success', "บันทึกสิทธิ์แล้ว — อนุญาต {$count} ตำแหน่ง (ผู้ดูแลระบบเข้าได้เสมอ)")
            ->with('open', 'access');
    }

    /** ปุ่ม "ซิงค์" (AJAX) */
    public function sync(Request $request, InsightMirror $mirror): JsonResponse
    {
        // ฝั่ง JS จะ reload หน้าหลังซิงค์เสร็จ — flash ไว้ให้กล่องพนักงานกางค้างในรอบนั้น
        $request->session()->flash('open', 'employees');

        if (! $mirror->connectionOk()) {
            $message = 'ต่อฐานข้อมูล Insight ไม่ได้ — ตรวจ INSIGHT_DB_* ใน .env และเช็คว่า MySQL ทำงานอยู่';
            SyncLog::record('manual', false, $message);

            return response()->json(['ok' => false, 'message' => $message], 503);
        }

        try {
            $result = $mirror->sync();
        } catch (Throwable $e) {
            $message = 'ผิดพลาด: '.$e->getMessage();
            SyncLog::record('manual', false, $message);

            return response()->json(['ok' => false, 'message' => $message], 500);
        }

        $e = $result['employees'];
        $u = $result['app_users'];

        $message = sprintf(
            'ซิงค์สำเร็จ · พนักงาน %s คน (ใหม่ %d · อัปเดต %d · ลบ %d) · บัญชีล็อกอิน %s บัญชี (ใหม่ %d · อัปเดต %d · ลบ %d)',
            number_format($e['total']), $e['created'], $e['updated'], $e['removed'],
            number_format($u['total']), $u['created'], $u['updated'], $u['removed'],
        );

        $log = SyncLog::record('manual', true, $message, $result);

        return response()->json([
            'ok' => true,
            'message' => $message,
            'ran_at' => $log->ran_at?->format('d/m/Y H:i:s'),
            'created_list' => array_slice($u['created_list'], 0, 100),
            'removed_list' => array_slice($u['removed_list'], 0, 100),
        ]);
    }
}
