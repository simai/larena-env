<?php

declare(strict_types=1);

namespace Larena\Env\Operations;

use Larena\Env\Update\UpdatePlan;
use UnexpectedValueException;

/** What an action would do, as the gateway answers it before anything runs (`kind: plan`). */
final readonly class PanelPlan
{
    /**
     * @param array<string, string> $args
     * @param ?UpdatePlan $update what a safe update would change (self:update's plan carries it, simai-env 1.32.0)
     */
    public function __construct(
        public string $command,
        public array $args,
        public ?string $target,
        public string $description,
        public string $effects,
        public ?string $lock,
        public ?UpdatePlan $update = null,
    ) {
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
            // fs:archive's paths are the one list an argument may be; the plan shows them one after another.
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
        );
    }
}
