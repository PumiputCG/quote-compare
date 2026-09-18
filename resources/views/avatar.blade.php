{{--
  รูปโปรไฟล์ที่ใช้ซ้ำทุกหน้า

  ตัวแปรที่รับ:
    $url       URL รูป (ได้จาก AppUser::avatarUrl() / Employee::avatarUrl())
    $class     คลาสของกรอบรูป เช่น 'avatar' หรือ 'pic'
    $zoomable  true = เป็นรูปจริง กดขยายได้ (รูป default ไม่ต้องให้กด)
    $alt       ชื่อเจ้าของรูป ใช้เป็น alt และป้ายบอกปุ่ม
    $lazy      true = ใส่ loading="lazy" (ใช้ในตารางที่มีหลายสิบแถว)
--}}
@php
  $zoomable ??= false;
  $alt ??= '';
  $lazy ??= false;
@endphp

@if ($zoomable)
  <button type="button" class="{{ $class }} is-zoomable"
          data-zoom="{{ $url }}" data-zoom-alt="{{ $alt }}"
          aria-label="ดูรูปของ {{ $alt ?: 'พนักงาน' }} ขนาดใหญ่">
    <img src="{{ $url }}" alt="{{ $alt }}" @if ($lazy) loading="lazy" @endif>
  </button>
@else
  <div class="{{ $class }}">
    <img src="{{ $url }}" alt="{{ $alt }}" @if ($lazy) loading="lazy" @endif>
  </div>
@endif
