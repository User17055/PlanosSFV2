{{-- Só aparece com JavaScript: sem ele, as telas continuam funcionando como páginas normais. --}}
<span class="lead-live" data-live-status hidden><i aria-hidden="true"></i><span>Ao vivo</span></span>
@push('scripts')<script defer src="{{ asset('assets/leads-live.js') }}?v={{ filemtime(public_path('assets/leads-live.js')) }}"></script>@endpush
