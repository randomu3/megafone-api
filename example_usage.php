<?php
/**
 * Пример использования улучшенного класса MegafonAPI
 */

require_once 'MegafonAPI.php';

use Sng\Core\MegafonAPI;

// Инициализация API (логин и пароль будут взяты из настроек модуля)
$oApi = new MegafonAPI();

// Или с явным указанием логина и пароля
// $oApi = new MegafonAPI('your_login', 'your_password');

// 1. Отправка одного SMS
echo "=== Отправка одного SMS ===\n";
$arResult = $oApi->sendSMS("TestSender", "79261238212", "Привет! Это тестовое сообщение.");
if ($arResult['success']) {
    echo "SMS отправлено, ID: " . $arResult['msg_id'] . "\n";
} else {
    echo "Ошибка: " . $arResult['error'] . "\n";
}

// 2. Массовая отправка одинакового сообщения
echo "\n=== Массовая отправка ===\n";
$arPhones = ["79261238212", "79261238213", "79261238214"];
$arBulkResult = $oApi->sendBulkSms("Массовое сообщение для всех", $arPhones);

echo "Всего отправлено: " . $arBulkResult['total_sent'] . "\n";
echo "Ошибок: " . $arBulkResult['total_failed'] . "\n";

foreach ($arBulkResult['results'] as $arResult) {
    echo "Телефон: " . $arResult['phone'] . " - ";
    if ($arResult['success']) {
        echo "Отправлено (ID: " . $arResult['msg_id'] . ")\n";
    } else {
        echo "Ошибка: " . $arResult['error'] . "\n";
    }
}

// 3. Индивидуальная отправка разных сообщений
echo "\n=== Индивидуальная отправка ===\n";
$arMessages = [
    ['phone' => '79261238212', 'message' => 'Персональное сообщение для первого клиента'],
    ['phone' => '79261238213', 'message' => 'Персональное сообщение для второго клиента'],
    ['phone' => '79261238214', 'message' => 'Персональное сообщение для третьего клиента']
];

$arIndividualResult = $oApi->sendIndividualSms($arMessages);

echo "Всего отправлено: " . $arIndividualResult['total_sent'] . "\n";
echo "Ошибок: " . $arIndividualResult['total_failed'] . "\n";

foreach ($arIndividualResult['results'] as $arResult) {
    echo "Телефон: " . $arResult['phone'] . " - ";
    if ($arResult['success']) {
        echo "Отправлено (ID: " . $arResult['msg_id'] . ")\n";
    } else {
        echo "Ошибка: " . $arResult['error'] . "\n";
    }
}

// 4. Пример с дополнительными опциями
echo "\n=== Отправка с опциями ===\n";
$arOptions = [
    'from' => 'CustomSender',
    'callback_url' => 'https://yourdomain.com/megafon_callback.php',
    'msg_id' => 'custom_id_' . time()
];

$arResult = $oApi->sendBulkSms(
    "Сообщение с кастомными опциями", 
    "79261238212", 
    $arOptions
);

if ($arResult['success']) {
    echo "SMS с опциями отправлено\n";
} else {
    echo "Ошибка: " . $arResult['error'] . "\n";
}