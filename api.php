<?php
// api.php - 高並行・完全並列パイプライン・Novita structured-outputs非対応モデル完全対応版
ini_set('session.cookie_httponly', 1);
session_start();
header('Content-Type: application/json; charset=utf-8');
@set_time_limit(240);

// 1. 設定読み込み
if (file_exists(__DIR__ . '/../config.php')) {
    require_once __DIR__ . '/../config.php';
} elseif (file_exists(__DIR__ . '/config.php')) {
    require_once __DIR__ . '/config.php';
} else {
    http_response_code(500);
    echo json_encode(['error' => 'config.php が見つかりません。']);
    exit;
}

$storageDir = defined('DATA_DIR') ? DATA_DIR : __DIR__ . '/books';
if (!is_dir($storageDir)) {
    @mkdir($storageDir, 0755, true);
}

// 2. SQLite によるデータ管理（WALモード）
$dbPath = $storageDir . '/app_data.db';
$pdo = new PDO('sqlite:' . $dbPath);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

$pdo->exec('PRAGMA journal_mode = WAL;');
$pdo->exec('PRAGMA busy_timeout = 8000;');

$pdo->exec("
    CREATE TABLE IF NOT EXISTS users (
        username TEXT PRIMARY KEY,
        password TEXT NOT NULL,
        created_at TEXT NOT NULL
    );
    CREATE TABLE IF NOT EXISTS books (
        id TEXT PRIMARY KEY,
        title TEXT NOT NULL,
        genre_key TEXT,
        genre_name TEXT,
        target_audience TEXT,
        overview TEXT,
        plan_json TEXT NOT NULL,
        created_at TEXT NOT NULL
    );
    CREATE TABLE IF NOT EXISTS chapter_sections (
        book_id TEXT NOT NULL,
        chapter_number INTEGER NOT NULL,
        section_number INTEGER NOT NULL,
        content TEXT NOT NULL,
        updated_at TEXT NOT NULL,
        PRIMARY KEY (book_id, chapter_number, section_number)
    );
    CREATE TABLE IF NOT EXISTS rate_limits (
        ip_hash TEXT NOT NULL,
        action_date TEXT NOT NULL,
        request_count INTEGER NOT NULL,
        PRIMARY KEY (ip_hash, action_date)
    );
");

// 互換性維持: 旧users.jsonが存在すればSQLiteへ移行
$legacyUsersFile = $storageDir . '/users.json';
if (file_exists($legacyUsersFile)) {
    $legacyUsers = json_decode(file_get_contents($legacyUsersFile), true) ?? [];
    $stmt = $pdo->prepare("INSERT OR IGNORE INTO users (username, password, created_at) VALUES (?, ?, ?)");
    foreach ($legacyUsers as $uName => $uData) {
        $stmt->execute([$uName, $uData['password'], $uData['created_at'] ?? date('Y-m-d H:i:s')]);
    }
}

$apiKey = defined('GEMINI_API_KEY') ? GEMINI_API_KEY : '';
$openrouterKey = defined('OPENROUTER_API_KEY') ? OPENROUTER_API_KEY : '';
$inviteCode = defined('INVITE_CODE') ? INVITE_CODE : '';
$genres = file_exists(__DIR__ . '/genres.php') ? require __DIR__ . '/genres.php' : [];

$input = json_decode(file_get_contents('php://input'), true) ?? [];
$action = $input['action'] ?? ($_GET['action'] ?? '');

// -------------------------------------------------------------
// 認証系エンドポイント
// -------------------------------------------------------------
if ($action === 'check_auth') {
    $auth = !empty($_SESSION['user']);
    $user = $_SESSION['user'] ?? null;
    session_write_close();
    echo json_encode(['authenticated' => $auth, 'username' => $user]);
    exit;
}

if ($action === 'register') {
    $username = trim($input['username'] ?? '');
    $password = trim($input['password'] ?? '');
    $code = trim($input['invite_code'] ?? '');

    if (empty($username) || empty($password)) {
        session_write_close();
        http_response_code(400);
        echo json_encode(['error' => 'ユーザー名とパスワードを入力してください。']);
        exit;
    }
    if (empty($inviteCode) || $code !== $inviteCode) {
        session_write_close();
        http_response_code(403);
        echo json_encode(['error' => '招待コード（合言葉）が正しくありません。']);
        exit;
    }

    try {
        $stmt = $pdo->prepare("INSERT INTO users (username, password, created_at) VALUES (?, ?, ?)");
        $stmt->execute([$username, password_hash($password, PASSWORD_DEFAULT), date('Y-m-d H:i:s')]);
        
        $_SESSION['user'] = $username;
        session_write_close();
        echo json_encode(['success' => true, 'username' => $username]);
    } catch (PDOException $e) {
        session_write_close();
        http_response_code(400);
        echo json_encode(['error' => 'そのユーザー名は既に使用されています。']);
    }
    exit;
}

if ($action === 'login') {
    $username = trim($input['username'] ?? '');
    $password = trim($input['password'] ?? '');

    $stmt = $pdo->prepare("SELECT password FROM users WHERE username = ?");
    $stmt->execute([$username]);
    $row = $stmt->fetch();

    if (!$row || !password_verify($password, $row['password'])) {
        session_write_close();
        http_response_code(401);
        echo json_encode(['error' => 'ユーザー名またはパスワードが正しくありません。']);
        exit;
    }

    $_SESSION['user'] = $username;
    session_write_close();
    echo json_encode(['success' => true, 'username' => $username]);
    exit;
}

if ($action === 'logout') {
    $_SESSION = [];
    if (ini_get("session.use_cookies")) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $params["path"], $params["domain"], $params["secure"], $params["httponly"]);
    }
    session_destroy();
    echo json_encode(['success' => true]);
    exit;
}

if (empty($_SESSION['user'])) {
    session_write_close();
    http_response_code(401);
    echo json_encode(['error' => 'ログインが必要です。']);
    exit;
}
session_write_close();

// -------------------------------------------------------------
// API通信レイヤー
// -------------------------------------------------------------
function callGeminiGenerate($model, $payload, $apiKey) {
    if (!$apiKey) throw new Exception('GEMINI_API_KEYが未設定です。');
    $url = "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent?key=" . $apiKey;
    
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_TIMEOUT => 120,
        CURLOPT_SSL_VERIFYPEER => true
    ]);
    $res = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($curlError || $httpCode !== 200) {
        throw new Exception("Generate Error ($model HTTP $httpCode): " . ($curlError ?: $res));
    }
    return json_decode($res, true);
}

function callOpenRouter($model, $messages, $apiKey, $temperature = 0.7, $provider = null) {
    if (!$apiKey) throw new Exception('OPENROUTER_API_KEYが設定されていません。');
    $url = "https://openrouter.ai/api/v1/chat/completions";

    $payload = [
        'model' => $model,
        'messages' => $messages,
        'temperature' => $temperature,
    ];

    if (!empty($provider)) {
        $payload['provider'] = $provider;
    }

    // 注意: structured-outputs 非対応プロバイダ（Novita等）で 400 エラーになるため、
    // response_format パラメータは一切付与せず、プロンプト指示のみで JSON を出力させる

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $apiKey,
            'HTTP-Referer: https://localhost/',
            'X-Title: Co-Author Studio'
        ],
        CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_TIMEOUT => 180,
        CURLOPT_SSL_VERIFYPEER => true
    ]);

    $res = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($curlError || $httpCode !== 200) {
        throw new Exception("OpenRouter API Error ($model HTTP $httpCode): " . ($curlError ?: $res));
    }

    $json = json_decode($res, true);
    return $json['choices'][0]['message']['content'] ?? '';
}

function callUnifiedLLM($model, $systemInstruction, $prompt, $jsonSchema = null, $temperature = 0.7, $provider = null) {
    $geminiKey = defined('GEMINI_API_KEY') ? GEMINI_API_KEY : '';
    $openrouterKey = defined('OPENROUTER_API_KEY') ? OPENROUTER_API_KEY : '';
    $isOpenRouterModel = (strpos($model, '/') !== false);

    if ($isOpenRouterModel || (empty($geminiKey) && !empty($openrouterKey))) {
        $messages = [];
        if (!empty($systemInstruction)) {
            $messages[] = ['role' => 'system', 'content' => $systemInstruction];
        }
        $messages[] = ['role' => 'user', 'content' => $prompt];

        // config.php に定義されたプロバイダー強制設定 (only 形式) を適用
        if ($provider === null && defined('OPENROUTER_PROVIDERS') && is_array(OPENROUTER_PROVIDERS)) {
            $provider = [
                'only' => OPENROUTER_PROVIDERS,
                'allow_fallbacks' => false
            ];
        }

        $rawResponse = callOpenRouter($model, $messages, $openrouterKey, $temperature, $provider);
    } else {
        $payload = [
            'contents' => [
                ['parts' => [['text' => $prompt]]]
            ],
            'generationConfig' => [
                'temperature' => $temperature
            ]
        ];

        if (!empty($systemInstruction)) {
            $payload['system_instruction'] = [
                'parts' => [['text' => $systemInstruction]]
            ];
        }

        if ($jsonSchema !== null) {
            $convertToUpper = function ($schema) use (&$convertToUpper) {
                if (!is_array($schema)) return $schema;
                $res = [];
                foreach ($schema as $k => $v) {
                    if ($k === 'type' && is_string($v)) {
                        $res[$k] = strtoupper($v);
                    } elseif (is_array($v)) {
                        $res[$k] = $convertToUpper($v);
                    } else {
                        $res[$k] = $v;
                    }
                }
                return $res;
            };

            $payload['generationConfig']['response_mime_type'] = 'application/json';
            $payload['generationConfig']['response_schema'] = $convertToUpper($jsonSchema);
        }

        $res = callGeminiGenerate($model, $payload, $geminiKey);
        $rawResponse = $res['candidates'][0]['content']['parts'][0]['text'] ?? '';
    }

    $cleaned = preg_replace('/^```(?:json)?\s*/i', '', trim($rawResponse));$cleaned = preg_replace('/\s*```$/', '', $cleaned);
    return trim($cleaned);
}

function getEmbedding($text, $apiKey) {
    if (!$apiKey) return null;
    $model = defined('MODEL_EMBEDDING') ? MODEL_EMBEDDING : 'text-embedding-004';
    $url = "https://generativelanguage.googleapis.com/v1beta/models/{$model}:embedContent?key=" . $apiKey;
    
    $payload = [
        'model' => "models/{$model}",
        'content' => [
            'parts' => [['text' => "task: classification | query: " . $text]]
        ]
    ];

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_TIMEOUT => 30,
        CURLOPT_SSL_VERIFYPEER => true
    ]);
    $res = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($curlError || $httpCode !== 200) {
        return null;
    }

    $json = json_decode($res, true);
    return $json['embedding']['values'] ?? null;
}

function cosineSimilarity(array $vecA, array $vecB) {
    $dot = 0.0;
    $normA = 0.0;
    $normB = 0.0;
    $count = count($vecA);
    for ($i = 0; $i < $count; $i++) {
        $dot += $vecA[$i] * $vecB[$i];
        $normA += $vecA[$i] * $vecA[$i];
        $normB += $vecB[$i] * $vecB[$i];
    }
    if ($normA <= 0.0 || $normB <= 0.0) return 0.0;
    return $dot / (sqrt($normA) * sqrt($normB));
}

function classifyThemeByEmbedding($theme, $genres, $storageDir, $apiKey) {
    try {
        if (!$apiKey) return 'tech';

        $cacheFile = $storageDir . '/genre_embeddings.json';
        $genreVectors = [];
        if (file_exists($cacheFile)) {
            $genreVectors = json_decode(file_get_contents($cacheFile), true);
        } else {
            foreach ($genres as $k => $c) {
                $vec = getEmbedding($c['seed_text'], $apiKey);
                if ($vec) $genreVectors[$k] = $vec;
            }
            if (!empty($genreVectors)) {
                file_put_contents($cacheFile, json_encode($genreVectors), LOCK_EX);
            }
        }

        $themeVector = getEmbedding($theme, $apiKey);
        if (!$themeVector || empty($genreVectors)) return 'tech';

        $bestGenre = 'tech';
        $highestScore = -1.0;
        foreach ($genreVectors as $genreKey => $vector) {
            $score = cosineSimilarity($themeVector, $vector);
            if ($score > $highestScore) {
                $highestScore = $score;
                $bestGenre = $genreKey;
            }
        }
        return $bestGenre;
    } catch (Exception $e) {
        return 'tech';
    }
}

// -------------------------------------------------------------
// アクションルーティング
// -------------------------------------------------------------
try {
    // 5. 書籍一覧
    if ($action === 'list_books') {
        $stmt = $pdo->query("SELECT id, title, created_at, genre_name, target_audience FROM books ORDER BY created_at DESC");
        $books = $stmt->fetchAll();
        echo json_encode(['success' => true, 'books' => $books]);
        exit;
    }

    // 6. 書籍詳細
    if ($action === 'get_book') {
        $bookId = preg_replace('/[^a-zA-Z0-9_-]/', '', $_GET['book_id'] ?? ($input['book_id'] ?? ''));
        $stmt = $pdo->prepare("SELECT * FROM books WHERE id = ?");
        $stmt->execute([$bookId]);
        $bookRow = $stmt->fetch();

        if (!$bookRow) throw new Exception('書籍データが見つかりません。');

        $book = json_decode($bookRow['plan_json'], true);

        $secStmt = $pdo->prepare("SELECT chapter_number, section_number, content FROM chapter_sections WHERE book_id = ? ORDER BY chapter_number ASC, section_number ASC");
        $secStmt->execute([$bookId]);
        $secRows = $secStmt->fetchAll();

        $chapterMap = [];
        foreach ($secRows as $r) {
            $chNum = $r['chapter_number'];
            if (!isset($chapterMap[$chNum])) {
                $chapterMap[$chNum] = [];
            }
            $chapterMap[$chNum][] = $r['content'];
        }

        foreach ($book['chapters'] as &$ch) {
            $chNum = $ch['chapter_number'];
            if (!empty($chapterMap[$chNum])) {
                $chHeader = "= 第{$chNum}章: {$ch['title']}\n:stem: latexmath\n\n";
                $ch['content'] = $chHeader . implode("\n\n'''\n\n", $chapterMap[$chNum]);
            } else {
                $ch['content'] = '';
            }
        }

        echo json_encode(['success' => true, 'book' => $book]);
        exit;
    }

    // 7. 章の手動編集保存
    if ($action === 'update_chapter') {
        $bookId = preg_replace('/[^a-zA-Z0-9_-]/', '', $input['book_id'] ?? '');
        $chapterNumber = (int)($input['chapter_number'] ?? 0);
        $content = $input['content'] ?? '';

        if (!$bookId || $chapterNumber <= 0) throw new Exception('更新パラメータが不正です。');

        $pdo->beginTransaction();
        $del = $pdo->prepare("DELETE FROM chapter_sections WHERE book_id = ? AND chapter_number = ?");
        $del->execute([$bookId, $chapterNumber]);

        $ins = $pdo->prepare("
            INSERT INTO chapter_sections (book_id, chapter_number, section_number, content, updated_at)
            VALUES (?, ?, 1, ?, ?)
        ");
        $ins->execute([$bookId, $chapterNumber, $content, date('Y-m-d H:i:s')]);
        $pdo->commit();

        echo json_encode(['success' => true, 'chapter_number' => $chapterNumber]);
        exit;
    }

    // 8. 書籍削除
    if ($action === 'delete_book') {
        $bookId = preg_replace('/[^a-zA-Z0-9_-]/', '', $input['book_id'] ?? '');
        $pdo->beginTransaction();
        $stmt1 = $pdo->prepare("DELETE FROM books WHERE id = ?");
        $stmt1->execute([$bookId]);
        $stmt2 = $pdo->prepare("DELETE FROM chapter_sections WHERE book_id = ?");
        $stmt2->execute([$bookId]);
        $pdo->commit();

        echo json_encode(['success' => true]);
        exit;
    }

    // 9. 企画案（Plan）生成
    if ($action === 'plan') {
        $promptTheme = trim($input['theme'] ?? '');
        if (empty($promptTheme)) throw new Exception('テーマが空です。');

        $clientIp = $_SERVER['HTTP_CF_CONNECTING_IP'] ?? $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        $ipHash = md5($clientIp);
        $today = date('Y-m-d');
        $dailyLimit = defined('DAILY_LIMIT_PER_IP') ? DAILY_LIMIT_PER_IP : 25;

        $pdo->beginTransaction();
        $rateStmt = $pdo->prepare("
            INSERT INTO rate_limits (ip_hash, action_date, request_count) VALUES (?, ?, 1)
            ON CONFLICT(ip_hash, action_date) DO UPDATE SET request_count = request_count + 1
        ");
        $rateStmt->execute([$ipHash, $today]);

        $getRateStmt = $pdo->prepare("SELECT request_count FROM rate_limits WHERE ip_hash = ? AND action_date = ?");
        $getRateStmt->execute([$ipHash, $today]);
        $currentCount = (int)$getRateStmt->fetchColumn();
        $pdo->commit();

        if ($currentCount > $dailyLimit) {
            http_response_code(429);
            echo json_encode(['error' => "本日の生成上限（{$dailyLimit}回）に達しました。"]);
            exit;
        }

        $genreKey = classifyThemeByEmbedding($promptTheme, $genres, $storageDir, $apiKey);
        $currentGenre = $genres[$genreKey] ?? ($genres['tech'] ?? ['name' => '専門書']);

        $systemInstruction = "あなたは商業出版の統括編集長です。\n"
                           . "ジャンル【{$currentGenre['name']}】に基づき、全6〜8章構成（各章3〜4節）の専門書企画を策定してください。\n"
                           . "前置きや挨拶、マークダウンコードブロック（```）は出力禁止です。\n"
                           . "最初の文字は必ず { 、最後の文字は必ず } にし、有効なJSONオブジェクトのみを出力してください。";

        $prompt = "以下のテーマで専門書籍の企画・目次構成を立案してください。\n\n"
                . "テーマ: {$promptTheme}\n\n"
                . "【出力必須フォーマット】\n"
                . "{\n"
                . "  \"book_title\": \"書籍タイトル\",\n"
                . "  \"target_audience\": \"想定読者層\",\n"
                . "  \"overview\": \"書籍全体の狙いと概要\",\n"
                . "  \"chapters\": [\n"
                . "    {\n"
                . "      \"chapter_number\": 1,\n"
                . "      \"title\": \"第1章タイトル\",\n"
                . "      \"focus_area\": \"担当する専門技術・理論領域\",\n"
                . "      \"sections\": [\n"
                . "        {\"section_number\": 1, \"title\": \"第1節タイトル\", \"key_focus\": \"重要解説事項\"}\n"
                . "      ]\n"
                . "    }\n"
                . "  ]\n"
                . "}\n\n"
                . "※章数は全6〜8章、各章に3〜4節を含めてください。JSON以外のテキストは絶対に出力しないでください。";

        $schema = [
            'type' => 'object',
            'properties' => [
                'book_title' => ['type' => 'string'],
                'target_audience' => ['type' => 'string'],
                'overview' => ['type' => 'string'],
                'chapters' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'chapter_number' => ['type' => 'integer'],
                            'title' => ['type' => 'string'],
                            'focus_area' => ['type' => 'string'],
                            'sections' => [
                                'type' => 'array',
                                'items' => [
                                    'type' => 'object',
                                    'properties' => [
                                        'section_number' => ['type' => 'integer'],
                                        'title' => ['type' => 'string'],
                                        'key_focus' => ['type' => 'string']
                                    ],
                                    'required' => ['section_number', 'title', 'key_focus']
                                ]
                            ]
                        ],
                        'required' => ['chapter_number', 'title', 'focus_area', 'sections']
                    ]
                ]
            ],
            'required' => ['book_title', 'target_audience', 'overview', 'chapters']
        ];

        $plannerModel = defined('MODEL_PLANNER') ? MODEL_PLANNER : 'inclusionai/ling-3.0-flash';
        $rawText = callUnifiedLLM($plannerModel,$systemInstruction, $prompt,$schema, 0.4);

        $firstBrace = strpos($rawText, '{');
        $lastBrace = strrpos($rawText, '}');
        $jsonStr = ($firstBrace !== false && $lastBrace !== false) ? substr($rawText, $firstBrace, ($lastBrace - $firstBrace) + 1) :$rawText;

        $planData = json_decode($jsonStr, true);
        if (!$planData || !isset($planData['chapters'])) {
            throw new Exception("企画案の生成に失敗しました。応答: " . mb_substr(strip_tags($rawText), 0, 200));
        }

        $bookId = bin2hex(random_bytes(8));$createdAt = date('Y-m-d H:i');

        $planData['id'] =$bookId;
        $planData['created_at'] =$createdAt;
        $planData['genre_key'] =$genreKey;
        $planData['genre_name'] =$currentGenre['name'];

        $insStmt =$pdo->prepare("
            INSERT INTO books (id, title, genre_key, genre_name, target_audience, overview, plan_json, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $insStmt->execute([
            $bookId,$planData['book_title'],
            $genreKey,$currentGenre['name'],
            $planData['target_audience'],$planData['overview'],
            json_encode($planData, JSON_UNESCAPED_UNICODE),$createdAt
        ]);

        echo json_encode(['success' => true, 'book_id' => $bookId, 'plan' =>$planData]);
        exit;
    }

    // 10. 節執筆
    if ($action === 'write_section') {
        $bookId = preg_replace('/[^a-zA-Z0-9_-]/', '',$input['book_id'] ?? '');
        $bookTitle =$input['book_title'] ?? '';
        $chapterNumber = (int)($input['chapter_number'] ?? 1);
        $chapterTitle =$input['chapter_title'] ?? '';
        $focusArea =$input['focus_area'] ?? '';
        $section =$input['section'] ?? [];
        $sectionNumber = (int)($section['section_number'] ?? 1);

        $systemInstruction = "あなたは書籍『{$bookTitle}』の専門執筆エージェントです。\n"
                           . "【絶対禁止】自己紹介や挨拶、前置き、Markdown記法は禁止。\n"
                           . "【AsciiDoc構文】\n"
                           . "- 節見出し（第{$sectionNumber}節）は `== `、小見出しは `=== `\n"
                           . "- 太字は `*太字*`（前後半角スペース）\n"
                           . "- 箇条書きは空行後に `* `\n"
                           . "- テーブルは `|===` で囲む\n"
                           . "- 数式は `[stem]` と `++++` で囲む\n"
                           . "- 今回担当する【第{$sectionNumber}節】の内容のみを徹底的に執筆してください。";

        $prompt = "第{$chapterNumber}章「{$chapterTitle}」({$focusArea})\n"
                . "担当節: 第{$sectionNumber}節「{$section['title']}」\n"
                . "重点解説事項: {$section['key_focus']}\n\n"
                . "上記節の本文をAsciiDocで執筆してください。";

        $writerModel = defined('MODEL_WRITER') ? MODEL_WRITER : 'inclusionai/ling-3.0-flash';
        $content = callUnifiedLLM($writerModel, $systemInstruction,$prompt, null, 0.7);

        $stmt =$pdo->prepare("
            INSERT INTO chapter_sections (book_id, chapter_number, section_number, content, updated_at)
            VALUES (?, ?, ?, ?, ?)
            ON CONFLICT(book_id, chapter_number, section_number) DO UPDATE SET content = excluded.content, updated_at = excluded.updated_at
        ");
        $stmt->execute([$bookId,$chapterNumber, $sectionNumber,$content, date('Y-m-d H:i:s')]);

        echo json_encode([
            'success' => true,
            'chapter_number' => $chapterNumber,
            'section_number' => $sectionNumber
        ]);
        exit;
    }

    throw new Exception('不正なリクエストです。');

} catch (Exception $e) {
    if ($pdo->inTransaction()) {$pdo->rollBack();
    }
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}