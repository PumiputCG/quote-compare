@extends('layouts.portal')

@section('title', 'จัดทำเอกสาร · QuoteCompare')
@section('heading', 'จัดทำเอกสาร')

@section('page-actions')
  <form method="POST" action="{{ route('documents.store') }}">
    @csrf
    <button type="submit" class="btn">
      <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round">
        <path d="M12 5v14M5 12h14"/>
      </svg>
      สร้างเอกสารใหม่
    </button>
  </form>
@endsection

@section('styles')
  .panel {
    background: var(--surface); border: 1px solid var(--line);
    border-radius: 8px; box-shadow: var(--shadow-sm); overflow: hidden;
  }
  .table-wrap { width: 100%; overflow-x: auto; }
  table { width: 100%; min-width: 1030px; border-collapse: collapse; font-size: 14px; }
  /* ทุกคอลัมน์จัดกึ่งกลาง หัวตารางจึงตรงกับข้อมูลใต้มัน (D-074) */
  thead th {
    padding: 11px 14px; text-align: center; white-space: nowrap;
    font-size: 12.5px; font-weight: 600; color: var(--muted);
    background: var(--surface-soft); border-bottom: 1px solid var(--line);
  }
  tbody td { padding: 13px 14px; vertical-align: middle; text-align: center; border-bottom: 1px solid var(--line-soft); }
  tbody tr:last-child td { border-bottom: 0; }
  tbody tr { transition: background .14s var(--ease); }
  tbody tr:hover { background: #f9fbfb; }

  .row-no {
    color: var(--muted); font-size: 13px; font-weight: 600;
    font-variant-numeric: tabular-nums; text-align: center;
  }
  .pr-no { font-weight: 700; font-variant-numeric: tabular-nums; white-space: nowrap; }
  .item-code-show {
    min-height: 32px; padding: 0; color: var(--teal-deep); background: transparent;
    border: 0; font-size: 13px; font-weight: 600; cursor: pointer;
  }
  .item-code-show:hover { color: var(--teal-ink); text-decoration: underline; }
  .department { display: block; max-width: 210px; margin-inline: auto; color: var(--ink-soft); overflow-wrap: anywhere; }
  .muted-value { color: var(--muted); }
  .route-show {
    min-height: 32px; padding: 0 12px; color: var(--teal-deep); background: var(--surface);
    border: 1px solid #bcdedb; border-radius: 7px; font-size: 13px; font-weight: 600; cursor: pointer;
    transition: background .16s var(--ease), border-color .16s var(--ease);
  }
  .route-show:hover { background: var(--teal-soft); border-color: var(--teal); }
  .go {
    display: inline-flex; align-items: center; gap: 3px; min-height: 32px;
    color: var(--teal-ink); font-weight: 600; white-space: nowrap;
  }
  .go svg { width: 15px; height: 15px; fill: none; stroke: currentColor; stroke-width: 2.2; stroke-linecap: round; }

  /* ── แบ่งหน้า ─────────────────────────────────────────── */
  .pager {
    display: flex; align-items: center; justify-content: space-between; gap: 14px;
    flex-wrap: wrap; padding: 12px 14px;
    background: var(--surface-soft); border-top: 1px solid var(--line);
  }
  .pager-count { font-size: 12.5px; color: var(--muted); font-variant-numeric: tabular-nums; }
  .pager-links { display: flex; align-items: center; gap: 4px; }
  .pager-step, .pager-page {
    display: grid; place-items: center; min-width: 30px; height: 30px; padding: 0 8px;
    border: 1px solid var(--line); border-radius: 7px; background: var(--surface);
    font-size: 13px; font-weight: 600; color: var(--ink-soft);
    font-variant-numeric: tabular-nums;
    transition: background .14s var(--ease), border-color .14s var(--ease), color .14s var(--ease);
  }
  .pager-step:hover, .pager-page:hover { background: var(--teal-soft); border-color: var(--teal); color: var(--teal-deep); }
  .pager-page.is-current {
    background: var(--teal); border-color: var(--teal); color: #fff; cursor: default;
  }
  .pager-page.is-current:hover { background: var(--teal); border-color: var(--teal); color: #fff; }
  .pager-step.is-off { opacity: .38; pointer-events: none; }
  .pager-step svg { width: 15px; height: 15px; fill: none; stroke: currentColor; stroke-width: 2.3; stroke-linecap: round; }
  .pager-gap { padding: 0 2px; font-size: 13px; color: var(--muted); }

  .empty { padding: 56px 24px; text-align: center; }
  .empty p { margin: 0 0 4px; font-size: 15px; font-weight: 600; }
  .empty span { font-size: 13.5px; color: var(--muted); }

  /* ── Modal เส้นทางเอกสาร ─────────────────────────────── */
  .route-dialog {
    width: min(92vw, 880px); max-height: min(86vh, 680px); padding: 0;
    color: var(--ink); background: var(--surface); border: 0; border-radius: 8px;
    box-shadow: 0 28px 72px -26px rgba(17, 24, 39, .55);
  }
  .route-dialog[open] { display: flex; flex-direction: column; }
  .route-dialog::backdrop { background: rgba(17, 24, 39, .48); }
  .item-code-dialog {
    width: min(92vw, 430px); max-height: min(82vh, 560px); padding: 0;
    color: var(--ink); background: var(--surface); border: 0; border-radius: 8px;
    box-shadow: 0 28px 72px -26px rgba(17, 24, 39, .55);
  }
  .item-code-dialog[open] { display: flex; flex-direction: column; }
  .item-code-dialog::backdrop { background: rgba(17, 24, 39, .48); }
  .item-code-list { display: grid; margin: 0; padding: 4px 18px 18px; list-style: none; }
  .item-code-list li {
    display: grid; grid-template-columns: 28px minmax(0, 1fr); gap: 8px;
    padding: 11px 0; border-bottom: 1px solid var(--line-soft);
  }
  .item-code-list li:last-child { border-bottom: 0; }
  .item-code-number { color: var(--muted); font-size: 13px; font-weight: 700; font-variant-numeric: tabular-nums; }
  .item-code-list code { color: var(--ink); background: transparent; font-family: var(--font); font-size: 14px; font-weight: 700; overflow-wrap: anywhere; }
  .route-dialog-header {
    display: flex; align-items: center; gap: 14px; padding: 16px 18px;
    border-bottom: 1px solid var(--line);
  }
  .route-dialog-title { min-width: 0; flex: 1; }
  .route-dialog-title h2 { margin: 0; font-size: 17px; line-height: 1.35; }
  .route-dialog-title p { margin: 2px 0 0; font-size: 13px; color: var(--muted); }
  .dialog-close {
    display: grid; place-items: center; width: 34px; height: 34px; flex: none; padding: 0;
    color: var(--muted); background: transparent; border: 0; border-radius: 7px; cursor: pointer;
  }
  .dialog-close:hover { color: var(--ink); background: var(--surface-soft); }
  .dialog-close svg { width: 18px; height: 18px; fill: none; stroke: currentColor; stroke-width: 2.2; }
  .route-dialog-body { overflow-y: auto; padding: 22px 20px 18px; }

  .route-map {
    display: flex; min-width: 720px; margin: 0; padding: 2px 0 10px;
    list-style: none; overflow: visible;
  }
  .route-map li {
    --step-bg: #dce2e3; --step-fg: #53606a; --step-line: #d3dbdc;
    position: relative; display: flex; flex-direction: column;
    flex: 1 1 0; min-width: 132px; padding: 0 7px; text-align: center;
  }
  .route-map li::before {
    content: ''; position: absolute; top: 18px; left: -50%; right: 50%; height: 2px;
    background: var(--step-line);
  }
  .route-map li:first-child::before { display: none; }
  .route-map .completed { --step-bg: var(--ok); --step-fg: #fff; --step-line: #76bd98; }
  .route-map .waiting { --step-bg: #e1ad47; --step-fg: #422c06; --step-line: #e4c27e; }
  .route-map .rejected { --step-bg: var(--danger); --step-fg: #fff; --step-line: #dd8d87; }
  .route-dot {
    position: relative; z-index: 1; display: grid; place-items: center;
    width: 38px; height: 38px; margin: 0 auto 10px; border-radius: 50%;
    color: var(--step-fg); background: var(--step-bg); font-size: 13px; font-weight: 700;
    box-shadow: 0 0 0 5px var(--surface);
  }
  /* ไม่ล็อกความสูง — Role สั้นอย่าง "CEO" จะได้ไม่มีบรรทัดว่างคั่นก่อนการดำเนินการ
     (ความสูงที่เท่ากันทุกใบมาจาก margin-top:auto ของ .route-state แทน) */
  .route-role { display: block; font-size: 12.5px; font-weight: 700; line-height: 1.3; }
  .route-duty { display: block; margin-top: 2px; margin-bottom: 10px; font-size: 12px; color: var(--ink-soft); }
  /* margin-top:auto ดันสถานะลงล่าง — ช่องว่างจึงอยู่ระหว่าง "การดำเนินการ" กับ "สถานะ"
     ไม่ใช่ค้างอยู่ใต้ชื่อ Role และทำให้สถานะของทุกขั้นอยู่ระดับเดียวกันแม้ชื่อ Role ยาวไม่เท่ากัน */
  .route-state {
    display: inline-block; align-self: center; margin-top: auto; padding: 2px 8px;
    font-size: 11.5px; font-weight: 600;
    color: var(--step-fg); background: var(--step-bg); border-radius: 999px;
  }
  .route-map .upcoming .route-state { color: var(--muted); }

  /* กล่องท้ายขั้น — จองความสูงไว้เท่ากันทุกใบ (ชื่อ 2 บรรทัด + วันที่ 1 บรรทัด)
     ป้ายสถานะจึงอยู่ระดับเดียวกันหมด ไม่ว่าขั้นนั้นจะมีวันที่หรือไม่ */
  .route-foot { display: block; min-height: 47px; margin-top: 7px; }

  /* ชื่อผู้รับผิดชอบ — ค้างไว้ทุกขั้น */
  .route-person {
    display: block; font-size: 11.5px; line-height: 1.35;
    color: var(--muted); overflow-wrap: anywhere;
  }
  /* เหตุผลที่ใบนี้จบ — แทนที่ปุ่มในคอลัมน์สุดท้ายของใบที่ถูกปฏิเสธ */
  .reject-note { display: inline-block; color: var(--danger); font-size: 12.5px; font-weight: 700; line-height: 1.35; }
  /* หน้าตาเดียวกับปุ่ม "เปิด" แต่เป็นสีแดง เพราะเป็นทางเดียวที่ยังกดได้ของใบที่ถูกปฏิเสธ */
  .reject-why {
    display: inline-flex; align-items: center; gap: 3px; min-height: 32px; padding: 0;
    border: 0; background: transparent; color: var(--danger);
    font: inherit; font-size: 14px; font-weight: 600; white-space: nowrap; cursor: pointer;
  }
  .reject-why svg { width: 15px; height: 15px; fill: none; stroke: currentColor; stroke-width: 2.2; stroke-linecap: round; }
  .reject-why:hover { text-decoration: underline; }
  .route-dialog.is-reason { width: min(94vw, 440px); }
  .reason-text { margin: 0; color: var(--ink); font-size: 14px; line-height: 1.65; white-space: pre-line; overflow-wrap: anywhere; }
  .reason-by { margin: 12px 0 0; padding-top: 10px; border-top: 1px solid var(--line-soft); color: var(--muted); font-size: 12.5px; }

  /* คนในขั้นนี้ลาออกแล้ว — เอกสารเดินต่อไม่ได้ ต้องเห็นชัดตั้งแต่แวบแรก */
  .route-resigned { display: block; margin-top: 1px; color: var(--danger); font-size: 11px; font-weight: 700; line-height: 1.35; }
  .route-map .blocked .route-person { color: var(--danger); }

  /* วันที่ — ต่อท้ายใต้ชื่อ เฉพาะขั้นที่ลงนามแล้ว (ว่างไว้เพื่อกันที่) */
  .route-signed {
    display: block; margin-top: 2px; min-height: 15px; font-size: 11px; line-height: 1.35;
    color: var(--muted); font-variant-numeric: tabular-nums;
  }

  .route-scroll { overflow-x: auto; padding: 2px 2px 8px; }
  .route-legend {
    display: flex; flex-wrap: wrap; gap: 7px 16px; margin-top: 14px; padding-top: 14px;
    border-top: 1px solid var(--line-soft); font-size: 12px; color: var(--muted);
  }
  .route-legend span { display: inline-flex; align-items: center; gap: 6px; }
  .legend-dot { width: 9px; height: 9px; border-radius: 50%; background: #dce2e3; }
  .legend-dot.completed { background: var(--ok); }
  .legend-dot.waiting { background: #e1ad47; }
  .legend-dot.rejected { background: var(--danger); }

  @media (max-width: 700px) {
    .route-dialog { width: min(94vw, 520px); }
    .route-dialog-body { padding: 18px 16px 16px; }
    .route-scroll { overflow: visible; }
    .route-map { display: block; min-width: 0; padding-left: 4px; }
    .route-map li {
      min-width: 0; display: grid; grid-template-columns: 38px minmax(0, 1fr);
      column-gap: 12px; padding: 0 0 18px; text-align: left;
    }
    .route-map li::before { top: -18px; left: 18px; right: auto; width: 2px; height: 36px; }
    /* 4 แถว: Role · การดำเนินการ · สถานะ · กล่องชื่อ+วันที่ */
    .route-dot { grid-row: 1 / span 4; margin: 0; box-shadow: 0 0 0 4px var(--surface); }
    .route-duty, .route-state, .route-foot { grid-column: 2; }
    .route-duty { margin-bottom: 0; }
    .route-state { align-self: start; margin-top: 8px; }
    /* แนวตั้งไม่ต้องจองที่ เพราะแต่ละขั้นเรียงลงมาอยู่แล้ว */
    .route-foot { min-height: 0; margin-top: 5px; }
    .route-signed { min-height: 0; }
    .route-state { justify-self: start; }
  }
@endsection

@section('content')
  @include('partials.status-tabs')

  @php
    $createWorkStep = $workflow_steps->first(fn ($step) => $step->duty === 'create');
  @endphp
  <div class="panel">
    @if ($documents->isEmpty())
      <div class="empty">
        <p>ยังไม่มีเอกสาร</p>
        <span>กด "สร้างเอกสารใหม่" มุมขวาบนเพื่อเริ่ม</span>
      </div>
    @else
      <div class="table-wrap">
        <table>
          <thead>
            <tr>
              <th style="width:56px">No.</th>
              <th style="width:150px">PR Number</th>
              <th style="width:210px">Item Code</th>
              <th>ผู้ขอซื้อ</th>
              <th style="width:78px">รายการ</th>
              <th style="width:145px">สถานะ</th>
              <th style="width:92px">สถานะทั้งหมด</th>
              <th style="width:108px">วันที่</th>
              <th style="width:72px"></th>
            </tr>
          </thead>
          <tbody>
            @foreach ($documents as $doc)
              @php
                $itemCodes = $doc->items
                  ->pluck('item_code')
                  ->filter(fn ($code) => trim((string) $code) !== '')
                  ->values();
                $tabStatus = $createWorkStep
                  ? $doc->workStatusForStep($createWorkStep, $workflow_steps)
                  : ['state' => 'waiting', 'label' => $doc->workStatusLabel()];
                $statusClass = match ($tabStatus['state']) {
                  'completed' => 'pill-ok',
                  'rejected' => 'pill-off',
                  'draft', 'upcoming' => 'pill-mute',
                  default => 'pill-warn',
                };
              @endphp
              <tr>
                {{-- นับต่อเนื่องข้ามหน้า ไม่ใช่เริ่ม 1 ใหม่ทุกหน้า --}}
                <td class="row-no">{{ $documents->firstItem() + $loop->index }}</td>

                <td class="pr-no">
                  <span>{{ $doc->displayLabel() }}</span>
                </td>

                <td>
                  @if ($itemCodes->isNotEmpty())
                    <button type="button" class="item-code-show"
                            data-item-code-dialog="itemCodeDialog{{ $doc->id }}"
                            aria-haspopup="dialog" aria-controls="itemCodeDialog{{ $doc->id }}">
                      ดูรายละเอียด
                    </button>
                  @else
                    <span class="muted-value">—</span>
                  @endif
                </td>

                <td>
                  {{-- ชื่อแผนกยึดจาก Insight เป็นหลัก ค่าที่ประทับไว้อาจเก่าถ้าต้นทางแก้ชื่อทีหลัง --}}
                  <span class="department {{ $doc->requesterDepartmentLabel() ? '' : 'muted-value' }}">
                    {{ $doc->requesterDepartmentLabel() ?: 'ยังไม่เลือกแผนก' }}
                  </span>
                </td>

                <td class="num">{{ number_format($doc->items_count) }}</td>

                <td>
                  {{-- เหตุผลเต็มไม่ต้องโชว์ในตาราง — คอลัมน์สุดท้ายบอกสั้นๆ อยู่แล้ว --}}
                  <span class="pill {{ $statusClass }}">{{ $tabStatus['label'] }}</span>
                </td>

                <td>
                  <button type="button" class="route-show"
                          data-route-dialog="routeDialog{{ $doc->id }}"
                          aria-haspopup="dialog" aria-controls="routeDialog{{ $doc->id }}">
                    แสดง
                  </button>
                </td>

                <td class="num" style="color:var(--muted);font-size:13px">
                  {{ $doc->document_date?->format('d/m/Y') }}
                </td>

                <td>
                  @if ($doc->isRejected())
                    {{-- ใบที่ถูกปฏิเสธจบแล้ว ไม่มีปุ่มเปิดแก้ — ฝ่ายจัดซื้อขึ้นใบใหม่เอง
                         ระบบปฏิเสธเอง = บอกเหตุผลตัวแดงตรงนั้นเลย · คนกดปฏิเสธ = เปิดอ่านเหตุผลที่เขาพิมพ์ --}}
                    @if ($doc->isAutoRejected())
                      <span class="reject-note" title="{{ $doc->rejected_by_name }} · {{ $doc->rejected_at?->format('d/m/Y') }}">
                        {{ $doc->rejectionNote($workflow_steps) }}
                      </span>
                    @else
                      <button type="button" class="reject-why" data-reason-dialog="reasonDialog{{ $doc->id }}"
                              aria-haspopup="dialog" aria-controls="reasonDialog{{ $doc->id }}">
                        เหตุผล
                        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="m9 18 6-6-6-6"/></svg>
                      </button>
                    @endif
                  @else
                    <a class="go" href="{{ route('documents.edit', $doc) }}">
                      เปิด
                      <svg viewBox="0 0 24 24" aria-hidden="true"><path d="m9 18 6-6-6-6"/></svg>
                    </a>
                  @endif
                </td>
              </tr>
            @endforeach
          </tbody>
        </table>
      </div>

      @if ($documents->hasPages())
        @php
          // โชว์เลขหน้ารอบๆ หน้าปัจจุบัน ที่เหลือย่อเป็น …
          $last = $documents->lastPage();
          $current = $documents->currentPage();
          $window = collect(range(max(1, $current - 2), min($last, $current + 2)))
            ->merge([1, $last])
            ->unique()
            ->sort()
            ->values();
        @endphp
        <nav class="pager" aria-label="แบ่งหน้าเอกสาร">
          <span class="pager-count">
            {{ number_format($documents->firstItem()) }}–{{ number_format($documents->lastItem()) }}
            จาก {{ number_format($documents->total()) }} เอกสาร
          </span>

          <div class="pager-links">
            <a class="pager-step @if ($documents->onFirstPage()) is-off @endif"
               href="{{ $documents->previousPageUrl() ?? '#' }}"
               @if ($documents->onFirstPage()) aria-disabled="true" @endif rel="prev" aria-label="หน้าก่อนหน้า">
              <svg viewBox="0 0 24 24" aria-hidden="true"><path d="m15 18-6-6 6-6"/></svg>
            </a>

            @php $previous = 0; @endphp
            @foreach ($window as $page)
              @if ($page - $previous > 1)
                <span class="pager-gap">…</span>
              @endif
              <a class="pager-page @if ($page === $current) is-current @endif"
                 href="{{ $documents->url($page) }}"
                 @if ($page === $current) aria-current="page" @endif>{{ $page }}</a>
              @php $previous = $page; @endphp
            @endforeach

            <a class="pager-step @if (! $documents->hasMorePages()) is-off @endif"
               href="{{ $documents->nextPageUrl() ?? '#' }}"
               @if (! $documents->hasMorePages()) aria-disabled="true" @endif rel="next" aria-label="หน้าถัดไป">
              <svg viewBox="0 0 24 24" aria-hidden="true"><path d="m9 18 6-6-6-6"/></svg>
            </a>
          </div>
        </nav>
      @endif
    @endif
  </div>

  @foreach ($documents as $doc)
    @php
      $routeProgress = $doc->routeProgress($workflow_steps);
      $tabStatus = $createWorkStep
        ? $doc->workStatusForStep($createWorkStep, $workflow_steps)
        : ['state' => 'waiting', 'label' => $doc->workStatusLabel()];
      $itemCodes = $doc->items
        ->pluck('item_code')
        ->filter(fn ($code) => trim((string) $code) !== '')
        ->values();
    @endphp
    @if ($itemCodes->isNotEmpty())
      <dialog class="item-code-dialog" id="itemCodeDialog{{ $doc->id }}" aria-labelledby="itemCodeTitle{{ $doc->id }}">
        <header class="route-dialog-header">
          <div class="route-dialog-title">
            <h2 id="itemCodeTitle{{ $doc->id }}">Item Code</h2>
            <p>{{ $doc->displayLabel() }}</p>
          </div>
          <form method="dialog">
            <button type="submit" class="dialog-close" aria-label="ปิดรายละเอียด Item Code">
              <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M18 6 6 18M6 6l12 12"/></svg>
            </button>
          </form>
        </header>
        <ol class="item-code-list">
          @foreach ($itemCodes as $itemCode)
            <li><span class="item-code-number">{{ $loop->iteration }}.</span><code>{{ $itemCode }}</code></li>
          @endforeach
        </ol>
      </dialog>
    @endif

    @if ($doc->isRejected() && ! $doc->isAutoRejected())
      <dialog class="route-dialog is-reason" id="reasonDialog{{ $doc->id }}" aria-labelledby="reasonTitle{{ $doc->id }}">
        <header class="route-dialog-header">
          <div class="route-dialog-title">
            <h2 id="reasonTitle{{ $doc->id }}">เหตุผลที่ปฏิเสธ</h2>
            <p>{{ $doc->displayLabel() }}</p>
          </div>
          <form method="dialog">
            <button type="submit" class="dialog-close" aria-label="ปิดเหตุผลที่ปฏิเสธ">
              <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M18 6 6 18M6 6l12 12"/></svg>
            </button>
          </form>
        </header>

        <div class="route-dialog-body">
          <p class="reason-text">{{ $doc->rejected_reason ?: 'ไม่ได้ระบุเหตุผล' }}</p>
          <p class="reason-by">
            ปฏิเสธโดย {{ $doc->rejected_by_name ?: 'ไม่ทราบ' }}
            @if ($doc->rejected_at)
              · {{ $doc->rejected_at->format('d/m/Y H:i') }}
            @endif
          </p>
        </div>
      </dialog>
    @endif

    <dialog class="route-dialog" id="routeDialog{{ $doc->id }}" aria-labelledby="routeTitle{{ $doc->id }}">
      <header class="route-dialog-header">
        <div class="route-dialog-title">
          <h2 id="routeTitle{{ $doc->id }}">สถานะทั้งหมด</h2>
          <p>{{ $doc->displayLabel() }} · {{ $tabStatus['label'] }}</p>
        </div>
        <form method="dialog">
          <button type="submit" class="dialog-close" aria-label="ปิดสถานะทั้งหมด">
            <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M18 6 6 18M6 6l12 12"/></svg>
          </button>
        </form>
      </header>

      <div class="route-dialog-body">
        @if ($routeProgress === [])
          <p class="muted-value">ยังไม่ได้กำหนดเส้นทางเอกสาร</p>
        @else
          <div class="route-scroll">
            <ol class="route-map" aria-label="สถานะทุกขั้นของเอกสาร">
              @foreach ($routeProgress as $index => $node)
                @php
                  $step = $node['step'];
                  $signature = $node['signature'];
                  $people = $node['people'];
                @endphp
                <li class="{{ $node['state'] }}{{ $node['blocked'] ? ' blocked' : '' }}">
                  <span class="route-dot">{{ $index + 1 }}</span>
                  <b class="route-role">{{ $step->roleLabel() }}</b>
                  <span class="route-duty">{{ $step->dutyLabel() }}</span>
                  <span class="route-state">{{ $node['state_label'] }}</span>

                  {{-- ชื่อผู้รับผิดชอบค้างไว้เสมอ · ลงนามแล้วค่อยมีวันที่ต่อท้ายข้างล่าง
                       ห่อไว้ในกล่องความสูงคงที่ เพื่อให้ป้ายสถานะของทุกขั้นอยู่ระดับเดียวกัน
                       (ไม่งั้นขั้นที่มีวันที่จะดันสถานะสูงกว่าขั้นอื่น) --}}
                  <span class="route-foot">
                    @if ($signature)
                      <span class="route-person">{{ $signature->signer_name }}</span>
                    @elseif ($people === [])
                      <span class="route-person">{{ $step->isUserStep() ? 'ยังไม่เลือกผู้ขอซื้อ' : 'ยังไม่กำหนดผู้รับผิดชอบ' }}</span>
                    @else
                      @foreach ($people as $person)
                        <span class="route-person">
                          {{ $person['name'] }}
                          @if ($person['resigned'])
                            <b class="route-resigned">ลาออก{{ $person['resigned_on'] ? ' · '.$person['resigned_on'] : '' }}</b>
                          @endif
                        </span>
                      @endforeach
                    @endif
                    <span class="route-signed">{{ $signature?->signed_at?->format('d/m/Y') }}</span>
                  </span>
                </li>
              @endforeach
            </ol>
          </div>

          <div class="route-legend" aria-label="คำอธิบายสถานะเส้นทาง">
            <span><i class="legend-dot completed"></i>ดำเนินการแล้ว</span>
            <span><i class="legend-dot waiting"></i>รอการดำเนินการ</span>
            <span><i class="legend-dot"></i>ยังไม่ถึงขั้น</span>
            <span><i class="legend-dot rejected"></i>ปฏิเสธ</span>
          </div>
        @endif
      </div>
    </dialog>
  @endforeach

  @if ($documents->hasPages())
    <div style="margin-top:14px">{{ $documents->links('pagination') }}</div>
  @endif
@endsection

@section('scripts')
  <script>
    'use strict';

    document.querySelectorAll('[data-route-dialog]').forEach(function (button) {
      button.addEventListener('click', function () {
        var dialog = document.getElementById(button.dataset.routeDialog);
        if (dialog) { dialog.showModal(); }
      });
    });

    document.querySelectorAll('[data-item-code-dialog]').forEach(function (button) {
      button.addEventListener('click', function () {
        var dialog = document.getElementById(button.dataset.itemCodeDialog);
        if (dialog) { dialog.showModal(); }
      });
    });

    document.querySelectorAll('[data-reason-dialog]').forEach(function (button) {
      button.addEventListener('click', function () {
        var dialog = document.getElementById(button.dataset.reasonDialog);
        if (dialog) { dialog.showModal(); }
      });
    });

    document.querySelectorAll('.route-dialog, .item-code-dialog').forEach(function (dialog) {
      dialog.addEventListener('click', function (event) {
        if (event.target === dialog) { dialog.close(); }
      });
    });
  </script>
@endsection
