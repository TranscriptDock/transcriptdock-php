<?php
declare(strict_types=1);
require __DIR__ . '/../src/ApiError.php';
require __DIR__ . '/../src/JobTimeoutError.php';
require __DIR__ . '/../src/Client.php';
use TranscriptDock\Client;
use TranscriptDock\ApiError;
use TranscriptDock\JobTimeoutError;
set_error_handler(function ($severity, $message, $file, $line) { throw new ErrorException($message, 0, $severity, $file, $line); });
function check(bool $value, string $message): void { if (!$value) throw new RuntimeException($message); }
function raises(string $type, callable $f): Throwable {
    try { $f(); } catch (Throwable $e) { if ($e instanceof $type) return $e; throw $e; }
    throw new RuntimeException("Expected $type");
}
$client = new Client('td_live_fixture_only', getenv('TRANSCRIPTDOCK_TEST_BASE_URL'));
$body = ['source' => ['url' => 'https://example.com/video'], 'mode' => 'captions_only', 'max_credits' => 1];
for ($i = 0; $i < 2; $i++) {
    $out = $client->createJob($body, 'fixture-replay-01');
    check($out['future_field'] === 42 && $out['request']['body'] === $body, 'request or future response fields changed');
    check($out['request']['authorized'] && $out['request']['idempotency_key'] === 'fixture-replay-01', 'headers changed');
}
$out = $client->request('GET', '/v1/jobs', query: ['cursor' => 'a/b?c&d', 'limit' => 7]);
check($out['request']['query']['cursor'] === ['a/b?c&d'], 'cursor encoding changed');
check(str_ends_with($client->getJob('id/other?x')['request']['path'], 'id%2Fother%3Fx'), 'ID encoding changed');
check($client->getTranscript('fixture')['words'][0]['start_ms'] === 123, 'timing unit changed');
check(str_contains($client->exportTranscript('fixture', 'srt'), 'Café fixture'), 'UTF-8 export lost');
foreach (['succeeded', 'failed', 'cancelled'] as $s) check($client->waitForJob($s)['status'] === $s, 'terminal status lost');
$e = raises(JobTimeoutError::class, fn() => $client->waitForJob('processing', .15, 1));
check($e->job['status'] === 'processing', 'timeout lost last job');
$e = raises(ApiError::class, fn() => $client->request('GET', '/v1/rate-limit'));
check($e->status === 429 && $e->retryable && $e->retryAfterSeconds === 4 && $e->requestId === 'fixture-request', 'API error fields lost');
check(!str_contains($e->getMessage(), 'td_live_fixture_only'), 'key in error');
check(raises(ApiError::class, fn() => $client->request('GET', '/v1/redirect'))->status === 302, 'redirect followed');
foreach (['gateway', 'error-shape', 'malformed', 'array'] as $p) raises(ApiError::class, fn() => $client->request('GET', '/v1/' . $p));
foreach (['/v1/batches', '/v1/video-discoveries', '/v1/language-discoveries'] as $path) {
    raises(InvalidArgumentException::class, fn() => $client->request('POST', $path, []));
    check($client->request('POST', $path, ['fixture' => true], idempotencyKey: 'fixture-generic-01')['request']['method'] === 'POST', 'generic submission failed');
}
check($client->request('DELETE', '/v1/transcripts/fixture')['request']['method'] === 'DELETE', 'delete dispatch failed');
check($client->request('DELETE', '/v1/no-content') === [], 'no content success not empty');
check($client->request('DELETE', '/v1/no-content', text: true) === '', 'no content text not empty');
check(raises(ApiError::class, fn() => $client->request('GET', '/v1/empty-ok'))->errorCode === 'INVALID_RESPONSE', 'empty 200 accepted');
foreach (['http://example.com', 'https://user:pass@example.com', 'https://example.com/path', 'https://example.com?x=1'] as $url) raises(InvalidArgumentException::class, fn() => new Client('fixture-key', $url));
raises(InvalidArgumentException::class, fn() => new Client("bad\nkey"));
raises(InvalidArgumentException::class, fn() => $client->createJob([], "bad\nreplay"));
raises(InvalidArgumentException::class, fn() => $client->request('GET', '/v1/../secret'));
raises(InvalidArgumentException::class, fn() => $client->waitForJob('fixture', NAN));
check(raises(ApiError::class, fn() => $client->request('GET', '/v1/slow', timeout: .03))->errorCode === 'NETWORK_ERROR', 'transport timeout not handled');
raises(InvalidArgumentException::class, fn() => $client->request('post', '/v1/jobs', []));
raises(InvalidArgumentException::class, fn() => $client->request('GET', '/v1/%2e%2e/secret'));
foreach (['budget' => [402, 'BUDGET_EXCEEDED'], 'conflict' => [409, 'IDEMPOTENCY_CONFLICT'], 'jobs/not-owned' => [404, 'NOT_FOUND']] as $path => $expected) {
    $e = raises(ApiError::class, fn() => $client->request('GET', '/v1/' . $path));
    check([$e->status, $e->errorCode] === $expected && !$e->retryable, 'budget, replay conflict or inaccessible job error lost');
}

echo "PHP client checks passed\n";
