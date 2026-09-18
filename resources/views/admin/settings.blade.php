@extends('layouts.portal')

@section('title', 'ตั้งค่าระบบ · QuoteCompare')
@section('heading', 'ตั้งค่าระบบ')

@section('styles')
  /* ── กล่องพับเก็บได้ ─────────────────────────────────── */
  .acc {
    background: var(--surface);
    border: 1px solid var(--line);
    border-radius: var(--radius);
    box-shadow: var(--shadow-sm);
    margin-bottom: 16px;
  }
  .acc > summary {
    display: flex; align-items: flex-start; gap: 10px;
    padding: 15px 18px; cursor: pointer;
    list-style: none; font-size: 15px; font-weight: 600;
    border-radius: var(--radius);
  }
  .acc > summary > .chev { margin-top: 4px; }
  .acc > summary::-webkit-details-marker { display: none; }
  .acc > summary:hover { background: var(--surface-soft); }
  .acc > summary .chev {
    width: 16px; height: 16px; flex: none; color: var(--muted);
    transition: transform .2s var(--ease);
  }
  .acc[open] > summary .chev { transform: rotate(90deg); }
  .acc[open] > summary { border-radius: var(--radius) var(--radius) 0 0; }
  .acc > summary .note { margin-left: auto; font-size: 13px; font-weight: 400; color: var(--muted); white-space: nowrap; }

  /* สรุปย่อบนหัวกล่อง "กำหนดเส้นทาง" — ชิปเรียงคั่นด้วยลูกศร */
  .sum { min-width: 0; flex: 1; }
  /* เรียง 1→N บรรทัดเดียวเสมอ ; ยาวเกินจอค่อยเลื่อนแนวนอนในแถบนี้เอง */
  .sum-line {
    display: flex; align-items: center; flex-wrap: nowrap; gap: 4px;
    margin-top: 7px; font-weight: 400;
    overflow-x: auto; padding-bottom: 3px;
    scrollbar-width: thin; scrollbar-color: #d3dcdd transparent;
  }
  .sum-line::-webkit-scrollbar { height: 4px; }
  .sum-line::-webkit-scrollbar-thumb { background: #d3dcdd; border-radius: 999px; }
  .sum-line::-webkit-scrollbar-track { background: transparent; }
  .sum-line.empty { margin-top: 4px; font-size: 12.5px; color: var(--muted); overflow: visible; }

  .chip {
    display: inline-flex; align-items: center; gap: 4px; flex: none;
    padding: 2px 9px 2px 3px;
    border: 1px solid var(--line); border-radius: 999px;
    background: var(--surface); white-space: nowrap;
  }
  .chip .n {
    display: grid; place-items: center; width: 18px; height: 18px; flex: none;
    border-radius: 50%; background: var(--teal-ink); color: #fff;
    font-size: 10px; font-weight: 700; font-style: normal;
    font-variant-numeric: tabular-nums;
  }
  .chip .role { font-size: 11.5px; font-weight: 600; font-style: normal; color: var(--ink-soft); }
  .chip .act { font-size: 11px; font-style: normal; color: var(--muted); }

  .sum-line .sep { width: 12px; height: 12px; flex: none; color: #b3c0c1; }
  .acc-body { padding: 16px 18px 18px; border-top: 1px solid var(--line-soft); }

  /* ── แผนผังสรุปเส้นทาง — วงกลมเรียงเชื่อมด้วยเส้นและหัวลูกศร ── */
  .route-map {
    display: flex; flex-wrap: wrap;
    margin: 0 0 24px; padding: 14px 8px 4px;
    list-style: none;
    background: var(--surface-soft); border-radius: var(--radius);
  }
  .route-map li {
    position: relative; flex: 1 1 118px; min-width: 106px;
    padding: 0 4px 12px; text-align: center;
  }
  /* เส้นเชื่อมจากใบซ้ายมาถึงขอบวงกลมของใบนี้ */
  .route-map li::before {
    content: ''; position: absolute; top: 16px;
    left: -50%; right: calc(50% + 22px); height: 2px;
    background: #cfdad9;
  }
  /* หัวลูกศรชี้เข้าวงกลม */
  .route-map li::after {
    content: ''; position: absolute; top: 11px; right: calc(50% + 16px);
    border-left: 7px solid #cfdad9;
    border-top: 6px solid transparent; border-bottom: 6px solid transparent;
  }
  .route-map li:first-child::before,
  .route-map li:first-child::after { display: none; }

  .route-map .dot {
    position: relative; z-index: 1;
    display: grid; place-items: center; width: 34px; height: 34px; margin: 0 auto 9px;
    border-radius: 50%; background: var(--teal-ink); color: #fff;
    font-size: 13px; font-weight: 700;
    box-shadow: 0 0 0 4px var(--surface-soft);
  }
  .route-map b { display: block; font-size: 12.5px; font-weight: 600; line-height: 1.3; }
  .route-map .act { display: block; margin-top: 1px; font-size: 11.5px; color: var(--muted); }

  @media (max-width: 620px) {
    .route-map li::before, .route-map li::after { display: none; }
    .route-map li { flex: 1 1 100%; text-align: left; display: flex; align-items: center; gap: 10px; padding-bottom: 8px; }
    .route-map .dot { margin: 0; box-shadow: none; }
  }

  /* ปุ่มเพิ่มขั้น — มุมขวาบนเหนือสายโซ่ */
  .chain-bar { display: flex; justify-content: flex-end; margin-bottom: 12px; }
  .chain-bar .btn { min-height: 34px; padding: 0 13px; font-size: 13px; }

  /* สายโซ่การ์ด — 1 การ์ด = 1 ขั้น เชื่อมกันด้วยลูกศร วางกึ่งกลาง
     ความกว้างคุมไว้ให้ 5 ขั้นอยู่แถวเดียวบนจอกว้าง (รวม ~1,022px ใน 1,076px) */
  .chain {
    display: flex; flex-wrap: wrap; align-items: stretch; justify-content: center; gap: 2px;
  }
  .chain .link { display: flex; align-items: center; flex: none; color: var(--muted); }
  .chain .link svg { width: 14px; height: 14px; display: block; }

  .chain-empty {
    width: 100%; margin: 0; padding: 30px 20px; text-align: center;
    font-size: 13px; color: var(--muted);
    border: 1px dashed #c3ced0; border-radius: 12px;
  }

  .node {
    display: flex; flex-direction: column; width: 190px;
    border: 1px solid var(--line); border-radius: 14px; background: var(--surface);
    overflow: hidden;
  }

  /* หัวการ์ด — เลขขั้น + Role + การดำเนินการ */
  .node-top {
    display: flex; align-items: flex-start; gap: 6px;
    padding: 10px 9px 11px; background: var(--surface-soft);
    border-bottom: 1px solid var(--line);
  }
  .node-no {
    display: grid; place-items: center; width: 20px; height: 20px; flex: none;
    margin-top: 5px;
    border-radius: 50%; background: var(--teal-ink); color: #fff;
    font-size: 11.5px; font-weight: 700; font-variant-numeric: tabular-nums;
  }
  .node-selects { flex: 1; min-width: 0; }
  .node select {
    width: 100%; min-height: 30px; padding: 0 7px;
    border: 1px solid var(--line); border-radius: 8px; background: var(--surface); font-size: 12px;
  }
  .node select + select { margin-top: 5px; }
  .node select:focus {
    outline: none; border-color: var(--teal); box-shadow: 0 0 0 3px rgba(12, 163, 154, .16);
  }
  .node-duty {
    display: block; margin-top: 6px; padding-left: 2px;
    font-size: 12px; color: var(--muted);
  }

  /* รายชื่อพนักงานในขั้น — หนึ่งขั้นมีได้หลายคน */
  .node-people { flex: 1; padding: 6px; }
  .member, .slot {
    display: flex; align-items: center; gap: 7px;
    padding: 4px; border-radius: 9px;
  }
  .member:hover { background: var(--surface-soft); }
  .member .pic, .slot .pic {
    width: 28px; height: 28px; flex: none; border-radius: 50%; overflow: hidden;
    background: var(--surface-soft);
  }
  .member .pic img { width: 100%; height: 100%; object-fit: cover; display: block; }
  .slot .pic {
    display: grid; place-items: center;
    border: 1px dashed #c3ced0; color: var(--muted); background: transparent;
  }
  .slot { color: var(--muted); font-size: 12.5px; }
  .slot .pic svg { width: 15px; height: 15px; }

  /* ชื่อ + รหัสพนักงานซ้อนกัน 2 บรรทัด — ชื่อซ้ำกันได้ รหัสเป็นตัวแยก */
  .member .who { flex: 1; min-width: 0; }
  .member .nm {
    display: block; font-size: 12.5px; font-weight: 500; line-height: 1.3;
    white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
  }
  .member .code {
    display: block; font-size: 11px; color: var(--muted); line-height: 1.3;
  }
  .member .x {
    display: grid; place-items: center; width: 22px; height: 22px; flex: none;
    border: 0; border-radius: 6px; background: transparent; color: #a3aeaf; cursor: pointer;
    transition: background .16s var(--ease), color .16s var(--ease);
  }
  .member .x:hover { background: var(--danger-soft); color: var(--danger); }

  .no-member { margin: 4px 5px 6px; font-size: 12px; color: var(--muted); }

  .add-member {
    display: flex; align-items: center; justify-content: center; gap: 6px;
    width: 100%; margin-top: 3px; padding: 7px;
    border: 1px dashed #c3ced0; border-radius: 9px; background: transparent;
    color: var(--teal-ink); font-size: 12.5px; font-weight: 600; cursor: pointer;
    transition: background .16s var(--ease), border-color .16s var(--ease);
  }
  .add-member:hover { background: var(--teal-soft); border-color: var(--teal); }

  .node-tools {
    display: flex; justify-content: center; gap: 2px;
    padding: 6px; border-top: 1px solid var(--line-soft);
  }
  .node-tools button {
    display: grid; place-items: center; width: 26px; height: 26px;
    border: 0; border-radius: 7px; background: transparent; color: var(--muted); cursor: pointer;
    transition: background .16s var(--ease), color .16s var(--ease);
  }
  .node-tools button:hover:not(:disabled) { background: var(--surface-soft); color: var(--ink-soft); }
  .node-tools .del:hover { background: var(--danger-soft); color: var(--danger); }
  .node-tools button:disabled { opacity: .28; cursor: default; }



  /* กล่องเลือกคน */
  .picker {
    border: 0; padding: 0; border-radius: 14px;
    width: min(92vw, 440px); max-height: 84vh;
    box-shadow: 0 30px 70px -24px rgba(17, 24, 39, .5);
  }
  .picker::backdrop { background: rgba(17, 24, 39, .55); }
  .picker form { display: flex; flex-direction: column; max-height: 84vh; }
  .picker header {
    display: flex; align-items: center; gap: 12px;
    padding: 15px 16px; border-bottom: 1px solid var(--line);
  }
  .picker header b { flex: 1; font-size: 15px; }
  .picker header button {
    display: grid; place-items: center; width: 30px; height: 30px;
    border: 0; border-radius: 8px; background: transparent; color: var(--muted); cursor: pointer;
  }
  .picker header button:hover { background: var(--surface-soft); color: var(--ink); }
  /* ขั้นตอนใน modal — หัวข้อมีเลขกลม */
  .steps { flex: 1; overflow-y: auto; padding: 6px 0 10px; }
  .step + .step { border-top: 1px solid var(--line-soft); }
  .step-head {
    display: flex; align-items: center; gap: 10px; width: 100%; text-align: left;
    padding: 12px 16px 9px; border: 0; background: transparent;
    font-size: 14px; font-weight: 600; color: var(--ink);
  }
  button.step-head { cursor: pointer; }
  button.step-head:hover { background: var(--surface-soft); }
  .step-head .n {
    display: grid; place-items: center; width: 24px; height: 24px; flex: none;
    border-radius: 50%; background: var(--teal-ink); color: #fff;
    font-size: 12px; font-weight: 700;
  }
  .step-head .pick {
    margin-left: auto; max-width: 52%;
    font-size: 13px; font-weight: 600; color: var(--teal-deep);
    white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
  }
  .step.is-done .step-head { color: var(--muted); font-weight: 500; }
  .step.is-done .step-head .n { background: var(--teal-soft); color: var(--teal-deep); }

  /* กล่องยืนยันก่อนลบ */
  .confirm {
    border: 0; padding: 22px 22px 18px; border-radius: 14px;
    width: min(92vw, 380px);
    box-shadow: 0 30px 70px -24px rgba(17, 24, 39, .5);
  }
  .confirm::backdrop { background: rgba(17, 24, 39, .55); }
  .confirm h3 { margin: 0 0 6px; font-size: 16px; font-weight: 700; }
  .confirm p { margin: 0; font-size: 13.5px; color: var(--muted); }
  /* ยกเลิกซ้าย · ปุ่มยืนยันขวาเสมอ */
  .confirm-actions { display: flex; flex-direction: row; justify-content: flex-end; gap: 9px; margin-top: 20px; }
  .confirm-actions .btn { order: 2; }
  .confirm-actions .btn-quiet { order: 1; }
  .btn-danger { background: var(--danger); border-color: var(--danger); }
  .btn-danger:hover:not(:disabled) { background: #93251e; border-color: #93251e; }

  .opts { display: flex; flex-direction: column; gap: 4px; padding: 2px 10px 12px; }
  .opt {
    display: block; width: 100%; text-align: left;
    padding: 10px 13px; border: 1px solid var(--line); border-radius: 10px;
    background: var(--surface); font-size: 14px; cursor: pointer;
    transition: background .16s var(--ease), border-color .16s var(--ease);
  }
  .opt:hover { background: var(--teal-soft); border-color: var(--teal); }

  .picker .search { padding: 2px 16px 10px; }
  .picker .search input {
    width: 100%; min-height: 40px; padding: 0 13px;
    border: 1px solid var(--line); border-radius: 10px; font-size: 14px;
  }
  .picker .search input::placeholder { color: var(--muted); }
  .picker .search input:focus {
    outline: none; border-color: var(--teal); box-shadow: 0 0 0 3px rgba(12, 163, 154, .16);
  }
  .picker-list { max-height: 46vh; overflow-y: auto; padding: 0 10px 12px; }
  .picker-list button {
    display: flex; align-items: center; gap: 11px; width: 100%; text-align: left;
    padding: 8px 10px; border: 0; border-radius: 9px; background: transparent; cursor: pointer;
    transition: background .14s var(--ease);
  }
  .picker-list button:hover { background: var(--surface-soft); }
  .picker-list .pic { width: 32px; height: 32px; border-radius: 50%; overflow: hidden; flex: none; background: var(--surface-soft); }
  .picker-list .pic img { width: 100%; height: 100%; object-fit: cover; display: block; }
  .picker-list .info { min-width: 0; flex: 1; }
  .picker-list .info b { display: block; font-size: 13.5px; font-weight: 600;
    white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
  .picker-list .info span { display: block; font-size: 12px; color: var(--muted);
    white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
  .picker-list .msg { padding: 26px 12px; text-align: center; font-size: 13.5px; color: var(--muted); }

  /* ── สิทธิ์ตามตำแหน่ง ────────────────────────────────── */
  .perm-bar { display: flex; gap: 9px; flex-wrap: wrap; align-items: center; margin-bottom: 12px; }
  .perm-bar input[type=search] {
    flex: 1 1 220px; max-width: 300px; min-height: 36px; padding: 0 12px;
    background: var(--surface); border: 1px solid var(--line); border-radius: 9px; font-size: 14px;
  }
  .perm-bar input::placeholder { color: var(--muted); }
  .perm-bar input:focus {
    outline: none; border-color: var(--teal); box-shadow: 0 0 0 3px rgba(12, 163, 154, .16);
  }
  .perm-bar .link {
    background: none; border: 0; padding: 4px 2px;
    font-size: 13px; color: var(--teal-ink); font-weight: 600; cursor: pointer;
  }
  .perm-bar .link:hover { text-decoration: underline; }

  .perm-list {
    display: grid; grid-template-columns: repeat(auto-fill, minmax(255px, 1fr)); gap: 1px;
    max-height: 400px; overflow-y: auto;
    padding: 4px; margin: 0 -4px;
    border: 1px solid var(--line-soft); border-radius: 10px;
  }
  .perm {
    display: flex; align-items: center; gap: 10px;
    padding: 7px 10px; border-radius: 8px; cursor: pointer;
    transition: background .14s var(--ease);
  }
  .perm:hover { background: var(--surface-soft); }
  .perm input { width: 16px; height: 16px; flex: none; accent-color: var(--teal-ink); cursor: pointer; }
  .perm .t {
    flex: 1; min-width: 0; font-size: 14px;
    white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
  }
  .perm .t em { font-style: normal; color: var(--muted); }
  .perm .c { font-size: 12.5px; color: var(--muted); }
  .perm.is-hidden { display: none; }

  .perm-foot {
    display: flex; align-items: center; justify-content: space-between;
    gap: 12px; flex-wrap: wrap; margin-top: 14px;
  }
  .perm-foot .hint { font-size: 13px; color: var(--muted); }

  /* ── ส่วนพนักงาน ─────────────────────────────────────── */

  .meta {
    display: flex; align-items: center; gap: 8px 18px; flex-wrap: wrap;
    margin-bottom: 14px; font-size: 13.5px; color: var(--muted);
  }
  .meta .dot {
    display: inline-block; width: 7px; height: 7px; border-radius: 50%;
    background: var(--ok); margin-right: 7px; vertical-align: 1px;
  }
  .meta .dot-off { background: var(--danger); }
  .meta b { color: var(--ink); font-weight: 600; }

  .toolbar { display: flex; gap: 9px; flex-wrap: wrap; align-items: center; margin-bottom: 14px; }
  .toolbar input[type=search], .toolbar select {
    min-height: 38px; padding: 0 12px;
    background: var(--surface); border: 1px solid var(--line); border-radius: 9px; font-size: 14px;
  }
  .toolbar input[type=search] { flex: 1 1 240px; max-width: 320px; }
  .toolbar input::placeholder { color: var(--muted); }
  .toolbar input:focus, .toolbar select:focus {
    outline: none; border-color: var(--teal); box-shadow: 0 0 0 3px rgba(12, 163, 154, .16);
  }

  .table-wrap { overflow-x: auto; border: 1px solid var(--line-soft); border-radius: 10px; }
  table { width: 100%; border-collapse: collapse; font-size: 14px; }
  thead th {
    text-align: left; white-space: nowrap; padding: 11px 14px;
    font-size: 12.5px; font-weight: 600; color: var(--muted);
    background: var(--surface-soft); border-bottom: 1px solid var(--line);
  }
  tbody td { padding: 11px 14px; border-bottom: 1px solid var(--line-soft); vertical-align: middle; }
  tbody tr:last-child td { border-bottom: 0; }
  tbody tr { transition: background .14s var(--ease); }
  tbody tr:hover { background: var(--surface-soft); }
  td.code { font-variant-numeric: tabular-nums; font-weight: 600; white-space: nowrap; }
  td.cut { max-width: 230px; }

  .who { display: flex; align-items: center; gap: 10px; }
  .who .pic {
    width: 30px; height: 30px; border-radius: 50%; flex: none; overflow: hidden;
    background: var(--surface-soft);
  }
  .who .pic img { width: 100%; height: 100%; object-fit: cover; display: block; }
  .who .en { font-size: 12px; color: var(--muted); }

  .sub-note { display: block; margin-top: 2px; font-size: 12px; color: var(--warn); }
  .empty { padding: 46px 20px; text-align: center; color: var(--muted); }

  .pager {
    display: flex; align-items: center; justify-content: space-between;
    gap: 14px; flex-wrap: wrap; margin-top: 13px; font-size: 13px; color: var(--muted);
  }
  .pager nav { display: flex; gap: 5px; flex-wrap: wrap; }
  .pager a, .pager span.pg {
    display: inline-grid; place-items: center; min-width: 32px; height: 32px; padding: 0 9px;
    border: 1px solid var(--line); border-radius: 8px; color: var(--ink-soft);
    transition: background .14s var(--ease);
  }
  .pager a:hover { background: var(--surface-soft); }
  .pager .is-cur { background: var(--teal-ink); border-color: var(--teal-ink); color: #fff; font-weight: 600; }
  .pager .is-off { opacity: .4; }

@endsection

@section('content')

  {{-- ─────────── 1) กำหนดเส้นทางเอกสาร ─────────── --}}
  @php
    $roles = \App\Models\DocumentRole::ROLES;
    $short = \App\Models\DocumentRole::SHORT;
    $last = $chain->count() - 1;

    // กล่องไหนเพิ่งทำงานไป ให้กางค้างไว้รอบเดียว (flash หมดอายุเอง -> refresh แล้วพับ)
    $justUsed = session('open');
  @endphp

  <details class="acc" @if ($justUsed === 'route') open @endif>
    <summary>
      <svg class="chev" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
        <path d="m9 18 6-6-6-6"/>
      </svg>

      <span class="sum">
        กำหนดเส้นทาง

        {{-- สรุปย่อว่าเลือกอะไรไปบ้าง เห็นได้โดยไม่ต้องกางกล่อง --}}
        @if ($flow !== [])
          <span class="sum-line">
            @foreach ($flow as $i => $s)
              @if ($i > 0)
                <svg class="sep" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                  <path d="m9 18 6-6-6-6"/>
                </svg>
              @endif
              <span class="chip">
                <i class="n">{{ $s['no'] }}</i>
                <i class="role">{{ $s['label'] }}</i>
                <i class="act">{{ $s['verb'] }}</i>
              </span>
            @endforeach
          </span>
        @else
          <span class="sum-line empty">ยังไม่ได้กำหนดเส้นทาง</span>
        @endif
      </span>

      <span class="note num">{{ number_format($chain->count()) }} ขั้น</span>
    </summary>

    <div class="acc-body">
      @if ($flow !== [])
        <ol class="route-map" aria-label="สรุปเส้นทางเอกสาร">
          @foreach ($flow as $s)
            <li>
              <span class="dot">{{ $s['no'] }}</span>
              <b>{{ $s['label'] }}</b>
              <span class="act">{{ $s['verb'] }}</span>
            </li>
          @endforeach
        </ol>
      @endif

      <div class="chain-bar">
        <button type="button" class="btn btn-quiet" id="addStep">
          <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round">
            <path d="M12 5v14M5 12h14"/>
          </svg>
          เพิ่มขั้น
        </button>
      </div>

      <div class="chain">
        @foreach ($chain as $i => $step)
          @if ($i > 0)
            <span class="link" aria-hidden="true">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round">
                <path d="M5 12h14"/><path d="m13 6 6 6-6 6"/>
              </svg>
            </span>
          @endif

          @php
            $isUserStep = $step->isUserStep();
            $duties = \App\Models\DocumentRole::dutiesFor($step->role);
            $stepDesc = 'ขั้นที่ ' . ($i + 1) . ' · ' . $short[$step->role] . ' ' . $step->dutyLabel();
          @endphp

          <div class="node">
            <div class="node-top">
              <span class="node-no">{{ $i + 1 }}</span>

              <form method="POST" action="{{ route('admin.settings.role.update') }}" class="node-selects">
                @csrf
                <input type="hidden" name="id" value="{{ $step->id }}">

                <select name="role" onchange="this.form.submit()" aria-label="Role ของขั้นที่ {{ $i + 1 }}">
                  @foreach ($roles as $key => $label)
                    <option value="{{ $key }}" @selected($step->role === $key)>{{ $short[$key] }}</option>
                  @endforeach
                </select>

                @if (count($duties) > 1)
                  <select name="duty" onchange="this.form.submit()" aria-label="การดำเนินการของขั้นที่ {{ $i + 1 }}">
                    @foreach ($duties as $key => $label)
                      <option value="{{ $key }}" @selected($step->duty === $key)>{{ $label }}</option>
                    @endforeach
                  </select>
                @else
                  <span class="node-duty">{{ $step->dutyLabel() }}</span>
                @endif
              </form>
            </div>

            <div class="node-people">
              @if ($isUserStep)
                {{-- ขั้น User = ช่องว่างที่เว้นไว้ ไม่ต้องระบุตัวคนตอนตั้งค่า --}}
                <div class="slot">
                  <span class="pic">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round">
                      <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/>
                    </svg>
                  </span>
                  ผู้ขอซื้อ
                </div>
              @else
                @forelse ($step->members as $m)
                  <div class="member">
                    {{-- กดรูปแล้วซูมดูหน้าพนักงานได้ (D-077) --}}
                    <button type="button" class="pic avatar-zoom" data-avatar-zoom="{{ $m->avatarUrl() }}"
                            data-avatar-name="{{ $m->displayName() }}" data-avatar-code="{{ $m->employeeCode() }}"
                            aria-label="ดูรูปของ {{ $m->displayName() }}">
                      <img src="{{ $m->avatarUrl() }}" alt="" loading="lazy">
                    </button>
                    <span class="who" title="{{ $m->displayName() }} · {{ $m->employeeCode() }}">
                      <b class="nm">{{ $m->displayName() }}</b>
                      <span class="code num">{{ $m->employeeCode() }}</span>
                    </span>
                    <button type="button" class="x" data-delmember="{{ $m->id }}"
                            data-desc="{{ $m->displayName() }} · {{ $m->employeeCode() }} — {{ $stepDesc }}"
                            aria-label="เอา {{ $m->displayName() }} ออกจากขั้นนี้">
                      <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round">
                        <path d="M18 6 6 18M6 6l12 12"/>
                      </svg>
                    </button>
                  </div>
                @empty
                  <p class="no-member">ยังไม่มีพนักงาน</p>
                @endforelse

                <button type="button" class="add-member" data-addmember="{{ $step->id }}"
                        aria-label="เพิ่มพนักงานเข้าขั้นที่ {{ $i + 1 }}">
                  <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round">
                    <path d="M12 5v14M5 12h14"/>
                  </svg>
                  เพิ่มพนักงาน
                </button>
              @endif
            </div>

            <div class="node-tools">
              <form method="POST" action="{{ route('admin.settings.role.move') }}">
                @csrf
                <input type="hidden" name="id" value="{{ $step->id }}">
                <input type="hidden" name="direction" value="up">
                <button type="submit" aria-label="เลื่อนขั้นที่ {{ $i + 1 }} ไปทางซ้าย" @disabled($i === 0)>
                  <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="m15 18-6-6 6-6"/>
                  </svg>
                </button>
              </form>

              <button type="button" class="del" data-del="{{ $step->id }}" data-desc="{{ $stepDesc }}"
                      aria-label="ลบขั้นที่ {{ $i + 1 }} ออกจากเส้นทาง">
                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round">
                  <path d="M18 6 6 18M6 6l12 12"/>
                </svg>
              </button>

              <form method="POST" action="{{ route('admin.settings.role.move') }}">
                @csrf
                <input type="hidden" name="id" value="{{ $step->id }}">
                <input type="hidden" name="direction" value="down">
                <button type="submit" aria-label="เลื่อนขั้นที่ {{ $i + 1 }} ไปทางขวา" @disabled($i === $last)>
                  <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="m9 18 6-6-6-6"/>
                  </svg>
                </button>
              </form>
            </div>
          </div>
        @endforeach

        @if ($chain->isEmpty())
          <p class="chain-empty">ยังไม่มีขั้นในเส้นทาง</p>
        @endif
      </div>

    </div>
  </details>

  {{-- ยืนยันก่อนลบการ์ด --}}
  <dialog class="confirm" id="confirmDel">
    <form method="POST" id="confirmDelForm">
      @csrf
      <input type="hidden" name="id" id="confirmDelId">
      <input type="hidden" name="member" id="confirmDelMember">

      <h3 id="confirmDelTitle"></h3>
      <p id="confirmDelDesc"></p>

      <div class="confirm-actions">
        <button type="button" class="btn btn-quiet" id="confirmDelCancel">ยกเลิก</button>
        <button type="submit" class="btn btn-danger">ลบออก</button>
      </div>
    </form>
  </dialog>

  {{-- กล่องเลือกคน — ใช้ทั้งตอนเพิ่มการ์ดใหม่และตอนเปลี่ยนคนในการ์ดเดิม --}}
  <dialog class="picker" id="picker">
    <form method="POST" id="pickerForm">
      @csrf
      <input type="hidden" name="id" id="pickerStep">
      <input type="hidden" name="role" id="pickerRole">

      <input type="hidden" name="duty" id="pickerDuty">

      <header>
        <b id="pickerTitle"></b>
        <button type="button" id="pickerClose" aria-label="ปิด">
          <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round">
            <path d="M18 6 6 18M6 6l12 12"/>
          </svg>
        </button>
      </header>

      <div class="steps">
        {{-- 1) เลือก Role --}}
        <section class="step" id="stepRole">
          <button type="button" class="step-head" data-goto="role">
            <span class="n">1</span>
            เลือก Role
            <span class="pick" id="pickRole"></span>
          </button>
          <div class="opts">
            @foreach ($roles as $key => $label)
              <button type="button" class="opt" data-role="{{ $key }}">{{ $label }}</button>
            @endforeach
          </div>
        </section>

        {{-- 2) เลือกการดำเนินการ (JS เติมตาม Role ที่เลือก) --}}
        <section class="step" id="stepDuty" hidden>
          <button type="button" class="step-head" data-goto="duty">
            <span class="n">2</span>
            เลือกการดำเนินการ
            <span class="pick" id="pickDuty"></span>
          </button>
          <div class="opts" id="optsDuty"></div>
        </section>

        {{-- 3) เลือกพนักงาน (Role User ไม่มีขั้นนี้) --}}
        <section class="step" id="stepPerson" hidden>
          <div class="step-head is-plain" id="stepPersonHead">
            <span class="n">3</span>
            เลือกพนักงาน
          </div>
          <div class="search">
            <input type="search" id="pickerSearch" placeholder="ค้นหา ชื่อ · รหัส · ตำแหน่ง" autocomplete="off">
          </div>
          <div class="picker-list" id="pickerList"></div>
        </section>
      </div>
    </form>
  </dialog>

  {{-- ─────────── 2) สิทธิ์เข้าใช้งานระบบ ─────────── --}}
  @php
    $allowedNow = $accessConfigured
      ? $positions->keys()->filter(fn ($p) => (bool) $access->get($p, false))->count()
      : $positions->count();
  @endphp

  <details class="acc" @if ($justUsed === 'access') open @endif>
    <summary>
      <svg class="chev" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
        <path d="m9 18 6-6-6-6"/>
      </svg>
      สิทธิ์เข้าใช้งานระบบ
      <span class="note">อนุญาต <span id="permCount" class="num">{{ number_format($allowedNow) }}</span> จาก {{ number_format($positions->count()) }} ตำแหน่ง</span>
    </summary>

    <div class="acc-body">
      <form method="POST" action="{{ route('admin.settings.access') }}" id="permForm">
        @csrf

        <div class="perm-bar">
          <input type="search" id="permSearch" placeholder="ค้นหาตำแหน่ง" aria-label="ค้นหาตำแหน่ง">
          <button type="button" class="link" data-bulk="1">เลือกทั้งหมด</button>
          <button type="button" class="link" data-bulk="0">ล้างทั้งหมด</button>
        </div>

        <div class="perm-list" id="permList">
          @forelse ($positions as $position => $total)
            <label class="perm" data-name="{{ mb_strtolower($position) }}">
              <input type="checkbox" name="positions[]" value="{{ $position }}"
                     @checked($accessConfigured ? (bool) $access->get($position, false) : true)>
              <span class="t">
                @if ($position === '')
                  <em>ไม่ระบุตำแหน่ง</em>
                @else
                  {{ $position }}
                @endif
              </span>
              <span class="c num">{{ number_format($total) }}</span>
            </label>
          @empty
            <p class="empty" style="grid-column:1/-1">ยังไม่มีบัญชีในระบบ — ซิงค์ข้อมูลจาก Insight ก่อน</p>
          @endforelse
        </div>

        <div class="perm-foot">
          <span class="hint">ผู้ดูแลระบบเข้าได้เสมอ</span>
          <button type="submit" class="btn" @disabled($positions->isEmpty())>บันทึกสิทธิ์</button>
        </div>
      </form>
    </div>
  </details>

  {{-- ─────────── 3) พนักงาน ─────────── --}}
  @php
    // กางเมื่อ: เพิ่งกดซิงค์ · หรือกำลังค้นหา/อยู่หน้าที่ 2+ (ไม่งั้นผลลัพธ์จะถูกซ่อน)
    $employeesOpen = $justUsed === 'employees'
      || $filters['q'] !== '' || $filters['company'] !== '' || $filters['status'] !== '' || request('page');
  @endphp

  <details class="acc" @if ($employeesOpen) open @endif>
    <summary>
      <svg class="chev" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
        <path d="m9 18 6-6-6-6"/>
      </svg>
      พนักงาน
      <span class="note num">{{ number_format($counts['employees']) }} คน</span>
    </summary>

    <div class="acc-body">
      <div class="meta">
        <span>
          <span class="dot {{ $connected ? '' : 'dot-off' }}"></span>
          {{ $connected ? 'เชื่อมต่อ Insight' : 'ต่อ Insight ไม่ได้' }}
        </span>

        <span>ทำงาน <b class="num">{{ number_format($counts['active']) }}</b> · ลาออก <b class="num">{{ number_format($counts['resigned']) }}</b></span>

        <span>บัญชีล็อกอิน <b class="num">{{ number_format($counts['accounts']) }}</b></span>

        @if ($source && ($source['employees'] !== $counts['employees'] || $source['app_users'] !== $counts['accounts']))
          <span class="pill pill-warn">ต้นทางไม่ตรง — กดซิงค์</span>
        @endif

        @if ($lastSync)
          <span>ซิงค์ล่าสุด {{ $lastSync->ran_at?->format('d/m/Y H:i') }}</span>
        @endif
      </div>


      <div class="toolbar">
        <form class="toolbar" method="GET" action="{{ route('admin.settings') }}" style="margin:0;flex:1 1 auto">
          <input type="search" name="q" value="{{ $filters['q'] }}" placeholder="ค้นหา รหัส · ชื่อ · แผนก · ตำแหน่ง" aria-label="ค้นหาพนักงาน">

          <select name="company" aria-label="กรองตามบริษัท">
            <option value="">ทุกบริษัท</option>
            @foreach ($companies as $c)
              <option value="{{ $c }}" @selected($filters['company'] === $c)>{{ $c }}</option>
            @endforeach
          </select>

          <select name="status" aria-label="กรองตามสถานะ">
            <option value="">ทุกสถานะ</option>
            <option value="active" @selected($filters['status'] === 'active')>ทำงาน</option>
            <option value="resigned" @selected($filters['status'] === 'resigned')>ลาออก</option>
          </select>

          <button type="submit" class="btn btn-quiet">ค้นหา</button>
          @if ($filters['q'] || $filters['company'] || $filters['status'])
            <a href="{{ route('admin.settings') }}" class="btn btn-quiet">ล้าง</a>
          @endif
        </form>

        <a class="btn btn-quiet" id="editInsight"
           href="{{ rtrim(config('app.insight_url'), '/').'/dashboard' }}"
           target="_blank" rel="noopener noreferrer" title="แก้ไขข้อมูลพนักงานที่ Insight">
          <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
            <path d="M15 3h6v6M10 14 21 3"/><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/>
          </svg>
          แก้ไขที่ Insight
        </a>

      </div>

      <div class="table-wrap">
        <table>
          <thead>
            <tr>
              <th style="width:90px">รหัส</th>
              <th>ชื่อ-สกุล</th>
              <th>ตำแหน่ง</th>
              <th>แผนก</th>
              <th style="width:110px">บริษัท</th>
              <th style="width:105px">สถานะ</th>
            </tr>
          </thead>
          <tbody>
            @forelse ($employees as $emp)
              <tr>
                <td class="code">{{ $emp->employee_code }}</td>

                <td class="cut">
                  <div class="who">
                    @include('avatar', [
                      'url' => $emp->avatarUrl(),
                      'class' => 'pic',
                      'zoomable' => (bool) $emp->appUser?->profilePictureUrl(),
                      'alt' => $emp->fullNameTh() ?: $emp->employee_code,
                      'lazy' => true,
                    ])
                    <div style="min-width:0">
                      <div>
                        {{ $emp->fullNameTh() ?: '—' }}
                        @if ($emp->appUser?->isAdmin())
                          <span class="pill pill-teal" style="margin-left:4px">admin</span>
                        @endif
                      </div>
                      <div class="en">{{ $emp->fullNameEn() ?: '—' }}</div>
                    </div>
                  </div>
                </td>

                <td class="cut">{{ $emp->job_th ?: ($emp->job_en ?: '—') }}</td>
                <td class="cut">{{ $emp->deptThClean() ?: ($emp->dept_en ?: '—') }}</td>
                <td style="font-size:13px;color:var(--ink-soft)">{{ $emp->company }}</td>

                <td>
                  @if ($emp->isResigned())
                    <span class="pill pill-off">ลาออก</span>
                  @else
                    <span class="pill pill-ok">ทำงาน</span>
                    @unless ($emp->appUser)
                      <span class="sub-note">ไม่มีบัญชี</span>
                    @endunless
                  @endif
                </td>
              </tr>
            @empty
              <tr>
                <td colspan="6" class="empty">
                  {{ $counts['employees'] === 0 ? 'ยังไม่มีข้อมูล — กดปุ่มซิงค์เพื่อดึงจาก Insight' : 'ไม่พบพนักงานที่ค้นหา' }}
                </td>
              </tr>
            @endforelse
          </tbody>
        </table>
      </div>

      @if ($employees->hasPages())
        <div class="pager">
          <div>
            {{ number_format($employees->firstItem() ?? 0) }}–{{ number_format($employees->lastItem() ?? 0) }}
            จาก {{ number_format($employees->total()) }}
          </div>
          {{ $employees->onEachSide(1)->links('pagination') }}
        </div>
      @endif
    </div>
  </details>

  {{-- กดรูปพนักงานในการ์ดกำหนดเส้นทางเพื่อดูรูปใหญ่ (D-077) --}}
  @include('partials.avatar-zoom')

@endsection

@section('scripts')
<script>
  'use strict';

  // ── กล่องเลือกคน (ใช้ทั้งเพิ่มการ์ดใหม่และเปลี่ยนคนในการ์ดเดิม) ──
  var picker = document.getElementById('picker');
  var pickerForm = document.getElementById('pickerForm');
  var pickerStep = document.getElementById('pickerStep');
  var pickerRole = document.getElementById('pickerRole');
  var pickerDuty = document.getElementById('pickerDuty');
  var pickerTitle = document.getElementById('pickerTitle');
  var pickerSearch = document.getElementById('pickerSearch');
  var pickerList = document.getElementById('pickerList');
  var stepRole = document.getElementById('stepRole');
  var stepDuty = document.getElementById('stepDuty');
  var stepPerson = document.getElementById('stepPerson');
  var stepPersonHead = document.getElementById('stepPersonHead');
  var optsDuty = document.getElementById('optsDuty');
  var searchTimer = null;

  var ADD_URL = '{{ route('admin.settings.role.add') }}';
  var MEMBER_ADD_URL = '{{ route('admin.settings.member.add') }}';
  var ROLE_USER = '{{ \App\Models\DocumentRole::ROLE_USER }}';
  var DUTIES = @json(\App\Models\DocumentRole::DUTIES);
  var ROLE_LABELS = @json(\App\Models\DocumentRole::ROLES);

  // ── ปุ่ม "เพิ่ม" -> เลือกทีละขั้น 1 → 2 → 3 ──────────────
  document.getElementById('addStep').addEventListener('click', function () {
    pickerForm.action = ADD_URL;
    pickerStep.value = '';
    pickerRole.value = '';
    pickerDuty.value = '';
    pickerTitle.textContent = 'เพิ่มขั้นในเส้นทาง';
    stepPersonHead.hidden = false;
    openStep('role');
    picker.showModal();
  });

  // ปุ่ม "เพิ่มพนักงาน" ในการ์ด -> ข้ามขั้น 1-2 ไปเลือกคนอย่างเดียว
  document.querySelectorAll('[data-addmember]').forEach(function (btn) {
    btn.addEventListener('click', function () {
      pickerForm.action = MEMBER_ADD_URL;
      pickerStep.value = btn.dataset.addmember;
      pickerRole.value = '';
      pickerDuty.value = '';
      pickerTitle.textContent = 'เพิ่มพนักงานเข้าขั้นนี้';

      stepRole.hidden = true;
      stepDuty.hidden = true;
      stepPersonHead.hidden = true;
      openPersonStep();
      picker.showModal();
    });
  });

  // 1) เลือก Role
  stepRole.querySelectorAll('.opt').forEach(function (opt) {
    opt.addEventListener('click', function () {
      pickerRole.value = opt.dataset.role;
      pickerDuty.value = '';
      openStep('duty');
    });
  });

  // 2) เลือกการดำเนินการ — Role User จบแค่นี้ ไม่ต้องเลือกคน
  optsDuty.addEventListener('click', function (e) {
    var opt = e.target.closest('.opt');
    if (!opt) { return; }

    pickerDuty.value = opt.dataset.duty;

    if (pickerRole.value === ROLE_USER) {
      pickerForm.submit();
      return;
    }
    openStep('person');
  });

  // กดหัวข้อขั้นที่ทำไปแล้ว = ย้อนกลับไปแก้
  document.querySelectorAll('button.step-head').forEach(function (head) {
    head.addEventListener('click', function () { openStep(head.dataset.goto); });
  });

  /** เปิดขั้นที่ระบุ — ขั้นก่อนหน้าพับเหลือแค่หัวข้อ + สิ่งที่เลือกไว้ */
  function openStep(step) {
    stepRole.hidden = false;
    stepDuty.hidden = step === 'role';
    stepPerson.hidden = step !== 'person';

    setStep(stepRole, step === 'role', pickerRole.value ? ROLE_LABELS[pickerRole.value] : '');
    setStep(stepDuty, step === 'duty', dutyLabel());

    if (step === 'duty') { renderDuties(); }
    if (step === 'person') { openPersonStep(); }
  }

  function openPersonStep() {
    stepPerson.hidden = false;
    pickerSearch.value = '';
    loadPeople('');
    pickerSearch.focus();
  }

  /** พับ/กางหนึ่งขั้น แล้วโชว์ค่าที่เลือกไว้ข้างหัวข้อ */
  function setStep(section, isOpen, picked) {
    section.classList.toggle('is-done', !isOpen && picked !== '');
    section.querySelector('.opts').hidden = !isOpen;
    section.querySelector('.pick').textContent = isOpen ? '' : picked;
  }

  function dutyLabel() {
    return pickerDuty.value ? ((DUTIES[pickerRole.value] || {})[pickerDuty.value] || '') : '';
  }

  function renderDuties() {
    var list = DUTIES[pickerRole.value] || {};
    optsDuty.innerHTML = '';

    Object.keys(list).forEach(function (key) {
      var btn = document.createElement('button');
      btn.type = 'button';
      btn.className = 'opt';
      btn.dataset.duty = key;
      btn.textContent = list[key];
      optsDuty.appendChild(btn);
    });
  }

  document.getElementById('pickerClose').addEventListener('click', function () { picker.close(); });

  // ── ยืนยันก่อนลบ (ใช้ทั้งลบทั้งขั้น และลบพนักงานออกจากขั้น) ──
  var confirmDel = document.getElementById('confirmDel');
  var confirmDelForm = document.getElementById('confirmDelForm');
  var confirmDelId = document.getElementById('confirmDelId');
  var confirmDelMember = document.getElementById('confirmDelMember');
  var confirmDelTitle = document.getElementById('confirmDelTitle');
  var confirmDelDesc = document.getElementById('confirmDelDesc');

  var STEP_DEL_URL = '{{ route('admin.settings.role.remove') }}';
  var MEMBER_DEL_URL = '{{ route('admin.settings.member.remove') }}';

  function askDelete(action, title, desc, stepId, memberId) {
    confirmDelForm.action = action;
    confirmDelId.value = stepId || '';
    confirmDelMember.value = memberId || '';
    confirmDelTitle.textContent = title;
    confirmDelDesc.textContent = desc;
    confirmDel.showModal();
  }

  // ลบทั้งขั้น
  document.querySelectorAll('[data-del]').forEach(function (btn) {
    btn.addEventListener('click', function () {
      askDelete(STEP_DEL_URL, 'ลบขั้นนี้ออกจากเส้นทาง?', btn.dataset.desc, btn.dataset.del, '');
    });
  });

  // ลบพนักงานคนเดียวออกจากขั้น
  document.querySelectorAll('[data-delmember]').forEach(function (btn) {
    btn.addEventListener('click', function () {
      askDelete(MEMBER_DEL_URL, 'เอาพนักงานคนนี้ออกจากขั้น?', btn.dataset.desc, '', btn.dataset.delmember);
    });
  });

  document.getElementById('confirmDelCancel').addEventListener('click', function () { confirmDel.close(); });
  confirmDel.addEventListener('click', function (e) {
    if (e.target === confirmDel) { confirmDel.close(); }
  });

  // คลิกพื้นหลังนอกกล่อง = ปิด
  picker.addEventListener('click', function (e) {
    if (e.target === picker) { picker.close(); }
  });

  // หน่วงไว้ 220ms กันยิง request ทุกตัวอักษร
  pickerSearch.addEventListener('input', function () {
    clearTimeout(searchTimer);
    searchTimer = setTimeout(function () { loadPeople(pickerSearch.value.trim()); }, 220);
  });

  // กด Enter ในช่องค้นหาไม่ต้อง submit ฟอร์ม (ต้องเลือกคนก่อน)
  pickerSearch.addEventListener('keydown', function (e) {
    if (e.key === 'Enter') { e.preventDefault(); }
  });

  function loadPeople(keyword) {
    pickerList.innerHTML = '<p class="msg">กำลังค้นหา</p>';

    var url = '{{ route('admin.settings.people') }}?q=' + encodeURIComponent(keyword);

    fetch(url, { headers: { 'Accept': 'application/json' } })
      .then(function (res) { return res.json(); })
      .then(function (data) { renderPeople(data.people || []); })
      .catch(function () {
        pickerList.innerHTML = '<p class="msg">โหลดรายชื่อไม่ได้ ลองใหม่อีกครั้ง</p>';
      });
  }

  function renderPeople(people) {
    if (people.length === 0) {
      pickerList.innerHTML = '<p class="msg">ไม่พบพนักงานที่ค้นหา</p>';
      return;
    }

    pickerList.innerHTML = '';

    people.forEach(function (p) {
      var btn = document.createElement('button');
      btn.type = 'submit';
      btn.name = 'id_thai_hash';
      btn.value = p.id_thai_hash;

      var pic = document.createElement('div');
      pic.className = 'pic';
      var img = document.createElement('img');
      img.src = p.avatar;
      img.alt = '';
      img.loading = 'lazy';
      pic.appendChild(img);

      var info = document.createElement('div');
      info.className = 'info';
      var name = document.createElement('b');
      name.textContent = p.name;
      var meta = document.createElement('span');
      meta.textContent = p.position + ' · ' + p.code + (p.department ? ' · ' + p.department : '');
      info.appendChild(name);
      info.appendChild(meta);

      btn.appendChild(pic);
      btn.appendChild(info);
      pickerList.appendChild(btn);
    });
  }

  // ── สิทธิ์ตามตำแหน่ง ────────────────────────────────────
  var permList = document.getElementById('permList');
  var permSearch = document.getElementById('permSearch');
  var permCount = document.getElementById('permCount');

  function permRows() {
    return Array.prototype.slice.call(permList.querySelectorAll('.perm'));
  }

  function refreshCount() {
    var n = permList.querySelectorAll('input[type=checkbox]:checked').length;
    permCount.textContent = n.toLocaleString();
  }

  // ค้นหา — ซ่อนแถวที่ไม่ตรง (ค่าที่ติ๊กไว้ยังถูกส่งไปตอนบันทึกเหมือนเดิม)
  permSearch.addEventListener('input', function () {
    var kw = permSearch.value.trim().toLowerCase();

    permRows().forEach(function (row) {
      var hit = kw === '' || row.dataset.name.indexOf(kw) !== -1;
      row.classList.toggle('is-hidden', !hit);
    });
  });

  // เลือกทั้งหมด / ล้างทั้งหมด — ทำเฉพาะแถวที่มองเห็นอยู่
  document.querySelectorAll('[data-bulk]').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var on = btn.dataset.bulk === '1';

      permRows().forEach(function (row) {
        if (row.classList.contains('is-hidden')) { return; }
        row.querySelector('input[type=checkbox]').checked = on;
      });
      refreshCount();
    });
  });

  permList.addEventListener('change', refreshCount);

  // ── ไม่มีปุ่มซิงค์แล้ว ─────────────────────────────────
  // Insight ยิงบอกทันทีที่มีการแก้ข้อมูล (ดู InsightWebhookController)
  // จึงเลิกทั้งปุ่มกดเอง · การซิงค์ตอนสลับแท็บกลับมา · และ Windows Task ที่รันทุก 15 นาที
</script>
@endsection
