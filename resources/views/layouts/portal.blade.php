{{--
  โครงหน้าหลังล็อกอิน — sidebar ขาวด้านซ้าย + เนื้อหาด้านขวา
  ไม่มีแถบ topbar แยก : หัวข้อหน้าอยู่ในคอลัมน์เนื้อหาเลย เพื่อลดแถบแนวนอนที่ไม่จำเป็น
--}}
<!doctype html>
<html lang="th">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="csrf-token" content="{{ csrf_token() }}">
  <title>@yield('title', 'QuoteCompare')</title>
  <link rel="icon" type="image/png" href="{{ asset('img/pr-compare-icon.png') }}">
  <link rel="apple-touch-icon" href="{{ asset('img/pr-compare-icon.png') }}">
  @include('theme')
  <style>
    :root { --sidebar-w: 236px; }

    .shell { display: flex; min-height: 100vh; }

    /* ── Sidebar ─────────────────────────────────────────── */
    .side {
      width: var(--sidebar-w);
      flex: 0 0 var(--sidebar-w);
      background: var(--surface);
      border-right: 1px solid var(--line);
      display: flex; flex-direction: column;
      position: sticky; top: 0; height: 100vh;
    }

    .side-brand {
      display: flex; align-items: center; gap: 10px;
      padding: 20px 18px 18px;
    }
    .side-brand img { width: 26px; height: 26px; object-fit: contain; }
    .side-brand b { font-size: 15px; font-weight: 700; letter-spacing: -.01em; }

    .side-nav { flex: 1; padding: 6px 12px; overflow-y: auto; }

    .side-link {
      display: flex; align-items: center; gap: 10px;
      padding: 9px 11px; margin-bottom: 2px;
      border-radius: 9px;
      font-size: 14px; color: var(--ink-soft);
      transition: background .18s var(--ease), color .18s var(--ease);
    }
    .side-link svg { width: 18px; height: 18px; flex: none; color: var(--muted); }
    .side-link:hover { background: var(--surface-soft); }
    .side-link.is-active {
      background: var(--teal-soft);
      color: var(--teal-deep);
      font-weight: 600;
    }
    .side-link.is-active svg { color: var(--teal-deep); }

    .side-foot { border-top: 1px solid var(--line-soft); padding: 14px 16px 16px; }
    .side-user { display: flex; align-items: center; gap: 10px; }
    .side-user-info { min-width: 0; flex: 1; }
    .side-user-info b {
      display: block; font-size: 13.5px; font-weight: 600;
      white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
    }
    .side-user-info span {
      display: block; font-size: 12px; color: var(--muted);
      white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
    }

    .avatar {
      width: 34px; height: 34px; border-radius: 50%; flex: none; overflow: hidden;
      background: var(--surface-soft);
    }
    .avatar img { width: 100%; height: 100%; object-fit: cover; display: block; }

    /* กรอบรูปที่กดขยายได้ (ใช้ร่วมกับ .avatar / .pic ผ่าน resources/views/avatar.blade.php) */
    .is-zoomable { border: 0; padding: 0; cursor: zoom-in; }
    .is-zoomable:hover img { filter: brightness(.92); }
    .is-zoomable img { transition: filter .18s var(--ease); }

    /* ── รูปขยาย ─────────────────────────────────────────── */
    .lightbox {
      border: 0; padding: 0; background: transparent;
      width: min(88vw, 460px); max-height: 90vh;
      overflow: visible;
    }
    .lightbox::backdrop { background: rgba(17, 24, 39, .64); }
    .lightbox figure { margin: 0; position: relative; }
    .lightbox img {
      display: block; width: 100%; height: auto; max-height: 82vh; object-fit: contain;
      border-radius: 14px; background: var(--surface);
      box-shadow: 0 30px 70px -24px rgba(0, 0, 0, .55);
    }
    .lightbox figcaption {
      margin-top: 12px; text-align: center;
      font-size: 13.5px; color: #eef2f3;
    }
    .lightbox .close {
      position: absolute; top: 9px; right: 9px;
      display: grid; place-items: center; width: 32px; height: 32px;
      border: 0; border-radius: 50%; cursor: pointer;
      background: rgba(17, 24, 39, .55); color: #fff;
      transition: background .18s var(--ease);
    }
    .lightbox .close:hover { background: rgba(17, 24, 39, .78); }
    .lightbox[open] { animation: pop .22s var(--ease); }
    .lightbox[open]::backdrop { animation: fade .22s var(--ease); }
    @keyframes pop { from { opacity: 0; transform: scale(.95); } }
    @keyframes fade { from { opacity: 0; } }

    .btn-logout {
      display: flex; align-items: center; justify-content: center; gap: 7px;
      margin-top: 12px; width: 100%; min-height: 34px;
      background: transparent; color: var(--danger);
      border: 1px solid #f0c9c6; border-radius: 8px;
      font-size: 13px; font-weight: 600; cursor: pointer;
      transition: background .18s var(--ease), border-color .18s var(--ease);
    }
    .btn-logout:hover { background: var(--danger-soft); border-color: #e5b0ac; }
    .btn-logout svg { width: 15px; height: 15px; }

    /* ── เนื้อหา ─────────────────────────────────────────── */
    .main { flex: 1; min-width: 0; }
    .page { width: min(1180px, 100%); margin: 0 auto; padding: 30px 34px 60px; }

    .page-head {
      display: flex; align-items: center; justify-content: space-between;
      gap: 16px; flex-wrap: wrap;
      margin-bottom: 22px;
    }
    .page-head h1 {
      margin: 0; font-size: 23px; font-weight: 700; letter-spacing: -.02em;
    }

    .menu-toggle {
      display: none; align-items: center; justify-content: center;
      width: 38px; height: 38px; flex: none;
      background: var(--surface); border: 1px solid var(--line); border-radius: 9px;
      color: var(--ink-soft); cursor: pointer;
    }

    .scrim {
      display: none; position: fixed; inset: 0;
      background: rgba(17, 24, 39, .34);
      z-index: var(--z-scrim);
    }

    .flash-page { margin-bottom: 20px; }

    @media (max-width: 900px) {
      .side {
        position: fixed; left: 0; top: 0; z-index: var(--z-drawer);
        transform: translateX(-100%);
        transition: transform .24s var(--ease);
        box-shadow: var(--shadow);
      }
      .side.is-open { transform: none; }
      .scrim.is-open { display: block; }
      .menu-toggle { display: inline-flex; }
      .page { padding: 22px 18px 48px; }
      .page-head h1 { font-size: 20px; }
    }

    @yield('styles')
  </style>
</head>
<body>
<div class="shell">

  <aside class="side" id="sidebar">
    <div class="side-brand">
      <img src="{{ asset('img/logo.png') }}" alt="" width="26" height="26">
      <b>QuoteCompare</b>
    </div>

    <nav class="side-nav">
      {{-- เห็นได้ทุกคน — เส้นทางของเอกสารที่ตัวเองเกี่ยวข้อง + ดาวน์โหลด PDF --}}
      <a href="{{ route('overview') }}" class="side-link {{ request()->routeIs('overview') ? 'is-active' : '' }}">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
          <rect x="3" y="3" width="7" height="9" rx="1.5"/><rect x="14" y="3" width="7" height="5" rx="1.5"/>
          <rect x="14" y="12" width="7" height="9" rx="1.5"/><rect x="3" y="16" width="7" height="5" rx="1.5"/>
        </svg>
        ภาพรวม
      </a>

      <a href="{{ route('dashboard') }}" class="side-link {{ request()->routeIs('dashboard') ? 'is-active' : '' }}">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
          <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/>
        </svg>
        ข้อมูลส่วนตัว
      </a>

      {{-- เมนูงาน — ขึ้นตามขั้นที่ admin ใส่ชื่อเราไว้ใน "กำหนดเส้นทาง" --}}
      @foreach ($workMenu ?? [] as $duty => $label)
        @php
          // "จัดทำเอกสาร" มีหน้าจอจริงแล้ว จึงชี้ไปที่ตัวจัดการเอกสารแทนหน้าเปล่า
          $isCreate = $duty === 'create';
          $isSelect = $duty === 'select';
          $isNegotiate = $duty === 'negotiate';
          $isConfirm = $duty === 'confirm';
          $isApproval = $duty === 'sign';
          $url = match (true) {
            $isCreate => route('documents.index'),
            $isSelect => route('work.select'),
            $isNegotiate => route('work.negotiate'),
            $isConfirm => route('work.confirm'),
            $isApproval => route('work.approval'),
            default => route('work', $duty),
          };
          $active = match (true) {
            $isCreate => request()->routeIs('documents.*'),
            $isSelect => request()->routeIs('work.select*'),
            $isNegotiate => request()->routeIs('work.negotiate*'),
            $isConfirm => request()->routeIs('work.confirm*'),
            $isApproval => request()->routeIs('work.approval*'),
            default => request()->routeIs('work') && request()->route('duty') === $duty,
          };
        @endphp
        <a href="{{ $url }}" class="side-link {{ $active ? 'is-active' : '' }}">
          @switch($duty)
            @case('create')
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6"/>
                <path d="M12 18v-6"/><path d="M9 15h6"/>
              </svg>
              @break
            @case('select')
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                <path d="M9 11l3 3L22 4"/><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/>
              </svg>
              @break
            @case('negotiate')
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                <line x1="19" y1="5" x2="5" y2="19"/><circle cx="6.5" cy="6.5" r="2.5"/><circle cx="17.5" cy="17.5" r="2.5"/>
              </svg>
              @break
            @case('confirm')
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                <path d="M9 12l2 2 4-4"/><circle cx="12" cy="12" r="9"/>
              </svg>
              @break
            @default
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                <path d="M12 20h9"/><path d="M16.5 3.5a2.12 2.12 0 0 1 3 3L7 19l-4 1 1-4Z"/>
              </svg>
          @endswitch
          {{ $label }}
        </a>
      @endforeach

      @if ($me->isAdmin())
        <a href="{{ route('admin.settings') }}" class="side-link {{ request()->routeIs('admin.settings') ? 'is-active' : '' }}">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
            <circle cx="12" cy="12" r="3"/>
            <path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 1 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 1 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06A1.65 1.65 0 0 0 9 4.6h.09A1.65 1.65 0 0 0 10 3.09V3a2 2 0 1 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 1 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/>
          </svg>
          ตั้งค่าระบบ
        </a>
      @endif

      {{-- เมนู "ดาวน์โหลดเอกสาร" ถอดออกตามคำสั่ง Manager (D-065)
           เอกสารที่อนุมัติแล้วจะไปรวมอยู่ในหน้าภาพรวมแทน
           หน้า /downloads และ DownloadController ถูกลบทิ้งแล้ว (D-072) --}}
    </nav>

    <div class="side-foot">
      <div class="side-user">
        @include('avatar', [
          'url' => $me->avatarUrl(),
          'class' => 'avatar',
          'zoomable' => (bool) $me->profilePictureUrl(),
          'alt' => $me->displayName(),
        ])
        <div class="side-user-info">
          <b>{{ $me->displayName() }}</b>
          <span>{{ $me->isAdmin() ? 'ผู้ดูแลระบบ' : ($me->position ?: 'พนักงาน') }}</span>
        </div>
      </div>

      <form method="POST" action="{{ route('logout') }}">
        @csrf
        <button type="submit" class="btn-logout">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
            <path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><path d="m16 17 5-5-5-5"/><path d="M21 12H9"/>
          </svg>
          ออกจากระบบ
        </button>
      </form>
    </div>
  </aside>

  <div class="scrim" id="scrim"></div>

  {{-- รูปโปรไฟล์ขยาย — ใช้ <dialog> ของเบราว์เซอร์ ปิดด้วย Esc ได้เอง --}}
  <dialog class="lightbox" id="lightbox">
    <figure>
      <img id="lightboxImg" src="" alt="">
      <button type="button" class="close" id="lightboxClose" aria-label="ปิด">
        <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round">
          <path d="M18 6 6 18M6 6l12 12"/>
        </svg>
      </button>
      <figcaption id="lightboxName"></figcaption>
    </figure>
  </dialog>

  <div class="main">
    <div class="page">

      <div class="page-head">
        <div style="display:flex;align-items:center;gap:12px;min-width:0">
          <button class="menu-toggle" type="button" id="menuToggle" aria-label="เปิดเมนู" aria-controls="sidebar" aria-expanded="false">
            <svg width="19" height="19" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round">
              <path d="M3 6h18M3 12h18M3 18h18"/>
            </svg>
          </button>
          <h1>@yield('heading', 'ภาพรวม')</h1>
        </div>
        @yield('page-actions')
      </div>

      @if (session('success'))
        <div class="flash flash-ok flash-page">{{ session('success') }}</div>
      @endif
      @if (session('error'))
        <div class="flash flash-err flash-page">{{ session('error') }}</div>
      @endif

      @yield('content')
    </div>
  </div>

</div>

<script>
  'use strict';

  // เมนูแบบลิ้นชักบนจอแคบ
  var sidebar = document.getElementById('sidebar');
  var scrim = document.getElementById('scrim');
  var toggle = document.getElementById('menuToggle');

  function setMenu(open) {
    sidebar.classList.toggle('is-open', open);
    scrim.classList.toggle('is-open', open);
    toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
  }

  toggle.addEventListener('click', function () {
    setMenu(!sidebar.classList.contains('is-open'));
  });
  scrim.addEventListener('click', function () { setMenu(false); });
  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') { setMenu(false); }
  });

  // ── กดรูปโปรไฟล์เพื่อขยาย ──────────────────────────────
  var lightbox = document.getElementById('lightbox');
  var lightboxImg = document.getElementById('lightboxImg');
  var lightboxName = document.getElementById('lightboxName');

  // ผูกที่ document เพราะรูปในตารางเปลี่ยนทุกครั้งที่เปลี่ยนหน้า
  document.addEventListener('click', function (e) {
    var trigger = e.target.closest('[data-zoom]');
    if (!trigger) { return; }

    lightboxImg.src = trigger.dataset.zoom;
    lightboxImg.alt = trigger.dataset.zoomAlt || '';
    lightboxName.textContent = trigger.dataset.zoomAlt || '';
    lightbox.showModal();
  });

  document.getElementById('lightboxClose').addEventListener('click', function () {
    lightbox.close();
  });

  // คลิกพื้นหลังนอกรูป = ปิด (คลิกโดนรูปจะไม่เข้าเงื่อนไขนี้)
  lightbox.addEventListener('click', function (e) {
    if (e.target === lightbox) { lightbox.close(); }
  });

  // ปล่อยรูปทิ้งหลังปิด จะได้ไม่ค้างในหน่วยความจำ
  lightbox.addEventListener('close', function () {
    lightboxImg.removeAttribute('src');
  });
</script>

@yield('scripts')
</body>
</html>
