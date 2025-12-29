<?php
// bot.php - الحل النهائي

$BOT_TOKEN = getenv('8430437491:AAH6rFJTYCC9fHxrv8euLlNVA7jFgzhvg50');

// إذا كان طلب GET
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    echo "Telegram Bot Webhook Endpoint\n";
    echo "Token exists: " . (strlen($BOT_TOKEN) > 10 ? 'YES' : 'NO') . "\n";
    exit;
}

// استقبال البيانات
$input = file_get_contents('php://input');
file_put_contents('webhook.log', date('Y-m-d H:i:s') . " - " . $input . "\n", FILE_APPEND);

$update = json_decode($input, true);

if ($update && isset($update['message'])) {
    $chatId = $update['message']['chat']['id'];
    $text = $update['message']['text'] ?? '';
    
    if ($text === '/start') {
        $firstName = $update['message']['from']['first_name'] ?? 'صديقي';
        
        $url = "https://api.telegram.org/bot{$BOT_TOKEN}/sendMessage";
        $data = [
            'chat_id' => $chatId,
            'text' => "🎉 أهلاً {$firstName}!\n\n✅ البوت يعمل!\n\nChat ID: {$chatId}",
            'parse_mode' => 'HTML'
        ];
        
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_exec($ch);
        curl_close($ch);
    }
}

echo "OK";
?>
