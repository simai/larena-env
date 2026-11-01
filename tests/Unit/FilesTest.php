<?php

declare(strict_types=1);

// A site's files (Panel plan, stage 6): the command table's paths, an attachment after the request line, a download read
// back and checked before it is served, and the stand-in for the file commands the recorded server has no answers for.

use Larena\Core\Process\ProcessResult;
use Larena\Core\Process\ProcessSpecification;
use Larena\Core\Process\ProcOpenProcessExecutor;
use Larena\Core\Process\RecordingProcessExecutor;
use Larena\Env\Files\FileModes;
use Larena\Env\Files\FilePath;
use Larena\Env\Files\SiteFiles;
use Larena\Env\Gateway\FakeFilesGateway;
use Larena\Env\Gateway\PanelActor;
use Larena\Env\Gateway\PanelAnswerStatus;
use Larena\Env\Gateway\PanelAttachment;
use Larena\Env\Gateway\PanelCapabilities;
use Larena\Env\Gateway\PanelCommands;
use Larena\Env\Gateway\PanelDownload;
use Larena\Env\Gateway\PanelGatewayFactory;
use Larena\Env\Gateway\PanelRefused;
use Larena\Env\Gateway\PanelRequest;
use Larena\Env\Gateway\RecordedAnswersGateway;
use Larena\Env\Gateway\SudoGateway;
use Larena\Env\Gateway\SudoGatewayTarget;
use Larena\Env\Operations\PanelOperations;

require_once dirname(__DIR__).'/Support/env-test.php';

$admin = new PanelActor('admin@example.com');
$owner = new PanelActor('staff@example.com', 'demo');
$refusedWith = static function (callable $make): ?string {
    try {
        $make();
    } catch (PanelRefused $refused) {
        return $refused->reasonCode;
    }

    return null;
};

// Paths: from the site's root, never absolute, never `..`; the panel refuses them before the gateway does.
foreach (['.', 'public', 'public/index.html', 'a b/ünï.txt', '.env', '..hidden'] as $good) {
    env_assert($refusedWith(static fn () => new PanelRequest('r1', $admin, 'fs:list', ['domain' => 'demo-php.test', 'path' => $good])) === null, "A path inside the site: {$good}");
}
foreach (['/etc', '..', '../x', 'a/../b', 'a/..', "a\nb", ''] as $bad) {
    env_assert($refusedWith(static fn () => new PanelRequest('r1', $admin, 'fs:list', ['domain' => 'demo-php.test', 'path' => $bad])) === 'invalid_argument', 'Refused: '.json_encode($bad));
}
env_assert(FilePath::clean('/public//a/./b/') === 'public/a/b' && FilePath::clean('a/../b') === null && FilePath::clean('') === '.' && FilePath::clean("a\0") === null, 'A path from a form is cleaned.');
env_assert(FilePath::crumbs('a/b') === [['name' => 'a', 'path' => 'a'], ['name' => 'b', 'path' => 'a/b']] && FilePath::parent('a/b') === 'a' && FilePath::parent('a') === '.', 'Crumbs and parents.');
env_assert(FileModes::allowed('0644', false) && FileModes::allowed('0755', true) && !FileModes::allowed('0666', false) && !FileModes::allowed('0777', true)
    && !FileModes::allowed('4755', true) && !FileModes::allowed('0755', false) && !FileModes::allowed('0300', false), 'Modes within the gateway\'s limits only.');
foreach ([...FileModes::FILE] as $mode) {
    env_assert(FileModes::allowed($mode, false), "Offered for a file: {$mode}");
}
foreach ([...FileModes::DIR] as $mode) {
    env_assert(FileModes::allowed($mode, true), "Offered for a folder: {$mode}");
}
// No release has the file commands yet: they are offered only by a server that lists them.
env_assert(!(new PanelCapabilities('9.9.9'))->allows('fs:list') && (new PanelCapabilities('1.30.0', ['fs:list']))->allows('fs:list'), 'Hidden until a server lists them.');

// An attachment: the plan names it, the confirmation sends it after the line, nothing else carries one.
$directory = env_temp_directory();
file_put_contents($directory.'/page.html', "<h1>new</h1>\n");
$attachment = PanelAttachment::fromFile($directory.'/page.html');
$plan = PanelRequest::plan($admin, 'fs:write', ['domain' => 'demo-php.test', 'path' => 'public/index.html'], $attachment);
env_assert($plan->toDocument()['attachment'] === ['sha256' => hash('sha256', "<h1>new</h1>\n"), 'size' => 13] && !$plan->sendsBytes() && $plan->stdin() === $plan->toLine(), 'A plan names the file and sends no bytes.');
$confirmed = PanelRequest::confirmed($admin, 'fs:write', ['domain' => 'demo-php.test', 'path' => 'public/index.html'], $attachment);
$stdin = $confirmed->stdin();
env_assert(is_resource($stdin) && stream_get_contents($stdin) === $confirmed->toLine()."<h1>new</h1>\n", 'A confirmed save sends the line, then exactly the file.');
env_assert($refusedWith(static fn () => PanelRequest::confirmed($admin, 'fs:write', ['domain' => 'demo-php.test', 'path' => 'a'])) === 'bad_request', 'A save without its file is refused.');
env_assert($refusedWith(static fn () => PanelRequest::confirmed($admin, 'fs:mkdir', ['domain' => 'demo-php.test', 'path' => 'a'], $attachment)) === 'bad_request', 'Only the attachment commands carry one.');
$big = fopen($directory.'/big', 'wb');
ftruncate($big, PanelCommands::attachmentLimit('fs:write') + 1);
fclose($big);
env_assert($refusedWith(static fn () => PanelRequest::plan($admin, 'fs:write', ['domain' => 'demo-php.test', 'path' => 'a'], PanelAttachment::fromFile($directory.'/big'))) === 'too_large', 'An editor save is at most 5 MB.');

// The real executor with a stand-in for sudo: the line and the file reach the gateway's stdin whole.
$standIn = $directory.'/sudo';
file_put_contents($standIn, "#!/bin/sh\n/bin/cat > ".escapeshellarg($directory.'/stdin')."\necho '{\"schema\":\"simai-panel-answer/1\",\"request_id\":null,\"status\":\"refused\",\"reason\":\"not_allowed\"}'\n");
chmod($standIn, 0700);
$sent = PanelRequest::confirmed($admin, 'fs:write', ['domain' => 'demo-php.test', 'path' => 'public/index.html'], $attachment);
(new SudoGateway(new SudoGatewayTarget($standIn, '/usr/local/sbin/simai-panel-gate'), new ProcOpenProcessExecutor()))->ask($sent);
env_assert(file_get_contents($directory.'/stdin') === $sent->toLine()."<h1>new</h1>\n", 'The real executor streams the line and the file.');

// A download: the answer's line, then exactly its bytes, checked before anything is served.
$frame = static function (string $id, string $bytes, ?int $size = null, ?string $sha = null): string {
    return json_encode(['schema' => 'simai-panel-answer/1', 'request_id' => $id, 'status' => 'ok', 'kind' => 'result',
        'data' => ['size' => $size ?? strlen($bytes), 'sha256' => $sha ?? hash('sha256', $bytes), 'name' => '../../etc/x.txt', 'mime' => 'text/plain']], JSON_THROW_ON_ERROR)."\n".$bytes;
};
$read = static function (string $written) use ($directory): PanelDownload {
    file_put_contents($directory.'/download', $written);

    return PanelDownload::read('d1', $directory.'/download');
};
$good = $read($frame('d1', "hello\n"));
env_assert($good->ok() && $good->size === 6 && $good->name === 'x.txt' && file_get_contents($directory.'/download', false, null, $good->offset) === "hello\n", 'A file that matches: its bytes after the line, a plain name.');
env_assert($read($frame('d1', "hello\n", null, str_repeat('0', 64)))->answer->reason === 'download_mismatch', 'Other bytes than named: refused.');
env_assert($read($frame('d1', "hel", 6))->answer->reason === 'download_mismatch', 'A short read: refused.');
env_assert($read($frame('d1', "hello\nmore", 6))->answer->reason === 'download_mismatch', 'Extra bytes: refused.');
env_assert($read($frame('d1', '', PanelCommands::DOWNLOAD_MAX_BYTES + 1))->answer->reason === 'too_large', 'Over 2 GB: refused.');
env_assert($read($frame('d2', "hello\n"))->answer->reason === 'gateway_answer_invalid', 'Another request\'s answer: refused.');
$refusal = $read('{"schema":"simai-panel-answer/1","request_id":"d1","status":"refused","reason":"path_outside"}'."\n");
env_assert(!$refusal->ok() && $refusal->answer->status === PanelAnswerStatus::Refused && $refusal->answer->reason === 'path_outside', 'A refusal is the line alone.');

// Through a driver: the output goes to the file, never into memory; a cut stream is refused.
$downloading = static function (string $output) use ($admin, $directory): PanelDownload {
    $executor = RecordingProcessExecutor::answering(static function (ProcessSpecification $spec) use ($output): ProcessResult {
        fwrite($spec->stdoutSink, $output);

        return ProcessResult::finished(0, '');
    });
    $request = PanelRequest::read($admin, 'fs:download', ['domain' => 'demo-php.test', 'path' => 'notes.txt']);
    $download = (new SudoGateway(new SudoGatewayTarget(), $executor))->download($request, $directory.'/through');
    $spec = $executor->recorded()[0];
    env_assert($spec->captureStdout === false && $spec->stdoutSink !== null && $spec->stdoutLimitBytes > PanelCommands::DOWNLOAD_MAX_BYTES, 'Streamed to a file, with room for 2 GB.');

    return $download;
};
$executorAnswer = static function (string $bytes) use ($admin, $directory, $frame): PanelDownload {
    $executor = RecordingProcessExecutor::answering(static function (ProcessSpecification $spec) use ($bytes, $frame): ProcessResult {
        $line = json_decode((string) $spec->stdin, true);
        fwrite($spec->stdoutSink, $frame((string) $line['request_id'], $bytes));

        return ProcessResult::finished(0, '');
    });

    return (new SudoGateway(new SudoGatewayTarget(), $executor))->download(PanelRequest::read($admin, 'fs:download', ['domain' => 'demo-php.test', 'path' => 'notes.txt']), $directory.'/through');
};
env_assert($executorAnswer("notes\n")->ok(), 'A driver\'s download is read back and checked.');
env_assert($downloading('not json')->answer->reason === 'gateway_answer_invalid', 'An unreadable answer is no file.');
env_assert((new SudoGateway(new SudoGatewayTarget(), new RecordingProcessExecutor()))->ask(PanelRequest::read($admin, 'fs:download', ['domain' => 'demo-php.test', 'path' => 'a']))->reason === 'not_allowed', 'A download is never asked the usual way.');

// The stand-in on recorded answers: it lists the file commands, works inside a site's folder only, keeps the trash.
$root = $directory.'/fake';
$gateway = PanelGatewayFactory::make(['driver' => 'recorded', 'recorded' => ['path' => env_recorded_path(), 'files' => $root]], true, static fn () => new RecordingProcessExecutor());
env_assert($gateway instanceof FakeFilesGateway, 'The stand-in only when its folder is configured.');
env_assert(PanelGatewayFactory::make(['driver' => 'recorded', 'recorded' => ['path' => env_recorded_path(), 'files' => '']], true, static fn () => new RecordingProcessExecutor()) instanceof RecordedAnswersGateway, 'Without it, the recorded answers alone.');
$described = $gateway->ask(PanelRequest::read($admin, 'self:describe'));
env_assert(in_array('fs:list', $described->data['gate']['commands'], true) && in_array('site:nginx-overlay-set', $described->data['gate']['commands'], true), 'The host description lists the file commands.');
$ownDescribed = $gateway->ask(PanelRequest::read($owner, 'self:describe'));
env_assert(!is_array($ownDescribed->data['gate']['commands'] ?? null) || !in_array('site:nginx-overlay-set', $ownDescribed->data['gate']['commands'], true), 'An owner is never offered the nginx directives.');
$files = new SiteFiles($gateway);
$listed = $files->folder($admin, 'demo-php.test', '.');
env_assert(is_array($listed) && $listed['entries'][0]->name === 'public' && $listed['entries'][0]->isDir(), 'A site\'s folder, folders first.');
env_assert($files->folder($admin, 'demo-php.test', 'public/../../x') === 'invalid_argument', 'A path out of the site is refused by the panel.');
symlink('/etc', $root.'/sites/demo-php.test/escape');
env_assert($files->folder($admin, 'demo-php.test', 'escape') === 'path_outside', 'A link that leaves the site is refused path_outside.');
unlink($root.'/sites/demo-php.test/escape');
env_assert($files->text($admin, 'demo-php.test', 'notes.txt')['content'] === "Notes of demo-php.test\n", 'A text file is read.');

$operations = new PanelOperations($gateway);
$planned = $operations->planCommand($admin, 'fs:write', ['domain' => 'demo-php.test', 'path' => 'notes.txt'], $attachment);
env_assert($planned->plan !== null && $planned->plan->command === 'fs:write', 'A save is planned first.');
$job = $operations->startCommand($admin, 'fs:write', ['domain' => 'demo-php.test', 'path' => 'notes.txt'], $attachment);
env_assert($job->jobId !== null && $files->text($admin, 'demo-php.test', 'notes.txt')['content'] === "<h1>new</h1>\n", 'Confirmed, the file is saved.');
$view = $operations->view($admin, (string) $job->jobId);
env_assert($view->status !== null && $view->log !== null, 'Its job is followed like any other.');
$trash = $files->trash($admin, 'demo-php.test');
env_assert(is_array($trash) && count($trash) === 1 && str_starts_with($trash[0]->path, 'notes.txt.bak-'), 'The previous version is in the trash.');
env_assert($operations->planCommand($admin, 'fs:mkdir', ['domain' => 'demo-php.test', 'path' => 'public'])->reason === 'exists', 'Something of that name: refused exists.');
$operations->startCommand($admin, 'fs:delete', ['domain' => 'demo-php.test', 'path' => 'public', 'recursive' => 'yes']);
$deleted = $files->trash($admin, 'demo-php.test');
$publicItem = array_values(array_filter($deleted, static fn ($item): bool => $item->path === 'public'))[0];
env_assert(!is_array($files->folder($admin, 'demo-php.test', 'public')), 'Deleted: gone from the site.');
$operations->startCommand($admin, 'fs:mkdir', ['domain' => 'demo-php.test', 'path' => 'public']);
env_assert($operations->planCommand($admin, 'fs:restore', ['domain' => 'demo-php.test', 'id' => $publicItem->id])->reason === 'exists', 'Its place reused: restoring is refused exists.');
$operations->startCommand($admin, 'fs:rename', ['domain' => 'demo-php.test', 'from' => 'public', 'to' => 'public-new']);
$operations->startCommand($admin, 'fs:restore', ['domain' => 'demo-php.test', 'id' => $publicItem->id]);
env_assert(is_array($files->text($admin, 'demo-php.test', 'public/index.html')), 'Restored where it was.');
env_assert($operations->planCommand($admin, 'fs:chmod', ['domain' => 'demo-php.test', 'path' => 'notes.txt', 'mode' => '0666'])->reason === 'invalid_argument', 'World-writable: refused.');
$saved = $files->download($admin, 'demo-php.test', 'notes.txt', $directory.'/served');
env_assert($saved->ok() && file_get_contents($directory.'/served', false, null, $saved->offset) === "<h1>new</h1>\n", 'A download through the stand-in, checked.');

// The owner: their own site only, and never the nginx directives.
env_assert($files->folder($owner, 'demo-php.test', '.') === 'owner_scope', 'Another owner\'s site is refused.');
env_assert(is_array($files->folder($owner, 'demo-owned.test', '.')), 'Their own site\'s files.');
env_assert($files->overlay($owner, 'demo-owned.test') === 'owner_admin_only', 'The nginx directives are the system level\'s.');
$overlay = new PanelOperations($gateway);
file_put_contents($directory.'/overlay.conf', "add_header X-Test 1;\n");
$set = $overlay->startCommand($admin, 'site:nginx-overlay-set', ['domain' => 'demo-php.test'], PanelAttachment::fromFile($directory.'/overlay.conf'));
$current = $files->overlay($admin, 'demo-php.test');
env_assert($set->jobId !== null && is_array($current) && $current['content'] === "add_header X-Test 1;\n" && $current['pending'] !== null
    && is_string($current['expires']), 'Directives applied, waiting to be kept until a time.');
// One confirmation for every timed change (simai-env): it names the change only.
$kept = $overlay->startCommand($admin, 'change:confirm', ['change_id' => (string) $current['pending']]);
env_assert($kept->jobId !== null && $files->overlay($admin, 'demo-php.test')['pending'] === null, 'Kept once confirmed.');
env_assert($overlay->startCommand($admin, 'change:confirm', ['change_id' => (string) $current['pending']])->reason === 'unknown_change', 'A change kept already is unknown.');

echo "FilesTest passed.\n";
