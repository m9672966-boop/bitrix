<?php
// bitrix_handler.php
// Обработчик исходящего вебхука для события OnTaskAdd

// --- НАСТРОЙКИ (ПРОВЕРЬТЕ ЭТИ ДАННЫЕ!) ---
// URL входящего вебхука из вашего Битрикс24 (с правами tasks, sonet_group)
$inboundWebhook = "https://b24-76mjtz.bitrix24.ru/rest/1/6yp45wovcl3yabiy/";
// Папка для логов (должна быть доступна на запись)
$logFile = __DIR__ . "/bitrix_handler_log.txt";
// -------------------------------------

// Функция writeLog объявлена только один раз
function writeLog($message, $logFile) {
    $timestamp = date("Y-m-d H:i:s");
    file_put_contents($logFile, "[$timestamp] $message" . PHP_EOL, FILE_APPEND);
}

// 1. Принимаем и декодируем входящий JSON от Битрикс24
$rawInput = file_get_contents("php://input");
writeLog("Скрипт выполнен. Получен сырой запрос: " . $rawInput, $logFile);

$data = json_decode($rawInput, true);
if (!$data) {
    writeLog("Ошибка: не удалось декодировать JSON", $logFile);
    http_response_code(200);
    echo "OK";
    exit;
}

// 2. Проверяем, что это событие OnTaskAdd и есть ID задачи
if (!isset($data['event']) || $data['event'] !== 'OnTaskAdd') {
    writeLog("Неизвестное событие: " . ($data['event'] ?? 'null'), $logFile);
    http_response_code(200);
    echo "OK";
    exit;
}

$taskId = $data['data']['FIELDS']['ID'] ?? null;
if (!$taskId) {
    writeLog("В событии нет ID задачи", $logFile);
    http_response_code(200);
    echo "OK";
    exit;
}

writeLog("Обработка задачи ID: " . $taskId, $logFile);

// 3. Получаем детали задачи через входящий вебхук
$getTaskUrl = $inboundWebhook . "tasks.task.get";
$postData = http_build_query([
    'taskId' => $taskId,
    'select' => ['ID', 'TITLE', 'DESCRIPTION', 'RESPONSIBLE_ID']
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

if ($httpCode !== 200 || !$response) {
    writeLog("Ошибка при получении задачи: HTTP $httpCode, ответ: $response", $logFile);
    http_response_code(200);
    echo "OK";
    exit;
}

$taskData = json_decode($response, true);
if (!isset($taskData['result']['task'])) {
    writeLog("Не удалось найти задачу: " . $response, $logFile);
    http_response_code(200);
    echo "OK";
    exit;
}

$task = $taskData['result']['task'];
$taskTitle = $task['TITLE'];
$taskDesc = $task['DESCRIPTION'] ?? '';

writeLog("Название задачи: $taskTitle", $logFile);

// 4. Создаём проект (группу) через sonet_group.create
$createGroupUrl = $inboundWebhook . "sonet_group.create";
$groupFields = [
    'NAME' => $taskTitle,
    'DESCRIPTION' => $taskDesc,
    'PROJECT' => 'Y',          // Это именно проект
    'VISIBLE' => 'Y',          // Видим всем
    'OPENED' => 'Y',           // Открытый
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
        writeLog("✅ Проект успешно создан. ID: " . $groupResult['result'], $logFile);
    } else {
        $error = $groupResult['error_description'] ?? $groupResult['error'] ?? 'неизвестная ошибка';
        writeLog("❌ Ошибка API при создании проекта: $error. Ответ: $groupResponse", $logFile);
    }
} else {
    writeLog("❌ HTTP ошибка при создании проекта: $groupHttpCode, ответ: $groupResponse", $logFile);
}

// 5. Отвечаем Битрикс24, что всё принято (обязательно HTTP 200)
http_response_code(200);
echo "OK";
?>
