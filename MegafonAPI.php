<?php
/**
 * Класс для работы с HTTP API Megafon для отправки и получения SMS.
 * Интеграция с Bitrix Framework, используя ORM для хранения данных SMS.
 * Включает обработчик callback_url для уведомлений о доставке и входящих SMS.
 * 
 * Обновлено: Добавлены лучшие практики из класса Sms:
 * - Поддержка массовой и индивидуальной отправки SMS
 * - Система опций с кешированием
 * - Логирование всех операций
 * - Поддержка языковых файлов
 * - Режим отладки
 */

namespace Sng\Core;

// Подключаем необходимые классы Bitrix
use Bitrix\Main\Application;
use Bitrix\Main\HttpClient;
use Bitrix\Main\Web\Json;
use Bitrix\Main\Context;
use Bitrix\Main\Entity;
use Bitrix\Main\Type\DateTime;
use Bitrix\Main\HttpResponse;
use Bitrix\Main\Web\HttpHeaders;
use Bitrix\Main\Server;
use Bitrix\Main\Localization\Loc;
use Sng\Sms\Tables\SmsLogTable;

/**
 * Класс MegafonAPI для взаимодействия с Megafon HTTP API.
 */
class MegafonAPI
{
    /**
     * @var string Логин для авторизации
     */
    protected $sLogin;

    /**
     * @var string Пароль для авторизации
     */
    protected $sPassword;

    /**
     * @var string Базовый URL API
     */
    protected $sBaseUrl = 'https://a2p-api.megalabs.ru/sms/v1/';

    /**
     * @var bool Режим отладки
     */
    protected $bDebug;

    /**
     * @var bool Включить логирование
     */
    protected $bLog;

    /**
     * @var string Имя отправителя по умолчанию
     */
    protected $sOriginator;

    /**
     * @var string URL для callback уведомлений по умолчанию
     */
    protected $sCallbackUrl;

    /**
     * @var int Интервал между отправкой сообщений (мс)
     */
    protected $iGap;

    /**
     * @var string ID для кеширования
     */
    protected $sCacheId = 'sng.megafon';

    /**
     * @var \Bitrix\Main\Data\ManagedCache
     */
    protected $oCache;

    /**
     * Массив кодов ошибок из документации Megafon API.
     */
    private static $arErrorCodes = [
        // Успех
        0 => 'Запрос выполнен успешно.',
        
        // Общие коды ошибок Megafon
        20101 => 'Обязательное поле отсутствует или поле содержит данные в неверном формате.',
        20102 => 'Сообщение с таким msg_id уже передано для отправки.',
        
        // Стандартные SMPP-коды ошибок
        1 => 'Message Length is invalid',
        2 => 'Command Length is invalid',
        3 => 'Invalid Command ID',
        4 => 'Incorrect BIND Status for given command',
        5 => 'ESME Already in Bound State',
        6 => 'Invalid Priority Flag',
        7 => 'Invalid Registered Delivery Flag',
        8 => 'System Error',
        9 => 'Reserved',
        10 => 'Invalid Source Address',
        11 => 'Invalid Dest Addr',
        12 => 'Message ID is invalid',
        13 => 'Bind Failed',
        14 => 'Invalid Password',
        15 => 'Invalid System ID',
        34 => 'Throttling error (ESME has exceeded allowed message limits)',
        49 => 'Ошибка приема сообщения smsc (транзитер для приема смс не доступен).',
        50 => 'Pdu запрещен по правилам роутинга',
        51 => 'Зараженный адрес у получателя находится в черных списках',
        52 => 'Отправка в данный регион недоступна для данной компании.',
        1281 => 'Ошибка приема сообщения smsc (транзитер для приема смс не доступен).',
        1282 => 'Pdu запрещен по правилам роутинга.',
        1284 => 'Зараженный адрес у получателя находится в черных списках.',
        1288 => 'Отправка в данный регион недоступна для данной компании.',
    ];

    /**
     * Конструктор класса.
     * @param string|null $sLogin Логин для авторизации (если null, берется из настроек).
     * @param string|null $sPassword Пароль для авторизации (если null, берется из настроек).
     */
    public function __construct($sLogin = null, $sPassword = null)
    {
        Loc::loadLanguageFile(__FILE__);
        
        $this->oCache = Application::getInstance()->getManagedCache();
        $bCache = \COption::GetOptionString('sng.megafon', 'cache_options') == 'Y';
        
        $this->options($bCache);
        
        // Переопределяем логин и пароль, если переданы в конструкторе
        if ($sLogin !== null) {
            $this->sLogin = $sLogin;
        }
        if ($sPassword !== null) {
            $this->sPassword = $sPassword;
        }
    }

    /**
     * Метод для отправки одинакового SMS на список номеров (массовая отправка).
     * @param string $sMessage Текст сообщения.
     * @param string|array $mPhone Номер телефона или массив номеров.
     * @param array $arOptions Дополнительные опции.
     * @return array Результат отправки.
     */
    public function sendBulkSms($sMessage, $mPhone, array $arOptions = [])
    {
        /** @var \CUser $USER */
        global $USER;

        $arPhones = is_array($mPhone) ? $mPhone : [$mPhone];
        $arResults = [];
        $bOverallSuccess = true;

        foreach ($arPhones as $sPhone) {
            // Нормализуем номер телефона (убираем лишние символы)
            $sPhone = preg_replace('/[^\d]/', '', $sPhone);
            
            $arResult = $this->sendSMS(
                $arOptions['from'] ?? $this->sOriginator,
                $sPhone,
                $sMessage,
                $arOptions['callback_url'] ?? $this->sCallbackUrl,
                $arOptions['msg_id'] ?? null
            );

            $arResults[] = [
                'phone' => $sPhone,
                'success' => $arResult['success'],
                'msg_id' => $arResult['msg_id'],
                'error' => $arResult['error']
            ];

            if (!$arResult['success']) {
                $bOverallSuccess = false;
            }

            // Логирование каждой отправки
            if ($this->bLog) {
                SmsLogTable::add([
                    'DATETIME' => new DateTime(),
                    'METHOD' => 'SendBulkSms',
                    'PHONE' => $sPhone,
                    'MSG' => $sMessage,
                    'DUMP' => serialize($arResult),
                    'ERROR' => $arResult['success'] ? 0 : 1,
                    'CLIENT_ID' => is_object($USER) ? $USER->GetID() : '',
                    'SMS_ID' => $arResult['msg_id']
                ]);
            }

            // Пауза между отправками
            if ($this->iGap > 0 && count($arPhones) > 1) {
                usleep($this->iGap * 1000);
            }
        }

        return [
            'success' => $bOverallSuccess,
            'results' => $arResults,
            'total_sent' => count(array_filter($arResults, function($r) { return $r['success']; })),
            'total_failed' => count(array_filter($arResults, function($r) { return !$r['success']; }))
        ];
    }

    /**
     * Метод для отправки индивидуальных SMS (каждому получателю свое сообщение).
     * @param array $arMessages Массив сообщений [['phone' => '79261234567', 'message' => 'Текст'], ...].
     * @param array $arOptions Дополнительные опции.
     * @return array Результат отправки.
     */
    public function sendIndividualSms(array $arMessages, array $arOptions = [])
    {
        /** @var \CUser $USER */
        global $USER;

        $arResults = [];
        $bOverallSuccess = true;

        foreach ($arMessages as $arMessage) {
            $sPhone = $arMessage['phone'];
            $sMessage = $arMessage['message'];
            
            // Нормализуем номер телефона (убираем лишние символы)
            $sPhone = preg_replace('/[^\d]/', '', $sPhone);

            $arResult = $this->sendSMS(
                $arOptions['from'] ?? $this->sOriginator,
                $sPhone,
                $sMessage,
                $arOptions['callback_url'] ?? $this->sCallbackUrl,
                $arMessage['msg_id'] ?? null
            );

            $arResults[] = [
                'phone' => $sPhone,
                'message' => $sMessage,
                'success' => $arResult['success'],
                'msg_id' => $arResult['msg_id'],
                'error' => $arResult['error']
            ];

            if (!$arResult['success']) {
                $bOverallSuccess = false;
            }

            // Логирование каждой отправки
            if ($this->bLog) {
                SmsLogTable::add([
                    'DATETIME' => new DateTime(),
                    'METHOD' => 'SendIndividualSms',
                    'PHONE' => $sPhone,
                    'MSG' => $sMessage,
                    'DUMP' => serialize($arResult),
                    'ERROR' => $arResult['success'] ? 0 : 1,
                    'CLIENT_ID' => is_object($USER) ? $USER->GetID() : '',
                    'SMS_ID' => $arResult['msg_id']
                ]);
            }

            // Пауза между отправками
            if ($this->iGap > 0 && count($arMessages) > 1) {
                usleep($this->iGap * 1000);
            }
        }

        return [
            'success' => $bOverallSuccess,
            'results' => $arResults,
            'total_sent' => count(array_filter($arResults, function($r) { return $r['success']; })),
            'total_failed' => count(array_filter($arResults, function($r) { return !$r['success']; }))
        ];
    } 
   /**
     * Метод для отправки SMS через Megafon API (базовый метод).
     * @param string $sFrom Имя отправителя.
     * @param string $sTo Номер получателя.
     * @param string $sMessage Текст сообщения.
     * @param string|null $sCallbackUrl URL для callback уведомлений.
     * @param string|null $sMsgId Уникальный ID сообщения.
     * @return array Результат отправки.
     */
    public function sendSMS($sFrom, $sTo, $sMessage, $sCallbackUrl = null, $sMsgId = null)
    {
        // Валидация входных параметров
        if (empty($sFrom) || strlen($sFrom) > 11) {
            return ['success' => false, 'msg_id' => null, 'error' => $this->getErrorText('INVALID_SENDER')];
        }

        if (!preg_match('/^\d{11,15}$/', $sTo)) {
            return ['success' => false, 'msg_id' => null, 'error' => $this->getErrorText('INVALID_PHONE')];
        }

        if (empty($sMessage) || strlen($sMessage) > 1000) {
            return ['success' => false, 'msg_id' => null, 'error' => $this->getErrorText('INVALID_MESSAGE')];
        }

        if ($sCallbackUrl && !filter_var($sCallbackUrl, FILTER_VALIDATE_URL)) {
            return ['success' => false, 'msg_id' => null, 'error' => $this->getErrorText('INVALID_CALLBACK_URL')];
        }

        if ($sMsgId && !preg_match('/^[A-Za-z0-9_:-]{1,16}$/', $sMsgId)) {
            return ['success' => false, 'msg_id' => null, 'error' => $this->getErrorText('INVALID_MSG_ID')];
        }

        // Подготовка данных запроса
        $arData = [
            'from' => $sFrom,
            'to' => $sTo,
            'message' => $sMessage,
        ];

        if ($sCallbackUrl) {
            $arData['callback_url'] = $sCallbackUrl;
        }

        if ($sMsgId) {
            $arData['msg_id'] = $sMsgId;
        }

        // Создание HTTP клиента
        $oHttpClient = new HttpClient();
        $oHttpClient->setAuthorization($this->sLogin, $this->sPassword);
        $oHttpClient->setHeader('Content-Type', 'application/json');
        $oHttpClient->setCharset('UTF-8');

        if ($this->bDebug) {
            $oHttpClient->setDebug(true);
        }

        // Отправка POST запроса
        $sUrl = $this->sBaseUrl . 'sms';
        $sResponse = $oHttpClient->post($sUrl, Json::encode($arData));

        // Обработка ответа
        if ($sResponse === false) {
            return ['success' => false, 'msg_id' => null, 'error' => $this->getErrorText('NETWORK_ERROR')];
        }

        $arResponseData = Json::decode($sResponse);
        $iCode = $arResponseData['result']['status']['code'] ?? null;

        if ($iCode == 0) {
            $sMsgId = $arResponseData['result']['msg_id'];

            // Сохранение в ORM таблицу (если таблица существует)
            if (class_exists('SmsTable')) {
                SmsTable::add([
                    'MSG_ID' => $sMsgId,
                    'FROM_FIELD' => $sFrom,
                    'TO_FIELD' => $sTo,
                    'MESSAGE' => $sMessage,
                    'STATUS' => 'sent',
                    'TYPE' => 'outgoing',
                    'UPDATED_AT' => new DateTime(),
                ]);
            }

            return ['success' => true, 'msg_id' => $sMsgId, 'error' => null];
        } else {
            $sErrorDescription = $arResponseData['result']['status']['description'] ?? $this->getErrorText('UNKNOWN_ERROR');
            
            // Дополняем описанием из массива, если есть
            if (isset(self::$arErrorCodes[$iCode])) {
                $sErrorDescription .= ' (' . self::$arErrorCodes[$iCode] . ')';
            }

            return ['success' => false, 'msg_id' => null, 'error' => $sErrorDescription];
        }
    }



    /**
     * Получение текстового описания ошибки по коду или ключу.
     * @param string|int $mCode Код ошибки или ключ языкового файла.
     * @return string Описание ошибки.
     */
    public function getErrorText($mCode)
    {
        // Сначала пробуем получить из языкового файла
        $sLangMessage = Loc::getMessage('SNG_MEGAFON_ERROR_' . $mCode);
        if ($sLangMessage) {
            return $sLangMessage;
        }

        // Если не найдено в языковом файле, ищем в массиве кодов
        if (is_numeric($mCode) && isset(self::$arErrorCodes[$mCode])) {
            return self::$arErrorCodes[$mCode];
        }

        return Loc::getMessage('SNG_MEGAFON_ERROR_UNKNOWN') ?: 'Неизвестная ошибка';
    }

    /**
     * Метод для обработки callback уведомлений.
     * @return void
     */
    public function handleCallback()
    {
        // Устанавливаем константу для отключения служебной информации
        if (!defined('PUBLIC_AJAX_MODE')) {
            define('PUBLIC_AJAX_MODE', true);
        }

        $oRequest = Application::getInstance()->getContext()->getRequest();
        $oResponse = Application::getInstance()->getResponse();

        // Проверяем Content-Type
        $oHeaders = $oRequest->getHeaders();
        if ($oHeaders->getContentType() !== 'application/json') {
            $oResponse->setStatus(400);
            $oResponse->setContent('Bad Request: Invalid Content-Type');
            $oResponse->send();
            return;
        }

        if (!$oRequest->isPost()) {
            $oResponse->setStatus(405);
            $oResponse->setContent('Method Not Allowed');
            $oResponse->send();
            return;
        }

        $sInput = $oRequest->getInput();
        $arData = Json::decode($sInput);

        // Проверка типа callback
        if (isset($arData['receipted_message_id']) && isset($arData['status'])) {
            // Уведомление о доставке
            $this->processDeliveryCallback($arData);
        } elseif (isset($arData['from']) && isset($arData['to']) && isset($arData['message'])) {
            // Входящее сообщение
            $this->processIncomingCallback($arData);
        } else {
            $oResponse->setStatus(400);
            $oResponse->setContent('Bad Request');
            $oResponse->send();
            return;
        }

        $oResponse->setStatus(200);
        $oResponse->setContent('OK');
        $oResponse->send();
    }

    /**
     * Обработка уведомления о доставке SMS.
     * @param array $arData Данные из callback.
     */
    private function processDeliveryCallback($arData)
    {
        $sMsgId = $arData['msg_id'];
        $sReceiptedMessageId = $arData['receipted_message_id'];
        $sStatus = $arData['status'];
        $sShortMessage = $arData['short_message'];

        // Обновление статуса в ORM таблице (если таблица существует)
        if (class_exists('SmsTable')) {
            $oSms = SmsTable::getList([
                'filter' => ['MSG_ID' => $sMsgId],
                'select' => ['ID'],
            ])->fetch();

            if ($oSms) {
                SmsTable::update($oSms['ID'], [
                    'STATUS' => ($sStatus == 'delivered') ? 'delivered' : 'failed',
                    'UPDATED_AT' => new DateTime(),
                ]);
            }
        }

        // Логирование callback
        if ($this->bLog) {
            /** @var \CUser $USER */
            global $USER;
            
            SmsLogTable::add([
                'DATETIME' => new DateTime(),
                'METHOD' => 'DeliveryCallback',
                'PHONE' => '-',
                'MSG' => 'Callback доставки для SMS [' . $sMsgId . ']: ' . $sStatus,
                'DUMP' => serialize($arData),
                'ERROR' => 0,
                'CLIENT_ID' => is_object($USER) ? $USER->GetID() : '',
                'SMS_ID' => $sMsgId
            ]);
        }
    }

    /**
     * Обработка входящего SMS.
     * @param array $arData Данные из callback.
     */
    private function processIncomingCallback($arData)
    {
        $sMsgId = $arData['msg_id'];
        $sFrom = $arData['from'];
        $sTo = $arData['to'];
        $sMessage = $arData['message'];

        // Сохранение входящего SMS в ORM таблицу (если таблица существует)
        if (class_exists('SmsTable')) {
            SmsTable::add([
                'MSG_ID' => $sMsgId,
                'FROM_FIELD' => $sFrom,
                'TO_FIELD' => $sTo,
                'MESSAGE' => $sMessage,
                'STATUS' => 'received',
                'TYPE' => 'incoming',
                'UPDATED_AT' => new DateTime(),
            ]);
        }

        // Логирование callback
        if ($this->bLog) {
            /** @var \CUser $USER */
            global $USER;
            
            SmsLogTable::add([
                'DATETIME' => new DateTime(),
                'METHOD' => 'IncomingCallback',
                'PHONE' => $sFrom,
                'MSG' => 'Входящее SMS: ' . $sMessage,
                'DUMP' => serialize($arData),
                'ERROR' => 0,
                'CLIENT_ID' => is_object($USER) ? $USER->GetID() : '',
                'SMS_ID' => $sMsgId
            ]);
        }
    }

    /**
     * Получение опций модуля с поддержкой кеширования.
     * @param bool $bCache Использовать кеширование.
     */
    protected function options($bCache = true)
    {
        if ($bCache) {
            if ($this->oCache->read(3600000, $this->sCacheId)) {
                $arVars = $this->oCache->get($this->sCacheId);
                $this->loadOptionsFromArray($arVars);
            } else {
                $this->getOptions();
                $this->saveOptionsToCache();
            }
        } else {
            $this->getOptions();
        }
    }

    /**
     * Загрузка опций из массива.
     * @param array $arVars Массив опций.
     */
    protected function loadOptionsFromArray($arVars)
    {
        $this->sLogin = $arVars['login'];
        $this->sPassword = $arVars['password'];
        $this->sOriginator = $arVars['originator'];
        $this->sCallbackUrl = $arVars['callback_url'];
        $this->bDebug = $arVars['debug'];
        $this->bLog = $arVars['log'];
        $this->iGap = $arVars['gap'];
    }

    /**
     * Сохранение опций в кеш.
     */
    protected function saveOptionsToCache()
    {
        $this->oCache->set($this->sCacheId, [
            'login' => $this->sLogin,
            'password' => $this->sPassword,
            'originator' => $this->sOriginator,
            'callback_url' => $this->sCallbackUrl,
            'debug' => $this->bDebug,
            'log' => $this->bLog,
            'gap' => $this->iGap,
        ]);
    }

    /**
     * Получение всех опций модуля из настроек Bitrix.
     */
    protected function getOptions()
    {
        $this->sLogin = \COption::GetOptionString('sng.megafon', 'login');
        $this->sPassword = \COption::GetOptionString('sng.megafon', 'password');
        $this->sOriginator = \COption::GetOptionString('sng.megafon', 'originator');
        $this->sCallbackUrl = \COption::GetOptionString('sng.megafon', 'callback_url');
        $this->bDebug = \COption::GetOptionString('sng.megafon', 'debug') == 'Y';
        $this->bLog = \COption::GetOptionString('sng.megafon', 'log') == 'Y';
        $this->iGap = (int)\COption::GetOptionString('sng.megafon', 'gap', 1000);
    }
}