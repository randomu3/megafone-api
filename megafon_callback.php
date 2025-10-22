<?php
/**
 * Обработчик callback уведомлений от Megafon API
 * Этот файл должен быть доступен по URL, который вы указываете в callback_url
 */

// Подключение Bitrix
require_once($_SERVER["DOCUMENT_ROOT"]."/bitrix/modules/main/include/prolog_before.php");

// Устанавливаем режим для чистого API endpoint
define('PUBLIC_AJAX_MODE', true);

use Sng\Core\MegafonAPI;

try {
    // Создаем экземпляр API
    $oApi = new MegafonAPI();
    
    // Обрабатываем callback
    $oApi->handleCallback();
    
} catch (Exception $e) {
    // Логируем ошибку
    error_log("Megafon Callback Error: " . $e->getMessage());
    
    // Возвращаем ошибку
    http_response_code(500);
    echo "Internal Server Error";
}

// Подключение эпилога не требуется, так как ответ уже отправлен в handleCallback()