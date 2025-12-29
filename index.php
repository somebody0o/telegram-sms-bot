<?php
// بوت تلغرام بسيط للاختبار
$BOT_TOKEN = '8430437491:AAH6rFJTYCC9fHxrv8euLlNVA7jFgzhvg50';

// إذا كان طلب GET، عرض صفحة الاختبار
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    echo "🤖 Telegram Bot Test Page\n";
    echo "=======================\n";
    echo "✅ Server is working!\n";
    echo "⏰ Time: " . date('Y-m-d H:i:s') . "\n";
    echo "📱 Bot Token: " . substr($BOT_TOKEN, 0, 10) . "...\n\n";
    
    // اختبار اتصال بالبوت
    $url = "https://api.telegram.org/bot{$BOT_TOKEN}/getMe";
    $result = file_get_contents($url);
    
    if ($result) {
        $data = json_decode($result, true);
        if ($data['ok']) {
            echo "✅ Bot is CONNECTED\n";
            echo "👤 Bot Username: @" . $data['result']['username'] . "\n";
            echo "🆔 Bot ID: " . $data['result']['id'] . "\n";
        } else {
            echo "❌ Bot connection FAILED\n";
        }
    } else {
        echo "❌ Cannot reach Telegram API\n";
    }
    exit;
}

// معالجة تحديثات Telegram
$input = file_get_contents('php://input');
file_put_contents('webhook.log', date('Y-m-d H:i:s') . " - " . $input . "\n", FILE_APPEND);

$update = json_decode($input, true);

if ($update && isset($update['message'])) {
    $chatId = $update['message']['chat']['id'];
    $text = $update['message']['text'] ?? '';
    $firstName = $update['message']['from']['first_name'] ?? 'صديقي';
    
    if ($text === '/start') {
        // إرسال رد بسيط
        $url = "https://api.telegram.org/bot{$BOT_TOKEN}/sendMessage";
        $data = [
            'chat_id' => $chatId,
            'text' => "🎉 أهلاً {$firstName}!\n\n✅ البوت يعمل بنجاح!\n\n🆔 Chat ID: {$chatId}\n⏰ Time: " . date('Y-m-d H:i:s'),
            'parse_mode' => 'HTML'
        ];
        
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 5);
        $result = curl_exec($ch);
        curl_close($ch);
        
        file_put_contents('webhook.log', "Sent response: " . $result . "\n", FILE_APPEND);
    }
}

// الرد بـ OK
http_response_code(200);
echo "OK";
?>
