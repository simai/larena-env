<?php

declare(strict_types=1);

namespace Larena\Env\Software;

/** The server's software (software:list, simai-software/1, simai-env 1.38.0): its components and the system's updates. */
final readonly class ServerSoftware
{
    /** @param list<SoftwareComponent> $components */
    public function __construct(public array $components, public SystemUpdates $system)
    {
    }

    public function component(string $name): ?SoftwareComponent
    {
        foreach ($this->components as $component) {
            if ($component->name === $name) {
                return $component;
            }
        }

        return null;
    }

    /** @param array<array-key, mixed> $data */
    public static function fromData(array $data): self
    {
        $components = [];
        foreach (is_array($data['components'] ?? null) ? $data['components'] : [] as $item) {
            $made = is_array($item) ? SoftwareComponent::fromData($item) : null;
            if ($made !== null) {
                $components[] = $made;
            }
        }

        return new self($components, SystemUpdates::fromData(is_array($data['system'] ?? null) ? $data['system'] : []));
    }
}
