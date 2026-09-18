<?php

namespace App\Http\Middleware;

use App\Models\AppUser;
use App\Models\DocumentRole;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\View;
use Symfony\Component\HttpFoundation\Response;

/**
 * ยืนยันตัวตนด้วย session เอง (custom auth — เทียบ password แบบ plaintext เหมือน Insight)
 *
 * - ไม่มี session / บัญชีหายจากมิเรอร์ (พนักงานลาออก) -> เด้งไปหน้าล็อกอิน
 * - แชร์ตัวแปร $me (AppUser) ให้ทุก view + ผูกใน container ('current_user')
 */
class Authenticate
{
    /** คีย์ session ของโปรเจคนี้ (ตั้งไม่ให้ชนกับ Insight ที่รันบนโดเมนเดียวกัน) */
    public const SESSION_KEY = 'prc_user_id';

    public function handle(Request $request, Closure $next): Response
    {
        $id = $request->session()->get(self::SESSION_KEY);
        $me = $id ? AppUser::find($id) : null;

        if (! $me) {
            $request->session()->forget(self::SESSION_KEY);

            return redirect()->route('login')->with('error', 'กรุณาเข้าสู่ระบบก่อนใช้งาน');
        }

        app()->instance('current_user', $me);
        View::share('me', $me);

        // เมนูงานใน sidebar ขึ้นตามเส้นทางที่ admin กำหนด (ดู App\Models\DocumentRole::menuFor)
        View::share('workMenu', DocumentRole::menuFor($me));

        return $next($request);
    }
}
