<?php
/**
 * Настройки модуля Megafon SMS в админке Bitrix
 * Этот файл должен быть размещен в папке модуля /bitrix/modules/sng.megafon/admin/
 */

require_once($_SERVER["DOCUMENT_ROOT"]."/bitrix/modules/main/include/prolog_admin_before.php");

use Bitrix\Main\Localization\Loc;
use Bitrix\Main\HttpApplication;
use Bitrix\Main\Loader;
use Bitrix\Main\Config\Option;

Loc::loadMessages(__FILE__);

$module_id = "sng.megafon";

// Проверяем права доступа
if (!$USER->IsAdmin()) {
    $APPLICATION->AuthForm(Loc::getMessage("ACCESS_DENIED"));
}

// Загружаем модуль
Loader::includeModule($module_id);

$request = HttpApplication::getInstance()->getContext()->getRequest();

// Массив опций модуля
$arOptions = [
    ["login", Loc::getMessage("SNG_MEGAFON_OPTION_LOGIN"), "", ["text", 40]],
    ["password", Loc::getMessage("SNG_MEGAFON_OPTION_PASSWORD"), "", ["password", 40]],
    ["originator", Loc::getMessage("SNG_MEGAFON_OPTION_ORIGINATOR"), "", ["text", 20]],
    ["callback_url", Loc::getMessage("SNG_MEGAFON_OPTION_CALLBACK_URL"), "", ["text", 60]],
    ["gap", Loc::getMessage("SNG_MEGAFON_OPTION_GAP"), "1000", ["text", 10]],
    ["debug", Loc::getMessage("SNG_MEGAFON_OPTION_DEBUG"), "N", ["checkbox"]],
    ["log", Loc::getMessage("SNG_MEGAFON_OPTION_LOG"), "Y", ["checkbox"]],
    ["cache_options", Loc::getMessage("SNG_MEGAFON_OPTION_CACHE"), "Y", ["checkbox"]],
];

// Обработка сохранения настроек
if ($request->isPost() && $request["save"] && check_bitrix_sessid()) {
    foreach ($arOptions as $arOption) {
        $optionName = $arOption[0];
        $optionValue = $request->getPost($optionName);
        
        if ($arOption[3][0] == "checkbox" && $optionValue != "Y") {
            $optionValue = "N";
        }
        
        Option::set($module_id, $optionName, $optionValue);
    }
    
    // Очищаем кеш настроек
    $cache = \Bitrix\Main\Application::getInstance()->getManagedCache();
    $cache->clean("sng.megafon");
    
    LocalRedirect($APPLICATION->GetCurPage()."?mid=".$module_id."&lang=".LANGUAGE_ID."&".$tabControl->ActiveTabParam());
}

// Создаем табы
$tabControl = new CAdminTabControl("tabControl", [
    [
        "DIV" => "edit1", 
        "TAB" => Loc::getMessage("SNG_MEGAFON_TAB_SETTINGS"), 
        "TITLE" => Loc::getMessage("SNG_MEGAFON_TAB_SETTINGS_TITLE")
    ],
    [
        "DIV" => "edit2", 
        "TAB" => Loc::getMessage("SNG_MEGAFON_TAB_TEST"), 
        "TITLE" => Loc::getMessage("SNG_MEGAFON_TAB_TEST_TITLE")
    ]
]);

$APPLICATION->SetTitle(Loc::getMessage("SNG_MEGAFON_OPTIONS_TITLE"));

require($_SERVER["DOCUMENT_ROOT"]."/bitrix/modules/main/include/prolog_admin_after.php");

// Обработка тестовой отправки
if ($request->isPost() && $request["test_send"] && check_bitrix_sessid()) {
    $testPhone = $request->getPost("test_phone");
    $testMessage = $request->getPost("test_message");
    
    if ($testPhone && $testMessage) {
        try {
            $oApi = new \Sng\Core\MegafonAPI();
            $arResult = $oApi->sendSMS(
                Option::get($module_id, "originator", "Test"),
                $testPhone,
                $testMessage
            );
            
            if ($arResult['success']) {
                CAdminMessage::ShowMessage([
                    "TYPE" => "OK",
                    "MESSAGE" => Loc::getMessage("SNG_MEGAFON_TEST_SUCCESS") . " ID: " . $arResult['msg_id']
                ]);
            } else {
                CAdminMessage::ShowMessage([
                    "TYPE" => "ERROR",
                    "MESSAGE" => Loc::getMessage("SNG_MEGAFON_TEST_ERROR") . ": " . $arResult['error']
                ]);
            }
        } catch (Exception $e) {
            CAdminMessage::ShowMessage([
                "TYPE" => "ERROR",
                "MESSAGE" => Loc::getMessage("SNG_MEGAFON_TEST_EXCEPTION") . ": " . $e->getMessage()
            ]);
        }
    }
}

?>

<form method="post" action="<?echo $APPLICATION->GetCurPage()?>?mid=<?=htmlspecialcharsbx($module_id)?>&lang=<?=LANGUAGE_ID?>">
    <?php echo bitrix_sessid_post(); ?>
    
    <?php $tabControl->Begin(); ?>
    
    <?php $tabControl->BeginNextTab(); ?>
    
    <?php foreach ($arOptions as $arOption): ?>
        <tr>
            <td width="40%">
                <label for="<?echo htmlspecialcharsbx($arOption[0])?>"><?echo $arOption[1]?>:</label>
            </td>
            <td width="60%">
                <?php
                $val = Option::get($module_id, $arOption[0], $arOption[2]);
                $type = $arOption[3];
                ?>
                
                <?php if ($type[0] == "checkbox"): ?>
                    <input type="checkbox" id="<?echo htmlspecialcharsbx($arOption[0])?>" name="<?echo htmlspecialcharsbx($arOption[0])?>" value="Y"<?if($val=="Y")echo" checked";?>>
                <?php elseif ($type[0] == "text"): ?>
                    <input type="text" size="<?echo $type[1]?>" maxlength="255" value="<?echo htmlspecialcharsbx($val)?>" name="<?echo htmlspecialcharsbx($arOption[0])?>" id="<?echo htmlspecialcharsbx($arOption[0])?>">
                <?php elseif ($type[0] == "password"): ?>
                    <input type="password" size="<?echo $type[1]?>" maxlength="255" value="<?echo htmlspecialcharsbx($val)?>" name="<?echo htmlspecialcharsbx($arOption[0])?>" id="<?echo htmlspecialcharsbx($arOption[0])?>">
                <?php endif; ?>
            </td>
        </tr>
    <?php endforeach; ?>
    
    <?php $tabControl->BeginNextTab(); ?>
    
    <tr>
        <td width="40%">
            <label for="test_phone"><?echo Loc::getMessage("SNG_MEGAFON_TEST_PHONE")?>:</label>
        </td>
        <td width="60%">
            <input type="text" size="20" name="test_phone" id="test_phone" placeholder="79261234567">
        </td>
    </tr>
    <tr>
        <td width="40%">
            <label for="test_message"><?echo Loc::getMessage("SNG_MEGAFON_TEST_MESSAGE")?>:</label>
        </td>
        <td width="60%">
            <textarea name="test_message" id="test_message" cols="50" rows="3" placeholder="<?echo Loc::getMessage("SNG_MEGAFON_TEST_MESSAGE_PLACEHOLDER")?>"></textarea>
        </td>
    </tr>
    <tr>
        <td colspan="2">
            <input type="submit" name="test_send" value="<?echo Loc::getMessage("SNG_MEGAFON_TEST_SEND_BUTTON")?>" class="adm-btn-save">
        </td>
    </tr>
    
    <?php $tabControl->Buttons(); ?>
    <input type="submit" name="save" value="<?echo Loc::getMessage("MAIN_SAVE")?>" title="<?echo Loc::getMessage("MAIN_OPT_SAVE_TITLE")?>" class="adm-btn-save">
    <input type="submit" name="restore" title="<?echo Loc::getMessage("MAIN_HINT_RESTORE_DEFAULTS")?>" onclick="return confirm('<?echo AddSlashes(Loc::getMessage("MAIN_HINT_RESTORE_DEFAULTS_WARNING"))?>')" value="<?echo Loc::getMessage("MAIN_RESTORE_DEFAULTS")?>">
    <?php $tabControl->End(); ?>
</form>

<?php require($_SERVER["DOCUMENT_ROOT"]."/bitrix/modules/main/include/epilog_admin.php"); ?>