<?
define('NO_AGENT_CHECK', true);
define('NO_KEEP_STATISTIC', true);
require($_SERVER['DOCUMENT_ROOT'].'/bitrix/modules/main/include/prolog_before.php');
header('Content-Type: application/json');

if (!\Bitrix\Main\Loader::includeModule('iblock')) {
    die(json_encode(['error' => 'Error include module iblock']));
}

$newsApi = new NewsApi();
echo $newsApi->getJson($newsApi->getNews());

/**
 * Класс для работы с новостями
 */
class NewsApi
{
    private $iblockId;
    private $dateFrom;
    private $dateTo;
    private $newsClassName;

    function __construct()
    {
        $this->iblockId = 12;
        $this->dateFrom = '01.01.2015';
        $this->dateTo = '01.01.2016';
        $this->newsClassName = $this->getNewsClassName();
    }

    /**
     * Основной метод для получения новостей
     */
    public function getNews(): ?array // d7 подход
    {
        $result = [];

        $arOrder = [
            'ID' => 'ASC'
        ];
        $arSelect = [
            'ID',
            'NAME',
            'CODE',
            'ELEMENT_CODE' => 'CODE',
            'IBLOCK_ID',
            'SECTION_NAME' => 'IBLOCK_SECTION.NAME',
            'SECTION_CODE' => 'IBLOCK_SECTION.CODE',
            'TAGS',
            'ACTIVE_FROM',
            'DETAIL_PAGE_URL' => 'IBLOCK.DETAIL_PAGE_URL',
            'AUTHOR_ID' => 'AUTHOR.VALUE',
            'AUTHOR_NAME' => 'AUTHOR_ELEMENT.NAME',
            // 'AUTHOR_NAME' => 'AUTHOR.ELEMENT.NAME', // так не работает, хотя, судя по документации, должно
            'PREVIEW_PICTURE_SRC',
        ];
        $arFilter = [
            '>=ACTIVE_FROM' => $this->dateFrom,
            '<ACTIVE_FROM' => $this->dateTo
        ];

        $news = $this->newsClassName::getList([
            'select' => $arSelect,
            'filter' => $arFilter,
            'order' => $arOrder,
            'runtime' => [
                new \Bitrix\Main\Entity\ReferenceField( // получаем данные о картинке
                    'PREVIEW_PICTURE_ELEMENT',
                    \Bitrix\Main\FileTable::getEntity(),
                    ['=this.PREVIEW_PICTURE' => 'ref.ID']
                ),
                new \Bitrix\Main\Entity\ExpressionField( // формируем путь к картинке
                    'PREVIEW_PICTURE_SRC',
                    'CONCAT("'.$this->getSiteUrl().'", "/upload/", %s, "/", %s)',
                    ['PREVIEW_PICTURE_ELEMENT.SUBDIR', 'PREVIEW_PICTURE_ELEMENT.FILE_NAME']
                ),
                new \Bitrix\Main\Entity\ReferenceField( // получаем данные об авторе
                    'AUTHOR_ELEMENT',
                    \Bitrix\Iblock\ElementTable::getEntity(),
                    ['=this.AUTHOR_ID' => 'ref.ID']
                )
            ],
        ]);

        while ($item = $news->fetch()) {
            $result[] = [
                'id' => $item['ID'] > 0 ? (int)$item['ID'] : null,
                'name' => strlen($item['NAME']) > 0 ? $item['NAME'] : null,
                'url' => $this->getDetailUrl($item['DETAIL_PAGE_URL'], $item) ?? null,
                'image' => strlen($item['PREVIEW_PICTURE_SRC']) > 0 ? $item['PREVIEW_PICTURE_SRC'] : null,
                'sectionName' => strlen($item['SECTION_NAME']) ? $item['SECTION_NAME'] : null,
                'date' => $this->getFormattedDate($item['ACTIVE_FROM']) ?? null,
                'author' => strlen($item['AUTHOR_NAME']) > 0 ? $item['AUTHOR_NAME'] : null,
                'tags' => $this->getTagsArray($item['TAGS']) ?? null,
            ];
        }

        return $result;
    }

    /**
     * Метод для получения названия разделов, к которым привязаны элементы
     */
    private function getNewsSections()
    {
        $result = [];

        $arSelect = ['ID', 'NAME'];
        $arFilter = ['IBLOCK_ID' => $this->iblockId];
        $dbSections = \CIBlockSection::GetList([], $arFilter, false, $arSelect);
        while ($arSection = $dbSections->fetch()) {
            $result[$arSection['ID']] = $arSection['NAME'];
        }

        return $result;
    }

    /**
     * Метод форматирует битриксовую дату
     */
    private function getFormattedDate(string $strDate = '', string $format = 'd F Y H:i'): ?string
    {
        if (strlen($strDate) == 0) {
            return null;
        }

        return \CIBlockFormatProperties::DateFormat($format, MakeTimeStamp($strDate, \CSite::GetDateFormat()));
    }

    /**
     * Метод получает имя автора
     */
    private function getAuthorName(Int $id = 0, Int $iblockId = 0): ?string
    {
        if (!$id || $iblockId) {
            return null;
        }

        $arSelect = ['ID', 'NAME'];
        $arFilter = ['IBLOCK_ID' => $iblockId];

        return CIBlockElement::GetList([], $arFilter, false, false, $arSelect)->fetch()['NAME'];
    }

    /**
     * Метод формирует массив тегов
     */
    private function getTagsArray(?string $strTags = ''): ?array
    {
        if (strlen($strTags) == 0) {
            return null;
        }

        return explode(', ', $strTags);
    }

    /**
     * Метод формирует json из массива
     */
    public function getJson(?array $arItems = []): ?string
    {
        if (empty($arItems)) {
            return null;
        }

        return json_encode($arItems);
    }

    /**
     * Метод формирует url детального просмотра
     */
    private function getDetailUrl(?string $patternUrl = '', array $urlFields = []): string
    {
        if (strlen($patternUrl) == 0 || empty($urlFields)) {
            return null;
        }

        return $this->getSiteUrl() . \CIBlock::ReplaceDetailUrl($patternUrl, $urlFields);
    }

    /**
     * Метод возвращает класс для работы с инфоблоком новостей
     */
    private function getNewsClassName(): string
    {
        $newsClassName = '\Bitrix\Iblock\Elements\ElementStocksTable';
        if (!class_exists($newsClassName) && (int)$this->iblockId > 0) {
            $newsClassName = "\Bitrix\Iblock\Iblock::wakeUp({$this->iblockId})->getEntityDataClass()";
        }

        return $newsClassName;
    }

    private function getSiteUrl()
    {
        $protocol = \Bitrix\Main\Application::getInstance()->getContext()->getRequest()->isHttps() ? 'https://' : 'http://';
        return $protocol . SITE_SERVER_NAME;
    }
}
