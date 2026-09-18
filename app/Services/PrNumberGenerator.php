<?php

namespace App\Services;

use App\Models\PrDocument;
use Illuminate\Support\Facades\DB;
use OverflowException;

class PrNumberGenerator
{
    public function next(): string
    {
        $series = 'PR'.now()->format('y');

        return DB::transaction(function () use ($series): string {
            $existing_max = PrDocument::query()
                ->where('pr_number', 'like', $series.'-%')
                ->pluck('pr_number')
                ->map(function (?string $number) use ($series): int {
                    return preg_match('/^'.preg_quote($series, '/').'-(\d{4})$/', (string) $number, $matches)
                        ? (int) $matches[1]
                        : 0;
                })
                ->max() ?? 0;

            DB::table('pr_number_sequences')->insertOrIgnore([
                'series' => $series,
                'last_number' => $existing_max,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $sequence = DB::table('pr_number_sequences')
                ->where('series', $series)
                ->lockForUpdate()
                ->first();
            $highWaterMark = max((int) ($sequence->last_number ?? 0), $existing_max);
            $used = PrDocument::query()
                ->where('pr_number', 'like', $series.'-%')
                ->pluck('pr_number')
                ->mapWithKeys(function (?string $number) use ($series): array {
                    if (preg_match('/^'.preg_quote($series, '/').'-(\d{4})$/', (string) $number, $matches) !== 1) {
                        return [];
                    }

                    return [(int) $matches[1] => true];
                });

            // เลขของใบร่างที่ถูกลบยังไม่ถือว่าออกใช้งาน จึงนำช่องว่างกลับมาใช้ได้
            $next = 1;
            while ($used->has($next)) {
                $next++;
            }

            if ($next > 9999) {
                throw new OverflowException("เลขเอกสารชุด {$series} ครบ 9,999 ใบแล้ว");
            }

            DB::table('pr_number_sequences')
                ->where('series', $series)
                ->update([
                    'last_number' => max($highWaterMark, $next),
                    'updated_at' => now(),
                ]);

            return $series.'-'.str_pad((string) $next, 4, '0', STR_PAD_LEFT);
        }, 3);
    }
}
