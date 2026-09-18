<?php

use App\Http\Controllers\Admin\SettingsController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\InsightAssetController;
use App\Http\Controllers\InsightWebhookController;
use App\Http\Controllers\OverviewController;
use App\Http\Controllers\PrDocumentController;
use App\Http\Controllers\WorkController;
use App\Models\DocumentRole;
use Illuminate\Support\Facades\Route;

/*
| PR Compare — ระบบเปรียบเทียบราคาผู้ขาย (ฝ่ายจัดซื้อ)
|
| Auth เป็น custom session (ดู App\Http\Middleware\Authenticate) เทียบรหัสผ่าน plaintext
| บัญชีทั้งหมดมิเรอร์มาจาก Supavut Insight — แก้ไขพนักงานให้ไปทำที่ Insight ที่เดียว
| สิทธิ์เข้าใช้ระบบคุมด้วย "ตำแหน่ง" ในหน้าตั้งค่าระบบ (App\Models\PositionAccess)
*/

/*
| เข้าเว็บแล้วไปหน้าล็อกอินเลย (ยังไม่มีหน้า landing สาธารณะ)
|
| ⚠️ ห้ามใช้ `Route::redirect('/', '/login')` — มันส่ง Location เป็น `/login` ดิบๆ
|    พอ deploy ไว้ใต้โฟลเดอร์ย่อย (`/PRCompare/public`) เบราว์เซอร์จะวิ่งไป
|    `http://host/login` แล้วเจอ 404 ; `redirect()->route()` สร้าง URL เต็มรวม base path ให้เอง
*/
Route::get('/', fn () => redirect()->route('login'));

Route::get('/login', [LoginController::class, 'show'])->name('login');
Route::post('/login', [LoginController::class, 'login'])->name('login.attempt');

/*
| Webhook จาก Insight — อัปเดตข้อมูลพนักงานทันทีที่มีการแก้ที่ต้นทาง
| ไม่ผ่าน middleware ล็อกอิน กันด้วย shared secret (INSIGHT_WEBHOOK_SECRET)
| ยกเว้น CSRF ที่ bootstrap/app.php เพราะเรียกมาจากระบบอื่น ไม่ใช่ฟอร์มในเว็บนี้
*/
Route::post('/insight-changed', [InsightWebhookController::class, 'handle'])->name('insight.changed');

Route::middleware('prc.auth')->group(function () {
    Route::post('/logout', [LoginController::class, 'logout'])->name('logout');

    Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');

    // ภาพรวมเอกสาร — เห็นได้ทุกคน (เส้นทางของแต่ละใบ + ดาวน์โหลด PDF)
    Route::get('/overview', [OverviewController::class, 'index'])->name('overview');
    // เปิดอ่านเอกสารจากหน้าภาพรวม (ปุ่ม "ดู") — อ่านอย่างเดียว
    Route::get('/overview/{document}', [OverviewController::class, 'show'])->name('overview.show');
    // รายงานสรุปเอกสารที่ส่งแล้ว เป็นไฟล์ Excel — เฉพาะฝ่ายจัดซื้อขั้นจัดทำและ admin (D-073)
    Route::get('/overview-export', [OverviewController::class, 'export'])->name('overview.export');
    // ดาวน์โหลดเป็นไฟล์ PDF เข้าเครื่อง — เฉพาะใบที่อนุมัติครบทุกขั้น (D-072)
    Route::get('/overview/{document}/pdf', [OverviewController::class, 'pdf'])->name('overview.pdf');

    /*
    | จัดทำเอกสาร (Purchasing) — ต้องมาก่อน /work/{duty} เพราะ path ชนกัน
    | ใบเปรียบเทียบราคาผู้ขาย : รายการ → ฟอร์มหน้าตาเหมือน Compare Sheet → ส่งให้ผู้ขอซื้อ
    */
    Route::get('/work/create', [PrDocumentController::class, 'index'])->name('documents.index');
    Route::post('/documents', [PrDocumentController::class, 'store'])->name('documents.store');
    Route::get('/item-codes/search', [PrDocumentController::class, 'searchItemCodes'])->name('item-codes.search');
    Route::post('/documents/{document}/reject', [PrDocumentController::class, 'reject'])->name('documents.reject');
    Route::get('/documents/{document}', [PrDocumentController::class, 'edit'])->name('documents.edit');
    Route::post('/documents/{document}', [PrDocumentController::class, 'update'])->name('documents.update');
    Route::post('/documents/{document}/send', [PrDocumentController::class, 'send'])->name('documents.send');
    Route::post('/documents/{document}/delete', [PrDocumentController::class, 'destroy'])->name('documents.destroy');

    // ไฟล์แนบ — ผูกกับผู้ขายแต่ละเจ้า
    Route::post('/suppliers/{supplier}/files', [PrDocumentController::class, 'uploadAttachment'])->name('documents.files.upload');
    Route::get('/files/{attachment}', [PrDocumentController::class, 'downloadAttachment'])->name('documents.files.download');
    Route::get('/files/{attachment}/view', [PrDocumentController::class, 'viewAttachment'])->name('documents.files.view');
    Route::post('/files/{attachment}/delete', [PrDocumentController::class, 'deleteAttachment'])->name('documents.files.delete');

    // ค้นหาพนักงานสำหรับช่อง Department Request
    Route::get('/documents-people', [PrDocumentController::class, 'searchPeople'])->name('documents.people');

    // ผู้ขอซื้อ — ดูใบที่ส่งถึงตัวเอง เลือก Supplier และลงนามคัดเลือกรายการ
    Route::get('/work/select', [WorkController::class, 'selectIndex'])->name('work.select');
    Route::get('/work/select/{document}', [WorkController::class, 'selectEdit'])->name('work.select.edit');
    Route::post('/work/select/{document}', [WorkController::class, 'selectUpdate'])->name('work.select.update');
    Route::post('/work/select/{document}/send', [WorkController::class, 'sendToNegotiation'])->name('work.select.send');

    // ต่อรองราคา — เปิดดูเอกสารและแนบไฟล์ชุดหลังการต่อรองแยกจากไฟล์ขั้นจัดทำ
    Route::get('/work/negotiate', [WorkController::class, 'negotiateIndex'])->name('work.negotiate');
    Route::get('/work/negotiate/{document}', [WorkController::class, 'negotiateEdit'])->name('work.negotiate.edit');
    Route::post('/work/negotiate/{document}', [WorkController::class, 'negotiateUpdate'])->name('work.negotiate.update');
    // ต่อรองเสร็จแล้วส่งกลับให้ผู้ขอซื้อยืนยันรายการ ไม่ได้ส่งตรงไปลงนาม (D-075)
    Route::post('/work/negotiate/{document}/send', [WorkController::class, 'sendToConfirmation'])->name('work.negotiate.send');
    // ตอบทีละบริษัทว่าต่อรองแล้วได้ใบเสนอราคาใหม่ไหม (บางเจ้าส่งมา บางเจ้าไม่ส่ง)
    Route::post('/work/negotiate/suppliers/{supplier}/quote-flag', [WorkController::class, 'setNegotiationQuoteFlag'])
        ->name('work.negotiate.quote-flag');
    Route::post('/work/negotiate/suppliers/{supplier}/files', [WorkController::class, 'uploadNegotiationAttachment'])
        ->name('work.negotiate.files.upload');
    Route::post('/work/negotiate/files/{attachment}/delete', [WorkController::class, 'deleteNegotiationAttachment'])
        ->name('work.negotiate.files.delete');

    // ยืนยันรายการ — ผู้ขอซื้อดูราคาหลังต่อรองครบทุกเจ้า เลือกเจ้าที่จะซื้อจริง แล้วลงนามยืนยัน
    Route::get('/work/confirm', [WorkController::class, 'confirmIndex'])->name('work.confirm');
    Route::get('/work/confirm/{document}', [WorkController::class, 'confirmEdit'])->name('work.confirm.edit');
    Route::post('/work/confirm/{document}', [WorkController::class, 'confirmUpdate'])->name('work.confirm.update');
    Route::post('/work/confirm/{document}/send', [WorkController::class, 'sendToApproval'])->name('work.confirm.send');

    // ลงนามอนุมัติ — Manager ลงนามแล้วส่งต่อ CEO; CEO ลงนามแล้วจบ Process
    Route::get('/work/sign', [WorkController::class, 'approvalIndex'])->name('work.approval');
    Route::get('/work/sign/{document}', [WorkController::class, 'approvalEdit'])->name('work.approval.edit');
    Route::post('/work/sign/{document}', [WorkController::class, 'approvalUpdate'])->name('work.approval.update');
    // ประทับลายเซ็นแล้วต้องกดยืนยันอีกครั้ง เอกสารถึงจะเดินหน้า (D-070)
    Route::post('/work/sign/{document}/send', [WorkController::class, 'sendApproval'])->name('work.approval.send');

    // หน้าทำงานของแต่ละขั้นในเส้นทาง — เมนูขึ้นตามที่ admin กำหนดใน "กำหนดเส้นทาง"
    Route::get('/work/{duty}', [WorkController::class, 'show'])
        ->whereIn('duty', array_keys(DocumentRole::MENU))
        ->name('work');

    // รูปโปรไฟล์จากสตอเรจของ Insight (ใช้เมื่อไม่มี symlink public/insight-storage)
    Route::get('/insight-storage/{path}', [InsightAssetController::class, 'show'])
        ->where('path', '.*')
        ->name('insight.asset');

    // ── เฉพาะผู้ดูแลระบบ ──────────────────────────────────────────────
    Route::middleware('prc.admin')->prefix('admin')->name('admin.')->group(function () {
        Route::get('/settings', [SettingsController::class, 'index'])->name('settings');
        Route::post('/settings/access', [SettingsController::class, 'saveAccess'])->name('settings.access');
        Route::post('/settings/sync', [SettingsController::class, 'sync'])->name('settings.sync');

        // เส้นทางเอกสาร PR — กำหนดว่าใครเป็น Purchasing / Mgr. Purchasing / CEO
        Route::get('/settings/people', [SettingsController::class, 'searchPeople'])->name('settings.people');
        Route::post('/settings/role', [SettingsController::class, 'addRole'])->name('settings.role.add');
        Route::post('/settings/role/update', [SettingsController::class, 'updateRole'])->name('settings.role.update');
        Route::post('/settings/role/move', [SettingsController::class, 'moveRole'])->name('settings.role.move');
        Route::post('/settings/role/remove', [SettingsController::class, 'removeRole'])->name('settings.role.remove');

        // พนักงานในแต่ละขั้น (หนึ่งขั้นมีได้หลายคน)
        Route::post('/settings/role/member', [SettingsController::class, 'addMember'])->name('settings.member.add');
        Route::post('/settings/role/member/remove', [SettingsController::class, 'removeMember'])->name('settings.member.remove');
    });
});
