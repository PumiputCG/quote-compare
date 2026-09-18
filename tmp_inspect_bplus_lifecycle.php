<?php

declare(strict_types=1);

require 'C:/xampp/htdocs/Insight/vendor/autoload.php';

$app = require 'C:/xampp/htdocs/Insight/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;

Config::set('database.connections.bplus.database', 'BPLUSHRM_SUPAVUT_INDUSTRY');
DB::purge('bplus');

$columns = DB::connection('bplus')->select(<<<'SQL'
SELECT TABLE_NAME, COLUMN_NAME, DATA_TYPE, ORDINAL_POSITION
FROM INFORMATION_SCHEMA.COLUMNS
WHERE TABLE_NAME IN ('PRTRAN', 'PRDEFTAB', 'PAYROLLINFO', 'PERSONALINFO')
ORDER BY TABLE_NAME, ORDINAL_POSITION
SQL);

$samples = DB::connection('bplus')->select(<<<'SQL'
SELECT TOP 10 prt.*
FROM dbo.PRTRAN AS prt
WHERE CONVERT(date, prt.PRT_DATE) >= '2026-08-01'
ORDER BY prt.PRT_DATE DESC
SQL);

echo json_encode([
  'columns' => $columns,
  'samples' => $samples,
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
