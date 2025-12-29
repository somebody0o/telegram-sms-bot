<?php
// ============================================
// بوت تلغرام لإرسال SMS - الإصدار المعدل
// ============================================

// تمكين عرض الأخطاء
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/php_errors.log');

// إعدادات البوت - من متغيرات البيئة
$BOT_TOKEN = getenv('8430437491:AAH6rFJTYCC9fHxrv8euLlNVA7jFgzhvg50');
$VONAGE_API_KEY = '0d887cbc';
$VONAGE_API_SECRET = 'wLvsSMD3YkHLfxmJ';
$ADMIN_GROUP_ID = '3614690801';
$ADMIN_USERNAME = '@dev_osamh';

// إذا لم يكن BOT_TOKEN مضبوطاً
if (!$BOT_TOKEN || strlen($BOT_TOKEN) < 20) {
    error_log("BOT_TOKEN not set or invalid");
    
    // إذا كان طلب GET، أظهر رسالة خطأ
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        header('Content-Type: text/plain; charset=utf-8');
        echo "❌ ERROR: BOT_TOKEN is not set!\n";
        echo "Please set BOT_TOKEN environment variable in Render.com\n";
        exit;
    }
    
    http_response_code(500);
    echo "Internal Server Error";
    exit;
}

// إعدادات المسارات
$BASE_DIR = __DIR__ . '/data/';
$USERS_DIR = $BASE_DIR . 'users/';
$BALANCE_DIR = $BASE_DIR . 'balance/';

// إنشاء المجلدات إذا لم تكن موجودة
@mkdir($USERS_DIR, 0777, true);
@mkdir($BALANCE_DIR, 0777, true);

// ============================================
// الدوال الأساسية
// ============================================

/**
 * إرسال طلب إلى API تلغرام
 */
function sendTelegram($method, $params = []) {
    global $BOT_TOKEN;
    
    $url = "https://api.telegram.org/bot{$BOT_TOKEN}/{$method}";
    
    // إضافة chat_id إذا لم يكن موجوداً في بعض الحالات
    if ($method == 'sendMessage' && !isset($params['parse_mode'])) {
        $params['parse_mode'] = 'HTML';
    }
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $params);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false); // مؤقتاً للتجربة
    
    $result = curl_exec($ch);
    
    if (curl_errno($ch)) {
        $error_msg = curl_error($ch);
        curl_close($ch);
        error_log("Telegram API Error: " . $error_msg);
        return false;
    }
    
    curl_close($ch);
    
    $json = json_decode($result, true);
    if (!$json || !isset($json['ok']) || !$json['ok']) {
        error_log("Telegram API Bad Response: " . $result);
    }
    
    return $json;
}

/**
 * معالجة أمر /start
 */
function handleStart($chatId, $userId, $userInfo) {
    $firstName = $userInfo['first_name'] ?? 'صديقي';
    $username = $userInfo['username'] ?? '';
    
    // تحقق إذا كان المستخدم مسجلاً
    $userFile = __DIR__ . "/data/users/{$userId}.json";
    $isRegistered = file_exists($userFile);
    
    if ($isRegistered) {
        // مستخدم موجود
        $balance = 0;
        $balanceFile = __DIR__ . "/data/balance/{$userId}.txt";
        if (file_exists($balanceFile)) {
            $balance = (int)file_get_contents($balanceFile);
        }
        
        $message = "👋 <b>مرحباً بعودتك {$firstName}!</b>\n\n";
        $message .= "🎯 <b>رصيدك الحالي:</b> {$balance} رسالة\n\n";
        $message .= "اختر من الخيارات:";
        
        $keyboard = [
            'inline_keyboard' => [
                [
                    ['text' => '📤 إرسال SMS', 'callback_data' => 'send_sms']
                ],
                [
                    ['text' => '💰 شراء رصيد', 'callback_data' => 'buy_credit'],
                    ['text' => '📊 رصيدي', 'callback_data' => 'check_balance']
                ]
            ]
        ];
        
    } else {
        // مستخدم جديد
        $message = "👋 <b>أهلاً وسهلاً بك {$firstName}!</b>\n\n";
        $message .= "🔹 <b>خدمة إرسال الرسائل القصيرة عبر SMS</b>\n\n";
        $message .= "لتتمكن من استخدام الخدمة، يرجى:\n";
        $message .= "1️⃣ الموافقة على الشروط\n";
        $message .= "2️⃣ إنشاء حساب جديد\n\n";
        $message .= "💡 <b>ملاحظة:</b> ستبدأ برصيد 0 رسالة";
        
        $keyboard = [
            'inline_keyboard' => [
                [
                    ['text' => '✅ إنشاء حساب وموافقة', 'callback_data' => 'register_' . $userId]
                ]
            ]
        ];
    }
    
    return sendTelegram('sendMessage', [
        'chat_id' => $chatId,
        'text' => $message,
        'reply_markup' => json_encode($keyboard),
        'parse_mode' => 'HTML'
    ]);
}

/**
 * معالجة تسجيل مستخدم جديد
 */
function handleRegister($userId, $userInfo) {
    $firstName = $userInfo['first_name'] ?? '';
    $lastName = $userInfo['last_name'] ?? '';
    $username = $userInfo['username'] ?? '';
    
    // حفظ بيانات المستخدم
    $userData = [
        'id' => $userId,
        'username' => $username,
        'first_name' => $firstName,
        'last_name' => $lastName,
        'registered_at' => date('Y-m-d H:i:s'),
        'status' => 'active'
    ];
    
    $userFile = __DIR__ . "/data/users/{$userId}.json";
    file_put_contents($userFile, json_encode($userData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    
    // إنشاء ملف الرصيد
    $balanceFile = __DIR__ . "/data/balance/{$userId}.txt";
    file_put_contents($balanceFile, '0');
    
    // إرسال رسالة للمسؤول
    global $ADMIN_GROUP_ID, $ADMIN_USERNAME;
    
    $adminMessage = "👤 <b>مستخدم جديد مسجل</b>\n\n";
    $adminMessage .= "🆔 <b>المعرف:</b> <code>{$userId}</code>\n";
    $adminMessage .= "👤 <b>الاسم:</b> {$firstName} {$lastName}\n";
    $adminMessage .= "📛 <b>اليوزر:</b> " . ($username ? "@{$username}" : "غير متوفر") . "\n\n";
    $adminMessage .= "📊 <b>الرصيد الحالي:</b> 0 رسالة";
    
    $adminKeyboard = [
        'inline_keyboard' => [
            [
                ['text' => '➕ شحن 10 رسائل', 'callback_data' => 'charge_' . $userId . '_10'],
                ['text' => '➕ شحن 50 رسالة', 'callback_data' => 'charge_' . $userId . '_50']
            ]
        ]
    ];
    
    sendTelegram('sendMessage', [
        'chat_id' => $ADMIN_GROUP_ID,
        'text' => $adminMessage,
        'reply_markup' => json_encode($adminKeyboard),
        'parse_mode' => 'HTML'
    ]);
    
    // إرسال رسالة ترحيب للمستخدم
    $welcomeMessage = "🎉 <b>مبروك {$firstName}!</b>\n\n";
    $welcomeMessage .= "✅ <b>تم إنشاء حسابك بنجاح</b>\n\n";
    $welcomeMessage .= "📊 <b>رصيدك الحالي:</b> 0 رسالة\n\n";
    $welcomeMessage .= "🔹 يمكنك الآن استخدام الخدمة\n";
    $welcomeMessage .= "🔹 تواصل مع {$ADMIN_USERNAME} لشراء الرصيد\n\n";
    $welcomeMessage .= "💡 <b>نصيحة:</b> يمكن للمسؤول شحن رصيدك من الإشعار الذي تم إرساله له";
    
    $userKeyboard = [
        'inline_keyboard' => [
            [
                ['text' => '📤 إرسال SMS', 'callback_data' => 'send_sms']
            ],
            [
                ['text' => '💰 شراء رصيد', 'callback_data' => 'buy_credit'],
                ['text' => '📊 رصيدي', 'callback_data' => 'check_balance']
            ]
        ]
    ];
    
    return sendTelegram('sendMessage', [
        'chat_id' => $userId,
        'text' => $welcomeMessage,
        'reply_markup' => json_encode($userKeyboard),
        'parse_mode' => 'HTML'
    ]);
}

/**
 * معالجة زر إرسال SMS
 */
function handleSendSMS($userId) {
    // التحقق من الرصيد أولاً
    $balanceFile = __DIR__ . "/data/balance/{$userId}.txt";
    $balance = file_exists($balanceFile) ? (int)file_get_contents($balanceFile) : 0;
    
    if ($balance <= 0) {
        $message = "❌ <b>عفواً، رصيدك غير كافي</b>\n\n";
        $message .= "📊 <b>رصيدك الحالي:</b> 0 رسالة\n\n";
        $message .= "⚠️ يجب شحن الرصيد أولاً قبل الإرسال";
        
        $keyboard = [
            'inline_keyboard' => [
                [
                    ['text' => '💰 شراء رصيد', 'callback_data' => 'buy_credit']
                ]
            ]
        ];
        
        return sendTelegram('sendMessage', [
            'chat_id' => $userId,
            'text' => $message,
            'reply_markup' => json_encode($keyboard),
            'parse_mode' => 'HTML'
        ]);
    }
    
    // حفظ حالة المستخدم
    $stateFile = __DIR__ . "/data/users/{$userId}_state.json";
    file_put_contents($stateFile, json_encode([
        'state' => 'awaiting_phone',
        'timestamp' => time()
    ]));
    
    $message = "📱 <b>إرسال رسالة SMS</b>\n\n";
    $message .= "1️⃣ <b>الخطوة الأولى:</b>\n";
    $message .= "أرسل رقم الهاتف بالصيغة الدولية\n\n";
    $message .= "📌 <b>مثال:</b>\n";
    $message .= "<code>+201234567890</code>\n\n";
    $message .= "📊 <b>رصيدك المتاح:</b> {$balance} رسالة";
    
    $keyboard = [
        'inline_keyboard' => [
            [
                ['text' => '🚫 إلغاء', 'callback_data' => 'cancel_sms']
            ]
        ]
    ];
    
    return sendTelegram('sendMessage', [
        'chat_id' => $userId,
        'text' => $message,
        'reply_markup' => json_encode($keyboard),
        'parse_mode' => 'HTML'
    ]);
}

/**
 * معالجة ضغطات الأزرار (Callback Queries)
 */
function handleCallbackQuery($callback) {
    $data = $callback['data'];
    $userId = $callback['from']['id'];
    $messageId = $callback['message']['message_id'];
    $chatId = $callback['message']['chat']['id'];
    
    // الرد الفوري على Callback Query
    sendTelegram('answerCallbackQuery', [
        'callback_query_id' => $callback['id']
    ]);
    
    // فتح الأزرار المختلفة
    if ($data === 'buy_credit') {
        global $ADMIN_USERNAME;
        
        $message = "💰 <b>شراء رصيد إضافي</b>\n\n";
        $message .= "لشراء رصيد إضافي، يرجى التواصل مع:\n";
        $message .= "<b>{$ADMIN_USERNAME}</b>\n\n";
        $message .= "📞 سيقوم المسؤول بالرد عليك في أقرب وقت";
        
        return sendTelegram('editMessageText', [
            'chat_id' => $chatId,
            'message_id' => $messageId,
            'text' => $message,
            'parse_mode' => 'HTML'
        ]);
        
    } elseif ($data === 'check_balance') {
        $balanceFile = __DIR__ . "/data/balance/{$userId}.txt";
        $balance = file_exists($balanceFile) ? (int)file_get_contents($balanceFile) : 0;
        
        $message = "📊 <b>رصيدك الحالي</b>\n\n";
        $message .= "🎯 <b>عدد الرسائل المتاحة:</b> {$balance}\n\n";
        
        if ($balance <= 0) {
            $message .= "⚠️ <b>رصيدك نفذ!</b>\n";
            $message .= "يرجى شحن الرصيد للمتابعة";
        } else {
            $message .= "✅ يمكنك إرسال {$balance} رسالة";
        }
        
        return sendTelegram('editMessageText', [
            'chat_id' => $chatId,
            'message_id' => $messageId,
            'text' => $message,
            'parse_mode' => 'HTML'
        ]);
        
    } elseif (strpos($data, 'register_') === 0) {
        // استخراج userId من البيانات
        $targetUserId = str_replace('register_', '', $data);
        return handleRegister($targetUserId, $callback['from']);
        
    } elseif ($data === 'send_sms') {
        return handleSendSMS($userId);
        
    } elseif ($data === 'cancel_sms') {
        // إلغاء عملية الإرسال
        $stateFile = __DIR__ . "/data/users/{$userId}_state.json";
        if (file_exists($stateFile)) {
            unlink($stateFile);
        }
        
        return handleStart($chatId, $userId, $callback['from']);
        
    } elseif (strpos($data, 'charge_') === 0) {
        // شحن الرصيد من الأدمن
        return handleAdminCharge($data, $chatId, $messageId, $callback['from']);
    }
    
    return false;
}

/**
 * معالجة شحن الرصيد من الأدمن
 */
function handleAdminCharge($callbackData, $chatId, $messageId, $adminInfo) {
    global $ADMIN_GROUP_ID;
    
    // التحقق من أن الرسالة من الجروب الإداري
    if ($chatId != $ADMIN_GROUP_ID) {
        return false;
    }
    
    // تحليل البيانات: charge_USERID_AMOUNT
    $parts = explode('_', $callbackData);
    if (count($parts) != 3) {
        return false;
    }
    
    $targetUserId = $parts[1];
    $amount = (int)$parts[2];
    
    // تحديث الرصيد
    $balanceFile = __DIR__ . "/data/balance/{$targetUserId}.txt";
    $currentBalance = file_exists($balanceFile) ? (int)file_get_contents($balanceFile) : 0;
    $newBalance = $currentBalance + $amount;
    
    file_put_contents($balanceFile, $newBalance);
    
    // تحديث رسالة الأدمن
    $adminUsername = $adminInfo['username'] ?? 'غير معروف';
    
    $message = "✅ <b>تم شحن الرصيد بنجاح</b>\n\n";
    $message .= "👤 <b>المستخدم:</b> <code>{$targetUserId}</code>\n";
    $message .= "📦 <b>الكمية المضافة:</b> {$amount} رسالة\n";
    $message .= "💰 <b>الرصيد الجديد:</b> {$newBalance} رسالة\n";
    $message .= "👨‍💼 <b>الأدمن:</b> " . ($adminUsername ? "@{$adminUsername}" : "غير معروف");
    
    sendTelegram('editMessageText', [
        'chat_id' => $chatId,
        'message_id' => $messageId,
        'text' => $message,
        'parse_mode' => 'HTML'
    ]);
    
    // إرسال إشعار للمستخدم
    $userMessage = "🎉 <b>تهانينا!</b>\n\n";
    $userMessage .= "✅ <b>تم شحن رصيدك بنجاح</b>\n\n";
    $userMessage .= "📦 <b>الرسائل المضافة:</b> {$amount}\n";
    $userMessage .= "💰 <b>رصيدك الحالي:</b> {$newBalance} رسالة\n\n";
    $userMessage .= "🔹 يمكنك الآن استخدام الخدمة";
    
    sendTelegram('sendMessage', [
        'chat_id' => $targetUserId,
        'text' => $userMessage,
        'parse_mode' => 'HTML'
    ]);
    
    return true;
}

/**
 * معالجة الرسائل النصية
 */
function handleTextMessage($chatId, $userId, $text, $userInfo) {
    // التحقق من حالة المستخدم
    $stateFile = __DIR__ . "/data/users/{$userId}_state.json";
    
    if (file_exists($stateFile)) {
        $stateData = json_decode(file_get_contents($stateFile), true);
        $state = $stateData['state'] ?? '';
        
        if ($state === 'awaiting_phone') {
            // المستخدم يرسل رقم الهاتف
            $phone = trim($text);
            
            // التحقق من صيغة الرقم
            if (!preg_match('/^\+[1-9]\d{1,14}$/', $phone)) {
                sendTelegram('sendMessage', [
                    'chat_id' => $chatId,
                    'text' => "❌ <b>رقم غير صحيح</b>\n\nالرجاء إرسال الرقم بالصيغة الدولية:\nمثال: <code>+201234567890</code>",
                    'parse_mode' => 'HTML'
                ]);
                return;
            }
            
            // حفظ الرقم والانتقال للمرحلة التالية
            $stateData['phone'] = $phone;
            $stateData['state'] = 'awaiting_message';
            $stateData['timestamp'] = time();
            file_put_contents($stateFile, json_encode($stateData));
            
            sendTelegram('sendMessage', [
                'chat_id' => $chatId,
                'text' => "✅ <b>تم حفظ الرقم</b>\n\n✍️ <b>الآن أرسل نص الرسالة:</b>\n\nاكتب النص الذي تريد إرساله:",
                'parse_mode' => 'HTML'
            ]);
            
        } elseif ($state === 'awaiting_message') {
            // المستخدم يرسل نص الرسالة
            $stateData = json_decode(file_get_contents($stateFile), true);
            $phone = $stateData['phone'] ?? '';
            $messageText = trim($text);
            
            if (empty($messageText)) {
                sendTelegram('sendMessage', [
                    'chat_id' => $chatId,
                    'text' => "❌ النص فارغ\nيرجى إرسال نص الرسالة:",
                    'parse_mode' => 'HTML'
                ]);
                return;
            }
            
            // التحقق من الرصيد مرة أخرى
            $balanceFile = __DIR__ . "/data/balance/{$userId}.txt";
            $balance = file_exists($balanceFile) ? (int)file_get_contents($balanceFile) : 0;
            
            if ($balance <= 0) {
                // حذف حالة المستخدم
                unlink($stateFile);
                
                sendTelegram('sendMessage', [
                    'chat_id' => $chatId,
                    'text' => "❌ <b>عفواً، نفذ رصيدك</b>\n\nيرجى شحن الرصيد أولاً.",
                    'parse_mode' => 'HTML'
                ]);
                return;
            }
            
            // إرسال رسالة تجهيز
            sendTelegram('sendMessage', [
                'chat_id' => $chatId,
                'text' => "⏳ <b>جاري الإرسال...</b>\n\n📱 إلى: <code>{$phone}</code>",
                'parse_mode' => 'HTML'
            ]);
            
            // إرسال SMS عبر Vonage
            global $VONAGE_API_KEY, $VONAGE_API_SECRET;
            
            $url = 'https://rest.nexmo.com/sms/json';
            $postData = [
                'api_key' => $VONAGE_API_KEY,
                'api_secret' => $VONAGE_API_SECRET,
                'to' => $phone,
                'from' => 'VonageSMS',
                'text' => $messageText,
                'type' => 'unicode'
            ];
            
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($postData));
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            $response = curl_exec($ch);
            curl_close($ch);
            
            $result = json_decode($response, true);
            
            // حذف حالة المستخدم
            unlink($stateFile);
            
            // معالجة النتيجة
            if (isset($result['messages'][0]['status']) && $result['messages'][0]['status'] == '0') {
                // نجاح الإرسال - خصم رسالة
                $newBalance = $balance - 1;
                file_put_contents($balanceFile, $newBalance);
                
                $successMessage = "✅ <b>تم إرسال الرسالة بنجاح!</b>\n\n";
                $successMessage .= "📱 <b>إلى:</b> <code>{$phone}</code>\n";
                $messagePreview = strlen($messageText) > 50 ? substr($messageText, 0, 50) . '...' : $messageText;
                $successMessage .= "📝 <b>الرسالة:</b> {$messagePreview}\n\n";
                $successMessage .= "💰 <b>الرصيد المتبقي:</b> {$newBalance} رسالة\n\n";
                $successMessage .= "🆔 <b>كود التتبع:</b> " . ($result['messages'][0]['message-id'] ?? 'N/A');
                
            } else {
                // فشل الإرسال
                $error = $result['messages'][0]['error-text'] ?? 'خطأ غير معروف';
                $successMessage = "❌ <b>فشل إرسال الرسالة</b>\n\n";
                $successMessage .= "📱 <b>إلى:</b> <code>{$phone}</code>\n";
                $successMessage .= "⚠️ <b>سبب الخطأ:</b> {$error}\n\n";
                $successMessage .= "💰 <b>رصيدك لم يتغير:</b> {$balance} رسالة";
            }
            
            sendTelegram('sendMessage', [
                'chat_id' => $chatId,
                'text' => $successMessage,
                'parse_mode' => 'HTML'
            ]);
            
            return;
        }
        
        // حذف ملف الحالة القديم (أكثر من ساعة)
        if (isset($stateData['timestamp']) && (time() - $stateData['timestamp']) > 3600) {
            unlink($stateFile);
        }
    }
    
    // إذا لم تكن هناك حالة خاصة، معالجة كرسالة عادية
    if ($text === '/start') {
        handleStart($chatId, $userId, $userInfo);
    } else {
        // إذا كان المستخدم مسجلاً، إعادة القائمة
        $userFile = __DIR__ . "/data/users/{$userId}.json";
        if (file_exists($userFile)) {
            handleStart($chatId, $userId, $userInfo);
        } else {
            sendTelegram('sendMessage', [
                'chat_id' => $chatId,
                'text' => "⚠️ <b>يرجى التسجيل أولاً</b>\n\nاستخدم الأمر /start للبدء",
                'parse_mode' => 'HTML'
            ]);
        }
    }
}

// ============================================
// نقطة الدخول الرئيسية
// ============================================

// تسجيل الطلب للتتبع
file_put_contents(__DIR__ . '/data/request.log', 
    date('Y-m-d H:i:s') . " - " . file_get_contents('php://input') . "\n", 
    FILE_APPEND
);

// إذا كان طلب GET (للاختبار)
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    header('Content-Type: text/plain; charset=utf-8');
    echo "🤖 Telegram SMS Bot\n";
    echo "==================\n";
    echo "✅ Server is working!\n";
    echo "📅 Time: " . date('Y-m-d H:i:s') . "\n";
    echo "🌐 IP: " . $_SERVER['SERVER_ADDR'] . "\n";
    
    // اختبار اتصال بالتليجرام
    if ($BOT_TOKEN) {
        echo "\n🔗 Testing Telegram API...\n";
        $test = sendTelegram('getMe');
        if ($test && isset($test['ok']) && $test['ok']) {
            echo "✅ Bot Name: @" . $test['result']['username'] . "\n";
            echo "✅ Bot ID: " . $test['result']['id'] . "\n";
        } else {
            echo "❌ Telegram API Error\n";
        }
    }
    
    exit;
}

// استقبال البيانات من Telegram
$input = file_get_contents('php://input');
$update = json_decode($input, true);

if (!$update) {
    http_response_code(400);
    echo "Invalid request";
    exit;
}

// معالجة التحديث
try {
    if (isset($update['callback_query'])) {
        // معالجة ضغطات الأزرار
        handleCallbackQuery($update['callback_query']);
        
    } elseif (isset($update['message'])) {
        $message = $update['message'];
        $chatId = $message['chat']['id'];
        $userId = $message['from']['id'];
        $text = $message['text'] ?? '';
        $userInfo = $message['from'];
        
        // معالجة الرسالة النصية
        handleTextMessage($chatId, $userId, $text, $userInfo);
    }
} catch (Exception $e) {
    error_log("Exception: " . $e->getMessage());
}

// الرد دائماً بـ OK
http_response_code(200);
echo "OK";
?>
