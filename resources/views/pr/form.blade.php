@php
  $document_mode = $mode ?? 'purchase';
  $is_purchase_mode = $document_mode === 'purchase';
  $is_requester_mode = $document_mode === 'select';
  $is_negotiate_mode = $document_mode === 'negotiate';
  // ขั้นที่ 4 — ผู้ขอซื้อยืนยันราคาหลังต่อรอง เลือกเจ้าที่จะซื้อจริงได้อีกครั้ง (D-075)
  $is_confirm_mode = $document_mode === 'confirm';
  $is_approval_mode = $document_mode === 'approval';
  // ส่งให้ผู้ขอซื้อแล้วห้ามแก้เอกสารและห้ามแนบไฟล์ — เอกสารออกจากมือ Purchasing แล้ว
  // (ลบยังทำได้จนกว่าผู้ขอซื้อจะลงนาม ตาม D-044)
  $can_edit_document = $is_purchase_mode && $doc->status === 'draft';
  $is_readonly_mode = ! $can_edit_document;
  $selection_signature = ($signature_by_step ?? collect())->first(
    fn ($signature) => $signature->duty === 'select' && $signature->signed_at !== null
  );
  $can_send_to_negotiation = $is_requester_mode
    && $doc->status === 'sent_user'
    && $selection_signature !== null;
  $negotiation_signature = ($signature_by_step ?? collect())->first(
    fn ($signature) => $signature->duty === 'negotiate' && $signature->signed_at !== null
  );
  // ประทับลายเซ็นแล้วยังแก้ข้อมูลได้ ล็อกเมื่อกดปุ่มส่งสีเขียวเท่านั้น (D-058)
  $can_edit_negotiation = $is_negotiate_mode && $doc->status === 'negotiating';
  $can_send_to_approval = $is_negotiate_mode
    && $doc->status === 'negotiating'
    && $negotiation_signature !== null;
  $confirmation_signature = ($signature_by_step ?? collect())->first(
    fn ($signature) => $signature->duty === 'confirm' && $signature->signed_at !== null
  );
  $can_send_confirmation = $is_confirm_mode
    && $doc->status === 'confirming'
    && $confirmation_signature !== null;
  // เส้นทางที่ไม่มีขั้นยืนยันรายการ ต่อรองเสร็จแล้วส่งตรงไปลงนามเหมือนเดิม
  $has_confirm_step = $workflow_steps->contains(fn ($step) => $step->duty === 'confirm');
  // ช่องติ๊กเลือกผู้ขาย โผล่ 2 ขั้น — คัดเลือกรายการ (ขั้น 2) และยืนยันรายการ (ขั้น 4)
  $shows_supplier_picker = $is_requester_mode || $is_confirm_mode;
  $can_pick_supplier = ($is_requester_mode && $doc->status === 'sent_user' && ! $selection_signature)
    || ($is_confirm_mode && $doc->status === 'confirming' && ! $confirmation_signature);
  // เทาเจ้าที่ตกรอบเมื่อผู้ขอซื้อยืนยันแล้วเท่านั้น — ก่อนหน้านั้นต้องเห็นครบทั้ง 3 เจ้าเพื่อเทียบราคา
  $mute_unselected_suppliers = $doc->selectionLocked();
  /*
  | หัวข้อ 7 — ตอบ "แยกทีละบริษัท" ว่าต่อรองแล้วได้ใบเสนอราคาใหม่จากเจ้านั้นไหม
  |   null  = ยังไม่ตอบ -> ยังไม่เปิดช่องแนบของเจ้านั้น
  |   true  = มี        -> เปิดช่องแนบเฉพาะเจ้านั้น
  |   false = ไม่มี     -> ขึ้นข้อความแทนช่องแนบในการ์ดของเจ้านั้น
  | ค่าอยู่ที่ `pr_suppliers.has_negotiation_quote` (ย้ายมาจากระดับเอกสาร)
  */
  // Comment เป็นช่องของผู้ขอซื้อ — ฝ่ายจัดซื้อเห็นแต่แก้ไม่ได้ (D-055)
  $can_edit_comment = ($is_requester_mode && $doc->status === 'sent_user')
    || ($is_confirm_mode && $doc->status === 'confirming');
  $negotiation_stage_open = $is_negotiate_mode && $doc->status === 'negotiating';
  $can_manage_negotiation_files = $negotiation_stage_open;
  // เอกสารเก่าที่แนบไฟล์ไว้ก่อนมีปุ่มติ๊ก ให้ถือว่า "มี" ตามไฟล์ที่เห็นจริง
  $negotiation_files_exist = $doc->suppliers->contains(
    fn ($supplier) => $supplier->attachments->where('stage', \App\Models\PrAttachment::STAGE_NEGOTIATE)->isNotEmpty()
  );
  // มีบริษัทไหนตอบไปแล้วบ้างหรือยัง — ใช้ตัดสินว่าจะขึ้น "ยังไม่มีข้อมูล" ทั้งหัวข้อไหม
  $negotiation_answered = $doc->suppliers->contains(
    fn ($supplier) => $supplier->has_negotiation_quote !== null
  );
  /*
  | ย่อเอกสารตอนพิมพ์/สร้าง PDF ให้ลงกระดาษ A4 แนวนอน "หน้าเดียว"
  |
  | ความสูงของกระดาษ = ส่วนหัว/ท้ายคงที่ + จำนวนแถวสินค้า จึงคิดเป็นสูตร
  |
  | วัดจริงหลังขยายตัวอักษรเป็น 8pt และขยายช่องลายเซ็นแล้ว — ค่าที่ใหญ่สุดที่ยังลงหน้าเดียว:
  |   5 แถว = 72% · 10 แถว = 56% · 15 แถว = 48% · 20 แถว = 40%
  | ใส่เผื่อไว้ 1% กันใบที่รายละเอียดสินค้ายาวกว่าตอนวัด
  |
  | ฟอร์มแสดงอย่างน้อย 5 แถวเสมอ จึงเริ่มนับที่ 5
  | ไม่ย่อต่ำกว่า 32% เพราะจะอ่านไม่ออก (ใบที่ยาวมากยอมให้เป็น 2 หน้า)
  */
  $print_rows = max(5, $doc->items->count());
  $print_zoom = max(32, min(85, (int) floor(100 / (1.0714 + 0.07143 * $print_rows)) - 1));

  $back_route = match ($document_mode) {
    'select' => route('work.select'),
    'negotiate' => route('work.negotiate'),
    'confirm' => route('work.confirm'),
    'approval' => route('work.approval'),
    'download' => route('overview'),
    default => route('documents.index'),
  };
@endphp

@extends('layouts.portal')

@section('title', $doc->displayLabel() . ' · QuoteCompare')
@section('heading', $doc->displayLabel())

@section('page-actions')
  <div class="form-actions">
    <a href="{{ $back_route }}" class="btn btn-quiet">
      <svg viewBox="0 0 24 24" aria-hidden="true"><path d="m15 18-6-6 6-6"/></svg>
      กลับ
    </a>
    {{-- ลายเซ็นขั้นจัดทำเอกสารยังลบได้ ล็อกเมื่อผู้ขอซื้อลงนามคัดเลือกรายการ --}}
    @if ($is_purchase_mode && $doc->canDelete())
      <button type="button" class="btn btn-quiet icon-btn danger" id="delDocBtn" aria-label="ลบเอกสารนี้" title="ลบเอกสารนี้">
        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M3 6h18M8 6V4h8v2M19 6l-1 14H6L5 6M10 11v5M14 11v5"/></svg>
      </button>
    @endif
    @if ($can_reject)
      <button type="button" class="btn btn-quiet danger-text" id="rejectBtn">
        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M18 6 6 18M6 6l12 12"/></svg>
        ปฏิเสธ
      </button>
    @endif
    @if ($can_edit_document)
      <button type="submit" form="docForm" name="intent" value="draft" class="btn btn-quiet" formnovalidate>
        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2Z"/><path d="M17 21v-8H7v8M7 3v5h8"/></svg>
        บันทึกร่าง
      </button>
      <button type="button" class="btn btn-success" id="sendBtn">
        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="m22 2-7 20-4-9-9-4Z"/><path d="M22 2 11 13"/></svg>
        ส่งให้ผู้ขอซื้อ
      </button>
    @endif
    @if ($can_send_to_negotiation)
      <button type="button" class="btn btn-success" id="sendNegotiationBtn">
        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="m22 2-7 20-4-9-9-4Z"/><path d="M22 2 11 13"/></svg>
        ส่งต่อรองราคา
      </button>
    @endif
    @if ($can_edit_negotiation)
      <button type="submit" form="docForm" name="intent" value="save_revision" class="btn btn-quiet">
        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2Z"/><path d="M17 21v-8H7v8M7 3v5h8"/></svg>
        บันทึกราคา Rev.1
      </button>
    @endif
    @if ($can_send_to_approval)
      <button type="button" class="btn btn-success" id="sendApprovalBtn">
        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="m22 2-7 20-4-9-9-4Z"/><path d="M22 2 11 13"/></svg>
        {{ $has_confirm_step ? 'ส่งให้ผู้ขอซื้อยืนยัน' : 'ส่งลงนามอนุมัติ' }}
      </button>
    @endif
    @if ($can_send_confirmation)
      <button type="button" class="btn btn-success" id="sendConfirmationBtn">
        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="m22 2-7 20-4-9-9-4Z"/><path d="M22 2 11 13"/></svg>
        ส่งลงนามอนุมัติ
      </button>
    @endif
    @if ($can_send_approval ?? false)
      {{-- ประทับลายเซ็นแล้วต้องกดยืนยันอีกครั้ง เอกสารถึงเดินหน้า (D-070) --}}
      <button type="button" class="btn btn-success" id="approveBtn">
        @if ($approval_is_final ?? false)
          <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M20 6 9 17l-5-5"/></svg>
          อนุมัติเอกสาร
        @else
          <svg viewBox="0 0 24 24" aria-hidden="true"><path d="m22 2-7 20-4-9-9-4Z"/><path d="M22 2 11 13"/></svg>
          ส่งให้ CEO ลงนาม
        @endif
      </button>
    @endif
  </div>
@endsection

@section('styles')
  .page { width: min(1640px, 100%); }
  .form-actions { display: flex; gap: 8px; flex-wrap: wrap; }
  .form-actions .btn svg {
    width: 16px; height: 16px; fill: none; stroke: currentColor;
    stroke-width: 2; stroke-linecap: round; stroke-linejoin: round;
  }
  .form-actions .icon-btn { width: 40px; padding: 0; }
  .form-actions .icon-btn.danger { color: var(--danger); }
  .form-actions .icon-btn.danger:hover { border-color: #e7b7b2; background: var(--danger-soft); }
  .form-actions .danger-text { color: var(--danger); }
  .btn.btn-success { color: #fff; background: var(--ok); border-color: var(--ok); }
  .btn.btn-success:hover { color: #fff; background: #237a4e; border-color: #237a4e; }
  .form-actions .danger-text:hover { border-color: #e7b7b2; background: var(--danger-soft); }
  .readonly-mode .purchase-only { display: none !important; }
  .validation-alert {
    margin-bottom: 16px; padding: 12px 14px; border: 1px solid #e7b7b2; border-radius: 8px;
    background: var(--danger-soft); color: #8e2a23; font-size: 13px;
  }
  .validation-alert b { display: block; margin-bottom: 4px; }
  .validation-alert ul { margin: 0; padding-left: 20px; }
  .is-invalid { outline: 2px solid var(--danger) !important; outline-offset: -2px; background: #fff5f4 !important; }
  .purchase-signature-invalid { border: 2px solid var(--danger); border-radius: 8px; background: #fff5f4; }

  /* ── ขั้นตอนจัดทำเอกสาร ────────────────────────────────── */
  .form-step { padding: 2px 0 22px; }
  .form-step + .form-step { padding-top: 20px; border-top: 1px solid var(--line); }
  .attachment-step { padding-top: 20px; border-top: 1px solid var(--line); }

  /* หัวข้อที่เป็นของขั้นอื่น — เบลอไว้แล้ววางแม่กุญแจคาดกลางการ์ด */
  .files-wrap { position: relative; }
  .files-wrap.is-locked .files { opacity: .32; filter: grayscale(1); pointer-events: none; user-select: none; }
  .is-locked-step .step-head { opacity: .6; }
  .files-lock {
    position: absolute; inset: 0; display: flex; flex-direction: column;
    align-items: center; justify-content: center; gap: 8px; pointer-events: none;
  }
  .files-lock svg {
    width: 34px; height: 34px; padding: 7px; box-sizing: content-box;
    color: var(--muted); background: var(--surface); border-radius: 50%;
    box-shadow: 0 6px 18px -8px rgba(17,24,39,.45);
    fill: none; stroke: currentColor; stroke-width: 1.7; stroke-linecap: round; stroke-linejoin: round;
  }
  .files-lock b {
    padding: 4px 12px; background: var(--surface); border-radius: 999px;
    box-shadow: 0 6px 18px -8px rgba(17,24,39,.45);
    color: var(--ink-soft); font-size: 13px; font-weight: 700;
  }
  .step-locked-note.is-info { border-style: solid; }

  /* หัวข้อ 7 — ถามทีละบริษัทว่ามีใบเสนอราคาหลังต่อรองไหม (อยู่ในการ์ดของแต่ละเจ้า) */
  .quote-ask { display: flex; gap: 6px; margin: 0 0 9px; }
  /* ยังไม่ได้ตอบ มี/ไม่มี แล้วกดส่ง — เน้นแดงเฉพาะการ์ดที่ยังไม่ตอบ */
  .is-invalid-step .file-card.is-unanswered .quote-opt { border-color: var(--danger); color: var(--danger); background: #fff7f6; }
  .is-invalid-step .step-head h2 { color: var(--danger); }
  .quote-opt {
    min-height: 30px; padding: 0 13px; cursor: pointer;
    background: var(--surface); border: 1px solid var(--line); border-radius: 8px;
    font-size: 12.5px; font-weight: 600; color: var(--ink-soft);
  }
  .quote-opt:hover { border-color: var(--teal); color: var(--teal-deep); }
  .quote-opt.is-on { background: var(--teal); border-color: var(--teal); color: #fff; }
  .step-locked-note {
    display: flex; align-items: center; gap: 8px;
    margin: -6px 0 14px; padding: 9px 12px;
    background: var(--surface-soft); border: 1px dashed var(--line);
    border-radius: 8px; color: var(--muted); font-size: 12.5px;
  }
  .step-locked-note svg {
    width: 15px; height: 15px; flex: none;
    fill: none; stroke: currentColor; stroke-width: 1.8; stroke-linecap: round; stroke-linejoin: round;
  }
  .step-head { display: flex; align-items: center; gap: 10px; min-height: 34px; margin-bottom: 13px; }
  .step-number {
    display: grid; place-items: center; width: 28px; height: 28px; flex: none;
    border-radius: 50%; background: var(--teal-ink); color: #fff;
    font-size: 12px; font-weight: 700; font-variant-numeric: tabular-nums;
  }
  .step-head h2 { margin: 0; font-size: 15px; font-weight: 700; letter-spacing: 0; }
  .step-controls { display: flex; align-items: center; gap: 12px; min-height: 40px; padding-left: 38px; }
  .step-controls .hint { margin-left: auto; color: var(--muted); font-size: 12.5px; }

  .item-count-field {
    width: 72px; height: 38px; padding: 0 8px;
    border: 1px solid var(--line); border-radius: 8px; background: var(--surface);
    text-align: center; font-size: 14px; font-weight: 700; appearance: textfield;
  }
  .item-count-field::-webkit-inner-spin-button,
  .item-count-field::-webkit-outer-spin-button { margin: 0; appearance: none; }
  .item-count-field:focus { outline: 2px solid var(--teal); outline-offset: -2px; background: var(--teal-soft); }
  .count-unit { font-size: 13.5px; color: var(--ink-soft); }

  .sup-pick { display: flex; gap: 6px; }
  .sup-pick label {
    display: inline-flex; align-items: center; gap: 6px;
    padding: 5px 12px; border: 1px solid var(--line); border-radius: 999px;
    cursor: pointer; transition: background .16s var(--ease), border-color .16s var(--ease);
  }
  .sup-pick label:hover { background: var(--surface-soft); }
  .sup-pick input { accent-color: var(--teal-ink); }
  .sup-pick input:checked + span { font-weight: 700; color: var(--teal-deep); }

  /* ── เส้นทางเอกสาร ─────────────────────────────────────── */
  .document-route {
    display: flex; align-items: flex-start; margin: 0; padding: 16px 8px 5px;
    overflow-x: auto; list-style: none; background: var(--surface-soft); border-radius: 8px;
  }
  .document-route li {
    position: relative; flex: 1 0 170px; min-width: 150px;
    padding: 0 8px 12px; text-align: center;
  }
  .document-route li::before {
    content: ''; position: absolute; top: 17px; left: -50%; right: calc(50% + 24px);
    height: 2px; background: #c8d6d5;
  }
  .document-route li::after {
    content: ''; position: absolute; top: 12px; right: calc(50% + 18px);
    border-left: 7px solid #c8d6d5; border-top: 6px solid transparent; border-bottom: 6px solid transparent;
  }
  .document-route li:first-child::before, .document-route li:first-child::after { display: none; }
  .route-dot {
    position: relative; z-index: 1; display: grid; place-items: center;
    width: 36px; height: 36px; margin: 0 auto 8px; border-radius: 50%;
    background: var(--teal-ink); color: #fff; box-shadow: 0 0 0 4px var(--surface-soft);
    font-size: 12px; font-weight: 700;
  }
  .route-role { display: block; font-size: 12.5px; font-weight: 700; line-height: 1.3; }
  .route-duty { display: block; margin-top: 2px; font-size: 11.5px; color: var(--muted); }
  .route-people { display: flex; justify-content: center; flex-wrap: wrap; gap: 5px; margin-top: 9px; }
  .route-person {
    display: inline-flex; align-items: center; gap: 5px; max-width: 160px;
    padding: 3px 7px 3px 3px; border: 1px solid var(--line); border-radius: 999px;
    background: var(--surface); font-size: 10.5px; color: var(--ink-soft);
  }
  button.route-person { font: inherit; cursor: pointer; }
  button.route-person:hover { border-color: var(--teal); background: var(--teal-soft); color: var(--teal-deep); }
  button.route-person:focus-visible { outline: 2px solid var(--teal); outline-offset: 2px; }
  .route-person img, .route-person .person-placeholder, .route-person .avatar-zoom {
    width: 21px; height: 21px; flex: none; border-radius: 50%; object-fit: cover;
  }
  /* รูปในการ์ดถูกครอบด้วยปุ่มซูม — ตัวรูปจึงเต็มปุ่มพอดี (D-077) */
  .route-person .avatar-zoom { overflow: hidden; }
  .route-person .avatar-zoom img { width: 100%; height: 100%; object-fit: cover; }

  /* การ์ดผู้ขอซื้อ — รูปกดซูม ชื่อกดเลือกคน แยกปุ่มกันแต่ดูเป็นชิ้นเดียว (D-078) */
  .requester-card { padding-right: 3px; }
  /* ⚠️ ต้องมีบรรทัดนี้ ไม่งั้นไอคอนคนกับรูปโปรไฟล์โผล่พร้อมกันเป็น 2 วง —
     `display: grid` ของ .person-placeholder มี specificity สูงกว่า attribute `hidden` */
  .route-person [hidden] { display: none !important; }
  .requester-pick {
    min-width: 0; padding: 0; border: 0; background: transparent;
    color: inherit; font: inherit; text-align: left;
    overflow: hidden; text-overflow: ellipsis; white-space: nowrap; cursor: pointer;
  }
  .requester-pick:disabled { cursor: default; }
  .requester-card:has(.requester-pick:not(:disabled)):hover {
    border-color: var(--teal); background: var(--teal-soft); color: var(--teal-deep);
  }
  .requester-pick:focus-visible { outline: 2px solid var(--teal); outline-offset: 2px; border-radius: 4px; }
  .route-person .person-placeholder { display: grid; place-items: center; background: var(--teal-soft); color: var(--teal-deep); }
  .route-person .person-placeholder svg { width: 12px; height: 12px; }
  .route-person span:last-child { min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }

  /* ── เครื่องมือซูม ─────────────────────────────────────── */
  .document-step .step-head { margin-bottom: 10px; }
  .zoom-tools { display: inline-flex; align-items: center; gap: 3px; margin-left: auto; }
  .zoom-tools button {
    display: grid; place-items: center; width: 32px; height: 32px;
    border: 1px solid var(--line); border-radius: 7px; background: var(--surface); color: var(--ink-soft); cursor: pointer;
  }
  .zoom-tools button:hover:not(:disabled) { background: var(--surface-soft); color: var(--teal-deep); }
  .zoom-tools button:disabled { opacity: .35; cursor: default; }
  .zoom-tools svg { width: 15px; height: 15px; fill: none; stroke: currentColor; stroke-width: 2.2; stroke-linecap: round; }
  .zoom-value { width: 48px; text-align: center; font-size: 12.5px; font-weight: 700; font-variant-numeric: tabular-nums; }

  /* ── กระดาษเอกสาร อ้างอิง Excel A1:M26 ───────────────── */
  .sheet-scroll {
    overflow: auto; padding: 18px; background: #e9eded;
    border: 1px solid #d7dddd; border-radius: 8px;
    box-shadow: var(--shadow-sm);
  }
  .sheet {
    width: 1520px; min-width: 1520px; margin: 0 auto; overflow: visible; zoom: 70%;
    background: #fff; border: 1px solid #9aa1a3;
    color: #111; font-family: Arial, Tahoma, "Leelawadee UI", sans-serif;
  }
  .sheet-head {
    position: relative; display: grid; grid-template-columns: 40.3% 59.7%;
    min-height: 102px; padding: 10px 14px 8px;
  }
  .sheet-head img {
    position: absolute; left: 40.3%; top: 58%; transform: translate(-50%, -50%);
    width: 50px; height: 58px; object-fit: contain;
  }
  .sheet-head .th, .sheet-head .en {
    min-width: 0; font-size: 11px; line-height: 1.55; color: #202426;
  }
  .sheet-head .en { text-align: right; }
  .sheet-head b { display: block; font-size: 12px; color: #111; }

  .sheet-title {
    display: grid; grid-template-columns: 40.3% 59.7%; min-height: 34px;
    border-top: 1px solid #92999b; border-bottom: 1px solid #92999b;
  }
  .sheet-title > span {
    display: grid; place-items: center; padding: 4px 10px;
    font-size: 14px; font-weight: 700;
  }
  .sheet-title .tag { border-right: 1px solid #92999b; }

  .sheet-meta {
    display: grid; grid-template-columns: 40.3% 59.7%; min-height: 84px;
    border-bottom: 1px solid #92999b;
  }
  .meta-left { display: grid; align-content: center; gap: 2px; padding: 5px 14px; }
  .meta-right { display: flex; justify-content: flex-end; align-items: flex-start; padding: 5px 14px; }
  .fld { display: flex; align-items: baseline; gap: 9px; min-width: 0; }
  .fld > label { flex: none; font-size: 12px; font-weight: 700; color: #ef1717; }
  .fld .val { flex: 1; min-width: 0; }
  .fld.stacked-field { display: grid; gap: 3px; align-items: stretch; }
  .fld.stacked-field > label { width: 100%; }
  .fld.stacked-field .val { display: block; width: 100%; }

  .blank {
    width: 100%; padding: 1px 4px; font-size: 12px; color: #111;
    border: 0; border-bottom: 1px dotted #555; background: transparent;
    transition: border-color .16s var(--ease), background .16s var(--ease);
  }
  .blank:focus { outline: none; border-bottom-color: var(--teal); background: var(--teal-soft); }
  .blank::placeholder { color: #687477; }
  .multiline-field {
    display: block; width: 100%; min-height: 42px; resize: none; overflow: hidden;
    line-height: 1.4; white-space: pre-wrap; overflow-wrap: anywhere;
  }
  .fixed { font-size: 12px; padding: 1px 4px; color: #111; }

  .pick-person {
    display: flex; align-items: center; gap: 8px; width: 100%;
    padding: 1px 4px; border: 0; border-bottom: 1px dotted #555;
    background: transparent; cursor: pointer; text-align: left; font-size: 12px;
  }
  .pick-person:hover { background: var(--teal-soft); }
  .pick-person .dept { font-weight: 600; }
  .pick-person .who { color: #465154; font-size: 11px; }
  .pick-person .none { color: #687477; }

  /* ── ตารางเทียบราคา ────────────────────────────────────── */
  table.grid { width: 100%; min-width: 100%; table-layout: auto; border-collapse: collapse; font-size: 12px; }
  table.grid th, table.grid td { border: 1px solid #aeb3b5; padding: 0; }
  table.grid tr > :first-child { border-left: 0; }
  table.grid tr > :last-child { border-right: 0; }
  table.grid thead th {
    background: #fff; color: #111; font-size: 11px; font-weight: 700;
    text-align: center; padding: 5px 4px; line-height: 1.25;
  }
  /* "Total Amount (Baht)" ต้องอยู่บรรทัดเดียว ไม่ตัดกลางวงเล็บ */
  table.grid thead th[data-sup] { white-space: nowrap; }
  table.grid thead tr:first-child th { height: 38px; }
  table.grid thead tr:last-child th { height: 42px; }
  table.grid td { vertical-align: middle; }
  table.grid td.n { position: relative; text-align: center; color: #3f4749; font-variant-numeric: tabular-nums; }

  .supplier-head { position: relative; height: 100%; padding: 0 34px 0 6px; }
  .supplier-head .cell { text-align: center; font-weight: 700; }
  .supplier-check {
    position: absolute; right: 7px; top: 50%; transform: translateY(-50%);
    width: 24px; height: 24px; border: 1px solid #3b4142; background: #fff;
  }
  .supplier-check.is-checked::after {
    content: ''; position: absolute; left: 6px; top: 2px;
    width: 8px; height: 14px; border: solid #111; border-width: 0 2px 2px 0;
    transform: rotate(45deg);
  }
  .supplier-select-control {
    position: absolute; right: 7px; top: 50%; transform: translateY(-50%);
    display: flex; align-items: center; gap: 5px; cursor: pointer;
  }
  .supplier-select-control input { position: absolute; opacity: 0; pointer-events: none; }
  .supplier-select-control .supplier-check { position: relative; inset: auto; transform: none; display: block; }
  .supplier-select-control em { font-size: 10px; font-style: normal; font-weight: 700; color: #475356; }
  .supplier-select-control input:focus-visible + .supplier-check { outline: 2px solid var(--teal); outline-offset: 2px; }
  .supplier-select-control input:checked + .supplier-check::after {
    content: ''; position: absolute; left: 6px; top: 2px;
    width: 8px; height: 14px; border: solid #111; border-width: 0 2px 2px 0; transform: rotate(45deg);
  }
  .supplier-select-control.is-invalid { padding: 3px; outline-offset: 2px; }
  .readonly-mode .sheet :disabled { opacity: 1; color: #111; -webkit-text-fill-color: #111; }
  .readonly-mode .rm, .readonly-mode .item-code-clear, .readonly-mode .item-code-open { display: none !important; }

  .cell { width: 100%; height: 100%; border: 0; background: transparent; padding: 6px 7px; font-size: 12px; }
  .cell:focus { outline: none; background: var(--teal-soft); }
  .item-code-control { display: flex; align-items: stretch; min-width: 172px; height: 100%; }
  .item-code-control .item-code-input { flex: 1 0 132px; min-width: 132px; }
  .item-code-input { cursor: pointer; }
  .item-code-input:focus { background: var(--teal-soft); }
  /* ปลดล็อกให้พิมพ์รหัสใหม่เอง */
  .item-code-input:not([readonly]) { cursor: text; background: var(--teal-soft); }
  .item-code-open, .item-code-clear {
    flex: 0 0 22px; display: grid; place-items: center; min-height: 26px;
    border: 0; background: transparent; color: #7d8688; cursor: pointer;
  }
  .item-code-clear { display: none; }
  .item-code-control.has-code .item-code-clear { display: grid; }
  .item-code-clear:hover { color: var(--danger); background: var(--danger-soft); }
  .item-code-clear svg { width: 11px; height: 11px; fill: none; stroke: currentColor; stroke-width: 2.8; stroke-linecap: round; }
  .item-code-open:hover, .item-code-open[aria-expanded="true"] { color: var(--teal-deep); background: var(--teal-soft); }
  .item-code-open svg { width: 13px; height: 13px; fill: none; stroke: currentColor; stroke-width: 2.4; stroke-linecap: round; }
  .description-cell {
    display: block; min-height: 48px; resize: none; overflow: hidden;
    line-height: 1.4; white-space: pre-wrap; overflow-wrap: anywhere;
  }
  .cell.num-in { text-align: right; font-variant-numeric: tabular-nums; appearance: textfield; }
  .cell.num-in::-webkit-inner-spin-button,
  .cell.num-in::-webkit-outer-spin-button { margin: 0; appearance: none; }
  .qty-cell { display: flex; align-items: center; }
  .qty-cell .cell { min-width: 0; }
  .qty-cell .qty { flex: 0 0 auto; }
  .qty-cell .unit {
    flex: 0 0 auto; width: 48px; min-width: 48px;
    border-left: 1px dotted #aeb3b5; text-align: center;
  }

  .item-row { min-height: 48px; }
  .placeholder-row { height: 48px; }
  .placeholder-row td { background: #fff; }
  table.grid td.amount {
    padding: 6px 12px; text-align: right; white-space: nowrap; background: #fff;
    font-variant-numeric: tabular-nums; color: #252b2c;
  }
  table.grid td.rev { padding: 0; text-align: center; white-space: nowrap; color: #6e787a; }
  .rev-price { color: #111; }

  /* ── ช่องที่ยังต้องกรอก ──
     เหลืองไว้ให้เห็นว่ายังขาด · กรอกแล้วกลับเป็นขาวเหมือนช่องอื่น
     ใช้ทุกแท็บที่มีช่องให้กรอก (จัดทำเอกสาร · ผู้ขอซื้อ · ต่อรองราคา) */
  .needs-fill,
  input.needs-fill,
  textarea.needs-fill,
  button.needs-fill { background: #fff9cc; }
  .needs-fill:focus { background: #fff3a3; }
  .amount-base, .amount-rev, .supplier-total-rev { display: block; }
  .amount-rev, .supplier-total-rev {
    margin-top: 3px; color: #566164; font-size: 10.5px; font-weight: 600;
  }

  tfoot td { height: 58px; background: #fff; font-weight: 700; }
  /* หมายเหตุ VAT — อยู่ในช่องว่างซ้ายมือของแถวยอดรวม เห็นทั้งบนจอและตอนพิมพ์ */
  .total-note { vertical-align: top !important; padding: 5px 8px !important; }
  .total-note span {
    display: block; color: #c0281f; font-size: 11.5px; font-weight: 700; text-align: left;
  }
  .supplier-total { background: #fff900 !important; padding: 5px 7px !important; }
  /* ใช้ `>` เพราะข้างในมี <span data-currency-label> ซ้อนอยู่
     ถ้าจับ span ทุกตัว ตัว Baht จะกลายเป็น block แล้วตกบรรทัด */
  .supplier-total > span { display: block; font-size: 10.5px; text-align: left; white-space: nowrap; }
  .supplier-total [data-currency-label] { display: inline; }
  .supplier-total strong {
    display: block; margin-top: 1px; font-size: 12px; text-align: right;
    white-space: nowrap; font-variant-numeric: tabular-nums;
  }
  .supplier-total-rev {
    display: block; text-align: right; white-space: nowrap; font-variant-numeric: tabular-nums;
  }
  /* มีราคาต่อรองแล้ว -> Rev.1 คือตัวเลขที่ต้องอ่าน ราคาเดิมลดความเด่นลงเป็นตัวอ้างอิง */
  .supplier-total.has-rev strong {
    font-size: 10.5px; font-weight: 500; color: #6b6f71; text-decoration: line-through;
  }
  .supplier-total.has-rev .supplier-total-rev {
    margin-top: 1px; font-size: 14px; font-weight: 800; color: #111;
  }

  .rm {
    position: absolute; left: 50%; top: 50%; transform: translate(-50%, -50%);
    display: grid; place-items: center; width: 25px; height: 25px;
    border: 0; border-radius: 6px; background: transparent; color: #b3bebf; cursor: pointer;
    opacity: 0; pointer-events: none;
  }
  .item-row:hover .row-no { opacity: 0; }
  .item-row:hover .rm, .rm:focus-visible { opacity: 1; pointer-events: auto; }
  .rm:hover { background: var(--danger-soft); color: var(--danger); }

  .is-off { background: repeating-linear-gradient(45deg, #f4f6f6, #f4f6f6 6px, #eef1f1 6px, #eef1f1 12px); }
  .is-off * { opacity: .28; pointer-events: none; }
  [data-sup].supplier-selection-muted {
    color: #7d8588; background: #eceff0 !important; opacity: .42;
    filter: grayscale(1); transition: opacity .16s var(--ease), background .16s var(--ease);
  }

  /* ── ท้ายฟอร์ม ─────────────────────────────────────────── */
  .terms-grid {
    display: grid; grid-template-columns: 40.3% repeat(3, 19.9%);
    min-height: 126px; border-bottom: 1px solid #aeb3b5;
  }
  .terms-spacer { border-right: 1px solid #aeb3b5; }
  .supplier-terms { display: grid; grid-template-rows: repeat(3, minmax(58px, auto)); border-right: 1px solid #aeb3b5; }
  .supplier-terms:last-child { border-right: 0; }
  .term-row { display: grid; grid-template-columns: 1fr; align-items: stretch; border-bottom: 1px solid #c6cacc; }
  .term-row:last-child { border-bottom: 0; }
  .term-row label { padding: 5px 7px 1px; font-size: 11px; font-weight: 700; }
  .term-row .cell { width: 100%; min-width: 0; min-height: 38px; padding: 3px 7px 6px; }

  .comment-box { min-height: 58px; padding: 12px 14px; border-bottom: 1px solid #aeb3b5; }
  .comment-box .fld > label { color: #111; }

  /* จำนวนช่องลายเซ็น = จำนวนขั้นในเส้นทางจริง (ตอนนี้ 6 ขั้น) ห้าม hardcode */
  .signature-grid {
    display: grid; grid-template-columns: repeat({{ max(1, $workflow_steps->count()) }}, minmax(0, 1fr));
    min-height: 144px; align-items: end; padding: 15px 20px 14px;
  }
  .signature { position: relative; min-width: 0; padding: 0 9px; text-align: center; font-size: 11px; }
  .signature-line {
    position: relative; display: flex; align-items: flex-end; justify-content: center;
    height: 54px; border-bottom: 2px dotted #202526;
  }
  .signature-image { display: block; max-width: 100%; max-height: 50px; object-fit: contain; }
  /* ช่องประทับลายเซ็น — ทำหน้าตาเหมือนช่องที่ยังต้องกรอก (เหลือง) พร้อมข้อความกลางช่อง
     กดแล้วเปิด modal ยืนยันเหมือนเดิม */
  .signature-stamp {
    display: flex; align-items: center; justify-content: center;
    width: 100%; height: 40px; margin-bottom: 4px;
    border: 1px solid #e0d38a; border-radius: 6px;
    background: #fff9cc; color: #6b5a12;
    font-size: 11px; font-weight: 700; letter-spacing: .02em; cursor: pointer;
    transition: background-color .18s ease, border-color .18s ease;
  }
  .signature-stamp:hover { background: #fff3a3; border-color: #cbb95f; }
  .signature-stamp:focus-visible { outline: 2px solid var(--teal); outline-offset: 2px; }
  .signature-remove svg { width: 13px; height: 13px; fill: none; stroke: currentColor; stroke-width: 2.2; stroke-linecap: round; stroke-linejoin: round; }
  .signature-remove {
    position: absolute; right: 8px; top: -4px; display: grid; place-items: center;
    width: 24px; height: 24px; border: 0; border-radius: 6px;
    background: #fff; color: var(--danger); cursor: pointer;
  }
  .signature-remove:hover { background: var(--danger-soft); }
  /* ชื่อ Role ยาวสุดคือ "Asst.Mgr.Purchasing / Mgr.Purchasing" — เผื่อไว้ 3 บรรทัดในช่องแคบ */
  .signature-role { min-height: 48px; padding-top: 5px; font-weight: 700; line-height: 1.25; }
  .signature-duty { min-height: 16px; margin-top: 1px; color: var(--ink-soft); line-height: 1.25; }
  .signature-date { margin-top: 5px; white-space: nowrap; }

  /* ── ไฟล์แนบ ───────────────────────────────────────────── */
  .files { display: grid; gap: 14px; grid-template-columns: repeat(auto-fit, minmax(260px, 1fr)); }
  .file-card {
    background: var(--surface); border: 1px solid var(--line);
    border-radius: 8px; box-shadow: var(--shadow-sm); padding: 14px 15px;
  }
  /* การ์ดที่ยังไม่แนบใบเสนอราคา — เน้นแดงให้เห็นว่าต้องแนบก่อนส่ง */
  .file-card.is-invalid { border-color: var(--danger); background: #fff7f6; outline: 0 !important; }
  .file-card.is-invalid .file-none { color: var(--danger); font-weight: 600; }
  .file-card h3 { margin: 0 0 11px; font-size: 13.5px; font-weight: 700; overflow-wrap: anywhere; }
  .file-row {
    display: flex; align-items: center; gap: 8px; padding: 6px 7px;
    border-radius: 8px; font-size: 12.5px;
  }
  .file-row:hover { background: var(--surface-soft); }

  /* กรอบไอคอน/ภาพย่อ — กดแล้วเปิดแท็บใหม่ */
  .file-open {
    display: block; width: 38px; height: 38px; flex: none;
    border-radius: 8px; overflow: hidden;
    transition: transform .16s var(--ease), box-shadow .16s var(--ease);
  }
  .file-open:hover { transform: scale(1.06); }
  .file-open.is-image { border: 1px solid var(--line); background: var(--surface-soft); }
  .file-open.is-image:hover { border-color: var(--teal); box-shadow: 0 3px 10px rgba(17, 24, 39, .16); }
  .file-open img { width: 100%; height: 100%; object-fit: cover; display: block; }

  /* ไอคอนเอกสาร — ไฟล์ไอคอนจริงคนละสัดส่วน จึงใส่ในกรอบเท่ากันแล้ว contain ให้เท่ากันทุกอัน */
  .file-row .ext {
    display: flex; align-items: center; justify-content: center; gap: 1px;
    flex-direction: column; width: 100%; height: 100%; padding: 3px;
  }
  .file-row .ext img { width: 100%; height: 100%; object-fit: contain; display: block; }
  .file-row .ext svg { width: 17px; height: 17px; }
  .file-row .ext i {
    font-style: normal; font-size: 8px; font-weight: 700;
    letter-spacing: .02em; text-transform: uppercase; line-height: 1;
  }
  .ext-file { background: var(--surface-soft); color: #5b6672; }

  .file-row .file-name { flex: 1; min-width: 0; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
  .file-row .file-name:hover { color: var(--teal-ink); text-decoration: underline; }
  .file-row .kb { font-size: 11.5px; color: var(--muted); white-space: nowrap; }
  .file-row .dl, .file-row .x {
    display: grid; place-items: center; width: 24px; height: 24px; flex: none;
    border: 0; border-radius: 6px; background: transparent; color: #a9b4b5; cursor: pointer;
  }
  .file-row .dl:hover { background: var(--teal-soft); color: var(--teal-deep); }
  .file-row .x:hover { background: var(--danger-soft); color: var(--danger); }
  .file-none { padding: 8px 7px; font-size: 12.5px; color: var(--muted); }
  /* บริษัทที่ฝ่ายจัดซื้อตอบว่าไม่มีใบเสนอราคาหลังต่อรอง — ขึ้นข้อความแทนช่องแนบ */
  .file-note {
    margin: 0; padding: 9px 11px; border-radius: 8px;
    background: var(--surface-soft); border: 1px dashed var(--line);
    font-size: 12.5px; color: var(--muted);
  }
  .file-add { margin-top: 9px; }
  .file-add input[type=file] { width: 100%; font-size: 12px; }
  .file-add input[type=file]::file-selector-button {
    min-height: 34px; margin-right: 9px; padding: 0 11px;
    border: 1px solid var(--line); border-radius: 7px;
    background: var(--surface-soft); color: var(--ink-soft); cursor: pointer;
  }
  .file-add input[type=file]::file-selector-button:hover { border-color: #c3ced0; background: var(--teal-soft); }
  .file-add button { margin-top: 8px; width: 100%; }


  /* ── Modal ประทับลายเซ็น ───────────────────────────────── */
  .signature-dialog {
    width: min(92vw, 440px); border: 0; border-radius: 10px; padding: 0;
    box-shadow: 0 30px 70px -24px rgba(17,24,39,.5);
  }
  .signature-dialog::backdrop { background: rgba(17,24,39,.55); }
  .signature-dialog header { display: flex; align-items: center; gap: 10px; padding: 15px 16px; border-bottom: 1px solid var(--line); }
  .signature-dialog header b { flex: 1; font-size: 15px; }
  .signature-dialog .dialog-close {
    display: grid; place-items: center; width: 30px; height: 30px; border: 0; border-radius: 7px;
    background: transparent; color: var(--muted); cursor: pointer;
  }
  .signature-dialog .dialog-close:hover { background: var(--surface-soft); color: var(--ink); }
  .signature-dialog .dialog-body { padding: 18px 18px 4px; }
  .signature-preview {
    display: grid; place-items: center; min-height: 110px; padding: 12px;
    border: 1px solid var(--line); border-radius: 8px; background: #fff;
  }
  .signature-preview img { display: block; max-width: 100%; max-height: 90px; object-fit: contain; }
  .signature-preview .missing { font-size: 13px; color: var(--danger); }
  .signature-dialog .signer { margin: 11px 0 0; text-align: center; font-size: 13px; color: var(--muted); }
  /* ยกเลิกอยู่ซ้าย · ปุ่มยืนยันอยู่ขวาเสมอ — บังคับด้วย order กันสลับตามลำดับใน markup */
  .signature-dialog .dialog-actions { display: flex; flex-direction: row; justify-content: flex-end; gap: 8px; padding: 16px 18px 18px; }
  .signature-dialog .dialog-actions .btn { order: 2; }
  .signature-dialog .dialog-actions .btn-quiet { order: 1; }
  .send-confirm-copy { margin: 0; color: var(--ink-soft); font-size: 13.5px; line-height: 1.65; }
  .send-confirm-copy strong { color: var(--ink); }

  @media (max-width: 900px) {
    .step-controls { padding-left: 0; flex-wrap: wrap; }
    .step-controls .hint { width: 100%; margin-left: 0; }
    .form-actions { width: 100%; }
    .form-actions .btn { flex: 1; min-width: max-content; }
    .form-actions .icon-btn { flex: 0 0 40px; min-width: 40px; }
    .document-route li { flex-basis: 150px; }
  }

  @media print {
    @page { size: A4 landscape; margin: 6mm; }
    body { background: #fff; }
    .side, .page-head, .flash-page, .form-step, .scrim, dialog { display: none !important; }
    .document-step { display: block !important; padding: 0; border: 0 !important; }
    .document-step .step-head { display: none !important; }
    .main, .page { width: 100%; margin: 0; padding: 0; }
    .sheet-scroll { overflow: visible; padding: 0; border: 0; border-radius: 0; box-shadow: none; }
    .sheet { min-width: 0; width: 100%; border-color: #555; zoom: {{ $print_zoom }}% !important; }
    .sheet-head { min-height: 56px; padding: 4px 7px; }
    .sheet-head img { width: 28px; height: 34px; }
    .sheet-head .th, .sheet-head .en { font-size: 8pt; line-height: 1.35; }
    .sheet-head b { font-size: 8.5pt; }
    .sheet-title { min-height: 20px; }
    .sheet-title > span { font-size: 9.5pt; padding: 2px 5px; }
    .sheet-meta { min-height: 45px; }
    .meta-left, .meta-right { padding: 2px 7px; }
    .fld > label, .blank, .fixed, .pick-person { font-size: 8pt; }
    table.grid, .term-row label, .signature { font-size: 8pt; }
    table.grid thead th { font-size: 7.5pt; padding: 2px; }
    table.grid thead tr:first-child th { height: 20px; }
    table.grid thead tr:last-child th { height: 23px; }
    table.grid td.amount, table.grid td.rev { padding: 2px 3px; }
    .item-row { min-height: 27px; break-inside: avoid; }
    .placeholder-row { height: 27px; }
    .description-cell { min-height: 27px; padding: 3px 4px; }
    tfoot td { height: 25px; }
    .supplier-total > span { font-size: 7.5pt; }
    .supplier-total strong { font-size: 8.5pt; }
    .terms-grid { min-height: 69px; }
    .supplier-terms { grid-template-rows: repeat(3, minmax(30px, auto)); }
    .term-row { grid-template-columns: 1fr; }
    .term-row label { padding: 2px 4px 0; }
    .term-row .cell, .multiline-field { min-height: 23px; padding: 1px 4px 2px; }
    .comment-box { min-height: 31px; padding: 5px 7px; }
    /* ลายเซ็นเป็นหลักฐานสำคัญ ให้ใหญ่กว่าส่วนอื่นชัดเจน */
    .signature-grid { min-height: 96px; padding: 8px 10px 5px; }
    .signature { padding: 0 6px; }
    .signature-line { height: 46px; }
    .signature-image { max-height: 44px; }
    .signature-role { min-height: 22px; padding-top: 2px; }
    .signature-duty { min-height: 12px; margin-top: 1px; }
    .signature-date { margin-top: 3px; }
    .rm, .item-code-open, .signature-stamp, .signature-remove { display: none !important; }
  }
@endsection

@section('content')

@php
  $suppliers = $doc->suppliers->keyBy('slot');
  $minimum_rows = 5;
  $placeholder_rows = max(0, $minimum_rows - $doc->items->count());
  $initial_item_count = max(1, $doc->items->count());
  $selected_supplier_slots = collect(old(
    'selected_suppliers',
    $doc->suppliers->where('is_selected', true)->pluck('slot')->all(),
  ))->map(fn ($slot) => (int) $slot)->all();
@endphp

@if ($errors->any())
  <div class="validation-alert" id="validationAlert" role="alert">
    <b>กรุณาตรวจสอบข้อมูลที่ทำเครื่องหมายสีแดง</b>
    <ul>
      @foreach ($errors->all() as $message)
        <li>{{ $message }}</li>
      @endforeach
    </ul>
  </div>
@else
  <div class="validation-alert" id="validationAlert" role="alert" hidden></div>
@endif

<form method="POST"
      action="{{ $is_requester_mode
        ? route('work.select.update', $doc)
        : ($is_purchase_mode
          ? route('documents.update', $doc)
          : ($is_negotiate_mode
            ? route('work.negotiate.update', $doc)
            : ($is_confirm_mode
              ? route('work.confirm.update', $doc)
              : ($is_approval_mode ? route('work.approval.update', $doc) : '#')))) }}"
      id="docForm" @class(['readonly-mode' => $is_readonly_mode]) novalidate>
  @csrf
  <input type="hidden" name="signature_step_id" id="signatureStepId">
  <input type="hidden" name="signature_id" id="signatureId">

  {{-- ── 1) จำนวนรายการสินค้า ───────────────────────────── --}}
  <section class="form-step purchase-only" data-view-anchor="items" aria-labelledby="item-count-title">
    <div class="step-head">
      <span class="step-number">1</span>
      <h2 id="item-count-title">จำนวนรายการสินค้า</h2>
    </div>
    <div class="step-controls">
      <input type="number" id="itemCount" name="item_count" class="item-count-field"
             min="1" max="50" value="{{ $initial_item_count }}" aria-label="จำนวนรายการสินค้า"
             @if (! $is_readonly_mode) data-required-fill @endif
             @disabled($is_readonly_mode)>
      <span class="count-unit">รายการ</span>
    </div>
  </section>

  {{-- ── 2) จำนวนผู้ขาย ─────────────────────────────────── --}}
  <section class="form-step purchase-only" data-view-anchor="suppliers" aria-labelledby="supplier-count-title">
    <div class="step-head">
      <span class="step-number">2</span>
      <h2 id="supplier-count-title">จำนวนผู้ขายที่นำมาเปรียบเทียบ</h2>
    </div>
    <div class="step-controls">
      <div class="sup-pick">
        @for ($n = 1; $n <= \App\Models\PrDocument::MAX_SUPPLIERS; $n++)
          <label>
            <input type="radio" name="supplier_count" value="{{ $n }}" @checked($doc->supplier_count === $n)
                   @disabled($is_readonly_mode)>
            <span>{{ $n }} บริษัท</span>
          </label>
        @endfor
      </div>
    </div>
  </section>

  {{-- ── 3) สกุลเงิน — กำกับ Total Amount ทั้งรายแถวและยอดรวม ── --}}
  <section class="form-step purchase-only" data-view-anchor="currency" aria-labelledby="currency-title">
    <div class="step-head">
      <span class="step-number">3</span>
      <h2 id="currency-title">สกุลเงิน</h2>
    </div>
    <div class="step-controls">
      <select name="currency" id="currencyPick" class="currency-pick" @disabled($is_readonly_mode)>
        @foreach (\App\Models\PrDocument::CURRENCIES as $code => $label)
          <option value="{{ $code }}" @selected(($doc->currency ?? 'THB') === $code)>
            {{ $code }} ({{ $label }})
          </option>
        @endforeach
      </select>
    </div>
  </section>

  {{-- ── 4) เส้นทางเอกสาร ───────────────────────────────── --}}
  <section class="form-step purchase-only" data-view-anchor="route" aria-labelledby="route-title">
    <div class="step-head">
      <span class="step-number">4</span>
      <h2 id="route-title">เส้นทางเอกสาร</h2>
    </div>

    <ol class="document-route" aria-label="เส้นทางเอกสารที่กำหนดในระบบ">
      @foreach ($workflow_steps as $step)
        <li @class(['requester-route-step' => $step->isUserStep()])>
          <span class="route-dot">{{ $loop->iteration }}</span>
          <span class="route-role">{{ $step->roleLabel() }}</span>
          <span class="route-duty">{{ $step->dutyLabel() }}</span>
          <div class="route-people">
            @if ($step->isUserStep())
              {{-- ขั้นของ Role User มีได้หลายขั้น (คัดเลือกรายการ · ยืนยันรายการ) และเป็น "คนเดียวกัน"
                   เสมอ เพราะผูกกับ requester ของใบนี้ — จึงใช้ data attribute ไม่ใช่ id (id ซ้ำไม่ได้)
                   กดรูป = ซูม · กดชื่อ = เลือกคน (D-078) --}}
              <span class="route-person requester-card" data-requester-card>
                @php $requester_photo = $requester_user?->profilePictureUrl(); @endphp
                <button type="button" class="avatar-zoom" data-requester-avatar
                        data-avatar-zoom="{{ $requester_user?->avatarUrl() }}"
                        data-avatar-name="{{ $requester_user?->displayName() }}"
                        data-avatar-code="{{ $requester_user?->employee_code }}"
                        aria-label="ดูรูปผู้ขอซื้อ" title="ดูรูปผู้ขอซื้อ"
                        @unless ($requester_photo) hidden @endunless>
                  <img src="{{ $requester_user?->avatarUrl() }}" alt="" data-requester-image loading="lazy">
                </button>

                <span class="person-placeholder" data-requester-placeholder @if ($requester_photo) hidden @endif>
                  <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                </span>

                <button type="button" class="requester-pick" data-requester-name
                        aria-label="เลือกพนักงานผู้ขอซื้อ" title="{{ $is_readonly_mode ? '' : 'เลือกพนักงานผู้ขอซื้อ' }}"
                        @disabled($is_readonly_mode)>
                  {{ $requester_user?->displayName() ?: ($doc->requester_name ?: 'ยังไม่เลือกผู้ขอซื้อ') }}
                </button>
              </span>
            @else
              @forelse ($step->members as $member)
                <span class="route-person" title="{{ $member->displayName() }} · {{ $member->employeeCode() }}">
                  {{-- กดรูปแล้วซูมดูหน้าพนักงานได้ (D-077) --}}
                  <button type="button" class="avatar-zoom" data-avatar-zoom="{{ $member->avatarUrl() }}"
                          data-avatar-name="{{ $member->displayName() }}" data-avatar-code="{{ $member->employeeCode() }}"
                          aria-label="ดูรูปของ {{ $member->displayName() }}">
                    <img src="{{ $member->avatarUrl() }}" alt="" loading="lazy">
                  </button>
                  <span>{{ $member->displayName() }}</span>
                </span>
              @empty
                <span class="route-person">
                  <span class="person-placeholder">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                  </span>
                  <span>ยังไม่มีพนักงาน</span>
                </span>
              @endforelse
            @endif
          </div>
        </li>
      @endforeach
    </ol>
  </section>

  {{-- ── 4) เอกสาร ──────────────────────────────────────── --}}
  <section class="form-step document-step" data-view-anchor="document" aria-labelledby="document-title">
    <div class="step-head">
      <span class="step-number">5</span>
      <h2 id="document-title">เอกสารเปรียบเทียบราคา</h2>
      <div class="zoom-tools" aria-label="ปรับขนาดเอกสาร">
        <button type="button" id="zoomOut" aria-label="ซูมออก" title="ซูมออก">
          <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="m20 20-4-4M8 11h6"/></svg>
        </button>
        <span class="zoom-value" id="zoomValue">70%</span>
        <button type="button" id="zoomIn" aria-label="ซูมเข้า" title="ซูมเข้า">
          <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="m20 20-4-4M11 8v6M8 11h6"/></svg>
        </button>
      </div>
    </div>

  <div class="sheet-scroll">
  <div class="sheet">
    {{-- หัวจดหมาย --}}
    <div class="sheet-head">
      <div class="th">
        <b>บริษัท สุภาวุฒิ อินดัสทรี จำกัด</b>
        44/2 หมู่ 8 ตำบลโป่ง อำเภอบางละมุง<br>
        จังหวัดชลบุรี 20150<br>
        โทรศัพท์ : 038-227-301-2 แฟกซ์ : 038-227-326<br>
        เลขประจำตัวผู้เสียภาษีอากร : 0-2055-46014-52-9
      </div>
      <img src="{{ asset('img/logo.png') }}" alt="">
      <div class="en">
        <b>SUPAVUT INDUSTRY CO., LTD.</b>
        44/2, Mu 8, Pong Sub-district,<br>
        Bang Lamung District, Chon Buri Province, 20150<br>
        Tel : 038-227-301-2 Fax: 038-227-326<br>
        Tax Number : 0-2055-46014-52-9
      </div>
    </div>

    <div class="sheet-title" aria-label="ชื่อเอกสาร">
      <span class="tag">Sourcing</span>
      <span class="mid">Comparing Suppliers for Approval.</span>
    </div>

    {{-- หัวเอกสาร --}}
    <div class="sheet-meta">
      <div class="meta-left">
        <div class="fld">
          <label>PR Number :</label>
          <span class="val fixed num">
            {{ $doc->displayLabel() }}
          </span>
        </div>

        <div class="fld">
          <label>Department Request :</label>
          <span class="val">
            <input type="hidden" name="requester_id_thai_hash" id="reqHash" value="{{ $doc->requester_id_thai_hash }}"
                   @disabled($is_readonly_mode)>
            <button type="button" @class(['pick-person', 'is-invalid' => $errors->has('requester_id_thai_hash')])
                    id="pickReq" @disabled($is_readonly_mode)>
              @if ($doc->requesterDepartmentLabel())
                <span class="dept">{{ $doc->requesterDepartmentLabel() }}</span>
                <span class="who">— {{ $doc->requester_name }}</span>
              @else
                <span class="none">เลือกพนักงานผู้ขอซื้อ</span>
              @endif
            </button>
          </span>
        </div>

        <div class="fld stacked-field">
          <label for="purpose">Purpose of the Purchase :</label>
          <span class="val">
            <textarea @class(['blank', 'multiline-field', 'is-invalid' => $errors->has('purpose')])
                      id="purpose" name="purpose" rows="2" maxlength="2000"
                      @if (! $is_readonly_mode) data-required-fill @endif
                      @disabled($is_readonly_mode)>{{ old('purpose', $doc->purpose) }}</textarea>
          </span>
        </div>
      </div>

      <div class="meta-right">
        <div class="fld">
          <label>Document Date :</label>
          <span class="val fixed">{{ $doc->document_date?->format('d/m/Y') }}</span>
        </div>
      </div>
    </div>

    {{-- ตารางเทียบราคา --}}
      <table class="grid" id="grid">
        <colgroup>
          <col style="width:2.3%">
          <col style="width:8.1%">
          <col style="width:22.8%">
          <col style="width:7.1%">
          @for ($s = 1; $s <= 3; $s++)
            <col style="width:5.95%"><col style="width:5.95%"><col style="width:8%">
          @endfor
        </colgroup>
        <thead>
          <tr>
            <th rowspan="2">No.</th>
            <th rowspan="2">Item Code</th>
            <th rowspan="2">Item Description</th>
            <th rowspan="2">Qty.</th>
            @for ($s = 1; $s <= 3; $s++)
              <th colspan="3" data-sup="{{ $s }}">
                <div class="supplier-head">
                  <input type="text" @class(['cell', 'is-invalid' => $errors->has("suppliers.{$s}.name")])
                         name="suppliers[{{ $s }}][name]" placeholder="Supplier Name"
                         aria-label="ชื่อผู้ขายที่ {{ $s }}"
                         @if (! $is_readonly_mode && $s <= $doc->supplier_count) data-required-fill @endif
                         value="{{ old("suppliers.{$s}.name", $suppliers[$s]->name ?? '') }}"
                         @disabled($is_readonly_mode)>
                  @if ($shows_supplier_picker)
                    {{-- เลือกได้เจ้าเดียวเท่านั้น จึงเป็น radio ไม่ใช่ checkbox (D-075) --}}
                    <label @class(['supplier-select-control', 'is-invalid' => $errors->has('selected_suppliers')])>
                      <input type="radio" name="selected_suppliers[]" value="{{ $s }}"
                             @checked(in_array($s, $selected_supplier_slots, true))
                             @disabled(! $can_pick_supplier || $s > $doc->supplier_count)
                             aria-label="เลือกผู้ขายที่ {{ $s }}">
                      <span class="supplier-check" aria-hidden="true"></span>
                      <em>เลือก</em>
                    </label>
                  @else
                    <span class="supplier-check {{ $suppliers[$s]?->is_selected ? 'is-checked' : '' }}"
                          role="checkbox" aria-checked="{{ $suppliers[$s]?->is_selected ? 'true' : 'false' }}"
                          aria-label="ผู้ขายที่ {{ $s }} {{ $suppliers[$s]?->is_selected ? 'ถูกเลือกแล้ว' : 'ยังไม่ถูกเลือก' }}"></span>
                  @endif
                </div>
              </th>
            @endfor
          </tr>
          <tr>
            @for ($s = 1; $s <= 3; $s++)
              <th data-sup="{{ $s }}">Unit Price</th>
              <th data-sup="{{ $s }}">Unit Price Rev.1</th>
              <th data-sup="{{ $s }}">Total Amount (<span data-currency-label>{{ $doc->currencyLabel() }}</span>)</th>
            @endfor
          </tr>
        </thead>

        <tbody id="rows">
          @foreach ($doc->items as $item)
            <tr class="item-row">
              <td class="n">
                <span class="row-no">{{ $loop->iteration }}</span>
                <button type="button" class="rm" aria-label="ลบรายการที่ {{ $loop->iteration }}">
                  <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round">
                    <path d="M18 6 6 18M6 6l12 12"/>
                  </svg>
                </button>
              </td>
              <td>
                {{-- เลือกจากทะเบียนเท่านั้น กรอกเองไม่ได้ (D-038)
                     ใช้รหัสเดิม -> ลงช่อง item_code · ออกรหัสใหม่ -> ลง item_code_prefix แล้วออกเลขตอนบันทึก --}}
                <div class="item-code-control">
                  <input type="text" @class(['cell', 'item-code-input', 'is-invalid' => $errors->has("items.{$item->id}.item_code")])
                         autocomplete="off" readonly
                         name="items[{{ $item->id }}][item_code]" value="{{ $item->item_code }}"
                         placeholder="กดเพื่อเลือกรหัส"
                         aria-label="Item Code รายการที่ {{ $loop->iteration }}"
                         @if (! $is_readonly_mode) data-required-fill @endif @disabled($is_readonly_mode)>
                  <button type="button" class="item-code-clear" aria-label="ล้างรหัสในช่องนี้" title="ล้างรหัส"
                          @disabled($is_readonly_mode)>
                    <svg viewBox="0 0 24 24"><path d="M18 6 6 18M6 6l12 12"/></svg>
                  </button>
                  <button type="button" class="item-code-open" aria-expanded="false" aria-label="เลือกรหัสจากรายการ"
                          @disabled($is_readonly_mode)>
                    <svg viewBox="0 0 24 24"><path d="m6 9 6 6 6-6"/></svg>
                  </button>
                </div>
              </td>
              <td>
                <textarea @class(['cell', 'description-cell', 'is-invalid' => $errors->has("items.{$item->id}.description")])
                          name="items[{{ $item->id }}][description]" rows="1" maxlength="2000"
                          aria-label="รายละเอียดสินค้า"
                          @if (! $is_readonly_mode) data-required-fill @endif @disabled($is_readonly_mode)>{{ $item->description }}</textarea>
              </td>
              <td>
                <div class="qty-cell">
                  <input type="number" step="any" min="0" @class(['cell', 'num-in', 'qty', 'is-invalid' => $errors->has("items.{$item->id}.qty")])
                         aria-label="จำนวน" name="items[{{ $item->id }}][qty]"
                         @if (! $is_readonly_mode) data-required-fill @endif
                         value="{{ $item->qty !== null ? rtrim(rtrim((string) $item->qty, '0'), '.') : '' }}"
                         @disabled($is_readonly_mode)>
                  <input type="text" @class(['cell', 'unit', 'is-invalid' => $errors->has("items.{$item->id}.unit")])
                         name="items[{{ $item->id }}][unit]" value="{{ $item->unit }}" aria-label="หน่วย"
                         @if (! $is_readonly_mode) data-required-fill @endif
                         @disabled($is_readonly_mode)>
                </div>
              </td>

              @for ($s = 1; $s <= 3; $s++)
                @php $price = $suppliers[$s] ? $item->priceFor($suppliers[$s]) : null; @endphp
                <td data-sup="{{ $s }}">
                  <input type="text" inputmode="decimal" autocomplete="off"
                         @class(['cell', 'num-in', 'price', 'money-in', 'is-invalid' => $errors->has("items.{$item->id}.prices.{$s}")]) data-sup="{{ $s }}"
                         name="items[{{ $item->id }}][prices][{{ $s }}]"
                         @if (! $is_readonly_mode && $s <= $doc->supplier_count) data-required-fill @endif
                         value="{{ $price?->unit_price !== null ? number_format((float) $price->unit_price, 2) : '' }}"
                         @disabled($is_readonly_mode)>
                </td>
                <td data-sup="{{ $s }}" class="rev">
                  @if ($is_negotiate_mode)
                    <input type="text" inputmode="decimal" autocomplete="off"
                           @class(['cell', 'num-in', 'rev-price', 'money-in', 'is-invalid' => $errors->has("revision_prices.{$item->id}.{$s}")])
                           data-sup="{{ $s }}" name="revision_prices[{{ $item->id }}][{{ $s }}]"
                           {{-- ต่อรองครบทุกเจ้าที่ใบนี้ใช้ ไม่ใช่เฉพาะเจ้าที่ถูกเลือกไว้ (D-075) --}}
                           @if ($can_edit_negotiation && $s <= $doc->supplier_count) data-required-fill @endif
                           value="{{ $price?->revisionInputValue() ?? '' }}"
                           aria-label="Unit Price Rev.1 ผู้ขายที่ {{ $s }} รายการที่ {{ $item->row_no }}"
                           @disabled(! $can_edit_negotiation || $s > $doc->supplier_count)>
                  @else
                    {{-- `-` = ยืนยันแล้วว่าไม่ต่อรอง · `—` = ยังไม่ได้กรอก --}}
                    <span class="rev-value" data-rev="{{ $price?->unit_price_rev }}">{{ $price?->revisionInputValue() ?: '—' }}</span>
                  @endif
                </td>
                <td data-sup="{{ $s }}" class="amount">
                  <span class="amount-base" data-amount-base="{{ $s }}">0.00</span>
                  <small class="amount-rev" data-amount-rev="{{ $s }}" hidden></small>
                </td>
              @endfor
            </tr>
          @endforeach

          @for ($row = 0; $row < $placeholder_rows; $row++)
            <tr class="placeholder-row" aria-hidden="true">
              @for ($cell = 0; $cell < 13; $cell++)
                @php $placeholder_supplier = $cell >= 4 ? intdiv($cell - 4, 3) + 1 : null; @endphp
                <td @if ($placeholder_supplier) data-sup="{{ $placeholder_supplier }}" @endif></td>
              @endfor
            </tr>
          @endfor
        </tbody>

        <tfoot>
          <tr>
            {{-- ช่องว่างซ้ายมือของแถวยอดรวม — ใส่หมายเหตุเรื่อง VAT ไว้ตรงนี้ --}}
            <td colspan="4" class="total-note">
              <span>* หมายเหตุ : ราคารวมข้างต้นยังไม่รวมภาษีมูลค่าเพิ่ม (VAT) 7%</span>
            </td>
            @for ($s = 1; $s <= 3; $s++)
              <td colspan="2" data-sup="{{ $s }}"></td>
              <td data-sup="{{ $s }}" class="supplier-total">
                <span>Total Amount (<span data-currency-label>{{ $doc->currencyLabel() }}</span>)</span>
                <strong data-total="{{ $s }}">0.00</strong>
                <small class="supplier-total-rev" data-total-rev="{{ $s }}" hidden></small>
              </td>
            @endfor
          </tr>
        </tfoot>
      </table>

    {{-- Lead Time / Term / Remark ต่อผู้ขาย --}}
    <div class="terms-grid">
      <div class="terms-spacer" aria-hidden="true"></div>
      @for ($s = 1; $s <= 3; $s++)
        <div class="supplier-terms" data-sup="{{ $s }}">
          <div class="term-row">
            <label for="lead{{ $s }}">Lead Time :</label>
            <textarea @class(['cell', 'multiline-field', 'is-invalid' => $errors->has("suppliers.{$s}.lead_time")])
                      id="lead{{ $s }}" name="suppliers[{{ $s }}][lead_time]" rows="2" maxlength="120"
                      @if (! $is_readonly_mode && $s <= $doc->supplier_count) data-required-fill @endif
                      @disabled($is_readonly_mode)>{{ $suppliers[$s]->lead_time ?? '' }}</textarea>
          </div>
          <div class="term-row">
            <label for="payment{{ $s }}">Term of Payment :</label>
            <textarea @class(['cell', 'multiline-field', 'is-invalid' => $errors->has("suppliers.{$s}.term_of_payment")])
                      id="payment{{ $s }}" name="suppliers[{{ $s }}][term_of_payment]" rows="2" maxlength="191"
                      @if (! $is_readonly_mode && $s <= $doc->supplier_count) data-required-fill @endif
                      @disabled($is_readonly_mode)>{{ $suppliers[$s]->term_of_payment ?? '' }}</textarea>
          </div>
          <div class="term-row">
            <label for="remark{{ $s }}">Remark :</label>
            <textarea @class(['cell', 'multiline-field', 'is-invalid' => $errors->has("suppliers.{$s}.remark")])
                      id="remark{{ $s }}" name="suppliers[{{ $s }}][remark]" rows="2" maxlength="2000"
                      @if (! $is_readonly_mode && $s <= $doc->supplier_count) data-required-fill @endif
                      @disabled($is_readonly_mode)>{{ $suppliers[$s]->remark ?? '' }}</textarea>
          </div>
        </div>
      @endfor
    </div>

    <div class="comment-box">
      <div class="fld stacked-field">
        {{-- Comment เป็นช่องของผู้ขอซื้อ ไม่ใช่ฝ่ายจัดซื้อ (D-055) --}}
        <label for="comment">Comment :</label>
        <span class="val">
          <textarea class="blank multiline-field" id="comment" name="comment"
                    rows="2" maxlength="2000"
                    placeholder="{{ $can_edit_comment ? 'ผู้ขอซื้อกรอกความเห็นที่นี่' : '' }}"
                    @if ($can_edit_comment) data-required-fill @endif
                    @disabled(! $can_edit_comment)>{{ old('comment', $doc->comment) }}</textarea>
        </span>
      </div>
    </div>

    {{-- ลายเซ็นตามเส้นทางที่ผู้ดูแลระบบกำหนด --}}
    <div class="signature-grid" aria-label="ลำดับการลงนาม">
      @foreach ($workflow_steps as $step)
        @php
          $stamp = $signature_by_step->get($step->id);
          $can_stamp = in_array($step->id, $signable_step_ids, true);
          // กดปุ่มส่งสีเขียวแล้วเอกสารพ้นมือไปแล้ว ลบลายเซ็นไม่ได้ (D-058)
          $can_remove = $stamp?->signer_id_thai_hash === $me->id_thai_hash
            && (($is_purchase_mode && $step->duty === 'create' && $doc->status === 'draft')
              || ($is_requester_mode && $step->duty === 'select' && $doc->status === 'sent_user')
              || ($is_negotiate_mode && $step->duty === 'negotiate' && $doc->status === 'negotiating')
              || ($is_confirm_mode && $step->duty === 'confirm' && $doc->status === 'confirming')
              // ขั้นลงนามอนุมัติ: ถอนได้จนกว่าจะกดปุ่มยืนยัน (D-070)
              || ($is_approval_mode && $step->duty === 'sign' && in_array($step->id, $signable_step_ids, true)));
        @endphp
        <div @class([
          'signature',
          'purchase-signature-invalid' => $step->duty === 'create' && $errors->has('purchase_signature'),
        ]) data-signature-duty="{{ $step->duty }}">
          <div class="signature-line">
            @if ($stamp)
              <img src="{{ $stamp->signature_data }}" class="signature-image" alt="ลายเซ็นของ {{ $stamp->signer_name }}">
              @if ($can_remove)
                <button type="button" class="signature-remove" data-signature-id="{{ $stamp->id }}"
                        aria-label="ลบลายเซ็น {{ $step->roleLabel() }}" title="ลบลายเซ็น">
                  <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M3 6h18M8 6V4h8v2M19 6l-1 14H6L5 6"/></svg>
                </button>
              @endif
            @elseif ($can_stamp)
              <button type="button" class="signature-stamp" data-signature-step="{{ $step->id }}"
                      data-signature-role="{{ $step->roleLabel() }}">
                ประทับลายเซ็น
              </button>
            @endif
          </div>
          <div class="signature-role">{{ $step->roleLabel() }}</div>
          <div class="signature-duty">{{ $step->dutyLabel() }}</div>
          <div class="signature-date">
            @if ($stamp)
              Date {{ $stamp->signed_at?->format('d/m/Y') }}
            @else
              Date........../............./.............
            @endif
          </div>
        </div>
      @endforeach
    </div>
  </div>
  </div>
  </section>
</form>

{{-- ── ไฟล์แนบ แยกตามขั้นและผู้ขาย (อยู่นอกฟอร์มหลัก เพราะ form ซ้อนกันไม่ได้) ── --}}
@foreach ([
  [
    'no' => 6,
    'stage' => \App\Models\PrAttachment::STAGE_CREATE,
    'title' => 'แนบไฟล์ใบเสนอราคา',
    'can_manage' => $can_edit_document,
    'locked_note' => null,
  ],
  [
    'no' => 7,
    'stage' => \App\Models\PrAttachment::STAGE_NEGOTIATE,
    'title' => 'แนบไฟล์ใบเสนอราคา (ต่อรองราคา)',
    'can_manage' => $can_manage_negotiation_files,
    // แท็บจัดทำเอกสารแตะหัวข้อนี้ไม่ได้ — เป็นของขั้นต่อรองราคา
    'locked_note' => $is_purchase_mode
      ? 'ส่วนนี้เป็นของขั้นต่อรองราคา — ฝ่ายจัดซื้อจะแนบใบเสนอราคาหลังต่อรองที่แท็บ "ต่อรองราคา"'
      : null,
  ],
] as $attachment_section)
  {{-- ผู้ขอซื้อไม่ต้องเห็นหัวข้อ 7 — ยังไม่ถึงขั้นต่อรองราคา ไม่เกี่ยวกับการคัดเลือก (D-056) --}}
  @continue($is_requester_mode && $attachment_section['stage'] === \App\Models\PrAttachment::STAGE_NEGOTIATE)

  <section @class(['form-step', 'attachment-step', 'is-locked-step' => $attachment_section['locked_note']])
           data-view-anchor="attachments-{{ $attachment_section['stage'] }}"
           aria-labelledby="attachment-title-{{ $attachment_section['stage'] }}">
    <div class="step-head">
      <span class="step-number">{{ $attachment_section['no'] }}</span>
      <h2 id="attachment-title-{{ $attachment_section['stage'] }}">{{ $attachment_section['title'] }}</h2>
    </div>

    @if ($attachment_section['stage'] === \App\Models\PrAttachment::STAGE_NEGOTIATE
      && ! $negotiation_stage_open && ! $negotiation_files_exist && ! $negotiation_answered)
      <p class="step-locked-note is-info">
        <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M12 8h.01M11 12h1v4h1"/></svg>
        ยังไม่มีข้อมูลใบเสนอราคาหลังต่อรอง
      </p>
    @endif

    <div @class(['files-wrap', 'is-locked' => $attachment_section['locked_note']])>
    <div class="files" @if ($attachment_section['stage'] === \App\Models\PrAttachment::STAGE_CREATE) id="fileArea" @endif>
      @for ($s = 1; $s <= 3; $s++)
        @php
          $sup = $suppliers[$s] ?? null;
          $stage_files = $sup?->attachments?->where('stage', $attachment_section['stage']) ?? collect();
          $is_negotiate_stage = $attachment_section['stage'] === \App\Models\PrAttachment::STAGE_NEGOTIATE;
          // ผู้ขายที่ใบนี้ใช้จริง — ช่องเกิน supplier_count เป็นการ์ดเปล่าไว้ให้ครบ 3 คอลัมน์
          $sup_in_document = $sup && $sup->slot <= $doc->supplier_count;
          /*
          | หัวข้อ 7 ตอบแยกทีละบริษัท (ต่อรองแล้วบางเจ้าส่งใบเสนอราคาใหม่ บางเจ้าไม่ส่ง)
          |   null = ยังไม่ตอบ · true = มี -> เปิดช่องแนบของเจ้านี้ · false = ไม่มี -> ขึ้นข้อความแทน
          */
          $sup_quote = $is_negotiate_stage ? $sup?->has_negotiation_quote : null;
          $shows_quote_ask = $is_negotiate_stage && $negotiation_stage_open && $sup_in_document;
          // เอกสารเก่าที่แนบไว้ก่อนมีปุ่มนี้ ให้ถือว่า "มี" ตามไฟล์ที่เห็นจริง
          $shows_stage_files = ! $is_negotiate_stage || $sup_quote === true
            || ($sup_quote === null && $stage_files->isNotEmpty());
        @endphp
        <div @class(['file-card', 'is-invalid' => $errors->has("attachments.{$s}")]) data-sup="{{ $s }}"
             @if ($shows_quote_ask) data-quote-ask="{{ $s }}" @endif>
          <h3>
            บริษัท:
            <span @if ($attachment_section['stage'] === \App\Models\PrAttachment::STAGE_CREATE) data-file-supplier-name="{{ $s }}" @endif>
              {{ trim((string) $sup?->name) ?: 'ยังไม่ระบุ' }}
            </span>
          </h3>

          @if ($shows_quote_ask)
            {{-- ฝ่ายจัดซื้อตอบทีละเจ้าว่าต่อรองแล้วได้ใบเสนอราคาใหม่จากบริษัทนี้ไหม --}}
            <form method="POST" action="{{ route('work.negotiate.quote-flag', $sup) }}" class="quote-ask">
              @csrf
              <button type="submit" name="has_quote" value="1"
                      @class(['quote-opt', 'is-on' => $sup_quote === true])>มี</button>
              <button type="submit" name="has_quote" value="0"
                      @class(['quote-opt', 'is-on' => $sup_quote === false])>ไม่มี</button>
            </form>
          @endif

          @if ($is_negotiate_stage && $sup_quote === false)
            <p class="file-note">ฝ่ายจัดซื้อระบุว่าไม่มีใบเสนอราคาเพิ่มเติมจากการต่อรอง</p>
          @elseif ($is_negotiate_stage && ! $shows_stage_files)
            <p class="file-none">{{ $shows_quote_ask ? 'เลือก "มี" เพื่อเปิดช่องแนบไฟล์' : 'ยังไม่มีข้อมูล' }}</p>
          @else
          @forelse ($stage_files as $file)
            @php $kind = $file->kind(); @endphp
            <div class="file-row">
              <a class="file-open {{ $kind === 'image' ? 'is-image' : '' }}"
                 href="{{ route('documents.files.view', $file) }}" target="_blank" rel="noopener"
                 title="เปิด {{ $file->original_name }} ในแท็บใหม่">
                @if ($kind === 'image')
                  <img src="{{ route('documents.files.view', $file) }}" alt="{{ $file->original_name }}" loading="lazy">
                @elseif ($icon = $file->iconUrl())
                  <span class="ext"><img src="{{ $icon }}" alt="{{ strtoupper($kind) }}" loading="lazy"></span>
                @else
                  <span class="ext ext-file">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round">
                      <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6"/>
                    </svg>
                    <i>{{ $file->extension() }}</i>
                  </span>
                @endif
              </a>

              <a class="file-name" href="{{ route('documents.files.view', $file) }}" target="_blank" rel="noopener">
                {{ $file->original_name }}
              </a>
              <span class="kb">{{ $file->sizeText() }}</span>

              <a class="dl" href="{{ route('documents.files.download', $file) }}" title="ดาวน์โหลด {{ $file->original_name }}" aria-label="ดาวน์โหลด {{ $file->original_name }}">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                  <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><path d="M7 10l5 5 5-5"/><path d="M12 15V3"/>
                </svg>
              </a>

              @if ($attachment_section['can_manage'])
                <button type="submit" class="x" form="delFile{{ $file->id }}" aria-label="ลบ {{ $file->original_name }}">
                  <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round">
                    <path d="M18 6 6 18M6 6l12 12"/>
                  </svg>
                </button>
              @endif
            </div>
          @empty
            <p class="file-none">{{ $is_negotiate_stage ? 'ยังไม่แนบไฟล์ — ต้องแนบก่อนส่ง' : ($attachment_section['can_manage'] ? 'ยังไม่แนบใบเสนอราคา — ต้องแนบก่อนส่ง' : 'ยังไม่มีไฟล์') }}</p>
          @endforelse
          @endif

          {{-- ต่อรองทั้ง 3 เจ้า จึงแนบไฟล์หลังต่อรองได้ทุกเจ้าที่ใบนี้ใช้ (D-075)
               ขั้นต่อรอง: เปิดช่องแนบเฉพาะเจ้าที่ตอบ "มี" แล้วเท่านั้น --}}
          @if ($sup_in_document && $attachment_section['can_manage']
            && (! $is_negotiate_stage || $sup_quote === true))
            <form method="POST"
                  action="{{ $attachment_section['stage'] === \App\Models\PrAttachment::STAGE_CREATE
                    ? route('documents.files.upload', $sup)
                    : route('work.negotiate.files.upload', $sup) }}"
                  enctype="multipart/form-data" class="file-add">
              @csrf
              <input type="file" name="files[]" multiple accept=".pdf,.doc,.docx,.xls,.xlsx,.csv,.png,.jpg,.jpeg,.webp,.gif">
              <button type="submit" class="btn btn-quiet">แนบไฟล์</button>
            </form>
          @endif
        </div>
      @endfor
    </div>

      @if ($attachment_section['locked_note'])
        <div class="files-lock" aria-hidden="true">
          <svg viewBox="0 0 24 24"><rect x="4" y="11" width="16" height="10" rx="2"/><path d="M8 11V7a4 4 0 0 1 8 0v4"/></svg>
          <b>สำหรับต่อรองราคา</b>
        </div>
      @endif
    </div>
  </section>
@endforeach

{{-- ฟอร์มลบไฟล์ · ลบเอกสาร — แยกออกมาไม่ให้ซ้อนกับฟอร์มหลัก --}}
@foreach ($doc->suppliers as $sup)
  @foreach ($sup->attachments as $file)
    @if (($is_purchase_mode && $file->stage === \App\Models\PrAttachment::STAGE_CREATE)
      || ($is_negotiate_mode && $file->stage === \App\Models\PrAttachment::STAGE_NEGOTIATE && $doc->status === 'negotiating'))
      <form method="POST"
            action="{{ $file->stage === \App\Models\PrAttachment::STAGE_CREATE
              ? route('documents.files.delete', $file)
              : route('work.negotiate.files.delete', $file) }}"
            id="delFile{{ $file->id }}">@csrf</form>
    @endif
  @endforeach
@endforeach

@if ($is_purchase_mode)
  <form method="POST" action="{{ route('documents.destroy', $doc) }}" id="delDocForm">@csrf</form>
@endif
@if ($can_send_to_negotiation)
  <form method="POST" action="{{ route('work.select.send', $doc) }}" id="sendNegotiationForm">@csrf</form>
@endif
@if ($can_send_to_approval)
  <form method="POST" action="{{ route('work.negotiate.send', $doc) }}" id="sendApprovalForm">@csrf</form>
@endif
@if ($can_send_confirmation)
  <form method="POST" action="{{ route('work.confirm.send', $doc) }}" id="sendConfirmationForm">@csrf</form>
@endif
<button type="submit" form="docForm" name="intent" value="remove_signature" id="signatureDeleteSubmit" hidden></button>

{{-- กล่องเลือกพนักงาน --}}
<dialog class="picker" id="picker">
  <header>
    <b>เลือกพนักงานผู้ขอซื้อ</b>
    <button type="button" id="pickerClose" aria-label="ปิด">
      <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round">
        <path d="M18 6 6 18M6 6l12 12"/>
      </svg>
    </button>
  </header>
  <div class="search">
    <input type="search" id="pickerSearch" placeholder="ค้นหา ชื่อ · รหัส · แผนก" autocomplete="off">
  </div>
  <div class="picker-list" id="pickerList"></div>
</dialog>

{{-- กล่องยืนยันประทับลายเซ็นจาก Insight --}}
<dialog class="signature-dialog" id="signatureDialog">
  <header>
    <b>ประทับลายเซ็น</b>
    <button type="button" class="dialog-close" id="signatureClose" aria-label="ปิด">
      <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"><path d="M18 6 6 18M6 6l12 12"/></svg>
    </button>
  </header>
  <div class="dialog-body">
    <div class="signature-preview">
      @if ($me->signature)
        <img src="{{ $me->signature }}" alt="ลายเซ็นของ {{ $me->displayName() }}">
      @else
        <span class="missing">ยังไม่พบลายเซ็นใน Insight</span>
      @endif
    </div>
    <p class="signer"><span id="signatureRole"></span> · {{ $me->displayName() }}</p>
  </div>
  <div class="dialog-actions">
    <button type="button" class="btn btn-quiet" id="signatureCancel">ยกเลิก</button>
    <button type="submit" form="docForm" name="intent" value="stamp" class="btn" @disabled(! $me->signature)>
      ประทับลายเซ็น
    </button>
  </div>
</dialog>

@if ($can_send_to_negotiation)
  <dialog class="signature-dialog" id="sendNegotiationDialog">
    <header>
      <b>ยืนยันการส่งเอกสาร</b>
      <button type="button" class="dialog-close" id="sendNegotiationClose" aria-label="ปิด">
        <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"><path d="M18 6 6 18M6 6l12 12"/></svg>
      </button>
    </header>
    <div class="dialog-body">
      <p class="send-confirm-copy">
        ส่ง <strong>{{ $doc->referenceLabel() }}</strong> ให้ฝ่ายจัดซื้อต่อรองราคา
      </p>
    </div>
    <div class="dialog-actions">
      <button type="button" class="btn btn-quiet" id="sendNegotiationCancel">ยกเลิก</button>
      <button type="submit" form="sendNegotiationForm" class="btn btn-success">ยืนยันส่งต่อรองราคา</button>
    </div>
  </dialog>
@endif

@if ($can_send_to_approval)
  <dialog class="signature-dialog" id="sendApprovalDialog">
    <header>
      <b>{{ $has_confirm_step ? 'ยืนยันการส่งให้ผู้ขอซื้อ' : 'ยืนยันการส่งลงนามอนุมัติ' }}</b>
      <button type="button" class="dialog-close" id="sendApprovalClose" aria-label="ปิด">
        <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"><path d="M18 6 6 18M6 6l12 12"/></svg>
      </button>
    </header>
    <div class="dialog-body">
      <p class="send-confirm-copy">
        @if ($has_confirm_step)
          ส่ง <strong>{{ $doc->referenceLabel() }}</strong> ให้ <strong>{{ $doc->requester_name ?: 'ผู้ขอซื้อ' }}</strong> ยืนยันรายการ<br>
        @else
          ส่ง <strong>{{ $doc->referenceLabel() }}</strong> ให้ Mgr. Purchasing ลงนาม<br>
        @endif
        ส่งแล้วแก้ราคา Rev.1 และลบลายเซ็นไม่ได้อีก
      </p>
    </div>
    <div class="dialog-actions">
      <button type="button" class="btn btn-quiet" id="sendApprovalCancel">ยกเลิก</button>
      <button type="submit" form="sendApprovalForm" class="btn btn-success">
        {{ $has_confirm_step ? 'ยืนยันส่งให้ผู้ขอซื้อ' : 'ยืนยันส่งลงนามอนุมัติ' }}
      </button>
    </div>
  </dialog>
@endif

@if ($can_send_confirmation)
  <dialog class="signature-dialog" id="sendConfirmationDialog">
    <header>
      <b>ยืนยันการส่งลงนามอนุมัติ</b>
      <button type="button" class="dialog-close" id="sendConfirmationClose" aria-label="ปิด">
        <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"><path d="M18 6 6 18M6 6l12 12"/></svg>
      </button>
    </header>
    <div class="dialog-body">
      <p class="send-confirm-copy">
        ส่ง <strong>{{ $doc->referenceLabel() }}</strong> ให้ Mgr. Purchasing ลงนาม<br>
        ส่งแล้วเปลี่ยน Supplier และลบลายเซ็นไม่ได้อีก
      </p>
    </div>
    <div class="dialog-actions">
      <button type="button" class="btn btn-quiet" id="sendConfirmationCancel">ยกเลิก</button>
      <button type="submit" form="sendConfirmationForm" class="btn btn-success">ยืนยันส่งลงนามอนุมัติ</button>
    </div>
  </dialog>
@endif

@if ($can_send_approval ?? false)
  <form method="POST" action="{{ route('work.approval.send', $doc) }}" id="approveForm">@csrf</form>

  <dialog class="signature-dialog" id="approveDialog">
    <header>
      <b>{{ ($approval_is_final ?? false) ? 'ยืนยันการอนุมัติขั้นสุดท้าย' : 'ยืนยันการลงนามอนุมัติ' }}</b>
      <button type="button" class="dialog-close" id="approveClose" aria-label="ปิด">
        <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"><path d="M18 6 6 18M6 6l12 12"/></svg>
      </button>
    </header>
    <div class="dialog-body">
      @if ($approval_is_final ?? false)
        <p class="send-confirm-copy">
          <strong>ลายเซ็นสุดท้ายของเอกสารนี้</strong><br>
          อนุมัติแล้ว <strong>{{ $doc->referenceLabel() }}</strong> ปิดกระบวนการทันที แก้ไขหรือย้อนกลับไม่ได้
        </p>
      @else
        <p class="send-confirm-copy">
          ลงนาม <strong>{{ $doc->referenceLabel() }}</strong> แล้วส่งต่อให้ CEO<br>
          ส่งแล้วถอนลายเซ็นไม่ได้อีก
        </p>
      @endif
    </div>
    <div class="dialog-actions">
      <button type="button" class="btn btn-quiet" id="approveCancel">ยกเลิก</button>
      <button type="submit" form="approveForm" class="btn btn-success">
        {{ ($approval_is_final ?? false) ? 'ยืนยันอนุมัติเอกสาร' : 'ยืนยันส่งให้ CEO' }}
      </button>
    </div>
  </dialog>
@endif

@if ($can_edit_document)
  <dialog class="signature-dialog" id="sendRequesterDialog">
    <header>
      <b>ยืนยันการส่งให้ผู้ขอซื้อ</b>
      <button type="button" class="dialog-close" id="sendRequesterClose" aria-label="ปิด">
        <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"><path d="M18 6 6 18M6 6l12 12"/></svg>
      </button>
    </header>
    <div class="dialog-body">
      <p class="send-confirm-copy">
        ส่ง <strong>{{ $doc->referenceLabel() }}</strong> ให้ <strong>{{ $doc->requester_name ?: 'ผู้ขอซื้อ' }}</strong> คัดเลือกผู้ขาย<br>
        ส่งแล้วแก้เอกสารและแนบไฟล์ไม่ได้อีก
      </p>
    </div>
    <div class="dialog-actions">
      <button type="button" class="btn btn-quiet" id="sendRequesterCancel">ยกเลิก</button>
      <button type="submit" form="docForm" name="intent" value="send" class="btn btn-success">ยืนยันส่งให้ผู้ขอซื้อ</button>
    </div>
  </dialog>
@endif

{{-- ปฏิเสธ = ปิดใบไว้เป็นหลักฐาน ไม่ลบทิ้ง แล้วให้ฝ่ายจัดซื้อขึ้นใบใหม่เอง (D-063)
     ขั้นแรก (Purchasing จัดทำเอกสาร) ไม่มีปุ่มนี้ — คนทำเองปฏิเสธของตัวเองไม่ได้ ใช้ลบแทน --}}
@if ($can_reject)
  <dialog class="signature-dialog" id="rejectDialog">
    <header>
      <b>ปฏิเสธเอกสาร</b>
    </header>
    <form method="POST" action="{{ route('documents.reject', $doc) }}" id="rejectForm">
      @csrf
      <div class="dialog-body">
        <label class="reject-label" for="rejectReason">เหตุผลที่ปฏิเสธ</label>
        <textarea id="rejectReason" name="reason" rows="3" maxlength="500" required
                  placeholder="เช่น ราคาสูงเกินงบ · ผู้ขายไม่ครบ 3 บริษัท"></textarea>
        <p class="reject-hint">ใบนี้ถูกปิดไว้เป็นหลักฐาน ไม่ถูกลบ · ถ้ายังต้องซื้อ ให้ขึ้นใบใหม่</p>
      </div>
      <div class="dialog-actions">
        <button type="button" class="btn btn-quiet" id="rejectCancel">ยกเลิก</button>
        <button type="submit" class="btn btn-danger">ยืนยันปฏิเสธ</button>
      </div>
    </form>
  </dialog>
@endif

{{-- กดรูปพนักงานในเส้นทางเอกสารเพื่อดูรูปใหญ่ (D-077) --}}
@include('partials.avatar-zoom')

@endsection

@section('scripts')
<style>
  .picker { border: 0; padding: 0; border-radius: 12px; width: min(92vw, 440px); max-height: 84vh;
    box-shadow: 0 30px 70px -24px rgba(17,24,39,.5); flex-direction: column; }
  .picker[open] { display: flex; }
  .picker::backdrop { background: rgba(17,24,39,.55); }
  .picker header { display: flex; align-items: center; gap: 12px; padding: 15px 16px; border-bottom: 1px solid var(--line); }
  .picker header b { flex: 1; font-size: 15px; }
  .picker header button { display: grid; place-items: center; width: 30px; height: 30px;
    border: 0; border-radius: 8px; background: transparent; color: var(--muted); cursor: pointer; }
  .picker header button:hover { background: var(--surface-soft); color: var(--ink); }
  .picker .search { padding: 12px 16px 10px; }
  .picker .search input { width: 100%; min-height: 40px; padding: 0 13px;
    border: 1px solid var(--line); border-radius: 10px; font-size: 14px; }
  .picker .search input:focus { outline: none; border-color: var(--teal); box-shadow: 0 0 0 3px rgba(12,163,154,.16); }
  .picker-list { flex: 1; overflow-y: auto; padding: 0 10px 12px; }
  /* หนึ่งแถว = ปุ่มรูป (ซูม) + ปุ่มเลือกคน — ปุ่มซ้อนปุ่มไม่ได้จึงเป็นพี่น้องกัน (D-078) */
  .picker-list .picker-row {
    display: flex; align-items: center; gap: 11px; width: 100%;
    padding: 8px 10px; border-radius: 9px;
  }
  .picker-list .picker-row:hover { background: var(--surface-soft); }
  .picker-list .picker-choose {
    display: flex; align-items: center; flex: 1; min-width: 0;
    padding: 0; border: 0; background: transparent; text-align: left; cursor: pointer;
  }
  .picker-list .picker-choose:focus-visible { outline: 2px solid var(--teal); outline-offset: 2px; border-radius: 7px; }
  .picker-list .pic { width: 32px; height: 32px; border-radius: 50%; overflow: hidden; flex: none; background: var(--surface-soft); }
  .picker-list button.pic { padding: 0; border: 0; cursor: zoom-in; }
  .picker-list button.pic:hover { box-shadow: 0 0 0 2px var(--teal); }
  .picker-list .pic img { width: 100%; height: 100%; object-fit: cover; display: block; }
  .picker-list .info { min-width: 0; flex: 1; }
  .picker-list .info b { display: block; font-size: 13.5px; font-weight: 600; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
  .picker-list .info span { display: block; font-size: 12px; color: var(--muted); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
  .picker-list .msg { padding: 26px 12px; text-align: center; font-size: 13.5px; color: var(--muted); }

  /* ── รายการเลือก Item Code ─────────────────────────────
     ต้องอยู่นอก .sheet เพราะ .sheet มี zoom:70% จะทำให้ตัวหนังสือเล็กจนอ่านไม่ออก */
  .code-menu {
    position: fixed; z-index: 80; display: none; flex-direction: column;
    width: 316px; max-height: 340px; border: 1px solid var(--line); border-radius: 11px;
    background: #fff; box-shadow: 0 20px 44px -16px rgba(17,24,39,.42); overflow: hidden;
  }
  .code-menu[data-open="1"] { display: flex; }
  .code-menu .search { padding: 9px 10px; border-bottom: 1px solid var(--line); }
  .code-menu .search input {
    width: 100%; min-height: 34px; padding: 0 11px;
    border: 1px solid var(--line); border-radius: 8px; font-size: 13px;
  }
  .code-menu .search input:focus { outline: none; border-color: var(--teal); box-shadow: 0 0 0 3px rgba(12,163,154,.16); }

  .code-menu .list { flex: 1; overflow-y: auto; padding: 5px; }
  .code-menu .list button {
    display: block; width: 100%; text-align: left; padding: 6px 9px;
    border: 0; border-radius: 7px; background: transparent; cursor: pointer;
  }
  .code-menu .list button:hover, .code-menu .list button.is-active { background: var(--teal-soft); }
  /* แถวเพิ่มรหัสใหม่ — เผื่อ ERP ออกรหัสมาแล้วยังไม่ได้ import */
  .code-menu .list .new-code { margin-bottom: 4px; border-bottom: 1px solid var(--line-soft); border-radius: 7px 7px 0 0; }
  .code-menu .list .new-code b { color: var(--teal-deep); font-family: inherit; }
  .code-menu .list .more { margin: 3px 0 6px; padding: 0 9px; font-size: 11.5px; color: var(--muted); }

  /* ── กล่องปฏิเสธ ─────────────────────────────────────── */
  .btn-danger { background: var(--danger); border-color: var(--danger); color: #fff; }
  .btn-danger:hover:not(:disabled) { background: #8e2a23; border-color: #8e2a23; }
  .reject-label { display: block; margin-bottom: 6px; font-size: 13px; font-weight: 600; }
  #rejectReason {
    width: 100%; padding: 9px 11px; border: 1px solid var(--line); border-radius: 9px;
    font: inherit; font-size: 13.5px; resize: vertical;
  }
  #rejectReason:focus { outline: none; border-color: var(--teal); box-shadow: 0 0 0 3px rgba(12,163,154,.16); }
  .reject-hint { margin: 8px 0 0; font-size: 12px; color: var(--muted); }
  .code-menu .list b {
    display: block; font-size: 13px; font-weight: 700; color: var(--ink);
    font-family: Consolas, "Cascadia Mono", monospace;
  }
  .code-menu .list .sub { display: flex; gap: 8px; font-size: 11.5px; color: var(--muted); }
  .code-menu .list .sub em { flex: 1; min-width: 0; font-style: normal; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
  .code-menu .list .sub i { flex: none; font-style: normal; font-variant-numeric: tabular-nums; }
  .code-menu .msg { margin: 0; padding: 22px 12px; text-align: center; font-size: 12.5px; color: var(--muted); }
</style>

{{-- รายการรหัสหมวดที่มีอยู่แล้ว · ค้นได้ทั้งรหัสและชื่อของ · ตัวเดียวใช้ร่วมกันทุกแถว --}}
<div class="code-menu" id="itemCodeMenu" data-open="0">
  <div class="search">
    <input type="search" id="itemCodeSearch" autocomplete="off"
           placeholder="ค้นรหัส หรือชื่อของ เช่น ถาด" aria-label="ค้นหารหัสสินค้า">
  </div>
  <div class="list" id="itemCodeList" role="listbox" aria-label="รายการรหัสสินค้า"></div>
</div>

<script>
  'use strict';

  var itemCodeOptions = {{ \Illuminate\Support\Js::from($item_code_options) }};
  var itemCodeSearchUrl = {{ \Illuminate\Support\Js::from(route('item-codes.search')) }};
  var csrfToken = document.querySelector('#docForm input[name="_token"]').value;
  // บริษัทที่ผู้ขอซื้อเลือกไว้แล้ว
  var selectedSupplierSlots = {{ \Illuminate\Support\Js::from(
      $doc->suppliers->where('is_selected', true)->pluck('slot')->map(fn ($slot) => (string) $slot)->values()
  ) }};
  // เทาเจ้าที่ตกรอบเฉพาะหลังผู้ขอซื้อยืนยันรายการแล้ว — ก่อนหน้านั้นต้องเทียบราคาครบ 3 เจ้า (D-075)
  var muteUnselectedSuppliers = {{ $mute_unselected_suppliers ? 'true' : 'false' }};

  // ── คืนตำแหน่งเดิมหลัง Action ที่ทำให้หน้าโหลดใหม่ ──────
  var documentViewStateKey = 'prCompare:documentView:{{ $doc->id }}';

  if ('scrollRestoration' in history) { history.scrollRestoration = 'manual'; }

  function elementDocumentTop(element) {
    return element.getBoundingClientRect().top + window.scrollY;
  }

  function captureDocumentView() {
    var anchors = Array.from(document.querySelectorAll('[data-view-anchor]'));
    var referenceY = window.scrollY + (window.innerHeight * 0.25);
    var anchor = anchors[0] || null;

    anchors.forEach(function (candidate) {
      if (elementDocumentTop(candidate) <= referenceY) { anchor = candidate; }
    });

    var sheetScroll = document.querySelector('.sheet-scroll');
    var zoomLabel = document.getElementById('zoomValue');
    var state = {
      path: window.location.pathname,
      expiresAt: Date.now() + 120000,
      windowX: window.scrollX,
      windowY: window.scrollY,
      anchor: anchor ? anchor.dataset.viewAnchor : null,
      anchorOffset: anchor ? window.scrollY - elementDocumentTop(anchor) : 0,
      sheetLeft: sheetScroll ? sheetScroll.scrollLeft : 0,
      sheetTop: sheetScroll ? sheetScroll.scrollTop : 0,
      zoom: zoomLabel ? parseInt(zoomLabel.textContent, 10) : 70,
    };

    try { sessionStorage.setItem(documentViewStateKey, JSON.stringify(state)); } catch (error) { /* storage unavailable */ }
  }

  function restoreDocumentView() {
    var state;

    try { state = JSON.parse(sessionStorage.getItem(documentViewStateKey) || 'null'); } catch (error) { state = null; }
    if (!state || state.path !== window.location.pathname || state.expiresAt < Date.now()) {
      try { sessionStorage.removeItem(documentViewStateKey); } catch (error) { /* storage unavailable */ }
      return;
    }

    if (zoomSteps.includes(state.zoom)) {
      zoomIndex = zoomSteps.indexOf(state.zoom);
      applyZoom();
    }

    window.requestAnimationFrame(function () {
      window.requestAnimationFrame(function () {
        var anchor = state.anchor
          ? document.querySelector('[data-view-anchor="' + state.anchor + '"]')
          : null;
        var targetY = anchor ? elementDocumentTop(anchor) + state.anchorOffset : state.windowY;
        var sheetScroll = document.querySelector('.sheet-scroll');

        window.scrollTo(state.windowX, Math.max(0, targetY));
        if (sheetScroll) {
          sheetScroll.scrollLeft = state.sheetLeft;
          sheetScroll.scrollTop = state.sheetTop;
        }

        try { sessionStorage.removeItem(documentViewStateKey); } catch (error) { /* storage unavailable */ }
      });
    });
  }

  document.addEventListener('submit', captureDocumentView, true);
  window.addEventListener('pageshow', restoreDocumentView);

  // ── เบลอช่องผู้ขายที่ไม่ได้ใช้ ─────────────────────────
  function paintSuppliers() {
    var used = parseInt(document.querySelector('input[name=supplier_count]:checked').value, 10);

    document.querySelectorAll('[data-sup]').forEach(function (el) {
      el.classList.toggle('is-off', parseInt(el.dataset.sup, 10) > used);
    });
  }

  document.querySelectorAll('input[name=supplier_count]').forEach(function (r) {
    r.addEventListener('change', function () { paintSuppliers(); recalc(); });
  });

  // ── คำนวณยอดเดิมและยอดหลังต่อรอง ──────────────────────
  function money(n) {
    return n.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
  }

  function fitNumericText(element) {
    var text = element.textContent.trim();
    if (text !== '' && text !== '—') { element.title = text; }
  }

  function writeMoney(element, value) {
    element.textContent = money(value);
    fitNumericText(element);
  }

  // ── สกุลเงิน — เปลี่ยนป้าย Total Amount ทันทีที่เลือก ไม่ต้องรอบันทึก ──
  var currencyPick = document.getElementById('currencyPick');

  if (currencyPick) {
    var currencyLabels = {{ \Illuminate\Support\Js::from(\App\Models\PrDocument::CURRENCIES) }};

    currencyPick.addEventListener('change', function () {
      var label = currencyLabels[currencyPick.value] || currencyPick.value;
      document.querySelectorAll('[data-currency-label]').forEach(function (el) {
        el.textContent = label;
      });
    });
  }

  // ── ช่องราคาแบบมีลูกน้ำคั่นหลักพัน ────────────────────
  //
  // ใช้ type="text" เพราะ <input type="number"> โชว์ลูกน้ำไม่ได้
  // ตอน submit จะถอดลูกน้ำออกให้เหลือตัวเลขล้วน (ฝั่งเซิร์ฟเวอร์ก็ถอดซ้ำอีกชั้น)

  function parseMoney(text) {
    var clean = String(text == null ? '' : text).replace(/,/g, '').trim();

    // '-' = ไม่มีการต่อรองราคา ให้ถือว่าไม่มีค่า แล้วไปใช้ราคาเดิมแทน
    return clean === '' || clean === '-' ? NaN : parseFloat(clean);
  }

  function moneyValue(text) {
    var n = parseMoney(text);

    return Number.isNaN(n) ? 0 : n;
  }

  /**
   * ใส่ลูกน้ำให้ส่วนจำนวนเต็ม ปล่อยทศนิยมตามที่พิมพ์ (กำลังพิมพ์ "1234." ต้องไม่โดนตัด)
   *
   * ช่อง Unit Price Rev.1 พิมพ์ "-" ได้ = ไม่มีการต่อรองราคา
   * แล้วยอดรวมจะใช้ราคาเดิมแทน (ดู recalc)
   */
  function formatMoneyInput(input) {
    if (input.classList.contains('rev-price') && input.value.trim() === '-') { return; }

    var raw = input.value.replace(/[^\d.]/g, '');
    var dot = raw.indexOf('.');

    if (dot !== -1) {
      raw = raw.slice(0, dot + 1) + raw.slice(dot + 1).replace(/\./g, '');
    }

    if (raw === '') { input.value = ''; return; }

    var parts = raw.split('.');
    parts[0] = parts[0].replace(/^0+(?=\d)/, '').replace(/\B(?=(\d{3})+(?!\d))/g, ',');
    input.value = parts.length > 1 ? parts[0] + '.' + parts[1].slice(0, 2) : parts[0];
  }

  document.getElementById('grid').addEventListener('input', function (e) {
    if (e.target.classList.contains('money-in')) { formatMoneyInput(e.target); }
  });

  /* ── ช่องที่ยังต้องกรอกเป็นสีเหลือง กรอกแล้วกลับเป็นขาว ──
     ติดป้าย data-required-fill ไว้ที่ช่องที่ role นั้นต้องกรอก (ดู blade ด้านบน)
     ช่อง Rev.1 พิมพ์ "-" ก็ถือว่ากรอกแล้ว เพราะแปลว่ายืนยันว่าไม่ต่อรอง */
  function paintRequiredField(el) {
    if (!el || !el.classList) { return; }

    // ช่อง Item Code เป็น readonly แต่ยังต้องกรอก (เลือกจากเมนู) จึงดูแค่ disabled
    if (el.disabled) {
      el.classList.remove('needs-fill');
      return;
    }

    el.classList.toggle('needs-fill', String(el.value || '').trim() === '');
  }

  function paintRequiredFields() {
    document.querySelectorAll('[data-required-fill]').forEach(paintRequiredField);

    // ผู้ขอซื้อเป็นปุ่มเลือกคน ไม่ใช่ช่องกรอก จึงดูจากค่าที่ซ่อนไว้
    var pick = document.getElementById('pickReq');
    var hash = document.getElementById('reqHash');
    if (pick && hash) {
      pick.classList.toggle('needs-fill', !pick.disabled && String(hash.value || '').trim() === '');
    }
  }

  document.addEventListener('input', function (e) {
    if (e.target && e.target.hasAttribute && e.target.hasAttribute('data-required-fill')) {
      paintRequiredField(e.target);
    }
  });

  document.addEventListener('change', function (e) {
    if (e.target && e.target.hasAttribute && e.target.hasAttribute('data-required-fill')) {
      paintRequiredField(e.target);
    }
  });

  // ให้ส่วนอื่นเรียกซ้ำได้ เช่น เพิ่มแถวสินค้าใหม่ หรือเลือกผู้ขอซื้อเสร็จ
  window.__paintRequiredFields = paintRequiredFields;
  paintRequiredFields();

  /* ส่งเป็นตัวเลขล้วน ไม่งั้น validation numeric ตกทันที
     แต่ต้องคง "-" ไว้ (= ไม่มีการต่อรองราคา) ให้หลังบ้านรู้ว่ากรอกแล้ว
     เดิมล้าง "-" เป็นค่าว่างตรงนี้ เซิร์ฟเวอร์เลยไม่เคยเห็น "-" เลยสักครั้ง */
  function cleanMoneyValue(input) {
    var value = String(input.value || '').trim();

    return value === '-' ? '-' : value.replace(/,/g, '');
  }

  document.getElementById('docForm').addEventListener('submit', function () {
    document.querySelectorAll('.money-in').forEach(function (input) {
      input.value = cleanMoneyValue(input);
    });
  });

  /* ปุ่มเขียว "ส่งลงนามอนุมัติ" เป็นคนละฟอร์มกับตาราง
     พาค่าที่พิมพ์ค้างอยู่ไปด้วย ผู้ใช้จะได้ไม่ต้องกด "บันทึกราคา Rev.1" ก่อนทุกครั้ง */
  var sendApprovalForm = document.getElementById('sendApprovalForm');

  if (sendApprovalForm) {
    sendApprovalForm.addEventListener('submit', function () {
      sendApprovalForm.querySelectorAll('[data-carried]').forEach(function (old) { old.remove(); });

      document.querySelectorAll('.rev-price').forEach(function (input) {
        if (input.disabled || !input.name) { return; }

        var hidden = document.createElement('input');
        hidden.type = 'hidden';
        hidden.name = input.name;
        hidden.value = cleanMoneyValue(input);
        hidden.setAttribute('data-carried', '');
        sendApprovalForm.appendChild(hidden);
      });
    });
  }

  function recalc() {
    var used = parseInt(document.querySelector('input[name=supplier_count]:checked').value, 10);
    var originalTotals = { 1: 0, 2: 0, 3: 0 };
    var revisedTotals = { 1: 0, 2: 0, 3: 0 };
    var hasRevision = { 1: false, 2: false, 3: false };

    document.querySelectorAll('#rows .item-row').forEach(function (tr) {
      var qty = parseFloat(tr.querySelector('.qty').value) || 0;

      [1, 2, 3].forEach(function (s) {
        var priceEl = tr.querySelector('.price[data-sup="' + s + '"]');
        var baseCell = tr.querySelector('[data-amount-base="' + s + '"]');
        var revCell = tr.querySelector('[data-amount-rev="' + s + '"]');
        if (!priceEl || !baseCell || !revCell) { return; }

        var originalAmount = qty * moneyValue(priceEl.value);
        var revInput = tr.querySelector('.rev-price[data-sup="' + s + '"]');
        var revValue = tr.querySelector('.rev[data-sup="' + s + '"] .rev-value[data-rev]');
        var rev = revInput ? parseMoney(revInput.value) : parseFloat(revValue ? revValue.dataset.rev : '');
        var rowHasRevision = !Number.isNaN(rev);
        var revisedAmount = qty * (rowHasRevision ? rev : moneyValue(priceEl.value));

        writeMoney(baseCell, originalAmount);
        revCell.hidden = !rowHasRevision;
        revCell.textContent = rowHasRevision ? 'Rev.1: ' + money(revisedAmount) : '';

        if (s <= used) {
          originalTotals[s] += originalAmount;
          revisedTotals[s] += revisedAmount;
          hasRevision[s] = hasRevision[s] || rowHasRevision;
        }
      });
    });

    [1, 2, 3].forEach(function (s) {
      var total = document.querySelector('[data-total="' + s + '"]');
      var revisionTotal = document.querySelector('[data-total-rev="' + s + '"]');
      if (total) { writeMoney(total, originalTotals[s]); }
      if (revisionTotal) {
        revisionTotal.hidden = !hasRevision[s];
        revisionTotal.textContent = hasRevision[s] ? 'Rev.1: ' + money(revisedTotals[s]) : '';
        // มีราคาต่อรองแล้วให้ Rev.1 เด่นกว่าราคาเดิม
        if (revisionTotal.parentElement) {
          revisionTotal.parentElement.classList.toggle('has-rev', hasRevision[s]);
        }
      }
    });

    document.querySelectorAll('.amount-base, .amount-rev, .supplier-total strong, .supplier-total-rev').forEach(fitNumericText);
  }

  // ── ช่องเลือก Item Code ────────────────────────────────
  //
  // 🔴 รหัสเต็มคือ "รหัสหมวด" ที่ใช้ซ้ำได้ ไม่ใช่ prefix + เลขคิว (D-042)
  //    ระบบไม่ออกเลขต่อท้ายให้ · Purchasing เลือกรหัสที่มีอยู่ หรือกรอกรหัสใหม่เอง
  //
  // ไม่ใช้ <datalist> เพราะเบราว์เซอร์กรองรายการตามค่าที่อยู่ในช่องเสมอ
  // พอเลือกไปแล้วจะเหลือตัวเลือกเดียว

  var codeMenu = document.getElementById('itemCodeMenu');
  var codeSearch = document.getElementById('itemCodeSearch');
  var codeList = document.getElementById('itemCodeList');
  var codeTarget = null;

  var codeSearchTimer = null;
  var codeSearchToken = 0;

  /** ค่าที่ฝังมากับหน้า = รหัสทั้งหมด เรียงใช้บ่อยไปน้อยแล้วจากฝั่งเซิร์ฟเวอร์ */
  var itemCodeAll = itemCodeOptions;

  function matchCode(opt, needle) {
    return !needle
      || opt.value.toUpperCase().indexOf(needle) !== -1
      || (opt.label || '').toUpperCase().indexOf(needle) !== -1;
  }

  function codeRow(opt) {
    var tail = opt.used > 0 ? 'ใช้แล้ว ' + opt.used + ' ครั้ง' : 'รหัสใหม่';

    return '<button type="button" role="option" data-code="' + escapeHtml(opt.value) + '">'
      + '<b>' + escapeHtml(opt.value) + '</b>'
      + '<span class="sub"><em>' + escapeHtml(opt.label || '') + '</em>'
      + '<i>' + tail + '</i></span>'
      + '</button>';
  }

  /** วาดรายการทั้งหมด เรียงใช้บ่อยไปน้อย · ไม่ตัดจำนวน Manager อยากเห็นครบ */
  function renderCodeList(keyword, existing) {
    // แถวบนสุด: เพิ่มรหัสใหม่ เผื่อ ERP ออกรหัสมาแล้วยังไม่ได้ import
    var html = '<button type="button" class="new-code" data-new-code="1">'
      + '<b>+ เพิ่มรหัสใหม่</b>'
      + '<span class="sub"><em>พิมพ์ลงช่อง Item Code ได้เลย</em></span>'
      + '</button>';

    for (var i = 0; i < existing.length; i++) { html += codeRow(existing[i]); }

    if (!existing.length) {
      html += '<p class="msg">ไม่พบรหัสที่ค้น<br>ลองค้นด้วยชื่อของ เช่น "ถาด"</p>';
    }

    codeList.innerHTML = html;
    codeList.scrollTop = 0;
  }

  /** ปุ่มล้างในช่องโผล่เฉพาะตอนมีรหัสอยู่ */
  function syncCodeClear(input) {
    input.parentElement.classList.toggle('has-code', input.value.trim() !== '');
  }

  /** ปลดล็อกช่องให้พิมพ์รหัสเอง แล้วล็อกกลับเมื่อพิมพ์เสร็จ */
  function startTypingCode(input) {
    closeCodeMenu();
    input.removeAttribute('readonly');
    input.placeholder = 'พิมพ์รหัสใหม่';
    input.focus();
    input.select();
  }

  function stopTypingCode(input) {
    if (input.hasAttribute('readonly')) { return; }

    input.value = input.value.trim();
    input.setAttribute('readonly', 'readonly');
    input.placeholder = 'กดเพื่อเลือกรหัส';
    syncCodeClear(input);
    resizeGridInput(input);
  }

  /** กรองในเครื่องก่อน — ได้ผลทันทีเพราะรหัสทั้งหมดฝังมากับหน้าแล้ว */
  function filterLocal(keyword) {
    var needle = keyword.trim().toUpperCase();

    return needle ? itemCodeAll.filter(function (opt) { return matchCode(opt, needle); }) : itemCodeAll;
  }

  /**
   * ค้นหา — กรองในเครื่องทันที แล้วค่อยเติมผลจากเซิร์ฟเวอร์
   *
   * เซิร์ฟเวอร์ค้นถึง **ชื่อของที่เคยซื้อในระบบ PR เดิม** ด้วย
   * เพราะหนึ่งรหัสใช้กับของหลายแบบ (`SIR-OSM-0003` = 302 แบบ) ทะเบียนเก็บตัวอย่างได้แค่ 3
   * พิมพ์ "ถาด" จึงต้องไปถามระบบเก่าถึงจะเจอ
   */
  function runCodeSearch(keyword) {
    var token = ++codeSearchToken;
    var local = filterLocal(keyword);

    renderCodeList(keyword, local);

    if (keyword.trim().length < 2) { return; }

    codeList.setAttribute('aria-busy', 'true');

    fetch(itemCodeSearchUrl + '?q=' + encodeURIComponent(keyword), {
      headers: { 'Accept': 'application/json' },
    })
      .then(function (response) { return response.ok ? response.json() : { options: [] }; })
      .then(function (data) {
        if (token !== codeSearchToken) { return; }   // ผลเก่ามาช้า ทิ้งไป

        // เติมเฉพาะรหัสที่กรองในเครื่องไม่เจอ (= เจอจากชื่อของในระบบเก่า)
        var seen = {};
        local.forEach(function (opt) { seen[opt.value] = true; });
        var extra = (data.options || []).filter(function (opt) { return !seen[opt.value]; });

        if (extra.length) {
          renderCodeList(keyword, local.concat(extra).sort(function (a, b) { return b.used - a.used; }));
        }
      })
      .catch(function () { /* ค้นในเครื่องได้ผลแล้ว ต่อเซิร์ฟเวอร์ไม่ติดก็ไม่เป็นไร */ })
      .finally(function () { codeList.removeAttribute('aria-busy'); });
  }

  function escapeHtml(text) {
    return String(text).replace(/[&<>"]/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c];
    });
  }

  function openCodeMenu(input) {
    codeTarget = input;
    // เปิดทีไรก็โชว์รหัสครบทุกตัวเสมอ ไม่กรองตามรหัสที่เลือกไปแล้ว
    codeSearch.value = '';
    renderCodeList('', itemCodeAll);
    codeMenu.dataset.open = '1';
    input.parentElement.querySelector('.item-code-open').setAttribute('aria-expanded', 'true');
    placeCodeMenu();
    codeSearch.focus();
  }

  function closeCodeMenu() {
    if (codeMenu.dataset.open !== '1') { return; }

    codeMenu.dataset.open = '0';
    document.querySelectorAll('.item-code-open[aria-expanded="true"]')
      .forEach(function (b) { b.setAttribute('aria-expanded', 'false'); });
    codeTarget = null;
  }

  /**
   * วางเมนูให้ชิดช่อง — ใช้ค่าจาก getBoundingClientRect ตรงๆ ได้
   * เพราะมันคืนพิกัดหลังคิด zoom ของ .sheet มาแล้ว
   */
  function placeCodeMenu() {
    if (!codeTarget) { return; }

    var box = codeTarget.getBoundingClientRect();
    var height = codeMenu.offsetHeight || 340;
    var below = window.innerHeight - box.bottom;

    codeMenu.style.left = Math.round(Math.max(8, Math.min(box.left, window.innerWidth - codeMenu.offsetWidth - 8))) + 'px';
    codeMenu.style.top = Math.round(below < height + 12 && box.top > height + 12
      ? box.top - height - 4
      : box.bottom + 4) + 'px';
  }

  /**
   * เลือกรหัสแล้วใส่ลงช่องเลย — ระบบไม่ต่อเลขท้ายให้ (D-042)
   *
   * รหัสใหม่ที่กรอกเองจะถูกลงทะเบียนตอนบันทึกเอกสาร (ItemCode::registerTyped)
   * แล้วครั้งหน้าจะโผล่ในรายการให้เลือกซ้ำได้เลย
   */
  function chooseItemCode(code) {
    var input = codeTarget;
    if (!input) { return; }

    closeCodeMenu();
    input.value = code;
    syncCodeClear(input);
    resizeGridInput(input);
    if (window.__paintRequiredFields) { window.__paintRequiredFields(); }
  }

  document.getElementById('grid').addEventListener('mousedown', function (e) {
    var control = e.target.closest('.item-code-control');
    if (!control) { return; }

    var input = control.querySelector('.item-code-input');

    // ปุ่มล้าง — อยู่ซ้ายลูกศร ล้างรหัสในช่องนี้อย่างเดียว ไม่เปิดเมนู
    if (e.target.closest('.item-code-clear')) {
      e.preventDefault();
      input.value = '';
      stopTypingCode(input);
      syncCodeClear(input);
      resizeGridInput(input);
      closeCodeMenu();

      return;
    }

    // กำลังพิมพ์รหัสใหม่อยู่ ปล่อยให้คลิกวางเคอร์เซอร์ตามปกติ
    if (!input.hasAttribute('readonly')) { return; }

    e.preventDefault();

    if (codeMenu.dataset.open === '1' && codeTarget === input) { closeCodeMenu(); } else { openCodeMenu(input); }
  });

  // พิมพ์เสร็จแล้วล็อกช่องกลับ
  document.getElementById('grid').addEventListener('focusout', function (e) {
    if (e.target.classList.contains('item-code-input')) { stopTypingCode(e.target); }
  });

  document.getElementById('grid').addEventListener('keydown', function (e) {
    if (! e.target.classList.contains('item-code-input') || e.target.hasAttribute('readonly')) { return; }

    if (e.key === 'Enter' || e.key === 'Escape') {
      e.preventDefault();
      stopTypingCode(e.target);
    }
  });

  // หน่วงไว้หน่อย จะได้ไม่ยิงทุกตัวอักษร
  codeSearch.addEventListener('input', function () {
    clearTimeout(codeSearchTimer);
    codeSearchTimer = setTimeout(function () { runCodeSearch(codeSearch.value); }, 180);
  });

  codeSearch.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') { closeCodeMenu(); return; }

    if (e.key === 'Enter') {
      e.preventDefault();
      var first = codeList.querySelector('button[data-code]');
      if (first) { chooseItemCode(first.dataset.code); }
    }
  });

  codeList.addEventListener('click', function (e) {
    // "+ เพิ่มรหัสใหม่" -> ปลดล็อกช่อง Item Code ให้พิมพ์เอง
    if (e.target.closest('button[data-new-code]')) {
      if (codeTarget) { startTypingCode(codeTarget); }

      return;
    }

    var button = e.target.closest('button[data-code]');
    if (button) { chooseItemCode(button.dataset.code); }
  });

  document.addEventListener('mousedown', function (e) {
    if (!codeMenu.contains(e.target) && !e.target.closest('.item-code-control')) { closeCodeMenu(); }
  });

  // ปิดเมนูเมื่อจอขยับ — ไม่ใช้ capture เพราะจะไปโดน scroll ของตัวรายการเองด้วย
  window.addEventListener('resize', closeCodeMenu);
  window.addEventListener('scroll', closeCodeMenu);
  document.querySelectorAll('.sheet-scroll').forEach(function (box) {
    box.addEventListener('scroll', closeCodeMenu);
  });

  document.getElementById('grid').addEventListener('input', function (e) {
    if (e.target.matches('input.cell')) { resizeGridInput(e.target); }
    if (e.target.matches('input[name^="suppliers"][name$="[name]"]')) { syncFileSupplierName(e.target); }
    if (e.target.classList.contains('qty') || e.target.classList.contains('price') || e.target.classList.contains('rev-price')) { recalc(); }
    if (e.target.classList.contains('description-cell')) { resizeDescription(e.target); }
  });

  function syncFileSupplierName(input) {
    var slotMatch = input.name.match(/^suppliers\[(\d+)]\[name]$/);
    if (!slotMatch) { return; }

    var label = document.querySelector('[data-file-supplier-name="' + slotMatch[1] + '"]');
    if (label) { label.textContent = input.value.trim() || 'ยังไม่ระบุ'; }
  }

  var gridMeasureCanvas = document.createElement('canvas');
  var gridMeasureContext = gridMeasureCanvas.getContext('2d');

  function resizeGridInput(input) {
    var style = window.getComputedStyle(input);
    var value = input.value || input.placeholder || '';
    var minimumWidth = 90;

    if (input.closest('.supplier-head')) { minimumWidth = 240; }
    if (input.classList.contains('qty')) { minimumWidth = 60; }
    if (input.classList.contains('unit')) { minimumWidth = 48; }
    if (input.name.includes('[item_code]')) { minimumWidth = 110; }

    gridMeasureContext.font = style.font;
    var horizontalPadding = parseFloat(style.paddingLeft) + parseFloat(style.paddingRight) + 4;
    input.style.width = Math.max(minimumWidth, Math.ceil(gridMeasureContext.measureText(value).width + horizontalPadding)) + 'px';
  }

  function resizeGridInputs(scope) {
    scope.querySelectorAll('input.cell').forEach(resizeGridInput);
    // ปุ่มล้างในช่องรหัสโผล่เฉพาะแถวที่มีรหัสอยู่
    scope.querySelectorAll('.item-code-input').forEach(syncCodeClear);
  }

  function resizeDescription(textarea, minimumHeight) {
    var minHeight = minimumHeight || 48;
    textarea.style.height = 'auto';
    textarea.style.height = Math.max(minHeight, textarea.scrollHeight) + 'px';
  }

  function resizeDescriptions(scope, minimumHeight) {
    scope.querySelectorAll('.description-cell').forEach(function (textarea) {
      resizeDescription(textarea, minimumHeight);
    });
  }

  function resizeMultilineField(textarea, minimumHeight) {
    var minHeight = minimumHeight || 42;
    textarea.style.height = 'auto';
    textarea.style.height = Math.max(minHeight, textarea.scrollHeight) + 'px';
  }

  function resizeMultilineFields(scope, minimumHeight) {
    scope.querySelectorAll('.multiline-field').forEach(function (textarea) {
      resizeMultilineField(textarea, minimumHeight);
    });
  }

  function syncTermRows() {
    for (var rowIndex = 1; rowIndex <= 3; rowIndex++) {
      var termRows = Array.from(document.querySelectorAll('.supplier-terms .term-row:nth-child(' + rowIndex + ')'));
      termRows.forEach(function (row) { row.style.minHeight = ''; });
      var tallest = Math.max.apply(null, termRows.map(function (row) { return row.offsetHeight; }));
      termRows.forEach(function (row) { row.style.minHeight = tallest + 'px'; });
    }
  }

  document.addEventListener('input', function (e) {
    if (!e.target.classList.contains('multiline-field')) { return; }
    resizeMultilineField(e.target);
    if (e.target.closest('.supplier-terms')) { syncTermRows(); }
  });

  window.addEventListener('beforeprint', function () {
    resizeDescriptions(document, 27);
    resizeMultilineFields(document, 23);
    syncTermRows();
  });

  window.addEventListener('afterprint', function () {
    resizeDescriptions(document, 48);
    resizeMultilineFields(document, 42);
    syncTermRows();
  });

  // ── จำนวนรายการสินค้า ───────────────────────────────────
  var rows = document.getElementById('rows');
  var itemCountInput = document.getElementById('itemCount');
  var newSeq = {{ $doc->items->count() }};

  function addItemRow(shouldFocus) {
    var key = 'new' + (++newSeq);
    var tr = document.createElement('tr');
    tr.className = 'item-row';
    var html = '<td class="n"><span class="row-no"></span>'
      + '<button type="button" class="rm" aria-label="ลบรายการ">'
      + '<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round"><path d="M18 6 6 18M6 6l12 12"/></svg>'
      + '</button></td>'
      + itemCodeInput(key)
      + descriptionInput('items[' + key + '][description]')
      + '<td><div class="qty-cell">'
      + '<input type="number" step="any" min="0" class="cell num-in qty" data-required-fill name="items[' + key + '][qty]" aria-label="จำนวน">'
      + '<input type="text" class="cell unit" data-required-fill name="items[' + key + '][unit]" aria-label="หน่วย">'
      + '</div></td>';

    for (var s = 1; s <= 3; s++) {
      html += '<td data-sup="' + s + '"><input type="text" inputmode="decimal" autocomplete="off" class="cell num-in price money-in" data-required-fill data-sup="' + s + '" name="items[' + key + '][prices][' + s + ']"></td>'
        + '<td data-sup="' + s + '" class="rev"><span class="rev-value" data-rev="">—</span></td>'
        + '<td data-sup="' + s + '" class="amount"><span class="amount-base" data-amount-base="' + s + '">0.00</span><small class="amount-rev" data-amount-rev="' + s + '" hidden></small></td>';
    }

    tr.innerHTML = html;
    var placeholder = rows.querySelector('.placeholder-row');
    rows.insertBefore(tr, placeholder);
    resizeGridInputs(tr);
    resizeDescriptions(tr);
    if (window.__paintRequiredFields) { window.__paintRequiredFields(); }
    if (shouldFocus) { tr.querySelector('input').focus(); }
  }

  function cellInput(type, name, value) {
    return '<td><input type="' + type + '" class="cell" name="' + name + '" value="' + value + '"></td>';
  }

  function itemCodeInput(key) {
    var base = 'items[' + key + ']';

    return '<td><div class="item-code-control">'
      + '<input type="text" class="cell item-code-input" autocomplete="off" readonly'
      + ' name="' + base + '[item_code]" data-required-fill placeholder="กดเพื่อเลือกรหัส" aria-label="Item Code">'
      + '<button type="button" class="item-code-clear" aria-label="ล้างรหัสในช่องนี้" title="ล้างรหัส">'
      + '<svg viewBox="0 0 24 24"><path d="M18 6 6 18M6 6l12 12"/></svg>'
      + '</button>'
      + '<button type="button" class="item-code-open" aria-expanded="false" aria-label="เลือกรหัสจากรายการ">'
      + '<svg viewBox="0 0 24 24"><path d="m6 9 6 6 6-6"/></svg>'
      + '</button>'
      + '</div></td>';
  }

  function descriptionInput(name) {
    return '<td><textarea class="cell description-cell" data-required-fill name="' + name + '" rows="1" maxlength="2000" aria-label="รายละเอียดสินค้า"></textarea></td>';
  }

  rows.addEventListener('click', function (e) {
    var btn = e.target.closest('.rm');
    if (!btn) { return; }

    if (rows.querySelectorAll('.item-row').length === 1) {
      alert('ต้องมีอย่างน้อย 1 รายการ');
      return;
    }

    if (rowHasValue(btn.closest('tr')) && !confirm('ลบข้อมูลรายการนี้ออกจากเอกสาร?')) { return; }

    btn.closest('tr').remove();
    itemCountInput.value = rows.querySelectorAll('.item-row').length;
    ensureVisualRows();
    renumber();
    recalc();
  });

  function rowHasValue(tr) {
    return Array.from(tr.querySelectorAll('input, textarea')).some(function (control) {
      return control.value.trim() !== '';
    });
  }

  function setItemCount(value, confirmRemoval, focusNewRow) {
    var target = Math.max(1, Math.min(50, parseInt(value, 10) || 1));
    var itemRows = Array.from(rows.querySelectorAll('.item-row'));

    if (target < itemRows.length) {
      var removedRows = itemRows.slice(target);
      var hasData = removedRows.some(rowHasValue);

      if (confirmRemoval && hasData && !confirm('ลดจำนวนรายการจะลบข้อมูลในแถวท้าย ยืนยันหรือไม่?')) {
        itemCountInput.value = itemRows.length;
        return;
      }

      removedRows.forEach(function (tr) { tr.remove(); });
    }

    while (rows.querySelectorAll('.item-row').length < target) {
      addItemRow(focusNewRow && rows.querySelectorAll('.item-row').length === target - 1);
    }

    itemCountInput.value = target;
    ensureVisualRows();
    renumber();
    paintSuppliers();
    recalc();
  }

  itemCountInput.addEventListener('change', function () {
    setItemCount(itemCountInput.value, true, false);
  });

  function renumber() {
    rows.querySelectorAll('.item-row').forEach(function (tr, i) {
      tr.querySelector('.row-no').textContent = i + 1;
      tr.querySelector('.rm').setAttribute('aria-label', 'ลบรายการที่ ' + (i + 1));
    });
  }

  function ensureVisualRows() {
    rows.querySelectorAll('.placeholder-row').forEach(function (tr) { tr.remove(); });
    var missing = Math.max(0, {{ $minimum_rows }} - rows.querySelectorAll('.item-row').length);

    for (var row = 0; row < missing; row++) {
      var tr = document.createElement('tr');
      tr.className = 'placeholder-row';
      tr.setAttribute('aria-hidden', 'true');
      var html = '';

      for (var cell = 0; cell < 13; cell++) {
        var supplier = cell >= 4 ? Math.floor((cell - 4) / 3) + 1 : 0;
        html += '<td' + (supplier ? ' data-sup="' + supplier + '"' : '') + '></td>';
      }

      tr.innerHTML = html;
      rows.appendChild(tr);
    }
  }

  // ── ซูมเอกสาร ค่าเริ่มต้น 70% ปรับครั้งละ 5% ──────────
  var zoomSteps = [50, 55, 60, 65, 70, 75, 80, 85, 90, 95, 100, 105, 110, 115, 120, 125];
  var zoomIndex = zoomSteps.indexOf(70);
  var sheet = document.querySelector('.sheet');
  var zoomValue = document.getElementById('zoomValue');
  var zoomOut = document.getElementById('zoomOut');
  var zoomIn = document.getElementById('zoomIn');

  function applyZoom() {
    var zoom = zoomSteps[zoomIndex];
    sheet.style.zoom = zoom + '%';
    zoomValue.textContent = zoom + '%';
    zoomOut.disabled = zoomIndex === 0;
    zoomIn.disabled = zoomIndex === zoomSteps.length - 1;
  }

  zoomOut.addEventListener('click', function () {
    if (zoomIndex > 0) { zoomIndex--; applyZoom(); }
  });

  zoomIn.addEventListener('click', function () {
    if (zoomIndex < zoomSteps.length - 1) { zoomIndex++; applyZoom(); }
  });

  // ── เลือกพนักงานผู้ขอซื้อ ──────────────────────────────
  var picker = document.getElementById('picker');
  var pickerSearch = document.getElementById('pickerSearch');
  var pickerList = document.getElementById('pickerList');
  var reqHash = document.getElementById('reqHash');
  var pickBtn = document.getElementById('pickReq');
  var timer = null;

  function openRequesterPicker() {
    pickerSearch.value = '';
    picker.showModal();
    loadPeople('');
    pickerSearch.focus();
  }

  pickBtn.addEventListener('click', openRequesterPicker);

  // ชื่อในการ์ดผู้ขอซื้อ (มีได้หลายขั้น) กดเพื่อเลือกคน — ส่วนรูปเป็นปุ่มซูมแยกต่างหาก (D-078)
  document.querySelectorAll('[data-requester-name]').forEach(function (button) {
    button.addEventListener('click', function () {
      if (!button.disabled) { openRequesterPicker(); }
    });
  });

  document.getElementById('pickerClose').addEventListener('click', function () { picker.close(); });
  picker.addEventListener('click', function (e) { if (e.target === picker) { picker.close(); } });

  pickerSearch.addEventListener('input', function () {
    clearTimeout(timer);
    timer = setTimeout(function () { loadPeople(pickerSearch.value.trim()); }, 220);
  });

  function loadPeople(keyword) {
    pickerList.innerHTML = '<p class="msg">กำลังค้นหา</p>';

    fetch('{{ route('documents.people') }}?q=' + encodeURIComponent(keyword), { headers: { Accept: 'application/json' } })
      .then(function (r) { return r.json(); })
      .then(function (d) { renderPeople(d.people || []); })
      .catch(function () { pickerList.innerHTML = '<p class="msg">โหลดรายชื่อไม่ได้</p>'; });
  }

  function renderPeople(people) {
    if (people.length === 0) {
      pickerList.innerHTML = '<p class="msg">ไม่พบพนักงานที่ค้นหา</p>';
      return;
    }

    pickerList.innerHTML = '';

    people.forEach(function (p) {
      var row = document.createElement('div');
      row.className = 'picker-row';

      // รูปเป็นปุ่มแยก กดแล้วซูมดูหน้าคนก่อนตัดสินใจเลือก (D-078)
      // ปุ่มซ้อนปุ่มไม่ได้ จึงต้องเป็นพี่น้องกัน ไม่ใช่ปุ่มเดียวครอบทั้งแถว
      var pic = document.createElement('button');
      pic.type = 'button';
      pic.className = 'pic avatar-zoom';
      pic.dataset.avatarZoom = p.avatar;
      pic.dataset.avatarName = p.name;
      pic.dataset.avatarCode = p.code || '';
      pic.setAttribute('aria-label', 'ดูรูปของ ' + p.name);
      var img = document.createElement('img');
      img.src = p.avatar; img.alt = ''; img.loading = 'lazy';
      pic.appendChild(img);

      var btn = document.createElement('button');
      btn.type = 'button';
      btn.className = 'picker-choose';

      var info = document.createElement('div');
      info.className = 'info';
      var b = document.createElement('b');
      b.textContent = p.name;
      var sp = document.createElement('span');
      sp.textContent = p.department + ' · ' + p.code;
      info.appendChild(b); info.appendChild(sp);

      btn.appendChild(info);
      row.appendChild(pic); row.appendChild(btn);

      // เลือกแล้วประทับ "แผนก" ลงเอกสาร ไม่ใช่ชื่อคน
      btn.addEventListener('click', function () {
        reqHash.value = p.id_thai_hash;
        pickBtn.innerHTML = '<span class="dept"></span><span class="who"></span>';
        pickBtn.querySelector('.dept').textContent = p.department;
        pickBtn.querySelector('.who').textContent = '— ' + p.name;
        updateRouteRequester(p);
        if (window.__paintRequiredFields) { window.__paintRequiredFields(); }
        picker.close();
      });

      pickerList.appendChild(row);
    });
  }

  /**
   * อัปเดตการ์ดผู้ขอซื้อ "ทุกขั้น" ที่เป็น Role User พร้อมกัน
   *
   * เส้นทางมีขั้นของ User ได้หลายขั้น (คัดเลือกรายการ · ยืนยันรายการ) และเป็นคนเดียวกันเสมอ
   * เลือกคนครั้งเดียวจึงต้องขึ้นชื่อครบทุกการ์ด (D-078)
   */
  function updateRouteRequester(person) {
    document.querySelectorAll('[data-requester-card]').forEach(function (card) {
      var avatarButton = card.querySelector('[data-requester-avatar]');
      var image = card.querySelector('[data-requester-image]');
      var placeholder = card.querySelector('[data-requester-placeholder]');
      var name = card.querySelector('[data-requester-name]');

      if (avatarButton && image) {
        image.src = person.avatar;
        avatarButton.dataset.avatarZoom = person.avatar;
        avatarButton.dataset.avatarName = person.name;
        avatarButton.dataset.avatarCode = person.code || '';
        avatarButton.hidden = false;
      }

      if (placeholder) { placeholder.hidden = true; }
      if (name) { name.textContent = person.name; }
    });
  }

  // ── ประทับ / ลบลายเซ็น ────────────────────────────────
  var signatureDialog = document.getElementById('signatureDialog');
  var signatureStepId = document.getElementById('signatureStepId');
  var signatureId = document.getElementById('signatureId');

  document.querySelectorAll('.signature-stamp').forEach(function (button) {
    button.addEventListener('click', function () {
      signatureStepId.value = button.dataset.signatureStep;
      document.getElementById('signatureRole').textContent = button.dataset.signatureRole;
      signatureDialog.showModal();
    });
  });

  document.getElementById('signatureClose').addEventListener('click', function () { signatureDialog.close(); });
  document.getElementById('signatureCancel').addEventListener('click', function () { signatureDialog.close(); });
  signatureDialog.addEventListener('click', function (e) { if (e.target === signatureDialog) { signatureDialog.close(); } });

  document.querySelectorAll('.signature-remove').forEach(function (button) {
    button.addEventListener('click', function () {
      if (!confirm('ลบลายเซ็นและวันที่ประทับออกจากเอกสาร?')) { return; }
      signatureId.value = button.dataset.signatureId;
      document.getElementById('signatureDeleteSubmit').click();
    });
  });

  // ── ผู้ขอซื้อเลือก Supplier: บริษัทที่ไม่ได้เลือกจางเป็นสีเทา ──
  var supplierSelectionBoxes = document.querySelectorAll('input[name="selected_suppliers[]"]');

  /**
   * เทาช่องของบริษัทที่ผู้ขอซื้อไม่ได้เลือก — ทำหลังขั้น "ยืนยันรายการ" เท่านั้น
   *
   * ขั้นคัดเลือก · ต่อรองราคา · ยืนยันรายการ ต้องเห็นครบทั้ง 3 เจ้าเพื่อเทียบราคา
   * แท็บที่ยังเลือกได้อ่านจากปุ่มสดๆ ส่วนแท็บอื่นอ่านจากผลที่บันทึกไว้แล้ว
   */
  function syncSupplierSelectionState() {
    var selectedSlots = supplierSelectionBoxes.length
      ? Array.from(supplierSelectionBoxes)
        .filter(function (choice) { return choice.checked; })
        .map(function (choice) { return choice.value; })
      : selectedSupplierSlots;
    var hasSelection = muteUnselectedSuppliers && selectedSlots.length > 0;

    document.querySelectorAll('[data-sup]').forEach(function (element) {
      var slot = String(element.dataset.sup || '');
      element.classList.toggle(
        'supplier-selection-muted',
        hasSelection && !selectedSlots.includes(slot)
      );
    });
  }

  supplierSelectionBoxes.forEach(function (choice) {
    choice.addEventListener('change', syncSupplierSelectionState);
  });
  syncSupplierSelectionState();

  // ── ยืนยันส่งเอกสารจากขั้นยืนยันรายการไปลงนามอนุมัติ ────
  var sendConfirmationBtn = document.getElementById('sendConfirmationBtn');
  var sendConfirmationDialog = document.getElementById('sendConfirmationDialog');

  if (sendConfirmationBtn && sendConfirmationDialog) {
    sendConfirmationBtn.addEventListener('click', function () { sendConfirmationDialog.showModal(); });
    document.getElementById('sendConfirmationClose').addEventListener('click', function () { sendConfirmationDialog.close(); });
    document.getElementById('sendConfirmationCancel').addEventListener('click', function () { sendConfirmationDialog.close(); });
    sendConfirmationDialog.addEventListener('click', function (event) {
      if (event.target === sendConfirmationDialog) { sendConfirmationDialog.close(); }
    });
  }

  // ── ยืนยันส่งเอกสารไปขั้นต่อรองราคา ────────────────────
  var sendNegotiationBtn = document.getElementById('sendNegotiationBtn');
  var sendNegotiationDialog = document.getElementById('sendNegotiationDialog');

  if (sendNegotiationBtn && sendNegotiationDialog) {
    sendNegotiationBtn.addEventListener('click', function () { sendNegotiationDialog.showModal(); });
    document.getElementById('sendNegotiationClose').addEventListener('click', function () { sendNegotiationDialog.close(); });
    document.getElementById('sendNegotiationCancel').addEventListener('click', function () { sendNegotiationDialog.close(); });
    sendNegotiationDialog.addEventListener('click', function (event) {
      if (event.target === sendNegotiationDialog) { sendNegotiationDialog.close(); }
    });
  }

  // ── ยืนยันส่งเอกสารจากต่อรองราคาไปลงนามอนุมัติ ────────
  var sendApprovalBtn = document.getElementById('sendApprovalBtn');
  var sendApprovalDialog = document.getElementById('sendApprovalDialog');

  if (sendApprovalBtn && sendApprovalDialog) {
    sendApprovalBtn.addEventListener('click', function () {
      if (validateNegotiationForm()) { sendApprovalDialog.showModal(); }
    });
    document.getElementById('sendApprovalClose').addEventListener('click', function () { sendApprovalDialog.close(); });
    document.getElementById('sendApprovalCancel').addEventListener('click', function () { sendApprovalDialog.close(); });
    sendApprovalDialog.addEventListener('click', function (event) {
      if (event.target === sendApprovalDialog) { sendApprovalDialog.close(); }
    });
  }

  // ── ยืนยันการลงนามอนุมัติ (Mgr. Purchasing และ CEO) ────
  var approveBtn = document.getElementById('approveBtn');
  var approveDialog = document.getElementById('approveDialog');

  if (approveBtn && approveDialog) {
    approveBtn.addEventListener('click', function () { approveDialog.showModal(); });
    document.getElementById('approveClose').addEventListener('click', function () { approveDialog.close(); });
    document.getElementById('approveCancel').addEventListener('click', function () { approveDialog.close(); });
    approveDialog.addEventListener('click', function (event) {
      if (event.target === approveDialog) { approveDialog.close(); }
    });
  }

  /**
   * ตรวจก่อนส่งลงนามอนุมัติ — หัวข้อ 7 ต้องตอบ "มี/ไม่มี" ให้ครบทุกบริษัท
   *
   * ผู้ลงนามต้องแยกออกว่า "ไม่มีใบเสนอราคา" กับ "ลืมแนบ" ต่างกัน
   * และแต่ละเจ้าตอบไม่เหมือนกันได้ จึงต้องเช็คทีละการ์ด
   */
  function validateNegotiationForm() {
    var quoteSection = document.querySelector('[data-view-anchor="attachments-negotiate"]');
    if (!quoteSection) { return true; }

    quoteSection.classList.remove('is-invalid-step');

    var cards = Array.prototype.slice.call(quoteSection.querySelectorAll('[data-quote-ask]'));
    var unanswered = 0;

    cards.forEach(function (card) {
      card.classList.remove('is-unanswered');

      if (card.querySelector('.quote-opt.is-on')) { return; }

      card.classList.add('is-unanswered');
      unanswered++;
    });

    if (!unanswered) { hideValidationErrors(); return true; }

    quoteSection.classList.add('is-invalid-step');
    showValidationErrors(['เลือก "มี" หรือ "ไม่มี" ในหัวข้อแนบไฟล์ใบเสนอราคา (ต่อรองราคา) ให้ครบทุกบริษัท']);
    quoteSection.scrollIntoView({ behavior: 'smooth', block: 'center' });

    return false;
  }

  function hideValidationErrors() {
    var alertBox = document.getElementById('validationAlert');
    if (alertBox) { alertBox.hidden = true; alertBox.innerHTML = ''; }
  }

  // ── ตรวจข้อมูลก่อนส่ง / เลือก Supplier ─────────────────
  function showValidationErrors(messages) {
    var alertBox = document.getElementById('validationAlert');
    alertBox.innerHTML = '';
    alertBox.hidden = false;

    var title = document.createElement('b');
    title.textContent = 'กรุณากรอกข้อมูลที่ทำเครื่องหมายสีแดงให้ครบ';
    alertBox.appendChild(title);

    var list = document.createElement('ul');
    messages.forEach(function (message) {
      var item = document.createElement('li');
      item.textContent = message;
      list.appendChild(item);
    });
    alertBox.appendChild(list);
  }

  function markMissing(control, message, errors) {
    if (!control || control.disabled || control.value.trim() !== '') { return; }
    control.classList.add('is-invalid');
    errors.push(message);
  }

  function validatePurchaseForm() {
    var errors = [];
    var used = parseInt(document.querySelector('input[name=supplier_count]:checked').value, 10);
    document.querySelectorAll('.is-invalid').forEach(function (element) { element.classList.remove('is-invalid'); });
    document.querySelectorAll('.purchase-signature-invalid').forEach(function (element) { element.classList.remove('purchase-signature-invalid'); });

    if (document.getElementById('reqHash').value.trim() === '') {
      document.getElementById('pickReq').classList.add('is-invalid');
      errors.push('เลือกผู้ขอซื้อ');
    }
    markMissing(document.getElementById('purpose'), 'กรอก Purpose of the Purchase', errors);

    for (var supplier = 1; supplier <= used; supplier++) {
      markMissing(document.querySelector('[name="suppliers[' + supplier + '][name]"]'), 'กรอกชื่อ Supplier ที่ ' + supplier, errors);
      markMissing(document.querySelector('[name="suppliers[' + supplier + '][lead_time]"]'), 'กรอก Lead Time ผู้ขายที่ ' + supplier, errors);
      markMissing(document.querySelector('[name="suppliers[' + supplier + '][term_of_payment]"]'), 'กรอก Term of Payment ผู้ขายที่ ' + supplier, errors);
      markMissing(document.querySelector('[name="suppliers[' + supplier + '][remark]"]'), 'กรอก Remark ผู้ขายที่ ' + supplier, errors);
    }

    document.querySelectorAll('#rows .item-row').forEach(function (row, index) {
      markMissing(row.querySelector('.item-code-input'), 'กรอก Item Code รายการที่ ' + (index + 1), errors);
      markMissing(row.querySelector('.description-cell'), 'กรอก Item Description รายการที่ ' + (index + 1), errors);
      markMissing(row.querySelector('.unit'), 'กรอกหน่วยรายการที่ ' + (index + 1), errors);

      var qty = row.querySelector('.qty');
      if (!qty.value.trim() || parseFloat(qty.value) <= 0) {
        qty.classList.add('is-invalid');
        errors.push('กรอกจำนวนมากกว่า 0 รายการที่ ' + (index + 1));
      }

      for (var supplier = 1; supplier <= used; supplier++) {
        markMissing(row.querySelector('.price[data-sup="' + supplier + '"]'), 'กรอก Unit Price ผู้ขายที่ ' + supplier + ' รายการที่ ' + (index + 1), errors);
      }
    });

    // หัวข้อ 6 — ทุกบริษัทที่เปิดใช้ต้องมีใบเสนอราคาแนบ ไม่งั้นผู้ขอซื้อไม่มีอะไรให้เทียบ
    document.querySelectorAll('#fileArea .file-card').forEach(function (card) {
      card.classList.remove('is-invalid');
      var slot = parseInt(card.dataset.sup, 10);
      if (slot > used) { return; }

      if (!card.querySelector('.file-row')) {
        card.classList.add('is-invalid');
        errors.push('แนบไฟล์ใบเสนอราคาของผู้ขายที่ ' + slot);
      }
    });

    var purchaseSignature = document.querySelector('[data-signature-duty="create"]');
    if (purchaseSignature && !purchaseSignature.querySelector('.signature-image')) {
      purchaseSignature.classList.add('purchase-signature-invalid');
      errors.push('ประทับลายเซ็น Purchasing จัดทำเอกสาร');
    }

    if (errors.length > 0) {
      showValidationErrors(errors);
      var firstInvalid = document.querySelector('.is-invalid, .purchase-signature-invalid');
      if (firstInvalid) {
        firstInvalid.scrollIntoView({ behavior: 'smooth', block: 'center' });
        if (typeof firstInvalid.focus === 'function') { firstInvalid.focus({ preventScroll: true }); }
      }
      return false;
    }

    return true;
  }

  // ปุ่มส่งสีเขียวทุกจุดต้องถามยืนยันด้วย modal เหมือนกันหมด
  var sendBtn = document.getElementById('sendBtn');
  var sendRequesterDialog = document.getElementById('sendRequesterDialog');

  if (sendBtn && sendRequesterDialog) {
    sendBtn.addEventListener('click', function () {
      if (validatePurchaseForm()) { sendRequesterDialog.showModal(); }
    });
    document.getElementById('sendRequesterCancel').addEventListener('click', function () { sendRequesterDialog.close(); });
    document.getElementById('sendRequesterClose').addEventListener('click', function () { sendRequesterDialog.close(); });
  }

  // ขั้นคัดเลือกรายการและขั้นยืนยันรายการ ต้องเลือก Supplier ก่อนถึงจะลงนามได้
  document.getElementById('docForm').addEventListener('submit', function (e) {
    if (supplierSelectionBoxes.length === 0) { return; }
    if (e.submitter && e.submitter.value === 'remove_signature') { return; }

    var picked = document.querySelectorAll('input[name="selected_suppliers[]"]:checked');
    if (picked.length > 0) { return; }

    e.preventDefault();
    signatureDialog.close();
    document.querySelectorAll('.supplier-select-control').forEach(function (label) { label.classList.add('is-invalid'); });
    showValidationErrors(['เลือก Supplier 1 บริษัทก่อนลงนาม']);
    document.querySelector('.supplier-head').scrollIntoView({ behavior: 'smooth', block: 'center' });
  });

  var delDocBtn = document.getElementById('delDocBtn');
  var deleteDocumentLabel = {{ \Illuminate\Support\Js::from($doc->referenceLabel()) }};

  if (delDocBtn) {
    delDocBtn.addEventListener('click', function () {
      if (confirm('ลบ ' + deleteDocumentLabel + '? ไฟล์แนบและลายเซ็นขั้นจัดทำเอกสารจะถูกลบด้วย')) {
        document.getElementById('delDocForm').submit();
      }
    });
  }

  // ── ปฏิเสธ ─────────────────────────────────────────────
  var rejectBtn = document.getElementById('rejectBtn');

  if (rejectBtn) {
    var rejectDialog = document.getElementById('rejectDialog');
    var rejectReason = document.getElementById('rejectReason');

    rejectBtn.addEventListener('click', function () {
      rejectReason.value = '';
      rejectDialog.showModal();
      rejectReason.focus();
    });

    document.getElementById('rejectCancel').addEventListener('click', function () {
      rejectDialog.close();
    });

    document.getElementById('rejectForm').addEventListener('submit', function (e) {
      if (rejectReason.value.trim() === '') {
        e.preventDefault();
        rejectReason.focus();
      }
    });
  }

  ensureVisualRows();
  resizeGridInputs(document);
  resizeDescriptions(document);
  resizeMultilineFields(document);
  syncTermRows();
  applyZoom();
  paintSuppliers();
  recalc();
</script>
@endsection
