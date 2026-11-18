# Transcript Dock for PHP

A synchronous API client for PHP 8.1 or newer. Requires the PHP cURL and JSON
extensions, with no third party runtime packages. Version 0.1.0 is available on
[Packagist](https://packagist.org/packages/transcriptdock/transcriptdock).

Install with Composer:

```sh
composer require transcriptdock/transcriptdock:0.1.0
```

```php
require 'vendor/autoload.php';

$client = new TranscriptDock\Client(getenv('TRANSCRIPTDOCK_API_KEY'));
// Generate once per intended operation and save with the returned job ID.
$key = bin2hex(random_bytes(16));
$job = $client->createJob([
    'source' => ['url' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ'],
    'mode' => 'captions_only', 'max_credits' => 1,
], $key);
$job = $client->waitForJob($job['id'], timeout: 120);
if ($job['status'] === 'succeeded') {
    echo $client->exportTranscript($job['result_id'], 'srt');
}
```

Composer autoloading maps `TranscriptDock\\` to `src/`. During local
development, a Composer `path` repository can point to this directory.

Creating a job can spend credits. The example requests captions only with a
one credit ceiling and does not enable AI fallback. Save the key and reuse the
same key and exact body if the submission response is lost. The client does
not retry automatically.

Core methods: `capabilities`, `createJob`, `getJob`, `getTranscript`,
`exportTranscript` and `waitForJob`. Use
`request($method, $path, $body, $query, $idempotencyKey)` for the complete public
API, including batches, discovery, upload slots, usage and webhook endpoints.
Bodies use the [API contract's exact fields](https://www.transcriptdock.com/docs/openapi.yaml).
A success with no body (204 No Content, such as deleting a webhook endpoint)
returns an empty array, or an empty string in text mode.

Responses are associative arrays preserving all API fields and timing units.
Exports are raw JSON, TXT, SRT or VTT strings. Inspect status before using a
terminal job's result. Polling returns failed and cancelled jobs normally;
`JobTimeoutError::$job` retains the last observed response when polling expires.
`ApiError` exposes `errorCode`, `status`, `retryable`, `requestId` and
`retryAfterSeconds`. Error messages redact bearer keys and hide gateway HTML.

The default request timeout is 30 seconds. Custom constructor origins require
HTTPS, except loopback HTTP development. Redirects are blocked. Signed storage
uploads must be sent separately without the API key. Keep webhook secrets
private and implement receiver signature checks from the API documentation.

## Check and build

From the application repository root with Python 3 and PHP installed:

```sh
python3 .ci/check.py
composer validate --strict
composer install
composer archive --format=zip --dir=dist --file=transcriptdock-0.1.0
```

The shared fixture server is local and spends no credits. `composer.lock`
records only platform requirements because there are no third party packages.
Source and release checks: https://github.com/TranscriptDock/transcriptdock-php.
