<?php
declare(strict_types=1);
namespace Waix;
final class WaixError extends \RuntimeException implements \JsonSerializable {
    public function __construct(string $message, public readonly int $status = 0, public readonly string $errorCode = 'TRANSPORT_ERROR', public readonly ?string $requestId = null, public readonly ?string $retryAfter = null, public readonly mixed $body = null) { parent::__construct($message); }
    /** Only metadata is serialized. The raw response body may contain personal data. */
    public function jsonSerialize(): array {
        return ['status'=>$this->status, 'code'=>$this->errorCode, 'request_id'=>$this->requestId, 'retry_after'=>$this->retryAfter];
    }
    public function retryDelayMs(?int $now = null): ?int {
        if ($this->retryAfter === null || $this->retryAfter === '') return null;
        if (ctype_digit($this->retryAfter)) return (int)$this->retryAfter * 1000;
        $date = strtotime($this->retryAfter);
        return $date === false ? null : max(0, $date - ($now ?? time())) * 1000;
    }
}
