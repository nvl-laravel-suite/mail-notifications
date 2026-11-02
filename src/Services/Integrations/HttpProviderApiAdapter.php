<?php

declare(strict_types=1);

namespace Nvl\MailNotifications\Services\Integrations;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use InvalidArgumentException;
use Nvl\MailNotifications\Contracts\ProviderApiAdapter;
use Nvl\MailNotifications\Models\ProviderAccount;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;

/** Normalizes bounded wire responses for the internal provider integration pipeline.
 * @internal
 */
class HttpProviderApiAdapter implements ProviderApiAdapter
{
    private const int MAX_BYTES = 2097152;

    private const int PAGE_SIZE = 100;

    public function __construct(private readonly string $key) {}

    /**
     * Report the selected integration's supported operations without opening an HTTP request.
     */
    public function capabilities(): array
    {
        return ['send' => true, 'domains' => $this->key !== 'smtp', 'senders' => in_array($this->key, ['mailersend', 'sendgrid'], true), 'message' => $this->key !== 'smtp', 'messages' => $this->key !== 'smtp'];
    }

    /**
     * Fetch one bounded domain page and return its continuation cursor without claiming the entire catalogue is complete.
     */
    public function domains(ProviderAccount $account, ?string $cursor = null): ProviderApiResult
    {
        if (! $this->capabilities()['domains']) {
            return $this->unsupported();
        }
        $position = $this->cursorPosition($account, 'domains', $cursor);
        if ($position === null) {
            return $this->invalid();
        }
        $path = match ($this->key) {
            'mailersend' => '/v1/domains', 'sendgrid' => '/v3/whitelabel/domains', 'mailgun' => '/v4/domains',
            default => throw new InvalidArgumentException('Unsupported domain API.'),
        };
        $result = $this->request($account, 'GET', $path, $this->pageParameters('domains', $position));
        if ($result->status !== 'ok') {
            return $result;
        }
        $rows = match ($this->key) {
            'mailersend' => $result->data['data'] ?? null, 'sendgrid' => $result->data, 'mailgun' => $result->data['items'] ?? null,
        };
        if (! is_array($rows) || ! array_is_list($rows) || count($rows) > self::PAGE_SIZE) {
            return $this->invalid();
        }
        $domains = [];
        foreach ($rows as $row) {
            if (! is_array($row)) {
                return $this->invalid();
            }
            $domain = $row['name'] ?? $row['domain'] ?? null;
            $remoteId = $this->key === 'mailgun' ? $domain : ($row['id'] ?? $domain);
            if (! is_string($domain) || ! is_scalar($remoteId)) {
                return $this->invalid();
            }
            $verified = match ($this->key) {
                'mailersend' => ($row['is_verified'] ?? false) === true,
                'sendgrid' => ($row['valid'] ?? false) === true,
                'mailgun' => ($row['state'] ?? null) === 'active',
            };
            $dns = $row['dns'] ?? $row['sending_dns_records'] ?? $row['dns_records'] ?? [];
            $domains[] = ['remote_id' => (string) $remoteId, 'domain' => strtolower($domain), 'status' => $verified ? 'verified' : 'unverified', 'dns_records' => $this->normalizeDnsRecords(is_array($dns) ? $dns : [])];
        }

        return $this->discoveryPage($account, 'domains', $position, $result->data, $domains);
    }

    /**
     * Fetch provider verification records for the supplied authorized domain identifier.
     */
    public function domainDns(ProviderAccount $account, string $remoteId): ProviderApiResult
    {
        if (! $this->capabilities()['domains']) {
            return $this->unsupported();
        }
        $path = match ($this->key) {
            'mailersend' => '/v1/domains/'.rawurlencode($remoteId).'/dns-records',
            'sendgrid' => '/v3/whitelabel/domains/'.rawurlencode($remoteId),
            'mailgun' => '/v4/domains/'.rawurlencode($remoteId),
            default => throw new InvalidArgumentException('Unsupported domain API.'),
        };
        $result = $this->request($account, 'GET', $path);
        if ($result->status !== 'ok') {
            return $result;
        }
        $dns = match ($this->key) {
            'mailersend' => $result->data['data'] ?? null,
            'sendgrid' => $result->data['dns'] ?? null,
            'mailgun' => $result->data['sending_dns_records'] ?? null,
        };

        return is_array($dns) ? new ProviderApiResult('ok', $this->normalizeDnsRecords($dns), true) : $this->invalid();
    }

    /**
     * Fetch one bounded sender page and retain provider-specific continuation metadata.
     */
    public function senders(ProviderAccount $account, ?string $cursor = null): ProviderApiResult
    {
        if (! $this->capabilities()['senders']) {
            return $this->unsupported();
        }
        $position = $this->cursorPosition($account, 'senders', $cursor);
        if ($position === null) {
            return $this->invalid();
        }
        $result = $this->request($account, 'GET', $this->key === 'mailersend' ? '/v1/identities' : '/v3/verified_senders', $this->pageParameters('senders', $position));
        if ($result->status !== 'ok') {
            return $result;
        }
        $rows = $result->data[$this->key === 'mailersend' ? 'data' : 'results'] ?? null;
        if (! is_array($rows) || ! array_is_list($rows) || count($rows) > self::PAGE_SIZE) {
            return $this->invalid();
        }
        $senders = [];
        $lastId = $position;
        foreach ($rows as $row) {
            if (! is_array($row) || ! is_scalar($row['id'] ?? null)) {
                return $this->invalid();
            }
            $email = $row['email'] ?? $row['from_email'] ?? null;
            $domain = $row['domain'] ?? null;
            $domainId = is_array($domain) ? ($domain['id'] ?? null) : null;
            if (! is_string($email) || ! filter_var($email, FILTER_VALIDATE_EMAIL) || ($domainId !== null && ! is_scalar($domainId))) {
                return $this->invalid();
            }
            if ($this->key === 'sendgrid') {
                $id = $this->positiveInteger($row['id']);
                if ($id === null || $id <= $lastId) {
                    return $this->invalid();
                }
                $lastId = $id;
            }
            $senders[] = ['remote_id' => (string) $row['id'], 'email' => strtolower($email), 'name' => $row['name'] ?? $row['from_name'] ?? null, 'status' => ($row['is_verified'] ?? $row['verified'] ?? false) === true ? 'verified' : 'unverified', 'domain_remote_id' => $domainId === null ? null : (string) $domainId, 'reply_to_email' => $row['reply_to_email'] ?? $row['reply_to'] ?? null, 'reply_to_name' => $row['reply_to_name'] ?? null];
        }

        return $this->discoveryPage($account, 'senders', $position, $result->data, $senders, $lastId);
    }

    /**
     * Read safe provider delivery diagnostics for an authorized attempt, excluding message bodies.
     */
    public function message(ProviderAccount $account, string $remoteId): ProviderApiResult
    {
        if (! $this->capabilities()['message']) {
            return $this->unsupported();
        }
        if ($this->key === 'mailgun') {
            return $this->messages($account, ['message_id' => $remoteId]);
        }
        $path = $this->key === 'mailersend' ? '/v1/messages/' : '/v3/logs/';
        $result = $this->request($account, 'GET', $path.rawurlencode($remoteId));
        if ($result->status !== 'ok') {
            return $result;
        }

        $row = $result->data['data'] ?? $result->data;
        if (! is_array($row)) {
            return $this->invalid();
        }
        $data = $this->messageData($row);
        if ($data === null) {
            return $this->invalid();
        }
        if ($this->key === 'mailersend' && isset($row['emails']) && is_array($row['emails'])) {
            $details = [];
            foreach (array_slice($row['emails'], 0, 3) as $email) {
                if (! is_array($email) || ! is_string($email['id'] ?? null)) {
                    return $this->invalid();
                }
                $detail = $this->request($account, 'GET', '/v1/email/'.rawurlencode($email['id']));
                $emailData = $detail->data['data'] ?? null;
                $normalized = is_array($emailData) ? $this->messageData($emailData) : null;
                $details[] = ['id' => $email['id'], 'result' => ($detail->status === 'ok' && $normalized !== null ? new ProviderApiResult('ok', $normalized, true) : ($detail->status === 'ok' ? $this->invalid() : $detail))->jsonSerialize()];
            }
            $data['emails'] = $details;

            return new ProviderApiResult('ok', $data, count($row['emails']) <= 3, count($row['emails']) > 3 ? 'Recipient inspection is limited to the first three emails.' : null);
        }

        return new ProviderApiResult('ok', $data, true);
    }

    /**
     * Read a bounded diagnostics page with only supported provider filters.
     *
     * @param  array{domain_id?: string, domain?: string, date_from?: int, date_to?: int, message_id?: string}  $filters
     */
    public function messages(ProviderAccount $account, array $filters = []): ProviderApiResult
    {
        if (! $this->capabilities()['messages']) {
            return $this->unsupported();
        }
        if ($this->key === 'mailersend') {
            if (empty($filters['domain_id'])) {
                return new ProviderApiResult('missing_configuration', message: 'Select a synchronized domain to inspect individual emails.');
            }
            $result = $this->request($account, 'GET', '/v1/emails', ['domain_id' => $filters['domain_id'], 'date_from' => $filters['date_from'] ?? now()->subDay()->getTimestamp(), 'date_to' => $filters['date_to'] ?? now()->getTimestamp(), 'limit' => 100]);
            $rows = $result->data['data'] ?? null;
        } elseif ($this->key === 'sendgrid') {
            $query = 'sg_message_id_created_at >= TIMESTAMP "'.gmdate('Y-m-d\\TH:i:s\\Z', $filters['date_from'] ?? now()->subDay()->getTimestamp()).'" AND sg_message_id_created_at <= TIMESTAMP "'.gmdate('Y-m-d\\TH:i:s\\Z', $filters['date_to'] ?? now()->getTimestamp()).'"';
            if (isset($filters['message_id'])) {
                $result = $this->message($account, $filters['message_id']);

                return $result->status === 'ok'
                    ? new ProviderApiResult('ok', $result->data === [] ? [] : [$result->data], $result->complete, $result->message, $result->nextCursor, $result->total, $result->retryAfter)
                    : $result;
            }
            $result = $this->request($account, 'POST', '/v3/logs', ['query' => $query, 'limit' => 100]);
            $rows = $result->data['messages'] ?? null;
        } else {
            $domain = $account->credentials['domain'] ?? null;
            if (! is_string($domain) || $domain === '') {
                return new ProviderApiResult('missing_configuration', message: 'Configure the Mailgun sending domain.');
            }
            $predicates = [$this->predicate('domain', $domain)];
            if (isset($filters['message_id'])) {
                $predicates[] = $this->predicate('message_id', trim($filters['message_id'], '<>'));
            }
            $result = $this->request($account, 'POST', '/v1/analytics/logs', ['start' => gmdate(DATE_RFC2822, $filters['date_from'] ?? now()->subDays(30)->getTimestamp()), 'end' => gmdate(DATE_RFC2822, $filters['date_to'] ?? now()->getTimestamp()), 'filter' => ['AND' => $predicates], 'include_subaccounts' => false, 'pagination' => ['limit' => 100, 'sort' => 'timestamp:desc']]);
            $rows = $result->data['items'] ?? null;
        }
        if ($result->status !== 'ok') {
            return $result;
        }
        if (! is_array($rows) || ! array_is_list($rows)) {
            return $this->invalid();
        }

        $messages = [];
        foreach (array_slice($rows, 0, 100) as $row) {
            $normalized = is_array($row) ? $this->messageData($row) : null;
            if ($normalized === null) {
                return $this->invalid();
            }
            $messages[] = $normalized;
        }

        return new ProviderApiResult('ok', $messages, count($rows) < 100 && (! is_array($result->data['pagination'] ?? null) || empty($result->data['pagination']['next'])) && (! is_array($result->data['links'] ?? null) || empty($result->data['links']['next'])));
    }

    /** @return array<string, int> */
    private function pageParameters(string $operation, int $position): array
    {
        $parameters = ['limit' => self::PAGE_SIZE];
        if ($this->key === 'mailersend') {
            $parameters['page'] = $position;
        } elseif ($this->key === 'mailgun') {
            $parameters['skip'] = $position;
            $parameters['limit'] = self::PAGE_SIZE;
        } elseif ($operation === 'domains') {
            $parameters['offset'] = $position;
        } elseif ($position > 0) {
            $parameters['lastSeenID'] = $position;
        }

        return $parameters;
    }

    private function cursorPosition(ProviderAccount $account, string $operation, ?string $cursor): ?int
    {
        if ($cursor === null) {
            return $this->key === 'mailersend' ? 1 : 0;
        }
        if (strlen($cursor) > 128 || preg_match('/\Av1\.([1-9][0-9]{0,18})\.([a-f0-9]{64})\z/', $cursor, $matches) !== 1) {
            return null;
        }
        $position = $this->positiveInteger($matches[1]);
        if ($position === null || $position > intdiv(PHP_INT_MAX, self::PAGE_SIZE) || ($this->key === 'mailersend' && $position < 2)) {
            return null;
        }
        if ($this->key === 'sendgrid' && $operation === 'domains' && $position % self::PAGE_SIZE !== 0) {
            return null;
        }

        return hash_equals($this->cursorSignature($account, $operation, $position), $matches[2]) ? $position : null;
    }

    private function cursorSignature(ProviderAccount $account, string $operation, int $position): string
    {
        $credentials = $account->credentials;
        ksort($credentials);

        return hash_hmac('sha256', json_encode(['v1', $this->key, $account->id, $operation, $position, $credentials], JSON_THROW_ON_ERROR), config()->string('app.key'));
    }

    private function nextCursor(ProviderAccount $account, string $operation, int $position): ?string
    {
        if ($position < 1 || $position > intdiv(PHP_INT_MAX, self::PAGE_SIZE)) {
            return null;
        }

        return 'v1.'.$position.'.'.$this->cursorSignature($account, $operation, $position);
    }

    /**
     * @param  array<array-key, mixed>  $payload
     * @param  list<array<string, mixed>>  $rows
     */
    private function discoveryPage(ProviderAccount $account, string $operation, int $position, array $payload, array $rows, ?int $lastId = null): ProviderApiResult
    {
        $count = count($rows);
        $total = null;
        $hasMore = $count === self::PAGE_SIZE;
        $nextPosition = $position + $count;
        if ($this->key === 'mailersend') {
            $pagination = $this->mailerSendPagination($payload, $position, $count);
            if ($pagination === null) {
                return $this->invalid();
            }
            [$hasMore, $total] = $pagination;
            $nextPosition = $position + 1;
        } elseif ($this->key === 'mailgun' && array_key_exists('total_count', $payload)) {
            $total = $this->nonNegativeInteger($payload['total_count']);
            if ($total === null || $total < $position + $count || ($count === 0 && $total > $position)) {
                return $this->invalid();
            }
            $hasMore = $position + $count < $total;
        } elseif ($this->key === 'sendgrid' && $operation === 'senders') {
            $nextPosition = $lastId ?? 0;
        }
        if ($hasMore && $count === 0) {
            return $this->invalid();
        }
        $cursor = $hasMore ? $this->nextCursor($account, $operation, $nextPosition) : null;
        if ($hasMore && $cursor === null) {
            return $this->invalid();
        }

        return new ProviderApiResult('ok', $rows, ! $hasMore, nextCursor: $cursor, total: $total);
    }

    /** @param array<array-key, mixed> $payload
     * @return array{bool, ?int}|null
     */
    private function mailerSendPagination(array $payload, int $page, int $count): ?array
    {
        $meta = $payload['meta'] ?? [];
        if ((array_key_exists('meta', $payload) && ! is_array($payload['meta'])) || ! is_array($meta)) {
            return null;
        }
        if (array_key_exists('current_page', $meta) && $this->positiveInteger($meta['current_page']) !== $page) {
            return null;
        }
        $perPage = array_key_exists('per_page', $meta) ? $this->positiveInteger($meta['per_page']) : self::PAGE_SIZE;
        if ($perPage === null || $perPage > self::PAGE_SIZE || $perPage < $count) {
            return null;
        }
        $total = array_key_exists('total', $meta) ? $this->nonNegativeInteger($meta['total']) : null;
        if (array_key_exists('total', $meta) && $total === null) {
            return null;
        }
        $lastPage = array_key_exists('last_page', $meta) ? $this->positiveInteger($meta['last_page']) : null;
        if (array_key_exists('last_page', $meta) && ($lastPage === null || $lastPage < $page)) {
            return null;
        }
        $hasMore = null;
        if (array_key_exists('links', $payload)) {
            if (! is_array($payload['links'])) {
                return null;
            }
            if (array_key_exists('next', $payload['links'])) {
                $next = $payload['links']['next'];
                if ($next !== null && (! is_string($next) || ! $this->isNextPageLink($next, $page + 1))) {
                    return null;
                }
                $hasMore = $next !== null;
            }
        }
        if ($lastPage !== null) {
            if ($hasMore !== null && $hasMore !== ($page < $lastPage)) {
                return null;
            }
            $hasMore = $page < $lastPage;
        }
        if ($total !== null) {
            $end = ($page - 1) * $perPage + $count;
            if ($end > $total || ($count === 0 && $end < $total)) {
                return null;
            }
            if ($hasMore !== null && $hasMore !== ($end < $total)) {
                return null;
            }
            $hasMore = $end < $total;
        }

        return [$hasMore ?? $count === $perPage, $total];
    }

    private function isNextPageLink(string $link, int $nextPage): bool
    {
        if (strlen($link) > 2048) {
            return false;
        }
        $query = parse_url($link, PHP_URL_QUERY);
        if (! is_string($query)) {
            return false;
        }
        parse_str($query, $parameters);

        return $this->positiveInteger($parameters['page'] ?? null) === $nextPage;
    }

    private function positiveInteger(mixed $value): ?int
    {
        $integer = $this->nonNegativeInteger($value);

        return $integer !== null && $integer > 0 ? $integer : null;
    }

    private function nonNegativeInteger(mixed $value): ?int
    {
        if ((! is_int($value) && ! is_string($value)) || preg_match('/\A(?:0|[1-9][0-9]*)\z/', (string) $value) !== 1) {
            return null;
        }
        $integer = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);

        return is_int($integer) ? $integer : null;
    }

    /** @param array<string, mixed> $parameters */
    private function request(ProviderAccount $account, string $method, string $path, array $parameters = []): ProviderApiResult
    {
        $token = $account->credentials['api_key'] ?? null;
        if (! is_string($token) || $token === '') {
            return new ProviderApiResult('missing_configuration', message: 'Configure an API token with the required read permissions.');
        }
        $readKey = 'provider-api:'.$account->id.':reads';
        $activityKey = 'provider-api:'.$account->id.':activity';
        $isActivity = $this->key === 'mailersend' && preg_match('~^/v1/(?:emails|email/|messages/|activity)~', $path) === 1;
        if (RateLimiter::tooManyAttempts($readKey, 60) || ($isActivity && RateLimiter::tooManyAttempts($activityKey, 10))) {
            return $this->locallyRateLimited($readKey, $isActivity ? $activityKey : null);
        }
        if (RateLimiter::increment($readKey, 60) > 60 || ($isActivity && RateLimiter::increment($activityKey, 60) > 10)) {
            return $this->locallyRateLimited($readKey, $isActivity ? $activityKey : null);
        }
        $eu = ($account->credentials['region'] ?? 'us') === 'eu';
        $base = match ($this->key) {
            'mailersend' => 'https://api.mailersend.com',
            'sendgrid' => $eu ? 'https://api.eu.sendgrid.com' : 'https://api.sendgrid.com',
            'mailgun' => $eu ? 'https://api.eu.mailgun.net' : 'https://api.mailgun.net',
            default => throw new InvalidArgumentException('Unsupported provider API.'),
        };
        try {
            $request = Http::acceptJson()->connectTimeout(3)->timeout(10)->withOptions([
                'allow_redirects' => false,
                'on_headers' => function (ResponseInterface $response): void {
                    if ((int) $response->getHeaderLine('Content-Length') > self::MAX_BYTES) {
                        throw new RuntimeException('Response size limit exceeded.');
                    }
                },
                'progress' => function (int $total, int $downloaded): void {
                    if ($downloaded > self::MAX_BYTES) {
                        throw new RuntimeException('Response size limit exceeded.');
                    }
                },
            ]);
            $request = $this->key === 'mailgun' ? $request->withBasicAuth('api', $token) : $request->withToken($token);
            $response = $method === 'GET' ? $request->get($base.$path, $parameters) : $request->post($base.$path, $parameters);
            if (! $response->successful()) {
                $status = match ($response->status()) {
                    401, 403 => 'permission_denied', 404 => 'not_found', 429 => 'rate_limited', default => 'unavailable',
                };

                return new ProviderApiResult($status, message: match ($status) {
                    'permission_denied' => 'The provider denied access. Check token scopes and account permissions.',
                    'not_found' => 'The provider record is unavailable or has expired.',
                    'rate_limited' => 'The provider rate limit was reached. Try again later.',
                    default => 'The provider could not complete this request.',
                }, retryAfter: $status === 'rate_limited' ? $this->retryAfter($response->header('Retry-After')) : null);
            }
            if (strlen($response->body()) > self::MAX_BYTES || ! is_array($response->json())) {
                return $this->invalid();
            }

            return new ProviderApiResult('ok', $response->json());
        } catch (ConnectionException|RuntimeException $exception) {
            return new ProviderApiResult('unavailable', message: 'The provider request failed or exceeded its response limits.');
        }
    }

    private function locallyRateLimited(string $readKey, ?string $activityKey): ProviderApiResult
    {
        $delay = max(1, RateLimiter::availableIn($readKey), $activityKey === null ? 0 : RateLimiter::availableIn($activityKey));

        return new ProviderApiResult('rate_limited', message: 'The account read allowance is exhausted. Try again later.', retryAfter: min(86400, $delay));
    }

    private function retryAfter(?string $header): int
    {
        if ($header === null || strlen($header) > 128) {
            return 60;
        }
        $header = trim($header);
        if (preg_match('/\A[0-9]+\z/', $header) === 1) {
            return (int) max(1, min(86400, (float) $header));
        }
        $date = \DateTimeImmutable::createFromFormat(DATE_RFC7231, $header);
        if ($date === false) {
            return 60;
        }

        return max(1, min(86400, $date->getTimestamp() - now()->getTimestamp()));
    }

    /** @param array<array-key, mixed> $row
     * @return array<string, mixed>|null
     */
    private function messageData(array $row): ?array
    {
        $fields = ['id', 'message_id', 'sg_message_id', 'sg_message_id_created_at', 'status', 'event', '@timestamp', 'timestamp', 'created_at', 'last_timestamp', 'processed', 'delivered', 'soft_bounced', 'hard_bounced', 'opened', 'clicked', 'unique_opens_count', 'unique_clicks_count', 'messages_count'];
        $data = [];
        foreach ($fields as $field) {
            if (array_key_exists($field, $row)) {
                $data[$field] = $row[$field];
            }
        }
        $lists = [
            'activity' => ['event', 'created_at', 'type', 'status', 'reason'],
            'events' => ['event', 'timestamp', 'type', 'status', 'reason', 'response', 'attempt'],
            'emails' => ['id', 'status', 'message_id', 'created_at'],
        ];
        foreach ($lists as $list => $fields) {
            if (! array_key_exists($list, $row)) {
                continue;
            }
            $items = $row[$list];
            if (! is_array($items) || ! array_is_list($items)) {
                return null;
            }
            $normalized = [];
            foreach ($items as $index => $item) {
                if (! is_array($item)) {
                    return null;
                }
                if ($index >= ($list === 'emails' ? 100 : 200)) {
                    continue;
                }
                $entry = [];
                foreach ($fields as $field) {
                    if (array_key_exists($field, $item)) {
                        $entry[$field] = $item[$field];
                    }
                }
                $normalized[] = $entry;
            }
            $data[$list] = $normalized;
        }

        return $data;
    }

    /** @param array<array-key, mixed> $records
     * @return list<array<string, mixed>>
     */
    private function normalizeDnsRecords(array $records): array
    {
        $normalized = [];
        foreach ($records as $record) {
            if (! is_array($record)) {
                continue;
            }
            $entry = [];
            foreach (['type', 'record_type', 'hostname', 'host', 'name', 'value', 'valid', 'validity', 'priority', 'status'] as $field) {
                if (array_key_exists($field, $record)) {
                    $entry[$field] = $record[$field];
                }
            }
            $normalized[] = $entry;
        }

        return $normalized;
    }

    /** @return array{attribute: string, comparator: string, values: list<array{label: string, value: string}>} */
    private function predicate(string $attribute, string $value): array
    {
        return ['attribute' => $attribute, 'comparator' => '=', 'values' => [['label' => $value, 'value' => $value]]];
    }

    private function unsupported(): ProviderApiResult
    {
        return new ProviderApiResult('unsupported', message: 'This integration does not expose this operation.');
    }

    private function invalid(): ProviderApiResult
    {
        return new ProviderApiResult('invalid_response', message: 'The provider returned an unexpected response.');
    }
}
