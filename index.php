<?php
// ============================================
// بوت تلغرام لإرسال SMS عبر Vonage API
// مع نظام أرصدة ولوحة تحكم إدارية
// ============================================

// إعدادات البوت - من متغيرات البيئة
$BOT_TOKEN = getenv('BOT_TOKEN') ?: '8430437491:AAH6rFJTYCC9fHxrv8euLlNVA7jFgzhvg50';
$VONAGE_API_KEY = getenv('VONAGE_API_KEY') ?: '0d887cbc';
$VONAGE_API_SECRET = getenv('VONAGE_API_SECRET') ?: 'wLvsSMD3YkHLfxmJ';
$ADMIN_GROUP_ID = getenv('ADMIN_GROUP_ID') ?: '3614690801';
$ADMIN_USERNAME = getenv('ADMIN_USERNAME') ?: '@dev_osamh';

// إعدادات المسارات - معدلة للدوكر
$BASE_DIR = __DIR__ . '/data/';
$USERS_DIR = $BASE_DIR . 'users/';
$BALANCE_DIR = $BASE_DIR . 'balance/';
$LOG_FILE = $BASE_DIR . 'bot.log';

// إنشاء المجلدات إذا لم تكن موجودة
if (!file_exists($USERS_DIR)) {
    mkdir($USERS_DIR, 0777, true);
}
if (!file_exists($BALANCE_DIR)) {
    mkdir($BALANCE_DIR, 0777, true);
}

// كتابة سجل بدء التشغيل
logMessage('INFO', 'Bot started at ' . date('Y-m-d H:i:s'));

// ============================================
// الدوال المساعدة
// ============================================

/**
 * تسجيل الرسائل في ملف السجل
 */
function logMessage($type, $message) {
    global $LOG_FILE;
    $logEntry = '[' . date('Y-m-d H:i:s') . '] [' . $type . '] ' . $message . PHP_EOL;
    file_put_contents($LOG_FILE, $logEntry, FILE_APPEND);
}

/**
 * إرسال طلب إلى API تلغرام
 */
function sendTelegramRequest($method, $parameters = []) {
    global $BOT_TOKEN;
    
    $url = "https://api.telegram.org/bot" . $BOT_TOKEN . "/" . $method;
    
    // تحويل المصفوفات إلى JSON إذا لزم الأمر
    foreach ($parameters as $key => $value) {
        if (is_array($value)) {
            $parameters[$key] = json_encode($value);
        }
    }
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $parameters);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/x-www-form-urlencoded'
    ]);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    
    if (curl_errno($ch)) {
        $error = curl_error($ch);
        curl_close($ch);
        logMessage('ERROR', 'Telegram API CURL Error: ' . $error);
        return false;
    }
    
    curl_close($ch);
    
    $decoded = json_decode($response, true);
    if (!$decoded || !$decoded['ok']) {
        logMessage('ERROR', 'Telegram API Error: ' . $response);
    }
    
    return $decoded;
}

/**
 * حفظ بيانات المستخدم
 */
function saveUserData($userId, $userData) {
    global $USERS_DIR;
    $file = $USERS_DIR . $userId . '.json';
    
    $userData['last_updated'] = time();
    
    $tempFile = $file . '.tmp';
    $result = file_put_contents($tempFile, json_encode($userData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    
    if ($result !== false) {
        rename($tempFile, $file);
        logMessage('INFO', "User data saved: {$userId}");
        return true;
    }
    
    logMessage('ERROR', "Failed to save user data: {$userId}");
    return false;
}

/**
 * تحميل بيانات المستخدم
 */
function loadUserData($userId) {
    global $USERS_DIR;
    $file = $USERS_DIR . $userId . '.json';
    
    if (file_exists($file)) {
        $content = @file_get_contents($file);
        if ($content !== false) {
            $data = json_decode($content, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                return $data;
            }
        }
    }
    return null;
}

/**
 * الحصول على رصيد المستخدم
 */
function getUserBalance($userId) {
    global $BALANCE_DIR;
    $file = $BALANCE_DIR . $userId . '.txt';
    
    if (file_exists($file)) {
        $content = @file_get_contents($file);
        if ($content !== false) {
            return (int)trim($content);
        }
    }
    
    // إذا لم يكن هناك ملف، نبدأ من الصفر
    updateUserBalance($userId, 0);
    return 0;
}

/**
 * تحديث رصيد المستخدم
 */
function updateUserBalance($userId, $amount) {
    global $BALANCE_DIR;
    $file = $BALANCE_DIR . $userId . '.txt';
    
    $amount = max(0, (int)$amount);
    
    $tempFile = $file . '.tmp';
    $result = file_put_contents($tempFile, $amount);
    
    if ($result !== false) {
        rename($tempFile, $file);
        logMessage('INFO', "Balance updated: {$userId} -> {$amount}");
        return true;
    }
    
    logMessage('ERROR', "Failed to update balance: {$userId}");
    return false;
}

/**
 * إرسال رسالة عبر Vonage API
 */
function sendSMSviaVonage($to, $text) {
    global $VONAGE_API_KEY, $VONAGE_API_SECRET;
    
    $url = 'https://rest.nexmo.com/sms/json';
    
    $postData = [
        'api_key' => $VONAGE_API_KEY,
        'api_secret' => $VONAGE_API_SECRET,
        'to' => $to,
        'from' => 'VonageSMS',
        'text' => $text,
        'type' => 'unicode'
    ];
    
    logMessage('INFO', "Sending SMS to: {$to}, length: " . strlen($text));
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($postData));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    
    $response = curl_exec($ch);
    
    if (curl_errno($ch)) {
        $error = curl_error($ch);
        curl_close($ch);
        logMessage('ERROR', 'Vonage CURL Error: ' . $error);
        return ['error' => $error];
    }
    
    curl_close($ch);
    
    logMessage('INFO', 'Vonage Response: ' . substr($response, 0, 200));
    return json_decode($response, true);
}

/**
 * إرسال إشعار للمسؤولين عن مستخدم جديد
 */
function notifyAdminNewUser($userId, $username, $firstName, $lastName) {
    global $ADMIN_GROUP_ID;
    
    $message = "👤 *مستخدم جديد مسجل*\n\n";
    $message .= "🆔 المعرف: `" . $userId . "`\n";
    $message .= "👤 الاسم: " . htmlspecialchars($firstName . " " . $lastName) . "\n";
    $message .= "📛 اليوزر: " . ($username ? htmlspecialchars($username) : 'غير متوفر') . "\n\n";
    $message .= "📊 الرصيد الحالي: 0 رسالة";
    
    $keyboard = [
        'inline_keyboard' => [
            [
                ['text' => '➕ شحن 10 رسائل', 'callback_data' => 'charge_' . $userId . '_10'],
                ['text' => '➕ شحن 50 رسالة', 'callback_data' => 'charge_' . $userId . '_50']
            ]
        ]
    ];
    
    $result = sendTelegramRequest('sendMessage', [
        'chat_id' => $ADMIN_GROUP_ID,
        'text' => $message,
        'parse_mode' => 'Markdown',
        'reply_markup' => json_encode($keyboard)
    ]);
    
    if ($result && $result['ok']) {
        logMessage('INFO', "Admin notified about new user: {$userId}");
    } else {
        logMessage('ERROR', "Failed to notify admin about user: {$userId}");
    }
    
    return $result;
}

// ============================================
// معالجات الكولباك كويري (Callback Queries)
// ============================================

/**
 * معالجة جميع ضغطات الأزرار
 */
function processCallbackQuery($callbackQuery) {
    $data = $callbackQuery['data'] ?? '';
    $callbackId = $callbackQuery['id'] ?? '';
    $userId = $callbackQuery['from']['id'] ?? 0;
    
    logMessage('INFO', "Callback query received: {$data} from user: {$userId}");
    
    // الرد الفوري لمنع تجمد البوت
    sendTelegramRequest('answerCallbackQuery', [
        'callback_query_id' => $callbackId
    ]);
    
    // معالجة الأنواع المختلفة من الأزرار
    if (strpos($data, 'register_') === 0) {
        handleRegistration($userId, $callbackQuery['from']);
        
    } elseif (strpos($data, 'charge_') === 0) {
        $messageId = $callbackQuery['message']['message_id'] ?? 0;
        $chatId = $callbackQuery['message']['chat']['id'] ?? 0;
        handleBalanceCharge($data, $chatId, $messageId, $callbackQuery['from']);
        
    } elseif ($data === 'send_sms') {
        handleSendSMSRequest($userId);
        
    } elseif ($data === 'buy_credit') {
        handleBuyCredit($userId);
        
    } elseif ($data === 'check_balance') {
        handleCheckBalance($userId);
        
    } elseif ($data === 'main_menu') {
        showMainMenu($userId);
        
    } else {
        logMessage('WARNING', "Unknown callback data: {$data}");
    }
}

/**
 * معالجة تسجيل المستخدم الجديد
 */
function handleRegistration($userId, $userInfo) {
    logMessage('INFO', "Processing registration for user: {$userId}");
    
    // إنشاء بيانات المستخدم
    $userData = [
        'id' => $userId,
        'username' => $userInfo['username'] ?? '',
        'first_name' => $userInfo['first_name'] ?? '',
        'last_name' => $userInfo['last_name'] ?? '',
        'registered_at' => date('Y-m-d H:i:s'),
        'status' => 'active',
        'language_code' => $userInfo['language_code'] ?? 'ar'
    ];
    
    // حفظ بيانات المستخدم
    if (saveUserData($userId, $userData)) {
        // إرسال إشعار للمسؤول
        notifyAdminNewUser(
            $userId,
            $userInfo['username'] ?? '',
            $userInfo['first_name'] ?? '',
            $userInfo['last_name'] ?? ''
        );
        
        // إرسال رسالة ترحيب
        $balance = getUserBalance($userId);
        sendWelcomeMessage($userId, $balance);
        
        logMessage('INFO', "User registered successfully: {$userId}");
    } else {
        sendTelegramRequest('sendMessage', [
            'chat_id' => $userId,
            'text' => "❌ حدث خطأ أثناء التسجيل. يرجى المحاولة مرة أخرى.",
            'parse_mode' => 'Markdown'
        ]);
        logMessage('ERROR', "Registration failed for user: {$userId}");
    }
}

/**
 * إرسال رسالة ترحيب
 */
function sendWelcomeMessage($userId, $balance) {
    global $ADMIN_USERNAME;
    
    $message = "🎉 *مرحباً بك في خدمة إرسال الرسائل القصيرة*\n\n";
    $message .= "✅ تم تفعيل حسابك بنجاح!\n";
    $message .= "يمكنك الآن استخدام الخدمة لإرسال الرسائل.\n\n";
    $message .= "📊 *رصيدك الحالي:* " . $balance . " رسالة\n\n";
    $message .= "📞 للإستفسارات: " . $ADMIN_USERNAME;
    
    $keyboard = [
        'inline_keyboard' => [
            [
                ['text' => '📤 إرسال SMS', 'callback_data' => 'send_sms'],
                ['text' => '💰 شراء رصيد', 'callback_data' => 'buy_credit']
            ],
            [
                ['text' => '📊 رصيدي', 'callback_data' => 'check_balance']
            ]
        ]
    ];
    
    sendTelegramRequest('sendMessage', [
        'chat_id' => $userId,
        'text' => $message,
        'parse_mode' => 'Markdown',
        'reply_markup' => json_encode($keyboard)
    ]);
}

/**
 * عرض القائمة الرئيسية
 */
function showMainMenu($userId) {
    $balance = getUserBalance($userId);
    
    $message = "🏠 *القائمة الرئيسية*\n\n";
    $message .= "📊 رصيدك الحالي: " . $balance . " رسالة\n\n";
    $message .= "اختر من الخيارات:";
    
    $keyboard = [
        'inline_keyboard' => [
            [
                ['text' => '📤 إرسال SMS', 'callback_data' => 'send_sms'],
                ['text' => '💰 شراء رصيد', 'callback_data' => 'buy_credit']
            ],
            [
                ['text' => '📊 رصيدي', 'callback_data' => 'check_balance']
            ]
        ]
    ];
    
    sendTelegramRequest('sendMessage', [
        'chat_id' => $userId,
        'text' => $message,
        'parse_mode' => 'Markdown',
        'reply_markup' => json_encode($keyboard)
    ]);
}

/**
 * معالجة طلب إرسال SMS
 */
function handleSendSMSRequest($userId) {
    $balance = getUserBalance($userId);
    
    if ($balance <= 0) {
        $message = "❌ *عفواً، رصيدك غير كافي*\n\n";
        $message .= "📊 رصيدك الحالي: 0 رسالة\n\n";
        $message .= "يرجى شراء رصيد لمتابعة عملية الإرسال.";
        
        $keyboard = [
            'inline_keyboard' => [
                [
                    ['text' => '💰 شراء رصيد', 'callback_data' => 'buy_credit'],
                    ['text' => '🏠 القائمة', 'callback_data' => 'main_menu']
                ]
            ]
        ];
        
        sendTelegramRequest('sendMessage', [
            'chat_id' => $userId,
            'text' => $message,
            'parse_mode' => 'Markdown',
            'reply_markup' => json_encode($keyboard)
        ]);
        logMessage('INFO', "User {$userId} has insufficient balance");
        return;
    }
    
    // حفظ حالة المستخدم
    $userData = loadUserData($userId) ?: [];
    $userData['state'] = 'awaiting_phone';
    saveUserData($userId, $userData);
    
    $message = "📱 *إرسال رسالة SMS*\n\n";
    $message .= "1️⃣ الرجاء إرسال رقم الهاتف بالصيغة الدولية\n";
    $message .= "مثال: `+201234567890`\n\n";
    $message .= "📊 الرصيد المتاح: " . $balance . " رسالة";
    
    $keyboard = [
        'inline_keyboard' => [
            [
                ['text' => '🏠 إلغاء والعودة للقائمة', 'callback_data' => 'main_menu']
            ]
        ]
    ];
    
    sendTelegramRequest('sendMessage', [
        'chat_id' => $userId,
        'text' => $message,
        'parse_mode' => 'Markdown',
        'reply_markup' => json_encode($keyboard)
    ]);
    
    logMessage('INFO', "User {$userId} started SMS process, balance: {$balance}");
}

/**
 * معالجة شحن الرصيد من الأدمن
 */
function handleBalanceCharge($callbackData, $chatId, $messageId, $adminInfo) {
    global $ADMIN_GROUP_ID;
    
    // التحقق من الصلاحية
    if ((string)$chatId !== (string)$ADMIN_GROUP_ID) {
        logMessage('WARNING', "Unauthorized balance charge attempt from chat: {$chatId}");
        return;
    }
    
    // تحليل البيانات
    $parts = explode('_', $callbackData);
    if (count($parts) !== 3) {
        logMessage('ERROR', "Invalid charge data format: {$callbackData}");
        return;
    }
    
    $targetUserId = $parts[1];
    $amount = (int)$parts[2];
    
    if ($amount <= 0) {
        logMessage('ERROR', "Invalid charge amount: {$amount}");
        return;
    }
    
    logMessage('INFO', "Processing charge: {$amount} messages to user {$targetUserId} by admin {$adminInfo['id']}");
    
    // شحن الرصيد
    $currentBalance = getUserBalance($targetUserId);
    $newBalance = $currentBalance + $amount;
    
    if (updateUserBalance($targetUserId, $newBalance)) {
        // تحديث رسالة الأدمن
        $adminMessage = "✅ *تم شحن الرصيد بنجاح*\n\n";
        $adminMessage .= "👤 المستخدم: `" . $targetUserId . "`\n";
        $adminMessage .= "📦 الكمية: " . $amount . " رسالة\n";
        $adminMessage .= "📊 الرصيد الجديد: " . $newBalance . " رسالة\n";
        $adminMessage .= "👨‍💼 الأدمن: " . ($adminInfo['username'] ?? 'غير معروف');
        
        sendTelegramRequest('editMessageText', [
            'chat_id' => $chatId,
            'message_id' => $messageId,
            'text' => $adminMessage,
            'parse_mode' => 'Markdown'
        ]);
        
        // إشعار المستخدم
        $userMessage = "🎉 *تم شحن رصيدك بنجاح*\n\n";
        $userMessage .= "📦 الرسائل المضافة: " . $amount . "\n";
        $userMessage .= "📊 رصيدك الحالي: " . $newBalance . " رسالة\n\n";
        $userMessage .= "شكراً لاستخدامك خدمتنا!";
        
        sendTelegramRequest('sendMessage', [
            'chat_id' => $targetUserId,
            'text' => $userMessage,
            'parse_mode' => 'Markdown'
        ]);
        
        logMessage('INFO', "Balance charged successfully: {$targetUserId} +{$amount} = {$newBalance}");
    } else {
        logMessage('ERROR', "Failed to charge balance: {$targetUserId}");
    }
}

/**
 * معالجة طلب شراء رصيد
 */
function handleBuyCredit($userId) {
    global $ADMIN_USERNAME;
    
    $message = "💰 *شراء رصيد إضافي*\n\n";
    $message .= "لشراء رصيد إضافي، يرجى التواصل مع:\n";
    $message .= $ADMIN_USERNAME . "\n\n";
    $message .= "سيقوم المسؤول بالرد عليك في أقرب وقت.";
    
    $keyboard = [
        'inline_keyboard' => [
            [
                ['text' => '🏠 القائمة الرئيسية', 'callback_data' => 'main_menu']
            ]
        ]
    ];
    
    sendTelegramRequest('sendMessage', [
        'chat_id' => $userId,
        'text' => $message,
        'parse_mode' => 'Markdown',
        'reply_markup' => json_encode($keyboard)
    ]);
    
    logMessage('INFO', "User {$userId} requested to buy credit");
}

/**
 * معالجة طلب التحقق من الرصيد
 */
function handleCheckBalance($userId) {
    $balance = getUserBalance($userId);
    
    $message = "📊 *رصيدك الحالي*\n\n";
    $message .= "عدد الرسائل المتاحة: *" . $balance . "*\n\n";
    
    if ($balance <= 0) {
        $message .= "⚠️ رصيدك نفذ، يرجى الشحن للمتابعة.";
    } else {
        $message .= "يمكنك إرسال " . $balance . " رسالة.";
    }
    
    $keyboard = [
        'inline_keyboard' => [
            [
                ['text' => '📤 إرسال SMS', 'callback_data' => 'send_sms'],
                ['text' => '💰 شراء رصيد', 'callback_data' => 'buy_credit']
            ],
            [
                ['text' => '🏠 القائمة', 'callback_data' => 'main_menu']
            ]
        ]
    ];
    
    sendTelegramRequest('sendMessage', [
        'chat_id' => $userId,
        'text' => $message,
        'parse_mode' => 'Markdown',
        'reply_markup' => json_encode($keyboard)
    ]);
    
    logMessage('INFO', "User {$userId} checked balance: {$balance}");
}

// ============================================
// معالجة الرسائل النصية
// ============================================

/**
 * معالجة الرسائل النصية الواردة
 */
function processTextMessage($message) {
    $userId = $message['from']['id'] ?? 0;
    $text = $message['text'] ?? '';
    $chatId = $message['chat']['id'] ?? 0;
    
    logMessage('INFO', "Text message from {$userId}: " . substr($text, 0, 100));
    
    // تجاهل الرسائل الفارغة
    if (empty(trim($text))) {
        return;
    }
    
    // الأمر /start
    if ($text === '/start') {
        handleStartCommand($userId, $chatId, $message['from']);
        return;
    }
    
    // التحقق من تسجيل المستخدم
    $userData = loadUserData($userId);
    if (!$userData || ($userData['status'] ?? '') !== 'active') {
        sendTelegramRequest('sendMessage', [
            'chat_id' => $chatId,
            'text' => "⚠️ يرجى التسجيل أولاً باستخدام الأمر /start",
            'parse_mode' => 'Markdown'
        ]);
        logMessage('WARNING', "Unregistered user {$userId} tried to send message");
        return;
    }
    
    // معالجة حالة المستخدم
    if (isset($userData['state'])) {
        handleUserState($userId, $chatId, $text, $userData);
        return;
    }
    
    // إذا لم تكن هناك حالة خاصة، إرسال القائمة
    showMainMenu($userId);
}

/**
 * معالجة أمر /start
 */
function handleStartCommand($userId, $chatId, $userInfo) {
    logMessage('INFO', "Start command from user {$userId}");
    
    $userData = loadUserData($userId);
    
    if ($userData && ($userData['status'] ?? '') === 'active') {
        // مستخدم مسجل - عرض القائمة
        $balance = getUserBalance($userId);
        
        $message = "👋 *مرحباً بعودتك*\n\n";
        $message .= "📊 رصيدك الحالي: " . $balance . " رسالة\n\n";
        $message .= "اختر من الخيارات أدناه:";
        
        $keyboard = [
            'inline_keyboard' => [
                [
                    ['text' => '📤 إرسال SMS', 'callback_data' => 'send_sms'],
                    ['text' => '💰 شراء رصيد', 'callback_data' => 'buy_credit']
                ],
                [
                    ['text' => '📊 رصيدي', 'callback_data' => 'check_balance']
                ]
            ]
        ];
        
        sendTelegramRequest('sendMessage', [
            'chat_id' => $chatId,
            'text' => $message,
            'parse_mode' => 'Markdown',
            'reply_markup' => json_encode($keyboard)
        ]);
    } else {
        // مستخدم جديد
        $message = "👋 *أهلاً وسهلاً بك*\n\n";
        $message .= "لبدء استخدام خدمة إرسال الرسائل القصيرة، يرجى:\n";
        $message .= "1️⃣ الموافقة على الشروط والأحكام\n";
        $message .= "2️⃣ إنشاء حساب جديد\n\n";
        $message .= "بعد التسجيل ستحصل على 0 رسالة مجانية لتبدأ التجربة.";
        
        $keyboard = [
            'inline_keyboard' => [
                [
                    ['text' => '✅ إنشاء حساب وموافقة', 'callback_data' => 'register_' . $userId]
                ]
            ]
        ];
        
        sendTelegramRequest('sendMessage', [
            'chat_id' => $chatId,
            'text' => $message,
            'parse_mode' => 'Markdown',
            'reply_markup' => json_encode($keyboard)
        ]);
    }
}

/**
 * معالجة حالة المستخدم أثناء العملية
 */
function handleUserState($userId, $chatId, $text, $userData) {
    $state = $userData['state'] ?? '';
    
    switch ($state) {
        case 'awaiting_phone':
            handlePhoneInput($userId, $chatId, $text, $userData);
            break;
            
        case 'awaiting_message':
            handleMessageInput($userId, $chatId, $text, $userData);
            break;
            
        default:
            // حالة غير معروفة، إعادة تعيين
            $userData['state'] = '';
            saveUserData($userId, $userData);
            showMainMenu($userId);
            logMessage('WARNING', "Unknown user state reset for user {$userId}");
    }
}

/**
 * معالجة إدخال رقم الهاتف
 */
function handlePhoneInput($userId, $chatId, $text, $userData) {
    $phone = trim($text);
    
    logMessage('INFO', "User {$userId} entered phone: {$phone}");
    
    // التحقق من صيغة الرقم
    if (!preg_match('/^\+[1-9]\d{1,14}$/', $phone)) {
        sendTelegramRequest('sendMessage', [
            'chat_id' => $chatId,
            'text' => "❌ *رقم غير صحيح*\n\nيرجى إرسال الرقم بالصيغة الدولية:\nمثال: `+201234567890`\n\nأعد إرسال الرقم:",
            'parse_mode' => 'Markdown'
        ]);
        return;
    }
    
    // حفظ الرقم والانتقال للمرحلة التالية
    $userData['temp_phone'] = $phone;
    $userData['state'] = 'awaiting_message';
    saveUserData($userId, $userData);
    
    sendTelegramRequest('sendMessage', [
        'chat_id' => $chatId,
        'text' => "✅ *تم حفظ الرقم*\n\n✍️ *الآن أرسل نص الرسالة*\n\nاكتب النص الذي تريد إرساله:",
        'parse_mode' => 'Markdown'
    ]);
}

/**
 * معالجة إدخال نص الرسالة
 */
function handleMessageInput($userId, $chatId, $text, $userData) {
    $messageText = trim($text);
    $phoneNumber = $userData['temp_phone'] ?? '';
    
    logMessage('INFO', "User {$userId} entered message for {$phoneNumber}, length: " . strlen($messageText));
    
    if (empty($messageText)) {
        sendTelegramRequest('sendMessage', [
            'chat_id' => $chatId,
            'text' => "❌ النص فارغ\nيرجى إرسال نص الرسالة:",
            'parse_mode' => 'Markdown'
        ]);
        return;
    }
    
    // التحقق من الرصيد
    $balance = getUserBalance($userId);
    if ($balance <= 0) {
        $userData['state'] = '';
        unset($userData['temp_phone']);
        saveUserData($userId, $userData);
        
        sendTelegramRequest('sendMessage', [
            'chat_id' => $chatId,
            'text' => "❌ عفواً، نفذ رصيدك أثناء العملية\nيرجى شحن الرصيد أولاً.",
            'parse_mode' => 'Markdown'
        ]);
        logMessage('WARNING', "User {$userId} ran out of balance during SMS process");
        return;
    }
    
    // إرسال رسالة التجهيز
    sendTelegramRequest('sendMessage', [
        'chat_id' => $chatId,
        'text' => "⏳ *جاري الإرسال...*\n\nرقم الوجهة: `" . $phoneNumber . "`\nطول الرسالة: " . strlen($messageText) . " حرف",
        'parse_mode' => 'Markdown'
    ]);
    
    // إرسال الرسالة عبر Vonage
    $result = sendSMSviaVonage($phoneNumber, $messageText);
    
    // تنظيف حالة المستخدم
    $userData['state'] = '';
    unset($userData['temp_phone']);
    saveUserData($userId, $userData);
    
    // معالجة النتيجة
    if (isset($result['messages'][0]['status']) && $result['messages'][0]['status'] == '0') {
        // نجاح الإرسال
        $newBalance = $balance - 1;
        updateUserBalance($userId, $newBalance);
        
        $successMessage = "✅ *تم إرسال الرسالة بنجاح*\n\n";
        $successMessage .= "📱 إلى: `" . $phoneNumber . "`\n";
        $successMessage .= "📝 الرسالة: " . substr($messageText, 0, 100) . (strlen($messageText) > 100 ? "..." : "") . "\n\n";
        $successMessage .= "📊 *الرصيد المتبقي:* " . $newBalance . " رسالة\n\n";
        $successMessage .= "🆔 كود التتبع: " . ($result['messages'][0]['message-id'] ?? 'غير متوفر');
        
        $keyboard = [
            'inline_keyboard' => [
                [
                    ['text' => '📤 إرسال رسالة أخرى', 'callback_data' => 'send_sms'],
                    ['text' => '🏠 القائمة', 'callback_data' => 'main_menu']
                ]
            ]
        ];
        
        sendTelegramRequest('sendMessage', [
            'chat_id' => $chatId,
            'text' => $successMessage,
            'parse_mode' => 'Markdown',
            'reply_markup' => json_encode($keyboard)
        ]);
        
        logMessage('INFO', "SMS sent successfully: {$userId} to {$phoneNumber}, new balance: {$newBalance}");
    } else {
        // فشل الإرسال
        $error = $result['messages'][0]['error-text'] ?? $result['error'] ?? 'غير معروف';
        $errorMessage = "❌ *فشل إرسال الرسالة*\n\n";
        $errorMessage .= "📱 إلى: `" . $phoneNumber . "`\n";
        $errorMessage .= "سبب الخطأ: " . $error . "\n\n";
        $errorMessage .= "يرجى المحاولة مرة أخرى.";
        
        $keyboard = [
            'inline_keyboard' => [
                [
                    ['text' => '🔄 حاول مرة أخرى', 'callback_data' => 'send_sms'],
                    ['text' => '🏠 القائمة', 'callback_data' => 'main_menu']
                ]
            ]
        ];
        
        sendTelegramRequest('sendMessage', [
            'chat_id' => $chatId,
            'text' => $errorMessage,
            'parse_mode' => 'Markdown',
            'reply_markup' => json_encode($keyboard)
        ]);
        
        logMessage('ERROR', "SMS failed: {$userId} to {$phoneNumber}, error: {$error}");
    }
}

// ============================================
// نقطة الدخول الرئيسية (Webhook Handler)
// ============================================

// تمكين عرض الأخطاء للتطوير
if (getenv('ENVIRONMENT') === 'development') {
    error_reporting(E_ALL);
    ini_set('display_errors', 1);
} else {
    error_reporting(0);
    ini_set('display_errors', 0);
}

// استقبال البيانات من Telegram
$input = @file_get_contents('php://input');

if ($input === false || empty($input)) {
    // إذا لم تكن هناك بيانات، قد يكون طلب اختبار من Render
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        echo "✅ Telegram SMS Bot is running!\n";
        echo "📅 Server Time: " . date('Y-m-d H:i:s') . "\n";
        echo "🐳 Running in Docker\n";
        echo "📁 Data Directory: " . __DIR__ . "/data/\n";
        exit;
    }
    
    http_response_code(400);
    echo "No input data";
    logMessage('ERROR', 'No input data received');
    exit;
}

$update = json_decode($input, true);

if ($update === null) {
    http_response_code(400);
    echo "Invalid JSON";
    logMessage('ERROR', 'Invalid JSON received: ' . $input);
    exit;
}

// معالجة البيانات الواردة
try {
    if (isset($update['callback_query'])) {
        processCallbackQuery($update['callback_query']);
    } elseif (isset($update['message'])) {
        processTextMessage($update['message']);
    } else {
        logMessage('WARNING', 'Unknown update type received');
    }
} catch (Exception $e) {
    logMessage('ERROR', 'Exception: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
}

// الرد بـ OK
http_response_code(200);
echo "OK";
?>
