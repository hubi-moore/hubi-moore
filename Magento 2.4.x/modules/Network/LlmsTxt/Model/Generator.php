<?php
declare(strict_types=1);

namespace Network\LlmsTxt\Model;

use Magento\Catalog\Model\Product\Attribute\Source\Status as ProductStatus;
use Magento\Catalog\Model\Product\Visibility as ProductVisibility;
use Magento\Catalog\Model\ResourceModel\Category\CollectionFactory as CategoryCollectionFactory;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory as ProductCollectionFactory;
use Magento\Cms\Model\ResourceModel\Page\CollectionFactory as PageCollectionFactory;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\Filesystem;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Generator llms.txt i llms-full.txt zgodny ze specyfikacją llmstxt.org (Jeremy Howard, 2024).
 *
 * /llms.txt      — zwięzły przewodnik: opis sklepu + linki do kluczowych sekcji
 * /llms-full.txt — pełna treść: wszystkie produkty, kategorie, strony CMS z opisami
 */
class Generator
{
    private const PRODUCT_LIMIT      = 0; // 0 - unlimited
    private const DESCRIPTION_LENGTH = 500;

    /** @var CategoryCollectionFactory */
    private $categoryCollectionFactory;

    /** @var ProductCollectionFactory */
    private $productCollectionFactory;

    /** @var PageCollectionFactory */
    private $pageCollectionFactory;

    /** @var StoreManagerInterface */
    private $storeManager;

    /** @var ScopeConfigInterface */
    private $scopeConfig;

    /** @var Filesystem */
    private $filesystem;

    /** @var LoggerInterface */
    private $logger;

    public function __construct(
        CategoryCollectionFactory $categoryCollectionFactory,
        ProductCollectionFactory  $productCollectionFactory,
        PageCollectionFactory     $pageCollectionFactory,
        StoreManagerInterface     $storeManager,
        ScopeConfigInterface      $scopeConfig,
        Filesystem                $filesystem,
        LoggerInterface           $logger
    ) {
        $this->categoryCollectionFactory = $categoryCollectionFactory;
        $this->productCollectionFactory  = $productCollectionFactory;
        $this->pageCollectionFactory     = $pageCollectionFactory;
        $this->storeManager              = $storeManager;
        $this->scopeConfig               = $scopeConfig;
        $this->filesystem                = $filesystem;
        $this->logger                    = $logger;
    }

    // =========================================================================
    // Publiczne API — dla kontrolerów HTTP
    // =========================================================================

    /**
     * Treść llms.txt dla bieżącego store view (HTTP).
     */
    public function getContent(): string
    {
        return $this->buildLlmsTxt($this->storeManager->getStore());
    }

    /**
     * Treść llms-full.txt dla bieżącego store view (HTTP).
     */
    public function getFullContent(): string
    {
        return $this->buildLlmsFullTxt($this->storeManager->getStore());
    }

    // =========================================================================
    // Publiczne API — dla CLI i crona
    // =========================================================================

    /**
     * Generuje i zapisuje oba pliki dla wszystkich aktywnych store view.
     */
    public function execute(): array
    {
        $messages = [];

        foreach ($this->storeManager->getStores() as $store) {
            if (!$store->getIsActive()) {
                continue;
            }

            $this->storeManager->setCurrentStore($store);
            $storeId = (int)$store->getId();

            // llms.txt
            try {
                $content  = $this->buildLlmsTxt($store);
                $filePath = $this->saveFile($content, $storeId, 'llms.txt');
                $messages[] = sprintf(
                    'Store [%s] llms.txt -> %s (%d B)',
                    $store->getName(), $filePath, mb_strlen($content, 'UTF-8')
                );
            } catch (\Throwable $e) {
                $messages[] = sprintf('✗ Store [%s] llms.txt błąd: %s', $store->getName(), $e->getMessage());
                $this->logger->error('Network_LlmsTxt: ' . end($messages));
            }

            // llms-full.txt
            try {
                $content  = $this->buildLlmsFullTxt($store);
                $filePath = $this->saveFile($content, $storeId, 'llms-full.txt');
                $messages[] = sprintf(
                    'Store [%s] llms-full.txt -> %s (%d B)',
                    $store->getName(), $filePath, mb_strlen($content, 'UTF-8')
                );
            } catch (\Throwable $e) {
                $messages[] = sprintf('✗ Store [%s] llms-full.txt błąd: %s', $store->getName(), $e->getMessage());
                $this->logger->error('Network_LlmsTxt: ' . end($messages));
            }
        }

        return $messages;
    }

    // =========================================================================
    // llms.txt — zwięzły przewodnik
    // =========================================================================

    private function buildLlmsTxt(\Magento\Store\Api\Data\StoreInterface $store): string
    {
        $storeId = (int)$store->getId();
        $baseUrl = rtrim($store->getBaseUrl(\Magento\Framework\UrlInterface::URL_TYPE_WEB), '/');
        $name    = $this->getConfig('general/store_information/name', $storeId) ?: $store->getName();
        $now     = date('Y-m-d');

        $metaDesc = $this->getConfig('design/head/default_description', $storeId);
        $phone    = $this->getConfig('general/store_information/phone', $storeId);
        $email    = $this->getConfig('trans_email/ident_general/email', $storeId);
        $country  = $this->getConfig('general/country/default', $storeId);
        $currency = $this->getConfig('currency/options/default', $storeId);

        $lines = [];

        // H1 — jedyna wymagana sekcja wg spec
        $lines[] = "# {$name}";
        $lines[] = '';

        // Blockquote — krótki opis
        $description = $metaDesc ?: "Sklep internetowy {$name} dostępny pod adresem {$baseUrl}.";
        foreach (explode("\n", wordwrap($description, 80, "\n", false)) as $l) {
            $lines[] = '> ' . $l;
        }
        $lines[] = '';

        // Dane kontaktowe i podstawowe informacje (bez H2, wg spec)
        $details = [];
        if ($currency) $details[] = "- Waluta: **{$currency}**";
        if ($country)  $details[] = "- Kraj działalności: **{$country}**";
        if ($phone)    $details[] = "- Telefon: {$phone}";
        if ($email)    $details[] = "- E-mail: {$email}";
        if ($details) {
            $lines[] = 'Informacje kontaktowe:';
            $lines[] = '';
            foreach ($details as $d) {
                $lines[] = $d;
            }
            $lines[] = '';
        }

        // H2: Kategorie główne
        $topCategories = $this->getTopCategories($store, $baseUrl);
        if ($topCategories) {
            $lines[] = '## Kategorie produktów';
            $lines[] = '';
            foreach ($topCategories as $cat) {
                $line    = "- [{$cat['name']}]({$cat['url']})";
                if ($cat['desc']) $line .= ": {$cat['desc']}";
                $lines[] = $line;
            }
            $lines[] = '';
        }

        // H2: Strony informacyjne
        $pages = $this->getCmsPages($store, $baseUrl);
        if ($pages) {
            $lines[] = '## Informacje i polityki';
            $lines[] = '';
            foreach ($pages as $page) {
                $line    = "- [{$page['title']}]({$page['url']})";
                if ($page['desc']) $line .= ": {$page['desc']}";
                $lines[] = $line;
            }
            $lines[] = '';
        }

        // H2: Optional — podkategorie + linki do pełnych plików
        $lines[] = '## Optional';
        $lines[] = '';
        $lines[] = "- [Pełny katalog produktów]({$baseUrl}/llms-full.txt): Wszystkie produkty z opisami, cenami i URL-ami";
        $lines[] = "- [Sitemap XML]({$baseUrl}/sitemap.xml): Pełna lista wszystkich stron sklepu";

        $subCategories = $this->getSubCategories($store, $baseUrl);
        foreach ($subCategories as $cat) {
            $lines[] = "- [{$cat['name']}]({$cat['url']})";
        }
        $lines[] = '';

        $lines[] = "---";
        $lines[] = "Wygenerowano: {$now} | {$baseUrl}";
        $lines[] = '';

        return implode("\n", $lines);
    }

    // =========================================================================
    // llms-full.txt — pełna treść katalogu
    // =========================================================================

    private function buildLlmsFullTxt(\Magento\Store\Api\Data\StoreInterface $store): string
    {
        $storeId = (int)$store->getId();
        $baseUrl = rtrim($store->getBaseUrl(\Magento\Framework\UrlInterface::URL_TYPE_WEB), '/');
        $name    = $this->getConfig('general/store_information/name', $storeId) ?: $store->getName();
        $now     = date('Y-m-d');
        $currency = $this->getConfig('currency/options/default', $storeId);

        $lines = [];

        // H1
        $lines[] = "# {$name} — pełny katalog";
        $lines[] = '';

        // Blockquote
        $lines[] = "> Pełna treść katalogu sklepu {$name} ({$baseUrl}).";
        $lines[] = "> Zawiera wszystkie aktywne produkty, kategorie oraz strony informacyjne.";
        $lines[] = "> Wygenerowano: {$now}.";
        $lines[] = '';
        $lines[] = "Skrócona wersja nawigacyjna dostępna pod: [{$baseUrl}/llms.txt]({$baseUrl}/llms.txt)";
        $lines[] = '';

        // H2: Wszystkie kategorie (pełna hierarchia)
        $allCategories = $this->getAllCategories($store, $baseUrl);
        if ($allCategories) {
            $lines[] = '## Kategorie';
            $lines[] = '';
            foreach ($allCategories as $cat) {
                $indent  = str_repeat('  ', max(0, (int)$cat['level'] - 2));
                $line    = "{$indent}- [{$cat['name']}]({$cat['url']})";
                if ($cat['desc']) $line .= ": {$cat['desc']}";
                $lines[] = $line;
            }
            $lines[] = '';
        }

        // H2: Strony CMS z pełną treścią meta
        $pages = $this->getCmsPages($store, $baseUrl, true);
        if ($pages) {
            $lines[] = '## Strony informacyjne';
            $lines[] = '';
            foreach ($pages as $page) {
                $lines[] = "### {$page['title']}";
                if ($page['desc']) {
                    $lines[] = '';
                    $lines[] = $page['desc'];
                }
                $lines[] = '';
                $lines[] = "URL: {$page['url']}";
                $lines[] = '';
            }
        }

        // H2: Produkty — pełne dane
        $products = $this->getProducts($store, $baseUrl, $currency);
        $lines[]  = '## Produkty';
        $lines[]  = '';
        $lines[]  = sprintf(
            'Sklep zawiera %d aktywnych produktów (wygenerowano: %s).',
            count($products),
            $now
        );
        $lines[]  = '';

        foreach ($products as $p) {
            $lines[] = "### {$p['name']}";
            $lines[] = '';
            if ($p['desc']) {
                $lines[] = $p['desc'];
                $lines[] = '';
            }
            $meta = [];
            if ($p['sku'])   $meta[] = "**SKU:** {$p['sku']}";
            if ($p['price']) $meta[] = "**Cena:** {$p['price']}";
            if ($p['url'])   $meta[] = "**URL:** {$p['url']}";
            if ($meta) {
                $lines[] = implode(' | ', $meta);
                $lines[] = '';
            }
        }

        $lines[] = '---';
        $lines[] = "Wygenerowano: {$now} | {$baseUrl}";
        $lines[] = '';

        return implode("\n", $lines);
    }

    // =========================================================================
    // Dane: kategorie
    // =========================================================================

    private function getTopCategories(
        \Magento\Store\Api\Data\StoreInterface $store,
        string $baseUrl
    ): array {
        return $this->fetchCategories($store, $baseUrl, 2, 2, 20);
    }

    private function getSubCategories(
        \Magento\Store\Api\Data\StoreInterface $store,
        string $baseUrl
    ): array {
        return $this->fetchCategories($store, $baseUrl, 3, 3, 50);
    }

    private function getAllCategories(
        \Magento\Store\Api\Data\StoreInterface $store,
        string $baseUrl
    ): array {
        return $this->fetchCategories($store, $baseUrl, 2, 99, 300);
    }

    private function fetchCategories(
        \Magento\Store\Api\Data\StoreInterface $store,
        string $baseUrl,
        int $levelFrom,
        int $levelTo,
        int $limit
    ): array {
        $collection = $this->categoryCollectionFactory->create();
        $collection->setStoreId($store->getId())
            ->addAttributeToSelect(['name', 'url_path', 'is_active', 'level', 'description'])
            ->addAttributeToFilter('is_active', 1)
            ->addAttributeToFilter('level', ['from' => $levelFrom, 'to' => $levelTo])
            ->addOrderField('level')
            ->addOrderField('position')
            ->setPageSize($limit);

        $result = [];
        foreach ($collection as $cat) {
            $name = $cat->getName();
            if (!$name) continue;

            $urlPath = $cat->getUrlPath();
            $url     = $urlPath
                ? $baseUrl . '/' . $urlPath
                : $baseUrl . '/catalog/category/view/id/' . $cat->getId();

            $desc = strip_tags((string)$cat->getDescription());
            $desc = preg_replace('/\s+/', ' ', trim($desc));
            if (mb_strlen($desc) > 120) {
                $desc = mb_substr($desc, 0, 117) . '...';
            }

            $result[] = [
                'name'  => $name,
                'url'   => $url,
                'level' => (int)$cat->getLevel(),
                'desc'  => $desc,
            ];
        }

        return $result;
    }

    // =========================================================================
    // Dane: strony CMS
    // =========================================================================

    private function getCmsPages(
        \Magento\Store\Api\Data\StoreInterface $store,
        string $baseUrl,
        bool $fullDesc = false
    ): array {
        $excluded = [
            'home', 'no-route', 'enable-cookies',
            'privacy-policy-cookie-restriction-mode',
        ];

        $collection = $this->pageCollectionFactory->create();
        $collection->addFieldToSelect(['title', 'identifier', 'meta_description', 'content'])
            ->addFieldToFilter('is_active', 1)
            ->addFieldToFilter('identifier', ['nin' => $excluded])
            ->setPageSize(50);

        $priorityHints = [
            'contact'              => 'Formularz kontaktowy',
            'about-us'             => 'Informacje o firmie',
            'o-nas'                => 'Informacje o firmie',
            'delivery'             => 'Warunki i koszty dostawy',
            'dostawa'              => 'Warunki i koszty dostawy',
            'shipping'             => 'Warunki i koszty dostawy',
            'returns'              => 'Polityka zwrotów i reklamacji',
            'zwroty'               => 'Polityka zwrotów i reklamacji',
            'reklamacje'           => 'Polityka zwrotów i reklamacji',
            'faq'                  => 'Najczęściej zadawane pytania',
            'privacy-policy'       => 'Polityka prywatności',
            'polityka-prywatnosci' => 'Polityka prywatności',
            'terms'                => 'Regulamin sklepu',
            'regulamin'            => 'Regulamin sklepu',
        ];

        $result = [];
        foreach ($collection as $page) {
            $identifier = $page->getIdentifier();

            // Dla llms-full.txt używamy treści strony, dla llms.txt — meta_description
            if ($fullDesc) {
                $desc = strip_tags((string)$page->getContent());
                $desc = preg_replace('/\s+/', ' ', trim($desc));
                if (mb_strlen($desc) > self::DESCRIPTION_LENGTH) {
                    $desc = mb_substr($desc, 0, self::DESCRIPTION_LENGTH - 3) . '...';
                }
            } else {
                $desc = (string)$page->getMetaDescription();
                if (empty($desc)) {
                    $desc = $priorityHints[$identifier] ?? '';
                }
                if (mb_strlen($desc) > 120) {
                    $desc = mb_substr($desc, 0, 117) . '...';
                }
            }

            $result[] = [
                'title' => $page->getTitle(),
                'url'   => $baseUrl . '/' . ltrim($identifier, '/'),
                'desc'  => $desc,
            ];
        }

        return $result;
    }

    // =========================================================================
    // Dane: produkty (tylko dla llms-full.txt)
    // =========================================================================

    private function getProducts(
        \Magento\Store\Api\Data\StoreInterface $store,
        string $baseUrl,
        string $currency
    ): array {
        $collection = $this->productCollectionFactory->create();
        $collection->setStoreId($store->getId())
            ->addAttributeToSelect(['name', 'description', 'short_description', 'price', 'status', 'visibility', 'url_key'])
            ->addAttributeToFilter('status', ProductStatus::STATUS_ENABLED)
            ->addAttributeToFilter('visibility', [
                'in' => [
                    ProductVisibility::VISIBILITY_IN_CATALOG,
                    ProductVisibility::VISIBILITY_IN_SEARCH,
                    ProductVisibility::VISIBILITY_BOTH,
                ],
            ])
            ->addUrlRewrite()
            ->setPageSize(self::PRODUCT_LIMIT)
            ->setCurPage(1);

        $result = [];
        foreach ($collection as $product) {
            $name = $product->getName();
            if (!$name) continue;

            // Preferujemy short_description, fallback na description
            $desc = strip_tags((string)($product->getShortDescription() ?: $product->getDescription()));
            $desc = preg_replace('/\s+/', ' ', trim($desc));
            if (mb_strlen($desc) > self::DESCRIPTION_LENGTH) {
                $desc = mb_substr($desc, 0, self::DESCRIPTION_LENGTH - 3) . '...';
            }

            $price = $product->getFinalPrice();
            $result[] = [
                'name'  => $name,
                'sku'   => $product->getSku(),
                'price' => $price
                    ? number_format((float)$price, 2, '.', '') . ' ' . $currency
                    : null,
                'url'   => $product->getProductUrl(),
                'desc'  => $desc,
            ];
        }

        return $result;
    }

    // =========================================================================
    // Zapis pliku
    // =========================================================================

    private function saveFile(string $content, int $storeId, string $filename): string
    {
        // Dla store_id > 1 dodajemy suffix, np. llms_2.txt / llms-full_2.txt
        if ($storeId > 1) {
            $ext      = pathinfo($filename, PATHINFO_EXTENSION);
            $base     = pathinfo($filename, PATHINFO_FILENAME);
            $filename = $base . '_' . $storeId . '.' . $ext;
        }

        $pubDir = $this->filesystem->getDirectoryWrite(DirectoryList::PUB);
        $pubDir->writeFile($filename, $content);

        return $pubDir->getAbsolutePath($filename);
    }

    // =========================================================================
    // Pomocnik
    // =========================================================================

    private function getConfig(string $path, int $storeId): string
    {
        return (string)$this->scopeConfig->getValue(
            $path,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }
}