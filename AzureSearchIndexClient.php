<?php

declare(strict_types=1);

/**
 * Azure AI Search index client.
 */
final readonly class AzureSearchIndexClient extends AbstractAzureClient implements SearchIndexClientInterface
{
    private const string INDEX_URL     = 'https://%s.search.windows.net/indexes/%s?api-version=2023-11-01';
    private const string INDEX_DOC_URL = 'https://%s.search.windows.net/indexes/%s/docs/index?api-version=2023-11-01';
    private const string SEARCH_URL    = 'https://%s.search.windows.net/indexes/%s/docs/search?api-version=2023-11-01';

    /**
     * Recommended batch size for indexing operations.
     *
     * Azure AI Search documents two limits for the indexing API:
     *   - Performance recommendation: max 1000 documents per request or 16 MB payload.
     *   - Documented API range: 1-32000 indexing actions per request.
     * Source: https://learn.microsoft.com/en-us/azure/search/search-limits-quotas-capacity
     *
     * The original task specification mentioned 32000 - that is the upper bound of the
     * documented API range, not a recommended target. We use 1000 to stay within the
     * performance recommendation and the 16 MB payload.
     *
     * BUG FIX #2: load() and deleteDocuments() sent ALL documents in one request.
     */
    private const int BATCH_SIZE = 1000;

    /** Timeout for a single indexing or delete batch (seconds). */
    private const int INDEX_TIMEOUT_SECONDS = 60;

    public function __construct(
        private string $azureSearchApiKey,
        private string $azureSearchService,
    ) {}

    /**
     * Creates or updates an Azure Search index.
     *
     * @param array $indexDefinition Full index schema as expected by Azure API.
     *
     * @return string The search query URL for this index.
     *
     * @throws JsonException
     * @throws RuntimeException
     */
    public function createIndex(array $indexDefinition): string
    {
        $url  = sprintf(self::INDEX_URL, $this->azureSearchService, $indexDefinition['name']);
        $body = json_encode($indexDefinition, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        $this->executeRequest(
            'PUT',
            $url,
            $body,
            $this->buildHeaders($body),
            null,
            'creating index',
        );

        // BUG FIX #1: Dead code removed - original had `if ($responseCode >= 400) throw` here.
        // executeRequest() already throws on non-retryable 4xx/5xx, so this check could never trigger.

        return sprintf(self::SEARCH_URL, $this->azureSearchService, $indexDefinition['name']);
    }

    /**
     * Indexes a collection of Product documents.
     *
     * BUG FIX #2: Previously sent all products in a single request regardless of count.
     * Azure AI Search recommends a maximum of 1000 documents per request for performance,
     * and the documented API range is up to 32000 actions per request.
     * Large collections are now chunked into BATCH_SIZE slices.
     *
     * @param Product[] $products
     *
     * @throws JsonException
     * @throws RuntimeException
     */
    public function load(string $indexName, array $products): void
    {
        if (empty($products)) {
            return;
        }

        $url = sprintf(self::INDEX_DOC_URL, $this->azureSearchService, $indexName);

        foreach (array_chunk(array_values($products), self::BATCH_SIZE) as $batch) {
            $documents = array_map(
                static fn (Product $product): array => [
                    '@search.action' => 'mergeOrUpload',
                    ...$product->getIndexDocument(),
                ],
                $batch,
            );

            $body = json_encode(['value' => $documents], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

            $this->executeRequest(
                'POST',
                $url,
                $body,
                $this->buildHeaders($body),
                self::INDEX_TIMEOUT_SECONDS,
                sprintf('indexing %d documents', count($batch)),
            );
        }
    }

    /**
     * Deletes an index and all its documents.
     *
     * BUG FIX #3: Original threw an exception on HTTP 404. Deleting a non-existent index via
     * DELETE /indexes/{name} returns 404 in Azure AI Search. The operation must treat this as
     * success to be idempotent - the index is already gone, which is the desired end state.
     *
     * Note: this is different from document-level deletes (@search.action: delete), which always
     * return HTTP 200 even for non-existent document keys.
     * Source: https://learn.microsoft.com/en-us/azure/search/search-howto-reindex
     *
     * @throws RuntimeException
     */
    public function deleteIndex(string $indexName): void
    {
        $url = sprintf(self::INDEX_URL, $this->azureSearchService, $indexName);

        $this->executeRequest(
            'DELETE',
            $url,
            null,
            $this->buildHeaders(),
            null,
            'deleting index',
            notFoundIsSuccess: true,
        );
    }

    /**
     * Deletes individual documents from an index by their IDs.
     *
     * BUG FIX #2 (same root cause as load()): Previously sent all IDs in one request.
     * Azure AI Search recommends a maximum of 1000 documents per request for performance,
     * and the documented API range is up to 32000 actions per request.
     * Now chunked into BATCH_SIZE slices.
     *
     * BUG FIX #4: Original passed null timeout. Delete batches can be slow for large
     * catalogs; an explicit timeout prevents the process from hanging indefinitely.
     *
     * Idempotent: Azure AI Search always returns HTTP 200 for document-level deletes,
     * even when the document key does not exist in the index.
     * Source: https://learn.microsoft.com/en-us/azure/search/search-howto-reindex
     *
     * @param string[] $documentIds
     *
     * @throws JsonException
     * @throws RuntimeException
     */
    public function deleteDocuments(string $indexName, array $documentIds): void
    {
        if (empty($documentIds)) {
            return;
        }

        $url = sprintf(self::INDEX_DOC_URL, $this->azureSearchService, $indexName);

        foreach (array_chunk(array_values($documentIds), self::BATCH_SIZE) as $batch) {
            $documents = array_map(
                static fn (string $documentId): array => [
                    '@search.action' => 'delete',
                    'id'             => $documentId,
                ],
                $batch,
            );

            $body = json_encode(['value' => $documents], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

            $this->executeRequest(
                'POST',
                $url,
                $body,
                $this->buildHeaders($body),
                self::INDEX_TIMEOUT_SECONDS,
                sprintf('deleting %d documents', count($batch)),
            );
        }
    }

    /**
     * Builds the standard header set for Azure Search requests.
     * Pass the serialized request body to include Content-Type and Content-Length.
     *
     * BUG FIX #5: Content-Length was present in createIndex() but missing from load()
     * and deleteDocuments(). Having one place for  header construction removes the inconsistency.
     * Content-Length is the byte count of the body (strlen is correct here -
     * it counts bytes, not characters, which is what HTTP requires).
     */
    private function buildHeaders(?string $body = null): array
    {
        $headers = [
            'Accept: application/json',
            'api-key: ' . $this->azureSearchApiKey,
        ];

        if ($body !== null) {
            $headers[] = 'Content-Type: application/json';
            $headers[] = 'Content-Length: ' . strlen($body);
        }

        return $headers;
    }
}