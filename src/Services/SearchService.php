<?php
namespace App\Services;

use App\Core\Database;
use App\Core\Logger;
use App\Core\Cache;
use OpenSearch\ClientBuilder;

class SearchService
{
    private static ?\OpenSearch\Client $client = null;
    
    public static function search(array $params): array
    {
        $requestId = uniqid('search_', true);
        $startTime = microtime(true);
        
        // ДОБАВЛЯЕМ ДИАГНОСТИКУ
        $diagnostics = [
            'request_id' => $requestId,
            'start_time' => date('Y-m-d H:i:s'),
            'params' => $params
        ];
        
        Logger::info("🔍 [$requestId] Search started", ['params' => $params]);
        
        try {
            $params = self::validateParams($params);
            $diagnostics['validated_params'] = $params;
            
            // Если нет поискового запроса, используем MySQL для листинга
            if (empty($params['q']) || strlen(trim($params['q'])) === 0) {
                Logger::info("📋 [$requestId] Empty query, using MySQL for listing");
                $diagnostics['search_method'] = 'mysql_listing';
                
                $result = self::searchViaMySQL($params);
                $result['diagnostics'] = $diagnostics;
                
                return [
                    'success' => true,
                    'data' => $result
                ];
            }
            
            // Проверяем доступность OpenSearch
            $opensearchAvailable = self::isOpenSearchAvailable();
            $diagnostics['opensearch_available'] = $opensearchAvailable;
            
            if ($opensearchAvailable) {
                Logger::debug("✅ [$requestId] Using OpenSearch");
                $diagnostics['search_method'] = 'opensearch';
                
                try {
                    $result = self::performOpenSearchWithTimeout($params, $requestId);
                    $diagnostics['opensearch_success'] = true;
                    $result['diagnostics'] = $diagnostics;
                    
                    $duration = round((microtime(true) - $startTime) * 1000, 2);
                    Logger::info("✅ [$requestId] OpenSearch completed in {$duration}ms");
                    
                    return [
                        'success' => true,
                        'data' => $result
                    ];
                } catch (\Exception $e) {
                    Logger::warning("⚠️ [$requestId] OpenSearch failed, falling back to MySQL", [
                        'error' => $e->getMessage()
                    ]);
                    $diagnostics['opensearch_error'] = $e->getMessage();
                    $diagnostics['search_method'] = 'mysql_fallback';
                }
            } else {
                Logger::warning("⚠️ [$requestId] OpenSearch unavailable, using MySQL");
                $diagnostics['search_method'] = 'mysql_primary';
            }
            
            // MySQL поиск (основной или fallback)
            $result = self::searchViaMySQL($params);
            $result['diagnostics'] = $diagnostics;
            
            return [
                'success' => true,
                'data' => $result
            ];
            
        } catch (\Exception $e) {
            $duration = round((microtime(true) - $startTime) * 1000, 2);
            Logger::error("❌ [$requestId] Failed after {$duration}ms", [
                'error' => $e->getMessage()
            ]);
            
            $diagnostics['final_error'] = $e->getMessage();
            $diagnostics['search_method'] = 'error_fallback';
            
            // Всегда пробуем MySQL fallback при ошибке
            try {
                Logger::info("🔄 [$requestId] Trying MySQL fallback");
                $result = self::searchViaMySQL($params);
                $result['diagnostics'] = $diagnostics;
                
                return [
                    'success' => true,
                    'data' => $result,
                    'used_fallback' => true
                ];
            } catch (\Exception $fallbackError) {
                Logger::error("❌ [$requestId] MySQL fallback also failed", [
                    'error' => $fallbackError->getMessage()
                ]);
                
                $diagnostics['fallback_error'] = $fallbackError->getMessage();
                
                return [
                    'success' => false,
                    'error' => 'Search service temporarily unavailable',
                    'error_code' => 'SERVICE_UNAVAILABLE',
                    'data' => [
                        'products' => [],
                        'total' => 0,
                        'page' => $params['page'] ?? 1,
                        'limit' => $params['limit'] ?? 20,
                        'diagnostics' => $diagnostics
                    ]
                ];
            }
        }
    }
    
    /**
     * Улучшенный MySQL поиск с поддержкой транслитерации и исправления раскладки
     */
    private static function searchViaMySQL(array $params): array
    {
        $query = $params['q'] ?? '';
        $page = $params['page'];
        $limit = $params['limit'];
        $offset = ($page - 1) * $limit;
        
        try {
            $pdo = Database::getConnection();
            
            // Если нет поискового запроса - просто получаем список товаров
            if (empty($query)) {
                $sql = "SELECT SQL_CALC_FOUND_ROWS 
                        p.product_id, p.external_id, p.sku, p.name, p.description,
                        p.brand_id, p.series_id, p.unit, p.min_sale, p.weight, p.dimensions,
                        b.name as brand_name, s.name as series_name,
                        1 as relevance_score
                        FROM products p
                        LEFT JOIN brands b ON p.brand_id = b.brand_id
                        LEFT JOIN series s ON p.series_id = s.series_id
                        WHERE 1=1";
                
                $bindParams = [];
            } else {
                // Генерируем варианты запроса
                $searchVariants = self::generateSearchVariants($query);
                
                // Строим SQL с учетом всех вариантов
                $whereClauses = [];
                $bindParams = [];
                $paramIndex = 0;
                
                foreach ($searchVariants as $variant) {
                    $exactKey = "exact_{$paramIndex}";
                    $prefixKey = "prefix_{$paramIndex}";
                    $searchKey = "search_{$paramIndex}";
                    
                    $whereClauses[] = "
                        p.external_id = :{$exactKey} OR
                        p.sku = :{$exactKey} OR
                        p.external_id LIKE :{$prefixKey} OR
                        p.sku LIKE :{$prefixKey} OR
                        p.name LIKE :{$searchKey} OR
                        p.description LIKE :{$searchKey} OR
                        b.name LIKE :{$searchKey}
                    ";
                    
                    $bindParams[$exactKey] = $variant;
                    $bindParams[$prefixKey] = $variant . '%';
                    $bindParams[$searchKey] = '%' . $variant . '%';
                    
                    $paramIndex++;
                }
                
                $sql = "SELECT SQL_CALC_FOUND_ROWS 
                        p.product_id, p.external_id, p.sku, p.name, p.description,
                        p.brand_id, p.series_id, p.unit, p.min_sale, p.weight, p.dimensions,
                        b.name as brand_name, s.name as series_name,
                        CASE 
                            WHEN p.external_id = :original_q THEN 1000
                            WHEN p.sku = :original_q THEN 900
                            WHEN p.external_id LIKE :original_prefix THEN 100
                            WHEN p.sku LIKE :original_prefix THEN 90
                            WHEN p.name = :original_q THEN 80
                            WHEN p.name LIKE :original_prefix THEN 50
                            WHEN p.name LIKE :original_search THEN 30
                            ELSE 1
                        END as relevance_score
                        FROM products p
                        LEFT JOIN brands b ON p.brand_id = b.brand_id
                        LEFT JOIN series s ON p.series_id = s.series_id
                        WHERE (" . implode(' OR ', $whereClauses) . ")";
                
                // Добавляем оригинальный запрос для скоринга
                $bindParams['original_q'] = $query;
                $bindParams['original_prefix'] = $query . '%';
                $bindParams['original_search'] = '%' . $query . '%';
            }
            
            // Сортировка
            switch ($params['sort']) {
                case 'name':
                    $sql .= " ORDER BY p.name ASC";
                    break;
                case 'external_id':
                    $sql .= " ORDER BY p.external_id ASC";
                    break;
                case 'popularity':
                    $sql .= " ORDER BY p.product_id DESC";
                    break;
                default:
                    if (!empty($query)) {
                        $sql .= " ORDER BY relevance_score DESC, p.name ASC";
                    } else {
                        $sql .= " ORDER BY p.product_id DESC";
                    }
                    break;
            }
            
            $sql .= " LIMIT :limit OFFSET :offset";
            
            $stmt = $pdo->prepare($sql);
            
            // Привязываем параметры поиска
            foreach ($bindParams as $key => $value) {
                $stmt->bindValue($key, $value);
            }
            
            $stmt->bindValue(':limit', $limit, \PDO::PARAM_INT);
            $stmt->bindValue(':offset', $offset, \PDO::PARAM_INT);
            $stmt->execute();
            
            $products = $stmt->fetchAll();
            $total = $pdo->query("SELECT FOUND_ROWS()")->fetchColumn();
            
            Logger::info("MySQL search completed", [
                'query' => $query,
                'variants' => $searchVariants ?? [],
                'found' => count($products),
                'total' => $total
            ]);
            
            return [
                'products' => $products,
                'total' => (int)$total,
                'page' => $page,
                'limit' => $limit,
                'source' => 'mysql',
                'search_variants' => $searchVariants ?? []
            ];
            
        } catch (\Exception $e) {
            Logger::error('MySQL search failed', ['error' => $e->getMessage()]);
            throw $e;
        }
    }
    
    /**
     * Генерация вариантов поискового запроса
     */
    private static function generateSearchVariants(string $query): array
    {
        $variants = [$query]; // Оригинальный запрос
        
        // 1. Конвертация раскладки клавиатуры RU<->EN
        $layoutConverted = self::convertKeyboardLayout($query);
        if ($layoutConverted !== $query) {
            $variants[] = $layoutConverted;
        }
        
        // 2. Транслитерация RU->EN
        $transliterated = self::transliterate($query);
        if ($transliterated !== $query && $transliterated !== $layoutConverted) {
            $variants[] = $transliterated;
        }
        
        // 3. Обратная транслитерация EN->RU
        $cyrillic = self::toCyrillic($query);
        if ($cyrillic !== $query && !in_array($cyrillic, $variants)) {
            $variants[] = $cyrillic;
        }
        
        // 4. Удаление пробелов и спецсимволов для артикулов
        $normalized = preg_replace('/[^a-zA-Z0-9а-яА-Я]/u', '', $query);
        if ($normalized !== $query && !in_array($normalized, $variants)) {
            $variants[] = $normalized;
        }
        
        return array_unique($variants);
    }
    
    /**
     * Конвертация раскладки клавиатуры
     */
    private static function convertKeyboardLayout(string $text): string
    {
        $ru = 'йцукенгшщзхъфывапролджэячсмитьбю';
        $en = 'qwertyuiop[]asdfghjkl;\'zxcvbnm,.';
        
        $ruUpper = mb_strtoupper($ru);
        $enUpper = strtoupper($en);
        
        // RU -> EN
        $result = strtr($text, $ru . $ruUpper, $en . $enUpper);
        if ($result !== $text) {
            return $result;
        }
        
        // EN -> RU
        return strtr($text, $en . $enUpper, $ru . $ruUpper);
    }
    
    /**
     * Транслитерация RU -> EN
     */
    private static function transliterate(string $text): string
    {
        $rules = [
            'а' => 'a', 'б' => 'b', 'в' => 'v', 'г' => 'g', 'д' => 'd',
            'е' => 'e', 'ё' => 'e', 'ж' => 'zh', 'з' => 'z', 'и' => 'i',
            'й' => 'y', 'к' => 'k', 'л' => 'l', 'м' => 'm', 'н' => 'n',
            'о' => 'o', 'п' => 'p', 'р' => 'r', 'с' => 's', 'т' => 't',
            'у' => 'u', 'ф' => 'f', 'х' => 'h', 'ц' => 'ts', 'ч' => 'ch',
            'ш' => 'sh', 'щ' => 'sch', 'ъ' => '', 'ы' => 'y', 'ь' => '',
            'э' => 'e', 'ю' => 'yu', 'я' => 'ya'
        ];
        
        $text = mb_strtolower($text);
        return strtr($text, $rules);
    }
    
    /**
     * Обратная транслитерация EN -> RU (простая версия)
     */
    private static function toCyrillic(string $text): string
    {
        $rules = [
            'a' => 'а', 'b' => 'б', 'v' => 'в', 'g' => 'г', 'd' => 'д',
            'e' => 'е', 'z' => 'з', 'i' => 'и', 'k' => 'к', 'l' => 'л',
            'm' => 'м', 'n' => 'н', 'o' => 'о', 'p' => 'п', 'r' => 'р',
            's' => 'с', 't' => 'т', 'u' => 'у', 'f' => 'ф', 'h' => 'х',
            'c' => 'ц', 'y' => 'у'
        ];
        
        $text = strtolower($text);
        return strtr($text, $rules);
    }
    
    /**
     * Проверка доступности OpenSearch с кешированием
     */
    private static function isOpenSearchAvailable(): bool
    {
        static $isAvailable = null;
        static $lastCheck = 0;
        static $consecutiveFailures = 0;
        
        // Кеш проверки на 60 секунд при успехе, 10 секунд при неудаче
        $cacheTime = $isAvailable ? 60 : 10;
        
        if ($isAvailable !== null && (time() - $lastCheck) < $cacheTime) {
            return $isAvailable;
        }
        
        try {
            $startTime = microtime(true);
            $client = self::getClient();
            
            // Быстрая проверка ping
            $response = $client->ping();
            
            if ($response) {
                // Дополнительно проверяем здоровье кластера
                $health = $client->cluster()->health([
                    'timeout' => '2s'
                ]);
                
                $isAvailable = in_array($health['status'] ?? 'red', ['green', 'yellow']);
                
                if ($isAvailable) {
                    $consecutiveFailures = 0;
                    Logger::debug("✅ OpenSearch available", [
                        'ping_time_ms' => round((microtime(true) - $startTime) * 1000, 2),
                        'cluster_status' => $health['status'] ?? 'unknown'
                    ]);
                } else {
                    $consecutiveFailures++;
                    Logger::warning("⚠️ OpenSearch cluster not healthy", [
                        'status' => $health['status'] ?? 'unknown'
                    ]);
                }
            } else {
                $isAvailable = false;
                $consecutiveFailures++;
                Logger::warning("❌ OpenSearch ping failed");
            }
            
            $lastCheck = time();
            
        } catch (\Exception $e) {
            $isAvailable = false;
            $lastCheck = time();
            $consecutiveFailures++;
            
            Logger::error("❌ OpenSearch check failed", [
                'error' => $e->getMessage(),
                'consecutive_failures' => $consecutiveFailures
            ]);
            
            // После 5 неудачных попыток увеличиваем интервал проверки
            if ($consecutiveFailures >= 5) {
                $lastCheck = time() - 50; // Будет проверять раз в 10 секунд вместо каждого запроса
            }
        }
        
        return $isAvailable;
    }
    
    private static function getClient(): \OpenSearch\Client
    {
        if (self::$client === null) {
            self::$client = ClientBuilder::create()
                ->setHosts(['localhost:9200'])
                ->setRetries(1)
                ->setConnectionParams([
                    'timeout' => 5,
                    'connect_timeout' => 2
                ])
                ->build();
        }
        return self::$client;
    }
    
    /**
     * Выполнение поиска в OpenSearch с таймаутом
     */
    private static function performOpenSearchWithTimeout(array $params, string $requestId): array
    {
        $body = [
            'timeout' => '10s',
            'size' => $params['limit'],
            'from' => ($params['page'] - 1) * $params['limit'],
            'track_total_hits' => true,
            '_source' => true
        ];
        
        if (!empty($params['q'])) {
            $query = trim($params['q']);
            
            // Нормализуем запрос - заменяем х на x для размеров кабелей
            $normalizedQuery = str_replace(['х', 'Х'], ['x', 'X'], $query);
            
            // Определяем, это поиск по коду/артикулу или по названию
            $isCodeSearch = preg_match('/^[A-Za-z0-9\-\.\_\/\s]+$/u', $normalizedQuery);
            
            // ВАЖНО: Используем bool query с should для более точного поиска
            $body['query'] = [
                'bool' => [
                    'should' => [
                        // Точное совпадение артикула - максимальный приоритет
                        [
                            'term' => [
                                'external_id.keyword' => [
                                    'value' => $query,
                                    'boost' => 100
                                ]
                            ]
                        ],
                        // Точное совпадение SKU
                        [
                            'term' => [
                                'sku.keyword' => [
                                    'value' => $query,
                                    'boost' => 90
                                ]
                            ]
                        ],
                        // Префиксный поиск по артикулу
                        [
                            'prefix' => [
                                'external_id' => [
                                    'value' => strtolower($query),
                                    'boost' => 50
                                ]
                            ]
                        ],
                        // Поиск по названию с фразой
                        [
                            'match_phrase' => [
                                'name' => [
                                    'query' => $query,
                                    'boost' => 30,
                                    'slop' => 2
                                ]
                            ]
                        ],
                        // Поиск по названию обычный
                        [
                            'match' => [
                                'name' => [
                                    'query' => $query,
                                    'boost' => 10,
                                    'fuzziness' => 'AUTO',
                                    'prefix_length' => 2
                                ]
                            ]
                        ],
                        // Поиск по бренду
                        [
                            'match' => [
                                'brand_name' => [
                                    'query' => $query,
                                    'boost' => 5
                                ]
                            ]
                        ]
                    ],
                    'minimum_should_match' => 1
                ]
            ];
            
            // Добавляем минимальный порог релевантности
            $body['min_score'] = 1.0;
            
            // Подсветка
            $body['highlight'] = [
                'fields' => [
                    'name' => ['number_of_fragments' => 0],
                    'external_id' => ['number_of_fragments' => 0],
                    'sku' => ['number_of_fragments' => 0]
                ],
                'pre_tags' => ['<mark>'],
                'post_tags' => ['</mark>']
            ];
        } else {
            $body['query'] = ['match_all' => new \stdClass()];
        }
        
        // Сортировка
        if (!empty($params['q'])) {
            // При поиске сортируем по релевантности
            $body['sort'] = ['_score' => 'desc', 'product_id' => 'asc'];
        } else {
            // Без поиска - по имени или как указано
            switch ($params['sort']) {
                case 'name':
                    $body['sort'] = [['name.keyword' => 'asc']];
                    break;
                case 'external_id':
                    $body['sort'] = [['external_id.keyword' => 'asc']];
                    break;
                default:
                    $body['sort'] = [['product_id' => 'desc']];
                    break;
            }
        }
        
        Logger::debug("[$requestId] OpenSearch query", ['body' => json_encode($body)]);
        
        try {
            $response = self::getClient()->search([
                'index' => 'products_current',
                'body' => $body
            ]);
            
            $products = [];
            foreach ($response['hits']['hits'] ?? [] as $hit) {
                $product = $hit['_source'];
                
                // Добавляем подсветку
                if (isset($hit['highlight'])) {
                    $product['_highlight'] = $hit['highlight'];
                }
                
                $product['_score'] = $hit['_score'] ?? 0;
                $products[] = $product;
            }
            
            $total = $response['hits']['total']['value'] ?? 0;
            
            Logger::info("[$requestId] OpenSearch results", [
                'query' => $params['q'],
                'total' => $total,
                'returned' => count($products),
                'max_score' => $response['hits']['max_score'] ?? 0
            ]);
            
            return [
                'products' => $products,
                'total' => $total,
                'page' => $params['page'],
                'limit' => $params['limit'],
                'source' => 'opensearch',
                'max_score' => $response['hits']['max_score'] ?? 0
            ];
            
        } catch (\Exception $e) {
            Logger::error("[$requestId] OpenSearch query failed", [
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }
    
    private static function validateParams(array $params): array
    {
        return [
            'q' => trim($params['q'] ?? ''),
            'page' => max(1, (int)($params['page'] ?? 1)),
            'limit' => min(100, max(1, (int)($params['limit'] ?? 20))),
            'city_id' => (int)($params['city_id'] ?? 1),
            'sort' => $params['sort'] ?? 'relevance',
            'user_id' => $params['user_id'] ?? null
        ];
    }
}