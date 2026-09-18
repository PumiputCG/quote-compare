# เปลี่ยนชื่อโฟลเดอร์ local จาก "PR Compare" -> "QuoteCompare"
#
# ทำไมต้องรันเอง: โปรแกรมที่เปิดโฟลเดอร์นี้อยู่ (Codex / Claude Code / dev server)
# จะจับโฟลเดอร์ไว้ Windows เลยเปลี่ยนชื่อไม่ได้ ต้องปิดให้หมดก่อน
#
# วิธีใช้
#   1. ปิด Codex · Claude Code · VS Code · หน้าต่าง terminal ที่อยู่ในโฟลเดอร์นี้
#   2. คลิกขวาไฟล์นี้ -> Run with PowerShell
#      (หรือเปิด PowerShell แล้วสั่ง:  powershell -ExecutionPolicy Bypass -File "C:\xampp\htdocs\PR Compare\rename-to-quotecompare.ps1")

$ErrorActionPreference = 'Stop'
$old = 'C:\xampp\htdocs\PR Compare'
$new = 'C:\xampp\htdocs\QuoteCompare'

Set-Location 'C:\'

if (Test-Path $new) {
    Write-Host "มีโฟลเดอร์ QuoteCompare อยู่แล้ว — ไม่ต้องทำอะไร" -ForegroundColor Yellow
    exit 0
}

# ปิด dev server ที่รันจากโฟลเดอร์เก่า
Get-CimInstance Win32_Process |
    Where-Object { $_.CommandLine -like '*127.0.0.1:8000*' -or $_.CommandLine -like '*artisan serve*' } |
    ForEach-Object {
        Write-Host ("ปิด dev server PID " + $_.ProcessId)
        Stop-Process -Id $_.ProcessId -Force -ErrorAction SilentlyContinue
    }

Start-Sleep -Milliseconds 800

try {
    Rename-Item -LiteralPath $old -NewName 'QuoteCompare' -ErrorAction Stop
    Write-Host "เปลี่ยนชื่อโฟลเดอร์เรียบร้อย" -ForegroundColor Green
}
catch {
    Write-Host "เปลี่ยนชื่อไม่ได้ ยังมีโปรแกรมเปิดโฟลเดอร์นี้อยู่:" -ForegroundColor Red
    Get-CimInstance Win32_Process |
        Where-Object { $_.CommandLine -like '*PR Compare*' } |
        ForEach-Object { Write-Host ("   PID {0,-6} {1}" -f $_.ProcessId, $_.Name) }
    Write-Host ""
    Write-Host "ปิดโปรแกรมข้างบนให้หมดแล้วรันไฟล์นี้ใหม่" -ForegroundColor Yellow
    exit 1
}

# APP_URL ของ local ชี้ผ่านชื่อโฟลเดอร์ ต้องตามแก้
$envPath = Join-Path $new '.env'
$content = [System.IO.File]::ReadAllText($envPath)
$content = $content.Replace('APP_URL=http://localhost/PR%20Compare/public', 'APP_URL=http://localhost/QuoteCompare/public')
[System.IO.File]::WriteAllText($envPath, $content)
Write-Host "อัปเดต APP_URL แล้ว"

# ล้าง cache ที่ฝังพาธเดิมไว้
foreach ($cmd in @('config:clear', 'route:clear', 'view:clear', 'cache:clear')) {
    & php (Join-Path $new 'artisan') $cmd | Out-Null
    Write-Host ("   " + $cmd)
}

Write-Host ""
Write-Host "เสร็จแล้ว — เปิด dev server ใหม่ด้วย:" -ForegroundColor Green
Write-Host "   cd `"$new`"; php artisan serve"
Write-Host ""
Write-Host "หมายเหตุ: PR_COMPARE_URL ของ Insight ฝั่ง local ชี้ที่ http://127.0.0.1:8000"
Write-Host "          เป็นพอร์ต ไม่ใช่ชื่อโฟลเดอร์ จึงไม่ต้องแก้"
