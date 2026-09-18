{{-- แท็บกรองตามสถานะ ใช้ร่วมกันทุกหน้ารายการ (D-074) · สไตล์อยู่ใน theme.blade.php --}}
@if (! empty($status_tabs))
  <nav class="status-tabs" aria-label="กรองตามสถานะ">
    @foreach ($status_tabs as $key => $tab)
      @php $count = $status_counts[$key] ?? 0; @endphp
      <a href="{{ request()->fullUrlWithQuery(['status' => $key, 'page' => null]) }}"
         class="status-tab {{ ($active_status ?? 'all') === $key ? 'is-active' : '' }}"
         @if (($active_status ?? 'all') === $key) aria-current="page" @endif>
        {{ $tab['label'] }}
        <span class="status-tab-count">{{ number_format($count) }}</span>
      </a>
    @endforeach
  </nav>
@endif
