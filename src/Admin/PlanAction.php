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
        // A kind of site switched on or off (simai-env 1.57.5): what it changes, in words.
        'profile:enable' => ['kind' => false, 'sites' => false],
        'profile:disable' => ['kind' => false, 'sites' => false],
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

    /** Actions that do not destroy data but whose result the panel says in its own words: a paused site. */
    private const array SAID_CONSEQUENCES = ['site-suspend'];

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
        } elseif ($plan->transfer !== null || ($plan->account !== null && $plan->account['sites'] !== [])) {
            // Sites moving between server accounts (simai-env 1.57.0, 1.63.1): from whom to whom in the panel's words, not
            // the server's values.
            $words = static fn (string $key, array $replace = []): string => self::text($key, $replace) ?? $key;
            if ($plan->transfer !== null) {
                $facts[] = [self::text('plan.target') ?? 'Target', $plan->transfer['domain']];
                $facts = [...$facts, ...AccountMoves::transferFacts($plan->transfer, $words)];
            } elseif ($plan->account !== null) {
                $facts[] = $plan->account['new']
                    ? [$words('people.invite.fact.account_new'), $words('people.invite.fact.account_new_value', ['account' => $plan->account['owner']])]
                    : [$words('people.invite.fact.account_existing'), $plan->account['owner']];
                $facts = [...$facts, ...AccountMoves::createFacts($plan->account, $words)];
            }
        } else {
            if ($target !== null && $target !== '') {
                $facts[] = [self::text('plan.target') ?? 'Target', $target];
            }
            foreach (PlanValues::of($plan->args, $target, array_merge($chosen, $screen['check'] !== null ? ['id'] : [])) as $row) {
                // The panel's own words where it has them, else the server's (simai-env 1.60.0 `display`): «Язык сайта» · «английский».
                $said = $plan->said($row['name']);
                $facts[] = [$row['label'] === $row['name'] && $said !== null ? $said['label'] : $row['label'],
                    $said !== null && $row['value'] === ($plan->args[$row['name']] ?? null) && $said['words'] !== '' ? $said['words'] : $row['value']];
            }
        }
        $heading = $screen['heading'] !== '' ? $screen['heading'] : ($plan->title ?? $command);
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
            // What deletes, replaces or stops something says so on the plate: the panel's sentence, else the server's (simai-env 1.60.0).
            consequence: ($destroys || in_array($key, self::SAID_CONSEQUENCES, true) ? self::text('plan.consequence.'.$key) : null) ?? $plan->consequence ?? '',
            require: $destroys && $target !== null ? $target : '',
            facts: $facts,
            // What the panel knows, then what the server's check found: what would stop it, then what is worth knowing.
            warnings: [...$screen['warnings'], ...($plan->checks?->warnings() ?? [])],
            details: [
                [self::text('plan.command') ?? 'Command', $command],
                [self::text('plan.effects') ?? 'Effects', PlanValues::effects($plan->effects) !== $plan->effects || $plan->effectsWords === null
                    ? PlanValues::effects($plan->effects) : $plan->effectsWords],
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

    /**
     * The question of an action the server will not plan (simai-env 1.60.0 checks, 1.61.0 named refusals): the same
     * window with nothing to accept — what stops it as blocking warnings, the acceptance off, what is worth knowing below,
     * and the way out the module draws ($more: «Выпустить сертификат» where HTTPS is what is missing).
     *
     * @param list<string> $reasons what stops it, in words: the panel's for a known refusal, then the server's
     * @param list<string> $notes what is worth knowing
     * @param string $server the server's own words of the refusal (a console command among them), under «Подробнее» where
     *                       the panel already says it in one sentence (FASTPANEL comparison 2026-11-18)
     */
    public static function refused(string $heading, array $reasons, array $notes, string $runUrl, string $token, string $cancelUrl, string $key, string $more = '', string $server = ''): ?HtmlString
    {
        if (!class_exists(AdminPlannedAction::class) || $reasons === []) {
            return null;
        }
        $warnings = [...array_map(static fn (string $text): array => ['text' => $text, 'blocking' => true], $reasons),
            ...array_map(static fn (string $text): array => ['text' => $text, 'blocking' => false], $notes)];

        return (new AdminPlannedAction(
            question: $heading.'?',
            runUrl: $runUrl,
            token: $token,
            acceptText: $heading,
            warnings: $warnings,
            factsTitle: self::text('plan.refused_title') ?? '',
            detailsText: trim($server),
            cancelUrl: $cancelUrl,
            key: 'env-'.preg_replace('/[^a-z0-9-]/', '-', strtolower($key)),
            more: trim($more) !== '' ? [\Larena\Admin\Runtime\AdminRenderedPart::control($more)] : [],
        ))->html();
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
