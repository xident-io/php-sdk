<?php

declare(strict_types=1);

namespace Xident\SDK\Tests\Unit\Resources;

use PHPUnit\Framework\TestCase;
use Xident\SDK\Client;
use Xident\SDK\Exceptions\NotFoundException;
use Xident\SDK\Exceptions\ValidationException;
use Xident\SDK\Responses\BlacklistEntry;
use Xident\SDK\Responses\BlacklistEntryList;
use Xident\SDK\Tests\Helpers\MockTransport;

final class BlacklistTest extends TestCase
{
    private function client(MockTransport $transport): Client
    {
        return new Client('sk_test_123', transport: $transport);
    }

    // --- list() ---

    /**
     * A row the API should never send, sent anyway.
     *
     * fromResponse() guards each row with is_array() because its input is raw
     * json_decode output, and one malformed row must not take down a whole
     * page of otherwise-good entries. This asserts the guard skips rather than
     * fatals, and that the good rows still come through.
     */
    public function testListSkipsRowsThatAreNotObjects(): void
    {
        $transport = new MockTransport();
        $transport->queueSuccess(
            [
                ['id' => 7, 'reason' => 'fraud attempt', 'source' => 'session', 'created_at' => '2026-08-01T10:00:00Z'],
                'a bare string where an object belongs',
                null,
                42,
                ['id' => 9, 'reason' => 'second good row', 'source' => 'image', 'created_at' => '2026-08-01T12:00:00Z'],
            ],
            ['pagination' => ['page' => 1, 'per_page' => 20, 'total' => 5, 'total_pages' => 1]],
        );

        $list = $this->client($transport)->blacklist()->list();

        $this->assertCount(2, $list->entries);
        $this->assertSame(2, $list->count());
        $this->assertSame([7, 9], array_map(static fn ($entry) => $entry->id, $list->entries));
        $this->assertSame('fraud attempt', $list->entries[0]->reason);
        $this->assertSame('second good row', $list->entries[1]->reason);
        // Pagination comes from meta, so it still reports what the server said.
        $this->assertSame(5, $list->total);
    }

    public function testListReturnsEntriesWithPagination(): void
    {
        $transport = new MockTransport();
        $transport->queueSuccess(
            [
                [
                    'id' => 7,
                    'reason' => 'fraud attempt',
                    'source' => 'session',
                    'session_id' => 991,
                    'created_at' => '2026-08-01T10:00:00Z',
                ],
                [
                    'id' => 8,
                    'reason' => 'chargeback abuse',
                    'source' => 'image',
                    'created_at' => '2026-08-01T11:00:00Z',
                ],
            ],
            [
                'request_id' => 'req_1',
                'pagination' => ['page' => 1, 'per_page' => 20, 'total' => 42, 'total_pages' => 3],
            ],
        );

        $list = $this->client($transport)->blacklist()->list();

        $this->assertInstanceOf(BlacklistEntryList::class, $list);
        $this->assertCount(2, $list->entries);
        $this->assertSame(2, $list->count());
        $this->assertSame(1, $list->page);
        $this->assertSame(20, $list->perPage);
        $this->assertSame(42, $list->total);
        $this->assertSame(3, $list->totalPages);
        $this->assertTrue($list->hasMore());

        $first = $list->entries[0];
        $this->assertInstanceOf(BlacklistEntry::class, $first);
        $this->assertSame(7, $first->id);
        $this->assertSame('fraud attempt', $first->reason);
        $this->assertSame('session', $first->source);
        $this->assertSame(991, $first->sessionId);
        $this->assertSame('2026-08-01T10:00:00Z', $first->createdAt);

        // session_id is omitted for image-sourced entries
        $this->assertNull($list->entries[1]->sessionId);
    }

    public function testListSendsQueryParams(): void
    {
        $transport = new MockTransport();
        $transport->queueSuccess([], ['pagination' => ['page' => 2, 'per_page' => 50, 'total' => 0, 'total_pages' => 0]]);

        $this->client($transport)->blacklist()->list(page: 2, perPage: 50);

        $req = $transport->getLastRequest();
        $this->assertSame('GET', $req['method']);
        $this->assertStringContainsString('/verify/v1/blacklist?', $req['url']);
        $this->assertStringContainsString('page=2', $req['url']);
        $this->assertStringContainsString('per_page=50', $req['url']);
    }

    public function testListDefaultsToFirstPage(): void
    {
        $transport = new MockTransport();
        $transport->queueSuccess([], ['pagination' => ['page' => 1, 'per_page' => 20, 'total' => 0, 'total_pages' => 0]]);

        $list = $this->client($transport)->blacklist()->list();

        $req = $transport->getLastRequest();
        $this->assertStringContainsString('page=1', $req['url']);
        $this->assertStringContainsString('per_page=20', $req['url']);

        $this->assertSame([], $list->entries);
        $this->assertFalse($list->hasMore());
    }

    public function testListPageZeroThrows(): void
    {
        $client = $this->client(new MockTransport());

        $this->expectException(\InvalidArgumentException::class);
        $client->blacklist()->list(page: 0);
    }

    public function testListPerPageZeroThrows(): void
    {
        $client = $this->client(new MockTransport());

        $this->expectException(\InvalidArgumentException::class);
        $client->blacklist()->list(perPage: 0);
    }

    public function testListPerPageOverLimitThrows(): void
    {
        $client = $this->client(new MockTransport());

        $this->expectException(\InvalidArgumentException::class);
        $client->blacklist()->list(perPage: 101);
    }

    // --- addBySession() ---

    public function testAddBySessionReturnsProcessing(): void
    {
        $transport = new MockTransport();
        $transport->queueResponse(201, [
            'success' => true,
            'data' => ['status' => 'processing'],
        ]);

        $status = $this->client($transport)->blacklist()->addBySession('xtk_abc', 'fraud attempt');

        $this->assertSame('processing', $status);

        $req = $transport->getLastRequest();
        $this->assertSame('POST', $req['method']);
        $this->assertStringContainsString('/verify/v1/blacklist/session', $req['url']);

        $body = json_decode($req['body'], true);
        $this->assertSame(['session_token' => 'xtk_abc', 'reason' => 'fraud attempt'], $body);
    }

    public function testAddBySessionNotFound(): void
    {
        $transport = new MockTransport();
        $transport->queueError(404, 'NOT_FOUND', 'session not found');

        $this->expectException(NotFoundException::class);
        $this->client($transport)->blacklist()->addBySession('xtk_foreign', 'fraud');
    }

    public function testAddBySessionStillInProgressConflict(): void
    {
        $transport = new MockTransport();
        // HTTP 409 maps to ValidationException (the default branch)
        $transport->queueError(409, 'CONFLICT', 'session is still in progress — blacklist after it completes');

        $this->expectException(ValidationException::class);
        $this->client($transport)->blacklist()->addBySession('xtk_live', 'fraud');
    }

    public function testAddBySessionEmptyTokenThrows(): void
    {
        $client = $this->client(new MockTransport());

        $this->expectException(\InvalidArgumentException::class);
        $client->blacklist()->addBySession('', 'fraud');
    }

    public function testAddBySessionEmptyReasonThrows(): void
    {
        $client = $this->client(new MockTransport());

        $this->expectException(\InvalidArgumentException::class);
        $client->blacklist()->addBySession('xtk_abc', '');
    }

    // --- addByImage() ---

    public function testAddByImageReturnsProcessing(): void
    {
        $transport = new MockTransport();
        $transport->queueSuccess(['status' => 'processing']);

        $status = $this->client($transport)->blacklist()->addByImage('aGVsbG8=', 'known fraudster');

        $this->assertSame('processing', $status);

        $req = $transport->getLastRequest();
        $this->assertSame('POST', $req['method']);
        $this->assertStringContainsString('/verify/v1/blacklist/image', $req['url']);

        $body = json_decode($req['body'], true);
        $this->assertSame(['image' => 'aGVsbG8=', 'reason' => 'known fraudster'], $body);
    }

    public function testAddByImageEmptyImageThrows(): void
    {
        $client = $this->client(new MockTransport());

        $this->expectException(\InvalidArgumentException::class);
        $client->blacklist()->addByImage('', 'fraud');
    }

    public function testAddByImageEmptyReasonThrows(): void
    {
        $client = $this->client(new MockTransport());

        $this->expectException(\InvalidArgumentException::class);
        $client->blacklist()->addByImage('aGVsbG8=', '');
    }

    // --- remove() ---

    public function testRemoveSendsDeleteAndReturnsTrue(): void
    {
        $transport = new MockTransport();
        $transport->queueSuccess(['message' => 'blacklist entry removed']);

        $removed = $this->client($transport)->blacklist()->remove(42);

        $this->assertTrue($removed);

        $req = $transport->getLastRequest();
        $this->assertSame('DELETE', $req['method']);
        $this->assertStringContainsString('/verify/v1/blacklist/42', $req['url']);
        $this->assertNull($req['body']);
    }

    public function testRemoveNotFound(): void
    {
        $transport = new MockTransport();
        $transport->queueError(404, 'NOT_FOUND', 'blacklist entry not found');

        $this->expectException(NotFoundException::class);
        $this->client($transport)->blacklist()->remove(999);
    }

    public function testRemoveZeroIdThrows(): void
    {
        $client = $this->client(new MockTransport());

        $this->expectException(\InvalidArgumentException::class);
        $client->blacklist()->remove(0);
    }

    public function testRemoveNegativeIdThrows(): void
    {
        $client = $this->client(new MockTransport());

        $this->expectException(\InvalidArgumentException::class);
        $client->blacklist()->remove(-5);
    }
}
