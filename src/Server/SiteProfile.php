<?php

declare(strict_types=1);

namespace Larena\Env\Server;

/**
 * A kind of site the server makes (self:profiles, simai-site-profiles/1, simai-env 1.33.0): its id and title, its
 * database (no, optional or required), and the site:add arguments it takes, each with the values it allows, its
 * default and a line about it. Only the kinds the administrator enabled are listed; an alias of another kind is not
 * one site:add takes, and is left out.
 */
final readonly class SiteProfile
{
    /** @param array<string, array{required: bool, default: ?string, values: ?list<string>, description: ?string}> $args */
    public function __construct(
        public string $name,
        public ?string $description,
        public array $args,
        public ?string $title = null,
        public string $db = 'no',
    ) {
    }

    public function takes(string $argument): bool
    {
        return array_key_exists($argument, $this->args);
    }

    /** @return list<string>|null the values an argument allows, when the server lists them */
    public function values(string $argument): ?array
    {
        return $this->args[$argument]['values'] ?? null;
    }

    /** @param array<array-key, mixed> $data */
    public static function fromData(array $data): ?self
    {
        $name = $data['id'] ?? $data['name'] ?? null;
        if (!is_string($name) || preg_match('/\A[a-z][a-z0-9-]{0,31}\z/D', $name) !== 1 || ($data['alias'] ?? false) === true) {
            return null;
        }
        $args = [];
        foreach (is_array($data['args'] ?? null) ? $data['args'] : [] as $argument) {
            if (!is_array($argument) || !is_string($argument['name'] ?? null)) {
                continue;
            }
            $default = $argument['default'] ?? null;
            $values = is_array($argument['values'] ?? null) ? array_values(array_filter($argument['values'], 'is_string')) : null;
            $args[$argument['name']] = ['required' => ($argument['required'] ?? false) === true,
                'default' => is_string($default) || is_int($default) ? (string) $default : null,
                'values' => $values, 'description' => is_string($argument['description'] ?? null) ? $argument['description'] : null];
        }
        $db = $data['db'] ?? 'no';

        return new self($name, is_string($data['description'] ?? null) ? $data['description'] : null, $args,
            is_string($data['title'] ?? null) ? $data['title'] : null, in_array($db, ['no', 'optional', 'required'], true) ? $db : 'no');
    }
}
