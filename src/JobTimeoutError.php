<?php
declare(strict_types=1);
namespace TranscriptDock;

final class JobTimeoutError extends \RuntimeException
{
    public function __construct(public readonly ?array $job)
    {
        parent::__construct('The job is still running. Check it again using its ID.');
    }
}
