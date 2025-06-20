<?php
/**
 * 🚀 ИНДЕКСАТОР OPENSEARCH v5.0 - ТОЛЬКО СТАТИЧЕСКИЕ ДАННЫЕ
 * 
 * Индексирует только постоянную информацию о товарах:
 * - Основные данные (название, артикул, SKU)
 * - Бренды и серии
 * - Категории и атрибуты
 * - Изображения и документы
 * 
 * НЕ индексирует:
 * - Цены (загружаются динамически для каждого клиента)
 * - Остатки (загружаются динамически по городам/складам)
 */

require __DIR__ . '/vendor/autoload.php';

use OpenSearch\ClientBuilder;
use App\Core\Database;
use App\Core\Config;

// 🔧 Конфигурация
const BATCH_SIZE = 1000;
const MEMORY_LIMIT = '4G'; // Уменьшили, так как не храним динамические данные
const MAX_EXECUTION_TIME = 3600;
const MAX_OLD_INDICES = 2;

class StaticProductIndexer {
    private $client;
    private $pdo;
    private $processed = 0;
    private $errors = 0;
    private $startTime;
    private $newIndexName;
    private $totalProducts = 0;

    public function __construct() {
        $this->startTime = microtime(true);
        $this->newIndexName = 'products_' . date('Y_m_d_H_i_s');
        
        ini_set('memory_limit', MEMORY_LIMIT);
        ini_set('max_execution_time', MAX_EXECUTION_TIME);
        set_time_limit(0);
        
        echo $this->getHeader();
    }

    /**
     * 🎯 Главный метод запуска
     */
    public function run(): void {
        try {
            $this->initializeConnections();
            $this->analyzeCurrentState();
            $this->createNewIndex();
            $this->indexAllProducts();
            $this->switchAlias();
            $this->cleanupOldIndices();
            $this->showFinalReport();
        } catch (Throwable $e) {
            $this->handleError($e);
        }
    }

    /**
     * 🔌 Инициализация соединений
     */
    private function initializeConnections(): void {
        echo "🔌 === ИНИЦИАЛИЗАЦИЯ ===\n\n";
        
        // OpenSearch
        try {
            $this->client = ClientBuilder::create()
                ->setHosts(['localhost:9200'])
                ->setRetries(3)
                ->build();
                
            $info = $this->client->info();
            echo "✅ OpenSearch подключен: v" . $info['version']['number'] . "\n";
            
            $health = $this->client->cluster()->health();
            echo "📊 Статус кластера: {$health['status']}\n";
            
        } catch (Exception $e) {
            throw new Exception("❌ Ошибка OpenSearch: " . $e->getMessage());
        }

        // База данных
        try {
            $this->pdo = Database::getConnection();
            echo "✅ База данных подключена\n\n";
        } catch (Exception $e) {
            throw new Exception("❌ Ошибка БД: " . $e->getMessage());
        }
    }

    /**
     * 📊 Анализ текущего состояния
     */
    private function analyzeCurrentState(): void {
        echo "📊 === АНАЛИЗ ===\n\n";
        
        // Подсчет товаров
        $this->totalProducts = $this->pdo->query("SELECT COUNT(*) FROM products")->fetchColumn();
        echo "📦 Товаров в БД: {$this->totalProducts}\n";
        
        // Проверка связанных данных
        $brands = $this->pdo->query("SELECT COUNT(DISTINCT brand_id) FROM products WHERE brand_id IS NOT NULL")->fetchColumn();
        $series = $this->pdo->query("SELECT COUNT(DISTINCT series_id) FROM products WHERE series_id IS NOT NULL")->fetchColumn();
        $categories = $this->pdo->query("SELECT COUNT(*) FROM product_categories")->fetchColumn();
        $images = $this->pdo->query("SELECT COUNT(DISTINCT product_id) FROM product_images")->fetchColumn();
        
        echo "🏷️ Брендов используется: $brands\n";
        echo "📚 Серий используется: $series\n";
        echo "📁 Связей с категориями: $categories\n";
        echo "🖼️ Товаров с изображениями: $images\n\n";
    }

    /**
     * 📝 Создание нового индекса
     */
    private function createNewIndex(): void {
        echo "📝 === СОЗДАНИЕ ИНДЕКСА ===\n\n";
        echo "🆕 Имя индекса: {$this->newIndexName}\n";
        
        // Загружаем конфигурацию
        $configFile = __DIR__ . '/products_static_v5.json';
        if (!file_exists($configFile)) {
            // Если нет файла, используем встроенную конфигурацию
            $config = $this->getIndexConfiguration();
            file_put_contents($configFile, json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            echo "📄 Создан файл конфигурации: $configFile\n";
        } else {
            $config = json_decode(file_get_contents($configFile), true);
        }
        
        // Создаем индекс
        try {
            $this->client->indices()->create([
                'index' => $this->newIndexName,
                'body' => $config
            ]);
            echo "✅ Индекс создан\n\n";
        } catch (Exception $e) {
            throw new Exception("❌ Ошибка создания индекса: " . $e->getMessage());
        }
    }

    /**
     * 📦 Индексация всех товаров
     */
    private function indexAllProducts(): void {
        echo "📦 === ИНДЕКСАЦИЯ ===\n\n";
        echo "🔄 Начинаем индексацию {$this->totalProducts} товаров...\n";
        echo "📊 Размер пакета: " . BATCH_SIZE . "\n\n";
        
        $offset = 0;
        $progressBar = $this->initProgressBar();
        
        while ($offset < $this->totalProducts) {
            $products = $this->fetchProductBatch($offset);
            if (empty($products)) break;
            
            $this->indexBatch($products);
            
            $offset += BATCH_SIZE;
            $this->updateProgress($offset);
            
            // Управление памятью
            if ($offset % 10000 === 0) {
                gc_collect_cycles();
            }
        }
        
        echo "\n✅ Индексация завершена!\n\n";
    }

    /**
     * 📥 Получение пакета товаров
     */
    private function fetchProductBatch(int $offset): array {
        $sql = "
            SELECT 
                p.product_id,
                p.external_id,
                p.sku,
                p.name,
                p.description,
                p.unit,
                p.min_sale,
                p.weight,
                p.dimensions,
                p.created_at,
                p.updated_at,
                p.brand_id,
                p.series_id,
                b.name as brand_name,
                s.name as series_name
            FROM products p
            LEFT JOIN brands b ON p.brand_id = b.brand_id
            LEFT JOIN series s ON p.series_id = s.series_id
            ORDER BY p.product_id
            LIMIT :limit OFFSET :offset
        ";
        
        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':limit', BATCH_SIZE, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * 📤 Индексация пакета
     */
    private function indexBatch(array $products): void {
        $bulkData = [];
        
        foreach ($products as $product) {
            // Получаем дополнительные данные
            $product['categories'] = $this->getProductCategories($product['product_id']);
            $product['images'] = $this->getProductImages($product['product_id']);
            $product['attributes'] = $this->getProductAttributes($product['product_id']);
            $product['documents'] = $this->getProductDocuments($product['product_id']);
            
            // Подготавливаем документ для индексации
            $doc = $this->prepareDocument($product);
            
            // Добавляем в bulk
            $bulkData[] = ['index' => ['_index' => $this->newIndexName, '_id' => $product['product_id']]];
            $bulkData[] = $doc;
        }
        
        // Отправляем в OpenSearch
        if (!empty($bulkData)) {
            try {
                $response = $this->client->bulk(['body' => $bulkData]);
                $this->processed += count($products);
                
                if ($response['errors']) {
                    $this->handleBulkErrors($response['items']);
                }
            } catch (Exception $e) {
                $this->errors += count($products);
                error_log("Bulk error: " . $e->getMessage());
            }
        }
    }

    /**
     * 🔨 Подготовка документа для индексации
     */
    private function prepareDocument(array $product): array {
        // Базовые поля
        $doc = [
            'product_id' => (int)$product['product_id'],
            'external_id' => $this->normalizeText($product['external_id']),
            'sku' => $this->normalizeText($product['sku']),
            'name' => $this->normalizeText($product['name']),
            'description' => $this->normalizeText($product['description']),
            'unit' => $product['unit'] ?: 'шт',
            'min_sale' => (int)($product['min_sale'] ?: 1),
            'weight' => (float)($product['weight'] ?: 0),
            'dimensions' => $product['dimensions'],
            'created_at' => $this->formatDate($product['created_at']),
            'updated_at' => $this->formatDate($product['updated_at']),
            
            // Бренд и серия
            'brand_id' => (int)($product['brand_id'] ?: 0),
            'brand_name' => $this->normalizeText($product['brand_name']),
            'series_id' => (int)($product['series_id'] ?: 0),
            'series_name' => $this->normalizeText($product['series_name']),
            
            // Дополнительные данные
            'categories' => $product['categories']['names'] ?? [],
            'category_ids' => $product['categories']['ids'] ?? [],
            'images' => $product['images'],
            'attributes' => $product['attributes'],
            'documents' => $product['documents'],
            
            // Поля для поиска
            'search_text' => $this->buildSearchText($product),
            
            // Данные для автодополнения
            'suggest' => $this->buildSuggestData($product),
            
            // Метрики (если есть)
            'popularity_score' => $this->getPopularityScore($product['product_id']),
            
            // Флаги для фильтрации
            'has_images' => !empty($product['images']),
            'has_description' => !empty($product['description'])
        ];
        
        // Удаляем пустые значения
        return array_filter($doc, function($value) {
            return $value !== null && $value !== '' && $value !== [];
        });
    }

    /**
     * 📁 Получение категорий товара
     */
    private function getProductCategories(int $productId): array {
        $stmt = $this->pdo->prepare("
            SELECT c.category_id, c.name
            FROM product_categories pc
            JOIN categories c ON pc.category_id = c.category_id
            WHERE pc.product_id = ?
        ");
        $stmt->execute([$productId]);
        
        $ids = [];
        $names = [];
        
        while ($row = $stmt->fetch()) {
            $ids[] = (int)$row['category_id'];
            $names[] = $row['name'];
        }
        
        return ['ids' => $ids, 'names' => $names];
    }

    /**
     * 🖼️ Получение изображений товара
     */
    private function getProductImages(int $productId): array {
        $stmt = $this->pdo->prepare("
            SELECT url, alt_text, is_main
            FROM product_images
            WHERE product_id = ?
            ORDER BY is_main DESC, sort_order ASC
        ");
        $stmt->execute([$productId]);
        
        $images = [];
        while ($row = $stmt->fetch()) {
            $images[] = $row['url'];
        }
        
        return $images;
    }

    /**
     * 📋 Получение атрибутов товара
     */
    private function getProductAttributes(int $productId): array {
        $stmt = $this->pdo->prepare("
            SELECT name, value, unit
            FROM product_attributes
            WHERE product_id = ?
            ORDER BY sort_order
        ");
        $stmt->execute([$productId]);
        
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * 📄 Получение документов товара
     */
    private function getProductDocuments(int $productId): array {
        $stmt = $this->pdo->prepare("
            SELECT type, COUNT(*) as count
            FROM product_documents
            WHERE product_id = ?
            GROUP BY type
        ");
        $stmt->execute([$productId]);
        
        $docs = [
            'certificates' => 0,
            'manuals' => 0,
            'drawings' => 0
        ];
        
        while ($row = $stmt->fetch()) {
            $type = $row['type'] . 's'; // certificate -> certificates
            $docs[$type] = (int)$row['count'];
        }
        
        return $docs;
    }

    /**
     * 📈 Получение популярности товара
     */
    private function getPopularityScore(int $productId): float {
        $stmt = $this->pdo->prepare("
            SELECT popularity_score
            FROM product_metrics
            WHERE product_id = ?
        ");
        $stmt->execute([$productId]);
        
        $score = $stmt->fetchColumn();
        return $score !== false ? (float)$score : 0.0;
    }

    /**
     * 🔤 Построение текста для поиска
     */
    private function buildSearchText(array $product): string {
        $parts = [
            $product['name'],
            $product['external_id'],
            $product['sku'],
            $product['brand_name'],
            $product['series_name'],
            $product['description']
        ];
        
        // Добавляем категории
        if (!empty($product['categories']['names'])) {
            $parts = array_merge($parts, $product['categories']['names']);
        }
        
        // Добавляем значения атрибутов
        if (!empty($product['attributes'])) {
            foreach ($product['attributes'] as $attr) {
                $parts[] = $attr['value'];
            }
        }
        
        // Объединяем и нормализуем
        $text = implode(' ', array_filter($parts));
        return $this->normalizeText($text);
    }

    /**
     * 💡 Построение данных для автодополнения
     */
    private function buildSuggestData(array $product): array {
        $suggestions = [];
        
        // Название с высоким приоритетом
        if (!empty($product['name'])) {
            $suggestions[] = [
                'input' => [$product['name']],
                'weight' => 100
            ];
        }
        
        // Артикул
        if (!empty($product['external_id'])) {
            $suggestions[] = [
                'input' => [$product['external_id']],
                'weight' => 95
            ];
        }
        
        // SKU
        if (!empty($product['sku'])) {
            $suggestions[] = [
                'input' => [$product['sku']],
                'weight' => 90
            ];
        }
        
        // Бренд
        if (!empty($product['brand_name'])) {
            $suggestions[] = [
                'input' => [$product['brand_name']],
                'weight' => 70
            ];
        }
        
        return $suggestions;
    }

    /**
     * 🔄 Переключение алиаса
     */
    private function switchAlias(): void {
        echo "🔄 === ПЕРЕКЛЮЧЕНИЕ АЛИАСА ===\n\n";
        
        try {
            $actions = [];
            
            // Проверяем существующие алиасы
            $hasExistingAlias = false;
            try {
                $aliases = $this->client->indices()->getAlias(['name' => 'products_current']);
                if (!empty($aliases)) {
                    $hasExistingAlias = true;
                    echo "📋 Найдены существующие алиасы:\n";
                    foreach (array_keys($aliases) as $oldIndex) {
                        echo "   - $oldIndex\n";
                        $actions[] = ['remove' => ['index' => $oldIndex, 'alias' => 'products_current']];
                    }
                }
            } catch (\Exception $e) {
                // Алиас не существует - это нормально для первого запуска
                echo "ℹ️ Алиас products_current не существует (первый запуск)\n";
            }
            
            // Добавляем новый алиас
            $actions[] = ['add' => ['index' => $this->newIndexName, 'alias' => 'products_current']];
            
            // Выполняем все действия одной транзакцией
            $response = $this->client->indices()->updateAliases([
                'body' => ['actions' => $actions]
            ]);
            
            if ($response['acknowledged'] === true) {
                echo "✅ Алиас products_current успешно " . 
                     ($hasExistingAlias ? "переключен на" : "создан для") . 
                     " {$this->newIndexName}\n";
                
                // Проверяем результат
                sleep(1); // Даем время на применение изменений
                $checkAliases = $this->client->indices()->getAlias(['name' => 'products_current']);
                if (isset($checkAliases[$this->newIndexName])) {
                    echo "✅ Проверка: алиас корректно указывает на новый индекс\n";
                } else {
                    echo "⚠️ Предупреждение: алиас создан, но проверка не прошла\n";
                }
            } else {
                throw new \Exception("Не удалось создать/обновить алиас");
            }
            
        } catch (\Exception $e) {
            echo "❌ ОШИБКА при работе с алиасом: " . $e->getMessage() . "\n";
            echo "🔄 Попытка принудительного создания алиаса...\n";
            
            try {
                // Принудительное создание алиаса
                $this->client->indices()->putAlias([
                    'index' => $this->newIndexName,
                    'name' => 'products_current'
                ]);
                echo "✅ Алиас создан принудительно\n";
            } catch (\Exception $e2) {
                echo "❌ Критическая ошибка: " . $e2->getMessage() . "\n";
                throw $e2;
            }
        }
        
        echo "\n";
    }

    /**
     * 🧹 Очистка старых индексов
     */
    private function cleanupOldIndices(): void {
        echo "🧹 === ОЧИСТКА ===\n\n";
        
        try {
            $indices = $this->client->indices()->get(['index' => 'products_*']);
            $allIndices = array_keys($indices);
            
            // Сортируем по дате (новые первые)
            usort($allIndices, function($a, $b) {
                return strcmp($b, $a);
            });
            
            // Оставляем только нужное количество
            $toDelete = array_slice($allIndices, MAX_OLD_INDICES + 1);
            
            foreach ($toDelete as $index) {
                try {
                    $this->client->indices()->delete(['index' => $index]);
                    echo "🗑️ Удален старый индекс: $index\n";
                } catch (Exception $e) {
                    echo "⚠️ Не удалось удалить $index\n";
                }
            }
            
            if (empty($toDelete)) {
                echo "ℹ️ Старые индексы не требуют очистки\n";
            }
            
        } catch (Exception $e) {
            echo "⚠️ Ошибка при очистке: " . $e->getMessage() . "\n";
        }
        
        echo "\n";
    }

    /**
     * 🎉 Финальный отчет
     */
    private function showFinalReport(): void {
        $duration = microtime(true) - $this->startTime;
        $speed = $this->processed > 0 ? $this->processed / $duration : 0;
        
        echo "🎉 === ГОТОВО! ===\n\n";
        echo "✅ Обработано товаров: {$this->processed}\n";
        echo "❌ Ошибок: {$this->errors}\n";
        echo "⏱️ Время: " . $this->formatTime($duration) . "\n";
        echo "🚀 Скорость: " . round($speed) . " товаров/сек\n";
        echo "💾 Пиковая память: " . round(memory_get_peak_usage(true) / 1024 / 1024, 2) . " MB\n";
        echo "\n";
        echo "🔗 Индекс доступен по алиасу: products_current\n";
        echo "✅ Система готова к работе!\n\n";
    }

    // === Вспомогательные методы ===

    private function normalizeText(?string $text): string {
        if (empty($text)) return '';
        
        // Удаляем лишние пробелы и спецсимволы
        $text = preg_replace('/\s+/', ' ', $text);
        $text = trim($text);
        
        return $text;
    }

    private function formatDate(?string $date): string {
        if (empty($date)) return date('c');
        
        $timestamp = strtotime($date);
        return $timestamp ? date('c', $timestamp) : date('c');
    }

    private function formatTime(float $seconds): string {
        if ($seconds < 60) {
            return round($seconds, 1) . 's';
        } elseif ($seconds < 3600) {
            return floor($seconds / 60) . 'm ' . round($seconds % 60) . 's';
        } else {
            return floor($seconds / 3600) . 'h ' . floor(($seconds % 3600) / 60) . 'm';
        }
    }

    private function updateProgress(int $current): void {
        $percent = round(($current / $this->totalProducts) * 100, 1);
        $bar = str_repeat('█', (int)($percent / 2)) . str_repeat('░', 50 - (int)($percent / 2));
        echo "\r[$bar] $percent% ({$this->processed}/{$this->totalProducts})";
    }

    private function initProgressBar(): void {
        echo "Progress: ";
    }

    private function handleBulkErrors(array $items): void {
        foreach ($items as $item) {
            if (isset($item['index']['error'])) {
                $this->errors++;
                error_log("Index error for ID {$item['index']['_id']}: " . json_encode($item['index']['error']));
            }
        }
    }

    private function handleError(Throwable $e): void {
        echo "\n\n💥 КРИТИЧЕСКАЯ ОШИБКА\n";
        echo "❌ " . $e->getMessage() . "\n";
        echo "📍 " . $e->getFile() . ":" . $e->getLine() . "\n\n";
        
        // Пытаемся удалить частично созданный индекс
        if (!empty($this->newIndexName)) {
            try {
                $this->client->indices()->delete(['index' => $this->newIndexName]);
                echo "🧹 Частично созданный индекс удален\n";
            } catch (Exception $cleanupError) {
                // Игнорируем
            }
        }
        
        exit(1);
    }

    private function getHeader(): string {
        return "
================================================================================
🚀 ИНДЕКСАТОР СТАТИЧЕСКИХ ДАННЫХ OPENSEARCH v5.0
================================================================================
📅 " . date('Y-m-d H:i:s') . "
🖥️ " . gethostname() . "
🐘 PHP " . PHP_VERSION . "
💾 Memory limit: " . ini_get('memory_limit') . "
================================================================================

";
    }

    /**
     * 📄 Конфигурация индекса (упрощенная для статических данных)
     */
    private function getIndexConfiguration(): array {
        return [
            'settings' => [
                'number_of_shards' => 1,
                'number_of_replicas' => 1,
                'index.refresh_interval' => '30s',
                'analysis' => [
                    'char_filter' => [
                        'code_normalizer' => [
                            'type' => 'pattern_replace',
                            'pattern' => '[\\s\\-\\._/,]+',
                            'replacement' => ''
                        ]
                    ],
                    'analyzer' => [
                        'text_analyzer' => [
                            'tokenizer' => 'standard',
                            'filter' => ['lowercase', 'russian_stemmer']
                        ],
                        'code_analyzer' => [
                            'tokenizer' => 'keyword',
                            'char_filter' => ['code_normalizer'],
                            'filter' => ['lowercase']
                        ]
                    ],
                    'filter' => [
                        'russian_stemmer' => [
                            'type' => 'stemmer',
                            'language' => 'russian'
                        ]
                    ]
                ]
            ],
            'mappings' => [
                'properties' => [
                    // Основные поля
                    'product_id' => ['type' => 'long'],
                    'external_id' => [
                        'type' => 'text',
                        'analyzer' => 'code_analyzer',
                        'fields' => [
                            'keyword' => ['type' => 'keyword']
                        ]
                    ],
                    'sku' => [
                        'type' => 'text',
                        'analyzer' => 'code_analyzer',
                        'fields' => [
                            'keyword' => ['type' => 'keyword']
                        ]
                    ],
                    'name' => [
                        'type' => 'text',
                        'analyzer' => 'text_analyzer',
                        'fields' => [
                            'keyword' => ['type' => 'keyword']
                        ]
                    ],
                    'description' => [
                        'type' => 'text',
                        'analyzer' => 'text_analyzer'
                    ],
                    'search_text' => [
                        'type' => 'text',
                        'analyzer' => 'text_analyzer'
                    ],
                    
                    // Характеристики
                    'unit' => ['type' => 'keyword'],
                    'min_sale' => ['type' => 'integer'],
                    'weight' => ['type' => 'float'],
                    'dimensions' => ['type' => 'keyword'],
                    
                    // Бренд и серия
                    'brand_id' => ['type' => 'integer'],
                    'brand_name' => [
                        'type' => 'text',
                        'analyzer' => 'text_analyzer',
                        'fields' => [
                            'keyword' => ['type' => 'keyword']
                        ]
                    ],
                    'series_id' => ['type' => 'integer'],
                    'series_name' => [
                        'type' => 'text',
                        'analyzer' => 'text_analyzer',
                        'fields' => [
                            'keyword' => ['type' => 'keyword']
                        ]
                    ],
                    
                    // Категории
                    'categories' => [
                        'type' => 'text',
                        'analyzer' => 'text_analyzer',
                        'fields' => [
                            'keyword' => ['type' => 'keyword']
                        ]
                    ],
                    'category_ids' => ['type' => 'integer'],
                    
                    // Медиа
                    'images' => ['type' => 'keyword'],
                    'documents' => [
                        'type' => 'object',
                        'properties' => [
                            'certificates' => ['type' => 'integer'],
                            'manuals' => ['type' => 'integer'],
                            'drawings' => ['type' => 'integer']
                        ]
                    ],
                    
                    // Атрибуты
                    'attributes' => [
                        'type' => 'nested',
                        'properties' => [
                            'name' => ['type' => 'keyword'],
                            'value' => ['type' => 'text'],
                            'unit' => ['type' => 'keyword']
                        ]
                    ],
                    
                    // Метрики
                    'popularity_score' => ['type' => 'float'],
                    
                    // Флаги
                    'has_images' => ['type' => 'boolean'],
                    'has_description' => ['type' => 'boolean'],
                    
                    // Даты
                    'created_at' => ['type' => 'date'],
                    'updated_at' => ['type' => 'date'],
                    
                    // Автодополнение
                    'suggest' => [
                        'type' => 'completion',
                        'analyzer' => 'text_analyzer'
                    ]
                ]
            ]
        ];
    }
}

// 🚀 ЗАПУСК
try {
    $indexer = new StaticProductIndexer();
    $indexer->run();
    exit(0);
} catch (Exception $e) {
    echo "\n💥 ФАТАЛЬНАЯ ОШИБКА: " . $e->getMessage() . "\n";
    exit(1);
}