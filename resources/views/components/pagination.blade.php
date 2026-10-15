@if($paginator->hasPages())
    <nav class="pagination" role="navigation" aria-label="Pagination">
        @if($paginator->onFirstPage())
            <span class="pagination-control is-disabled">← Newer</span>
        @else
            <a class="pagination-control" href="{{ $paginator->previousPageUrl() }}" rel="prev">← Newer</a>
        @endif
        <span class="pagination-position">PAGE {{ $paginator->currentPage() }} <i>/</i> {{ $paginator->lastPage() }}</span>
        @if($paginator->hasMorePages())
            <a class="pagination-control" href="{{ $paginator->nextPageUrl() }}" rel="next">Older →</a>
        @else
            <span class="pagination-control is-disabled">Older →</span>
        @endif
    </nav>
@endif
