<?php

declare(strict_types=1);

namespace Larena\Env\Gateway;

use Larena\Env\Contracts\PanelFileGateway;
use Larena\Env\Contracts\PanelGateway;
use Throwable;

/**
 * A stand-in for the server's file commands (simai-env 1.39.0–1.40.0) and a site's nginx directives on recorded answers,
 * for tests and the install check only: the recordings hold one folder and a few plans, never the bytes of a download or
 * an upload. It answers file:* and site:nginx-overlay-* the way the gateway does, on a local folder per site, and lists
 * them in the host description; everything else goes to the recorded answers it wraps. Paths are checked as the gateway
 * checks them: nothing outside a site's folder, no `..`, no link that leaves it.
 */
final readonly class FakeFilesGateway implements PanelGateway, PanelFileGateway
{
    /** The commands this stand-in answers, added to the host description's own list. */
    public const array COMMANDS = ['file:list', 'file:stat', 'file:read', 'file:download', 'file:write', 'file:upload', 'file:mkdir', 'file:rename',
        'file:remove', 'file:archive', 'file:extract', 'site:nginx-overlay-get', 'site:nginx-overlay-set', 'site:nginx-overlay-clear', 'change:confirm', 'change:status'];

    /** How long an applied change waits for its confirmation before the server would put the previous one back. */
    private const int CHANGE_SECONDS = 120;

    private const array DESCRIPTIONS = [
        'file:write' => 'Replace or create a text file of a site', 'file:upload' => 'Put a file into a site', 'file:mkdir' => 'Make a directory',
        'file:rename' => 'Rename or move', 'file:remove' => 'Remove a file, a link or a directory with its contents',
        'file:archive' => 'Pack a file or directory into a new archive', 'file:extract' => 'Unpack an archive into a directory', 'site:nginx-overlay-set' => 'Apply nginx directives',
        'site:nginx-overlay-clear' => 'Remove nginx directives', 'change:confirm' => 'Keep the change',
    ];

    public function __construct(private PanelGateway $inner, private string $root)
    {
    }

    public function source(): string
    {
        return $this->inner->source();
    }

    public function ask(PanelRequest $request): PanelAnswer
    {
        $command = $request->command;
        if (in_array($command, ['job:status', 'job:log'], true) && is_file($this->jobFile($request->args['job_id'] ?? ''))) {
            return $this->job($request);
        }
        // A timed change of the firewall or of who reaches the panel is the server's own (simai-env 1.35.0, twelve hex
        // digits); the stand-in names its own with sixteen.
        $foreignChange = str_starts_with($command, 'change:') && preg_match('/\A[a-f0-9]{16}\z/D', (string) ($request->args['change_id'] ?? '')) !== 1;
        if (!in_array($command, self::COMMANDS, true) || $foreignChange) {
            $answer = $this->inner->ask($request);

            return $command === 'self:describe' ? $this->listed($request, $answer) : $answer;
        }
        if ($command === 'file:download') {
            return PanelAnswer::refused($request->requestId, PanelReason::NOT_ALLOWED);
        }
        // A timed change is named by its id alone; the stand-in knows only the nginx directives' changes.
        $domain = str_starts_with($command, 'change:') ? $this->changeDomain($request->args['change_id'] ?? '') : $request->args['domain'];
        $scope = $this->scope($request, $domain);
        if ($scope !== null) {
            return $scope;
        }
        try {
            if (PanelCommands::kind($command) === PanelCommands::READ) {
                return $this->ok($request, 'result', $this->read($command, $domain, $request->args));
            }
            $this->check($command, $domain, $request);
            if (!$request->confirm) {
                // As the gateway, a plan shows a text's size, never the text.
                $shown = isset($request->args['content']) ? ['content' => '('.strlen((string) $request->args['content']).' bytes)'] + $request->args : $request->args;

                return $this->ok($request, 'plan', ['command' => $command, 'args' => $shown, 'target' => $domain,
                    'description' => self::DESCRIPTIONS[$command] ?? $command, 'effects' => 'write', 'lock' => $domain, 'applied' => false]);
            }
            $lines = $this->act($command, $domain, $request);
        } catch (FakeRefusal $refusal) {
            return PanelAnswer::refused($request->requestId, $refusal->reason);
        }

        return $this->started($request, $lines);
    }

    public function download(PanelRequest $request, string $into): PanelDownload
    {
        $domain = $request->args['domain'] ?? '';
        $scope = $this->scope($request, $domain);
        try {
            if ($scope !== null) {
                throw new FakeRefusal($scope->reason ?? PanelReason::NOT_ALLOWED);
            }
            $path = $this->path($domain, $request->args['path'] ?? '');
            if (!file_exists($path)) {
                throw new FakeRefusal('not_found');
            }
            if (!is_file($path) || is_link($path)) {
                throw new FakeRefusal('not_a_file');
            }
            $header = json_encode(['schema' => 'simai-panel-answer/1', 'request_id' => $request->requestId, 'status' => 'ok', 'kind' => 'download',
                'data' => ['path' => $request->args['path'], 'size' => (int) filesize($path), 'mtime' => (int) filemtime($path)]],
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)."\n";
            file_put_contents($into, $header);
            file_put_contents($into, (string) file_get_contents($path), FILE_APPEND);
        } catch (FakeRefusal $refusal) {
            file_put_contents($into, json_encode(['schema' => 'simai-panel-answer/1', 'request_id' => $request->requestId, 'status' => 'refused',
                'reason' => $refusal->reason], JSON_THROW_ON_ERROR)."\n");
        }

        return PanelDownload::read($request->requestId, $into);
    }

    /** The host description lists the file commands too: the screens offer what a server lists. */
    private function listed(PanelRequest $request, PanelAnswer $answer): PanelAnswer
    {
        if ($answer->status !== PanelAnswerStatus::Ok || !is_array($answer->data['gate']['commands'] ?? null)) {
            return $answer;
        }
        $data = $answer->data;
        $data['gate']['commands'] = array_values(array_unique([...$data['gate']['commands'], ...$this->offered($request)]));

        return PanelAnswer::fromDocument($request->requestId, ['status' => 'ok', 'kind' => 'result', 'data' => $data,
            'command_status' => $answer->commandStatus, 'operation_id' => $answer->operationId]);
    }

    /** @return list<string> an owner gets the files, never the nginx directives (the system level's) */
    private function offered(PanelRequest $request): array
    {
        return $request->actor->owner === null ? self::COMMANDS : array_values(array_filter(self::COMMANDS, static fn (string $command): bool => str_starts_with($command, 'file:')));
    }

    /** An owner works on their own sites only, and never on nginx: as the gateway, through the recorded description. */
    private function scope(PanelRequest $request, string $domain): ?PanelAnswer
    {
        if ($request->actor->owner === null) {
            return $domain === '' ? PanelAnswer::refused($request->requestId, 'unknown_change') : null;
        }
        if (!str_starts_with($request->command, 'file:')) {
            return PanelAnswer::refused($request->requestId, 'owner_admin_only');
        }
        $own = $this->inner->ask(PanelRequest::read($request->actor, 'site:describe', ['domain' => $domain]));

        return $own->status === PanelAnswerStatus::Ok ? null : PanelAnswer::refused($request->requestId, $own->reason ?? 'owner_scope');
    }

    /**
     * @param array<string, string> $args
     *
     * @return array<string, mixed>
     *
     * @throws FakeRefusal
     */
    private function read(string $command, string $domain, array $args): array
    {
        if ($command === 'site:nginx-overlay-get') {
            $file = $this->root.'/overlay/'.$domain.'.conf';

            return ['content' => is_file($file) ? (string) file_get_contents($file) : '', 'applied_at' => is_file($file) ? gmdate('Y-m-d\TH:i:s\Z', (int) filemtime($file)) : null,
                'pending' => $this->pending($domain)];
        }
        if ($command === 'change:status') {
            $pending = $this->pending($domain);

            return ['change_id' => $args['change_id'], 'kind' => 'nginx_overlay', 'domain' => $domain,
                'state' => ($pending['change_id'] ?? null) === $args['change_id'] ? 'pending' : 'confirmed', 'expires_at' => $pending['expires_at'] ?? null];
        }
        $relative = $args['path'] ?? '';
        $path = $this->path($domain, $relative);
        if ($command === 'file:list') {
            if (!is_dir($path)) {
                throw new FakeRefusal(file_exists($path) ? 'not_a_directory' : 'not_found');
            }
            $entries = [];
            foreach (array_diff((array) scandir($path), ['.', '..']) as $name) {
                $entries[] = ['name' => (string) $name] + self::stat($path.'/'.$name);
            }
            // Directories first, as the gateway lists them.
            usort($entries, static fn (array $a, array $b): int => [$a['type'] === 'dir' ? 0 : 1, $a['name']] <=> [$b['type'] === 'dir' ? 0 : 1, $b['name']]);

            return ['schema' => 'simai-files/1', 'path' => $relative === '.' ? '' : $relative, 'entries' => $entries, 'truncated' => false];
        }
        if (!file_exists($path) && !is_link($path)) {
            throw new FakeRefusal('not_found');
        }
        if ($command === 'file:stat') {
            return ['schema' => 'simai-file-stat/1', 'path' => $relative, 'name' => basename($relative)] + self::stat($path);
        }
        if (!is_file($path) || is_link($path)) {
            throw new FakeRefusal('not_a_file');
        }
        if (filesize($path) > 1_048_576) {
            throw new FakeRefusal('too_large');
        }
        $bytes = (string) file_get_contents($path);
        if (!mb_check_encoding($bytes, 'UTF-8') || str_contains($bytes, "\0")) {
            throw new FakeRefusal('not_text');
        }

        return ['schema' => 'simai-file/1', 'path' => $relative, 'size' => strlen($bytes), 'mtime' => (int) filemtime($path), 'sha256' => hash('sha256', $bytes), 'content' => $bytes];
    }

    /** @return array{type: string, size: int, mtime: int, mode: string, target?: string} */
    private static function stat(string $full): array
    {
        $type = is_link($full) ? 'link' : (is_dir($full) ? 'dir' : (is_file($full) ? 'file' : 'other'));
        $stat = ['type' => $type, 'size' => $type === 'link' ? 0 : (int) @filesize($full), 'mtime' => (int) @filemtime($full),
            'mode' => sprintf('%o', (int) @fileperms($full) & 0o777)];

        return $type === 'link' ? $stat + ['target' => (string) readlink($full)] : $stat;
    }

    /** @throws FakeRefusal what the gateway refuses before it plans */
    private function check(string $command, string $domain, PanelRequest $request): void
    {
        $args = $request->args;
        match ($command) {
            'file:rename' => $this->free($this->path($domain, $args['to'])) && $this->existing($this->path($domain, $args['path'])),
            'file:mkdir' => $this->free($this->path($domain, $args['path'])),
            'file:remove' => $this->existing($this->path($domain, $args['path'])) && $this->notSite($args['path']),
            'file:archive' => $this->existing($this->path($domain, $args['path'])) && $this->free($this->path($domain, $args['to'])),
            'file:extract' => $this->existing($this->path($domain, $args['path'])) && $this->path($domain, $args['to']) !== '',
            'file:write', 'file:upload' => $this->expected($this->path($domain, $args['path']), $args['expect-sha256'] ?? 'any'),
            default => true,
        };
    }

    /**
     * What the person expected at the path is still there: nothing (`new`), the text read before (its sha256), or
     * anything (`any`).
     *
     * @throws FakeRefusal
     */
    private function expected(string $path, string $expect): bool
    {
        if ($expect === 'new') {
            return $this->free($path);
        }
        if ($expect !== 'any' && (!is_file($path) || !hash_equals($expect, (string) hash_file('sha256', $path)))) {
            throw new FakeRefusal(is_file($path) ? 'changed' : 'not_found');
        }

        return true;
    }

    /** @throws FakeRefusal the site's folder itself is never removed */
    private function notSite(string $relative): bool
    {
        if (trim($relative, '/.') === '') {
            throw new FakeRefusal('bad_path');
        }

        return true;
    }

    /**
     * @return list<string> the job's lines
     *
     * @throws FakeRefusal
     */
    private function act(string $command, string $domain, PanelRequest $request): array
    {
        $args = $request->args;
        switch ($command) {
            case 'file:write':
                $target = $this->path($domain, $args['path']);
                file_put_contents($target, (string) $args['content']);

                return ["Wrote {$args['path']} (".strlen((string) $args['content']).' bytes)'];
            case 'file:upload':
                $target = $this->path($domain, $args['path']);
                $attachment = $request->attachment ?? throw new FakeRefusal('bad_request');
                if (!hash_equals($attachment->sha256, (string) hash_file('sha256', $attachment->path))) {
                    throw new FakeRefusal('attachment_mismatch');
                }
                copy($attachment->path, $target);

                return ["Uploaded {$args['path']} ({$attachment->size} bytes)"];
            case 'file:mkdir':
                if (!is_dir(dirname($this->path($domain, $args['path'])))) {
                    throw new FakeRefusal('not_found');
                }
                mkdir($this->path($domain, $args['path']), 0o775);

                return ["Made {$args['path']}"];
            case 'file:rename':
                rename($this->path($domain, $args['path']), $this->path($domain, $args['to']));

                return ["Renamed {$args['path']} to {$args['to']}"];
            case 'file:remove':
                self::removeTree($this->path($domain, $args['path']));

                return ["Removed {$args['path']}"];
            case 'file:archive':
                return ["Packed {$args['path']} into {$args['to']}", 'packed: 1'];
            case 'file:extract':
                if (($args['overwrite'] ?? 'no') !== 'yes' && is_dir($this->path($domain, $args['to'])) && $this->path($domain, $args['to']) !== $this->path($domain, '.')) {
                    // The stand-in unpacks nothing; a folder that is there already holds files of the same name.
                    throw new FakeRefusal('exists');
                }

                return ["Unpacked {$args['path']} into {$args['to']}", 'files: 0, dirs: 0, skipped: 0'];
            case 'site:nginx-overlay-set':
            case 'site:nginx-overlay-clear':
                @mkdir($this->root.'/overlay', 0o775, true);
                $content = $command === 'site:nginx-overlay-clear' ? '' : (string) file_get_contents(($request->attachment ?? throw new FakeRefusal('bad_request'))->path);
                file_put_contents($this->root.'/overlay/'.$domain.'.conf', $content);
                $change = bin2hex(random_bytes(8));
                @mkdir($this->root.'/overlay/changes', 0o775, true);
                file_put_contents($this->root.'/overlay/'.$domain.'.pending', json_encode(['change_id' => $change,
                    'expires_at' => gmdate('Y-m-d\TH:i:s\Z', time() + self::CHANGE_SECONDS)], JSON_THROW_ON_ERROR));
                file_put_contents($this->root.'/overlay/changes/'.$change, $domain);

                return ['nginx -t: ok', 'Reloaded nginx', 'Change '.$change.': confirm within '.self::CHANGE_SECONDS.' seconds or it is undone'];
            case 'change:confirm':
                if (($this->pending($domain)['change_id'] ?? null) !== $args['change_id']) {
                    throw new FakeRefusal('unknown_change');
                }
                unlink($this->root.'/overlay/'.$domain.'.pending');
                unlink($this->root.'/overlay/changes/'.$args['change_id']);

                return ["Change {$args['change_id']} kept"];
        }

        throw new FakeRefusal('not_allowed');
    }

    /**
     * The local path of a site's path: its folder is made with a page and a note the first time; anything that would
     * leave it is refused path_outside, as the gateway does.
     *
     * @throws FakeRefusal
     */
    private function path(string $domain, string $relative): string
    {
        $site = $this->root.'/sites/'.$domain;
        if (!is_dir($site)) {
            mkdir($site.'/public', 0o775, true);
            file_put_contents($site.'/public/index.html', "<h1>{$domain}</h1>\n");
            file_put_contents($site.'/notes.txt', "Notes of {$domain}\n");
        }
        if ($relative === '.' || $relative === '') {
            return $site;
        }
        if (str_starts_with($relative, '/') || preg_match('#(\A|/)\.\.(/|\z)#', $relative) === 1) {
            throw new FakeRefusal('path_outside');
        }
        $path = $site.'/'.trim($relative, '/');
        $real = realpath(file_exists($path) ? $path : dirname($path));
        $siteReal = (string) realpath($site);
        if ($real === false || ($real !== $siteReal && !str_starts_with($real, $siteReal.'/'))) {
            throw new FakeRefusal(file_exists(dirname($path)) || $real !== false ? 'path_outside' : 'not_found');
        }

        return $path;
    }

    /** @throws FakeRefusal */
    private function free(string $path): bool
    {
        if (file_exists($path)) {
            throw new FakeRefusal('exists');
        }

        return true;
    }

    /** @throws FakeRefusal */
    private function existing(string $path): bool
    {
        if (!file_exists($path)) {
            throw new FakeRefusal('not_found');
        }

        return true;
    }

    /** @return array{change_id: string, expires_at: string}|null the nginx directives' change applied and not kept yet */
    private function pending(string $domain): ?array
    {
        $pending = json_decode((string) @file_get_contents($this->root.'/overlay/'.$domain.'.pending'), true);

        return is_array($pending) && is_string($pending['change_id'] ?? null) && is_string($pending['expires_at'] ?? null)
            ? ['change_id' => $pending['change_id'], 'expires_at' => $pending['expires_at']] : null;
    }

    /** The site a pending change belongs to; '' for a change the stand-in does not know. */
    private function changeDomain(string $change): string
    {
        $file = $this->root.'/overlay/changes/'.$change;

        return preg_match('/\A[a-f0-9]{16}\z/D', $change) === 1 && is_file($file) ? trim((string) file_get_contents($file)) : '';
    }

    /** @param array<string, mixed> $data */
    private function ok(PanelRequest $request, string $kind, array $data): PanelAnswer
    {
        return PanelAnswer::fromDocument($request->requestId, ['status' => 'ok', 'kind' => $kind, 'data' => $data, 'command_status' => 'success']);
    }

    /** @param list<string> $lines */
    private function started(PanelRequest $request, array $lines): PanelAnswer
    {
        $id = bin2hex(random_bytes(12));
        @mkdir($this->root.'/jobs', 0o775, true);
        file_put_contents($this->jobFile($id), json_encode(['job_id' => $id, 'state' => 'succeeded', 'exit_code' => 0, 'command' => $request->command,
            'args' => $request->args, 'created_at' => gmdate('Y-m-d\TH:i:s\Z'), 'request_id' => $request->requestId,
            'actor' => ['user' => $request->actor->user, 'owner' => $request->actor->owner], 'lines' => $lines], JSON_THROW_ON_ERROR));

        return PanelAnswer::fromDocument($request->requestId, ['status' => 'ok', 'kind' => 'job', 'job_id' => $id]);
    }

    private function job(PanelRequest $request): PanelAnswer
    {
        try {
            $job = json_decode((string) file_get_contents($this->jobFile($request->args['job_id'] ?? '')), true, 16, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return PanelAnswer::failed($request->requestId, PanelReason::RECORDING_MISSING);
        }
        $lines = (array) $job['lines'];
        unset($job['lines']);
        $after = (int) ($request->args['after'] ?? 0);

        return $this->ok($request, 'result', $request->command === 'job:status' ? $job
            : ['job_id' => $job['job_id'], 'state' => $job['state'], 'lines' => array_slice($lines, $after), 'next' => count($lines)]);
    }

    private function jobFile(string $id): string
    {
        return $this->root.'/jobs/'.(preg_match('/\A[a-f0-9]{24}\z/D', $id) === 1 ? $id : 'none').'.json';
    }

    private static function removeTree(string $path): void
    {
        if (is_dir($path) && !is_link($path)) {
            foreach (array_diff((array) scandir($path), ['.', '..']) as $name) {
                self::removeTree($path.'/'.$name);
            }
            rmdir($path);

            return;
        }
        @unlink($path);
    }
}
