@extends('layouts.portal')

@section('title', $title . ' · QuoteCompare')
@section('heading', $title)

@section('styles')
  .blank {
    display: flex; flex-direction: column; align-items: center; justify-content: center;
    padding: 64px 24px; text-align: center;
    background: var(--surface);
    border: 1px solid var(--line); border-radius: var(--radius);
    box-shadow: var(--shadow-sm);
  }
  .blank .ico {
    display: grid; place-items: center; width: 52px; height: 52px; margin-bottom: 14px;
    border-radius: 50%; background: var(--teal-soft); color: var(--teal-deep);
  }
  .blank .ico svg { width: 24px; height: 24px; }
  .blank h2 { margin: 0; font-size: 16px; font-weight: 700; }
  .blank p { margin: 6px 0 0; font-size: 13.5px; color: var(--muted); }
@endsection

@section('content')

  <div class="blank">
    <span class="ico">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round">
        <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/>
        <path d="M14 2v6h6"/><path d="M9 14h6"/><path d="M9 18h4"/>
      </svg>
    </span>

    <h2>{{ $title }}</h2>
    <p>{{ $note ?? 'ยังไม่มีเอกสาร' }}</p>
  </div>

@endsection
