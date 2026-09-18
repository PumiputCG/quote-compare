<?php

namespace Tests\Unit;

use App\Models\DocumentRole;
use App\Models\PrDocument;
use App\Models\PrDocumentSignature;
use Illuminate\Database\Eloquent\Collection;
use Tests\TestCase;

class PrDocumentRouteProgressTest extends TestCase
{
    public function test_draft_starts_with_create_waiting_and_future_steps_upcoming(): void
    {
        $document = $this->document('draft');

        $this->assertSame(
            ['waiting', 'upcoming', 'upcoming', 'upcoming', 'upcoming'],
            array_column($document->routeProgress($this->workflow()), 'state')
        );
    }

    /** ประทับลายเซ็นแล้วยังไม่นับว่าเสร็จ ต้องกด "ส่งให้ผู้ขอซื้อ" ก่อน (D-051) */
    public function test_signature_alone_does_not_complete_a_step(): void
    {
        $document = $this->document('draft', [1]);

        $this->assertSame(
            ['waiting', 'upcoming', 'upcoming', 'upcoming', 'upcoming'],
            array_column($document->routeProgress($this->workflow()), 'state')
        );
    }

    public function test_waiting_manager_completes_every_previous_step(): void
    {
        $document = $this->document('waiting_mgr');

        $this->assertSame(
            ['completed', 'completed', 'completed', 'waiting', 'upcoming'],
            array_column($document->routeProgress($this->workflow()), 'state')
        );
    }

    public function test_rejected_document_marks_first_incomplete_step_red(): void
    {
        $document = $this->document('rejected', [1]);

        $this->assertSame(
            ['rejected', 'upcoming', 'upcoming', 'upcoming', 'upcoming'],
            array_column($document->routeProgress($this->workflow()), 'state')
        );
    }

    public function test_each_work_tab_reports_its_own_step_status(): void
    {
        $workflow = $this->workflow();

        $this->assertSame(
            ['state' => 'draft', 'label' => 'ใบร่าง'],
            $this->document('draft')->workStatusForStep($workflow[0], $workflow)
        );
        $this->assertSame(
            ['state' => 'completed', 'label' => 'ดำเนินการแล้ว'],
            $this->document('sent_user')->workStatusForStep($workflow[0], $workflow)
        );
        $this->assertSame(
            ['state' => 'waiting', 'label' => 'รอคัดเลือกรายการ'],
            $this->document('sent_user')->workStatusForStep($workflow[1], $workflow)
        );
        $this->assertSame(
            ['state' => 'waiting', 'label' => 'รอคัดเลือกรายการ'],
            $this->document('sent_user', [2])->workStatusForStep($workflow[1], $workflow)
        );
        $this->assertSame(
            ['state' => 'waiting', 'label' => 'รอต่อรองราคา'],
            $this->document('negotiating')->workStatusForStep($workflow[2], $workflow)
        );
        $this->assertSame(
            ['state' => 'completed', 'label' => 'ดำเนินการแล้ว'],
            $this->document('waiting_mgr')->workStatusForStep($workflow[2], $workflow)
        );
        $this->assertSame(
            ['state' => 'waiting', 'label' => 'รอลงนามอนุมัติ'],
            $this->document('waiting_mgr')->workStatusForStep($workflow[3], $workflow)
        );
        $this->assertSame(
            ['state' => 'completed', 'label' => 'ดำเนินการแล้ว'],
            $this->document('waiting_ceo')->workStatusForStep($workflow[3], $workflow)
        );
        $this->assertSame(
            ['state' => 'waiting', 'label' => 'รอลงนามอนุมัติ'],
            $this->document('waiting_ceo')->workStatusForStep($workflow[4], $workflow)
        );
        $this->assertSame(
            ['state' => 'completed', 'label' => 'ดำเนินการแล้ว'],
            $this->document('approved')->workStatusForStep($workflow[4], $workflow)
        );
    }

    /** เส้นทาง 6 ขั้น — ขั้นยืนยันรายการของผู้ขอซื้อแทรกอยู่ก่อนขั้นลงนาม (D-075) */
    public function test_confirmation_step_takes_its_place_in_a_six_step_route(): void
    {
        $workflow = $this->workflowWithConfirmation();

        $this->assertSame(
            ['completed', 'completed', 'completed', 'waiting', 'upcoming', 'upcoming'],
            array_column($this->document('confirming')->routeProgress($workflow), 'state')
        );
        $this->assertSame(
            ['completed', 'completed', 'completed', 'completed', 'waiting', 'upcoming'],
            array_column($this->document('waiting_mgr')->routeProgress($workflow), 'state')
        );
        $this->assertSame(
            ['completed', 'completed', 'completed', 'completed', 'completed', 'waiting'],
            array_column($this->document('waiting_ceo')->routeProgress($workflow), 'state')
        );

        // ปฏิเสธตอนยืนยันรายการ = ขั้นที่ 4 แดง ขั้นลงนามยังไม่ถึง
        $this->assertSame(
            ['completed', 'completed', 'completed', 'rejected', 'upcoming', 'upcoming'],
            array_column($this->document('rejected_4')->routeProgress($workflow), 'state')
        );
        // ปฏิเสธตอนผู้จัดการลงนาม = ขั้นยืนยันรายการผ่านไปแล้ว
        $this->assertSame(
            ['completed', 'completed', 'completed', 'completed', 'rejected', 'upcoming'],
            array_column($this->document('rejected_5')->routeProgress($workflow), 'state')
        );

        $this->assertSame(
            ['state' => 'waiting', 'label' => 'รอยืนยันรายการ'],
            $this->document('confirming')->workStatusForStep($workflow[3], $workflow)
        );
        $this->assertSame(
            ['state' => 'completed', 'label' => 'ดำเนินการแล้ว'],
            $this->document('waiting_mgr')->workStatusForStep($workflow[3], $workflow)
        );
    }

    /** @param array<int,int> $signedStepIds */
    private function document(string $status, array $signedStepIds = []): PrDocument
    {
        $document = new PrDocument(['status' => $status]);
        $signatures = new Collection;

        foreach ($signedStepIds as $stepId) {
            $signatures->push(new PrDocumentSignature([
                'document_role_id' => $stepId,
                'signer_name' => 'ผู้ลงนาม',
            ]));
        }

        $document->setRelation('signatures', $signatures);

        return $document;
    }

    /** เส้นทางเดิม 5 ขั้น — ยังต้องเดินได้ปกติถ้า admin ไม่ได้ใส่ขั้นยืนยันรายการ */
    private function workflow(): Collection
    {
        return $this->chain([
            [1, 'purchasing', 'create'],
            [2, 'user', 'select'],
            [3, 'purchasing', 'negotiate'],
            [4, 'mgr_purchasing', 'sign'],
            [5, 'ceo', 'sign'],
        ]);
    }

    /** @return Collection<int,DocumentRole> */
    private function workflowWithConfirmation(): Collection
    {
        return $this->chain([
            [1, 'purchasing', 'create'],
            [2, 'user', 'select'],
            [3, 'purchasing', 'negotiate'],
            [4, 'user', 'confirm'],
            [5, 'mgr_purchasing', 'sign'],
            [6, 'ceo', 'sign'],
        ]);
    }

    /**
     * @param  array<int,array{0:int,1:string,2:string}>  $definitions
     * @return Collection<int,DocumentRole>
     */
    private function chain(array $definitions): Collection
    {
        return new Collection(array_map(function (array $definition): DocumentRole {
            [$id, $role, $duty] = $definition;
            $step = new DocumentRole(['step_no' => $id, 'role' => $role, 'duty' => $duty]);
            $step->id = $id;
            // เทสต์นี้ไม่แตะฐานข้อมูล — ใส่รายชื่อว่างไว้กัน routeProgress ไปดึงสมาชิกจริง
            $step->setRelation('members', new Collection);

            return $step;
        }, $definitions));
    }
}
