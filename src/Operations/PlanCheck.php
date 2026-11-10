<?php

declare(strict_types=1);

namespace Larena\Env\Operations;

use UnexpectedValueException;

/**
 * The rule of a site's check a fix would put right, as the plan of `site:check-fix` carries it (simai-env 1.55.1): which
 * rule, what the server found and what it would do. The words are the server's own.
 */
final readonly class PlanCheck
{
    public function __construct(public string $id, public string $title, public ?string $plan, public ?string $detail, public ?string $state)
    {
    }

    /**
     * @param array<array-key, mixed> $data
     *
     * @throws UnexpectedValueException
     */
    public static function fromData(array $data): self
    {
        if (!is_string($data['id'] ?? null) || !is_string($data['title'] ?? null)) {
            throw new UnexpectedValueException('plan_field_invalid');
        }
        $text = static fn (string $name): ?string => is_string($data[$name] ?? null) && trim($data[$name]) !== '' ? $data[$name] : null;

        return new self($data['id'], $data['title'], $text('plan'), $text('detail'), $text('state'));
    }
}
