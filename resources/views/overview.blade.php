@extends('layouts.portal')

@section('title', 'ภาพรวม · QuoteCompare')
@section('heading', 'ภาพรวม')

@section('styles')
  /* หน้านี้มีคอลัมน์เยอะกว่าหน้าอื่น ขยายกระดาษให้เห็นครบโดยไม่ต้องเลื่อนซ้ายขวา */
  .page { width: min(1520px, 100%); }

  /* ── คำอธิบายสัญลักษณ์ อยู่เหนือตารางให้อ่านก่อนดูข้อมูล ── */
  .ov-legend {
    display: flex; flex-wrap: wrap; align-items: center; gap: 8px 20px;
    margin-bottom: 12px; padding: 11px 16px;
    background: var(--surface); border: 1px solid var(--line); border-radius: var(--radius);
    color: var(--muted); font-size: 12.5px;
  }
  .ov-legend b { color: var(--ink-soft); font-size: 12px; font-weight: 700; }
  /* ปุ่มออกรายงาน — ชิดขวาสุดของแถบสัญลักษณ์ */
  .ov-export {
    display: inline-flex; align-items: center; gap: 6px; margin-left: auto;
    padding: 7px 14px; border: 1px solid #1f7a4d; border-radius: 8px;
    background: #e7f4ec; color: #1f7a4d;
    font-size: 12.5px; font-weight: 700; text-decoration: none; white-space: nowrap;
    transition: background-color .18s ease;
  }
  .ov-export:hover { background: #d5ecdf; }
  .ov-export svg { width: 15px; height: 15px; fill: none; stroke: currentColor; stroke-width: 2; stroke-linecap: round; stroke-linejoin: round; }
  .ov-legend span { display: inline-flex; align-items: center; gap: 6px; white-space: nowrap; }
  .legend-pip { width: 11px; height: 11px; border-radius: 50%; background: var(--surface-soft); border: 1px solid var(--line); }
  .legend-pip.done { background: var(--ok); border-color: var(--ok); }
  .legend-pip.now { background: #e1ad47; border-color: #e1ad47; }
  .legend-pip.stop { background: var(--danger); border-color: var(--danger); }
  .legend-pip.left { background: #fff; border: 2px solid var(--danger); }

  .ov-panel { background: var(--surface); border: 1px solid var(--line); border-radius: var(--radius); box-shadow: var(--shadow-sm); overflow: hidden; }
  .ov-wrap { overflow-x: auto; }
  .ov-table { width: 100%; min-width: 1160px; border-collapse: collapse; }
  /* ทุกคอลัมน์จัดกึ่งกลาง หัวตารางจึงตรงกับข้อมูลใต้มันพอดี */
  .ov-table th {
    padding: 11px 14px; border-bottom: 1px solid var(--line); background: var(--surface-soft);
    color: var(--muted); font-size: 12px; font-weight: 700; text-align: center; white-space: nowrap;
  }
  .ov-table td { padding: 13px 14px; border-bottom: 1px solid var(--line-soft); font-size: 13.5px; vertical-align: middle; text-align: center; }
  .ov-table tbody tr:last-child td { border-bottom: 0; }
  .ov-table tbody tr:hover { background: #fafcfc; }
  .ov-table .num { font-variant-numeric: tabular-nums; }
  .pr-reference { display: block; white-space: nowrap; font-weight: 700; }
  .muted-value { color: var(--muted); }
  .date-cell { white-space: nowrap; font-variant-numeric: tabular-nums; }
  .requester-name { display: block; max-width: 200px; margin-inline: auto; overflow-wrap: anywhere; }
  .requester-dept { display: block; max-width: 200px; margin: 2px auto 0; color: var(--muted); font-size: 12px; overflow-wrap: anywhere; }

  .item-code-show {
    min-height: 32px; padding: 0; color: var(--teal-deep); background: transparent;
    border: 0; font-size: 13px; font-weight: 600; cursor: pointer;
  }
  .item-code-show:hover { color: var(--teal-ink); text-decoration: underline; }

  .status-badge {
    display: inline-block; padding: 4px 11px; border-radius: 999px;
    background: var(--surface-soft); color: var(--muted); font-size: 12.5px; font-weight: 700; white-space: nowrap;
  }
  .status-badge.waiting { background: #fff4d6; color: #8a5b00; }
  .status-badge.completed { background: #e1f3e9; color: #17663c; }
  .status-badge.rejected { background: #fae4e2; color: #9b2c26; }

  /* ── สถานะทุกขั้นในแถวเดียว · กดที่แถบเพื่อเปิดดูแบบเต็ม ── */
  .route-track {
    display: inline-flex; align-items: center; gap: 0;
    padding: 4px 6px; margin: -4px -6px; border: 0; border-radius: 8px;
    background: transparent; cursor: pointer;
  }
  .route-track:hover { background: var(--surface-soft); }
  .route-track:focus-visible { outline: 2px solid var(--teal); outline-offset: 1px; }
  .route-node { display: flex; align-items: center; }
  .route-node + .route-node::before { content: ''; width: 13px; height: 2px; background: var(--line); flex: 0 0 auto; }
  .route-node.done + .route-node::before { background: #76bd98; }
  .route-pip {
    display: grid; place-items: center; width: 24px; height: 24px; border-radius: 50%;
    background: var(--surface-soft); color: var(--muted);
    font-size: 11.5px; font-weight: 700; border: 1px solid var(--line);
  }
  .route-node.done .route-pip { background: var(--ok); border-color: var(--ok); color: #fff; }
  .route-node.now .route-pip { background: #e1ad47; border-color: #e1ad47; color: #422c06; }
  .route-node.stop .route-pip { background: var(--danger); border-color: var(--danger); color: #fff; }
  .route-node.left .route-pip { background: #fff; border: 2px solid var(--danger); color: var(--danger); }

  /* ── คอลัมน์จัดการ — ช่องตายตัว 2 ช่อง จัดกึ่งกลางคอลัมน์
       ช่องที่สองใช้ร่วมกันระหว่าง "ดาวน์โหลด" (ใบที่อนุมัติ) กับ "เหตุผล" (ใบที่ถูกปฏิเสธ)
       ใบหนึ่งเป็นได้อย่างเดียว จึงไม่ชนกัน และปุ่มทุกแถวตรงคอลัมน์กันเสมอ ── */
  .row-actions { display: grid; grid-template-columns: 34px 88px; align-items: center; gap: 8px; justify-content: center; }
  .act {
    display: inline-flex; align-items: center; gap: 3px; padding: 0;
    background: transparent; border: 0; cursor: pointer;
    color: var(--teal-deep); font: inherit; font-size: 13px; font-weight: 700;
    text-decoration: none; white-space: nowrap;
  }
  .act:hover { text-decoration: underline; }
  .act svg { width: 14px; height: 14px; fill: none; stroke: currentColor; stroke-width: 2; stroke-linecap: round; stroke-linejoin: round; }
  .act.download { color: #17663c; }
  .act.why { color: var(--danger); }

  .ov-empty { padding: 56px 20px; text-align: center; color: var(--muted); }

  /* ── กล่องสถานะทั้งหมด (แบบเต็ม) และกล่องอื่นๆ ── */
  .route-dialog {
    width: min(92vw, 880px); max-height: min(86vh, 680px); padding: 0;
    color: var(--ink); background: var(--surface); border: 0; border-radius: 8px;
    box-shadow: 0 28px 72px -26px rgba(17, 24, 39, .55);
  }
  .route-dialog[open] { display: flex; flex-direction: column; }
  .route-dialog::backdrop, .item-code-dialog::backdrop { background: rgba(17, 24, 39, .48); }
  .route-dialog.is-reason { width: min(94vw, 440px); }
  .item-code-dialog {
    width: min(92vw, 430px); max-height: min(82vh, 560px); padding: 0;
    color: var(--ink); background: var(--surface); border: 0; border-radius: 8px;
    box-shadow: 0 28px 72px -26px rgba(17, 24, 39, .55);
  }
  .item-code-dialog[open] { display: flex; flex-direction: column; }
  .route-dialog-header { display: flex; align-items: center; gap: 14px; padding: 16px 18px; border-bottom: 1px solid var(--line); }
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
  .route-scroll { overflow-x: auto; padding: 2px 2px 8px; }
  .route-map { display: flex; min-width: 720px; margin: 0; padding: 2px 0 10px; list-style: none; }
  .route-map li {
    --step-bg: #dce2e3; --step-fg: #53606a; --step-line: #d3dbdc;
    position: relative; display: flex; flex: 1 1 0; min-width: 132px; flex-direction: column; padding: 0 7px; text-align: center;
  }
  .route-map li::before { content: ''; position: absolute; top: 18px; left: -50%; right: 50%; height: 2px; background: var(--step-line); }
  .route-map li:first-child::before { display: none; }
  .route-map .completed { --step-bg: var(--ok); --step-fg: #fff; --step-line: #76bd98; }
  .route-map .waiting { --step-bg: #e1ad47; --step-fg: #422c06; --step-line: #e4c27e; }
  .route-map .rejected { --step-bg: var(--danger); --step-fg: #fff; --step-line: #dd8d87; }
  .route-dot {
    position: relative; z-index: 1; display: grid; place-items: center;
    width: 38px; height: 38px; margin: 0 auto 10px; border-radius: 50%;
    color: var(--step-fg); background: var(--step-bg); font-size: 13px; font-weight: 700; box-shadow: 0 0 0 5px var(--surface);
  }
  .route-role { display: block; font-size: 12.5px; font-weight: 700; line-height: 1.3; }
  .route-duty { display: block; margin: 2px 0 10px; font-size: 12px; color: var(--ink-soft); }
  .route-state {
    display: inline-block; align-self: center; margin-top: auto; padding: 2px 8px;
    color: var(--step-fg); background: var(--step-bg); border-radius: 999px; font-size: 11.5px; font-weight: 600;
  }
  .route-map .upcoming .route-state { color: var(--muted); }
  .route-foot { display: block; min-height: 47px; margin-top: 7px; }
  .route-person { display: block; color: var(--muted); font-size: 11.5px; line-height: 1.35; overflow-wrap: anywhere; }
  .route-signed { display: block; min-height: 15px; margin-top: 2px; color: var(--muted); font-size: 11px; line-height: 1.35; font-variant-numeric: tabular-nums; }
  .route-resigned { display: block; margin-top: 1px; color: var(--danger); font-size: 11px; font-weight: 700; line-height: 1.35; }
  .route-map .blocked .route-person { color: var(--danger); }
  /* ขั้นที่คนลาออก — วงกลมกรอบแดง ใช้สัญลักษณ์เดียวกับแถบสถานะและคำอธิบายสัญลักษณ์ (D-079) */
  .route-map .resigned .route-dot {
    background: #fff; border: 2px solid var(--danger); color: var(--danger);
  }
  .route-map .resigned .route-state { color: var(--danger); background: #fdeceb; }

  .item-code-list { display: grid; margin: 0; padding: 4px 18px 18px; list-style: none; }
  .item-code-list li {
    display: grid; grid-template-columns: 28px minmax(0, 1fr); gap: 8px;
    padding: 11px 0; border-bottom: 1px solid var(--line-soft);
  }
  .item-code-list li:last-child { border-bottom: 0; }
  .item-code-number { color: var(--muted); font-size: 13px; font-weight: 700; font-variant-numeric: tabular-nums; }
  .item-code-list code { color: var(--ink); background: transparent; font-family: var(--font); font-size: 14px; font-weight: 700; overflow-wrap: anywhere; }
  .reason-text { margin: 0; color: var(--ink); font-size: 14px; line-height: 1.65; white-space: pre-line; overflow-wrap: anywhere; }
  .reason-by { margin: 12px 0 0; padding-top: 10px; border-top: 1px solid var(--line-soft); color: var(--muted); font-size: 12.5px; }

  @media (max-width: 900px) {
    .ov-legend { gap: 6px 14px; }
  }
@endsection

@section('content')
  @include('partials.status-tabs')

  @if ($documents->isNotEmpty())
    <div class="ov-legend" aria-label="คำอธิบายสัญลักษณ์สถานะ">
      <b>สัญลักษณ์สถานะ</b>
      <span><i class="legend-pip done"></i>ดำเนินการแล้ว</span>
      <span><i class="legend-pip now"></i>รอดำเนินการ</span>
      <span><i class="legend-pip"></i>ยังไม่ถึงขั้น</span>
      <span><i class="legend-pip stop"></i>ปฏิเสธ</span>
      <span><i class="legend-pip left"></i>ลาออก</span>

      @if ($can_export ?? false)
        {{-- รายงานสรุปทั้งหมดเป็น Excel — เห็นเฉพาะฝ่ายจัดซื้อขั้นจัดทำเอกสารและ admin (D-073) --}}
        <a class="ov-export" href="{{ route('overview.export') }}">
          <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><path d="m7 10 5 5 5-5"/><path d="M12 15V3"/></svg>
          ดาวน์โหลด Excel
        </a>
      @endif
    </div>
  @endif

  <div class="ov-panel">
    @if ($documents->isEmpty())
      <div class="ov-empty">ยังไม่มีเอกสารที่ส่งออกไปแล้ว</div>
    @else
      <div class="ov-wrap">
        <table class="ov-table">
          <thead>
            <tr>
              <th style="width:56px">No.</th>
              <th style="width:150px">PR Number</th>
              <th style="width:126px">Item Code</th>
              <th style="width:196px">ผู้ขอซื้อ</th>
              <th style="width:72px">รายการ</th>
              <th style="width:128px">สถานะ</th>
              <th style="width:206px">สถานะทั้งหมด</th>
              <th style="width:128px">วันที่เอกสาร (อนุมัติ)</th>
              <th style="width:150px">จัดการ</th>
            </tr>
          </thead>
          <tbody>
            @foreach ($documents as $doc)
              @php
                $progress = $doc->routeProgress($workflow_steps);
                $overall = $doc->workStatusLabel();
                $overall_state = $doc->isRejected() ? 'rejected' : ($doc->status === 'approved' ? 'completed' : 'waiting');
                $item_codes = $doc->items
                  ->pluck('item_code')
                  ->filter(fn ($code) => trim((string) $code) !== '')
                  ->values();
              @endphp
              <tr>
                <td class="num">{{ $documents->firstItem() + $loop->index }}</td>

                <td><span class="pr-reference">{{ $doc->displayLabel() }}</span></td>

                <td>
                  @if ($item_codes->isNotEmpty())
                    <button type="button" class="item-code-show" data-item-code-dialog="itemCodeDialog{{ $doc->id }}"
                            aria-haspopup="dialog" aria-controls="itemCodeDialog{{ $doc->id }}">
                      ดูรายละเอียด
                    </button>
                  @else
                    <span class="muted-value">—</span>
                  @endif
                </td>

                <td>
                  <span class="requester-name">{{ $doc->requester_name ?: 'ยังไม่เลือกผู้ขอซื้อ' }}</span>
                  @if ($doc->requesterDepartmentLabel())
                    <span class="requester-dept">{{ $doc->requesterDepartmentLabel() }}</span>
                  @endif
                </td>

                <td class="num">{{ number_format($doc->items_count) }}</td>

                <td><span class="status-badge {{ $overall_state }}">{{ $overall }}</span></td>

                {{-- สถานะทุกขั้นเห็นได้ในแถวเลย · กดที่แถบเพื่อเปิดดูแบบเต็ม (D-071) --}}
                <td>
                  <button type="button" class="route-track" data-route-dialog="routeDialog{{ $doc->id }}"
                          aria-haspopup="dialog" aria-controls="routeDialog{{ $doc->id }}"
                          aria-label="ดูสถานะทุกขั้นของ {{ $doc->referenceLabel() }}">
                    @foreach ($progress as $index => $node)
                      @php
                        $pip_state = match ($node['state']) {
                          'completed' => 'done',
                          'waiting' => 'now',
                          'rejected' => 'stop',
                          default => '',
                        };
                        $left = collect($node['people'])->where('resigned', true)->pluck('name')->join(', ');
                        /*
                        | สัญลักษณ์ "ลาออก" (วงกลมกรอบ) ขึ้นที่ **ขั้นที่เอกสารสะดุดจริง** เท่านั้น (D-079)
                        |   - ขั้นที่กำลังรอ (waiting) หรือขั้นที่ใบตกไป (rejected) และคนที่ต้องทำลาออก
                        |   - ขั้นถัดไปที่เป็นคนเดียวกัน (ผู้ขอซื้ออยู่ 2 ขั้น) ปล่อยเป็น "ยังไม่ถึงขั้น" สีเทา
                        */
                        if ($left !== '' && in_array($node['state'], ['waiting', 'rejected'], true)) {
                          $pip_state = 'left';
                        }
                        $people = collect($node['people'])->pluck('name')->join(', ');
                        $tip = ($index + 1).'. '.$node['step']->roleLabel().' — '.$node['step']->dutyLabel()
                          .' · '.$node['state_label']
                          .($people !== '' ? ' · '.$people : '')
                          .($left !== '' ? ' (ลาออกแล้ว)' : '');
                      @endphp
                      <span class="route-node {{ $pip_state }}">
                        <span class="route-pip" title="{{ $tip }}">{{ $index + 1 }}</span>
                      </span>
                    @endforeach
                  </button>
                </td>

                <td class="num date-cell">{{ $doc->document_date?->format('d/m/Y') }}</td>

                <td>
                  <div class="row-actions">
                    <a class="act" href="{{ route('overview.show', $doc) }}">ดู</a>

                    <span>
                      @if ($doc->isRejected())
                        <button type="button" class="act why" data-reason-dialog="reasonDialog{{ $doc->id }}"
                                aria-haspopup="dialog" aria-controls="reasonDialog{{ $doc->id }}">เหตุผล</button>
                      @elseif ($doc->status === 'approved')
                        <a class="act download" href="{{ route('overview.pdf', $doc) }}">
                          ดาวน์โหลด
                          <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><path d="m7 10 5 5 5-5"/><path d="M12 15V3"/></svg>
                        </a>
                      @endif
                    </span>
                  </div>
                </td>
              </tr>
            @endforeach
          </tbody>
        </table>
      </div>
    @endif
  </div>

  @if ($documents->hasPages())
    <div style="margin-top:14px">{{ $documents->links('pagination') }}</div>
  @endif

  @foreach ($documents as $doc)
    @php
      $route_progress = $doc->routeProgress($workflow_steps);
      $item_codes = $doc->items
        ->pluck('item_code')
        ->filter(fn ($code) => trim((string) $code) !== '')
        ->values();
    @endphp

    @if ($item_codes->isNotEmpty())
      <dialog class="item-code-dialog" id="itemCodeDialog{{ $doc->id }}" aria-labelledby="itemCodeTitle{{ $doc->id }}">
        <header class="route-dialog-header">
          <div class="route-dialog-title">
            <h2 id="itemCodeTitle{{ $doc->id }}">Item Code</h2>
            <p>{{ $doc->displayLabel() }}</p>
          </div>
          <form method="dialog">
            <button type="submit" class="dialog-close" aria-label="ปิด">
              <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M18 6 6 18M6 6l12 12"/></svg>
            </button>
          </form>
        </header>
        <ol class="item-code-list">
          @foreach ($item_codes as $item_code)
            <li><span class="item-code-number">{{ $loop->iteration }}.</span><code>{{ $item_code }}</code></li>
          @endforeach
        </ol>
      </dialog>
    @endif

    @if ($doc->isRejected())
      <dialog class="route-dialog is-reason" id="reasonDialog{{ $doc->id }}" aria-labelledby="reasonTitle{{ $doc->id }}">
        <header class="route-dialog-header">
          <div class="route-dialog-title">
            <h2 id="reasonTitle{{ $doc->id }}">เหตุผลที่ปฏิเสธ</h2>
            <p>{{ $doc->displayLabel() }}</p>
          </div>
          <form method="dialog">
            <button type="submit" class="dialog-close" aria-label="ปิด">
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
          <p>{{ $doc->displayLabel() }} · {{ $doc->workStatusLabel() }}</p>
        </div>
        <form method="dialog">
          <button type="submit" class="dialog-close" aria-label="ปิดสถานะทั้งหมด">
            <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M18 6 6 18M6 6l12 12"/></svg>
          </button>
        </form>
      </header>

      <div class="route-dialog-body">
        @if ($route_progress === [])
          <p class="muted-value">ยังไม่ได้กำหนดเส้นทางเอกสาร</p>
        @else
          <div class="route-scroll">
            <ol class="route-map" aria-label="สถานะทุกขั้นของเอกสาร">
              @foreach ($route_progress as $index => $node)
                @php
                  $step = $node['step'];
                  $signature = $node['signature'];
                  $people = $node['people'];
                  // วงกลมกรอบแดง = "ลาออก" เฉพาะขั้นที่เอกสารสะดุดจริง (รออยู่ หรือใบตกที่ขั้นนี้)
                  // ขั้นถัดไปที่เป็นคนเดียวกันปล่อยเป็น "ยังไม่ถึงขั้น" สีเทา
                  $node_resigned = collect($people)->where('resigned', true)->isNotEmpty()
                    && in_array($node['state'], ['waiting', 'rejected'], true);
                @endphp
                <li class="{{ $node['state'] }}{{ $node['blocked'] ? ' blocked' : '' }}{{ $node_resigned ? ' resigned' : '' }}">
                  <span class="route-dot">{{ $index + 1 }}</span>
                  <b class="route-role">{{ $step->roleLabel() }}</b>
                  <span class="route-duty">{{ $step->dutyLabel() }}</span>
                  <span class="route-state">{{ $node['state_label'] }}</span>
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
        @endif
      </div>
    </dialog>
  @endforeach
@endsection

@section('scripts')
  <script>
    'use strict';
    ['data-item-code-dialog', 'data-reason-dialog', 'data-route-dialog'].forEach(function (attr) {
      document.querySelectorAll('[' + attr + ']').forEach(function (button) {
        button.addEventListener('click', function () {
          var dialog = document.getElementById(button.getAttribute(attr));
          if (dialog) { dialog.showModal(); }
        });
      });
    });
  </script>
@endsection
