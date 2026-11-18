<?php

declare(strict_types=1);

namespace Larena\Env\Operations;

use Larena\Env\Software\SystemUpdates;
use Larena\Env\Update\UpdatePlan;
use UnexpectedValueException;

/** What an action would do, as the gateway answers it before anything runs (`kind: plan`). */
final readonly class PanelPlan
{
    /**
     * @param array<string, string> $args
     * @param ?UpdatePlan $update what a safe update would change (self:update's plan carries it, simai-env 1.32.0)
     * @param ?SystemUpdates $system the packages a system update would install (software:update's plan, simai-env 1.38.0)
     * @param ?PlanCheck $check the rule of a site's check a fix would put right (site:check-fix's plan, simai-env 1.55.1)
     */
    public function __construct(
        public string $command,
        public array $args,
        public ?string $target,
        public string $description,
        public string $effects,
        public ?string $lock,
        public ?UpdatePlan $update = null,
        public ?SystemUpdates $system = null,
        public ?PlanCheck $check = null,
        // What a new site or a restored copy asks of the server (site:add, bitrix:restore-ready, simai-env 1.56.0).
        public ?PlanResources $resources = null,
        // The plan in words (simai-env 1.60.0, `lang`): its title, each value with its label and words, what it does to
        // data, the sentence for what deletes or replaces data, and what the plan's check found.
        public ?string $title = null,
        /** @var list<array{key: string, label: string, value: string, words: string}> */
        public array $display = [],
        public ?string $effectsWords = null,
        public ?string $consequence = null,
        public ?PlanChecks $checks = null,
    ) {
    }

    /**
     * A value's words as the server says them, by its argument; null when the server said none.
     *
     * @return array{key: string, label: string, value: string, words: string}|null
     */
    public function said(string $key): ?array
    {
        foreach ($this->display as $row) {
            if ($row['key'] === $key) {
                return $row;
            }
        }

        return null;
    }

    /**
     * @param array<array-key, mixed> $data
     *
     * @throws UnexpectedValueException
     */
    public static function fromData(array $data): self
    {
        $command = $data['command'] ?? null;
        $description = $data['description'] ?? null;
        $effects = $data['effects'] ?? null;
        if (!is_string($command) || !is_string($description) || !is_string($effects)) {
            throw new UnexpectedValueException('plan_field_invalid');
        }
        // A plan that says it has already been applied is not a plan: the panel never shows it as one.
        if (($data['applied'] ?? false) !== false) {
            throw new UnexpectedValueException('plan_already_applied');
        }
        $args = [];
        foreach (is_array($data['args'] ?? null) ? $data['args'] : [] as $name => $value) {
            // The addresses that reach the panel are the one list an argument may be; the plan shows them one after another.
            if (is_array($value) && array_is_list($value) && array_filter($value, 'is_string') === $value) {
                $value = implode(', ', $value);
            }
            if (!is_string($value)) {
                throw new UnexpectedValueException('plan_field_invalid');
            }
            $args[(string) $name] = $value;
        }

        return new self(
            command: $command,
            args: $args,
            target: is_string($data['target'] ?? null) ? $data['target'] : null,
            description: $description,
            effects: $effects,
            lock: is_string($data['lock'] ?? null) ? $data['lock'] : null,
            update: is_array($data['update'] ?? null) ? UpdatePlan::fromData($data['update']) : null,
            system: $command === 'software:update' && is_array($data['update'] ?? null) ? SystemUpdates::fromData($data['update']) : null,
            check: $command === 'site:check-fix' && is_array($data['check'] ?? null) ? PlanCheck::fromData($data['check']) : null,
            resources: is_array($data['resources'] ?? null) ? PlanResources::fromData($data['resources'], is_array($data['archive'] ?? null) ? $data['archive'] : null) : null,
            title: self::words($data['title'] ?? null),
            display: self::display($data['display'] ?? null),
            effectsWords: self::words($data['effects_words'] ?? null),
            consequence: self::words($data['consequence'] ?? null),
            checks: PlanChecks::fromData($data['checks'] ?? null),
        );
    }

    private static function words(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? mb_substr(trim($value), 0, 500) : null;
    }

    /** @return list<array{key: string, label: string, value: string, words: string}> */
    private static function display(mixed $rows): array
    {
        $kept = [];
        foreach (is_array($rows) ? $rows : [] as $row) {
            if (!is_array($row) || !is_string($row['key'] ?? null)) {
                continue;
            }
            $value = $row['value'] ?? '';
            $value = is_array($value) ? implode(', ', array_filter($value, 'is_string')) : (is_scalar($value) ? (string) $value : '');
            $kept[] = ['key' => $row['key'], 'label' => self::words($row['label'] ?? null) ?? $row['key'], 'value' => $value,
                'words' => self::words($row['words'] ?? null) ?? $value];
        }

        return $kept;
    }
}
