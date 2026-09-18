@extends('layouts.portal')

@section('title', 'ภาพรวม · QuoteCompare')
@section('heading', 'สวัสดี ' . $me->displayName())

@section('styles')
  /* ตัวเลขสรุป — วางบนพื้นหน้า ไม่ต้องใส่การ์ดครอบ */
  .stat { display: flex; align-items: baseline; gap: 8px; margin-bottom: 22px; }
  .stat .k { font-size: 13.5px; color: var(--muted); }
  .stat .v { font-size: 19px; font-weight: 700; letter-spacing: -.01em; }

  .panel {
    background: var(--surface);
    border: 1px solid var(--line);
    border-radius: var(--radius);
    box-shadow: var(--shadow-sm);
  }

  .who { display: flex; align-items: center; gap: 15px; padding: 20px 22px; }
  .who .pic {
    width: 52px; height: 52px; border-radius: 13px; flex: none; overflow: hidden;
    background: var(--surface-soft);
  }
  .who .pic img { width: 100%; height: 100%; object-fit: cover; display: block; }
  .who .name { font-size: 17px; font-weight: 700; letter-spacing: -.01em; }
  .who .sub { font-size: 13.5px; color: var(--muted); }

  .facts {
    display: grid; gap: 16px 30px;
    grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
    padding: 18px 22px 20px;
    border-top: 1px solid var(--line-soft);
  }
  .facts dt { font-size: 12.5px; color: var(--muted); }
  .facts dd { margin: 1px 0 0; font-size: 14.5px; }

  /* ลายเซ็น — เก็บเป็น data URL มาจาก Insight */
  .sign { padding: 16px 22px 20px; border-top: 1px solid var(--line-soft); }
  .sign .k { font-size: 12.5px; color: var(--muted); margin-bottom: 8px; }
  .sign img {
    display: block; max-width: 260px; max-height: 68px;
    object-fit: contain; object-position: left center;
  }
  .sign .none { margin: 0; font-size: 14px; color: var(--muted); }

  /* แถบท้าย — ส่งผู้ใช้ไปแก้ข้อมูลที่ Insight */
  .edit-note {
    display: flex; align-items: center; justify-content: space-between;
    gap: 12px 16px; flex-wrap: wrap;
    padding: 14px 22px;
    background: var(--surface-soft);
    border-top: 1px solid var(--line-soft);
    font-size: 13.5px; color: var(--muted);
  }
  .edit-note .btn svg { width: 14px; height: 14px; }
@endsection

@section('content')

  @if ($activeEmployees !== null)
    <div class="stat">
      <span class="k">ทำงานอยู่</span>
      <span class="v num">{{ number_format($activeEmployees) }}</span>
      <span class="k">คน</span>
    </div>
  @endif

  <section class="panel">
    <div class="who">
      @include('avatar', [
        'url' => $me->avatarUrl(),
        'class' => 'pic',
        'zoomable' => (bool) $me->profilePictureUrl(),
        'alt' => $me->displayName(),
      ])
      <div style="min-width:0;flex:1">
        <div class="name">{{ $me->displayName() }}</div>
        <div class="sub">{{ $me->position ?: 'พนักงาน' }}</div>
      </div>
      <span class="pill {{ $me->isAdmin() ? 'pill-teal' : 'pill-mute' }}">
        {{ $me->isAdmin() ? 'ผู้ดูแลระบบ' : 'ผู้ใช้ทั่วไป' }}
      </span>
    </div>

    <dl class="facts">
      <div>
        <dt>รหัสพนักงาน</dt>
        <dd class="num">{{ $me->employee_code }}</dd>
      </div>
      <div>
        <dt>แผนก</dt>
        <dd>{{ $me->department ?: '—' }}</dd>
      </div>
      <div>
        <dt>บริษัท</dt>
        <dd>{{ implode(' · ', $me->companyList()) ?: '—' }}</dd>
      </div>
      <div>
        <dt>อีเมล</dt>
        <dd>{{ $me->email ?: '—' }}</dd>
      </div>
    </dl>

    <div class="sign">
      <div class="k">ลายเซ็น</div>
      @if ($me->signature)
        <img src="{{ $me->signature }}" alt="ลายเซ็นของ {{ $me->displayName() }}">
      @else
        <p class="none">ยังไม่ได้บันทึกลายเซ็น</p>
      @endif
    </div>

    <div class="edit-note">
      <span>แก้ไขข้อมูลได้ที่ Supavut Insight</span>
      <a class="btn btn-quiet" href="{{ config('app.insight_url') }}" target="_blank" rel="noopener noreferrer">
        เข้าสู่ Supavut Insight
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
          <path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/>
          <path d="M15 3h6v6"/><path d="M10 14 21 3"/>
        </svg>
      </a>
    </div>
  </section>

@endsection
