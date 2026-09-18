<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Middleware\Authenticate;
use App\Models\AppUser;
use App\Models\PositionAccess;
use App\Services\InsightMirror;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\View;
use Illuminate\View\View as ViewContract;
use Throwable;

/**
 * ล็อกอินด้วย รหัสพนักงาน + รหัสผ่าน (เทียบ plaintext — สืบทอด decision D-007 จาก Insight)
 *
 * บัญชีทั้งหมดมาจากมิเรอร์ของ Insight :
 *   - รหัสผ่านเริ่มต้นของพนักงาน = เลขบัตรประชาชน (เปลี่ยนได้ที่ Insight)
 *   - system admin: employee_code = Admin
 *   - คนข้ามบริษัทพิมพ์รหัสของบริษัทไหนก็เข้าได้ (resolve จาก map `companies`)
 *   - พนักงานลาออกจะไม่มีบัญชีใน Insight -> มิเรอร์ก็ไม่มี -> ล็อกอินไม่ได้
 *
 * เพิ่มเติมของ PR Compare เอง: รหัสผ่านถูกแล้วยังต้องผ่านสิทธิ์ตาม "ตำแหน่ง" อีกชั้น
 * (ดู App\Models\PositionAccess — admin ตั้งค่าที่หน้าตั้งค่าระบบ ; role=admin ผ่านเสมอ)
 *
 * ถ้าหารหัสในมิเรอร์ไม่เจอ จะลองถาม Insight สดอีกครั้งแล้วดึงบัญชีนั้นเข้ามาทันที
 * (พนักงานเพิ่งเข้าใหม่จึงล็อกอินได้เลย ไม่ต้องรอรอบซิงค์ 15 นาที)
 */
class LoginController extends Controller
{
    public function show(Request $request): ViewContract|RedirectResponse
    {
        if ($request->session()->get(Authenticate::SESSION_KEY)) {
            return redirect()->route('dashboard');
        }

        return View::make('auth.login');
    }

    public function login(Request $request, InsightMirror $mirror): RedirectResponse
    {
        $data = $request->validate([
            'employee_code' => ['required', 'string'],
            'password' => ['required', 'string'],
        ], [], [
            'employee_code' => 'รหัสพนักงาน',
            'password' => 'รหัสผ่าน',
        ]);

        $code = trim($data['employee_code']);
        $user = $this->resolve($code) ?? $this->resolveFromInsight($mirror, $code);

        // แยกข้อความ: ไม่พบรหัสพนักงาน vs รหัสผ่านไม่ถูกต้อง (แสดงใต้ช่องที่ผิด)
        if (! $user) {
            return back()
                ->withInput($request->only('employee_code'))
                ->withErrors(['employee_code' => 'ไม่พบรหัสพนักงานนี้ในระบบ']);
        }

        if (! hash_equals((string) $user->password, (string) $data['password'])) {
            return back()
                ->withInput($request->only('employee_code'))
                ->withErrors(['password' => 'รหัสผ่านไม่ถูกต้อง']);
        }

        // รหัสผ่านถูกแล้ว แต่ตำแหน่งอาจยังไม่ได้รับสิทธิ์เข้าระบบนี้ (ตั้งที่หน้าตั้งค่าระบบ)
        if (! PositionAccess::allows($user)) {
            return back()
                ->withInput($request->only('employee_code'))
                ->withErrors(['employee_code' => 'ตำแหน่งของคุณยังไม่ได้รับสิทธิ์เข้าใช้ระบบ — ติดต่อผู้ดูแลระบบ']);
        }

        $request->session()->regenerate();   // กัน session fixation
        $request->session()->put(Authenticate::SESSION_KEY, $user->id);

        return redirect()->intended(route('dashboard'));
    }

    public function logout(Request $request): RedirectResponse
    {
        $request->session()->forget(Authenticate::SESSION_KEY);
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login')->with('success', 'ออกจากระบบแล้ว');
    }

    /** หาบัญชีในมิเรอร์: รหัสหลัก หรือรหัสในบริษัทใดบริษัทหนึ่ง */
    private function resolve(string $code): ?AppUser
    {
        return AppUser::where('employee_code', $code)
            ->orWhereRaw("JSON_SEARCH(companies, 'one', ?) IS NOT NULL", [$code])
            ->first();
    }

    /** ยังไม่มีในมิเรอร์ -> ถาม Insight สด แล้วดึงบัญชีเดียวเข้ามา (Insight ล่มก็แค่คืน null) */
    private function resolveFromInsight(InsightMirror $mirror, string $code): ?AppUser
    {
        try {
            return $mirror->pullUserByCode($code);
        } catch (Throwable) {
            return null;
        }
    }
}
