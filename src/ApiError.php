<?php
declare(strict_types=1);
namespace TranscriptDock;

final class ApiError extends \RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly ?int $status = null,
        public readonly bool $retryable = false,
        public readonly ?string $requestId = null,
        public readonly ?int $retryAfterSeconds = null,
    ) {
        parent::__construct($message);
    }
}
