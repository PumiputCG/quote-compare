<?php

namespace App\Http\Controllers;

use App\Models\Employee;
use Illuminate\View\View as ViewContract;

/**
 * หน้าแรกหลังล็อกอิน — สรุปสั้นๆ ว่าใครเข้าใช้ระบบ และมีข้อมูลอะไรอยู่บ้าง
 */
class DashboardController extends Controller
{
    public function index(): ViewContract
    {
        $me = app('current_user');

        return view('dashboard', [
            'me' => $me,
            // แสดงเฉพาะจำนวนพนักงานที่ยังทำงานอยู่ (ตัวเลขอื่นดูได้ที่หน้าตั้งค่าระบบ)
            'activeEmployees' => $me->isAdmin() ? Employee::active()->count() : null,
        ]);
    }
}
