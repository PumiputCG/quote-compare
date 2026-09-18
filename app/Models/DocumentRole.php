<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

/**
 * หนึ่งขั้นในเส้นทางเอกสาร PR — **ตารางของ PR Compare เอง** ไม่ได้มิเรอร์จาก Insight
 *
 * admin ต่อการ์ดเองที่หน้า "ตั้งค่าระบบ → กำหนดเส้นทาง" : 1 การ์ด = 1 ขั้น = Role + หน้าที่ + คน
 * เรียงลำดับด้วย `step_no` (น้อยไปมาก) แล้วเชื่อมกันด้วยลูกศรบนหน้าจอ
 *
 * สองอย่างที่ต่างจากที่คิดตอนแรก:
 *   - **คนเดิมใส่ซ้ำได้หลายขั้น** เพราะ Purchasing เข้ามา 2 รอบ (สร้างเอกสาร / ต่อราคา)
 *   - **ขั้น `user` ไม่ระบุตัวคน** (`id_thai_hash = null`) — Purchasing เลือกเองตอนสร้างเอกสารจริง
 */
class DocumentRole extends Model
{
    protected $table = 'document_roles';

    protected $fillable = ['step_no', 'role', 'duty'];

    protected $casts = ['step_no' => 'integer'];

    /** Role ที่ใส่ในเส้นทางได้ */
    public const ROLES = [
        'purchasing' => 'Purchasing',
        'user' => 'User',
        'mgr_purchasing' => 'Asst.Mgr.Purchasing / Mgr.Purchasing',
        'ceo' => 'CEO',
    ];

    /** ชื่อย่อ ใช้บนการ์ดที่พื้นที่แคบ */
    public const SHORT = [
        'purchasing' => 'Purchasing',
        'user' => 'User',
        'mgr_purchasing' => 'Mgr. Purchasing',
        'ceo' => 'CEO',
    ];

    /**
     * การดำเนินการที่แต่ละ Role ทำได้ — admin เลือกตอนเพิ่มการ์ด
     *
     * ใช้คำแบบเอกสารราชการ/จัดซื้อ ไม่ใช่คำพูดทั่วไป
     * (สร้างเอกสาร → จัดทำเอกสาร · ต่อราคา → ต่อรองราคา · เลือกของ → คัดเลือกรายการ · เซ็น → ลงนามอนุมัติ)
     */
    public const DUTIES = [
        'purchasing' => [
            'create' => 'จัดทำเอกสาร',
            'negotiate' => 'ต่อรองราคา',
        ],
        'user' => [
            'select' => 'คัดเลือกรายการ',
            // ขั้นที่ 4 ของเส้นทาง — ยืนยันราคาที่ต่อรองมาแล้ว ก่อนส่งให้ผู้บริหารลงนาม (D-075)
            'confirm' => 'ยืนยันรายการ',
        ],
        'mgr_purchasing' => [
            'sign' => 'ลงนามอนุมัติ',
        ],
        'ceo' => [
            'sign' => 'ลงนามอนุมัติ',
        ],
    ];

    /**
     * Role ที่ **ไม่ต้อง** ระบุตัวคนตอนตั้งค่า
     *
     * ขั้น User เป็นช่องว่างที่เว้นไว้ — Purchasing เลือกเองตอนสร้างเอกสารว่าจะส่งให้ใคร
     */
    public const ROLE_USER = 'user';

    /**
     * ชื่อเมนูใน sidebar ของแต่ละการดำเนินการ
     *
     * เมนูไม่ได้ hardcode ไว้ — ขึ้นตามเส้นทางที่ admin กำหนดและตามว่าใครอยู่ขั้นไหน
     * (ดู menuFor) ; key ตรงกับคอลัมน์ `duty` และใช้เป็นส่วนหนึ่งของ URL `/work/{duty}`
     */
    public const MENU = [
        'create' => 'จัดทำเอกสาร',
        'select' => 'ผู้ขอซื้อ',
        'negotiate' => 'ต่อรองราคา',
        'confirm' => 'ยืนยันรายการ',
        'sign' => 'ลงนามอนุมัติ',
    ];

    /** พนักงานทุกคนที่อยู่ในขั้นนี้ (หนึ่งขั้นมีได้หลายคน) */
    public function members(): HasMany
    {
        return $this->hasMany(DocumentRoleMember::class, 'document_role_id')->orderBy('id');
    }

    /**
     * ทุกขั้นเรียงตามลำดับในเส้นทาง
     *
     * @return EloquentCollection<int,DocumentRole>
     */
    public static function chain(): EloquentCollection
    {
        return static::query()
            ->with('members.appUser')
            ->orderBy('step_no')
            ->orderBy('id')
            ->get();
    }

    /**
     * แผนผังสั้นๆ ไว้แสดงหัวกล่อง — สร้างจาก Role + หน้าที่ ที่ admin เลือกจริง
     *
     * @param  EloquentCollection<int,DocumentRole>  $chain
     * @return array<int,array{no:int,label:string,verb:string}>
     */
    public static function flowOf(EloquentCollection $chain): array
    {
        $flow = [];

        foreach ($chain as $i => $step) {
            $flow[] = [
                'no' => $i + 1,
                'label' => self::SHORT[$step->role] ?? $step->role,
                'verb' => $step->dutyLabel(),
            ];
        }

        return $flow;
    }

    /**
     * ต่อการ์ดใหม่ท้ายแถว (ห้ามตั้งชื่อว่า append — ชนกับ Model::append ของ Eloquent)
     *
     * ขั้น User ไม่ต้องส่ง $idThaiHash เพราะยังไม่รู้ว่าจะส่งให้ใคร
     * ขั้นอื่นใส่คนแรกให้เลย แล้วค่อยเพิ่มคนอื่นทีหลังที่การ์ด
     */
    public static function addStep(string $role, ?string $duty = null, ?string $idThaiHash = null): self
    {
        $step = static::create([
            'step_no' => (int) static::query()->max('step_no') + 1,
            'role' => $role,
            'duty' => static::resolveDuty($role, $duty),
        ]);

        if ($role !== self::ROLE_USER && $idThaiHash) {
            $step->addMember($idThaiHash);
        }

        return $step;
    }

    /** เพิ่มพนักงานเข้าขั้นนี้ (คนเดิมใส่ซ้ำไม่ได้ · ขั้น User ไม่รับสมาชิก) */
    public function addMember(string $idThaiHash): ?DocumentRoleMember
    {
        if ($this->isUserStep()) {
            return null;
        }

        return DocumentRoleMember::firstOrCreate([
            'document_role_id' => $this->id,
            'id_thai_hash' => $idThaiHash,
        ]);
    }

    /**
     * เมนูงานของผู้ใช้คนนี้ — สร้างจากเส้นทางที่ admin กำหนดไว้
     *
     * กติกา:
     *   - ขั้น Role `user` ขึ้นให้ **ทุกคน** เพราะพนักงานทุกคนเป็นผู้ขอซื้อได้
     *   - ขั้นอื่นขึ้นเฉพาะคนที่ถูกใส่ชื่อไว้ในขั้นนั้น
     *   - เรียงตามลำดับขั้นในเส้นทาง · การดำเนินการซ้ำกันนับเป็นเมนูเดียว
     *
     * @return array<string,string> ['duty' => 'ชื่อเมนู']
     */
    public static function menuFor(?AppUser $user): array
    {
        $hash = trim((string) $user?->id_thai_hash);
        $menu = [];

        foreach (static::query()->with('members')->orderBy('step_no')->orderBy('id')->get() as $step) {
            $mine = $step->isUserStep()
                || ($hash !== '' && $step->members->contains('id_thai_hash', $hash));

            if ($mine) {
                $menu[$step->duty] = self::MENU[$step->duty] ?? $step->dutyLabel();
            }
        }

        // Purchase ใช้สองขั้นเป็นงานต่อเนื่อง จึงวางต่อรองราคาไว้ใต้จัดทำเอกสาร
        // ก่อนเมนูผู้ขอซื้อที่ทุกคนมองเห็น
        if (isset($menu['create'], $menu['negotiate'])) {
            $ordered = [];
            foreach ($menu as $duty => $label) {
                if ($duty === 'negotiate') {
                    continue;
                }

                $ordered[$duty] = $label;
                if ($duty === 'create') {
                    $ordered['negotiate'] = $menu['negotiate'];
                }
            }

            $menu = $ordered;
        }

        return $menu;
    }

    /** หน้าที่ทั้งหมดที่ Role นี้เลือกได้ */
    public static function dutiesFor(string $role): array
    {
        return self::DUTIES[$role] ?? [];
    }

    /** หน้าที่แรกของ Role — ใช้เป็นค่าตั้งต้นเมื่อไม่ได้ระบุหรือระบุมาไม่ถูก */
    public static function defaultDutyFor(string $role): string
    {
        return (string) array_key_first(static::dutiesFor($role) ?: ['sign' => 'เซ็น']);
    }

    /** ตรวจว่าหน้าที่ที่ส่งมาใช้กับ Role นี้ได้ไหม ถ้าไม่ได้ให้ถอยไปใช้ค่าตั้งต้น */
    public static function resolveDuty(string $role, ?string $duty): string
    {
        return array_key_exists((string) $duty, static::dutiesFor($role))
            ? (string) $duty
            : static::defaultDutyFor($role);
    }

    /**
     * เลื่อนการ์ดไปทางซ้าย/ขวา โดยสลับ step_no กับใบที่ติดกัน
     *
     * @param  string  $direction  'up' = ไปทางซ้าย · 'down' = ไปทางขวา
     */
    public function move(string $direction): bool
    {
        $neighbour = static::query()
            ->when(
                $direction === 'up',
                fn ($q) => $q->where('step_no', '<', $this->step_no)->orderByDesc('step_no'),
                fn ($q) => $q->where('step_no', '>', $this->step_no)->orderBy('step_no'),
            )
            ->first();

        if (! $neighbour) {
            return false;   // อยู่หัวแถวหรือท้ายแถวแล้ว
        }

        DB::transaction(function () use ($neighbour): void {
            [$this->step_no, $neighbour->step_no] = [$neighbour->step_no, $this->step_no];
            $this->save();
            $neighbour->save();
        });

        return true;
    }

    /** ขั้นนี้ต้องระบุตัวคนไหม (ขั้น User ไม่ต้อง) */
    public function needsPerson(): bool
    {
        return $this->role !== self::ROLE_USER;
    }

    public function isUserStep(): bool
    {
        return $this->role === self::ROLE_USER;
    }

    /** สถานะเอกสารที่เปิดให้ขั้นนี้ลงนามได้ */
    public function signatureStatus(): ?string
    {
        return match ($this->duty) {
            'create' => 'draft',
            'select' => 'sent_user',
            'negotiate' => 'negotiating',
            'confirm' => 'confirming',
            'sign' => match ($this->role) {
                'mgr_purchasing' => 'waiting_mgr',
                'ceo' => 'waiting_ceo',
                default => null,
            },
            default => null,
        };
    }

    /** ขั้นที่กำหนดสถานะไว้เป็นจุดลงนามของเส้นทางเอกสาร */
    public function requiresSignature(): bool
    {
        return $this->signatureStatus() !== null;
    }

    public function canSignInStatus(string $status): bool
    {
        return $this->signatureStatus() === $status;
    }

    public function roleLabel(): string
    {
        return self::ROLES[$this->role] ?? $this->role;
    }

    public function dutyLabel(): string
    {
        return static::dutiesFor($this->role)[$this->duty] ?? (string) $this->duty;
    }
}
