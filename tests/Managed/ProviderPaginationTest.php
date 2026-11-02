<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Nvl\MailNotifications\Models\ProviderAccount;
use Nvl\MailNotifications\Services\Integrations\HttpProviderApiAdapter;
use Nvl\MailNotifications\Services\Integrations\ProviderApiResult;

test('sendgrid message filtering keeps the list contract and error outcomes', function () {
    Http::preventStrayRequests();
    $account = ProviderAccount::factory()->make(['provider' => 'sendgrid', 'credentials' => ['api_key' => 'secret']]);
    Http::fake(['https://api.sendgrid.com/v3/logs/remote-id' => Http::sequence()->push(['sg_message_id' => 'remote-id', 'status' => 'delivered'])->push([], 403)]);
    $adapter = new HttpProviderApiAdapter('sendgrid');

    $result = $adapter->messages($account, ['message_id' => 'remote-id']);

    expect($result)->status->toBe('ok')->data->toBe([['sg_message_id' => 'remote-id', 'status' => 'delivered']])->complete->toBeTrue();
    expect($adapter->messages($account, ['message_id' => 'remote-id'])->status)->toBe('permission_denied');
    Http::assertSentCount(2);
});

beforeEach(function () {
    Http::preventStrayRequests();
});

/** @return list<array<string, mixed>> */
function discoveryRows(string $operation, int $first, int $last): array
{
    return array_map(fn (int $id): array => $operation === 'domains'
        ? ['id' => $id, 'name' => 'domain'.$id.'.example.com', 'domain' => 'domain'.$id.'.example.com', 'is_verified' => true, 'valid' => true, 'state' => 'active', 'dns' => [['type' => 'TXT', 'value' => 'dns-'.$id]]]
        : ['id' => $id, 'email' => 'sender'.$id.'@example.com', 'from_email' => 'sender'.$id.'@example.com', 'name' => 'Sender '.$id, 'is_verified' => true, 'verified' => true, 'domain' => ['id' => 'domain-'.$id], 'reply_to_email' => 'reply'.$id.'@example.com', 'reply_to_name' => 'Reply '.$id], range($first, $last));
}

/** @param list<array<string, mixed>> $rows
 * @return array<array-key, mixed>
 */
function discoveryEnvelope(string $provider, string $operation, array $rows): array
{
    return match (true) {
        $provider === 'mailersend' => ['data' => $rows],
        $provider === 'mailgun' => ['items' => $rows],
        $operation === 'senders' => ['results' => $rows],
        default => $rows,
    };
}

dataset('discovery endpoints', [
    ['mailersend', 'domains', 'api.mailersend.com/v1/domains', 'page', 1, 2],
    ['mailersend', 'senders', 'api.mailersend.com/v1/identities', 'page', 1, 2],
    ['sendgrid', 'domains', 'api.sendgrid.com/v3/whitelabel/domains', 'offset', 0, 100],
    ['sendgrid', 'senders', 'api.sendgrid.com/v3/verified_senders', 'lastSeenID', null, 100],
    ['mailgun', 'domains', 'api.mailgun.net/v4/domains', 'skip', 0, 100],
]);

test('discovery returns every normalized record across multiple pages', function (string $provider, string $operation, string $endpoint, string $parameter, ?int $initial, int $next) {
    $account = ProviderAccount::factory()->create(['provider' => $provider]);
    Http::fake([$endpoint.'*' => Http::sequence()
        ->push(discoveryEnvelope($provider, $operation, discoveryRows($operation, 1, 100)))
        ->push(discoveryEnvelope($provider, $operation, discoveryRows($operation, 101, 137)))]);
    $adapter = new HttpProviderApiAdapter($provider);

    $first = $adapter->$operation($account);

    expect($first->status)->toBe('ok');
    expect($first->data)->toHaveCount(100);
    expect($first->complete)->toBeFalse();
    expect($first->nextCursor)->toBeString();
    expect($first->total)->toBeNull();

    $last = $adapter->$operation($account, $first->nextCursor);

    expect($last->status)->toBe('ok');
    expect($last->data)->toHaveCount(37);
    expect($last->complete)->toBeTrue();
    expect($last->nextCursor)->toBeNull();
    expect($last->data[36])->toMatchArray($operation === 'domains'
        ? ['remote_id' => $provider === 'mailgun' ? 'domain137.example.com' : '137', 'domain' => 'domain137.example.com', 'status' => 'verified', 'dns_records' => [['type' => 'TXT', 'value' => 'dns-137']]]
        : ['remote_id' => '137', 'email' => 'sender137@example.com', 'name' => 'Sender 137', 'status' => 'verified', 'domain_remote_id' => 'domain-137', 'reply_to_email' => 'reply137@example.com', 'reply_to_name' => 'Reply 137']);
    Http::assertSent(fn (Request $request): bool => ($request[$parameter] ?? null) === $initial && $request['limit'] === 100);
    Http::assertSent(fn (Request $request): bool => ($request[$parameter] ?? null) === $next && parse_url($request->url(), PHP_URL_HOST) === parse_url('https://'.$endpoint, PHP_URL_HOST));
    Http::assertSentCount(2);
})->with('discovery endpoints');

test('a full last page needs an empty confirmation when no pagination metadata exists', function (string $provider, string $operation, string $endpoint) {
    $account = ProviderAccount::factory()->create(['provider' => $provider]);
    Http::fake([$endpoint.'*' => Http::sequence()
        ->push(discoveryEnvelope($provider, $operation, discoveryRows($operation, 1, 100)))
        ->push(discoveryEnvelope($provider, $operation, discoveryRows($operation, 101, 200)))
        ->push(discoveryEnvelope($provider, $operation, []))]);
    $adapter = new HttpProviderApiAdapter($provider);

    $first = $adapter->$operation($account);
    $second = $adapter->$operation($account, $first->nextCursor);
    $last = $adapter->$operation($account, $second->nextCursor);

    expect($second->complete)->toBeFalse();
    expect($second->nextCursor)->toBeString();
    expect($last->status)->toBe('ok');
    expect($last->complete)->toBeTrue();
    expect($last->data)->toBe([]);
    expect($last->nextCursor)->toBeNull();
    Http::assertSentCount(3);
})->with('discovery endpoints');

test('an empty first page is positively exhausted', function (string $provider, string $operation, string $endpoint) {
    $account = ProviderAccount::factory()->create(['provider' => $provider]);
    Http::fake([$endpoint.'*' => Http::response(discoveryEnvelope($provider, $operation, []))]);

    $result = (new HttpProviderApiAdapter($provider))->$operation($account);

    expect($result->complete)->toBeTrue();
    expect($result->nextCursor)->toBeNull();
    Http::assertSentCount(1);
})->with('discovery endpoints');

test('MailerSend metadata continues short server pages and completes a full final page', function (string $operation, string $path) {
    $account = ProviderAccount::factory()->create();
    Http::fake(['api.mailersend.com'.$path.'*' => Http::sequence()
        ->push(['data' => discoveryRows($operation, 1, 25), 'links' => ['next' => 'https://malicious.example'.$path.'?page=2'], 'meta' => ['current_page' => 1, 'last_page' => 2, 'per_page' => 25, 'total' => 50]])
        ->push(['data' => discoveryRows($operation, 26, 50), 'links' => ['next' => null], 'meta' => ['current_page' => 2, 'last_page' => 2, 'per_page' => 25, 'total' => 50]])]);
    $adapter = new HttpProviderApiAdapter('mailersend');

    $first = $adapter->$operation($account);
    $last = $adapter->$operation($account, $first->nextCursor);

    expect($first->complete)->toBeFalse();
    expect($first->total)->toBe(50);
    expect($last->complete)->toBeTrue();
    expect($last->total)->toBe(50);
    Http::assertSent(fn (Request $request): bool => $request['page'] === 2 && str_starts_with($request->url(), 'https://api.mailersend.com'.$path.'?'));
    Http::assertSentCount(2);
})->with([['domains', '/v1/domains'], ['senders', '/v1/identities']]);

test('Mailgun reports its explicit total and ends on a full last page', function () {
    $account = ProviderAccount::factory()->create(['provider' => 'mailgun']);
    Http::fake(['api.mailgun.net/v4/domains*' => Http::response(['items' => discoveryRows('domains', 1, 100), 'total_count' => 100])]);

    $result = (new HttpProviderApiAdapter('mailgun'))->domains($account);

    expect($result->complete)->toBeTrue();
    expect($result->total)->toBe(100);
    Http::assertSentCount(1);
});

test('invalid pagination metadata never marks discovery exhausted', function (string $provider, array $metadata) {
    $account = ProviderAccount::factory()->create(['provider' => $provider]);
    $endpoint = $provider === 'mailgun' ? 'api.mailgun.net/v4/domains' : 'api.mailersend.com/v1/domains';
    Http::fake([$endpoint.'*' => Http::response([...discoveryEnvelope($provider, 'domains', discoveryRows('domains', 1, 1)), ...$metadata])]);

    $result = (new HttpProviderApiAdapter($provider))->domains($account);

    expect($result->status)->toBe('invalid_response');
    expect($result->complete)->toBeFalse();
    Http::assertSentCount(1);
})->with([
    ['mailersend', ['meta' => 'invalid']],
    ['mailersend', ['meta' => ['current_page' => 2]]],
    ['mailersend', ['meta' => ['last_page' => 0]]],
    ['mailersend', ['meta' => ['total' => 'unknown']]],
    ['mailersend', ['links' => ['next' => ['page' => 2]]]],
    ['mailersend', ['links' => ['next' => 'https://api.mailersend.com/v1/domains?page=1']]],
    ['mailersend', ['links' => ['next' => null], 'meta' => ['current_page' => 1, 'last_page' => 2]]],
    ['mailgun', ['total_count' => -1]],
    ['mailgun', ['total_count' => 0]],
]);

test('malformed or untrusted cursors are rejected before any outgoing request', function (string $cursor) {
    $account = ProviderAccount::factory()->create();

    $result = (new HttpProviderApiAdapter('mailersend'))->domains($account, $cursor);

    expect($result->status)->toBe('invalid_response');
    expect($result->complete)->toBeFalse();
    Http::assertNothingSent();
})->with(['', 'https://malicious.example/?page=2', 'page:2', str_repeat('x', 2000), base64_encode('{"position":-1}')]);

test('cursors cannot cross accounts operations or credentials', function (string $change) {
    $account = ProviderAccount::factory()->create();
    Http::fake(['api.mailersend.com/v1/domains*' => Http::response(['data' => discoveryRows('domains', 1, 100)])]);
    $adapter = new HttpProviderApiAdapter('mailersend');
    $first = $adapter->domains($account);
    $operation = 'domains';
    match ($change) {
        'account' => $account = ProviderAccount::factory()->create(),
        'operation' => $operation = 'senders',
        'credentials' => $account->credentials = ['api_key' => 'new-token'],
    };

    $result = $adapter->$operation($account, $first->nextCursor);

    expect($result->status)->toBe('invalid_response');
    Http::assertSentCount(1);
})->with(['account', 'operation', 'credentials']);

test('a repeated SendGrid sender page does not falsely exhaust discovery', function () {
    $account = ProviderAccount::factory()->create(['provider' => 'sendgrid']);
    Http::fake(['api.sendgrid.com/v3/verified_senders*' => Http::response(['results' => discoveryRows('senders', 1, 100)])]);
    $adapter = new HttpProviderApiAdapter('sendgrid');
    $first = $adapter->senders($account);

    $result = $adapter->senders($account, $first->nextCursor);

    expect($result->status)->toBe('invalid_response');
    expect($result->complete)->toBeFalse();
    Http::assertSentCount(2);
});

test('provider retry delays support seconds dates and safe bounds without exposing response secrets', function (string $header, int $seconds) {
    $this->freezeTime();
    $account = ProviderAccount::factory()->create();
    Http::fake(['api.mailersend.com/v1/domains*' => Http::response(['secret' => 'private-provider-token'], 429, ['Retry-After' => $header === 'date' ? now()->addSeconds(75)->toRfc7231String() : $header])]);

    $result = (new HttpProviderApiAdapter('mailersend'))->domains($account);

    expect($result->status)->toBe('rate_limited');
    expect($result->retryAfter)->toBe($seconds);
    expect(json_encode($result))->not->toContain('private-provider-token');
    Http::assertSentCount(1);
})->with([['45', 45], ['date', 75], ['99999999999999999999999999', 86400], ['invalid', 60], ['-10', 60], ['0', 1]]);

test('shared account read limiting includes a delay without sending a request', function () {
    $account = ProviderAccount::factory()->create();
    $readKey = 'provider-api:'.$account->id.':reads';
    RateLimiter::increment($readKey, 60, 60);

    $result = (new HttpProviderApiAdapter('mailersend'))->domains($account);

    expect($result->status)->toBe('rate_limited');
    expect($result->retryAfter)->toBeGreaterThan(0)->toBeLessThanOrEqual(60);
    Http::assertNothingSent();
});

test('unavailable provider responses remain retryable statuses', function () {
    $account = ProviderAccount::factory()->create();
    Http::fake(['api.mailersend.com/v1/domains*' => Http::sequence()->pushStatus(503)->pushResponse(Http::failedConnection())]);
    $adapter = new HttpProviderApiAdapter('mailersend');

    expect($adapter->domains($account)->status)->toBe('unavailable');
    expect($adapter->domains($account)->status)->toBe('unavailable');
    Http::assertSentCount(2);
});

test('unsupported discovery is explicit without making provider requests', function (string $provider, string $operation) {
    $account = ProviderAccount::factory()->create(['provider' => $provider]);

    $result = (new HttpProviderApiAdapter($provider))->$operation($account);

    expect($result->status)->toBe('unsupported');
    expect($result->complete)->toBeFalse();
    Http::assertNothingSent();
})->with([['mailgun', 'senders'], ['smtp', 'domains'], ['smtp', 'senders']]);

test('legacy result serialization is unchanged unless pagination or retry metadata is available', function () {
    expect((new ProviderApiResult('ok', [], true))->jsonSerialize())->toBe(['status' => 'ok', 'data' => [], 'complete' => true, 'message' => null]);
    expect((new ProviderApiResult('ok', [], false, null, 'cursor', 150, 5))->jsonSerialize())->toBe(['status' => 'ok', 'data' => [], 'complete' => false, 'message' => null, 'next_cursor' => 'cursor', 'total' => 150, 'retry_after' => 5]);
});

test('sender discovery rejects malformed email and nested domain identifiers safely', function (array $row): void {
    $account = ProviderAccount::factory()->create();
    Http::fake(['api.mailersend.com/v1/identities*' => Http::response(['data' => [$row]])]);

    expect((new HttpProviderApiAdapter('mailersend'))->senders($account)->status)->toBe('invalid_response');
})->with([
    [['id' => 'sender', 'email' => 42]],
    [['id' => 'sender', 'email' => 'sender@example.com', 'domain' => ['id' => ['invalid']]]],
]);
