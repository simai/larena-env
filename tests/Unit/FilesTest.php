<?php

declare(strict_types=1);

// A site's files (simai-env 1.39.0–1.40.0): the command table's paths, an upload after the request line, a download read
// back and checked before it is served, and the stand-in for the bytes and folders the recordings do not hold.

use Larena\Core\Process\ProcessResult;
use Larena\Core\Process\ProcessSpecification;
use Larena\Core\Process\ProcOpenProcessExecutor;
use Larena\Core\Process\RecordingProcessExecutor;
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
    env_assert($refusedWith(static fn () => new PanelRequest('r1', $admin, 'file:list', ['domain' => 'demo-php.test', 'path' => $good])) === null, "A path inside the site: {$good}");
}
foreach (['/etc', '..', '../x', 'a/../b', 'a/..', "a\nb", ''] as $bad) {
    env_assert($refusedWith(static fn () => new PanelRequest('r1', $admin, 'file:list', ['domain' => 'demo-php.test', 'path' => $bad])) === 'invalid_argument', 'Refused: '.json_encode($bad));
}
env_assert(FilePath::clean('/public//a/./b/') === 'public/a/b' && FilePath::clean('a/../b') === null && FilePath::clean('') === '.' && FilePath::clean("a\0") === null, 'A path from a form is cleaned.');
env_assert(FilePath::crumbs('a/b') === [['name' => 'a', 'path' => 'a'], ['name' => 'b', 'path' => 'a/b']] && FilePath::parent('a/b') === 'a' && FilePath::parent('a') === '.', 'Crumbs and parents.');
// The file commands from simai-env 1.39.0; an upload and archives from 1.40.0.
env_assert((new PanelCapabilities('1.39.0'))->allows('file:list') && !(new PanelCapabilities('1.38.0'))->allows('file:list')
    && !(new PanelCapabilities('1.39.0'))->allows('file:upload') && (new PanelCapabilities('1.40.0'))->allows('file:extract'), 'Offered by the releases that have them.');

// An upload: the plan names its file, the confirmation sends it after the line, nothing else carries one.
$directory = env_temp_directory();
file_put_contents($directory.'/page.html', "<h1>new</h1>\n");
$attachment = PanelAttachment::fromFile($directory.'/page.html');
$plan = PanelRequest::plan($admin, 'file:upload', ['domain' => 'demo-php.test', 'path' => 'public/page.html'], $attachment);
env_assert($plan->toDocument()['attachment'] === ['sha256' => hash('sha256', "<h1>new</h1>\n"), 'size' => 13] && !$plan->sendsBytes() && $plan->stdin() === $plan->toLine(), 'A plan names the file and sends no bytes.');
$confirmed = PanelRequest::confirmed($admin, 'file:upload', ['domain' => 'demo-php.test', 'path' => 'public/page.html'], $attachment);
$stdin = $confirmed->stdin();
env_assert(is_resource($stdin) && stream_get_contents($stdin) === $confirmed->toLine()."<h1>new</h1>\n", 'A confirmed upload sends the line, then exactly the file.');
env_assert($refusedWith(static fn () => PanelRequest::confirmed($admin, 'file:upload', ['domain' => 'demo-php.test', 'path' => 'a'])) === 'bad_request', 'An upload without its file is refused.');
env_assert($refusedWith(static fn () => PanelRequest::confirmed($admin, 'file:write', ['domain' => 'demo-php.test', 'path' => 'a', 'content' => 'x'], $attachment)) === 'bad_request', 'A saved text travels in the line, never as a file.');
env_assert(PanelCommands::attachmentLimit('file:upload') === 1_073_741_824 && PanelCommands::attachmentLimit('file:write') === null, 'An upload up to 1 GB.');
env_assert(SiteFiles::savable(str_repeat('a', 60_000)) && !SiteFiles::savable(str_repeat("\n", 40_000)) && !SiteFiles::savable(str_repeat('a', 61_440)), 'A text is saved from the panel while the line holds it.');

// The real executor with a stand-in for sudo: the line and the file reach the gateway's stdin whole.
$standIn = $directory.'/sudo';
file_put_contents($standIn, "#!/bin/sh\n/bin/cat > ".escapeshellarg($directory.'/stdin')."\necho '{\"schema\":\"simai-panel-answer/1\",\"request_id\":null,\"status\":\"refused\",\"reason\":\"not_allowed\"}'\n");
chmod($standIn, 0700);
$sent = PanelRequest::confirmed($admin, 'file:upload', ['domain' => 'demo-php.test', 'path' => 'public/page.html'], $attachment);
(new SudoGateway(new SudoGatewayTarget($standIn, '/usr/local/sbin/simai-panel-gate'), new ProcOpenProcessExecutor()))->ask($sent);
env_assert(file_get_contents($directory.'/stdin') === $sent->toLine()."<h1>new</h1>\n", 'The real executor streams the line and the file.');

// A download: the answer's line of the `download` kind, then exactly its bytes, checked before anything is served.
$frame = static function (string $id, string $bytes, ?int $size = null, string $kind = 'download'): string {
    return json_encode(['schema' => 'simai-panel-answer/1', 'request_id' => $id, 'status' => 'ok', 'kind' => $kind,
        'data' => ['path' => 'public/../x.txt', 'size' => $size ?? strlen($bytes), 'mtime' => 1767225600]], JSON_THROW_ON_ERROR)."\n".$bytes;
};
$read = static function (string $written) use ($directory): PanelDownload {
    file_put_contents($directory.'/download', $written);

    return PanelDownload::read('d1', $directory.'/download');
};
$good = $read($frame('d1', "hello\n"));
env_assert($good->ok() && $good->size === 6 && $good->name === 'x.txt' && file_get_contents($directory.'/download', false, null, $good->offset) === "hello\n", 'A file that matches: its bytes after the line, a plain name.');
env_assert($read($frame('d1', "hel", 6))->answer->reason === 'download_mismatch', 'A short read, the file changed meanwhile: refused.');
env_assert($read($frame('d1', "hello\nmore", 6))->answer->reason === 'download_mismatch', 'Extra bytes: refused.');
env_assert($read($frame('d1', "hello\n", null, 'result'))->answer->reason === 'answer_shape_invalid', 'Another kind of answer is no download.');
env_assert($read($frame('d1', '', PanelCommands::DOWNLOAD_MAX_BYTES + 1))->answer->reason === 'too_large', 'Over 1 GB: refused.');
env_assert($read($frame('d2', "hello\n"))->answer->reason === 'gateway_answer_invalid', 'Another request\'s answer: refused.');
$refusal = $read('{"schema":"simai-panel-answer/1","request_id":"d1","status":"refused","reason":"not_a_file"}'."\n");
env_assert(!$refusal->ok() && $refusal->answer->status === PanelAnswerStatus::Refused && $refusal->answer->reason === 'not_a_file', 'A refusal is the line alone.');

// Through a driver: the output goes to the file, never into memory; a cut stream is refused.
$downloading = static function (string $output) use ($admin, $directory): PanelDownload {
    $executor = RecordingProcessExecutor::answering(static function (ProcessSpecification $spec) use ($output): ProcessResult {
        fwrite($spec->stdoutSink, $output);

        return ProcessResult::finished(0, '');
    });
    $request = PanelRequest::read($admin, 'file:download', ['domain' => 'demo-php.test', 'path' => 'notes.txt']);
    $download = (new SudoGateway(new SudoGatewayTarget(), $executor))->download($request, $directory.'/through');
    $spec = $executor->recorded()[0];
    env_assert($spec->captureStdout === false && $spec->stdoutSink !== null && $spec->stdoutLimitBytes > PanelCommands::DOWNLOAD_MAX_BYTES, 'Streamed to a file, with room for 1 GB.');

    return $download;
};
$executorAnswer = static function (string $bytes) use ($admin, $directory, $frame): PanelDownload {
    $executor = RecordingProcessExecutor::answering(static function (ProcessSpecification $spec) use ($bytes, $frame): ProcessResult {
        $line = json_decode((string) $spec->stdin, true);
        fwrite($spec->stdoutSink, $frame((string) $line['request_id'], $bytes));

        return ProcessResult::finished(0, '');
    });

    return (new SudoGateway(new SudoGatewayTarget(), $executor))->download(PanelRequest::read($admin, 'file:download', ['domain' => 'demo-php.test', 'path' => 'notes.txt']), $directory.'/through');
};
env_assert($executorAnswer("notes\n")->ok(), 'A driver\'s download is read back and checked.');
env_assert($downloading('not json')->answer->reason === 'gateway_answer_invalid', 'An unreadable answer is no file.');
env_assert((new SudoGateway(new SudoGatewayTarget(), new RecordingProcessExecutor()))->ask(PanelRequest::read($admin, 'file:download', ['domain' => 'demo-php.test', 'path' => 'a']))->reason === 'not_allowed', 'A download is never asked the usual way.');

// The recordings (set 16): an owner's folder, as the server lists it.
$recorded = new SiteFiles(new RecordedAnswersGateway(env_recorded_path()));
$own = $recorded->folder($owner, 'demo-owned.test', '.');
env_assert(is_array($own) && $own['entries'][0]->name === 'public' && $own['entries'][0]->isDir() && $own['entries'][0]->mode === '775'
    && $own['entries'][0]->mtime === gmdate('Y-m-d\TH:i:s\Z', 1793871093), 'The recorded folder (simai-files/1).');
env_assert($recorded->folder($owner, 'demo-php.test', '.') === 'owner_scope', 'Another owner\'s site is refused.');

// The stand-in on recorded answers: it lists the file commands and works inside a site's folder only.
$root = $directory.'/fake';
$gateway = PanelGatewayFactory::make(['driver' => 'recorded', 'recorded' => ['path' => env_recorded_path(), 'files' => $root]], true, static fn () => new RecordingProcessExecutor());
env_assert($gateway instanceof FakeFilesGateway, 'The stand-in only when its folder is configured.');
env_assert(PanelGatewayFactory::make(['driver' => 'recorded', 'recorded' => ['path' => env_recorded_path(), 'files' => '']], true, static fn () => new RecordingProcessExecutor()) instanceof RecordedAnswersGateway, 'Without it, the recorded answers alone.');
$described = $gateway->ask(PanelRequest::read($admin, 'self:describe'));
env_assert(in_array('file:list', $described->data['gate']['commands'], true), 'The host description lists the file commands.');
$files = new SiteFiles($gateway);
$listed = $files->folder($admin, 'demo-php.test', '.');
env_assert(is_array($listed) && $listed['entries'][0]->name === 'public' && $listed['entries'][0]->isDir(), 'A site\'s folder, folders first.');
env_assert($files->folder($admin, 'demo-php.test', 'public/../../x') === 'invalid_argument', 'A path out of the site is refused by the panel.');
symlink('/etc', $root.'/sites/demo-php.test/escape');
env_assert($files->folder($admin, 'demo-php.test', 'escape') === 'path_outside', 'A link that leaves the site is refused.');
unlink($root.'/sites/demo-php.test/escape');
$notes = $files->text($admin, 'demo-php.test', 'notes.txt');
env_assert(is_array($notes) && $notes['content'] === "Notes of demo-php.test\n" && $notes['sha256'] === hash('sha256', "Notes of demo-php.test\n") && $notes['savable'], 'A text file is read with its sha256.');
file_put_contents($root.'/sites/demo-php.test/logo.png', "\x89PNG\0\0");
env_assert($files->text($admin, 'demo-php.test', 'logo.png') === 'not_text', 'Not text: refused, downloaded instead.');

$operations = new PanelOperations($gateway);
$save = ['domain' => 'demo-php.test', 'path' => 'notes.txt', 'content' => "<h1>new</h1>\n", 'expect-sha256' => $notes['sha256']];
$planned = $operations->planCommand($admin, 'file:write', $save);
env_assert($planned->plan !== null && $planned->plan->command === 'file:write' && $planned->plan->args['content'] === '(13 bytes)', 'A save is planned first; the plan names the text\'s size only.');
$job = $operations->startCommand($admin, 'file:write', $save);
env_assert($job->jobId !== null && $files->text($admin, 'demo-php.test', 'notes.txt')['content'] === "<h1>new</h1>\n", 'Confirmed, the file is saved.');
$view = $operations->view($admin, (string) $job->jobId);
env_assert($view->status !== null && $view->log !== null, 'Its job is followed like any other.');
env_assert($operations->planCommand($admin, 'file:write', $save)->reason === 'changed', 'Saved again over a newer text: refused changed, never overwritten.');
env_assert($operations->planCommand($admin, 'file:write', ['domain' => 'demo-php.test', 'path' => 'notes.txt', 'content' => '', 'expect-sha256' => 'new'])->reason === 'exists', 'A new file where one is: refused exists.');
env_assert($operations->planCommand($admin, 'file:mkdir', ['domain' => 'demo-php.test', 'path' => 'public'])->reason === 'exists', 'Something of that name: refused exists.');
$operations->startCommand($admin, 'file:rename', ['domain' => 'demo-php.test', 'path' => 'public', 'to' => 'public-new']);
env_assert(is_array($files->folder($admin, 'demo-php.test', 'public-new')) && !is_array($files->folder($admin, 'demo-php.test', 'public')), 'Renamed.');
env_assert($operations->planCommand($admin, 'file:remove', ['domain' => 'demo-php.test', 'path' => '.'])->reason === 'bad_path', 'The site\'s folder itself is never removed.');
$operations->startCommand($admin, 'file:remove', ['domain' => 'demo-php.test', 'path' => 'public-new']);
env_assert($files->folder($admin, 'demo-php.test', 'public-new') === 'not_found', 'Removed for good, with what it held.');
env_assert($operations->planCommand($admin, 'file:upload', ['domain' => 'demo-php.test', 'path' => 'notes.txt', 'expect-sha256' => 'new'], $attachment)->reason === 'exists', 'An upload never replaces a file unasked.');
$uploaded = $operations->startCommand($admin, 'file:upload', ['domain' => 'demo-php.test', 'path' => 'page.html', 'expect-sha256' => 'new'], $attachment);
env_assert($uploaded->jobId !== null && $files->text($admin, 'demo-php.test', 'page.html')['content'] === "<h1>new</h1>\n", 'An upload lands where it was asked.');
env_assert($operations->planCommand($admin, 'file:archive', ['domain' => 'demo-php.test', 'path' => 'notes.txt', 'to' => 'notes.zip'])->plan?->command === 'file:archive', 'Packing is planned first.');
$saved = $files->download($admin, 'demo-php.test', 'notes.txt', $directory.'/served');
env_assert($saved->ok() && $saved->name === 'notes.txt' && file_get_contents($directory.'/served', false, null, $saved->offset) === "<h1>new</h1>\n", 'A download through the stand-in, checked.');
env_assert($files->download($admin, 'demo-php.test', '.', $directory.'/served2')->answer->reason === 'not_a_file', 'A folder is not downloaded.');

// The owner: their own site only, and never the nginx directives.
env_assert($files->folder($owner, 'demo-php.test', '.') === 'owner_scope', 'Another owner\'s site is refused.');
env_assert(is_array($files->folder($owner, 'demo-owned.test', '.')), 'Their own site\'s files.');

// A site's own nginx directives (simai-env 1.41.0, set 17): the system level's, a timed change kept by change:confirm.
$nginx = $recorded->nginxCustom($admin, 'demo-php.test');
env_assert(is_array($nginx) && $nginx['content'] === '' && $nginx['included'] && !$nginx['present'] && $nginx['pending'] === null, 'The recorded directives (simai-nginx-custom/1).');
env_assert($recorded->nginxCustom($owner, 'demo-owned.test') === 'not_allowed', 'The nginx directives are the system level\'s.');
$nginxPlan = (new PanelOperations(new RecordedAnswersGateway(env_recorded_path())))->planCommand($admin, 'site:nginx-custom', ['domain' => 'demo-php.test', 'content' => "add_header X-Test 1;\n"]);
env_assert($nginxPlan->plan?->command === 'site:nginx-custom' && $nginxPlan->plan->args['content'] === '(26 bytes)', 'Directives are planned first; the plan names their size only.');
env_assert(PanelCommands::since('site:nginx-custom') === '1.41.0' && PanelCommands::attachmentLimit('site:nginx-custom') === null, 'From 1.41.0, in the request line.');
$waiting = \Larena\Env\Firewall\TimedChange::fromData(['change_id' => 'c0ffee12', 'expires_at' => '2026-01-01T00:02:00Z', 'kind' => 'nginx_custom']);
env_assert($waiting?->kind === 'nginx_custom' && $waiting->secondsLeft(new DateTimeImmutable('2026-01-01T00:00:30Z')) === 90, 'A change applied, kept within its time.');

echo "FilesTest passed.\n";
