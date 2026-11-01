<form method="post" action="{{ route('larena.env.admin.dns.zone.plan', ['zone' => $zone->id], false) }}" class="larena-form" data-env-dns-form="{{ $action }}">
    @csrf
    <input type="hidden" name="action" value="{{ $action }}">
    @if ($record !== null)
        <input type="hidden" name="record" value="{{ $record->id }}">
    @endif
    <div class="larena-field">
        <label for="env-dns-{{ $action }}-type">{{ __('larena-env::admin.dns.type') }}</label>
        @if ($record !== null)
            <input type="text" id="env-dns-{{ $action }}-type" value="{{ $record->type }}" disabled>
        @else
            <select id="env-dns-{{ $action }}-type" name="type" required>
                @foreach ($types as $type)
                    <option value="{{ $type }}">{{ $type }}</option>
                @endforeach
            </select>
        @endif
    </div>
    <div class="larena-field">
        <label for="env-dns-{{ $action }}-name">{{ __('larena-env::admin.dns.name') }}</label>
        <input type="text" id="env-dns-{{ $action }}-name" name="name" value="{{ $record?->name ?? '@' }}" maxlength="253" required>
        <p>{{ __('larena-env::admin.dns.name_help', ['zone' => $zone->name]) }}</p>
    </div>
    <div class="larena-field">
        <label for="env-dns-{{ $action }}-content">{{ __('larena-env::admin.dns.content') }}</label>
        <input type="text" id="env-dns-{{ $action }}-content" name="content" value="{{ $record?->content }}" maxlength="2048" required>
        <p>{{ __('larena-env::admin.dns.content_help') }}</p>
    </div>
    <div class="larena-field">
        <label for="env-dns-{{ $action }}-priority">{{ __('larena-env::admin.dns.priority') }}</label>
        <input type="number" id="env-dns-{{ $action }}-priority" name="priority" min="0" max="65535" value="{{ $record?->priority }}">
    </div>
    <div class="larena-field">
        <label for="env-dns-{{ $action }}-ttl">{{ __('larena-env::admin.dns.ttl') }}</label>
        <select id="env-dns-{{ $action }}-ttl" name="ttl">
            @foreach ([1, 60, 300, 1800, 3600, 86400] as $ttl)
                <option value="{{ $ttl }}" @if (($record?->ttl ?? 1) === $ttl) selected @endif>{{ $ttl === 1 ? __('larena-env::admin.dns.ttl_auto') : $ttl }}</option>
            @endforeach
        </select>
    </div>
    <div class="larena-field">
        <label for="env-dns-{{ $action }}-proxied">{{ __('larena-env::admin.dns.proxied_field') }}</label>
        <select id="env-dns-{{ $action }}-proxied" name="proxied">
            <option value="no" @if (!($record?->proxied ?? false)) selected @endif>{{ __('larena-env::admin.no') }}</option>
            <option value="yes" @if ($record?->proxied ?? false) selected @endif>{{ __('larena-env::admin.yes') }}</option>
        </select>
    </div>
    <div class="larena-form-actions">
        <button type="submit" class="larena-button">{{ __('larena-env::admin.operations.plan') }}</button>
    </div>
</form>
