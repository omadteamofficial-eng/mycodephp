<?php
/**
 * Telegram Bot - PHP Version (Migration from Python aiogram)
 * Database: SQLite
 * Features: Cabinet, Statuses, Projects, Services, Transfers, Admin Panel, Clicker
 */

// --- SOZLAMALAR (CONFIG) ---
$config = [
    'api_token' => getenv('BOT_TOKEN') ?: 'SIZNING_BOT_TOKENINGIZ', // Tokenni shu yerga yozing
    'admin_id'  => (int)(getenv('ADMIN_ID') ?: 123456789),          // Admin ID
    'db_name'   => str_replace("uzcoin", "coin", getenv('DB_NAME') ?: "bot_database_coin_pro.db"),
    'currency'  => ['name' => '🪙', 'symbol' => '🪙'],
    'cards'     => [
        'uzs'   => getenv('CARD_UZS') ?: "5614686817322558",
        'name'  => getenv('CARD_NAME') ?: "Sayfullayev Sherali",
        'visa'  => getenv('CARD_VISA') ?: "4176550026725055"
    ]
];

// --- BAZA BILAN ISHLASH (DB HELPER) ---
try {
    $pdo = new PDO("sqlite:" . $config['db_name']);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    die("Baza xatoligi: " . $e->getMessage());
}

// Jadvallarni yaratish (Init DB)
function init_db($pdo) {
    $queries = [
        "CREATE TABLE IF NOT EXISTS users (
            id INTEGER PRIMARY KEY, 
            balance REAL DEFAULT 0.0,
            status_level INTEGER DEFAULT 0,
            status_expire TEXT,
            referrer_id INTEGER,
            joined_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )",
        "CREATE TABLE IF NOT EXISTS config (key TEXT PRIMARY KEY, value TEXT)",
        "CREATE TABLE IF NOT EXISTS projects (
            id INTEGER PRIMARY KEY AUTOINCREMENT, 
            name TEXT, 
            price REAL, 
            description TEXT,
            media_id TEXT,
            media_type TEXT,
            file_id TEXT
        )",
        // PHP da State (holat) saqlash uchun qo'shimcha jadval
        "CREATE TABLE IF NOT EXISTS user_states (
            user_id INTEGER PRIMARY KEY,
            state TEXT,
            data TEXT
        )"
    ];
    
    foreach ($queries as $q) {
        $pdo->exec($q);
    }
}
init_db($pdo);

// --- YORDAMCHI FUNKSIYALAR ---

function bot($method, $datas = []) {
    global $config;
    $url = "https://api.telegram.org/bot" . $config['api_token'] . "/" . $method;
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $datas);
    $res = curl_exec($ch);
    if (curl_errno($ch)) {
        error_log(curl_error($ch));
    }
    curl_close($ch);
    return json_decode($res);
}

function db_query($sql, $params = [], $fetch = null) {
    global $pdo;
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        if ($fetch === 'one') return $stmt->fetch();
        if ($fetch === 'all') return $stmt->fetchAll();
        return true;
    } catch (Exception $e) {
        return null;
    }
}

function get_config_val($key, $default) {
    $res = db_query("SELECT value FROM config WHERE key = ?", [$key], 'one');
    if ($res) return $res['value'];
    db_query("INSERT INTO config (key, value) VALUES (?, ?)", [$key, (string)$default]);
    return (string)$default;
}

function set_config_val($key, $value) {
    db_query("INSERT OR REPLACE INTO config (key, value) VALUES (?, ?)", [$key, (string)$value]);
}

function format_num($num) {
    return rtrim(rtrim(number_format((float)$num, 2, '.', ''), '0'), '.');
}

// --- STATE SYSTEM (FSM) ---
function set_state($user_id, $state, $data = []) {
    $json_data = json_encode($data);
    db_query("INSERT OR REPLACE INTO user_states (user_id, state, data) VALUES (?, ?, ?)", [$user_id, $state, $json_data]);
}

function get_state($user_id) {
    $res = db_query("SELECT state, data FROM user_states WHERE user_id = ?", [$user_id], 'one');
    if (!$res) return ['state' => null, 'data' => []];
    return ['state' => $res['state'], 'data' => json_decode($res['data'], true)];
}

function clear_state($user_id) {
    db_query("DELETE FROM user_states WHERE user_id = ?", [$user_id]);
}

function update_state_data($user_id, $new_data) {
    $current = get_state($user_id);
    $merged = array_merge($current['data'], $new_data);
    set_state($user_id, $current['state'], $merged);
}

// --- LOGIKA VA NARXLAR ---
$STATUS_DATA = [
    0 => ["name" => "👤 Start", "limit" => 30],
    1 => ["name" => "🥈 Silver", "limit" => 100, "desc" => "✅ Clicker (Pul ishlash)\n✅ Limit: 100 🪙"],
    2 => ["name" => "🥇 Gold", "limit" => 1000, "desc" => "✅ Loyihalar 50% chegirma\n✅ Limit: 1000 🪙"],
    3 => ["name" => "💎 Platinum", "limit" => 100000, "desc" => "✅ Hammasi TEKIN (Xizmatlar ham)\n✅ Limit: 100000 🪙"]
];

function get_dynamic_prices() {
    return [
        "web" => (float)get_config_val("price_web", 50.0),
        "apk" => (float)get_config_val("price_apk", 100.0),
        "bot" => (float)get_config_val("price_bot", 30.0),
        "ref_reward" => (float)get_config_val("ref_reward", 1.0),
        "click_reward" => (float)get_config_val("click_reward", 0.05),
        "pro_price" => (float)get_config_val("status_price_1", 20.0),
        "prem_price" => (float)get_config_val("status_price_2", 50.0),
        "king_price" => (float)get_config_val("status_price_3", 200.0)
    ];
}

function get_user_data($user_id) {
    $res = db_query("SELECT balance, status_level, status_expire FROM users WHERE id = ?", [$user_id], 'one');
    if (!$res) return null;
    
    $balance = $res['balance'];
    $level = $res['status_level'];
    $expire = $res['status_expire'];
    
    if ($expire && strtotime($expire) < time()) {
        db_query("UPDATE users SET status_level = 0, status_expire = NULL WHERE id = ?", [$user_id]);
        $level = 0;
        $expire = null;
    }
    return ["balance" => $balance, "level" => $level, "expire" => $expire];
}

// --- UPDATE NI QABUL QILISH ---
$update = json_decode(file_get_contents('php://input'));

if (isset($update->message)) {
    $message = $update->message;
    $chat_id = $message->chat->id;
    $user_id = $message->from->id;
    $text = $message->text ?? '';
    $name = $message->from->first_name;
    
    // Foydalanuvchini bazaga qo'shish
    $user_exists = db_query("SELECT id FROM users WHERE id = ?", [$user_id], 'one');
    if (!$user_exists) {
        $referrer_id = null;
        // Start parametrini olish (/start 12345)
        if (preg_match('/^\/start (\d+)$/', $text, $matches)) {
            $ref_candidate = intval($matches[1]);
            if ($ref_candidate != $user_id) {
                $referrer_id = $ref_candidate;
            }
        }
        
        db_query("INSERT INTO users (id, balance, referrer_id) VALUES (?, 0.0, ?)", [$user_id, $referrer_id]);
        
        // Referal bonusi
        if ($referrer_id) {
            $prices = get_dynamic_prices();
            db_query("UPDATE users SET balance = balance + ? WHERE id = ?", [$prices['ref_reward'], $referrer_id]);
            bot('sendMessage', [
                'chat_id' => $referrer_id,
                'text' => "🎉 Sizda yangi referal! +" . format_num($prices['ref_reward']) . " " . $config['currency']['symbol']
            ]);
        }
    }

    // STATE TEKSHIRISH
    $state_info = get_state($user_id);
    $current_state = $state_info['state'];

    // 1. BEKOR QILISH (Global)
    if ($text == "🚫 Bekor qilish") {
        clear_state($user_id);
        bot('sendMessage', [
            'chat_id' => $chat_id,
            'text' => "🚫 Bekor qilindi.",
            'reply_markup' => json_encode([
                'keyboard' => [
                    [['text' => "📂 Loyihalar"]],
                    [['text' => "👤 Kabinet"], ['text' => "🌟 Statuslar"]],
                    [['text' => "🛠 Xizmatlar"]],
                    [['text' => "💳 Hisobni to'ldirish"], ['text' => "💸 Pul ishlash"]],
                    [['text' => "🏆 Top Foydalanuvchilar"]]
                ],
                'resize_keyboard' => true
            ])
        ]);
        exit;
    }

    // 2. STATE HANDLERS (FSM)
    if ($current_state) {
        // --- XIZMAT BUYURTMA QILISH ---
        if ($current_state == 'order_desc') {
            $cost = $state_info['data']['cost'];
            $stype = $state_info['data']['stype'];
            
            if ($cost > 0) {
                db_query("UPDATE users SET balance = balance - ? WHERE id = ?", [$cost, $user_id]);
            }
            
            bot('sendMessage', [
                'chat_id' => $config['admin_id'],
                'text' => "🛠 **YANGI BUYURTMA**\n👤 User: `$user_id`\n🧩 Tur: $stype\n💰 To'landi: $cost\n📝 Matn: $text",
                'parse_mode' => 'Markdown'
            ]);
            
            bot('sendMessage', [
                'chat_id' => $chat_id,
                'text' => "✅ Buyurtmangiz qabul qilindi!",
                'reply_markup' => json_encode([
                    'keyboard' => [
                        [['text' => "📂 Loyihalar"]],
                        [['text' => "👤 Kabinet"], ['text' => "🌟 Statuslar"]],
                        [['text' => "🛠 Xizmatlar"]],
                        [['text' => "💳 Hisobni to'ldirish"], ['text' => "💸 Pul ishlash"]],
                        [['text' => "🏆 Top Foydalanuvchilar"]]
                    ],
                    'resize_keyboard' => true
                ])
            ]);
            clear_state($user_id);
            exit;
        }
        
        // --- PUL O'TKAZISH ---
        if ($current_state == 'transfer_recipient') {
            if (!ctype_digit($text)) {
                bot('sendMessage', ['chat_id' => $chat_id, 'text' => "⚠️ Iltimos, faqat raqamlardan iborat ID kiriting!"]);
                exit;
            }
            $rid = intval($text);
            if ($rid == $user_id) {
                bot('sendMessage', ['chat_id' => $chat_id, 'text' => "⚠️ O'zingizga pul o'tkaza olmaysiz!"]);
                exit;
            }
            $recipient = db_query("SELECT id FROM users WHERE id = ?", [$rid], 'one');
            if (!$recipient) {
                bot('sendMessage', ['chat_id' => $chat_id, 'text' => "⚠️ Bunday ID ga ega foydalanuvchi topilmadi!"]);
                exit;
            }
            
            update_state_data($user_id, ['rid' => $rid]);
            set_state($user_id, 'transfer_amount', $state_info['data']); // Data merged above
            
            $u_data = get_user_data($user_id);
            $limit = $STATUS_DATA[$u_data['level']]['limit'];
            
            bot('sendMessage', [
                'chat_id' => $chat_id,
                'text' => "💰 Qancha **{$config['currency']['name']}** o'tkazmoqchisiz?\nBalansingiz: " . format_num($u_data['balance']) . "\nLimit: $limit",
                'parse_mode' => 'Markdown'
            ]);
            exit;
        }
        
        if ($current_state == 'transfer_amount') {
            $amount = floatval($text);
            if ($amount <= 0) {
                bot('sendMessage', ['chat_id' => $chat_id, 'text' => "⚠️ Musbat son yozing!"]);
                exit;
            }
            
            $u_data = get_user_data($user_id);
            $limit = $STATUS_DATA[$u_data['level']]['limit'];
            
            if ($amount > $limit) {
                bot('sendMessage', ['chat_id' => $chat_id, 'text' => "⚠️ Limitdan oshdingiz! Limit: $limit"]);
                exit;
            }
            if ($u_data['balance'] < $amount) {
                bot('sendMessage', ['chat_id' => $chat_id, 'text' => "⚠️ Mablag' yetarli emas!"]);
                exit;
            }
            
            $rid = $state_info['data']['rid'];
            db_query("UPDATE users SET balance = balance - ? WHERE id = ?", [$amount, $user_id]);
            db_query("UPDATE users SET balance = balance + ? WHERE id = ?", [$amount, $rid]);
            
            bot('sendMessage', [
                'chat_id' => $chat_id,
                'text' => "✅ **Muvaffaqiyatli!**\n`$rid` ID ga " . format_num($amount) . " o'tkazildi.",
                'parse_mode' => 'Markdown',
                'reply_markup' => json_encode(['remove_keyboard' => true]) // Main menu is handled by default reply if text is empty, but here we can just show menu
            ]);
            
             // Menyu qaytarish
             bot('sendMessage', [
                'chat_id' => $chat_id,
                'text' => "Asosiy menyu",
                'reply_markup' => json_encode([
                    'keyboard' => [
                        [['text' => "📂 Loyihalar"]],
                        [['text' => "👤 Kabinet"], ['text' => "🌟 Statuslar"]],
                        [['text' => "🛠 Xizmatlar"]],
                        [['text' => "💳 Hisobni to'ldirish"], ['text' => "💸 Pul ishlash"]],
                        [['text' => "🏆 Top Foydalanuvchilar"]]
                    ],
                    'resize_keyboard' => true
                ])
            ]);

            bot('sendMessage', [
                'chat_id' => $rid,
                'text' => "📥 **Sizga pul kelib tushdi!**\n+" . format_num($amount) . " {$config['currency']['symbol']}\nKimdan: ID `$user_id`",
                'parse_mode' => 'Markdown'
            ]);
            
            clear_state($user_id);
            exit;
        }

        // --- ADMIN: BROADCAST ---
        if ($current_state == 'adm_broadcast') {
            $users = db_query("SELECT id FROM users", [], 'all');
            $count = 0;
            bot('sendMessage', ['chat_id' => $chat_id, 'text' => "⏳ Xabar yuborilmoqda..."]);
            
            // Xabar turi (Copy Message)
            foreach ($users as $u) {
                $res = bot('copyMessage', [
                    'chat_id' => $u['id'],
                    'from_chat_id' => $chat_id,
                    'message_id' => $message->message_id
                ]);
                if ($res && $res->ok) $count++;
            }
            
            bot('sendMessage', ['chat_id' => $chat_id, 'text' => "✅ Xabar $count ta foydalanuvchiga yetib bordi."]);
            clear_state($user_id);
            exit;
        }
        
        // --- ADMIN: ADD PROJECT ---
        if ($current_state == 'adm_proj_name') {
            update_state_data($user_id, ['name' => $text]);
            set_state($user_id, 'adm_proj_price', get_state($user_id)['data']);
            bot('sendMessage', ['chat_id' => $chat_id, 'text' => "💰 Narxini kiriting:"]);
            exit;
        }
        if ($current_state == 'adm_proj_price') {
            update_state_data($user_id, ['price' => (float)$text]);
            set_state($user_id, 'adm_proj_desc', get_state($user_id)['data']);
            bot('sendMessage', ['chat_id' => $chat_id, 'text' => "📝 Description:"]);
            exit;
        }
        if ($current_state == 'adm_proj_desc') {
            update_state_data($user_id, ['desc' => $text]);
            set_state($user_id, 'adm_proj_media', get_state($user_id)['data']);
            bot('sendMessage', ['chat_id' => $chat_id, 'text' => "🖼 Rasm/Video yuboring yoki 'skip':"]);
            exit;
        }
        if ($current_state == 'adm_proj_media') {
            $mid = null; $mtype = null;
            if (isset($message->photo)) {
                $mid = end($message->photo)->file_id;
                $mtype = "photo";
            } elseif (isset($message->video)) {
                $mid = $message->video->file_id;
                $mtype = "video";
            } elseif (strtolower($text) != 'skip') {
                bot('sendMessage', ['chat_id' => $chat_id, 'text' => "Media yuboring yoki 'skip'!"]);
                exit;
            }
            update_state_data($user_id, ['mid' => $mid, 'mtype' => $mtype]);
            set_state($user_id, 'adm_proj_file', get_state($user_id)['data']);
            bot('sendMessage', ['chat_id' => $chat_id, 'text' => "📁 Asosiy faylni yuboring:"]);
            exit;
        }
        if ($current_state == 'adm_proj_file') {
            if (!isset($message->document)) {
                bot('sendMessage', ['chat_id' => $chat_id, 'text' => "Fayl yuboring!"]);
                exit;
            }
            $data = $state_info['data'];
            db_query("INSERT INTO projects (name, price, description, media_id, media_type, file_id) VALUES (?,?,?,?,?,?)",
                [$data['name'], $data['price'], $data['desc'], $data['mid'], $data['mtype'], $message->document->file_id]);
            
            bot('sendMessage', ['chat_id' => $chat_id, 'text' => "✅ Loyiha qo'shildi!"]);
            clear_state($user_id);
            exit;
        }

        // --- ADMIN: CONFIG CHANGE ---
        if ($current_state == 'adm_change_val') {
            $key = $state_info['data']['key'];
            set_config_val($key, $text);
            bot('sendMessage', ['chat_id' => $chat_id, 'text' => "✅ Saqlandi!"]);
            clear_state($user_id);
            exit;
        }

        // --- HISOB TO'LDIRISH ---
        if ($current_state == 'fill_curr') {
            $rates = ["uzs" => (float)get_config_val("rate_uzs", 1000.0), "usd" => (float)get_config_val("rate_usd", 0.1)];
            
            if (strpos($text, "UZS") !== false) {
                $curr = "UZS"; $rate = $rates['uzs']; $card = $config['cards']['uzs']; $holder = $config['cards']['name'];
            } elseif (strpos($text, "USD") !== false) {
                $curr = "USD"; $rate = $rates['usd']; $card = $config['cards']['visa']; $holder = "Sayfullayev Sherali";
            } else {
                bot('sendMessage', ['chat_id' => $chat_id, 'text' => "Tugmani tanlang!"]);
                exit;
            }
            
            update_state_data($user_id, ['curr' => $curr, 'rate' => $rate, 'card' => $card, 'holder' => $holder]);
            set_state($user_id, 'fill_amount', get_state($user_id)['data']);
            
            bot('sendMessage', [
                'chat_id' => $chat_id,
                'text' => "💳 **To'lov ma'lumotlari:**\nKarta: `$card`\nEga: **$holder**\n1 🪙 = $rate $curr\n\nQancha sotib olmoqchisiz?",
                'parse_mode' => 'Markdown',
                'reply_markup' => json_encode(['keyboard' => [[['text' => "🚫 Bekor qilish"]]], 'resize_keyboard' => true])
            ]);
            exit;
        }
        
        if ($current_state == 'fill_amount') {
            $amt = floatval($text);
            if ($amt <= 0) {
                bot('sendMessage', ['chat_id' => $chat_id, 'text' => "Musbat son yozing!"]); exit;
            }
            $data = $state_info['data'];
            $total = $amt * $data['rate'];
            $txt = ($data['curr'] == "UZS") ? number_format($total, 0, '.', ' ') . " so'm" : number_format($total, 2) . " $";
            
            update_state_data($user_id, ['amt' => $amt, 'txt' => $txt]);
            set_state($user_id, 'fill_receipt', get_state($user_id)['data']);
            
            bot('sendMessage', [
                'chat_id' => $chat_id,
                'text' => "💵 To'lov miqdori: **$txt**\nChekni (rasm) yuboring:",
                'parse_mode' => 'Markdown'
            ]);
            exit;
        }

        if ($current_state == 'fill_receipt') {
            if (!isset($message->photo)) {
                 bot('sendMessage', ['chat_id' => $chat_id, 'text' => "Rasm yuboring!"]); exit;
            }
            $data = $state_info['data'];
            $pid = end($message->photo)->file_id;
            
            bot('sendPhoto', [
                'chat_id' => $config['admin_id'],
                'photo' => $pid,
                'caption' => "📥 **YANGI TO'LOV**\nUser: `$user_id`\nSo'raldi: {$data['amt']}\nTo'lov: {$data['txt']}",
                'parse_mode' => 'Markdown',
                'reply_markup' => json_encode([
                    'inline_keyboard' => [[
                        ['text' => "✅ Tasdiqlash", 'callback_data' => "p_ok:$user_id:{$data['amt']}"],
                        ['text' => "❌ Rad etish", 'callback_data' => "p_no:$user_id"]
                    ]]
                ])
            ]);
            
            bot('sendMessage', ['chat_id' => $chat_id, 'text' => "✅ Chek yuborildi. Admin tasdiqlashini kuting.", 
                'reply_markup' => json_encode([
                    'keyboard' => [
                        [['text' => "📂 Loyihalar"]],
                        [['text' => "👤 Kabinet"], ['text' => "🌟 Statuslar"]],
                        [['text' => "🛠 Xizmatlar"]],
                        [['text' => "💳 Hisobni to'ldirish"], ['text' => "💸 Pul ishlash"]],
                        [['text' => "🏆 Top Foydalanuvchilar"]]
                    ],
                    'resize_keyboard' => true
                ])
            ]);
            clear_state($user_id);
            exit;
        }
    }

    // 3. ODDIY KOMANDALAR VA MENYULAR
    if (strpos($text, "/start") === 0) {
        bot('sendMessage', [
            'chat_id' => $chat_id,
            'text' => get_config_val("text_welcome", "🖥 Asosiy menyudasiz"),
            'reply_markup' => json_encode([
                'keyboard' => [
                    [['text' => "📂 Loyihalar"]],
                    [['text' => "👤 Kabinet"], ['text' => "🌟 Statuslar"]],
                    [['text' => "🛠 Xizmatlar"]],
                    [['text' => "💳 Hisobni to'ldirish"], ['text' => "💸 Pul ishlash"]],
                    [['text' => "🏆 Top Foydalanuvchilar"]]
                ],
                'resize_keyboard' => true
            ])
        ]);
        exit;
    }

    if ($text == "👤 Kabinet") {
        $u = get_user_data($user_id);
        $sname = $STATUS_DATA[$u['level']]['name'];
        $slimit = $STATUS_DATA[$u['level']]['limit'];
        
        $msg = "🆔 ID: `$user_id`\n💰 Balans: **" . format_num($u['balance']) . " {$config['currency']['symbol']}**\n📊 Status: $sname\n💳 Limit: $slimit";
        if ($u['expire']) $msg .= "\n⏳ Tugash: `{$u['expire']}`";
        
        bot('sendMessage', [
            'chat_id' => $chat_id, 
            'text' => $msg, 
            'parse_mode' => 'Markdown',
            'reply_markup' => json_encode([
                'inline_keyboard' => [[['text' => "💸 Do'stga o'tkazish", 'callback_data' => "transfer_start"]]]
            ])
        ]);
    }
    elseif ($text == "💸 Pul ishlash") {
        $u = get_user_data($user_id);
        $prices = get_dynamic_prices();
        $bot_info = bot('getMe');
        $bot_user = $bot_info->result->username;
        $link = "https://t.me/$bot_user?start=$user_id";
        
        $msg = "🔗 **Referal:**\n`$link`\n\nHar bir taklif: **" . format_num($prices['ref_reward']) . "**\n";
        $kb = [];
        
        if ($u['level'] >= 1) {
            $msg .= "\n🥈 **Silver Clicker** faol!\nClick: " . format_num($prices['click_reward']);
            $kb[] = [['text' => "👆 CLICK", 'callback_data' => "clicker_process"]];
        } else {
            $msg .= "\n🔒 Clicker yopiq (Silver kerak)";
            $kb[] = [['text' => "🥈 Status sotib olish", 'callback_data' => "open_status_shop"]];
        }
        
        bot('sendMessage', [
            'chat_id' => $chat_id,
            'text' => $msg,
            'parse_mode' => 'Markdown',
            'reply_markup' => json_encode(['inline_keyboard' => $kb])
        ]);
    }
    elseif ($text == "🌟 Statuslar") {
        $p = get_dynamic_prices();
        $kb = [
            [['text' => "🥈 Silver ({$p['pro_price']})", 'callback_data' => "buy_status_1"]],
            [['text' => "🥇 Gold ({$p['prem_price']})", 'callback_data' => "buy_status_2"]],
            [['text' => "💎 Platinum ({$p['king_price']})", 'callback_data' => "buy_status_3"]]
        ];
        $info = "**STATUSLAR:**\n\n🥈 **SILVER** - {$p['pro_price']}\n{$STATUS_DATA[1]['desc']}\n\n🥇 **GOLD** - {$p['prem_price']}\n{$STATUS_DATA[2]['desc']}\n\n💎 **PLATINUM** - {$p['king_price']}\n{$STATUS_DATA[3]['desc']}";
        
        bot('sendMessage', [
            'chat_id' => $chat_id,
            'text' => $info,
            'parse_mode' => 'Markdown',
            'reply_markup' => json_encode(['inline_keyboard' => $kb])
        ]);
    }
    elseif ($text == "🏆 Top Foydalanuvchilar") {
        $users = db_query("SELECT id, balance, status_level FROM users ORDER BY balance DESC LIMIT 10", [], 'all');
        $msg = "🏆 **TOP 10:**\n\n";
        $i = 1;
        foreach ($users as $u) {
            $badge = ($u['status_level'] == 1) ? "🥈" : (($u['status_level'] == 2) ? "🥇" : (($u['status_level'] == 3) ? "💎" : ""));
            $hid = substr($u['id'], 0, 4) . "..." . substr($u['id'], -2);
            $msg .= "$i. $badge ID: `$hid` — **" . format_num($u['balance']) . "**\n";
            $i++;
        }
        bot('sendMessage', ['chat_id' => $chat_id, 'text' => $msg, 'parse_mode' => 'Markdown']);
    }
    elseif ($text == "📂 Loyihalar") {
        $projs = db_query("SELECT id, name FROM projects", [], 'all');
        if (!$projs) {
            bot('sendMessage', ['chat_id' => $chat_id, 'text' => "📂 Loyihalar yo'q."]);
        } else {
            $kb = [];
            foreach ($projs as $p) {
                $kb[] = [['text' => "📁 " . $p['name'], 'callback_data' => "view_proj_" . $p['id']]];
            }
            bot('sendMessage', [
                'chat_id' => $chat_id,
                'text' => "📥 Loyihani tanlang:",
                'reply_markup' => json_encode(['inline_keyboard' => $kb])
            ]);
        }
    }
    elseif ($text == "🛠 Xizmatlar") {
        $p = get_dynamic_prices();
        $kb = [
            [['text' => "🌐 Web Sayt ({$p['web']})", 'callback_data' => "serv_web"]],
            [['text' => "📱 Android ({$p['apk']})", 'callback_data' => "serv_apk"]],
            [['text' => "🤖 Telegram Bot ({$p['bot']})", 'callback_data' => "serv_bot"]]
        ];
        bot('sendMessage', [
            'chat_id' => $chat_id,
            'text' => "🛠 **Buyurtma turini tanlang:**",
            'reply_markup' => json_encode(['inline_keyboard' => $kb]),
            'parse_mode' => 'Markdown'
        ]);
    }
    elseif ($text == "💳 Hisobni to'ldirish") {
        set_state($user_id, 'fill_curr');
        bot('sendMessage', [
            'chat_id' => $chat_id,
            'text' => "To'lov valyutasini tanlang:",
            'reply_markup' => json_encode([
                'keyboard' => [
                    [['text' => "🇺🇿 UZS (Humo/Uzcard)"], ['text' => "🇺🇸 USD (Visa)"]],
                    [['text' => "🚫 Bekor qilish"]]
                ],
                'resize_keyboard' => true
            ])
        ]);
    }
    elseif ($text == "/admin" && $user_id == $config['admin_id']) {
        $kb = [
            [['text' => "➕ Loyiha", 'callback_data' => "adm_add_proj"], ['text' => "💵 Narxlar", 'callback_data' => "adm_prices"]],
            [['text' => "📢 Broadcast", 'callback_data' => "adm_broadcast"]]
        ];
        bot('sendMessage', [
            'chat_id' => $chat_id,
            'text' => "🔐 **Admin Panel**",
            'parse_mode' => 'Markdown',
            'reply_markup' => json_encode(['inline_keyboard' => $kb])
        ]);
    }
}

// --- CALLBACK QUERY ---
if (isset($update->callback_query)) {
    $cb = $update->callback_query;
    $data = $cb->data;
    $qid = $cb->id;
    $msg = $cb->message;
    $chat_id = $msg->chat->id;
    $user_id = $cb->from->id;
    
    // Admin: To'lov tasdiqlash
    if (strpos($data, "p_ok:") === 0) {
        if ($user_id != $config['admin_id']) return;
        list($prefix, $uid, $amt) = explode(":", $data);
        db_query("UPDATE users SET balance = balance + ? WHERE id = ?", [$amt, $uid]);
        bot('sendMessage', ['chat_id' => $uid, 'text' => "✅ **To'lov tasdiqlandi!** +$amt", 'parse_mode' => 'Markdown']);
        bot('editMessageCaption', ['chat_id' => $chat_id, 'message_id' => $msg->message_id, 'caption' => $msg->caption . "\n\n✅ TASDIQLANDI"]);
    }
    elseif (strpos($data, "p_no:") === 0) {
        if ($user_id != $config['admin_id']) return;
        $uid = explode(":", $data)[1];
        bot('sendMessage', ['chat_id' => $uid, 'text' => "❌ To'lov rad etildi."]);
        bot('editMessageCaption', ['chat_id' => $chat_id, 'message_id' => $msg->message_id, 'caption' => $msg->caption . "\n\n❌ RAD ETILDI"]);
    }
    // Clicker
    elseif ($data == "clicker_process") {
        $u = get_user_data($user_id);
        if ($u['level'] < 1) {
            bot('answerCallbackQuery', ['callback_query_id' => $qid, 'text' => "Faqat Silver va yuqori!", 'show_alert' => true]);
        } else {
            $rew = get_dynamic_prices()['click_reward'];
            db_query("UPDATE users SET balance = balance + ? WHERE id = ?", [$rew, $user_id]);
            bot('answerCallbackQuery', ['callback_query_id' => $qid, 'text' => "+$rew", 'cache_time' => 1]);
        }
    }
    // Shop
    elseif ($data == "open_status_shop") {
         // Logic is same as text command, just edit msg or send new
         bot('sendMessage', ['chat_id' => $chat_id, 'text' => "🌟 Statuslar bo'limidan xarid qiling."]);
    }
    // Buy Status
    elseif (strpos($data, "buy_status_") === 0) {
        $lvl = (int)explode("_", $data)[2];
        $u = get_user_data($user_id);
        $p = get_dynamic_prices();
        $prices = [1 => $p['pro_price'], 2 => $p['prem_price'], 3 => $p['king_price']];
        $cost = $prices[$lvl];
        
        if ($u['level'] >= $lvl) {
            bot('answerCallbackQuery', ['callback_query_id' => $qid, 'text' => "Sizda bu status bor!", 'show_alert' => true]);
        } elseif ($u['balance'] < $cost) {
            bot('answerCallbackQuery', ['callback_query_id' => $qid, 'text' => "Mablag' yetarli emas!", 'show_alert' => true]);
        } else {
            $expire = date("Y-m-d H:i:s", strtotime("+30 days"));
            db_query("UPDATE users SET balance = balance - ?, status_level = ?, status_expire = ? WHERE id = ?", [$cost, $lvl, $expire, $user_id]);
            bot('deleteMessage', ['chat_id' => $chat_id, 'message_id' => $msg->message_id]);
            bot('sendMessage', ['chat_id' => $chat_id, 'text' => "🎉 Status sotib olindi: " . $STATUS_DATA[$lvl]['name']]);
        }
    }
    // Project View
    elseif (strpos($data, "view_proj_") === 0) {
        $pid = explode("_", $data)[2];
        $proj = db_query("SELECT * FROM projects WHERE id = ?", [$pid], 'one');
        if (!$proj) {
            bot('answerCallbackQuery', ['callback_query_id' => $qid, 'text' => "Topilmadi", 'show_alert' => true]);
        } else {
            $u = get_user_data($user_id);
            $discount = ($u['level'] == 2) ? 0.5 : (($u['level'] == 3) ? 1.0 : 0);
            $final = $proj['price'] * (1 - $discount);
            
            $txt = "📂 **{$proj['name']}**\n\n📝 {$proj['description']}\n\n💰 Narx: " . format_num($final);
            $kb = json_encode(['inline_keyboard' => [[['text' => "📥 Sotib olish", 'callback_data' => "buy_proj_$pid"]]]]);
            
            if ($proj['media_id']) {
                $method = ($proj['media_type'] == 'video') ? 'sendVideo' : 'sendPhoto';
                $field = ($proj['media_type'] == 'video') ? 'video' : 'photo';
                bot($method, ['chat_id' => $chat_id, $field => $proj['media_id'], 'caption' => $txt, 'parse_mode' => 'Markdown', 'reply_markup' => $kb]);
            } else {
                bot('sendMessage', ['chat_id' => $chat_id, 'text' => $txt, 'parse_mode' => 'Markdown', 'reply_markup' => $kb]);
            }
        }
    }
    // Buy Project
    elseif (strpos($data, "buy_proj_") === 0) {
        $pid = explode("_", $data)[2];
        $proj = db_query("SELECT * FROM projects WHERE id = ?", [$pid], 'one');
        if ($proj) {
            $u = get_user_data($user_id);
            $discount = ($u['level'] == 2) ? 0.5 : (($u['level'] == 3) ? 1.0 : 0);
            $final = $proj['price'] * (1 - $discount);
            
            if ($u['balance'] < $final) {
                bot('answerCallbackQuery', ['callback_query_id' => $qid, 'text' => "Pul yetmaydi!", 'show_alert' => true]);
            } else {
                if ($final > 0) db_query("UPDATE users SET balance = balance - ? WHERE id = ?", [$final, $user_id]);
                bot('sendDocument', ['chat_id' => $chat_id, 'document' => $proj['file_id'], 'caption' => "✅ Xarid qilindi!"]);
            }
        }
    }
    // Services
    elseif (strpos($data, "serv_") === 0) {
        $stype = explode("_", $data)[1];
        $p = get_dynamic_prices();
        $cost = $p[$stype] ?? 0;
        $u = get_user_data($user_id);
        
        if ($u['level'] == 3) { $cost = 0; bot('sendMessage', ['chat_id' => $chat_id, 'text' => "💎 Platinum: Tekin!"]); }
        elseif ($u['balance'] < $cost) {
            bot('answerCallbackQuery', ['callback_query_id' => $qid, 'text' => "Mablag' yetmaydi!", 'show_alert' => true]);
            return;
        }
        
        set_state($user_id, 'order_desc', ['stype' => $stype, 'cost' => $cost]);
        bot('sendMessage', ['chat_id' => $chat_id, 'text' => "📝 Texnik topshiriqni yozing:", 'reply_markup' => json_encode(['keyboard' => [[['text' => "🚫 Bekor qilish"]]], 'resize_keyboard' => true])]);
    }
    // Transfer Start
    elseif ($data == "transfer_start") {
        set_state($user_id, 'transfer_recipient');
        bot('sendMessage', ['chat_id' => $chat_id, 'text' => "🆔 Qabul qiluvchi ID sini yozing:", 'reply_markup' => json_encode(['keyboard' => [[['text' => "🚫 Bekor qilish"]]], 'resize_keyboard' => true])]);
    }
    // Admin features
    elseif ($data == "adm_add_proj") {
        set_state($user_id, 'adm_proj_name');
        bot('sendMessage', ['chat_id' => $chat_id, 'text' => "📝 Loyiha nomi:", 'reply_markup' => json_encode(['keyboard' => [[['text' => "🚫 Bekor qilish"]]], 'resize_keyboard' => true])]);
    }
    elseif ($data == "adm_broadcast") {
        set_state($user_id, 'adm_broadcast');
        bot('sendMessage', ['chat_id' => $chat_id, 'text' => "📢 Xabarni yuboring (Text/Media):", 'reply_markup' => json_encode(['keyboard' => [[['text' => "🚫 Bekor qilish"]]], 'resize_keyboard' => true])]);
    }
    elseif ($data == "adm_prices") {
        $p = get_dynamic_prices();
        $kb = [
            [['text' => "Ref ({$p['ref_reward']})", 'callback_data' => "set_ref_reward"]],
            [['text' => "Silver ({$p['pro_price']})", 'callback_data' => "set_status_price_1"]]
            // Boshqalarni ham qo'shish mumkin...
        ];
        bot('editMessageText', ['chat_id' => $chat_id, 'message_id' => $msg->message_id, 'text' => "⚙️ Narxlar:", 'reply_markup' => json_encode(['inline_keyboard' => $kb])]);
    }
    elseif (strpos($data, "set_") === 0) {
        $key = str_replace("set_", "", $data);
        set_state($user_id, 'adm_change_val', ['key' => $key]);
        bot('sendMessage', ['chat_id' => $chat_id, 'text' => "Yangi qiymatni yozing:", 'reply_markup' => json_encode(['keyboard' => [[['text' => "🚫 Bekor qilish"]]], 'resize_keyboard' => true])]);
    }
}
?>
