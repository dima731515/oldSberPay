<?php declare(strict_types=1);

namespace Pay;

if(null !== phpunit && phpunit !== true)
{
    $_SERVER["DOCUMENT_ROOT"] = "/home/bitrix/www";
    require($_SERVER["DOCUMENT_ROOT"]."/bitrix/modules/main/include/prolog_before.php");
}

// файл настроек, содержит пароли
if(file_exists('/home/bitrix/.key.php')){
    require_once('/home/bitrix/.key.php');
}else{
    require_once('test_sber_config.php');
}

use Voronkovich\SberbankAcquiring\Client;
use Voronkovich\SberbankAcquiring\OrderStatus;
use Voronkovich\SberbankAcquiring\Currency;
use Voronkovich\SberbankAcquiring\HttpClient\HttpClientInterface;
use Voronkovich\SberbankAcquiring\HttpClient\GuzzleAdapter;
use GuzzleHttp\Client as Guzzle;

use Monolog\Logger;
use Monolog\Handler\StreamHandler;
use Monolog\Handler\SwiftMailerHandler;
use Monolog\Handler\NativeMailerHandler;
use Monolog\Formatter\HtmlFormatter;
use Monolog\Formatter\LineFormatter;

/**
 * Драйвер для работы счетов Макслевел с онлайн оплатой Сбребанк
 * Интерфейс в данном случае чтоб видеть публичные методы
 *
 * @author Dmitriy Perevyazkin <dima731515@yandex.ru>
 */
interface sberInterface
{
    public function sberCallback   (string $json) : bool; // принимает от сбербанка результаты платежей
    public function initByInvoiceId(string $invoiceId) : void; // инициализация объекта данными из Битрикс
    public function getPayLink     () : string; // вызывается после инициализации, формирует "заказ" в Сбербанке, возвращает ссылку на оплату
    public function reverseOrder   (string $sberOrderId = null, array $data = []): array; // полный возврат платежа (до 24:00 дня платежа) коммисия не вызымается
    public function refundOrder    (string $sberOrderId = null, int $amount = null, array $data = []): array; // возврат, возможен частичный, с продавца взымается коммисия
    public function setPay         () : bool; // проставить оплату текущему счету.
    public function log            (string $string) : void; // для логирования снаружи.
    public function getCompanyCode () : string; // Сахар для формирования чека
    public function getInvoiceId   () : int; // Сахар для формирования чека
}

class SberPay implements sberInterface 
{
    // id инфоблока счетов
    protected const INVOICE_IBLOCK_ID = 61;
    // код свойства инфоблока в котором хранится сумма счета
    protected const INVOICE_AMOUNT_FIELD_CODE = 'INVOICE_AMOUNT';
    // код свойста инфоблока в котором уазанно Юр лицо счета
    protected const PS_ID_FIELD_CODE = 'PS_ID';
    // код свойста инфоблока, флаг оплаты счета
    protected const PAYED_FIELD_CODE  = 'PAYED';
    // код свойста инфоблока, дата оплаты счета 
    protected const PAY_DATE_FIELD_CODE  = 'PAY_DATE';
    // соотношение списка Юр лиц в Битрикс с кодами для конфигурации 
    // ключом является id значения в инфоблоке счетов
    protected const COMPANY_CODE_BY_INVOICE_PS_ID = [
        '176' => 'motors',
        '177' => 'design', 
        '203' => 'eridan',
        '602' => 'maxlevel',
        '603' => 'aurum',
        '604' => 'interno',
        '605' => 'sanexpo',
        '606' => 'sella',
        '590' => 'gms'
    ];
    private   const LOGER_EMAIL   = 'dima731515@yandex.ru';
    private   const LOGER_PATH    = '/home/bitrix/www/upload/logs/test/';    
    private   const LOGER_FILE_NAME='_info_sber.log';

    public    $isTestServer       = false;
    private   $loger              = null;
    protected $client             = null; // объект для взаимодействия с api сбербанк
    protected $params             = [];   // параметры для передачи в сбер
    private   $sberCallbackData   = null; // todo: хз, помойму не использую
    private   $summ               = 0;    // сумма счета
    protected $config             = [];   // конфиг для текущего Юр лица
    protected $companyCode        = null; // maxlevel
    private   $checkSum           = null; // хеш сумма, передается сбербанком в данных о проведенном платеже, применяется для определения достоверности
    private   $sberOrderNumber    = null; // 2123123-invoice
    private   $uuidSberOrderNumber= null; // uuid номер заказа каторый присылает сбер, по нему можно сделать возврат
    private   $invoiceInit        = false;// флаг, переключается если объект инициализирован данными счета. Сахар
    private   $payed              = false;// флаг оплаты счета true/false;
    private   $payDate            = null; // дата оплаты счета;
    private   $bxInvoiceData      = [];   // хранит данные о счете из битрикс
    private   $dateActiveTo       = null; // дата до которой ссылка на опллату должна быть активна, берется из инфоблока, передается в Сбер для формирования сссылки на оплату
    private   $payLink            = null; // ссылка на оплату полученную или из битрикс или запрошенную у сбербанк

    public function __construct(bool $isTestServer = false)
    {
        if($isTestServer) $this->isTestServer = true;

        $this->loger = new Logger('maxlevel.sberPay.log'); 
        $this->loger->pushHandler(new StreamHandler(self::LOGER_PATH . date('d-m-Y') . '_' . self::LOGER_FILE_NAME, Logger::INFO, false));
        //$this->loger->info('Сообщение в лог', []);

    }
    /**
     * Публичный метод логирования, чтоб из вне использовать
     *
     * @param sting $message 
    */
    public function log(string $message) : void
    {
        $this->loger->info($message, []);
    }

    /**
     * Обрабатывает запрсы от сбербанк
     * получает переданную контрольную строку,
     * используя ключ из настроек и алгоритм получения контрольной строки
     * определяет правдоподобность запроса
     *
     * @param string json строка параметров, среди которых контрольная строка
     * @return bool, возвращает факт правдоподобности запроса
     *
     * @see initBySberOrderNumber()
     */
    public function sberCallback(string $json) : bool
    {
        $this->loger->info('От Сбербанк пришол запрос о пллатеже: ' . $json, []);
        $arData = json_decode($json, true);
        if ( !is_array($arData) || !isset($arData['checksum']) || empty($arData['checksum']) || empty($arData['orderNumber']) )
        {
            $this->loger->info('Формат ответа не соответствует ожидаемому!', []);
            Throw new \Exception('Формат ответа не соответствует ожидаемому!');
        }

        $this->sberCallbackData = $arData; 
        $this->checksum = $arData['checksum']; 
        $this->sberOrderNumber = $arData['orderNumber']; 
        $this->initBySberOrderNumber(); // для получения токена из конфигруации для конкретного юр.лица

        // формируем переданые данные по правилам Сбера:
        unset($arData['checksum']);
        ksort($arData);
        $ar = array_map(function($k, $v){return "$k;$v";},array_keys($arData), $arData);
        $controlString = implode(';', $ar) . ';';
        $hmac = hash_hmac('sha256', $controlString, $this->config['callbackToken']);
        $hmac = strtoupper($hmac);
        // сравниваем переданный хеш с тем что мы сформировали с помощью токена, если суммы совподают то ок
        if($this->checksum === $hmac)
            return true;
        return false;
    }

    /**
     * Проверит, не была ли ссылка уже запрошена и сохранена в счете битрикс 
     * получает возвращает ее
     * или обащается к сбербак для генерирования ссылки
     *
     * @uses getBxPayLink()
     * @uses getSberApiPayLink()
     * @return string, возвращает ссылку (https://sber.ru....)
     */
    public function getPayLink() : string
    {
        try{
            $res = ($this->getBxPayLink() || $this->getSberApiPayLink());
        }catch(\Exception $e){
            $this->loger->info($e->getMessage(), []);
            //echo $e->getMessage();
            throw new \Exception('Не удалось получить ссылку на оплату, оплата невозможна! Обратитесь к администратору!'); 
        }
        return $this->payLink; 
    }

    /**
     * Выполняет полный возврат денег, без движения средств, при этом коммисия за транзакцию не взымается
     * Можно выполнить до 24:00 текущего (дня платежа) дня
     * @param string sberOrderId, обязательный UUID заказа (не путать с номером зказа) 
     * @param array data, не используется
     * @return array, ответ Сбера 
     */
    public function reverseOrder(string $sberOrderId = null, array $data = []): array
    {
        if(null === $sberOrderId && null === $this->uuidSberOrderNumber)
        { 
            $this->loger->info('Нет данных для Полного возврата!', []);
            Throw new \Exception('Нет данных для Полного возврата!');
        }

        $this->uuidSberOrderNumber = ($sberOrderId) ? $sberOrderId : $this->uuidSberOrderNumber; 

        $this->initBySberOrderNumber(); // для получения токена для конкретного юр.лица
        $result = $this->client->reverseOrder( $this->uuidSberOrderNumber );
        $this->loger->info($result, []);
        return $result; 
    }

    /**
     * Возврат денег покупателю,
     * без ограничений по дате платежа,
     * взымается коммисия с продавца,
     * можно делать частичный возврат
     * @param string sberOrderId, обязательный UUID заказа (платежа в Сбербанк)
     * @param string amoun, сумма которую нужно вернуть
     * @param array data, до параметры, не используется
     * @return array, возвращает ответ Сбербанк
     */
    public function refundOrder(string $sberOrderId = null, int $amount = null, array $data = []): array
    {
        if(null === $sberOrderId && null === $this->uuidSberOrderNumber)
        {
           $this->loger->info('Нет данных для Полного возврата!', []);
           Throw new \Exception('Нет данных для Полного возврата!');
        }
        if(null === $amount && null === $this->summ)
        {
           $this->loger->info('Не указана сумма возврата, является обязательной!', []);
           Throw new \Exception('Не указана сумма возврата, является обязательной!');
        }

        $this->uuidSberOrderNumber = ($sberOrderId)?$sberOrderId:$this->uuidSberOrderNumber; 
        $this->summ = ($amount)?$amount:$this->summ; 
        $this->initBySberOrderNumber(); // для получения токена для конкретного юр.лица
        $result = $this->client->refundOrder($this->uuidSberOrderNumber, $this->summ);
        $this->loger->info($result, []);
        return $result; 
    }

    /**
     * Устанавливает факт оплаты, и дату оплаты в Битрикс
     * Должна вызываться только после инициализации методом sberCallback() 
     * т.к. в нем устанавливаются необходимые параметры
     * @return bool, вернет установлен/не установлен
     */
    public function setPay() : bool 
    {
        if('deposited' !== $this->sberCallbackData['operation'] || 1 != $this->sberCallbackData['status'])
        {
           $this->loger->info('Статус не соответствует оплаченному!', []);
           Throw new \Exception('Статус не соответствует оплаченному!');
        }
        // сделать запрос, может фаг оплаты уже стоит
        // если стоит вернуть true
        if($this->payed)
            return true;

        // если нет, утановить и вернуть результат
        $res = \CIBlockElement::SetPropertyValues($this->bxInvoiceData['ID'], self::INVOICE_IBLOCK_ID, "Y", self::PAYED_FIELD_CODE);
        $resPayDate = \CIBlockElement::SetPropertyValues($this->bxInvoiceData['ID'], self::INVOICE_IBLOCK_ID, (new DateTime())->format('d.m.Y H:i:s'), self::PAY_DATE_FIELD_CODE);

        return true;
    }

    /**
     * Так как сбер генерирут ссылку на оплату один раз
     * при первом получении сохраняем ссылку в Битрикс
     * данный метод проверит, нет ли ссылки в счете битрикс
     * если ссылка на оплату уже была получена в сбер, то этот метод вернет ее получив в записи инфоблока счетов
     * @return bool, !!!Возвращает только факт (ссылка установлена), саму ссылку возвращает метод getPayLink()
     */
    private function getBxPayLink() : bool 
    {
        if( !isset($this->bxInvoiceData['DETAIL_TEXT']) || empty($this->bxInvoiceData['DETAIL_TEXT']) )
            return false;

        $payLink = json_decode($this->bxInvoiceData['DETAIL_TEXT'], true);
        if(!isset($payLink['formUrl']) || empty($payLink['formUrl']))
            return false;

        $this->payLink = $payLink['formUrl'];
        return true;
    }

    /** Регистрирует заказ в сбербанк (потуму что так работает Сбербанк)
     * сбер возвращает ссылку на оплату
     * @return bool
     */
    private function getSberApiPayLink() : bool
    {
        $this->params = [
            'failUrl' => 'https://www.maxlevel.ru/pay/fail.php',
            'expirationDate' => $this->dateActiveTo,
        ];

        $result = $this->client->registerOrder($this->sberOrderNumber, $this->summ, $this->config['returnUrl'], $this->params);

        if( isset($result['formUrl']) && !empty($result['formUrl']) )
        {
            $this->payLink = $result['formUrl'];
            $this->setPayLinkDataInBxInvoice(json_encode($result));
            return true;
        }
        return false;
    }

    /**
     * получает Счет из Битрикс используя API Битрикс
     * @param int invoiceId, id элемента инфоблока
     * @return array, возввращает стандартный Битрикс Макссив
     */
    protected function getBxInvoiceById(int $invoiceId) : array 
    {
        if(!CModule::IncludeModule("iblock")) die();
        $result   = [];
        $arSelect = ['ID','NAME', 'DETAIL_TEXT', 'DATE_ACTIVE_TO', 'PROPERTY_'. self::INVOICE_AMOUNT_FIELD_CODE, 'PROPERTY_' . self::PS_ID_FIELD_CODE, 'PROPERTY_' . self::PAYED_FIELD_CODE, 'PROPERTY_' . self::PAY_DATE_FIELD_CODE];
        $res = CIBlockElement::getList(['ID'=>'ASC'], ['IBLOCK_ID'=>self::INVOICE_IBLOCK_ID,'ID'=>$invoiceId], false, ['nTopCount'=>1, 'nPageSize' => 1], $arSelect);
        while($row = $res->fetch()){
            $result = $row;
            $result['COMPANY_CODE'] = (key_exists($row['PROPERTY_PS_ID_ENUM_ID'], self::COMPANY_CODE_BY_INVOICE_PS_ID)) ? self::COMPANY_CODE_BY_INVOICE_PS_ID[$row['PROPERTY_PS_ID_ENUM_ID']]:'test'; 
        }
        return $result;
    }

    /**
     * сохраняет сылку на оплату полученную в сбер в счете Битрикс, чтобы повторно не запрашивать
     * @param string data, все, что прислал Сбер на запрос ссылки на оплату 
     * @return bool
     */
    protected function setPayLinkDataInBxInvoice(string $data) : bool
    {
        $el = new CIBlockElement;
        $prop = [ 
            "DETAIL_TEXT" =>$data,
        ];
        $res = $el->Update($this->bxInvoiceData['ID'], $prop);
        return $res;
    }

    /**
     * декодирует номер заказ сбер (234234-invoice) делает запрос к Битрикс и инициализирует полученными данными
     *
     *
     */
    protected function initBySberOrderNumber() : void
    {
        if(null === $this->sberOrderNumber){
            $this->loger->info('Необходимо установить sberOrderNumber!', []);
            Throw new \Exception('Необходимо установить sberOrderNumber!');
        }

        $invoiceId = $this->decodeSberOrderNum();
        $this->initByInvoiceId($invoiceId);
    }

    /**
     * кодирует id счета для сбербанка (1231234-invoice)
     * @return string (131231-invoice)
     */
    private function encodeSberOrderNumByInvoiceId(string $invoiceId) : string
    {
        return $invoiceId . '-invoice' ;
    }
    /**
     * декодирует номер заказа (23234-invoice)
     * @see (input 123123-invoice)
     * @return string (123123)
     */
    private function decodeSberOrderNum() : string
    {
        $result = explode('-', $this->sberOrderNumber);
        return $result[0];
    }

    /**
     * Инициализирует Объект по id счета
     * запрашивает счет по id и заполняет свойства объекта 
     *
     *
     */ 
    public function initByInvoiceId(string $invoiceId) : void
    {
        $this->bxInvoiceData = $this->getBxInvoiceById( (int)$invoiceId );
        $this->initConfigByCompanyCode($this->bxInvoiceData['COMPANY_CODE']); 
        $this->sberOrderNumber = $this->encodeSberOrderNumByInvoiceId($this->bxInvoiceData['ID']); 
        $this->summ = (int) ((float) $this->bxInvoiceData['PROPERTY_' . self::INVOICE_AMOUNT_FIELD_CODE . '_VALUE'] * 100);
        $this->dateActiveTo = ($this->bxInvoiceData['DATE_ACTIVE_TO']) ? (new DateTime($this->bxInvoiceData['DATE_ACTIVE_TO']))->modify('+1 day')->format('Y-m-d\TH:i:s') : (new DateTime())->modify('+1 day')->format('Y-m-d\TH:i:s');

        if('Y' === $this->bxInvoiceData['PROPERTY_' . self::PAYED_FIELD_CODE . '_VALUE'])
            $this->payed = true;

        if($this->bxInvoiceData['PROPERTY_' . self::PAY_DATE_FIELD_CODE . '_VALUE'])
            $this->payDate = $this->bxInvoiceData['PROPERTY_' . self::PAY_DATE_FIELD_CODE . '_VALUE'];

        if(isset($this->bxInvoiceData['DETAIL_TEXT']) && !empty($this->bxInvoiceData['DETAIL_TEXT']))
        {
            $payLink = json_decode($this->bxInvoiceData['DETAIL_TEXT'], true);
            if(isset($payLink['orderId']) && !empty($payLink['orderId']))
            {
                $this->uuidSberOrderNumber = $payLink['orderId'];
            }
        }
        
        $this->payLink = $payLink['formUrl'];
        $this->invoiceInit = true;
    }

    /**
     * получает и устанавливает настройки для доступа к Сбер в зависимости от Юр лица
     * @param string $code
     * @return void
     */
    protected function initConfigByCompanyCode(string $code) : void
    {
        if( !key_exists($code, SBER_CONFIG) || empty(SBER_CONFIG[$code]) )
        {
           $this->loger->info('В файле конфигурации нет записи о компании '. $code . '!', []);
           Throw new \Exception('В файле конфигурации нет записи о компании ' . $code . '!');
        }

        $this->companyCode = $code;
        $this->config['callbackToken'] = '';
        $this->config['returnUrl'] = '';
        $this->config['sberOptions'] = [
            'apiUri'     =>'https://securepayments.sberbank.ru',
            'currency'   => Currency::RUB,
            'language'   => 'ru',
            'httpMethod' => HttpClientInterface::METHOD_GET,
            'httpClient' => new GuzzleAdapter(new Guzzle()),
        ];
        if($this->isTestServer || $this->companyCode === null)
        {
            $this->companyCode = 'test';
            $this->config['sberOptions']['apiUri'] = Client::API_URI_TEST;
            $this->loger->info('Выполняется с тестовым сервером!', $this->config);
        }
        $this->config['sberOptions'] = array_merge($this->config['sberOptions'], SBER_CONFIG[$this->companyCode]['sberOptions']);
        $this->config['callbackToken'] = SBER_CONFIG[$this->companyCode]['callbackToken'];
        $this->config['returnUrl'] = SBER_CONFIG[$this->companyCode]['returnUrl'];

        $this->initClient();
    }
    // инициализирует объект Client для работы с api сбербанк
    protected function initClient() : bool  
    {
        try{
            $this->client = new Client($this->config['sberOptions']);
        }catch(\Exception $e)
        {
            $this->loger->info($e->getMessage(), []);
            throw new \Exception('Не удалось создать объект Client');
        }
        return true; 
    }
    /**
     * Публичный метод, чтоб получить код компании с которой сейчас идет работа
     * @return string motor/maxlevel/...
     */
    public function getCompanyCode() : string
    {
        if(null === $this->companyCode) Throw new \Exception('Отсутствует Код компании, такого быть не должно!');
        return $this->companyCode;
    }
    /**
     * Получить id текущего счета
     * @return int
     */
    public function getInvoiceId() : int
    {
        if( !isset($this->bxInvoiceData['ID']) ||  empty($this->bxInvoiceData['ID'])) Throw new \Exception('Отсутствует ID счета, такого быть не должно!');
        return (int) $this->bxInvoiceData['ID'];
    }
}
