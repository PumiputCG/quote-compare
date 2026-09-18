{{--
  ธีมกลางของ PR Compare — ใช้ร่วมกันทั้งหน้าล็อกอินและหน้าหลังล็อกอิน
  แก้สีหรือชิ้นส่วนพื้นฐานที่ไฟล์นี้ที่เดียว ทุกหน้าเปลี่ยนตาม
  โทนอ้างอิงจาก Supavut Insight เพื่อให้สองระบบอยู่ในตระกูลเดียวกัน
--}}
<style>
  :root {
    /* ── พื้นผิว ─────────────────────────────────────────── */
    --bg: #f7f9f9;
    --surface: #ffffff;
    --surface-soft: #f1f6f5;

    /* ── ตัวอักษร (ผ่าน WCAG AA บนพื้น --bg ทุกตัว) ───────── */
    --ink: #111827;        /* หัวข้อ / ค่าหลัก */
    --ink-soft: #39434f;   /* เนื้อความ */
    --muted: #5b6672;      /* ป้ายกำกับ · 5.5:1 */

    /* ── เส้น ───────────────────────────────────────────── */
    --line: #e4e9ea;
    --line-soft: #eef2f3;

    /* ── teal (สีหลัก) ──────────────────────────────────── */
    --teal: #0ca39a;       /* ไอคอน / ขอบ / จุดสถานะ — องค์ประกอบ UI 3:1 */
    --teal-ink: #087f79;   /* ตัวอักษร / พื้นปุ่ม — 4.9:1 กับตัวหนังสือขาว */
    --teal-deep: #066b66;  /* ตัวอักษรบนพื้น --teal-soft — 5.6:1 */
    --teal-soft: #e2f4f2;

    /* ── สีบอกสถานะ ─────────────────────────────────────── */
    --ok: #127044;      --ok-soft: #e4f5ec;
    --warn: #8a5a12;    --warn-soft: #fdf3e2;
    --danger: #ad352d;  --danger-soft: #fdeceb;

    /* ── อื่นๆ ──────────────────────────────────────────── */
    --shadow-sm: 0 1px 2px rgba(17, 24, 39, .05);
    --shadow: 0 14px 34px -20px rgba(17, 24, 39, .3);
    --radius: 12px;
    --ease: cubic-bezier(.22, 1, .36, 1);
    --font: "Segoe UI", "Noto Sans Thai", "Leelawadee UI", system-ui, -apple-system, sans-serif;

    /* ลำดับชั้นการซ้อน — ห้ามใส่ตัวเลขมั่วนอกสเกลนี้ */
    --z-sticky: 20;
    --z-scrim: 30;
    --z-drawer: 40;
  }

  * { box-sizing: border-box; }
  html, body { margin: 0; padding: 0; }

  body {
    font-family: var(--font);
    background: var(--bg);
    color: var(--ink);
    font-size: 15px;
    line-height: 1.6;
    -webkit-font-smoothing: antialiased;
    text-rendering: optimizeLegibility;
  }

  a { color: inherit; text-decoration: none; }
  button, input, select { font: inherit; color: inherit; }

  :focus-visible {
    outline: 2px solid var(--teal);
    outline-offset: 2px;
    border-radius: 6px;
  }

  h1, h2, h3 { text-wrap: balance; }

  /* ── ปุ่ม ─────────────────────────────────────────────── */
  .btn {
    display: inline-flex; align-items: center; justify-content: center; gap: 8px;
    min-height: 38px; padding: 0 16px;
    background: var(--teal-ink); color: #fff;
    border: 1px solid var(--teal-ink); border-radius: 9px;
    font-size: 14px; font-weight: 600; cursor: pointer;
    transition: background .18s var(--ease), border-color .18s var(--ease);
  }
  .btn:hover:not(:disabled) { background: var(--teal-deep); border-color: var(--teal-deep); }
  .btn:disabled { opacity: .55; cursor: default; }

  .btn-quiet {
    background: var(--surface); color: var(--ink-soft); border-color: var(--line);
  }
  .btn-quiet:hover:not(:disabled) { background: var(--surface-soft); border-color: #cfd8d9; }

  /* ── ป้ายสถานะ ───────────────────────────────────────── */
  .pill {
    display: inline-flex; align-items: center;
    padding: 2px 9px; border-radius: 999px;
    font-size: 12px; font-weight: 600; white-space: nowrap;
  }
  .pill-ok { background: var(--ok-soft); color: var(--ok); }
  .pill-off { background: var(--danger-soft); color: var(--danger); }
  .pill-warn { background: var(--warn-soft); color: var(--warn); }
  .pill-mute { background: var(--surface-soft); color: var(--muted); }
  .pill-teal { background: var(--teal-soft); color: var(--teal-deep); }

  /* ── ช่องกรอก ────────────────────────────────────────── */
  .field { margin-bottom: 16px; }
  .field > label {
    display: block; margin-bottom: 6px;
    font-size: 13px; font-weight: 600; color: var(--ink-soft);
  }
  .control { position: relative; display: flex; align-items: center; }
  .control > input {
    width: 100%; min-height: 44px;
    padding: 0 14px;
    color: var(--ink); background: var(--surface);
    border: 1px solid var(--line); border-radius: 10px;
    transition: border-color .18s var(--ease), box-shadow .18s var(--ease);
  }
  .control > input::placeholder { color: var(--muted); }
  .control > input:focus {
    outline: none;
    border-color: var(--teal);
    box-shadow: 0 0 0 3px rgba(12, 163, 154, .16);
  }
  .control.has-error > input { border-color: var(--danger); }
  .err { margin: 6px 0 0; font-size: 13px; color: var(--danger); }

  /* ── ข้อความแจ้งผล ───────────────────────────────────── */
  .flash {
    display: flex; align-items: flex-start; gap: 9px;
    padding: 11px 14px; border-radius: 10px;
    font-size: 14px; border: 1px solid;
  }
  .flash-ok { background: var(--ok-soft); border-color: #c6e6d4; color: var(--ok); }
  .flash-err { background: var(--danger-soft); border-color: #f2cdca; color: var(--danger); }

  /* ── ตัวหมุนรอโหลด ───────────────────────────────────── */
  .spin {
    width: 15px; height: 15px; border-radius: 50%;
    border: 2px solid rgba(255, 255, 255, .38); border-top-color: #fff;
    animation: spin .7s linear infinite;
  }
  @keyframes spin { to { transform: rotate(360deg); } }

  .num { font-variant-numeric: tabular-nums; }

  /* ── แท็บกรองตามสถานะ (D-074) — ใช้ร่วมกันทุกหน้ารายการ ── */
  .status-tabs {
    display: flex; flex-wrap: wrap; gap: 6px;
    margin-bottom: 14px; padding: 5px;
    background: var(--surface); border: 1px solid var(--line); border-radius: 11px;
  }
  .status-tab {
    display: inline-flex; align-items: center; gap: 7px;
    padding: 7px 14px; border-radius: 8px;
    color: var(--muted); font-size: 13px; font-weight: 600; text-decoration: none;
    transition: background-color .18s var(--ease), color .18s var(--ease);
  }
  .status-tab:hover { background: var(--surface-soft); color: var(--ink); }
  .status-tab.is-active { background: var(--teal-soft); color: var(--teal-deep); }
  .status-tab-count {
    min-width: 20px; padding: 1px 6px; border-radius: 999px;
    background: var(--surface-soft); color: var(--muted);
    font-size: 11.5px; font-weight: 700; text-align: center; font-variant-numeric: tabular-nums;
  }
  .status-tab.is-active .status-tab-count { background: #fff; color: var(--teal-deep); }

  @media (max-width: 640px) {
    .status-tabs { gap: 4px; }
    .status-tab { flex: 1; justify-content: center; padding: 7px 8px; font-size: 12.5px; }
  }

  @media (prefers-reduced-motion: reduce) {
    *, *::before, *::after {
      animation-duration: .01ms !important;
      animation-iteration-count: 1 !important;
      transition-duration: .01ms !important;
    }
  }
</style>
