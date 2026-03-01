<?php

declare(strict_types=1);

/**
 * Abstract base client for Azure AI Search API communication.
 *
 * Handles HTTP execution with retry logic for transient errors
 * and proper resource cleanup.
 */
abstract readonly class AbstractAzureClient
{
    /**
     * Maximum number of retry attempts after the initial request.
     * BUG FIX #6: Original had MAX_RETRIES = 9 - excessive, wastes ~45s on permanent errors.
     * 3 retries is standard practice; beyond that, the error is likely permanent.
     */
    private const int MAX_RETRIES = 3;

    /**
     * Flat delay in seconds before each retry attempt.
     * BUG FIX #7: Original had RETRY_DELAY = 2s - too short.
     * Azure AI Search documentation recommends waiting before retrying 503 responses.
     * Source: https://learn.microsoft.com/en-us/azure/search/search-how-to-large-index
     */
    private const int RETRY_DELAY_SECONDS = 5;

    /**
     * HTTP status codes considered transient and safe to retry.
     * Source: https://learn.microsoft.com/en-us/rest/api/searchservice/http-status-codes
     *
     *   503 - Service Unavailable: system under heavy load. Azure docs explicitly say to retry.
     *   500 - Internal Server Error: transient server-side failure.
     *   409 - Conflict: two processes updated the same document simultaneously. Retryable.
     *
     * Deliberately excluded:
     *   429 - In Azure AI Search, HTTP 429 means document index quota exceeded - a capacity
     *          problem, not rate limiting. Retrying does not help.
     *   422 - Per-document code inside a 207 Multi-Status response body, not an HTTP-level
     *          status. Means index temporarily unavailable due to 'allowIndexDowntime' flag.
     *          Not handled here; would require parsing the 207 response body per document.
     *   502 - Azure docs state this occurs specifically when HTTP is used instead of HTTPS.
     *          A configuration error, not a transient failure. Retrying will not help.
     *   504 - Same as 502: HTTP vs HTTPS misconfiguration per Azure docs.
     */
    private const array RETRYABLE_HTTP_CODES = [409, 500, 503];

    /**
     * @return array{0: string, 1: int}
     *
     * @throws RuntimeException on unrecoverable error or max retries exceeded
     */
    protected function executeRequest(
        string $method,
        string $url,
        ?string $body,
        array $headers,
        ?int $timeoutSeconds,
        string $action,
        bool $notFoundIsSuccess = false,
    ): array {
        $lastError = null;

        for ($attempt = 0; $attempt <= self::MAX_RETRIES; $attempt++) {
            // BUG FIX #8: curl handle was never closed - resource leak on every request.
            // try/finally guarantees curl_close() runs even when an exception is thrown.
            $curl = curl_init($url);

            try {
                $options = [
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_HTTPHEADER     => $headers,
                ];

                if (null !== $timeoutSeconds) {
                    $options[CURLOPT_TIMEOUT] = $timeoutSeconds;
                }

                if ($method === 'POST') {
                    $options[CURLOPT_POST] = true;
                } else {
                    $options[CURLOPT_CUSTOMREQUEST] = $method;
                }

                if (null !== $body) {
                    $options[CURLOPT_POSTFIELDS] = $body;
                }

                curl_setopt_array($curl, $options);

                $responseBody = curl_exec($curl);
                $responseCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);

                // -- cURL transport-level failure (DNS, connection refused, timeout…) --
                if (false === $responseBody) {
                    $lastError = new RuntimeException(sprintf(
                        'cURL error when %s (%s %s): %s',
                        $action,
                        $method,
                        $url,
                        curl_error($curl),
                    ));

                    $this->sleepBeforeRetry($attempt, $this->resolveRetryDelay());
                    continue;
                }

                // BUG FIX #9: The original code threw immediately on any response >= 400,
                // which meant 503 (Azure AI Search throttling) and 409 (write conflict) were
                // never retried. Azure AI Search documentation states both should be retried.
                // Source: https://learn.microsoft.com/en-us/rest/api/searchservice/http-status-codes
                //
                // BUG FIX #10: 5xx server errors (500) were also never retried.
                //
                // See RETRYABLE_HTTP_CODES for including excluded codes.
                if (in_array($responseCode, self::RETRYABLE_HTTP_CODES, true)) {
                    $lastError = new RuntimeException(sprintf(
                        'Retryable HTTP %d when %s (%s %s): %s',
                        $responseCode,
                        $action,
                        $method,
                        $url,
                        $responseBody,
                    ));

                    $this->sleepBeforeRetry($attempt, $this->resolveRetryDelay());
                    continue;
                }

                // HTTP 404 treated as success for index deletion only (see AzureSearchIndexClient BUG FIX #3).
                // Note: document-level deletes (@search.action: delete) always return HTTP 200 even for
                // non-existent keys - idempotency is built into the API at that level.
                // This path is only reached when deleteIndex() passes notFoundIsSuccess: true.
                if ($responseCode === 404 && $notFoundIsSuccess) {
                    return [$responseBody, $responseCode];
                }

                // -- Non-retryable error - fail immediately ------------------------
                if ($responseCode >= 400) {
                    throw new RuntimeException(sprintf(
                        'HTTP error when %s (%s %s): HTTP %d - %s',
                        $action,
                        $method,
                        $url,
                        $responseCode,
                        $responseBody,
                    ));
                }

                return [$responseBody, $responseCode];

            } finally {
                curl_close($curl);
            }
        }

        throw $lastError ?? new RuntimeException(
            sprintf('Unable to %s after %d attempts.', $action, self::MAX_RETRIES + 1),
        );
    }

    /**
     * Sleeps only when there are remaining attempts to avoid a pointless delay
     * on the final failed attempt before throwing.
     */
    private function sleepBeforeRetry(int $attempt, int $delay): void
    {
        if ($attempt < self::MAX_RETRIES) {
            sleep($delay);
        }
    }

    /**
     * Returns a flat delay before each retry attempt.
     *
     * Microsoft recommends exponential backoff for 503 retries in their tutorial:
     * https://learn.microsoft.com/en-us/azure/search/tutorial-optimize-indexing-push-api
     *
     * We consciously use a flat delay because:
     * - This is a single sync process, not a thundering herd scenario where backoff
     *   would help distribute load across many clients.
     * - If Azure AI Search returns 503 due to a rolling restart, it typically recovers
     *   quickly. Backoff would add unnecessary latency (5s, 10s, 20s) for no benefit.
     * - A flat, predictable delay is simpler to reason about and easier to tune.
     *
     * If a use case arises that requires backoff, RetryPolicy (see NOTES.md §4)
     * is the right place to make it configurable.
     */
    private function resolveRetryDelay(): int
    {
        return self::RETRY_DELAY_SECONDS;
    }
}