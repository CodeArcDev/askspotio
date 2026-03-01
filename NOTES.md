# Code Review - Azure AI Search Client

## Podejście do zadania

Kod potraktowałem jak PR do review. Zapoznałem się z 
całą implementacją i zrobiłem listę "podejrzanych" miejsc. Następnie - zanim
wprowadziłem poprawki - **zweryfikowałem każde założenie w dokumentacji Microsoft Azure AI Search**, 
bo kilka błędów okazało się subtelniejszych niż wyglądało na pierwszy rzut oka.

Weryfikowałem m.in.:
- znaczenie kodów HTTP specyficzną dla Azure AI Search (np. co oznacza 429, a co 503)
- limity batch size
- idempotentność operacji DELETE na indeksach vs. usuwania dokumentów wewnątrz indeksu
- które kody błędów 5xx są przejściowe (retry), a które oznaczają błąd konfiguracji

Źródła użyte do weryfikacji:
- https://learn.microsoft.com/en-us/rest/api/searchservice/http-status-codes
- https://learn.microsoft.com/en-us/azure/search/search-limits-quotas-capacity
- https://learn.microsoft.com/en-us/azure/search/search-howto-reindex
- https://learn.microsoft.com/en-us/azure/search/tutorial-optimize-indexing-push-api

---

## Znalezione i poprawione błędy

### `AzureSearchIndexClient.php`

**BUG FIX #1 - Martwy kod w `createIndex()`**

Po wywołaniu `executeRequest()` znajdował się zbędny warunek `if ($responseCode >= 400) throw`.
Problem polega na tym, że `executeRequest()` już sam rzuca wyjątek dla wszystkich
"nieretryowalnych" kodów 4xx i 5xx - ten check nigdy nie mógł się wykonać. Usunąłem
martwy kod, żeby nie sugerował, że obsługa błędów dzieje się w tym miejscu.

**BUG FIX #2 - Brak podziału na batche w `load()` i `deleteDocuments()`**

Obie metody wysyłały wszystkie dokumenty w jednym żądaniu HTTP bez żadnego podziału.

Azure AI Search API przyjmuje maksymalnie 32000 dokumentów lub 16 MB na request
- cokolwiek nastąpi pierwsze.

`BATCH_SIZE = 1000` to świadomy wybór wydajnościowy: znacznie poniżej twardego limitu,
zgodny z zaleceniami Microsoft ("200–1 000 dokumentów ejst ok dla większości przypadków").
Mniejsze batche łatwiej retryować przy błędzie i nie blokują procesu na długo.

**BUG FIX #3 - `deleteIndex()` traktował HTTP 404 jako błąd**

Usunięcie indeksu który już nie istnieje powinno być traktowane jako sukces - cel został
osiągnięty, niezależnie od tego czy indeks istniał. Dodałem obsługę `notFoundIsSuccess: true`.

Ważna różnica odkryta podczas weryfikacji dokumentacji: to zachowanie dotyczy tylko
operacji `DELETE /indexes/{name}`. Usuwanie **dokumentów** wewnątrz indeksu
(`@search.action: delete`) zawsze zwraca **HTTP 200** - nawet gdy klucz nie istnieje -
więc tam problem nie wystąpuje.

**BUG FIX #4 - Brak timeoutu dla requestów usuwania batchów**

`deleteDocuments()` wywoływało `executeRequest()` bez jawnego timeoutu. Przy dużych
wolumenach mogło to powodować zawieszenie procesu na czas nieokreślony. Dodałem
`timeout: self::BATCH_TIMEOUT_SECONDS`.

**BUG FIX #5 - Nagłówek `Content-Length` ustawiany tylko w `load()`**

Nagłówek był dodawany tylko w jednej metodzie, mimo że każdy request z body
go wymaga.  Logikę tworzenia nagłówków została przeniesiona do `buildHeaders()`.

---

### `AbstractAzureClient.php`

**BUG FIX #6 - `MAX_RETRIES = 9` - wartość zbyt wysoka**

9 prób przy 5-sekundowym opóźnieniu oznacza potencjalnie **45 sekund blokowania procesu**
na jeden request. To niezbyt dobre podejście w synchronicznym procesie. Zmieniłem na
`MAX_RETRIES = 3`.

**BUG FIX #7 - `RETRY_DELAY = 2` - opóźnienie zbyt krótkie**

2 sekundy to za mało przy throttlingu ze strony Azure. Zmieniłem na `5` sekund. 
Wybrałem stały delay zamiast exponential backoff (zalecany przez Azure): synchroniczny, 
jednostkowy proces nie jest scenariuszem "thundering herd" - prostota jest ważniejsza.

**BUG FIX #8 - Wyciek zasobu cURL**

`curl_close()` w ogóle nie było wywoływane - każde wywołanie `executeRequest()`
zostawiało otwarty uchwyt cURL. Dodałem blok `try/finally`, żeby `curl_close()`
zawsze się wykonało - zarówno przy normalnym powrocie, jak i przy wyjątku.

**BUG FIX #9 - Błędna użycie HTTP 429 + brak retry dla HTTP 503 i 409**

To był nieco inny błąd.

W większości API HTTP 429 oznacza rate limiting (zbyt wiele requestów) i powinno być
retryowane po chwili. W Azure AI Search HTTP 429 oznacza co innego: przekroczenie
limitu dokumentów w indeksie (quota). To problem pojemnościowy, a nie przejściowy
- retry nic nie da. Usunąłem 429 z listy kodów do ponowienia.

**Throttling przy dużym obciążeniu sygnalizowany jest przez HTTP 503** - i to właśnie
503 powinno być retryowane. Dodałem 503 do `RETRYABLE_HTTP_CODES`.

Dodałem też HTTP **409** (write conflict przy równoległych operacjach) - może ustąpić
przy ponownej próbie.

**BUG FIX #10 - Błędy 4xx i 5xx serwera nie były retryowane**

Oryginalny kod retryował tylko błędy cURL (sieciowe). Błędy po stronie serwera (np.
HTTP 500, 503) oraz odpowiednie 4xx wymagają tej samej logiki.

Kody **502 i 504 celowo wykluczone** - według dokumentacji Azure oznaczają błędną
konfigurację HTTP vs. HTTPS, a nie błędy przejściowe. Retry byłby tu bezużyteczny.

---

### `Product.php`

**BUG FIX #11 - Regex w `normalizeId()` sprzeczny z komunikatem wyjątku**

Regex `/[^a-zA-Z0-9_\-=]/` przepuszczał znak `=`. Komunikat wyjątku
mówił _"letters, numbers, dashes and underscores"_ - bez `=`. Dwie sprzeczne definicje
dopuszczalnego formatu ID w tym samym miejscu kodu.

Poprawiłem regex do `/[^a-zA-Z0-9_\-]/`, żeby był zgodny z opisem w wyjątku.

---

## Co zostało dodane poza poprawkami

### Komentarze inline

Każda nieoczywista decyzja projektowa jest opisana komentarzem `// BUG FIX #N`
z wyjaśnieniem _co_ zmieniłem i _dlaczego_. Decyzje świadomie odbiegające od
standardowych zaleceń (np. stały delay zamiast exponential backoff) są udokumentowane
z uzasadnieniem. Nieoczywiste zachowania Azure (np. semantyka 429 vs. 503, obsługa 404
przy DELETE) mają wskazane źródła w dokumentacji Microsoft.

### Testy jednostkowe (`AzureSearchIndexClientTest.php`)

Dodałem testy oparte na **proxy pattern** - klasa testowa nadpisuje chronioną metodę
`executeRequest()`, nie zmieniając publicznego kontraktu. Pozwala to testować logikę
walidacji, batching i transformację danych bez prawdziwego połączenia z Azure.

Testy dla `AbstractAzureClient` (logika retry, obsługa timeoutów) są oznaczone
jako pominięte z dokumentacją wymagań: potrzebne jest mockowanie `curl_exec()`
przez `php-mock/php-mock-phpunit` albo refaktoryzacja do `HttpClientInterface`.
Oba warianty opisane są w sekcji §1 poniżej.

---

# Zadania dodatkowe

## §1 - Jak przetestowałbyś tę implementację?

### Problem: curl jest ściśle sprzęgnięty z logiką

`AbstractAzureClient` wywołuje `curl_exec()` bezpośrednio, co uniemożliwia
testowanie logiki retry bez mockowania globalnych funkcji PHP lub warstwy abstrakcji.

#### Opcja A - Krótkoterminowo: `php-mock/php-mock-phpunit`

Biblioteka pozwala mockować globalne funkcje PHP w danym namespace. Daje pełną
kontrolę nad tym, co zwracają `curl_exec()`, `curl_getinfo()` i `curl_error()`:

```php
use phpmock\phpunit\PHPMock;

final class AbstractAzureClientRetryTest extends TestCase
{
    use PHPMock;

    public function test_retries_on_503_and_succeeds_on_third_attempt(): void
    {
        $callCount = 0;

        $curlExec = $this->getFunctionMock('App', 'curl_exec');
        $curlExec->expects($this->exactly(3))
            ->willReturnCallback(function () use (&$callCount): string {
                return ++$callCount < 3 ? '' : '{"value":[]}';
            });

        $curlInfo = $this->getFunctionMock('App', 'curl_getinfo');
        $curlInfo->willReturnCallback(fn () => $callCount < 3 ? 503 : 200);

        $sleepMock = $this->getFunctionMock('App', 'sleep');
        $sleepMock->expects($this->exactly(2)); // przed próbą 2 i 3, nie po ostatniej
    }
}
```

#### Opcja B - Długoterminowo: `HttpClientInterface` (rekomendowane)

Wyciągnięcie wywołania curl do dedykowanej klasy i wstrzyknięcie przez konstruktor.
Na produkcji wstrzykujemy `CurlHttpClient`, w testach - mocka:

```php
interface HttpClientInterface
{
    public function request(string $method, string $url, HttpRequest $request): HttpResponse;
}
```

```php
$httpClient = $this->createMock(HttpClientInterface::class);
$httpClient->expects($this->exactly(3))
    ->method('request')
    ->willReturnOnConsecutiveCalls(
        new HttpResponse('', 503),
        new HttpResponse('', 503),
        new HttpResponse('{"value":[]}', 200),
    );
```

Pozwala też wstrzyknąć `SleeperInterface`, dzięki czemu testy działają w czasie
zerowym - bez rzeczywistych wywołań `sleep()`.

#### Przykłądowe testy

| Scenariusz | Oczekiwany rezultat |
|---|---|
| HTTP 503 × 2, potem 200 | Sukces po 3 próbach |
| HTTP 503 × (MAX_RETRIES+1) | `RuntimeException` |
| HTTP 429 (quota exceeded) | Wyjątek natychmiast - bez retry |
| HTTP 400 Bad Request | Wyjątek natychmiast - bez retry |
| Błąd cURL × 1, potem 200 | Sukces po 2 próbach |
| HTTP 404 + `notFoundIsSuccess=true` | Zwraca bez wyjątku |
| `deleteDocuments([], ...)` | Zero wywołań HTTP |
| 2 500 produktów w `load()` | Dokładnie 3 wywołania HTTP |
| Stały delay przy błędach | `sleep(5)` przed każdą próbą retry |
| Wyjątek w trakcie requestu | `curl_close()` nadal wywołane (brak wycieku) |
| Ostatnia próba nieudana | `sleep()` NIE jest wywoływane przed rzuceniem wyjątku |

---

## §2 - Jak dodałbyś observability (logging, metryki)?

### Logowanie (PSR-3)

Można wstrzyknąć `Psr\Log\LoggerInterface` do `AbstractAzureClient`. Kluczowe zdarzenia:

```php
$this->logger->debug('Azure API request', ['method' => $method, 'url' => $url]);

$this->logger->warning('Azure API retry', [
    'attempt'      => $attempt,
    'action'       => $action,
    'responseCode' => $responseCode,
    'retryIn'      => $delay,
]);

$this->logger->error('Azure API fatal error', [
    'action' => $action,
    'error'  => $error->getMessage(),
]);
```

### Metryki (OpenTelemetry / Prometheus)

```
azure_search_request_duration_seconds{action, status_code}
azure_search_request_total{action, status_code}
azure_search_retry_total{action, reason}         # reason: throttling_503 | server_error | curl_error
azure_search_batch_size{operation}               # liczba dokumentów w batchu
azure_search_documents_indexed_total
azure_search_documents_deleted_total
```

### Distributed tracing

Dodanie nagłówków `traceparent` (W3C Trace Context) do każdego requestu. Pozwala
powiązać błędy po stronie Azure z konkretnymi requestami aplikacji w Jaeger / Azure Monitor.

---

## §3 - Jak obsłużyłbyś timeouty przy bardzo dużych operacjach?

Główny problem: synchronizacja 500 000 produktów nie zmieści się ani w jednym
żądaniu HTTP, ani w jednym procesie PHP (memory limit, `max_execution_time`).

### Opcja 1 - Asynchroniczna kolejka zadań (najlepiej)

Rozbicie operacji na mniejsze joby w kolejce komunikatów (Laravel Queue, RabbitMQ):


Zalety: każdy job ma własny timeout i politykę retry, workery skalowalne horyzontalnie,
błąd jednego batcha nie blokuje pozostałych, postęp widoczny w monitoringu.

### Opcja 2 - PHP Fibers (PHP 8.1+)

Dla mniejszych wolumenów Fibers pozwalają na równoległe wykonywanie batchów bez
zewnętrznej kolejki.

### Opcja 3 - Azure Indexer

W dokumentacji znalazłęm Azure Indexer. 
Dla bardzo dużych zbiorów danych Azure oferuje **Indexer** - mechanizm pull zamiast
push. Konfiguruje się źródło danych (np. Azure SQL, Cosmos DB, Blob Storage) i harmonogram,
a Azure sam pobiera dane i wstawia do indeksu. Nie wymaga kodu synchronizacji po stronie
aplikacji, zarządzanie timeoutami jest po stronie Azure. Ograniczenie: działa tylko z
obsługiwanymi źródłami danych Microsoft, a minimalny interwał odświeżania to 5 minut.

---

## §4 - Co zrobiłbyś inaczej projektując od zera?

### 1. Oddzieliłbym transport HTTP od logiki retry (`HttpClientInterface`)

Opisane w §1. Kluczowa zmiana architektoniczna - testowalność i Single Responsibility.

### 2. Value Objects zamiast surowych tablic

Zamiast `array $indexDefinition` typowany VO:

```php
final readonly class IndexDefinition
{
    public function __construct(
        public string $name,
        /** @var IndexField[] */
        public array  $fields,
        public ?SemanticConfiguration $semantic = null,
    ) {}

    public function toArray(): array { /* ... */ }
}
```

Type safety, autocompletion IDE, walidacja w konstruktorze - błędy wykrywane
w compile time zamiast runtime.

### 3. Result Object zamiast `void` dla operacji indeksowania

```php
final readonly class IndexingResult
{
    public function __construct(
        public int   $succeeded,
        public int   $failed,
        /** @var FailedDocument[] */
        public array $failures,
    ) {}
}
```

Azure zwraca status per-dokument w ciele odpowiedzi 207. Obecna implementacja
to całkowicie ignoruje - jeśli 3 z 1 000 dokumentów nie zaindeksują się,
nie mamy o tym pojęcia.

### 4. `sleep()` → wstrzyknięty `SleeperInterface`

Testy działają w czasie zerowym zamiast czekać 5+ sekund na każdą próbę retry.

### 5. Explicit `RetryPolicy` zamiast hardkodowanych stałych

```php
final readonly class RetryPolicy
{
    public function __construct(
        public int $maxAttempts       = 3,
        public int $baseDelaySeconds  = 5,
        public int $maxDelaySeconds   = 30,
    ) {}
}
```

Różne operacje mogą mieć różne polityki retry bez modyfikowania klasy bazowej.

### 6. `iterable<Product>` zamiast `array $products`

```php
public function load(string $indexName, iterable $products): void
```

Lazy-loading z kursora bazodanowego bez wczytywania całego katalogu do pamięci.
Kluczowe przy 100k+ dokumentach.