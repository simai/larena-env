<?php

declare(strict_types=1);

namespace Larena\Env\Server;

/**
 * A kind of site the server makes (self:profiles, Panel plan 5.5): its name, a line about it when the server gives
 * one, and the site:add arguments it takes, with their defaults.
 */
final readonly class SiteProfile
{
    /** @param array<string, array{required: bool, default: ?string}> $args */
    public function __construct(
        public string $name,
        public ?string $description,
        public array $args,
    ) {
    }

    public function takes(string $argument): bool
    {
        return array_key_exists($argument, $this->args);
    }

    /** @param array<array-key, mixed> $data */
    public static function fromData(array $data): ?self
    {
        $name = $data['name'] ?? null;
        if (!is_string($name) || preg_match('/\A[a-z][a-z0-9-]{0,31}\z/D', $name) !== 1) {
            return null;
        }
        $args = [];
        foreach (is_array($data['args'] ?? null) ? $data['args'] : [] as $key => $argument) {
            // A list of {name, required, default}, or a map of name to its rules, or a plain list of names.
            $argName = is_string($argument) ? $argument : (is_array($argument) && is_string($argument['name'] ?? null) ? $argument['name'] : (is_string($key) ? $key : null));
            if ($argName === null) {
                continue;
            }
            $rules = is_array($argument) ? $argument : [];
            $default = $rules['default'] ?? null;
            $args[$argName] = ['required' => ($rules['required'] ?? false) === true,
                'default' => is_string($default) || is_int($default) ? (string) $default : (is_bool($default) ? ($default ? 'yes' : 'no') : null)];
        }

        return new self($name, is_string($data['description'] ?? null) ? $data['description'] : null, $args);
    }
}
