<?php

declare(strict_types=1);

namespace Larena\Env\Operations;

use UnexpectedValueException;

/** What an action would do, as the gateway answers it before anything runs (`kind: plan`). */
final readonly class PanelPlan
{
    /** @param array<string, string> $args */
    public function __construct(
        public string $command,
        public array $args,
        public ?string $target,
        public string $description,
        public string $effects,
        public ?string $lock,
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
        );
    }
}
