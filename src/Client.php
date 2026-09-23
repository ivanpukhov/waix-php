<?php
declare(strict_types=1);
namespace Waix;

final class Client {
    public const VERSION = '0.2.0';
    private int $maxResponseBytes;
    private string $apiKey;
    private string $baseUrl;
    private int $timeoutMs;
    private ?\Closure $transport;
    public readonly Messages $messages;
    public readonly Connections $connections;
    public readonly Templates $templates;
    public readonly Otp $otp;
    public readonly Webhooks $webhook;
    public readonly Media $media;

    public function __construct(string $apiKey, string $baseUrl = 'https://waix.kz/api/v1', int $timeoutMs = 30000, ?callable $transport = null, int $maxResponseBytes = 2097152) {
        if (trim($apiKey) === '' || strpbrk($apiKey, "\r\n") !== false) throw new \InvalidArgumentException('A server-side WAIX API key is required');
        $u = parse_url($baseUrl);
        if (!$u || empty($u['host']) || isset($u['user']) || isset($u['pass']) || isset($u['query']) || isset($u['fragment']) || rtrim($u['path'] ?? '', '/') !== '/api/v1' || !(($u['scheme'] ?? '') === 'https' || ($u['scheme'] ?? '') === 'http' && in_array($u['host'], ['localhost','127.0.0.1','[::1]'], true))) throw new \InvalidArgumentException('baseUrl must be an HTTPS /api/v1 URL (HTTP allowed only on localhost)');
        if ($timeoutMs < 1 || $timeoutMs > 300000) throw new \InvalidArgumentException('timeoutMs must be 1–300000');
        if ($maxResponseBytes < 1024 || $maxResponseBytes > 16777216) throw new \InvalidArgumentException('maxResponseBytes must be 1024–16777216 bytes');
        $this->maxResponseBytes = $maxResponseBytes;
        $this->apiKey = $apiKey; $this->baseUrl = rtrim($baseUrl, '/'); $this->timeoutMs = $timeoutMs;
        $this->transport = $transport === null ? null : \Closure::fromCallable($transport);
        $this->messages = new Messages($this); $this->connections = new Connections($this); $this->templates = new Templates($this);
        $this->otp = new Otp($this); $this->webhook = new Webhooks($this); $this->media = new Media($this);
    }
    public static function key(string $key, bool $uuid = false): string {
        $pattern = $uuid ? '/^[a-f0-9]{8}-[a-f0-9]{4}-[1-8][a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/iD' : '/^[A-Za-z0-9_.:-]{8,128}$/D';
        if (!preg_match($pattern, $key)) throw new \InvalidArgumentException($uuid ? 'A stable UUID Idempotency-Key is required' : 'Invalid Idempotency-Key');
        return $key;
    }
    public static function part(string $id): string { if ($id === '') throw new \InvalidArgumentException('A non-empty identifier is required'); return rawurlencode($id); }

    /** Returns the complete API envelope, including pagination. Never retries automatically. */
    public function request(string $method, string $path, mixed $body = null, array $query = [], ?string $idempotencyKey = null, bool $multipart = false): mixed {
        if (!preg_match('~^/(?!/)[a-zA-Z0-9_/%.-]+$~D', $path) || str_contains($path, '..') || preg_match('/%2f|%5c|%2e/i', $path)) throw new \InvalidArgumentException('Use a relative API endpoint path');
        if (!in_array($method, ['GET','POST','PUT','PATCH','DELETE'], true)) throw new \InvalidArgumentException('Unsupported HTTP method');
        if ($method === 'GET' && $body !== null) throw new \InvalidArgumentException('GET cannot have a body');
        $url = $this->baseUrl . $path;
        $query = array_filter($query, static fn($v) => $v !== null);
        foreach ($query as $k => $v) {
            if (!is_scalar($v) || is_float($v) && !is_finite($v)) throw new \InvalidArgumentException('Query values must be strings, finite numbers or booleans');
            if (is_bool($v)) $query[$k] = $v ? 'true' : 'false';
        }
        if ($query) $url .= '?'  . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
        $headers = ['Authorization: Bearer ' . $this->apiKey, 'Accept: application/json', 'User-Agent: waix-php/' . self::VERSION];
        if ($idempotencyKey !== null) $headers[] = 'Idempotency-Key: ' . self::key($idempotencyKey);
        $data = $body;
        if ($body !== null && !$multipart) { $headers[] = 'Content-Type: application/json'; $data = json_encode($body, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE); }
        $response = $this->transport ? ($this->transport)($method, $url, $headers, $data, $this->timeoutMs) : $this->curl($method, $url, $headers, $data);
        $status = $response['status']; $h = array_change_key_case($response['headers'], CASE_LOWER);
        $requestId = $h['x-request-id'] ?? null; $retryAfter = $h['retry-after'] ?? null;
        if (strlen($response['body']) > $this->maxResponseBytes) throw new WaixError('WAIX response exceeds maxResponseBytes', $status, 'RESPONSE_TOO_LARGE', $requestId, $retryAfter);
        $result = null;
        try { $result = $response['body'] === '' ? null : json_decode($response['body'], true, 512, JSON_THROW_ON_ERROR); }
        catch (\JsonException $e) { /* HTTP errors remain HTTP errors even when a proxy sends HTML. */ }
        if ($status < 200 || $status >= 300) {
            $v = is_array($result) ? $result : [];
            $code = $status >= 300 && $status < 400 ? 'REDIRECT_DISALLOWED' : (is_string($v['code'] ?? null) ? $v['code'] : 'API_ERROR');
            throw new WaixError(is_string($v['error'] ?? null) ? $v['error'] : 'WAIX HTTP ' . $status, $status, $code, is_string($v['request_id'] ?? null) ? $v['request_id'] : $requestId, $retryAfter, $result);
        }
        if ($status === 204 && $response['body'] === '') return null;
        if (!is_array($result) || !str_starts_with(ltrim($response['body']), '{')) throw new WaixError('WAIX returned an invalid JSON object', $status, 'INVALID_RESPONSE', $requestId, $retryAfter);
        return $result;
    }
    private function curl(string $method, string $url, array $headers, mixed $body): array {
        if (!function_exists('curl_init')) throw new \LogicException('The PHP cURL extension is required');
        $ch = curl_init($url); $responseHeaders = []; $raw = ''; $tooLarge = false;
        $maxResponseBytes = $this->maxResponseBytes;
        curl_setopt_array($ch, [CURLOPT_CUSTOMREQUEST=>$method, CURLOPT_HTTPHEADER=>$headers, CURLOPT_WRITEFUNCTION=>static function($curl, string $chunk) use (&$raw, &$tooLarge, $maxResponseBytes): int {
            if (strlen($raw) + strlen($chunk) > $maxResponseBytes) { $tooLarge = true; return 0; }
            $raw .= $chunk;
            return strlen($chunk);
        }, CURLOPT_FOLLOWLOCATION=>false, CURLOPT_CONNECTTIMEOUT_MS=>min(10000,$this->timeoutMs), CURLOPT_TIMEOUT_MS=>$this->timeoutMs, CURLOPT_SSL_VERIFYPEER=>true, CURLOPT_SSL_VERIFYHOST=>2, CURLOPT_HEADERFUNCTION=>static function($curl,string $line) use (&$responseHeaders): int {
            if (str_starts_with($line, 'HTTP/')) $responseHeaders = [];
            elseif (str_contains($line, ':')) { [$key,$value] = explode(':',$line,2); $responseHeaders[strtolower(trim($key))] = trim($value); }
            return strlen($line);
        }]);
        if ($body !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        $ok = curl_exec($ch); $status = curl_getinfo($ch, CURLINFO_RESPONSE_CODE); $errno = curl_errno($ch); unset($ch);
        if ($tooLarge) throw new WaixError('WAIX response exceeds maxResponseBytes', $status, 'RESPONSE_TOO_LARGE', $responseHeaders['x-request-id'] ?? null, $responseHeaders['retry-after'] ?? null);
        if ($ok === false) throw new WaixError('WAIX response was not received completely. Delivery outcome may be unknown; reuse the same idempotency key.', 0, $errno === CURLE_OPERATION_TIMEDOUT ? 'TIMEOUT' : 'TRANSPORT_ERROR', $responseHeaders['x-request-id'] ?? null, $responseHeaders['retry-after'] ?? null);
        return ['status'=>$status,'headers'=>$responseHeaders,'body'=>$raw];
    }
}
class Resource { public function __construct(protected readonly Client $c) {} }
final class Messages extends Resource {
    public function send(array $body,string $idempotencyKey): mixed { return $this->c->request('POST','/messages',$body,[],Client::key($idempotencyKey,true)); }
    public function list(array $query=[]): mixed { return $this->c->request('GET','/messages',null,$query); }
    public function iterate(array $query = [], int $maxPages = 1000): \Generator {
        if ($maxPages < 1) throw new \InvalidArgumentException('maxPages must be a positive integer');
        $current = $query; $seen = [];
        for ($page = 0; $page < $maxPages; $page++) {
            $result = $this->c->request('GET', '/messages', null, $current);
            if (!is_array($result['data'] ?? null) || !array_is_list($result['data'])) throw new WaixError('Expected a message list', errorCode: 'INVALID_RESPONSE');
            foreach ($result['data'] as $item) yield $item;
            $before = $result['pagination']['next_before'] ?? null;
            $beforeId = $result['pagination']['next_before_id'] ?? null;
            if ($before === null && $beforeId === null) return;
            $cursor = json_encode([$before, $beforeId], JSON_THROW_ON_ERROR);
            if (!is_string($before) || !is_string($beforeId) || isset($seen[$cursor])) throw new WaixError('Invalid or repeated pagination cursor', errorCode: 'INVALID_PAGINATION');
            $seen[$cursor] = true;
            $current = array_merge($query, ['before'=>$before, 'before_id'=>$beforeId]);
        }
        throw new WaixError('Message iteration reached maxPages', errorCode: 'PAGINATION_LIMIT');
    }
    public function get(string $id): mixed { return $this->c->request('GET','/messages/'.Client::part($id)); }
    public function retry(string $id,bool $confirmOutcomeUnknown=false): mixed { return $this->c->request('POST','/messages/'.Client::part($id).'/retry',['confirm_outcome_unknown'=>$confirmOutcomeUnknown]); }
}
final class Connections extends Resource {
    public function list(): mixed { return $this->c->request('GET','/connections'); }
    public function profile(string $id): mixed { return $this->c->request('GET','/connections/'.Client::part($id).'/profile'); }
    public function updateProfile(string $id,array $body): mixed { return $this->c->request('PUT','/connections/'.Client::part($id).'/profile',$body); }
}
final class Templates extends Resource {
    public function list(string $id,array $query=[]): mixed { return $this->c->request('GET','/connections/'.Client::part($id).'/templates',null,$query); }
    public function get(string $id,string $name): mixed { return $this->c->request('GET','/connections/'.Client::part($id).'/templates/'.Client::part($name)); }
    public function create(string $id,array $body): mixed { return $this->c->request('POST','/connections/'.Client::part($id).'/templates',$body); }
    public function update(string $id,string $name,array $body): mixed { return $this->c->request('PATCH','/connections/'.Client::part($id).'/templates/'.Client::part($name),$body); }
    public function delete(string $id,string $name): mixed { return $this->c->request('DELETE','/connections/'.Client::part($id).'/templates/'.Client::part($name)); }
    public function preview(string $id,array $body): mixed { return $this->c->request('POST','/connections/'.Client::part($id).'/templates/preview',$body); }
}
final class Otp extends Resource {
    public function send(array $body,string $idempotencyKey): mixed { return $this->c->request('POST','/otp/send',$body,[],Client::key($idempotencyKey)); }
    public function verify(string $id,mixed $code): mixed {
        Client::key($id, true);
        if (!is_string($code) || !preg_match('/^[0-9]{6}$/D', $code)) throw new \InvalidArgumentException('OTP code must be a six-digit string');
        return $this->c->request('POST','/otp/verify',['id'=>$id,'code'=>$code]);
    }
    public function status(string $id): mixed { return $this->c->request('GET','/otp/'.Client::part($id)); }
}
final class Webhooks extends Resource {
    public function get(): mixed { return $this->c->request('GET','/webhook'); }
    public function update(array $body): mixed { return $this->c->request('PUT','/webhook',$body); }
    public function delete(): mixed { return $this->c->request('DELETE','/webhook'); }
    public function test(): mixed { return $this->c->request('POST','/webhook/test',(object)[]); }
    public function rotateSecret(): mixed { return $this->c->request('POST','/webhook/rotate-secret',(object)[]); }
}
final class Media extends Resource {
    public function list(array $query=[]): mixed { return $this->c->request('GET','/media',null,$query); }
    public function getUrl(string $id): mixed { return $this->c->request('GET','/media/'.Client::part($id).'/url'); }
    public function delete(string $id): mixed { return $this->c->request('DELETE','/media/'.Client::part($id)); }
    public function upload(string $connectionId,string $file,string $contentType='application/octet-stream',array $options=[]): mixed {
        if (!is_file($file) || !is_readable($file)) throw new \InvalidArgumentException('File is not readable');
        Client::key($connectionId, true);
        if (filesize($file) > 100 * 1024 * 1024) throw new \InvalidArgumentException('File exceeds 100 MB');
        if (!preg_match('~^[a-zA-Z0-9.+-]+/[a-zA-Z0-9.+-]+$~D', $contentType)) throw new \InvalidArgumentException('Invalid contentType');
        if (isset($options['voice']) && !is_bool($options['voice'])) throw new \InvalidArgumentException('voice must be boolean');
        $body = ['connection_id'=>$connectionId,'file'=>new \CURLFile($file,$contentType,basename($file))];
        foreach (['type','voice'] as $name) if (array_key_exists($name,$options)) $body[$name] = is_bool($options[$name]) ? ($options[$name] ? 'true' : 'false') : (string)$options[$name];
        return $this->c->request('POST','/media',$body,[],null,true);
    }
}
