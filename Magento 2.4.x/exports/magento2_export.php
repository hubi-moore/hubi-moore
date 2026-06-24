<?php
/**
 * Magento 2 — Eksport produktów do CSV
 *
 * Eksportuje produkty z kategorii: Kamień naturalny (ID: 80) i Płytki ceramiczne (ID: 92)
 * Tylko produkty włączone (status = 1)
 * Pomija: Dodatki, Armatura, Pakiety, Przedsprzedaż, Archiwa
 *
 * Uruchomienie:
 *   php magento2_export.php > export.csv
 *
 * lub z logiem błędów:
 *   php magento2_export.php > export.csv 2> errors.log
 */

// ============================================================
// KONFIGURACJA — dostosuj do swojego środowiska
// ============================================================
define('DB_HOST',   'localhost');
define('DB_USER',   'magento_user');   // użytkownik bazy danych
define('DB_PASS',   'magento_pass');   // hasło
define('DB_NAME',   'magento');        // nazwa bazy danych
define('DB_PREFIX', '');               // prefix tabel, np. 'mag_' lub zostaw puste

// ID kategorii głównych do eksportu (z screenshota)
define('CAT_KAMIEN',  80);  // Kamień naturalny
define('CAT_PLYTKI',  92);  // Płytki ceramiczne

// ID kategorii wykluczonych (produkty należące do tych kategorii są pomijane)
$EXCLUDED_CAT_IDS = [76, 71, 128, 133, 147, 169, 170, 171, 174];
// 76  = Dodatki
// 71  = Armatura
// 128 = Pakiety
// 133 = Przedsprzedaż
// 147 = Archiwum
// 169 = Archiwum-PAK
// 170 = Archiwum-PS
// 171 = Archiwum-RM
// 174 = Archiwum-OFF

// ============================================================

$pdo = new PDO(
    'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
    DB_USER,
    DB_PASS,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);

$p = DB_PREFIX;

// Pobierz store_id dla store default (widok 0 = admin/global)
$storeId = 0;

// ============================================================
// Funkcja pomocnicza: pobierz wartość atrybutu (varchar/text/decimal/int)
// ============================================================
function getAttrId(PDO $pdo, string $prefix, string $code): ?int {
    $stmt = $pdo->prepare("SELECT attribute_id FROM {$prefix}eav_attribute WHERE attribute_code = ? AND entity_type_id = 4");
    $stmt->execute([$code]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ? (int)$row['attribute_id'] : null;
}

// ============================================================
// Pobierz attribute_id dla każdego potrzebnego atrybutu
// ============================================================
$attrCodes = [
    'name', 'description', 'short_description', 'status',
    'typ_kamienia', 'kolor', 'length', 'width', 'thickness',
    'rodzaj_powierzchni', 'mgs_brand', 'weight',
    'waga_opakowania', 'jed'
];

$attrIds = [];
foreach ($attrCodes as $code) {
    $id = getAttrId($pdo, $p, $code);
    if ($id === null) {
        fwrite(STDERR, "UWAGA: Nie znaleziono atrybutu '$code' — kolumna będzie pusta.\n");
    }
    $attrIds[$code] = $id;
}

// ============================================================
// Pobierz produkty należące do kategorii Kamień naturalny LUB Płytki ceramiczne
// (włącznie z podkategoriami) i wyklucz wyłączone produkty
// ============================================================

// Znajdź wszystkie podkategorie obu kategorii głównych (rekurencyjnie)
function getSubcategoryIds(PDO $pdo, string $prefix, int $parentId): array {
    $stmt = $pdo->prepare("
        SELECT e.entity_id
        FROM {$prefix}catalog_category_entity e
        JOIN {$prefix}catalog_category_entity_varchar v
            ON v.entity_id = e.entity_id
            AND v.attribute_id = (
                SELECT attribute_id FROM {$prefix}eav_attribute
                WHERE attribute_code = 'name' AND entity_type_id = 3
            )
        WHERE e.path LIKE (
            SELECT CONCAT(path, '/%')
            FROM {$prefix}catalog_category_entity
            WHERE entity_id = ?
        )
        OR e.entity_id = ?
    ");
    $stmt->execute([$parentId, $parentId]);
    return array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'entity_id');
}

$catIds = array_merge(
    getSubcategoryIds($pdo, $p, CAT_KAMIEN),
    getSubcategoryIds($pdo, $p, CAT_PLYTKI)
);
$catIds = array_unique($catIds);

if (empty($catIds)) {
    fwrite(STDERR, "BŁĄD: Nie znaleziono kategorii. Sprawdź ID kategorii w konfiguracji.\n");
    exit(1);
}

$inPlaceholders = implode(',', array_fill(0, count($catIds), '?'));

// Pobierz entity_id produktów z tych kategorii (tylko enabled = status attr = 1)
// i wyklucz produkty należące do kategorii z listy wykluczeń
$statusAttrId = $attrIds['status'];

$exclPlaceholders = implode(',', array_fill(0, count($EXCLUDED_CAT_IDS), '?'));

$sql = "
    SELECT DISTINCT p.entity_id, p.sku
    FROM {$p}catalog_product_entity p
    JOIN {$p}catalog_category_product cp ON cp.product_id = p.entity_id
    JOIN {$p}catalog_product_entity_int st
        ON st.entity_id = p.entity_id
        AND st.attribute_id = $statusAttrId
        AND st.store_id IN (0, 1)
    WHERE cp.category_id IN ($inPlaceholders)
      AND st.value = 1
      AND p.entity_id NOT IN (
          SELECT product_id
          FROM {$p}catalog_category_product
          WHERE category_id IN ($exclPlaceholders)
      )
    ORDER BY p.entity_id
";

$stmt = $pdo->prepare($sql);
$stmt->execute(array_merge($catIds, $EXCLUDED_CAT_IDS));
$products = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (empty($products)) {
    fwrite(STDERR, "UWAGA: Brak produktów spełniających kryteria.\n");
    exit(0);
}

fwrite(STDERR, "Znaleziono produktów: " . count($products) . "\n");

// ============================================================
// Funkcja: pobierz wartość atrybutu dla produktu
// Obsługuje varchar, text, decimal, int + select (label)
// ============================================================
function getAttrValue(PDO $pdo, string $prefix, int $entityId, ?int $attrId, int $storeId = 0): string {
    if ($attrId === null) return '';

    // Sprawdź backend_type
    $stmt = $pdo->prepare("SELECT backend_type, frontend_input FROM {$prefix}eav_attribute WHERE attribute_id = ?");
    $stmt->execute([$attrId]);
    $meta = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$meta) return '';

    $type = $meta['backend_type'];
    $input = $meta['frontend_input'];

    // Dla select/multiselect pobierz label opcji
    // Magento zazwyczaj trzyma w _int, ale niektorе instalacje uzywaja _varchar
    if (in_array($input, ['select', 'multiselect'])) {
        $tables = ($type === 'varchar')
            ? ["{$prefix}catalog_product_entity_varchar"]
            : ["{$prefix}catalog_product_entity_int", "{$prefix}catalog_product_entity_varchar"];

        foreach ($tables as $table) {
            $stmt = $pdo->prepare("
                SELECT ov.value AS label
                FROM $table a
                JOIN {$prefix}eav_attribute_option o ON o.option_id = CAST(a.value AS UNSIGNED)
                JOIN {$prefix}eav_attribute_option_value ov ON ov.option_id = o.option_id AND ov.store_id = 0
                WHERE a.entity_id = ? AND a.attribute_id = ?
                ORDER BY a.store_id DESC LIMIT 1
            ");
            $stmt->execute([$entityId, $attrId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row && $row['label'] !== null) return $row['label'];
        }
        return '';
    }

    $tableMap = [
        'varchar' => "{$prefix}catalog_product_entity_varchar",
        'text'    => "{$prefix}catalog_product_entity_text",
        'decimal' => "{$prefix}catalog_product_entity_decimal",
        'int'     => "{$prefix}catalog_product_entity_int",
    ];

    if (!isset($tableMap[$type])) return '';

    $table = $tableMap[$type];

    // Próbuj store-specific, fallback do global (store_id=0)
    $stmt = $pdo->prepare("
        SELECT value FROM $table
        WHERE entity_id = ? AND attribute_id = ?
        ORDER BY FIELD(store_id, ?, 0) DESC
        LIMIT 1
    ");
    $stmt->execute([$entityId, $attrId, $storeId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ? (string)$row['value'] : '';
}

// ============================================================
// Pobierz kategorię produktu (nazwa kategorii głównej)
// ============================================================
function getProductCategory(PDO $pdo, string $prefix, int $entityId, array $catKamien, array $catPlytki): string {
    $allIds = array_merge($catKamien, $catPlytki);
    $in = implode(',', array_fill(0, count($allIds), '?'));
    $stmt = $pdo->prepare("
        SELECT cp.category_id
        FROM {$prefix}catalog_category_product cp
        WHERE cp.product_id = ? AND cp.category_id IN ($in)
        LIMIT 1
    ");
    $params = array_merge([$entityId], $allIds);
    $stmt->execute($params);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) return '';

    // Zwróć nazwę kategorii
    $nameAttrStmt = $pdo->prepare("
        SELECT attribute_id FROM {$prefix}eav_attribute
        WHERE attribute_code = 'name' AND entity_type_id = 3
    ");
    $nameAttrStmt->execute();
    $nameAttr = $nameAttrStmt->fetchColumn();

    $stmt2 = $pdo->prepare("
        SELECT value FROM {$prefix}catalog_category_entity_varchar
        WHERE entity_id = ? AND attribute_id = ? AND store_id = 0
        LIMIT 1
    ");
    $stmt2->execute([$row['category_id'], $nameAttr]);
    return (string)($stmt2->fetchColumn() ?: '');
}

// Przygotuj listy ID podkategorii osobno (do etykietowania)
$catKamienIds = getSubcategoryIds($pdo, $p, CAT_KAMIEN);
$catPlytekIds = getSubcategoryIds($pdo, $p, CAT_PLYTKI);

// ============================================================
// Generuj CSV
// ============================================================
$output = fopen('php://stdout', 'w');

// BOM dla poprawnego wyświetlania polskich znaków w Excelu
fwrite($output, "\xEF\xBB\xBF");

// Nagłówki
$headers = [
    'SKU', 'Nazwa', 'Kategoria', 'Opis krótki', 'Opis długi',
    'Typ kamienia', 'Kolor', 'Długość', 'Szerokość', 'Grubość',
    'Rodzaj powierzchni', 'Producent', 'Waga jednostkowa',
    'Waga opakowania', 'Jednostka'
];
fputcsv($output, $headers, ';');

// Dane produktów
foreach ($products as $product) {
    $eid = (int)$product['entity_id'];

    // Pobierz kategorię (pierwsza pasująca z głównych)
    $allCatIds = array_merge($catKamienIds, $catPlytekIds);
    $inCat = implode(',', array_fill(0, count($allCatIds), '?'));
    $catStmt = $pdo->prepare("
        SELECT cp.category_id FROM {$p}catalog_category_product cp
        WHERE cp.product_id = ? AND cp.category_id IN ($inCat)
        ORDER BY cp.category_id ASC LIMIT 1
    ");
    $catStmt->execute(array_merge([$eid], $allCatIds));
    $catRow = $catStmt->fetch(PDO::FETCH_ASSOC);

    $categoryLabel = '';
    if ($catRow) {
        $catId = (int)$catRow['category_id'];
        if (in_array($catId, $catKamienIds)) {
            $categoryLabel = 'Kamień naturalny';
        } elseif (in_array($catId, $catPlytekIds)) {
            $categoryLabel = 'Płytki ceramiczne';
        }
    }

    $row = [
        $product['sku'],
        getAttrValue($pdo, $p, $eid, $attrIds['name']),
        $categoryLabel,
        getAttrValue($pdo, $p, $eid, $attrIds['short_description']),
        strip_tags(getAttrValue($pdo, $p, $eid, $attrIds['description'])),
        getAttrValue($pdo, $p, $eid, $attrIds['typ_kamienia']),
        getAttrValue($pdo, $p, $eid, $attrIds['kolor']),
        getAttrValue($pdo, $p, $eid, $attrIds['length']),
        getAttrValue($pdo, $p, $eid, $attrIds['width']),
        getAttrValue($pdo, $p, $eid, $attrIds['thickness']),
        getAttrValue($pdo, $p, $eid, $attrIds['rodzaj_powierzchni']),
        getAttrValue($pdo, $p, $eid, $attrIds['mgs_brand']),
        getAttrValue($pdo, $p, $eid, $attrIds['weight']),
        getAttrValue($pdo, $p, $eid, $attrIds['waga_opakowania']),
        getAttrValue($pdo, $p, $eid, $attrIds['jed']),
    ];

    fputcsv($output, $row, ';');
}

fclose($output);
fwrite(STDERR, "Eksport zakończony pomyślnie.\n");
