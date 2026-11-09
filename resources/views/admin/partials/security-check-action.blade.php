{{-- One check's button: «Исправить» where the server puts it right itself, else the screen where it is done — the
     site's card, or «Кто может открыть панель» for the panel's own address. --}}
@if ($fixes[$check->id] ?? false)
    <form method="post" action="{{ route('larena.env.admin.security.plan', [], false) }}" class="m-0" data-env-security-fix="{{ $check->id }}">
        @csrf
        <input type="hidden" name="op" value="fix">
        <input type="hidden" name="check" value="{{ $check->id }}">
        <button type="submit" class="larena-button larena-button-primary larena-control-floor">{{ __('larena-env::admin.security.fix') }}</button>
    </form>
@elseif ($panel && $check->domain !== null)
    <a class="larena-button larena-control-floor" href="{{ route('larena.env.admin.panel.access', [], false) }}" data-env-security-open-panel="{{ $check->id }}">{{ __('larena-env::admin.security.open_panel') }}</a>
@elseif ($check->domain !== null)
    <a class="larena-button larena-control-floor" href="{{ route('larena.env.admin.site', ['domain' => $check->domain], false) }}{{ $check->check === 'https' ? '?tab=domains' : ($check->check === 'backup_verify' ? '?tab=backups' : '') }}" data-env-security-open="{{ $check->id }}">{{ __('larena-env::admin.security.open_site') }}</a>
@endif
