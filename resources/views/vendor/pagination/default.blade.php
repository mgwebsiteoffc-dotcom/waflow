@if ($paginator->hasPages())
<nav class="pager" role="navigation" aria-label="Pagination">
 @if ($paginator->onFirstPage())
  <span class="pager-btn disabled" aria-disabled="true">‹ Prev</span>
 @else
  <a class="pager-btn" href="{{ $paginator->previousPageUrl() }}" rel="prev">‹ Prev</a>
 @endif
 @foreach ($paginator->links() as $link)
  @if ($link['url'] === null)
   <span class="pager-btn disabled">{{ $link['label'] }}</span>
  @else
   <a class="pager-btn {{ $link['active'] ? 'active' : '' }}" href="{{ $link['url'] }}">{{ $link['label'] }}</a>
  @endif
 @endforeach
 @if ($paginator->hasMorePages())
  <a class="pager-btn" href="{{ $paginator->nextPageUrl() }}" rel="next">Next ›</a>
 @else
  <span class="pager-btn disabled" aria-disabled="true">Next ›</span>
 @endif
</nav>
@endif
