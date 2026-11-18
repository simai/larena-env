{{-- The one run (PX-18): the site, its database, WordPress and HTTPS as the steps of one Framework sf-progress-run; the
     page reads itself again every few seconds while it goes (no script of the panel's own). --}}
<sf-progress-run label="{{ $runLabel }}" heading-level="3" run-state="{{ $runState }}" started-at="{{ $runStarted }}"
    steps="{{ json_encode($runSteps, JSON_UNESCAPED_UNICODE) }}" @if ($runResult !== '') result-text="{{ $runResult }}" @endif data-env-new-run="{{ $runState }}"></sf-progress-run>
@if ($runFailedJob !== null)
    <p class="m-0"><a href="{{ route('larena.env.admin.job', ['job' => $runFailedJob], false) }}" data-env-new-run-log>{{ __('larena-env::admin.new_site.wizard.run_log') }}</a></p>
@endif
