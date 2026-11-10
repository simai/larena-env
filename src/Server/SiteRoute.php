<?php

declare(strict_types=1);

namespace Larena\Env\Server;

use Larena\Env\Gateway\PanelCommands;

/**
 * One way a site of a kind is made (self:profiles `routes`, simai-env 1.56.0): new, restored from a copy, or a release from
 * a repository. It names what it asks (`inputs`, each a text, a choice, an address, a file the route puts on the server
 * first or bytes sent with a step) and its steps, each a gateway command or `open_url`, the last always `site:doctor`.
 * A route the server marks unavailable is planned there, not made yet.
 */
final readonly class SiteRoute
{
    /** The kinds of input the panel asks on the form; a file is put on the server after the site is made. */
    public const array ASKED = ['text', 'choice', 'url'];

    /**
     * @param list<array{name: string, required: bool, kind: string, values: ?list<string>, default: ?string, description: ?string}> $inputs
     * @param list<array{id: string, title: string, command: string, args: array<string, string>, url_from: ?string}> $steps
     */
    public function __construct(
        public string $id,
        public string $title,
        public bool $available,
        public array $inputs,
        public array $steps,
    ) {
    }

    /** @param array<array-key, mixed> $data */
    public static function fromData(array $data): ?self
    {
        $id = $data['id'] ?? null;
        if (!is_string($id) || preg_match('/\A[a-z][a-z0-9_-]{0,31}\z/D', $id) !== 1) {
            return null;
        }
        $inputs = [];
        foreach (is_array($data['inputs'] ?? null) ? $data['inputs'] : [] as $input) {
            if (!is_array($input) || !is_string($input['name'] ?? null) || preg_match('/\A[a-z][a-z0-9-]{0,31}\z/D', $input['name']) !== 1) {
                continue;
            }
            $default = $input['default'] ?? null;
            $inputs[] = ['name' => $input['name'], 'required' => ($input['required'] ?? false) === true,
                'kind' => is_string($input['kind'] ?? null) ? $input['kind'] : 'text',
                'values' => is_array($input['values'] ?? null) ? array_values(array_filter($input['values'], 'is_string')) : null,
                'default' => is_string($default) || is_int($default) ? (string) $default : null,
                'description' => is_string($input['description'] ?? null) ? $input['description'] : null];
        }
        $steps = [];
        foreach (is_array($data['steps'] ?? null) ? $data['steps'] : [] as $step) {
            if (!is_array($step) || !is_string($step['id'] ?? null) || !is_string($step['command'] ?? null)) {
                continue;
            }
            $args = [];
            foreach (is_array($step['args'] ?? null) ? $step['args'] : [] as $name => $value) {
                if (is_string($value)) {
                    $args[(string) $name] = $value;
                }
            }
            $steps[] = ['id' => $step['id'], 'title' => is_string($step['title'] ?? null) ? $step['title'] : $step['id'], 'command' => $step['command'],
                'args' => $args, 'url_from' => is_string($step['url_from'] ?? null) ? $step['url_from'] : null];
        }

        return new self($id, is_string($data['title'] ?? null) ? $data['title'] : $id, ($data['available'] ?? false) === true, $inputs, $steps);
    }

    /** The command of the step after the site is made that takes the input (wp:install takes the title), or null. */
    public function stepTaking(string $input): ?string
    {
        foreach ($this->steps as $step) {
            if ($step['command'] !== 'site:add' && PanelCommands::accepts($step['command'], $input)) {
                return $step['command'];
            }
        }

        return null;
    }

    /** The fixed arguments the route gives site:add (bitrix-files: setup or restore). @return array<string, string> */
    public function addArguments(): array
    {
        foreach ($this->steps as $step) {
            if ($step['command'] === 'site:add') {
                return $step['args'];
            }
        }

        return [];
    }

    /** The index of a step by its id, or null. */
    public function stepIndex(string $id): ?int
    {
        foreach ($this->steps as $index => $step) {
            if ($step['id'] === $id) {
                return $index;
            }
        }

        return null;
    }

    /**
     * Why the panel cannot lead this route, or null when it can: the server plans it only; bytes sent with a step (the
     * archive of a static release) the panel does not send; or this server does not let the panel make the site. A later
     * step the panel does not lead on this server is done outside it and said so; it does not stop the route.
     *
     * @param callable(string): bool $allows whether this server's gateway allows a command
     */
    public function blockedBy(callable $allows): ?string
    {
        if (!$this->available) {
            return 'planned';
        }
        foreach ($this->inputs as $input) {
            if ($input['kind'] === 'upload') {
                return 'upload';
            }
        }

        return $allows('site:add') ? null : 'command';
    }

    /**
     * Whether the route deploys a release from Git (the Laravel repository route, simai-env 1.56.2): it asks the repository
     * and has a site:deploy step. A route deploying an archive sent with the step does not.
     */
    public function deploysFromGit(): bool
    {
        return in_array('git', array_column($this->inputs, 'name'), true) && in_array('site:deploy', array_column($this->steps, 'command'), true);
    }

    /**
     * Whether the panel leads the step on this server: a command of its table the server allows, a page the browser
     * opens, the site's files, or the check that ends every route.
     *
     * @param array{command: string} $step
     * @param callable(string): bool $allows
     */
    public static function led(array $step, callable $allows): bool
    {
        return in_array($step['command'], ['open_url', 'site:doctor', 'file:upload'], true)
            || (PanelCommands::kind($step['command']) !== null && $allows($step['command']));
    }
}
