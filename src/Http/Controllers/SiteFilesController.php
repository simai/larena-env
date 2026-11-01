<?php

declare(strict_types=1);

namespace Larena\Env\Http\Controllers;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\Translation\Translator;
use Illuminate\Contracts\View\Factory;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Larena\Env\Files\FileModes;
use Larena\Env\Files\FilePath;
use Larena\Env\Files\SiteFiles;
use Larena\Env\Gateway\PanelActor;
use Larena\Env\Gateway\PanelAttachment;
use Larena\Env\Gateway\PanelCapabilities;
use Larena\Env\Gateway\PanelCommands;
use Larena\Env\Gateway\PanelReason;
use Larena\Env\Gateway\PanelRefused;
use Larena\Env\Http\Middleware\ResolvePanelActor;
use Larena\Env\Http\Support\NoticeRedirect;
use Larena\Env\Operations\PanelOperations;
use Larena\Env\Server\ServerOverviewReader;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * A site's files and its own nginx directives (Panel plan 6.1–6.3). Every change goes the panel's one way: the server
 * says what it would do, a person confirms, and only then it runs as a job. A file that is saved or uploaded waits on the
 * panel's disk between the plan and the confirmation, named in the person's session by its sha256, and is sent after
 * the confirmed request; it is removed once sent. A download is checked against the size and sha256 the server named
 * before a byte of it reaches the browser.
 */
final readonly class SiteFilesController
{
    /** The changes of the «Файлы» tab and the gateway command of each. */
    public const array FILE_OPERATIONS = ['write' => 'fs:write', 'upload' => 'fs:upload', 'mkdir' => 'fs:mkdir', 'rename' => 'fs:rename',
        'copy' => 'fs:copy', 'delete' => 'fs:delete', 'restore' => 'fs:restore', 'chmod' => 'fs:chmod', 'archive' => 'fs:archive', 'extract' => 'fs:extract'];
    /** The nginx directives', the system level's only. */
    public const array OVERLAY_OPERATIONS = ['overlay-set' => 'site:nginx-overlay-set', 'overlay-clear' => 'site:nginx-overlay-clear',
        'overlay-confirm' => 'change:confirm'];

    private const string STAGED = 'larena_env_staged';
    private const int STAGED_SECONDS = 3600;

    public function __construct(
        private PanelOperations $operations,
        private ServerOverviewReader $overviews,
        private SiteFiles $files,
        private Factory $views,
        private Translator $translator,
        private Application $app,
    ) {
    }

    /** What the change would do, with a confirmation that does it and nothing else. */
    public function plan(Request $request, string $domain): mixed
    {
        $actor = ResolvePanelActor::of($request);
        $op = (string) $request->input('op');
        $command = $this->offered($actor, $op);
        $tab = isset(self::OVERLAY_OPERATIONS[$op]) ? 'nginx' : 'files';
        if ($command === null) {
            return $this->back($request, $domain, $tab, PanelReason::NOT_ALLOWED);
        }
        $args = $this->arguments($request, $op, $domain);
        if ($args === null) {
            return $this->back($request, $domain, $tab, 'path_invalid');
        }
        $staged = null;
        if (PanelCommands::attachmentLimit($command) !== null) {
            $staged = $this->stage($request, $domain, $command, $op);
            if (is_string($staged)) {
                return $this->back($request, $domain, $tab, $staged);
            }
        }
        $result = $this->operations->planCommand($actor, $command, $args, $staged['attachment'] ?? null);
        if ($result->plan === null) {
            if ($staged !== null) {
                $this->unstage($request, $staged['id']);
            }

            return $this->back($request, $domain, $tab, $result->reason ?? PanelReason::ANSWER_SHAPE_INVALID, $args['path'] ?? null);
        }
        $hidden = ['op' => $op];
        foreach ($args as $name => $value) {
            // A list (fs:archive's paths) goes back item by item.
            foreach (is_array($value) ? $value : [null => $value] as $index => $item) {
                $hidden['args['.$name.']'.(is_int($index) ? '['.$index.']' : '')] = $item;
            }
        }
        if ($staged !== null) {
            $hidden['staged'] = $staged['id'];
        }

        return $this->views->make('larena-env::admin.plan', [
            'heading' => (string) $this->translator->get('larena-env::admin.files.operations.'.$op),
            'eyebrow' => $domain,
            'target' => $domain,
            'source' => $result->source,
            'plan' => $result->plan,
            'runUrl' => route($tab === 'nginx' ? 'larena.env.admin.site.nginx.run' : 'larena.env.admin.site.files.run', ['domain' => $domain], false),
            'cancelUrl' => route('larena.env.admin.site', ['domain' => $domain], false).'?tab='.$tab,
            'hidden' => $hidden,
            'replaces' => $this->replaces($op, $args),
        ]);
    }

    /** The confirmation itself: the server starts the change, with its file when it has one, and the screen follows the job. */
    public function run(Request $request, string $domain): RedirectResponse
    {
        $actor = ResolvePanelActor::of($request);
        $op = (string) $request->input('op');
        $command = $this->offered($actor, $op);
        $tab = isset(self::OVERLAY_OPERATIONS[$op]) ? 'nginx' : 'files';
        $args = array_filter((array) $request->input('args', []), static fn (mixed $value, mixed $name): bool => is_string($name)
            && (is_string($value) || ($name === 'paths' && is_array($value) && array_is_list($value) && array_filter($value, 'is_string') === $value)), ARRAY_FILTER_USE_BOTH);
        // Keeping a timed change names the change only (change:confirm); every other change names its site.
        $site = $op === 'overlay-confirm' ? $domain : ($args['domain'] ?? null);
        if ($command === null || $site !== $domain) {
            return $this->back($request, $domain, $tab, PanelReason::NOT_ALLOWED);
        }
        $attachment = null;
        $stagedId = (string) $request->input('staged');
        if (PanelCommands::attachmentLimit($command) !== null) {
            $attachment = $this->staged($request, $stagedId, $domain, $command);
            if ($attachment === null) {
                return $this->back($request, $domain, $tab, 'staged_missing');
            }
        }
        try {
            $started = $this->operations->startCommand($actor, $command, $args, $attachment);
        } finally {
            if ($attachment !== null) {
                $this->unstage($request, $stagedId);
            }
        }
        if ($started->jobId === null) {
            return $this->back($request, $domain, $tab, $started->reason ?? PanelReason::ANSWER_SHAPE_INVALID, $args['path'] ?? $args['from'] ?? null);
        }

        return new RedirectResponse(route('larena.env.admin.job', ['job' => $started->jobId], false));
    }

    /** A site's file, handed to the browser only once its bytes match what the server said it sent. */
    public function download(Request $request, string $domain): mixed
    {
        $actor = ResolvePanelActor::of($request);
        $path = FilePath::clean($request->query('path'));
        if (!$this->capabilities($actor)->allows('fs:download')) {
            return $this->back($request, $domain, 'files', PanelReason::NOT_ALLOWED);
        }
        if ($path === null || $path === '.') {
            return $this->back($request, $domain, 'files', 'path_invalid');
        }
        $folder = $this->app->storagePath('app/larena-env/downloads');
        if (!is_dir($folder) && !@mkdir($folder, 0o700, true) && !is_dir($folder)) {
            return $this->back($request, $domain, 'files', PanelReason::BAD_REQUEST);
        }
        $into = $folder.'/'.bin2hex(random_bytes(16));
        $download = $this->files->download($actor, $domain, $path, $into);
        if (!$download->ok() || $download->name === null) {
            @unlink($into);

            return $this->back($request, $domain, 'files', $download->answer->reason ?? PanelReason::ANSWER_INVALID, FilePath::parent($path));
        }
        $offset = $download->offset;
        $name = preg_replace('/[^\x20-\x7e]|["\\\\]/', '_', $download->name) ?: 'file';

        return new StreamedResponse(static function () use ($into, $offset): void {
            $stream = fopen($into, 'rb');
            if ($stream !== false) {
                fseek($stream, $offset);
                while (!feof($stream)) {
                    echo fread($stream, 1_048_576);
                    flush();
                }
                fclose($stream);
            }
            @unlink($into);
        }, 200, [
            // Never shown in the panel's own origin: a downloaded page or script cannot act as the panel.
            'Content-Type' => 'application/octet-stream',
            'Content-Length' => (string) $download->size,
            'Content-Disposition' => 'attachment; filename="'.$name.'"; filename*=UTF-8\'\''.rawurlencode($download->name),
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'no-store',
        ]);
    }

    /**
     * The gateway arguments of the change, from the form: paths are cleaned, never trusted as typed.
     *
     * @return array<string, string|list<string>>|null null when a path or name is not one
     */
    private function arguments(Request $request, string $op, string $domain): ?array
    {
        $path = FilePath::clean($request->input('path'));
        $folder = FilePath::clean($request->input('folder'));
        $name = self::name($request->input('name'));
        $args = ['domain' => $domain];
        switch ($op) {
            case 'write':
            case 'delete':
                if ($path === null || $path === '.') {
                    return null;
                }
                $args['path'] = $path;
                if ($op === 'delete') {
                    $args['recursive'] = $request->input('recursive') === 'yes' ? 'yes' : 'no';
                }

                return $args;
            case 'upload':
                $file = $request->file('file');
                $uploaded = $file instanceof UploadedFile ? self::name($file->getClientOriginalName()) : null;
                if ($folder === null || $uploaded === null) {
                    return null;
                }

                return $args + ['path' => FilePath::join($folder, $uploaded), 'overwrite' => $request->input('overwrite') === 'yes' ? 'yes' : 'no'];
            case 'mkdir':
                return $folder === null || $name === null ? null : $args + ['path' => FilePath::join($folder, $name)];
            case 'rename':
            case 'copy':
                return $path === null || $path === '.' || $name === null ? null : $args + ['from' => $path, 'to' => FilePath::join(FilePath::parent($path), $name)];
            case 'restore':
                $id = $request->input('id');

                return is_string($id) && preg_match('/\A[A-Za-z0-9][A-Za-z0-9._-]{0,127}\z/D', $id) === 1 ? $args + ['id' => $id] : null;
            case 'chmod':
                $mode = $request->input('mode');
                if ($path === null || $path === '.' || !is_string($mode) || !in_array($mode, [...FileModes::FILE, ...FileModes::DIR], true)) {
                    return null;
                }
                $folderMode = $request->input('kind') === 'dir';
                if (!FileModes::allowed($mode, $folderMode)) {
                    return null;
                }

                return $args + ['path' => $path, 'mode' => $mode, 'recursive' => $folderMode && $request->input('recursive') === 'yes' ? 'yes' : 'no'];
            case 'archive':
                $format = $request->input('format') === 'tar.gz' ? 'tar.gz' : 'zip';
                if ($path === null || $path === '.' || $name === null) {
                    return null;
                }
                $archive = str_ends_with($name, '.'.$format) ? $name : $name.'.'.$format;

                return $args + ['paths' => [$path], 'name' => $archive, 'format' => $format];
            case 'extract':
                return $path === null || $path === '.' || $folder === null ? null : $args + ['path' => $path, 'into' => $folder];
            case 'overlay-set':
            case 'overlay-clear':
                return $args;
            case 'overlay-confirm':
                $change = $request->input('change');

                // The shared confirmation of a timed change names the change only; the server knows its site.
                return is_string($change) && preg_match('/\A[A-Za-z0-9][A-Za-z0-9._-]{0,63}\z/D', $change) === 1 ? ['change_id' => $change] : null;
        }

        return null;
    }

    /**
     * Keeps the file a change sends on the panel's disk until it is confirmed: an editor's text, an upload, nginx
     * directives. The session names it with its sha256, so another file cannot be sent in its place.
     *
     * @return array{id: string, attachment: PanelAttachment}|string the staged file, or why it cannot be
     */
    private function stage(Request $request, string $domain, string $command, string $op): array|string
    {
        $folder = $this->app->storagePath('app/larena-env/staged');
        if (!is_dir($folder) && !@mkdir($folder, 0o700, true) && !is_dir($folder)) {
            return PanelReason::BAD_REQUEST;
        }
        $this->pruneStaged($request, $folder);
        $id = bin2hex(random_bytes(16));
        $path = $folder.'/'.$id;
        if ($op === 'upload') {
            $file = $request->file('file');
            if (!$file instanceof UploadedFile || !$file->isValid()) {
                return 'upload_failed';
            }
            $file->move($folder, $id);
        } else {
            // A browser sends a text area's lines ended by CR LF; the file keeps the lines a server's files have.
            $text = str_replace("\r\n", "\n", self::rawText($request, 'content'));
            if (file_put_contents($path, $text) === false) {
                return PanelReason::BAD_REQUEST;
            }
        }
        try {
            $attachment = PanelAttachment::fromFile($path);
        } catch (PanelRefused $refused) {
            @unlink($path);

            return $refused->reasonCode;
        }
        if ($attachment->size > (int) PanelCommands::attachmentLimit($command)) {
            @unlink($path);

            return PanelReason::TOO_LARGE;
        }
        $staged = (array) $request->session()->get(self::STAGED, []);
        $staged[$id] = ['domain' => $domain, 'command' => $command, 'sha256' => $attachment->sha256, 'at' => time()];
        $request->session()->put(self::STAGED, $staged);

        return ['id' => $id, 'attachment' => $attachment];
    }

    /** The staged file of this person's plan, unchanged since: anything else is not sent. */
    private function staged(Request $request, string $id, string $domain, string $command): ?PanelAttachment
    {
        $entry = ((array) $request->session()->get(self::STAGED, []))[$id] ?? null;
        if (preg_match('/\A[a-f0-9]{32}\z/D', $id) !== 1 || !is_array($entry) || ($entry['domain'] ?? null) !== $domain || ($entry['command'] ?? null) !== $command) {
            return null;
        }
        try {
            $attachment = PanelAttachment::fromFile($this->app->storagePath('app/larena-env/staged/'.$id));
        } catch (PanelRefused) {
            return null;
        }

        return hash_equals((string) ($entry['sha256'] ?? ''), $attachment->sha256) ? $attachment : null;
    }

    private function unstage(Request $request, string $id): void
    {
        if (preg_match('/\A[a-f0-9]{32}\z/D', $id) === 1) {
            @unlink($this->app->storagePath('app/larena-env/staged/'.$id));
        }
        $staged = (array) $request->session()->get(self::STAGED, []);
        unset($staged[$id]);
        $request->session()->put(self::STAGED, $staged);
    }

    /** A plan left unconfirmed for an hour is dropped, with its file. */
    private function pruneStaged(Request $request, string $folder): void
    {
        $staged = (array) $request->session()->get(self::STAGED, []);
        foreach ($staged as $id => $entry) {
            if (!is_array($entry) || (int) ($entry['at'] ?? 0) < time() - self::STAGED_SECONDS) {
                @unlink($folder.'/'.$id);
                unset($staged[$id]);
            }
        }
        $request->session()->put(self::STAGED, $staged);
    }

    /** @param array<string, string> $args */
    private function replaces(string $op, array $args): ?string
    {
        $key = match ($op) {
            'write' => 'replaces_write',
            'upload' => $args['overwrite'] === 'yes' ? 'replaces_upload' : null,
            'delete' => 'replaces_delete',
            'overlay-set', 'overlay-clear' => 'replaces_overlay',
            default => null,
        };

        return $key === null ? null : (string) $this->translator->get('larena-env::admin.files.'.$key, ['path' => $args['path'] ?? '']);
    }

    /** The change's command, when this server's gateway has it; the nginx directives only for the system level. */
    private function offered(PanelActor $actor, string $op): ?string
    {
        $command = self::FILE_OPERATIONS[$op] ?? (($actor->owner === null) ? (self::OVERLAY_OPERATIONS[$op] ?? null) : null);

        return $command !== null && $this->capabilities($actor)->allows($command) ? $command : null;
    }

    private function capabilities(PanelActor $actor): PanelCapabilities
    {
        return $this->overviews->read($actor)->overview->capabilities ?? PanelCapabilities::none();
    }

    /**
     * A text field as the browser sent it. The application's middleware trims input and turns an empty one into null,
     * which would take a file's last line end or leading spaces; the form's own body keeps them.
     */
    private static function rawText(Request $request, string $field): string
    {
        if (str_starts_with((string) $request->headers->get('Content-Type'), 'application/x-www-form-urlencoded')) {
            parse_str((string) $request->getContent(), $body);
            if (is_string($body[$field] ?? null)) {
                return $body[$field];
            }
        }

        return (string) $request->input($field, '');
    }

    /** A file or folder's own name: no slash, not `.` or `..`, no control characters. */
    private static function name(mixed $value): ?string
    {
        $name = is_string($value) ? trim($value) : '';

        return $name === '' || $name === '.' || $name === '..' || strlen($name) > 255 || preg_match('/[\/\x00-\x1f\x7f]/', $name) === 1 ? null : $name;
    }

    private function back(Request $request, string $domain, string $tab, string $reason, ?string $path = null): RedirectResponse
    {
        $key = 'larena-env::admin.files.reasons.'.$reason;
        $line = (string) $this->translator->get($key);
        if ($line === $key) {
            $key = 'larena-env::admin.reasons.'.$reason;
            $line = (string) $this->translator->get($key);
            $line = $line !== $key ? $line : (string) $this->translator->get('larena-env::admin.reasons.unknown', ['reason' => $reason]);
        }
        $folder = $path === null ? '' : '&path='.rawurlencode(FilePath::parent($path));

        return NoticeRedirect::to($request, route('larena.env.admin.site', ['domain' => $domain], false).'?tab='.$tab.($tab === 'files' ? $folder : ''), $line);
    }
}
