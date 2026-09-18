<?php

use App\Http\Middleware\Authenticate;
use App\Http\Middleware\EnsureAdmin;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Support\Env;

// ⚠️ ห้ามลบ — Apache บนเซิร์ฟเวอร์เป็น process เดียวหลาย thread และรันหลายแอป (QuoteCompare + Insight)
// Laravel เขียน .env ลง environment ของทั้ง process ด้วย putenv() ค่าจึงปนข้ามแอปกันได้
// ผลคือ Dotenv ของแอปนี้เห็นว่าตัวแปรมีค่าอยู่แล้ว (ของอีกแอป) เลยไม่โหลด .env ตัวเอง
// เคยทำให้ DB_DATABASE กลายเป็น insight แล้ว webhook พัง 500 — ปิด putenv ให้อ่าน .env ตัวเองเสมอ
Env::disablePutenv();

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // custom auth (ไม่ใช้ guard มาตรฐานของ Laravel — ดู App\Http\Middleware\Authenticate)
        $middleware->alias([
            'prc.auth' => Authenticate::class,
            'prc.admin' => EnsureAdmin::class,
        ]);

        // webhook จาก Insight ไม่มี session/CSRF token — กันด้วย shared secret แทน
        $middleware->validateCsrfTokens(except: [
            'insight-changed',
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
