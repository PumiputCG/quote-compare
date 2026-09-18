@extends('layouts.portal')

@php
  // ขั้นยืนยันรายการเป็นหน้าของผู้ขอซื้อเหมือนกัน จึงใช้ชุดคอลัมน์เดียวกัน (D-075)
  $is_confirm_list = ($list_mode ?? 'select') === 'confirm';
  $is_requester_list = ($list_mode ?? 'select') === 'select' || $is_confirm_list;
  // หน้าต่อรองราคาใช้ชุดคอลัมน์เดียวกับ /work/create ตามที่ Manager สั่ง
  $is_negotiate_list = ($list_mode ?? 'select') === 'negotiate';
  $is_approval_list = ($list_mode ?? 'select') === 'approval';
  // หน้าดาวน์โหลดใช้ชุดคอลัมน์เดียวกับหน้าต่อรองราคา
  $is_download_list = ($list_mode ?? 'select') === 'download';
  $is_negotiate_list = $is_negotiate_list || $is_download_list;
  $show_route_column = $is_requester_list || $is_negotiate_list || $is_approval_list;
  $current_work_duty = match (true) {
    $is_negotiate_list => 'negotiate',
    $is_approval_list => 'sign',
    $is_confirm_list => 'confirm',
    default => 'select',
  };
  $current_work_step = $work_step ?? $workflow_steps->first(fn ($step) => $step->duty === $current_work_duty);
@endphp

@section('title', ($page_title ?? 'ผู้ขอซื้อ') . ' · QuoteCompare')
@section('heading', $page_title ?? 'ผู้ขอซื้อ')

@section('styles')
  .select-panel { background: var(--surface); border: 1px solid var(--line); border-radius: var(--radius); box-shadow: var(--shadow-sm); overflow: hidden; }
  .select-table-wrap { overflow-x: auto; }
  .select-table { width: 100%; min-width: 820px; border-collapse: collapse; }
  .select-table.requester-table { min-width: 1080px; }
  /* ทุกคอลัมน์จัดกึ่งกลาง หัวตารางจึงตรงกับข้อมูลใต้มัน (D-074) */
  .select-table th {
    padding: 11px 14px; border-bottom: 1px solid var(--line); background: var(--surface-soft);
    color: var(--muted); font-size: 12px; font-weight: 700; text-align: center; white-space: nowrap;
  }
  .select-table td { padding: 13px 14px; border-bottom: 1px solid var(--line-soft); font-size: 13.5px; vertical-align: middle; text-align: center; }
  .select-table tbody tr:last-child td { border-bottom: 0; }
  .select-table tbody tr:hover { background: #fafcfc; }
  .select-table .num { font-variant-numeric: tabular-nums; }
  .pr-reference { display: block; white-space: nowrap; font-weight: 700; }
  .purpose { display: block; max-width: 460px; margin-inline: auto; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
  .item-code-show {
    min-height: 32px; padding: 0; color: var(--teal-deep); background: transparent;
    border: 0; font-size: 13px; font-weight: 600; cursor: pointer;
  }
  .item-code-show:hover { color: var(--teal-ink); text-decoration: underline; }
  .creator-department { display: block; max-width: 180px; margin-inline: auto; color: var(--ink-soft); overflow-wrap: anywhere; }
  .muted-value { color: var(--muted); }
  .date-cell { color: var(--muted); font-size: 13px; white-space: nowrap; }
  .supplier-list { display: grid; gap: 4px; margin: 0; padding: 0; list-style: none; }
  .supplier-list { justify-items: center; }
  .supplier-list li { display: grid; grid-template-columns: 20px minmax(0, 1fr); gap: 2px; color: var(--ink-soft); line-height: 1.35; text-align: left; }
  .supplier-number { color: var(--muted); font-variant-numeric: tabular-nums; }
  .status-badge {
    display: inline-flex; align-items: center; min-height: 22px; padding: 2px 8px;
    border-radius: 999px; background: var(--surface-soft); color: var(--ink-soft); font-size: 11.5px; font-weight: 700;
  }
  .status-badge.waiting { background: #fff4d6; color: #8a5b00; }
  .status-badge.completed { background: #e1f3e9; color: #17663c; }
  .status-badge.rejected { background: #fae4e2; color: #9b2c26; }
  .route-show {
    min-height: 32px; padding: 0 12px; color: var(--teal-deep); background: var(--surface);
    border: 1px solid #bcdedb; border-radius: 7px; font-size: 13px; font-weight: 600; cursor: pointer;
  }
  .route-show:hover { background: var(--teal-soft); border-color: var(--teal); }
  .route-show:focus-visible, .dialog-close:focus-visible, .open-document:focus-visible { outline: 2px solid var(--teal); outline-offset: 2px; }
  .open-document { display: inline-flex; align-items: center; gap: 5px; color: var(--teal-deep); font-weight: 700; text-decoration: none; white-space: nowrap; }
  .open-document svg { width: 15px; height: 15px; fill: none; stroke: currentColor; stroke-width: 2; stroke-linecap: round; stroke-linejoin: round; }
  .empty-select { padding: 56px 20px; text-align: center; color: var(--muted); }

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
  /* ใบที่จบแล้ว — บอกสาเหตุแทนปุ่มเปิดเอกสาร */
  .reject-note { display: inline-block; color: var(--danger); font-size: 12.5px; font-weight: 700; line-height: 1.35; }
  /* หน้าตาเดียวกับปุ่ม "ดู/เลือก" แต่เป็นสีแดง */
  .reject-why {
    display: inline-flex; align-items: center; gap: 5px; padding: 0;
    border: 0; background: transparent; color: var(--danger);
    font: inherit; font-weight: 700; white-space: nowrap; cursor: pointer;
  }
  .reject-why svg { width: 15px; height: 15px; fill: none; stroke: currentColor; stroke-width: 2; stroke-linecap: round; stroke-linejoin: round; }
  .reject-why:hover { text-decoration: underline; }
  .route-dialog.is-reason { width: min(94vw, 440px); }
  .reason-text { margin: 0; color: var(--ink); font-size: 14px; line-height: 1.65; white-space: pre-line; overflow-wrap: anywhere; }
  .reason-by { margin: 12px 0 0; padding-top: 10px; border-top: 1px solid var(--line-soft); color: var(--muted); font-size: 12.5px; }

  /* คนในขั้นนี้ลาออกแล้ว — เอกสารเดินต่อไม่ได้ ต้องเห็นชัดตั้งแต่แวบแรก */
  .route-resigned { display: block; margin-top: 1px; color: var(--danger); font-size: 11px; font-weight: 700; line-height: 1.35; }
  .route-map .blocked .route-person { color: var(--danger); }
  /* ขั้นที่คนลาออก — วงกลมกรอบแดง สัญลักษณ์เดียวกับหน้าภาพรวม (D-079) */
  .route-map .resigned .route-dot { background: #fff; border: 2px solid var(--danger); color: var(--danger); }
  .route-map .resigned .route-state { color: var(--danger); background: #fdeceb; }
  .legend-dot.left { background: #fff; border: 2px solid var(--danger); }
  .route-legend { display: flex; flex-wrap: wrap; gap: 7px 16px; margin-top: 14px; padding-top: 14px; border-top: 1px solid var(--line-soft); color: var(--muted); font-size: 12px; }
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
    .route-map li { min-width: 0; display: grid; grid-template-columns: 38px minmax(0, 1fr); column-gap: 12px; padding: 0 0 18px; text-align: left; }
    .route-map li::before { top: -18px; left: 18px; right: auto; width: 2px; height: 36px; }
    .route-dot { grid-row: 1 / span 4; margin: 0; box-shadow: 0 0 0 4px var(--surface); }
    .route-duty, .route-state, .route-foot { grid-column: 2; }
    .route-duty { margin-bottom: 0; }
    .route-state { justify-self: start; align-self: start; margin-top: 5px; }
    .route-foot { min-height: 0; margin-top: 5px; }
  }
@endsection

@section('content')
  @include('partials.status-tabs')

  <div class="select-panel">
    @if ($documents->isEmpty())
      <div class="empty-select">{{ $empty_message ?? 'ยังไม่มีเอกสารส่งถึงคุณ' }}</div>
    @else
      <div class="select-table-wrap">
        <table class="select-table {{ $show_route_column ? 'requester-table' : '' }}">
          <thead>
            <tr>
              <th style="width:56px">No.</th>
              <th style="width:190px">PR Number</th>
              @if ($is_negotiate_list || $is_approval_list)
                <th style="width:210px">Item Code</th>
                <th>ผู้ขอซื้อ</th>
                <th style="width:78px">รายการ</th>
              @elseif ($is_requester_list)
                <th style="width:170px">Item Code</th>
                <th style="width:180px">ผู้จัดทำ</th>
                <th style="width:250px">Supplier</th>
              @else
                <th>Purpose</th>
                <th style="width:250px">Supplier</th>
              @endif
              <th style="width:160px">สถานะ</th>
              @if ($show_route_column)
                <th style="width:90px">สถานะทั้งหมด</th>
              @endif
              @if ($is_negotiate_list || $is_approval_list)
                <th style="width:108px">วันที่</th>
              @endif
              <th style="width:78px"></th>
            </tr>
          </thead>
          <tbody>
            @foreach ($documents as $doc)
              @php
                $item_codes = $doc->items
                  ->pluck('item_code')
                  ->filter(fn ($code) => trim((string) $code) !== '')
                  ->values();
                $tab_status = $current_work_step
                  ? $doc->workStatusForStep($current_work_step, $workflow_steps)
                  : ['state' => 'waiting', 'label' => $doc->workStatusLabel()];
              @endphp
              <tr>
                <td class="num">{{ $documents->firstItem() + $loop->index }}</td>
                <td><span class="pr-reference">{{ $doc->displayLabel() }}</span></td>

                @if ($is_negotiate_list || $is_approval_list)
                  <td>
                    @if ($item_codes->isNotEmpty())
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
                    <span class="creator-department {{ $doc->requesterDepartmentLabel() ? '' : 'muted-value' }}">
                      {{ $doc->requesterDepartmentLabel() ?: 'ยังไม่เลือกแผนก' }}
                    </span>
                  </td>
                  <td class="num">{{ number_format($doc->items_count) }}</td>
                @elseif ($is_requester_list)
                  <td>
                    @if ($item_codes->isNotEmpty())
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
                    <span class="creator-department {{ $doc->creator?->department ? '' : 'muted-value' }}">
                      {{ $doc->creator?->department ?: '—' }}
                    </span>
                  </td>
                  <td>
                    <ol class="supplier-list">
                      @forelse ($doc->activeSuppliers() as $supplier)
                        <li><span class="supplier-number">{{ $loop->iteration }}.</span><span>{{ $supplier->displayName() }}</span></li>
                      @empty
                        <li class="muted-value">—</li>
                      @endforelse
                    </ol>
                  </td>
                @else
                  <td><span class="purpose">{{ $doc->purpose ?: '—' }}</span></td>
                  <td>
                    <ol class="supplier-list">
                      @forelse ($doc->activeSuppliers() as $supplier)
                        <li><span class="supplier-number">{{ $loop->iteration }}.</span><span>{{ $supplier->displayName() }}</span></li>
                      @empty
                        <li class="muted-value">—</li>
                      @endforelse
                    </ol>
                  </td>
                @endif

                <td>
                  <span class="status-badge {{ $tab_status['state'] }}">
                    {{ $tab_status['label'] }}
                  </span>
                </td>
                @if ($show_route_column)
                  <td>
                    <button type="button" class="route-show"
                            data-route-dialog="routeDialog{{ $doc->id }}"
                            aria-haspopup="dialog" aria-controls="routeDialog{{ $doc->id }}">
                      แสดง
                    </button>
                  </td>
                @endif
                @if ($is_negotiate_list || $is_approval_list)
                  <td class="num date-cell">{{ $doc->document_date?->format('d/m/Y') }}</td>
                @endif
                <td>
                  {{-- ใบที่คนกดปฏิเสธ เปิดอ่านเหตุผลที่เขาพิมพ์ไว้ได้
                       ใบที่ระบบปฏิเสธเอง บอกสาเหตุตัวแดงตรงนี้เลย ไม่ต้องกดเข้าไปอ่าน --}}
                  @if ($doc->isRejected() && $doc->isAutoRejected())
                    <span class="reject-note" title="{{ $doc->rejected_by_name }} · {{ $doc->rejected_at?->format('d/m/Y') }}">
                      {{ $doc->rejectionNote($workflow_steps) }}
                    </span>
                  @elseif ($doc->isRejected())
                    <button type="button" class="reject-why" data-reason-dialog="reasonDialog{{ $doc->id }}"
                            aria-haspopup="dialog" aria-controls="reasonDialog{{ $doc->id }}">
                      เหตุผล
                      <svg viewBox="0 0 24 24" aria-hidden="true"><path d="m9 18 6-6-6-6"/></svg>
                    </button>
                  @else
                    @php
                      $open_route = $open_route_name ?? 'work.select.edit';
                      $open_text = $open_label ?? match (true) {
                        $open_route === 'work.select.edit' && $doc->status === 'sent_user' => 'เลือก',
                        $open_route === 'work.confirm.edit' && $doc->status === 'confirming' => 'ยืนยัน',
                        default => 'ดู',
                      };
                    @endphp
                    <a class="open-document" href="{{ route($open_route, $doc) }}">
                      {{ $open_text }}
                      <svg viewBox="0 0 24 24" aria-hidden="true"><path d="m9 18 6-6-6-6"/></svg>
                    </a>
                  @endif
                </td>
              </tr>
            @endforeach
          </tbody>
        </table>
      </div>
    @endif
  </div>

  @if ($show_route_column)
    @foreach ($documents as $doc)
      @php
        $route_progress = $doc->routeProgress($workflow_steps);
        $tab_status = $current_work_step
          ? $doc->workStatusForStep($current_work_step, $workflow_steps)
          : ['state' => 'waiting', 'label' => $doc->workStatusLabel()];
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
              <button type="submit" class="dialog-close" aria-label="ปิดรายละเอียด Item Code">
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
            <p>{{ $doc->displayLabel() }} · {{ $tab_status['label'] }}</p>
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

            <div class="route-legend" aria-label="คำอธิบายสถานะเส้นทาง">
              <span><i class="legend-dot completed"></i>ดำเนินการแล้ว</span>
              <span><i class="legend-dot waiting"></i>รอการดำเนินการ</span>
              <span><i class="legend-dot"></i>ยังไม่ถึงขั้น</span>
              <span><i class="legend-dot rejected"></i>ปฏิเสธ</span>
              <span><i class="legend-dot left"></i>ลาออก</span>
            </div>
          @endif
        </div>
      </dialog>
    @endforeach
  @endif

  @if ($documents->hasPages())
    <div style="margin-top:14px">{{ $documents->links('pagination') }}</div>
  @endif
@endsection

@section('scripts')
  @if ($show_route_column)
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
  @endif
@endsection
