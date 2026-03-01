<?php

declare(strict_types=1);

final readonly class Product
{
    public const string PRODUCT_HASH_FIELD_NAME = 'productHash';
    public const string VECTOR_HASH_FIELD_NAME  = 'vectorHash';
    public const string VECTOR_FIELD_NAME       = 'vector';
    public const string ID_FIELD_NAME           = 'id';
    public const int    MAX_FIELD_VALUE_LENGTH   = 1500;

    private function __construct(
        public string $searchEngineId,
        public string $productId,
        public array  $productData,
    ) {}

    /**
     * Creates and normalises a Product ready for Azure Search indexing.     
     * @param string[] $vectorableFields
     */
    public static function create(
        string $searchEngineId,
        string $productId,
        array  $productData,
        array  $vectorableFields,
    ): self {
        $normalized = self::normalizeId($productId);
        $productData[self::ID_FIELD_NAME] = $normalized;

        ksort($productData);

        $vectorString = '';
        $fieldsString = '';

        foreach ($productData as $key => $value) {
            if (in_array($key, $vectorableFields, true)) {
                $vectorString .= $value;
            }

            $fieldsString .= $value;
        }

        $productData[self::PRODUCT_HASH_FIELD_NAME] = md5($fieldsString);
        $productData[self::VECTOR_HASH_FIELD_NAME]  = md5($vectorString);

        return new self($searchEngineId, $normalized, $productData);
    }

    /**
     * Returns the document payload to be sent to Azure Search.
     * Internal hash fields are stripped - they are used only for
     * change-detection in the sync layer, not stored in the index.
     */
    public function getIndexDocument(): array
    {
        $data = $this->productData;
        unset($data[self::PRODUCT_HASH_FIELD_NAME], $data[self::VECTOR_HASH_FIELD_NAME]);

        return $data;
    }

    /**
     * Normalizes a raw product ID by stripping any character that is not a letter,
     * digit, dash, or underscore.
     *
     * BUG FIX: Original regex '/[^a-zA-Z0-9_\-=]/' allowed equals signs ('=') but the
     * exception message said "letters, numbers, dashes and underscores"
     * Removed '=' from the allowed set to match the contract.
     *
     * @throws InvalidArgumentException if the result would be an empty string.
     */
    public static function normalizeId(string $id): string
    {
        $normalized = preg_replace('/[^a-zA-Z0-9_\-]/', '', $id);

        if ($normalized === null || $normalized === '') {
            throw new InvalidArgumentException(
                'Product ID must contain only letters, numbers, dashes and underscores.'
            );
        }

        return $normalized;
    }
}