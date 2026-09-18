{{-- ตัวแบ่งหน้าแบบเรียบ ใช้สไตล์ .pager ในหน้าที่เรียกใช้ --}}
@if ($paginator->hasPages())
  <nav role="navigation" aria-label="แบ่งหน้า">

    @if ($paginator->onFirstPage())
      <span class="pg is-off">‹</span>
    @else
      <a href="{{ $paginator->previousPageUrl() }}" rel="prev" aria-label="หน้าก่อนหน้า">‹</a>
    @endif

    @foreach ($elements as $element)
      @if (is_string($element))
        <span class="pg is-off">{{ $element }}</span>
      @endif

      @if (is_array($element))
        @foreach ($element as $page => $url)
          @if ($page == $paginator->currentPage())
            <span class="pg is-cur">{{ $page }}</span>
          @else
            <a href="{{ $url }}">{{ $page }}</a>
          @endif
        @endforeach
      @endif
    @endforeach

    @if ($paginator->hasMorePages())
      <a href="{{ $paginator->nextPageUrl() }}" rel="next" aria-label="หน้าถัดไป">›</a>
    @else
      <span class="pg is-off">›</span>
    @endif

  </nav>
@endif
