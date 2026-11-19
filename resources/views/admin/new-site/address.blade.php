{{-- Only the fields the kind and its way take (PX-20): the language by name, the database in words or as a switch, whose
     site only for the whole server with why. A way of making it (new, from a copy) is chosen above them. --}}
@if (($routes ?? null) !== null && count($routes) > 1)
    <div class="flex flex-column gap-1" data-env-new-ways>
        <p class="m-0 text-medium">{{ __('larena-env::admin.new_site.wizard.way') }}</p>
        <div class="flex flex-wrap gap-1">
            @foreach ($routes as $one)
                <sf-button href="{{ route('larena.env.admin.sites.new.step', ['step' => 'address'], false) }}?route={{ $one['id'] }}" text="{{ $one['label'] }}"
                    @if ($one['id'] === $route) appearance="default" scheme="primary" aria-current="true" @else appearance="outline" scheme="on-surface" @endif data-env-new-way="{{ $one['id'] }}"></sf-button>
            @endforeach
        </div>
        @foreach ($routes as $one)
            @if ($one['id'] === $route)
                <p class="m-0 color-on-surface-variant">{{ $one['hint'] }}</p>
            @endif
        @endforeach
    </div>
@endif
@if (($route ?? null) !== null)
    <input type="hidden" name="route" value="{{ $route }}">
@endif
<p class="m-0" data-env-domain-point>{{ ($serverIp ?? null) !== null ? __('larena-env::admin.point_domain.ip', ['ip' => $serverIp]) : __('larena-env::admin.point_domain.unknown') }}</p>
@foreach ($fields as $field)
    @switch($field['kind'])
        @case('note')
            <p class="m-0" data-env-new-field="{{ $field['name'] }}">{{ $field['value'] }}</p>
            @break
        @case('choice')
            <sf-dropdown name="{{ $field['name'] }}" label="{{ $field['label'] }}" value="{{ $field['value'] }}" options="{{ json_encode($field['options'], JSON_UNESCAPED_UNICODE) }}"
                @if ($field['required'] ?? false) required @endif @if (($field['hint'] ?? '') !== '') hint="{{ $field['hint'] }}" @endif
                @if (($errors[$field['name']] ?? null) !== null) invalid error-message="{{ $errors[$field['name']] }}" @endif data-env-new-field="{{ $field['name'] }}"></sf-dropdown>
            @break
        @case('switch')
            <sf-switch name="{{ $field['name'] }}" value="yes" label="{{ $field['label'] }}" @if ($field['value'] === 'yes') checked @endif
                @if (($field['hint'] ?? '') !== '') hint="{{ $field['hint'] }}" @endif data-env-new-field="{{ $field['name'] }}"></sf-switch>
            @break
        @case('check')
            <sf-checkbox name="{{ $field['name'] }}" value="yes" label="{{ $field['label'] }}" @if ($field['value'] === 'yes') checked @endif
                @if (($field['hint'] ?? '') !== '') hint="{{ $field['hint'] }}" @endif data-env-new-field="{{ $field['name'] }}"></sf-checkbox>
            @break
        @default
            <sf-input name="{{ $field['name'] }}" label="{{ $field['label'] }}" default-value="{{ $field['value'] }}" autocomplete="off"
                @if ($field['kind'] === 'email') input-type="email" @endif @if ($field['required'] ?? false) required @endif
                @if (($field['placeholder'] ?? '') !== '') placeholder="{{ $field['placeholder'] }}" @endif @if (($field['hint'] ?? '') !== '') hint="{{ $field['hint'] }}" @endif
                @if (($errors[$field['name']] ?? null) !== null) invalid error-message="{{ $errors[$field['name']] }}" @endif data-env-new-field="{{ $field['name'] }}"></sf-input>
    @endswitch
@endforeach
