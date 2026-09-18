<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * ทะเบียนรหัสสินค้า — Purchasing ค้นแล้วเลือกรหัสหมวดที่มีอยู่แล้ว
 *
 * 🔴 รหัสเต็มคือ "รหัสหมวด" ที่ใช้ซ้ำได้ ไม่ใช่ prefix + เลขคิว (D-042)
 *    ห้ามรันเลขท้ายอัตโนมัติ · ของหลายแบบในหมวดเดียวกันใช้รหัสเดียวกันได้
 *    เช่น FIX-IT-26-001 ใช้กับ Zenbook / Intel i5 / จอมอนิเตอร์ ในหมวดไอทีเหมือนกัน
 */
class ItemCode extends Model
{
    protected $table = 'item_codes';

    protected $fillable = [
        'code', 'name', 'examples', 'family', 'sub', 'prefix', 'seq', 'seq_width', 'used_count', 'source',
    ];

    protected $casts = [
        'seq' => 'integer',
        'seq_width' => 'integer',
        'used_count' => 'integer',
    ];

    /** คำอธิบายหมวดหลัก — เอาไว้โชว์ใน dropdown ให้คนเลือกรู้ว่าคืออะไร */
    public const FAMILIES = [
        'SIR' => 'ของใช้สิ้นเปลือง',
        'FIX' => 'ทรัพย์สินถาวร',
        'MV' => 'Moldvanto — แม่พิมพ์',
        'MVT' => 'Moldvanto — Tooling',
        'MVR' => 'Moldvanto — ของใช้',
        'SIM' => 'ชิ้นส่วนผลิต',
        'SIS' => 'ชิ้นส่วนประกอบ',
        'TP' => 'ชิ้นส่วนลูกค้า',
        'BOX' => 'กล่องบรรจุ',
        'MODIFY' => 'ของที่ไม่มีรหัส',
        'Freight' => 'ค่าขนส่ง',
        'Import' => 'ภาษี/อากรขาเข้า',
    ];

    /**
     * แยกรหัสเต็มออกเป็นท่อน
     *
     * @return array{code:string,family:string,sub:?string,prefix:string,seq:?int,seq_width:int}
     */
    public static function parse(string $code): array
    {
        $code = trim($code);
        $parts = explode('-', $code);
        $last = end($parts);

        // ท่อนท้ายเป็นตัวเลขล้วน = เลขลำดับ ; ถ้าไม่ใช่ถือว่ารหัสนี้ไม่มีเลขรัน
        $hasSeq = count($parts) > 1 && $last !== '' && ctype_digit($last);

        return [
            'code' => $code,
            'family' => $parts[0] ?? $code,
            'sub' => $parts[1] ?? null,
            'prefix' => $hasSeq ? implode('-', array_slice($parts, 0, -1)) : $code,
            'seq' => $hasSeq ? (int) $last : null,
            'seq_width' => $hasSeq ? strlen($last) : 3,
        ];
    }

    /** หมวดหลักที่มีรหัสอยู่จริง พร้อมจำนวน */
    public static function families(): Collection
    {
        return static::query()
            ->selectRaw('family, COUNT(*) AS total')
            ->groupBy('family')
            ->orderByDesc('total')
            ->pluck('total', 'family');
    }

    /** หมวดย่อยของหมวดหลักที่เลือก */
    public static function subsOf(string $family): Collection
    {
        return static::query()
            ->where('family', $family)
            ->whereNotNull('sub')
            ->selectRaw('sub, COUNT(*) AS total')
            ->groupBy('sub')
            ->orderBy('sub')
            ->pluck('total', 'sub');
    }

    /**
     * รหัสทั้งหมดในทะเบียน เรียงจากใช้บ่อยไปน้อย — ฝังไปกับหน้าเว็บทั้งก้อน
     *
     * Manager สั่งให้เห็นครบทุกรหัสตั้งแต่เปิดเมนู ไม่ต้องพิมพ์ค้นก่อน
     * ฝังทั้งหมดทำให้กรองในเครื่องได้ทันทีโดยไม่ต้องรอเซิร์ฟเวอร์
     * (ยังยิง searchExisting() ตอนพิมพ์อยู่ เพื่อค้นชื่อของจากระบบเก่าเพิ่ม)
     *
     * @return Collection<int,array<string,mixed>>
     */
    public static function pickerOptions(): Collection
    {
        return static::searchExisting('', PHP_INT_MAX);
    }

    /**
     * ค้นรหัสที่มีอยู่แล้ว — ค้นได้ทั้งรหัสและ "ชื่อของที่เคยซื้อด้วยรหัสนั้น"
     *
     * ทำไมต้องไปค้นระบบเก่าด้วย: หนึ่งรหัสคือ "หมวด" ที่ใช้กับของหลายแบบ (D-042)
     * `SIR-OSM-0003` ใช้กับของ 302 แบบ ถ้าค้นแต่ชื่อในทะเบียนซึ่งเก็บได้ชื่อเดียว
     * พิมพ์ "ถาด" จะไม่เจอทั้งที่เคยซื้อถาดด้วยรหัสนี้จริง
     *
     * ⚠️ `label` เป็นแค่ **ตัวอย่างของที่เคยซื้อ** ไว้ให้เดาได้ว่าหมวดนี้คืออะไร
     *    **ห้ามเอาไปเติมช่อง Item Description** เพราะหนึ่งรหัสผูกได้หลายรายละเอียด (D-035, D-042)
     *
     * @return Collection<int,array<string,mixed>>
     */
    public static function searchExisting(string $keyword, int $limit = 60): Collection
    {
        $keyword = trim($keyword);

        $query = static::query()
            ->orderByDesc('used_count')
            ->orderBy('code');

        if ($keyword !== '') {
            $matched_codes = static::legacyCodesByItemName($keyword);

            $query->where(function ($q) use ($keyword, $matched_codes): void {
                $q->where('code', 'like', '%'.$keyword.'%')
                    ->orWhere('name', 'like', '%'.$keyword.'%')
                    ->orWhere('examples', 'like', '%'.$keyword.'%')
                    ->when($matched_codes, fn ($q) => $q->orWhereIn('code', $matched_codes));
            });
        }

        return $query
            ->limit($limit)
            ->get(['code', 'name', 'examples', 'family', 'used_count'])
            ->map(fn (self $c): array => [
                'value' => $c->code,
                'kind' => 'existing',
                'label' => $c->pickerLabel(),
                'used' => (int) $c->used_count,
            ]);
    }

    /**
     * คำอธิบายสั้นๆ ใต้รหัสในเมนู
     *
     * เรียงความน่าเชื่อถือ: ตัวอย่างของที่เคยซื้อจริง -> ชื่อตัวแทน -> ชื่อหมวดหลัก
     */
    public function pickerLabel(): string
    {
        foreach ([$this->examples, $this->name] as $text) {
            $text = trim(preg_replace('/\s+/u', ' ', (string) $text));

            if ($text !== '') {
                return Str::limit($text, 64);
            }
        }

        return self::FAMILIES[$this->family] ?? '';
    }

    /**
     * ลงทะเบียนรหัสใหม่ที่ Purchasing กรอกเอง
     *
     * มีไว้เผื่อกรณีที่ฝ่ายบัญชี/ERP ออกรหัสใหม่มาแล้วยังไม่ได้ import
     * ลงทะเบียนไว้เลยจะได้เลือกซ้ำได้ครั้งหน้าโดยไม่ต้องพิมพ์ใหม่
     */
    public static function registerTyped(string $code): ?self
    {
        $code = trim($code);

        if ($code === '' || ! static::looksLikeCode($code)) {
            return null;
        }

        $existing = static::where('code', $code)->first();

        if ($existing) {
            return $existing;
        }

        return static::create(static::parse($code) + [
            'source' => 'prcompare',
            'used_count' => 0,
        ]);
    }

    /** กันขยะเข้าทะเบียน — ต้องขึ้นต้นด้วยตัวอักษร/ตัวเลข และไม่มีอักขระแปลก */
    public static function looksLikeCode(string $code): bool
    {
        return (bool) preg_match('/^[A-Za-z0-9][A-Za-z0-9._\/-]{1,59}$/', trim($code));
    }

    /**
     * รหัสในระบบเก่าที่เคยใช้กับของชื่อนี้
     *
     * ต่อไม่ติดก็ไม่ให้ล้ม — คืนรายการว่างแล้วค้นเฉพาะทะเบียนในเครื่อง
     *
     * @return array<int,string>
     */
    public static function legacyCodesByItemName(string $keyword): array
    {
        if (mb_strlen($keyword) < 2) {
            return [];
        }

        try {
            return DB::connection('pr_legacy')
                ->table('prline_manager')
                ->selectRaw('DISTINCT TRIM(ItemCode) AS code')
                ->where('ItemName', 'like', '%'.$keyword.'%')
                ->whereRaw("TRIM(COALESCE(ItemCode, '')) <> ''")
                ->limit(200)
                ->pluck('code')
                ->all();
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * เก็บรายละเอียดที่ Purchasing พิมพ์ไว้เป็น "ตัวอย่างของหมวดนี้"
     *
     * หนึ่งรหัสใช้กับของหลายแบบ (D-042) จึงสะสมเป็นตัวอย่างไม่เกิน 3 รายการ
     * ไม่ใช่เขียนทับชื่อเดียว — และแตะเฉพาะ `source = prcompare`
     * ทะเบียนที่ import จากระบบเก่าห้ามเขียนทับ
     */
    public static function rememberName(string $code, ?string $name): void
    {
        $name = trim(preg_replace('/\s+/u', ' ', (string) $name));

        if ($code === '' || $name === '') {
            return;
        }

        $item_code = static::where('code', $code)->where('source', 'prcompare')->first();

        if (! $item_code) {
            return;
        }

        $sample = Str::limit($name, 30);
        $examples = collect(explode(' · ', (string) $item_code->examples))
            ->push($sample)
            ->map('trim')
            ->filter()
            ->unique()
            ->take(3);

        $item_code->update([
            'name' => mb_substr($name, 0, 500),
            'examples' => Str::limit($examples->implode(' · '), 250, ''),
        ]);
    }

    /**
     * ลบรหัสที่ PR Compare ลงทะเบียนไว้แต่ไม่มีใครใช้จริง
     *
     * เรียกตอนลบแถวสินค้าหรือเปลี่ยนรหัสในใบร่าง — กันรหัสที่กรอกผิดค้างในทะเบียน
     * แตะเฉพาะ `source = prcompare` ที่ยังไม่มีเอกสารไหนอ้างถึง
     */
    public static function releaseIfUnused(?string $code): bool
    {
        $code = trim((string) $code);

        if ($code === '') {
            return false;
        }

        return (bool) DB::transaction(function () use ($code): bool {
            $item_code = static::where('code', $code)->where('source', 'prcompare')->lockForUpdate()->first();

            if (! $item_code || DB::table('pr_items')->where('item_code', $code)->exists()) {
                return false;
            }

            return (bool) $item_code->delete();
        });
    }

    public function familyLabel(): string
    {
        return self::FAMILIES[$this->family] ?? $this->family;
    }
}
