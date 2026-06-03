<?php
// bitrix_handler.php
// Обработчик исходящего вебхука для события OnTaskAdd

// ========== НАСТРОЙТЕ ЭТУ ПЕРЕМЕННУЮ ==========
// Вместо текста ниже вставьте URL вашего ВХОДЯЩЕГО вебхука из Битрикс24
// Как его создать: Приложения → Разработчикам → Входящие вебхуки → Добавить
// Права: tasks, sonet_group
$inboundWebhook = "https://b24-76mjtz.bitrix24.ru/rest/1/a3zrzighzyvakfqc/";
// =============================================

// Лог-файл (будет создан в той же папке, что и скрипт)
$logFile = __DIR__ . "/bitrix_handler_log.txt";

function writeLog($message, $logFile) {
    $timestamp = date("Y-m-d H:i:s");
    file_put_contents($logFile, "[$timestamp] $message" . PHP_EOL, FILE_APPEND);
}

// 1. Принимаем данные от Битрикс24
$rawInput = file_get_contents("php://input");
writeLog("Получен запрос: " . $rawInput, $logFile);

$data = json_decode($rawInput, true);
if (!$data) {
    writeLog("Ошибка: не JSON", $logFile);
    http_response_code(200);
    echo "OK";
    exit;
}

// 2. Проверяем, что это событие OnTaskAdd
if (!isset($data['event']) || $data['event'] !== 'OnTaskAdd') {
    writeLog("Не событие OnTaskAdd, игнорируем", $logFile);
    http_response_code(200);
    echo "OK";
    exit;
}

$taskId = $data['data']['FIELDS']['ID'] ?? null;
if (!$taskId) {
    writeLog("Нет ID задачи", $logFile);
    http_response_code(200);
    echo "OK";
    exit;
}

writeLog("Начинаем обработку задачи ID: " . $taskId, $logFile);

// 3. Получаем детали задачи через REST
$getTaskUrl = $inboundWebhook . "tasks.task.get";
$postData = http_build_query([
    'taskId' => $taskId,
    'select' => ['ID', 'TITLE', 'DESCRIPTION']
]);

$ch = curl_init();
curl_setopt_array($ch, [
    CURLOPT_URL => $getTaskUrl,
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => $postData,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_SSL_VERIFYPEER => false,
    CURLOPT_TIMEOUT => 30
]);
$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($httpCode != 200 || !$response) {
    writeLog("Ошибка получения задачи: HTTP $httpCode", $logFile);
    http_response_code(200);
    echo "OK";
    exit;
}

$taskData = json_decode($response, true);
if (!isset($taskData['result']['task'])) {
    writeLog("Задача не найдена: " . $response, $logFile);
    http_response_code(200);
    echo "OK";
    exit;
}

$task = $taskData['result']['task'];
$taskTitle = $task['TITLE'];
$taskDesc = $task['DESCRIPTION'] ?? '';

writeLog("Создаём проект с названием: $taskTitle", $logFile);

// 4. Создаём проект (группу)
$createGroupUrl = $inboundWebhook . "sonet_group.create";
$groupFields = [
    'NAME' => $taskTitle,
    'DESCRIPTION' => $taskDesc,
    'PROJECT' => 'Y',
    'VISIBLE' => 'Y',
    'OPENED' => 'Y'
];
$postDataGroup = http_build_query(['fields' => $groupFields]);

$chGroup = curl_init();
curl_setopt_array($chGroup, [
    CURLOPT_URL => $createGroupUrl,
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => $postDataGroup,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_SSL_VERIFYPEER => false,
    CURLOPT_TIMEOUT => 30
]);
$groupResponse = curl_exec($chGroup);
$groupHttpCode = curl_getinfo($chGroup, CURLINFO_HTTP_CODE);
curl_close($chGroup);

if ($groupHttpCode == 200) {
    $groupResult = json_decode($groupResponse, true);
    if (isset($groupResult['result'])) {
        writeLog("✅ Проект создан! ID группы: " . $groupResult['result'], $logFile);
    } else {
        $error = $groupResult['error_description'] ?? $groupResult['error'] ?? 'неизвестная ошибка';
        writeLog("❌ Ошибка API: $error", $logFile);
    }
} else {
    writeLog("❌ HTTP ошибка $groupHttpCode: $groupResponse", $logFile);
}

// 5. Обязательный ответ 200
http_response_code(200);
echo "OK";
