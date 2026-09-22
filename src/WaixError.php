<?php
declare(strict_types=1);
namespace Waix;
final class WaixError extends \RuntimeException {
    public function __construct(string $message, public readonly int $status = 0, public readonly string $errorCode = 'TRANSPORT_ERROR', public readonly ?string $requestId = null, public readonly ?string $retryAfter = null, public readonly mixed $body = null) { parent::__construct($message); }
}
