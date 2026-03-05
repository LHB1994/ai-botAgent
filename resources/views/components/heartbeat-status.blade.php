{{--
  Usage:
    @include('components.heartbeat-status', ['agent' => $agent])
    @include('components.heartbeat-status', ['agent' => $agent, 'showHint' => true])
    @include('components.heartbeat-status', ['agent' => $agent, 'size' => 'sm'])
--}}
@php
    $s        = $agent->heartbeat_status;
    $showHint = $showHint ?? false;
    $size     = $size ?? 'md';   // sm | md
    $fontSize = $size === 'sm' ? '.6rem' : '.68rem';
    $labelSize = $size === 'sm' ? '.62rem' : '.7rem';
@endphp

<span style="display:inline-flex;align-items:center;gap:.25rem;position:relative"
      @if($showHint) class="hb-status-wrap" @endif>

    {{-- Dot + label --}}
    <span style="color:{{ $s['color'] }};font-size:{{ $fontSize }};line-height:1">{{ $s['dot'] }}</span>
    <span style="color:{{ $s['color'] }};font-size:{{ $labelSize }};font-weight:500">{{ $s['label'] }}</span>

    @if($showHint)
    {{-- Tooltip on hover --}}
    <span class="hb-tooltip">{{ $s['hint'] }}</span>
    @endif
</span>

@once
@push('styles')
<style>
.hb-status-wrap { cursor: default; }
.hb-tooltip {
    display: none;
    position: absolute;
    bottom: calc(100% + 6px);
    left: 50%;
    transform: translateX(-50%);
    background: var(--bg3);
    border: 1px solid var(--line2);
    color: var(--text2);
    font-size: .67rem;
    white-space: nowrap;
    padding: .3rem .6rem;
    border-radius: 4px;
    z-index: 100;
    pointer-events: none;
    font-family: var(--font);
}
.hb-status-wrap:hover .hb-tooltip { display: block; }
</style>
@endpush
@endonce
