<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

// ---------------------------------------------------------------------------
// Product tests
// ---------------------------------------------------------------------------

#[CoversClass(Product::class)]
final class ProductTest extends TestCase
{
    #[Test]
    public function normalizeId_stripsInvalidCharacters(): void
    {
        self::assertSame('abc-123_AB', Product::normalizeId('abc-123_A=B!@#$%^'));
    }

    #[Test]
    public function normalizeId_throwsOnEmptyResult(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Product::normalizeId('!!!');
    }

    #[Test]
    public function create_computesDeterministicHashesRegardlessOfInsertionOrder(): void
    {
        $p1 = Product::create('svc', 'prod-1', ['title' => 'Foo', 'sku' => 'X'], ['title']);
        $p2 = Product::create('svc', 'prod-1', ['sku' => 'X', 'title' => 'Foo'], ['title']);

        self::assertSame(
            $p1->productData[Product::PRODUCT_HASH_FIELD_NAME],
            $p2->productData[Product::PRODUCT_HASH_FIELD_NAME],
        );
    }

    #[Test]
    public function getIndexDocument_stripsInternalHashFilds(): void
    {
        $product = Product::create('svc', 'id-1', ['title' => 'Test'], ['title']);
        $doc     = $product->getIndexDocument();

        self::assertArrayNotHasKey(Product::PRODUCT_HASH_FIELD_NAME, $doc);
        self::assertArrayNotHasKey(Product::VECTOR_HASH_FIELD_NAME, $doc);
    }

    #[Test]
    public function getIndexDocument_containsNormalisedId(): void
    {
        $product = Product::create('svc', 'raw!!id', ['title' => 'Test'], []);

        self::assertSame('rawid', $product->getIndexDocument()[Product::ID_FIELD_NAME]);
    }

    #[Test]
    public function vectorHash_changesWhenVectorableFieldChanges(): void
    {
        $p1 = Product::create('svc', 'p1', ['title' => 'A', 'sku' => 'X'], ['title']);
        $p2 = Product::create('svc', 'p1', ['title' => 'B', 'sku' => 'X'], ['title']);

        self::assertNotSame(
            $p1->productData[Product::VECTOR_HASH_FIELD_NAME],
            $p2->productData[Product::VECTOR_HASH_FIELD_NAME],
        );
    }

    #[Test]
    public function vectorHash_doesNotChangeWhenOnlyNonVectorableFieldChanges(): void
    {
        $p1 = Product::create('svc', 'p1', ['title' => 'A', 'sku' => 'X'], ['title']);
        $p2 = Product::create('svc', 'p1', ['title' => 'A', 'sku' => 'Y'], ['title']);

        self::assertSame(
            $p1->productData[Product::VECTOR_HASH_FIELD_NAME],
            $p2->productData[Product::VECTOR_HASH_FIELD_NAME],
        );
    }
}

// ---------------------------------------------------------------------------
// AzureSearchIndexClient tests
//
// We test via a proxy subclass that overrides executeRequest() so no real
// HTTP calls are made. The proxy simulates the real executeRequest() contract:
// it throws on 4xx/5xx (except 404 when $notFoundIsSuccess is true) and
// returns the stubbed [body, code] tuple on success.
// ---------------------------------------------------------------------------

final class AzureSearchIndexClientTestProxy extends AzureSearchIndexClient
{
    /** Pre-programmed responses consumed in FIFO order: [body, statusCode] */
    public array $responses = [];

    /** Every executeRequest() call is recorded here for assertions. */
    public array $capturedCalls = [];

    protected function executeRequest(
        string $method,
        string $url,
        ?string $body,
        array $headers,
        ?int $timeoutSeconds,
        string $action,
        bool $notFoundIsSuccess = false,
    ): array {
        $this->capturedCalls[] = compact('method', 'url', 'body', 'headers', 'timeoutSeconds', 'action');

        $response = array_shift($this->responses);
        if ($response === null) {
            throw new \LogicException('No more stubbed responses configured.');
        }

        [$responseBody, $responseCode] = $response;

        if ($responseCode === 404 && $notFoundIsSuccess) {
            return [$responseBody, $responseCode];
        }

        if ($responseCode >= 400) {
            throw new RuntimeException("HTTP $responseCode: $responseBody");
        }

        return [$responseBody, $responseCode];
    }
}

#[CoversClass(AzureSearchIndexClient::class)]
final class AzureSearchIndexClientTest extends TestCase
{
    private AzureSearchIndexClientTestProxy $client;

    protected function setUp(): void
    {
        $this->client = new AzureSearchIndexClientTestProxy('test-api-key', 'my-service');
    }

    // -----------------------------------------------------------------------
    // createIndex()
    // -----------------------------------------------------------------------

    #[Test]
    public function createIndex_returnsSearchUrlForIndex(): void
    {
        $this->client->responses = [['{}', 201]];

        $url = $this->client->createIndex(['name' => 'products']);

        self::assertStringContainsString('products/docs/search', $url);
        self::assertStringContainsString('my-service', $url);
    }

    #[Test]
    public function createIndex_usesPutMethod(): void
    {
        $this->client->responses = [['{}', 201]];
        $this->client->createIndex(['name' => 'products']);

        self::assertSame('PUT', $this->client->capturedCalls[0]['method']);
    }

    #[Test]
    public function createIndex_sendsContentLengthHeader(): void
    {
        $this->client->responses = [['{}', 201]];
        $this->client->createIndex(['name' => 'products']);

        $headers = $this->client->capturedCalls[0]['headers'];
        $found   = array_filter($headers, static fn ($h) => str_starts_with($h, 'Content-Length:'));

        self::assertNotEmpty($found, 'Content-Length header must be present on PUT requests');
    }

    // -----------------------------------------------------------------------
    // load()
    // -----------------------------------------------------------------------

    #[Test]
    public function load_doesNothingForEmptyArray(): void
    {
        $this->client->load('products', []);

        self::assertEmpty($this->client->capturedCalls);
    }

    #[Test]
    public function load_sendsSingleRequestForSmallBatch(): void
    {
        $this->client->responses = [['{"value":[]}', 200]];
        $this->client->load('products', $this->makeProducts(5));

        self::assertCount(1, $this->client->capturedCalls);
    }

    #[Test]
    public function load_chunks2500ProductsInto3Batches(): void
    {
        $this->client->responses = array_fill(0, 3, ['{"value":[]}', 200]);
        $this->client->load('products', $this->makeProducts(2500));

        self::assertCount(3, $this->client->capturedCalls);
    }

    #[Test]
    public function load_setsIndexingTimeoutOf60Seconds(): void
    {
        $this->client->responses = [['{"value":[]}', 200]];
        $this->client->load('products', $this->makeProducts(1));

        self::assertSame(60, $this->client->capturedCalls[0]['timeoutSeconds']);
    }

    #[Test]
    public function load_setsContentLengthHeader(): void
    {
        $this->client->responses = [['{"value":[]}', 200]];
        $this->client->load('products', $this->makeProducts(2));

        $headers = $this->client->capturedCalls[0]['headers'];
        $found   = array_filter($headers, static fn ($h) => str_starts_with($h, 'Content-Length:'));

        self::assertNotEmpty($found, 'Content-Length header must be present on POST requests');
    }

    #[Test]
    public function load_usesMergeOrUploadActionForEachDocument(): void
    {
        $this->client->responses = [['{"value":[]}', 200]];
        $this->client->load('products', $this->makeProducts(3));

        $body = json_decode($this->client->capturedCalls[0]['body'], true);
        foreach ($body['value'] as $doc) {
            self::assertSame('mergeOrUpload', $doc['@search.action']);
        }
    }

    // -----------------------------------------------------------------------
    // deleteDocuments()
    // -----------------------------------------------------------------------

    #[Test]
    public function deleteDocuments_doesNothingForEmptyArray(): void
    {
        $this->client->deleteDocuments('products', []);

        self::assertEmpty($this->client->capturedCalls);
    }

    #[Test]
    public function deleteDocuments_chunks3200IdsInto4Batches(): void
    {
        $ids = array_map(static fn ($i) => "id-$i", range(1, 3200));
        $this->client->responses = array_fill(0, 4, ['{"value":[]}', 200]);

        $this->client->deleteDocuments('products', $ids);

        self::assertCount(4, $this->client->capturedCalls);
    }

    #[Test]
    public function deleteDocuments_usesDeleteActionForEachDocument(): void
    {
        $this->client->responses = [['{"value":[]}', 200]];
        $this->client->deleteDocuments('products', ['id-1', 'id-2']);

        $body = json_decode($this->client->capturedCalls[0]['body'], true);
        foreach ($body['value'] as $doc) {
            self::assertSame('delete', $doc['@search.action']);
        }
    }

    #[Test]
    public function deleteDocuments_setsExplicitTimeout(): void
    {
        $this->client->responses = [['{"value":[]}', 200]];
        $this->client->deleteDocuments('products', ['id-1']);

        self::assertNotNull($this->client->capturedCalls[0]['timeoutSeconds']);
    }

    #[Test]
    public function deleteDocuments_setsContentLengthHeader(): void
    {
        $this->client->responses = [['{"value":[]}', 200]];
        $this->client->deleteDocuments('products', ['id-1']);

        $headers = $this->client->capturedCalls[0]['headers'];
        $found   = array_filter($headers, static fn ($h) => str_starts_with($h, 'Content-Length:'));

        self::assertNotEmpty($found);
    }

    // -----------------------------------------------------------------------
    // deleteIndex()
    // -----------------------------------------------------------------------

    #[Test]
    public function deleteIndex_usesDeleteMethod(): void
    {
        $this->client->responses = [['', 204]];
        $this->client->deleteIndex('products');

        self::assertSame('DELETE', $this->client->capturedCalls[0]['method']);
    }

    #[Test]
    public function deleteIndex_doesNotThrowWhenIndexAlreadyGone(): void
    {
        // Azure returns 404 when the index does not exist - must be idempotent.
        $this->client->responses = [['{"error":{"code":"IndexNotFound"}}', 404]];

        $this->expectNotToPerformAssertions();
        $this->client->deleteIndex('already-deleted-index');
    }

    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------

    /** @return Product[] */
    private function makeProducts(int $count): array
    {
        return array_map(
            static fn ($i) => Product::create(
                'svc',
                "product-$i",
                ['title' => "Product $i", 'sku' => "SKU-$i"],
                ['title'],
            ),
            range(1, $count),
        );
    }
}

// ---------------------------------------------------------------------------
// AbstractAzureClient contract tests
//
// The retry/sleep logic in AbstractAzureClient cannot be exercised without
// either (a) mocking global curl_* functions via php-mock, or (b) replacing
// curl with an injectable HttpClientInterface. The tests below are marked
// skipped and serve as executable specification. See NOTES.md §1.
// ---------------------------------------------------------------------------

#[CoversClass(AbstractAzureClient::class)]
final class AbstractAzureClientContractTest extends TestCase
{
    #[Test]
    public function retriesOnThrottlingAndEventuallySucceeds(): void
    {
        // Given: Azure AI Search returns 503 (throttling) twice, then 200.
        // Expect: method returns the 200 response without throwing,
        //         and exactly 3 HTTP calls were made.
        $this->markTestSkipped('Requires php-mock or HttpClientInterface injection. See NOTES.md §1.');
    }

    #[Test]
    public function throwsAfterAllRetriesExhaustedOn503(): void
    {
        // Given: Azure AI Search returns 503 for all attempts (MAX_RETRIES + 1 times).
        // Expect: RuntimeException thrown, last error message includes HTTP 503.
        $this->markTestSkipped('Requires php-mock or HttpClientInterface injection. See NOTES.md §1.');
    }

    #[Test]
    public function noSleepOnFinalFailedAttempt(): void
    {
        // Given: every attempt fails (cURL error or retryable HTTP code).
        // Expect: sleep() is NOT called after the last attempt - only before retries.
        // Rationale: sleeping before throwing is wasteful and increases latency for callers.
        $this->markTestSkipped('Requires php-mock or HttpClientInterface injection. See NOTES.md §1.');
    }

    #[Test]
    public function respectsRetryAfterHeaderOn429(): void
    {
        // Given: Azure returns 429 with "Retry-After: 10" header.
        // Expect: sleep(10) is called, not the default RETRY_DELAY_SECONDS value.
        $this->markTestSkipped('Requires php-mock or HttpClientInterface injection. See NOTES.md §1.');
    }

    #[Test]
    public function flatDelayAppliedForCurlErrorsAnd5xx(): void
    {
        // Given: cURL fails 3 times then succeeds.
        // Expect: sleep(5) called before each retry - flat delay, not exponential.
        $this->markTestSkipped('Requires php-mock or HttpClientInterface injection. See NOTES.md §1.');
    }

    #[Test]
    public function curlHandleIsClosedEvenWhenExceptionIsThrown(): void
    {
        // Given: executeRequest throws a RuntimeException (e.g. non-retryable 4xx).
        // Expect: curl_close() was still called - no resource leak.
        $this->markTestSkipped('Requires php-mock or HttpClientInterface injection. See NOTES.md §1.');
    }
}