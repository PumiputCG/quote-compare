{{-- กดรูปพนักงานเพื่อดูรูปใหญ่ — ใช้ร่วมกันทุกหน้าที่แสดงรูปคนในเส้นทางเอกสาร (D-077)

     วิธีใช้: ทำรูปให้เป็นปุ่ม แล้วติดแอตทริบิวต์ 3 ตัวนี้
       <button type="button" class="avatar-zoom"
               data-avatar-zoom="{{ URL รูป }}"
               data-avatar-name="{{ ชื่อ }}"
               data-avatar-code="{{ รหัสพนักงาน }}">…</button>
     ⚠️ ต้องเป็น type="button" เสมอ เพราะบางจุดอยู่ในฟอร์ม กดแล้วจะ submit โดยไม่ตั้งใจ --}}

<dialog class="avatar-dialog" id="avatarDialog" aria-labelledby="avatarDialogName">
  <button type="button" class="avatar-close" id="avatarClose" aria-label="ปิด">
    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M18 6 6 18M6 6l12 12"/></svg>
  </button>
  <img src="" alt="" id="avatarDialogImage">
  <div class="avatar-caption">
    <b id="avatarDialogName"></b>
    <span class="num" id="avatarDialogCode"></span>
  </div>
</dialog>

<style>
  /* ปุ่มครอบรูป — ตัวรูปเดิมหน้าตาไม่เปลี่ยน แค่กดได้และมีสัญญาณว่ากดได้ */
  .avatar-zoom {
    display: block; padding: 0; border: 0; background: transparent;
    line-height: 0; cursor: zoom-in; border-radius: 50%;
  }
  .avatar-zoom:hover { box-shadow: 0 0 0 2px var(--teal); }
  .avatar-zoom:focus-visible { outline: 2px solid var(--teal); outline-offset: 2px; }

  .avatar-dialog {
    width: min(92vw, 360px); padding: 0; overflow: hidden;
    border: 0; border-radius: var(--radius); background: var(--surface);
    box-shadow: 0 30px 70px -24px rgba(17, 24, 39, .55);
  }
  .avatar-dialog[open] { display: flex; flex-direction: column; }
  .avatar-dialog::backdrop { background: rgba(17, 24, 39, .55); }
  .avatar-dialog img {
    display: block; width: 100%; max-height: 70vh; object-fit: contain; background: var(--surface-soft);
  }
  .avatar-caption {
    display: grid; gap: 2px; padding: 13px 16px; border-top: 1px solid var(--line); text-align: center;
  }
  .avatar-caption b { font-size: 14.5px; overflow-wrap: anywhere; }
  .avatar-caption span { color: var(--muted); font-size: 12.5px; font-variant-numeric: tabular-nums; }
  .avatar-close {
    position: absolute; right: 10px; top: 10px; display: grid; place-items: center;
    width: 32px; height: 32px; padding: 0; border: 0; border-radius: 50%;
    background: rgba(17, 24, 39, .55); color: #fff; cursor: pointer;
  }
  .avatar-close:hover { background: rgba(17, 24, 39, .78); }
  .avatar-close svg { width: 17px; height: 17px; fill: none; stroke: currentColor; stroke-width: 2.2; stroke-linecap: round; }

  @media print { .avatar-dialog { display: none !important; } }
</style>

<script>
  'use strict';

  (function () {
    var dialog = document.getElementById('avatarDialog');
    var image = document.getElementById('avatarDialogImage');
    var name = document.getElementById('avatarDialogName');
    var code = document.getElementById('avatarDialogCode');

    if (!dialog) { return; }

    // ผูกที่ document เพราะบางหน้าสร้างการ์ดพนักงานเพิ่มหลังโหลดเสร็จ
    document.addEventListener('click', function (event) {
      var trigger = event.target.closest('[data-avatar-zoom]');
      if (!trigger) { return; }

      event.preventDefault();
      image.src = trigger.dataset.avatarZoom;
      image.alt = 'รูปของ ' + (trigger.dataset.avatarName || 'พนักงาน');
      name.textContent = trigger.dataset.avatarName || '';
      code.textContent = trigger.dataset.avatarCode || '';
      dialog.showModal();
    });

    document.getElementById('avatarClose').addEventListener('click', function () { dialog.close(); });
    dialog.addEventListener('click', function (event) {
      if (event.target === dialog) { dialog.close(); }
    });
  }());
</script>
