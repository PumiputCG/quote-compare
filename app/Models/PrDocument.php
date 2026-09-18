<?php

namespace App\Models;

use App\Services\ResignationGuard;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;

/**
 * ใบเปรียบเทียบราคาผู้ขาย 1 ใบ (หัวเอกสาร)
 *
 * ผู้ขายสูงสุด 3 เจ้าตามฟอร์ม Excel — `supplier_count` บอกว่าใบนี้ใช้จริงกี่เจ้า
 * ช่องของเจ้าที่ไม่ได้ใช้จะถูกเบลอเทาในเอกสาร (ไม่ลบทิ้ง เผื่อเพิ่มทีหลัง)
 */
class PrDocument extends Model
{
    protected $table = 'pr_documents';

    /** จำนวนผู้ขายสูงสุดที่ฟอร์มรองรับ */
    public const MAX_SUPPLIERS = 3;

    /** สกุลเงินที่ใช้กำกับ Total Amount — code => ป้ายในวงเล็บ */
    public const CURRENCIES = [
        'THB' => 'Baht',
        'USD' => '$',
        'JPY' => '¥',
        'EUR' => '€',
        'CNY' => 'CNY',
    ];

    protected $fillable = [
        'pr_number', 'document_date', 'company', 'currency',
        'requester_id_thai_hash', 'requester_name', 'requester_department',
        'purpose', 'supplier_count', 'comment', 'has_negotiation_quote',
        'status', 'created_by_id_thai_hash',
        'rejected_reason', 'rejected_by_name', 'rejected_at',
    ];

    protected $casts = [
        'document_date' => 'date',
        'supplier_count' => 'integer',
        'rejected_at' => 'datetime',
        'has_negotiation_quote' => 'boolean',
    ];

    /** สถานะเอกสารตามเส้นทาง */
    public const STATUSES = [
        'draft' => 'ร่าง',
        'sent_user' => 'รอผู้ขอซื้อเลือก',
        'negotiating' => 'รอต่อรองราคา',
        'confirming' => 'รอผู้ขอซื้อยืนยัน',
        'waiting_mgr' => 'รอผู้จัดการลงนาม',
        'waiting_ceo' => 'รอ CEO ลงนาม',
        'approved' => 'อนุมัติแล้ว',
        'rejected' => 'ปฏิเสธ',
    ];

    /**
     * ลำดับที่เอกสารเดินผ่านสถานะต่างๆ — เป็นคุณสมบัติของตัวสถานะเอง ไม่ใช่ของเส้นทาง
     *
     * ใช้เทียบว่าขั้นหนึ่ง "ผ่านไปแล้วหรือยัง" โดยไม่ต้องรู้ว่าขั้นนั้นอยู่ลำดับที่เท่าไร
     * (`approved` ไม่อยู่ในนี้เพราะแปลว่าจบทุกขั้น ไม่ใช่ขั้นที่รออยู่)
     */
    private const STATUS_ORDER = ['draft', 'sent_user', 'negotiating', 'confirming', 'waiting_mgr', 'waiting_ceo'];

    /** ลำดับของสถานะ — `null` เมื่อไม่ใช่สถานะที่รอการดำเนินการของขั้นใดขั้นหนึ่ง */
    private static function statusRank(?string $status): ?int
    {
        $rank = array_search((string) $status, self::STATUS_ORDER, true);

        return $rank === false ? null : $rank;
    }

    public function suppliers(): HasMany
    {
        return $this->hasMany(PrSupplier::class, 'pr_document_id')->orderBy('slot');
    }

    public function items(): HasMany
    {
        return $this->hasMany(PrItem::class, 'pr_document_id')->orderBy('row_no')->orderBy('id');
    }

    public function signatures(): HasMany
    {
        return $this->hasMany(PrDocumentSignature::class, 'pr_document_id')->orderBy('step_no');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(AppUser::class, 'created_by_id_thai_hash', 'id_thai_hash');
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(AppUser::class, 'requester_id_thai_hash', 'id_thai_hash');
    }

    public function statusLabel(): string
    {
        // ปฏิเสธเก็บเป็น `rejected_{ลำดับขั้น}` เพื่อรู้ว่าตกที่ขั้นไหน แต่โชว์คำเดียวพอ
        if (str_starts_with((string) $this->status, 'rejected')) {
            return 'ปฏิเสธ';
        }

        return self::STATUSES[$this->status] ?? $this->status;
    }

    /** สถานะรวมสำหรับหน้ารายการงาน ไม่เปิดเผยว่ากำลังค้างอยู่ที่ขั้นใด */
    public function workStatusLabel(): string
    {
        if ($this->isRejected()) {
            return 'ปฏิเสธ';
        }

        return match ($this->status) {
            'draft' => 'ใบร่าง',
            'approved' => 'ดำเนินการแล้ว',
            default => 'รอดำเนินการ',
        };
    }

    /**
     * สถานะงานของขั้นที่กำลังเปิดดู ไม่ใช่สถานะรวมของเอกสารทั้งใบ
     *
     * @param  Collection<int,DocumentRole>  $workflowSteps
     * @return array{state:string,label:string}
     */
    public function workStatusForStep(DocumentRole $currentStep, Collection $workflowSteps): array
    {
        // ปฏิเสธแล้วคือจบทั้งใบ ไม่ใช่จบเฉพาะขั้น — ทุกแท็บต้องเห็นว่า "ปฏิเสธ"
        // (ไม่งั้นแท็บจัดทำเอกสารจะขึ้น "ดำเนินการแล้ว" ทั้งที่ใบนี้ตายไปแล้ว)
        if ($this->isRejected()) {
            return ['state' => 'rejected', 'label' => 'ปฏิเสธ'];
        }

        $node = collect($this->routeProgress($workflowSteps))->first(
            fn (array $progress): bool => (int) $progress['step']->id === (int) $currentStep->id
        );

        if ($node === null) {
            return ['state' => 'upcoming', 'label' => 'ยังไม่ถึงขั้น'];
        }

        $state = (string) $node['state'];
        if ($state === 'completed') {
            return ['state' => 'completed', 'label' => 'ดำเนินการแล้ว'];
        }

        if ($state === 'rejected') {
            return ['state' => 'rejected', 'label' => 'ปฏิเสธ'];
        }

        if ($state === 'upcoming') {
            return ['state' => 'upcoming', 'label' => 'ยังไม่ถึงขั้น'];
        }

        if ($currentStep->duty === 'create' && $this->status === 'draft') {
            return ['state' => 'draft', 'label' => 'ใบร่าง'];
        }

        return [
            'state' => 'waiting',
            'label' => match ($currentStep->duty) {
                'select' => 'รอคัดเลือกรายการ',
                'negotiate' => 'รอต่อรองราคา',
                'confirm' => 'รอยืนยันรายการ',
                'sign' => 'รอลงนามอนุมัติ',
                default => 'รอการดำเนินการ',
            },
        ];
    }

    /**
     * ชื่อแผนกของผู้ขอซื้อที่จะโชว์บนหน้าจอ
     *
     * ยึดค่าที่มิเรอร์มาจาก Insight เป็นหลัก แล้วค่อยตกไปใช้ค่าที่ประทับไว้ตอนสร้างเอกสาร
     * เพราะ `requester_department` เป็น snapshot — ถ้า Insight แก้ชื่อแผนกทีหลัง
     * ค่าเก่าจะค้างและกลายเป็นชื่อที่ไม่มีอยู่จริงในระบบต้นทาง
     */
    public function requesterDepartmentLabel(): ?string
    {
        $live = trim((string) $this->requester?->department);

        return $live !== '' ? $live : (trim((string) $this->requester_department) ?: null);
    }

    /** ป้ายสกุลเงินที่ต่อท้าย Total Amount เช่น `(Baht)` */
    public function currencyLabel(): string
    {
        return self::CURRENCIES[$this->currency] ?? self::CURRENCIES['THB'];
    }

    /** เลขอ้างอิงบนหน้าจอ */
    public function referenceLabel(): string
    {
        return trim((string) $this->pr_number) !== ''
            ? (string) $this->pr_number
            : ($this->getKey() ? 'ใบร่าง #'.$this->getKey() : 'ใบร่าง');
    }

    /** ก่อนผู้ขอซื้อลงนาม เลข PR เป็นเลขที่จองไว้และต้องแสดงป้ายใบร่าง */
    public function isDraftPhase(): bool
    {
        if (! in_array($this->status, ['draft', 'sent_user'], true)) {
            return false;
        }

        $signatures = $this->relationLoaded('signatures') ? $this->signatures : $this->signatures()->get();

        return $signatures
            ->whereNotNull('signed_at')
            ->where('duty', 'select')
            ->isEmpty();
    }

    public function displayLabel(): string
    {
        return $this->referenceLabel().($this->isDraftPhase() ? ' #ใบร่าง' : '');
    }

    /**
     * Purchase ลบได้จนกว่าผู้ขอซื้อจะลงนามคัดเลือกรายการ
     * ลายเซ็นขั้นจัดทำเอกสารไม่เริ่ม Process และไม่ล็อกการลบ
     */
    public function canDelete(): bool
    {
        if (! $this->isDraftPhase()) {
            return false;
        }

        $signatures = $this->relationLoaded('signatures') ? $this->signatures : $this->signatures()->get();

        return $signatures
            ->whereNotNull('signed_at')
            ->where('duty', '!=', 'create')
            ->isEmpty();
    }

    public function isRejected(): bool
    {
        return str_starts_with((string) $this->status, 'rejected');
    }

    /**
     * สถานะ "ปฏิเสธ" ของใบที่เดินมาถึงขั้นนี้แล้ว — ใช้กรองรายการของแต่ละแท็บ
     *
     * ใบที่ถูกปฏิเสธต้องยังอยู่ในรายการของขั้นที่มันเคยผ่าน ไม่ใช่หายไปเฉยๆ
     * แต่ไม่ต้องไปโผล่ในขั้นที่ยังไม่เคยเดินไปถึง
     *
     * @param  int  $stepPosition  ลำดับของขั้นนั้นในเส้นทาง (เริ่มที่ 1)
     * @return array<int, string>
     */
    public static function rejectedStatusesFrom(int $stepPosition, int $totalSteps): array
    {
        $statuses = [];

        for ($position = max(1, $stepPosition); $position <= $totalSteps; $position++) {
            $statuses[] = 'rejected_'.$position;
        }

        // ชื่อแบบเก่าที่เคยเขียนลง DB ก่อนเปลี่ยนมาใช้เลขลำดับ
        // ลำดับยึดเส้นทาง 6 ขั้นปัจจุบัน (ลงนามอนุมัติเลื่อนจาก 4-5 เป็น 5-6 หลังเพิ่มขั้นยืนยันรายการ)
        $legacy = [
            'rejected' => 1,
            'rejected_user' => 2,
            'rejected_select' => 2,
            'rejected_negotiate' => 3,
            'rejected_confirm' => 4,
            'rejected_mgr' => 5,
            'rejected_ceo' => 6,
        ];

        foreach ($legacy as $name => $position) {
            if ($position >= $stepPosition) {
                $statuses[] = $name;
            }
        }

        return $statuses;
    }

    /**
     * เอกสารที่คนคนนี้มีสิทธิ์เห็น
     *
     * admin เห็นทุกใบ · คนทั่วไปเห็นใบที่ตัวเองสร้าง หรือเป็นผู้ขอซื้อ
     * ส่วนคนที่ถูกใส่ชื่อไว้ในเส้นทางเอกสาร เห็นทุกใบที่ผ่านมือขั้นของตัวเอง
     *
     * @param  Builder<PrDocument>  $query
     * @param  Collection<int,DocumentRole>  $workflowSteps
     * @return Builder<PrDocument>
     */
    public function scopeVisibleTo(Builder $query, AppUser $me, Collection $workflowSteps): Builder
    {
        if ($me->isAdmin()) {
            return $query;
        }

        $inRoute = $workflowSteps->contains(
            fn (DocumentRole $step): bool => $step->members->contains('id_thai_hash', $me->id_thai_hash)
        );

        if ($inRoute) {
            return $query;
        }

        return $query->where(function (Builder $q) use ($me): void {
            $q->where('created_by_id_thai_hash', $me->id_thai_hash)
                ->orWhere('requester_id_thai_hash', $me->id_thai_hash);
        });
    }

    /** ระบบเป็นคนปฏิเสธเอง ไม่ใช่คนกดปฏิเสธ */
    public function isAutoRejected(): bool
    {
        return $this->isRejected() && $this->rejected_by_name === ResignationGuard::REJECTED_BY;
    }

    /**
     * ข้อความสั้นๆ สีแดงในหน้ารายการ บอกว่าทำไมใบนี้ถึงจบ
     *
     * ใบที่ถูกปฏิเสธเปิดแก้ไม่ได้แล้ว ฝ่ายจัดซื้อต้องขึ้นใบใหม่เอง
     * จึงไม่มีปุ่มอะไรให้กด เหลือแต่เหตุผลให้อ่าน
     */
    public function rejectionNote(?Collection $workflowSteps = null): ?string
    {
        if (! $this->isRejected()) {
            return null;
        }

        if (! $this->isAutoRejected()) {
            return 'ถูกปฏิเสธ';
        }

        $steps = $workflowSteps ?? DocumentRole::chain();
        $index = preg_match('/^rejected_(\d+)$/', (string) $this->status, $m)
            ? (int) $m[1] - 1
            : null;
        $step = $index === null ? null : $steps->values()->get($index);

        return match ($step?->duty) {
            'create' => 'ผู้จัดทำเอกสารลาออก',
            'select', 'confirm' => 'ผู้ขอซื้อลาออก',
            'negotiate' => 'ผู้ต่อรองราคาลาออก',
            'sign' => 'ผู้ลงนามอนุมัติลาออก',
            default => 'ผู้รับผิดชอบลาออก',
        };
    }

    /**
     * สถานะรายขั้นสำหรับแผนผังเส้นทางในหน้ารายการเอกสาร
     *
     * สีของขั้นคำนวณจากสถานะรวมของใบ แล้วใช้ลายเซ็นเป็นหลักฐานเพิ่มว่า
     * ขั้นนั้นเสร็จจริง แม้สถานะรวมยังไม่ได้เลื่อนไปขั้นถัดไป
     *
     * @param  Collection<int,DocumentRole>  $workflowSteps
     * @return array<int,array{step:DocumentRole,state:string,state_label:string,signature:?PrDocumentSignature}>
     */
    public function routeProgress(Collection $workflowSteps): array
    {
        $steps = $workflowSteps->values();

        if ($steps->isEmpty()) {
            return [];
        }

        $signatures = $this->relationLoaded('signatures')
            ? $this->signatures
            : $this->signatures()->get();
        $signedStepIds = $signatures->pluck('document_role_id')->map('intval')->all();
        $isApproved = $this->status === 'approved';
        $isRejected = str_starts_with((string) $this->status, 'rejected');

        /*
        | ขั้นที่เอกสารค้างอยู่ = จำนวนขั้นที่เอกสาร "เดินผ่านมาแล้ว" ตามเส้นทางจริง
        |
        | ⚠️ ห้ามไล่เลขลำดับตายตัว (sent_user=1, negotiating=2, …) — พอ admin แทรกขั้นใหม่
        |    เข้ามากลางเส้นทาง เลขจะเลื่อนทั้งแถวแล้วทุกหน้าจะระบายสีผิดขั้น (D-075)
        |    นับจากลำดับของสถานะแทน จึงย้ายขั้นหรือตัดขั้นออกได้โดยไม่พัง
        */
        $currentIndex = $this->status === 'approved'
            ? $steps->count()
            : $steps->filter(fn (DocumentRole $step): bool => self::statusRank($step->signatureStatus()) !== null
                && self::statusRank($step->signatureStatus()) < (int) self::statusRank($this->status))->count();

        // `rejected_{ลำดับขั้น}` เป็นรูปแบบที่ระบบเขียนจริง ส่วนชื่อขั้นเก็บไว้เผื่อข้อมูลเก่า
        $rejectedIndex = preg_match('/^rejected_(\d+)$/', (string) $this->status, $m)
            ? max(0, (int) $m[1] - 1)
            : $this->legacyRejectedIndex($steps);

        /*
        | ขั้นจะ "ดำเนินการแล้ว" ก็ต่อเมื่อเอกสารเดินพ้นขั้นนั้นไปแล้วเท่านั้น
        |
        | ⚠️ ห้ามใช้ "มีลายเซ็น = เสร็จ" — ลายเซ็นเป็นแค่การประทับ ยังกดปุ่มส่งสีเขียวไม่ได้ส่ง
        |    เคยทำแบบนั้นแล้วประทับปุ๊บขึ้นดำเนินการแล้วทั้งที่ยังไม่ได้กด "ส่งให้ผู้ขอซื้อ" (D-051)
        */
        $completed = [];
        foreach ($steps as $index => $step) {
            $completed[$index] = match (true) {
                $isApproved => true,
                // ปฏิเสธที่ขั้นไหน ขั้นก่อนหน้าก็ทำไปจริงแล้ว ไม่ใช่ "ยังไม่ถึงขั้น"
                $isRejected && $rejectedIndex !== null => $index < $rejectedIndex,
                default => $index < $currentIndex,
            };
        }

        if ($isRejected && $rejectedIndex === null) {
            $rejectedIndex = array_search(false, $completed, true);
            $rejectedIndex = $rejectedIndex === false ? $steps->count() - 1 : $rejectedIndex;
        }

        $waitingIndex = null;
        if (! $isApproved && ! $isRejected) {
            $waitingIndex = array_search(false, $completed, true);
            $waitingIndex = $waitingIndex === false ? null : $waitingIndex;
        }

        // เตือนเรื่องคนลาออกแค่ขั้นเดียว — ขั้นแรกที่เอกสารจะไปสะดุด (D-079)
        $blockedMarked = false;

        return $steps->map(function (DocumentRole $step, int $index) use (
            $completed,
            $isRejected,
            $rejectedIndex,
            $waitingIndex,
            $signatures,
            &$blockedMarked,
        ): array {
            $state = match (true) {
                $isRejected && $index === $rejectedIndex => 'rejected',
                $completed[$index] => 'completed',
                $index === $waitingIndex => 'waiting',
                default => 'upcoming',
            };

            $people = $this->stepPeople($step);

            /*
            | ขั้นนี้ "เดินต่อไม่ได้" เมื่อคนที่ต้องทำลาออกหมดแล้ว
            |
            | นับเฉพาะขั้นที่ยังไม่ผ่าน — เซ็นไปแล้วค่อยลาออกทีหลังไม่กระทบเอกสาร
            | ขั้นที่ยังไม่ได้กำหนดคน (people ว่าง) ไม่ถือว่าติดล็อกเพราะเป็นเรื่องตั้งค่าเส้นทาง
            |
            | ⚠️ ทำเครื่องหมาย **ขั้นเดียว** คือขั้นแรกที่เอกสารจะไปสะดุด (D-079)
            |    ผู้ขอซื้อคนเดียวกันอยู่ทั้งขั้นคัดเลือกและขั้นยืนยันรายการ ถ้าไม่จำกัดไว้
            |    ลาออกทีเดียวจะขึ้นวงแดง 2 ขั้น ทั้งที่เอกสารตายตั้งแต่ขั้นแรกแล้ว
            |    ใบที่ถูกปฏิเสธไปแล้วก็ไม่ต้องเตือนขั้นถัดไปอีก เพราะจบไปแล้ว
            */
            $stuck = $people !== []
                && in_array($state, ['waiting', 'upcoming'], true)
                && ! in_array(false, array_column($people, 'resigned'), true);
            $blocked = $stuck && ! $isRejected && ! $blockedMarked;

            if ($blocked) {
                $blockedMarked = true;
            }

            return [
                'step' => $step,
                'state' => $state,
                'state_label' => match ($state) {
                    'completed' => 'ดำเนินการแล้ว',
                    'waiting' => 'รอการดำเนินการ',
                    'rejected' => 'ปฏิเสธ',
                    default => 'ยังไม่ถึงขั้น',
                },
                'signature' => $signatures->firstWhere('document_role_id', $step->id),
                'people' => $people,
                'blocked' => $blocked,
            ];
        })->all();
    }

    /**
     * ลำดับขั้นของสถานะปฏิเสธชื่อเก่า — หาจาก `duty` ของเส้นทางจริง ไม่ใช่เลขตายตัว
     *
     * @param  Collection<int,DocumentRole>  $steps
     */
    private function legacyRejectedIndex(Collection $steps): ?int
    {
        $duty = match ($this->status) {
            'rejected_user', 'rejected_select' => 'select',
            'rejected_negotiate' => 'negotiate',
            'rejected_confirm' => 'confirm',
            'rejected_mgr', 'rejected_ceo' => 'sign',
            default => null,
        };

        if ($duty === null) {
            return null;
        }

        $role = match ($this->status) {
            'rejected_mgr' => 'mgr_purchasing',
            'rejected_ceo' => 'ceo',
            default => null,
        };

        $index = $steps->search(fn (DocumentRole $step): bool => $step->duty === $duty
            && ($role === null || $step->role === $role));

        return $index === false ? null : $index;
    }

    /**
     * ผู้ขอซื้อยืนยันรายการแล้วหรือยัง — ใช้ตัดสินว่าจะเทาผู้ขายที่ตกรอบไหม (D-075)
     *
     * ก่อนยืนยัน ทุกแท็บต้องเห็นครบทั้ง 3 เจ้าเพราะยังเทียบราคากันอยู่
     * ยืนยันแล้วจึงเทาเจ้าที่ไม่ได้เลือก ให้ผู้ลงนามและเอกสาร PDF อ่านง่าย
     */
    public function selectionLocked(): bool
    {
        if (in_array($this->status, ['waiting_mgr', 'waiting_ceo', 'approved'], true)) {
            return true;
        }

        $signatures = $this->relationLoaded('signatures') ? $this->signatures : $this->signatures()->get();

        return $signatures
            ->whereNotNull('signed_at')
            ->where('duty', 'confirm')
            ->isNotEmpty();
    }

    /**
     * คนที่รับผิดชอบขั้นหนึ่ง พร้อมสถานะลาออก
     *
     * ขั้นของผู้ขอซื้อยึดคนที่ถูกเลือกไว้ในใบนี้ ไม่ใช่สมาชิกของ Role
     * (Role `user` ไม่ได้กำหนดสมาชิกไว้ — ผู้ขอซื้อเปลี่ยนไปตามแต่ละใบ)
     *
     * @return array<int, array{name: string, resigned: bool, resigned_on: ?string}>
     */
    private function stepPeople(DocumentRole $step): array
    {
        if ($step->isUserStep()) {
            $hash = trim((string) $this->requester_id_thai_hash);

            if ($hash === '') {
                return [];
            }

            return [[
                'name' => $this->requester_name
                    ?: (ResignationGuard::employeeOf($hash)?->fullNameTh() ?: 'ผู้ขอซื้อ'),
                'resigned' => ResignationGuard::isResigned($hash),
                'resigned_on' => ResignationGuard::resignedOn($hash),
            ]];
        }

        return $step->members->map(fn (DocumentRoleMember $member): array => [
            'name' => $member->displayName(),
            'resigned' => $member->hasResigned(),
            'resigned_on' => ResignationGuard::resignedOn($member->id_thai_hash),
        ])->values()->all();
    }

    /** ผู้ขายเฉพาะช่องที่ใบนี้เปิดใช้ (ที่เหลือเบลอในเอกสาร) */
    public function activeSuppliers()
    {
        return $this->suppliers->where('slot', '<=', $this->supplier_count);
    }

    /** ยอดรวมของผู้ขายรายหนึ่ง = ผลรวมของ (Qty × Unit Price) ทุกแถว */
    public function totalOf(PrSupplier $supplier): float
    {
        $sum = 0.0;

        foreach ($this->items as $item) {
            $sum += $item->amountFor($supplier);
        }

        return $sum;
    }

    /** สร้างช่องผู้ขายให้ครบ 3 ช่องเสมอ — ช่องที่ยังไม่ใช้ก็มีแถวรออยู่ */
    public function ensureSuppliers(): void
    {
        $have = $this->suppliers()->pluck('slot')->all();

        for ($slot = 1; $slot <= self::MAX_SUPPLIERS; $slot++) {
            if (! in_array($slot, $have, true)) {
                $this->suppliers()->create(['slot' => $slot]);
            }
        }

        $this->load('suppliers');
    }
}
