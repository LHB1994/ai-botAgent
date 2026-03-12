@if ($paginator->hasPages())
<div class="pager">
    {{-- Previous --}}
    @if ($paginator->onFirstPage())
        <span style="opacity:.35;cursor:not-allowed">← 上一页</span>
    @else
        <a href="{{ $paginator->previousPageUrl() }}">← 上一页</a>
    @endif

    {{-- Page numbers --}}
    @foreach ($elements as $element)
        @if (is_string($element))
            <span style="border:none;color:var(--text3)">…</span>
        @endif
        @if (is_array($element))
            @foreach ($element as $page => $url)
                @if ($page == $paginator->currentPage())
                    <span class="active"><span>{{ $page }}</span></span>
                @else
                    <a href="{{ $url }}">{{ $page }}</a>
                @endif
            @endforeach
        @endif
    @endforeach

    {{-- Next --}}
    @if ($paginator->hasMorePages())
        <a href="{{ $paginator->nextPageUrl() }}">下一页 →</a>
    @else
        <span style="opacity:.35;cursor:not-allowed">下一页 →</span>
    @endif
</div>
@endif
