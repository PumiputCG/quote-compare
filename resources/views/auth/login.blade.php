{{-- หน้าเข้าสู่ระบบ — การ์ดเดียว โลโก้ + ฟอร์ม ไม่มีอย่างอื่น --}}
<!doctype html>
<html lang="th">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>เข้าสู่ระบบ · QuoteCompare</title>
  <link rel="icon" type="image/png" href="{{ asset('img/pr-compare-icon.png') }}">
  <link rel="apple-touch-icon" href="{{ asset('img/pr-compare-icon.png') }}">
  @include('theme')
  <style>
    body {
      min-height: 100vh;
      display: grid;
      place-items: center;
      padding: 32px 20px;
      background-color: #f2f5fb;
      background-image: url('{{ asset('img/login-bg.png') }}');
      background-size: cover;
      background-position: center;
      background-repeat: no-repeat;
    }

    .wrap { width: 100%; max-width: 372px; }

    .card {
      background: var(--surface);
      border: 1px solid #e6ebf3;
      border-radius: 16px;
      padding: 32px 28px 26px;
      box-shadow: 0 1px 2px rgba(17, 24, 39, .04), 0 24px 48px -24px rgba(17, 24, 39, .28);
    }

    .logo {
      display: block; margin: 0 auto 18px;
      width: 54px; height: 54px; object-fit: contain;
    }

    .card h1 {
      margin: 0 0 24px;
      font-size: 19px; font-weight: 700; letter-spacing: -.01em;
      text-align: center;
    }

    .peek {
      position: absolute; right: 6px;
      display: grid; place-items: center;
      width: 34px; height: 34px;
      border: 0; background: transparent; border-radius: 8px;
      color: var(--muted); cursor: pointer;
      transition: color .18s var(--ease);
    }
    .peek:hover { color: var(--ink-soft); }
    .peek svg { width: 18px; height: 18px; }
    #password { padding-right: 46px; }

    .submit { width: 100%; min-height: 44px; margin-top: 6px; }
    .submit .spin { display: none; }
    .submit.is-loading .spin { display: block; }

    .note {
      margin: 18px 0 0;
      font-size: 13px; color: var(--muted); text-align: center;
    }

    .alert { margin-bottom: 18px; }
  </style>
</head>
<body>
<div class="wrap">
  <form class="card" method="POST" action="{{ route('login.attempt') }}" id="loginForm">
    @csrf

    <img class="logo" src="{{ asset('img/logo.png') }}" alt="" width="54" height="54">
    <h1>เข้าสู่ระบบ</h1>

    {{-- ข้อความจาก middleware (session หมดอายุ) และจากการออกจากระบบ --}}
    @if (session('error'))
      <div class="flash flash-err alert">{{ session('error') }}</div>
    @endif
    @if (session('success'))
      <div class="flash flash-ok alert">{{ session('success') }}</div>
    @endif

    <div class="field">
      <label for="employee_code">รหัสพนักงาน</label>
      <div class="control {{ $errors->has('employee_code') ? 'has-error' : '' }}">
        <input type="text" id="employee_code" name="employee_code"
               value="{{ old('employee_code') }}"
               placeholder="71056" autocomplete="username" autofocus required>
      </div>
      @error('employee_code') <p class="err">{{ $message }}</p> @enderror
    </div>

    <div class="field">
      <label for="password">รหัสผ่าน</label>
      <div class="control {{ $errors->has('password') ? 'has-error' : '' }}">
        <input type="password" id="password" name="password"
               autocomplete="current-password" required>
        <button type="button" class="peek" id="peek" aria-label="แสดงรหัสผ่าน">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
            <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/>
          </svg>
        </button>
      </div>
      @error('password') <p class="err">{{ $message }}</p> @enderror
    </div>

    <button type="submit" class="btn submit" id="submitBtn">
      <span class="spin" aria-hidden="true"></span>
      <span class="label">เข้าสู่ระบบ</span>
    </button>

    <p class="note">ใช้รหัสเดียวกับ Supavut Insight</p>
  </form>
</div>

<script>
  'use strict';

  // ปุ่มตา — สลับแสดง/ซ่อนรหัสผ่าน
  var peek = document.getElementById('peek');
  var pwd = document.getElementById('password');

  peek.addEventListener('click', function () {
    var shown = pwd.type === 'text';
    pwd.type = shown ? 'password' : 'text';
    peek.setAttribute('aria-label', shown ? 'แสดงรหัสผ่าน' : 'ซ่อนรหัสผ่าน');
    pwd.focus();
  });

  // กันกดซ้ำ + แสดงสถานะกำลังเข้าสู่ระบบ
  var form = document.getElementById('loginForm');
  var btn = document.getElementById('submitBtn');

  form.addEventListener('submit', function () {
    btn.disabled = true;
    btn.classList.add('is-loading');
    btn.querySelector('.label').textContent = 'กำลังเข้าสู่ระบบ';
  });
</script>
</body>
</html>
