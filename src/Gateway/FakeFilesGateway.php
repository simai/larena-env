<?php

declare(strict_types=1);

namespace Larena\Env\Gateway;

use Larena\Env\Contracts\PanelFileGateway;
use Larena\Env\Contracts\PanelGateway;
use Larena\Env\Files\FileModes;
use Throwable;

/**
 * A stand-in for the server's file commands (Panel plan 6.1–6.3) on recorded answers, for tests and the install check
 * only: simai-env has no release with them yet, so no recording exists. It answers fs:* and site:nginx-overlay-* the
 * way the agreed protocol does, on a local folder per site, and lists them in the host description; everything else
 * goes to the recorded answers it wraps. Paths are checked as the gateway checks them: nothing outside a site's folder,
 * no `..`, no link that leaves it.
 */
final readonly class FakeFilesGateway implements PanelGateway, PanelFileGateway
{
    /** The commands this stand-in answers, added to the host description's own list. */
    public const array COMMANDS = ['fs:list', 'fs:read', 'fs:trash-list', 'fs:download', 'fs:write', 'fs:upload', 'fs:mkdir', 'fs:rename',
        'fs:copy', 'fs:delete', 'fs:restore', 'fs:chmod', 'fs:archive', 'fs:extract',
        'site:nginx-overlay-get', 'site:nginx-overlay-set', 'site:nginx-overlay-clear', 'site:nginx-overlay-confirm'];

    private const array DESCRIPTIONS = [
        'fs:write' => 'Save a file', 'fs:upload' => 'Upload a file', 'fs:mkdir' => 'Make a folder', 'fs:rename' => 'Rename',
        'fs:copy' => 'Copy', 'fs:delete' => 'Move to the trash', 'fs:restore' => 'Restore from the trash', 'fs:chmod' => 'Change permissions',
        'fs:archive' => 'Pack into an archive', 'fs:extract' => 'Unpack an archive', 'site:nginx-overlay-set' => 'Apply nginx directives',
        'site:nginx-overlay-clear' => 'Remove nginx directives', 'site:nginx-overlay-confirm' => 'Keep the nginx directives',
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
        if (!in_array($command, self::COMMANDS, true)) {
            $answer = $this->inner->ask($request);

            return $command === 'self:describe' ? $this->listed($request, $answer) : $answer;
        }
        if ($command === 'fs:download') {
            return PanelAnswer::refused($request->requestId, PanelReason::NOT_ALLOWED);
        }
        $domain = $request->args['domain'];
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
                return $this->ok($request, 'plan', ['command' => $command, 'args' => $request->args, 'target' => $domain,
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
            if (!is_file($path)) {
                throw new FakeRefusal('not_found');
            }
            $header = json_encode(['schema' => 'simai-panel-answer/1', 'request_id' => $request->requestId, 'status' => 'ok', 'kind' => 'result',
                'data' => ['size' => (int) filesize($path), 'sha256' => hash_file('sha256', $path), 'name' => basename($path), 'mime' => 'application/octet-stream']],
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
        return $request->actor->owner === null ? self::COMMANDS : array_values(array_filter(self::COMMANDS, static fn (string $command): bool => str_starts_with($command, 'fs:')));
    }

    /** An owner works on their own sites only, and never on nginx: as the gateway, through the recorded description. */
    private function scope(PanelRequest $request, string $domain): ?PanelAnswer
    {
        if ($request->actor->owner === null) {
            return null;
        }
        if (!str_starts_with($request->command, 'fs:')) {
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
        if ($command === 'fs:trash-list') {
            return ['items' => $this->trashItems($domain)];
        }
        if ($command === 'site:nginx-overlay-get') {
            $file = $this->root.'/overlay/'.$domain.'.conf';
            $pending = $this->root.'/overlay/'.$domain.'.pending';

            return ['content' => is_file($file) ? (string) file_get_contents($file) : '', 'pending' => is_file($pending) ? trim((string) file_get_contents($pending)) : null];
        }
        $path = $this->path($domain, $args['path']);
        if ($command === 'fs:list') {
            if (!is_dir($path)) {
                throw new FakeRefusal('not_a_directory');
            }
            $entries = [];
            foreach (array_diff((array) scandir($path), ['.', '..']) as $name) {
                $full = $path.'/'.$name;
                $entries[] = ['name' => (string) $name, 'type' => is_link($full) ? 'link' : (is_dir($full) ? 'dir' : 'file'),
                    'size' => is_file($full) ? (int) filesize($full) : 0, 'mode' => sprintf('%04o', fileperms($full) & 0o777),
                    'mtime' => gmdate('Y-m-d\TH:i:s\Z', (int) filemtime($full)), 'owner' => 'site'];
            }

            return ['path' => $args['path'], 'entries' => $entries, 'truncated' => false];
        }
        if (!is_file($path)) {
            throw new FakeRefusal('not_found');
        }
        $limit = min(1_048_576, (int) ($args['max_bytes'] ?? 1_048_576));
        $bytes = (string) file_get_contents($path, false, null, 0, $limit + 1);
        if (strlen($bytes) > $limit || !mb_check_encoding($bytes, 'UTF-8') || str_contains($bytes, "\0")) {
            return ['path' => $args['path'], 'size' => (int) filesize($path), 'binary' => true];
        }

        return ['path' => $args['path'], 'size' => (int) filesize($path), 'binary' => false, 'content' => $bytes];
    }

    /** @throws FakeRefusal what the gateway refuses before it plans */
    private function check(string $command, string $domain, PanelRequest $request): void
    {
        $args = $request->args;
        match ($command) {
            'fs:rename', 'fs:copy' => $this->free($this->path($domain, $args['to'])) && $this->existing($this->path($domain, $args['from'])),
            'fs:mkdir' => $this->free($this->path($domain, $args['path'])),
            'fs:upload' => ($args['overwrite'] ?? 'no') === 'yes' || $this->free($this->path($domain, $args['path'])),
            'fs:delete', 'fs:chmod', 'fs:archive' => $this->existing($this->path($domain, $args['path'] ?? $args['paths'])),
            'fs:extract' => $this->existing($this->path($domain, $args['path'])) && $this->path($domain, $args['into']) !== '',
            'fs:restore' => $this->restorable($domain, $args['id']),
            'fs:write' => $this->path($domain, $args['path']) !== '',
            default => true,
        };
        if ($command === 'fs:chmod' && !FileModes::allowed($args['mode'], is_dir($this->path($domain, $args['path'])))) {
            throw new FakeRefusal('invalid_argument');
        }
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
            case 'fs:write':
            case 'fs:upload':
                $target = $this->path($domain, $args['path']);
                $attachment = $request->attachment ?? throw new FakeRefusal('bad_request');
                if (!hash_equals($attachment->sha256, (string) hash_file('sha256', $attachment->path))) {
                    throw new FakeRefusal('attachment_mismatch');
                }
                if (is_file($target)) {
                    $this->toTrash($domain, $target, $args['path'], $command === 'fs:write' ? '.bak-'.gmdate('YmdHis') : '', true);
                }
                @mkdir(dirname($target), 0o775, true);
                copy($attachment->path, $target);

                return ["Saved {$args['path']} ({$attachment->size} bytes)"];
            case 'fs:mkdir':
                mkdir($this->path($domain, $args['path']), 0o775, true);

                return ["Made {$args['path']}"];
            case 'fs:rename':
                rename($this->path($domain, $args['from']), $this->path($domain, $args['to']));

                return ["Renamed {$args['from']} to {$args['to']}"];
            case 'fs:copy':
                self::copyTree($this->path($domain, $args['from']), $this->path($domain, $args['to']));

                return ["Copied {$args['from']} to {$args['to']}"];
            case 'fs:delete':
                $path = $this->path($domain, $args['path']);
                if (is_dir($path) && ($args['recursive'] ?? 'no') !== 'yes' && array_diff((array) scandir($path), ['.', '..']) !== []) {
                    throw new FakeRefusal('not_empty');
                }
                $this->toTrash($domain, $path, $args['path'], '', false);

                return ["Moved {$args['path']} to the trash"];
            case 'fs:restore':
                $item = $this->trashDir($domain).'/'.$args['id'];
                $meta = json_decode((string) file_get_contents($item.'/meta.json'), true);
                $target = $this->path($domain, (string) $meta['path']);
                @mkdir(dirname($target), 0o775, true);
                rename($item.'/item', $target);
                self::removeTree($item);

                return ["Restored {$meta['path']}"];
            case 'fs:chmod':
                chmod($this->path($domain, $args['path']), (int) octdec($args['mode']));

                return ["Set {$args['mode']} on {$args['path']}"];
            case 'fs:archive':
                return ["Packed {$args['paths']} into {$args['name']}"];
            case 'fs:extract':
                return ["Unpacked {$args['path']} into {$args['into']}"];
            case 'site:nginx-overlay-set':
            case 'site:nginx-overlay-clear':
                @mkdir($this->root.'/overlay', 0o775, true);
                $content = $command === 'site:nginx-overlay-clear' ? '' : (string) file_get_contents(($request->attachment ?? throw new FakeRefusal('bad_request'))->path);
                file_put_contents($this->root.'/overlay/'.$domain.'.conf', $content);
                $change = bin2hex(random_bytes(8));
                file_put_contents($this->root.'/overlay/'.$domain.'.pending', $change);

                return ['nginx -t: ok', 'Reloaded nginx', "Change {$change}: confirm within 120 seconds or it is undone"];
            case 'site:nginx-overlay-confirm':
                $pending = $this->root.'/overlay/'.$domain.'.pending';
                if (!is_file($pending) || trim((string) file_get_contents($pending)) !== $args['change']) {
                    throw new FakeRefusal('unknown_change');
                }
                unlink($pending);

                return ["Change {$args['change']} kept"];
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

    /** @throws FakeRefusal */
    private function restorable(string $domain, string $id): bool
    {
        $meta = $this->trashDir($domain).'/'.$id.'/meta.json';
        if (preg_match('/\A[A-Za-z0-9][A-Za-z0-9._-]{0,127}\z/D', $id) !== 1 || !is_file($meta)) {
            throw new FakeRefusal('not_found');
        }
        $item = json_decode((string) file_get_contents($meta), true);

        return $this->free($this->path($domain, (string) ($item['path'] ?? '')));
    }

    private function toTrash(string $domain, string $path, string $relative, string $suffix, bool $copy): void
    {
        $id = gmdate('YmdHis').'-'.bin2hex(random_bytes(4));
        $item = $this->trashDir($domain).'/'.$id;
        mkdir($item, 0o775, true);
        $copy ? copy($path, $item.'/item') : rename($path, $item.'/item');
        file_put_contents($item.'/meta.json', json_encode(['path' => $relative.$suffix, 'deleted_at' => gmdate('Y-m-d\TH:i:s\Z'),
            'type' => is_dir($item.'/item') ? 'dir' : 'file', 'size' => is_file($item.'/item') ? (int) filesize($item.'/item') : 0], JSON_THROW_ON_ERROR));
    }

    /** @return list<array<string, mixed>> */
    private function trashItems(string $domain): array
    {
        $items = [];
        foreach (is_dir($this->trashDir($domain)) ? array_diff((array) scandir($this->trashDir($domain)), ['.', '..']) : [] as $id) {
            $meta = json_decode((string) @file_get_contents($this->trashDir($domain).'/'.$id.'/meta.json'), true);
            if (is_array($meta)) {
                $items[] = ['id' => (string) $id] + $meta + ['expires_at' => null];
            }
        }

        return $items;
    }

    private function trashDir(string $domain): string
    {
        return $this->root.'/trash/'.$domain;
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

    private static function copyTree(string $from, string $to): void
    {
        if (is_file($from)) {
            copy($from, $to);

            return;
        }
        mkdir($to, 0o775, true);
        foreach (array_diff((array) scandir($from), ['.', '..']) as $name) {
            self::copyTree($from.'/'.$name, $to.'/'.$name);
        }
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
