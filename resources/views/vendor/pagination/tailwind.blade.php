@if ($paginator->hasPages())
<nav class="pager" role="navigation" aria-label="Paginação">
    <p class="pager-info">{{ $paginator->firstItem() ?? 0 }}–{{ $paginator->lastItem() ?? 0 }} de {{ number_format($paginator->total(),0,',','.') }}</p>
    <ul class="pager-list">
        @if ($paginator->onFirstPage())
            <li><span class="pager-link is-disabled" aria-disabled="true" aria-label="Página anterior">‹</span></li>
        @else
            <li><a class="pager-link" href="{{ $paginator->previousPageUrl() }}" rel="prev" aria-label="Página anterior">‹</a></li>
        @endif
        @foreach ($elements as $element)
            @if (is_string($element))
                <li><span class="pager-link is-gap" aria-disabled="true">{{ $element }}</span></li>
            @endif
            @if (is_array($element))
                @foreach ($element as $page => $url)
                    @if ($page == $paginator->currentPage())
                        <li><span class="pager-link is-current" aria-current="page">{{ $page }}</span></li>
                    @else
                        <li><a class="pager-link" href="{{ $url }}" aria-label="Ir para a página {{ $page }}">{{ $page }}</a></li>
                    @endif
                @endforeach
            @endif
        @endforeach
        @if ($paginator->hasMorePages())
            <li><a class="pager-link" href="{{ $paginator->nextPageUrl() }}" rel="next" aria-label="Próxima página">›</a></li>
        @else
            <li><span class="pager-link is-disabled" aria-disabled="true" aria-label="Próxima página">›</span></li>
        @endif
    </ul>
</nav>
@endif
