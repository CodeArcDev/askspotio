<?php

declare(strict_types=1);

interface SearchIndexClientInterface
{
    /**
     * Creates or updates an Azure Search index schema.
     *
     * @param  array  $indexDefinition Full index definition per Azure Search API spec.
     * @return string The search query URL for this index.
     */
    public function createIndex(array $indexDefinition): string;

    /**
     * Indexes (upsert) a collection of Product documents.
     * Large collections are automatically chunked into batches.
     *
     * @param Product[] $products
     */
    public function load(string $indexName, array $products): void;

    /**
     * Deletes an index and all of its documents.
     * Idempotent: no error if the index does not exist.
     */
    public function deleteIndex(string $indexName): void;

    /**
     * Deletes individual documents from an index by their IDs.
     * Idempotent: silently ignores IDs that do not exist.
     * Large collections are automatically chunked into batches.
     *
     * @param string[] $documentIds
     */
    public function deleteDocuments(string $indexName, array $documentIds): void;
}
