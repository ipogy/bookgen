<?php
// config.php
// ※ 外部から直接アクセスされない場所（または.htaccessでアクセス禁止）に配置
define('GEMINI_API_KEY', 'gemini_api_key_example');
define('DATA_DIR', __DIR__ . '/books'); // 書籍保存先
define('OPENROUTER_API_KEY', 'openrouter_api_key_example');

// 仲間内だけが新規登録できる「招待コード（合言葉）」
define('INVITE_CODE', 'invite_code_example');

// 1日あたりの生成上限（同一IPからの暴走防止用）
define('DAILY_LIMIT_PER_IP', 20);

// 1. ジャンル自動分類用（Embeddingモデル）
define('MODEL_EMBEDDING', 'gemini-embedding-2');

// 2. 統括編集長・構成立案用（推論・目次設計）
define('MODEL_PLANNER', 'inclusionai/ling-3.0-flash');

// 3. 各章・節の本文執筆用エージェント
define('MODEL_WRITER', 'inclusionai/ling-3.0-flash');

define('OPENROUTER_PROVIDERS', [
    'novita'
]);