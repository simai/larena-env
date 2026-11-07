{{-- The server's plan for the step's change, in the panel's words; the server's own terms under «Подробнее». Nothing
     changes until «Применить». --}}
<p class="m-0" data-env-setup-plan="{{ $plan->command }}">{{ __('larena-env::admin.setup.review') }}</p>
<dl class="m-0" style="display: block;" data-env-facts>
    @foreach (\Larena\Env\Admin\PlanValues::of($plan->args, $plan->target) as $row)
        <div class="flex content-main-between g-2 p-y-1 border-top-1 border-top-solid border-outline-variant" data-env-plan-argument="{{ $row['name'] }}"><dt class="color-on-surface-variant">{{ $row['label'] }}</dt><dd class="m-0 min-w-0 text-end" style="overflow-wrap: anywhere;">{{ $row['value'] }}</dd></div>
    @endforeach
</dl>
@if (($plan->update ?? null) !== null)
    @include('larena-env::admin.partials.update-plan', ['update' => $plan->update])
@endif
<details data-env-plan-server>
    <summary class="color-primary" style="cursor: pointer;">{{ __('larena-env::admin.more') }}</summary>
    <p>{{ $plan->description }}</p>
    <p class="m-0 color-on-surface-variant">{{ $plan->effects }}</p>
</details>
<div><sf-button appearance="link" size="1/2" text="{{ __('larena-env::admin.setup.edit') }}" native-type="submit" name="wizard-action" value="edit" data-env-setup-edit></sf-button></div>
