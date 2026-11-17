<?php

declare(strict_types=1);

namespace Larena\Env\Admin;

use Illuminate\Support\HtmlString;
use Larena\Admin\Runtime\AdminPlannedAction;
use Larena\Admin\Runtime\AdminRenderedPart;
use Larena\Env\Operations\PanelPlan;
use Larena\Env\Operations\SiteOperation;

/**
 * The panel's plan as the admin's «Действие с планом» (larena/admin AdminPlannedAction, owner decision dec-63f3e971f41f):
 * the panel gives only its words — the question, the plan's values said in words, what it already knows would stop the
 * action, the deciding sentence, the name to type before a site or a database goes, the ticks that change the plan —
 * and the admin draws the one question window over the site, in its side panel or on the plan's own page.
 */
final class PlanAction
{
    /**
     * What the plan says in words of its own rather than as values (`plan.said.<command>.<row>`): a row always the same
     * (`value`), or a tick's two outcomes (`yes`, `no`) by the argument of that name.
     */
    private const array SAID = [
        'site:remove' => ['site' => false, 'config' => false, 'remove-files' => true, 'drop-db' => true, 'drop-db-user' => true],
        'site:db-drop' => ['db' => false, 'site' => false],
    ];

    private function __construct()
    {
    }

    /**
     * The part for the plan's screen, from the screen's own data (heading, plan, runUrl, cancelUrl, hidden, target,
     * check, warnings); $more is what the screen draws of the plan itself (what an update changes).
     *
     * @param array<string, mixed> $data
     */
    public static function part(array $data, string $token, string $more = ''): ?HtmlString
    {
        $plan = $data['plan'] ?? null;
        $runUrl = $data['runUrl'] ?? null;
        if (!$plan instanceof PanelPlan || !is_string($runUrl) || !class_exists(AdminPlannedAction::class)) {
            return null;
        }

        return self::of($plan, $runUrl, is_array($data['hidden'] ?? null) ? $data['hidden'] : [], $token, [
            'heading' => is_string($data['heading'] ?? null) ? $data['heading'] : '',
            'target' => is_string($data['target'] ?? null) && $data['target'] !== '' ? $data['target'] : $plan->target,
            'cancel' => is_string($data['cancelUrl'] ?? null) ? $data['cancelUrl'] : '',
            'check' => isset($data['check']) && is_object($data['check']) && is_string($data['check']->id ?? null) ? $data['check']->id : null,
            'warnings' => is_array($data['warnings'] ?? null) ? $data['warnings'] : [],
            'more' => $more,
        ])->html();
    }

    /**
     * @param array<array-key, mixed> $hidden what the confirmation sends back
     * @param array{heading: string, target: ?string, cancel: string, check: ?string, warnings: array<mixed>, more: string} $screen
     */
    public static function of(PanelPlan $plan, string $runUrl, array $hidden, string $token, array $screen): AdminPlannedAction
    {
        $target = $screen['target'];
        $command = $plan->command;
        $key = str_replace(':', '-', $command);
        $destroys = PlanValues::destroys($command);
        // The ticks of the action's own form are the question's choices, planned again when one changes.
        $operation = is_string($hidden['operation'] ?? null) ? SiteOperation::tryFrom($hidden['operation']) : null;
        $options = [];
        foreach ($operation?->fields() ?? [] as $field) {
            if (in_array($field, SiteOperation::SWITCHES, true) && is_string($hidden[$field] ?? null) && in_array($hidden[$field], ['yes', 'no'], true)) {
                $options[] = ['name' => $field, 'label' => self::text('fields.'.$field) ?? $field, 'checked' => $hidden[$field] === 'yes'];
            }
        }
        $chosen = array_column($options, 'name');
        $facts = [];
        $said = self::SAID[$command] ?? null;
        if ($said !== null) {
            foreach ($said as $name => $ticked) {
                $value = self::text('plan.said.'.$key.'.'.$name.'.'.(!$ticked ? 'value' : (($plan->args[$name] ?? $hidden[$name] ?? null) === 'yes' ? 'yes' : 'no')));
                $label = self::text('plan.said.'.$key.'.'.$name.'.label');
                if ($label !== null && $value !== null) {
                    $facts[] = [$label, $value];
                }
            }
        } else {
            if ($target !== null && $target !== '') {
                $facts[] = [self::text('plan.target') ?? 'Target', $target];
            }
            foreach (PlanValues::of($plan->args, $target, array_merge($chosen, $screen['check'] !== null ? ['id'] : [])) as $row) {
                $facts[] = [$row['label'], $row['value']];
            }
        }
        $heading = $screen['heading'] !== '' ? $screen['heading'] : $command;
        $question = $destroys
            ? self::firstSentence(self::text('plan.ask.'.$key, ['target' => (string) $target]) ?? $heading.'?')
            : (($target !== null && $target !== '' && !str_contains($heading, $target))
                ? (self::text('plan.ask_do', ['action' => $heading, 'target' => $target]) ?? $heading.'?') : $heading.'?');
        $fields = [];
        foreach ($hidden as $name => $value) {
            if (is_string($name) && (is_string($value) || is_int($value)) && !in_array($name, $chosen, true)) {
                $fields[$name] = (string) $value;
            }
        }
        $planUrl = str_ends_with($runUrl, '/run') && $options !== [] ? substr($runUrl, 0, -4).'/plan' : '';

        return new AdminPlannedAction(
            question: $question,
            runUrl: $runUrl,
            token: $token,
            runFields: $fields,
            acceptText: $destroys ? $heading : ($heading !== $command ? $heading : (self::text('plan.confirm') ?? '')),
            danger: $destroys,
            consequence: $destroys ? (self::text('plan.consequence.'.$key) ?? '') : '',
            require: $destroys && $target !== null ? $target : '',
            facts: $facts,
            warnings: $screen['warnings'],
            details: [
                [self::text('plan.command') ?? 'Command', $command],
                [self::text('plan.effects') ?? 'Effects', PlanValues::effects($plan->effects)],
                [self::text('plan.lock') ?? 'Lock', PlanValues::lock($plan->lock)],
            ],
            detailsText: $plan->description,
            options: $options,
            planUrl: $planUrl,
            planFields: $fields,
            cancelUrl: $screen['cancel'],
            key: 'env-'.preg_replace('/[^a-z0-9-]/', '-', strtolower($key)),
            more: trim($screen['more']) !== '' ? [AdminRenderedPart::control($screen['more'])] : [],
        );
    }

    /** «Удалить сайт demo.test? Отменить это нельзя.» asks its first sentence; the rest is the plate's own words. */
    private static function firstSentence(string $text): string
    {
        $cut = strpos($text, '?');

        return $cut === false ? $text : substr($text, 0, $cut + 1);
    }

    /** @param array<string, string> $replace */
    private static function text(string $key, array $replace = []): ?string
    {
        if (!function_exists('__')) {
            return null;
        }
        $line = (string) __('larena-env::admin.'.$key, $replace);

        return $line !== 'larena-env::admin.'.$key && $line !== '' ? $line : null;
    }
}
