<?php
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

// OPTIONSリクエストの処理
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// GETリクエストのみ受け付け
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed'], JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * ログ記録関数（最大10MBでローテーション）
 */
function writeApiLog($message, $level = 'INFO') {
    $logDir = dirname(__DIR__) . '/logs';
    if (!is_dir($logDir)) {
        @mkdir($logDir, 0777, true);
    }

    $logFile = $logDir . '/api.log';
    $maxBytes = 10 * 1024 * 1024; // 10MB目安

    if (file_exists($logFile) && filesize($logFile) > $maxBytes) {
        // 直近2MB分を残して切り詰め
        $content = @file_get_contents($logFile);
        if ($content !== false && strlen($content) > 2 * 1024 * 1024) {
            $recent = substr($content, - (2 * 1024 * 1024));
            @file_put_contents($logFile, $recent);
        }
    }

    $timestamp = date('Y-m-d H:i:s');
    $line = sprintf("[%s] [%s] %s\n", $timestamp, $level, $message);
    @file_put_contents($logFile, $line, FILE_APPEND);
}

/**
 * .envファイルを読み込む
 */
function loadEnv($path) {
    if (!file_exists($path)) {
        return false;
    }

    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        $trimmed = trim($line);
        if (strpos($trimmed, '#') === 0 || empty($trimmed)) {
            continue;
        }

        if (strpos($line, '=') === false) {
            continue;
        }

        list($name, $value) = explode('=', $line, 2);
        $name = trim($name);
        $value = trim($value);

        // クォートの除去
        if (preg_match('/^(["\'])(.*)\1$/', $value, $matches)) {
            $value = $matches[2];
        }

        if (!array_key_exists($name, $_ENV)) {
            putenv("$name=$value");
            $_ENV[$name] = $value;
        }
    }

    return true;
}

/**
 * Amazon Creators APIのアクセストークンを取得（OAuth 2.0 / キャッシュ付き）
 */
function getCreatorsAccessToken($clientId, $clientSecret, $tokenEndpoint = 'https://api.amazon.co.jp/auth/o2/token') {
    $cacheDir = dirname(__DIR__) . '/data';
    if (!is_dir($cacheDir)) {
        @mkdir($cacheDir, 0777, true);
    }
    $cacheFile = $cacheDir . '/.creators_token.json';

    // キャッシュ確認
    if (file_exists($cacheFile)) {
        $cache = json_decode(@file_get_contents($cacheFile), true);
        if ($cache && isset($cache['access_token']) && isset($cache['expires_at'])) {
            // 有効期限の60秒前まで再利用
            if ($cache['expires_at'] > time() + 60) {
                return $cache['access_token'];
            }
        }
    }

    // トークンを新規取得
    $postData = json_encode([
        'grant_type' => 'client_credentials',
        'client_id' => $clientId,
        'client_secret' => $clientSecret,
        'scope' => 'creatorsapi::default'
    ]);

    $ch = curl_init($tokenEndpoint);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $postData);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json; charset=utf-8'
    ]);
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);

    if ($curlError) {
        writeApiLog('Amazon Creators API トークン取得 cURLエラー: ' . $curlError, 'ERROR');
        throw new Exception('Amazon認証サーバーへの接続に失敗しました: ' . $curlError);
    }

    $resData = json_decode($response, true);

    if ($httpCode !== 200 || !isset($resData['access_token'])) {
        $msg = $resData['error_description'] ?? $resData['message'] ?? $resData['error'] ?? ('HTTP ' . $httpCode);
        writeApiLog('Amazon Creators API トークン取得エラー: ' . $msg . ' (Response: ' . $response . ')', 'ERROR');
        throw new Exception('Amazon認証トークンの取得に失敗しました: ' . $msg);
    }

    $accessToken = $resData['access_token'];
    $expiresIn = intval($resData['expires_in'] ?? 3600);

    // キャッシュ保存
    $cacheData = [
        'access_token' => $accessToken,
        'expires_at' => time() + $expiresIn
    ];
    @file_put_contents($cacheFile, json_encode($cacheData));

    return $accessToken;
}

/**
 * Amazon Creators APIで書籍を検索
 */
function searchBooksOnCreatorsApi($query, $page = 1, $itemsPerPage = 10) {
    $envPath = dirname(__DIR__) . '/.env';
    if (!loadEnv($envPath)) {
        throw new Exception('.envファイルが見つかりません。.env.exampleを参考に.envファイルを作成してください。');
    }

    // 認証情報の取得（新Creators APIキーを優先、互換性のために旧キー名も参照）
    $credentialId = getenv('AMAZON_CREDENTIAL_ID') ?: getenv('AWS_ACCESS_KEY_ID');
    $credentialSecret = getenv('AMAZON_CREDENTIAL_SECRET') ?: getenv('AWS_SECRET_ACCESS_KEY');
    $partnerTag = getenv('AMAZON_ASSOCIATE_TAG') ?: getenv('ASSOCIATE_TAG');
    $marketplace = getenv('AMAZON_MARKETPLACE') ?: (getenv('MARKETPLACE') ?: 'www.amazon.co.jp');
    $tokenEndpoint = getenv('AMAZON_TOKEN_ENDPOINT') ?: 'https://api.amazon.co.jp/auth/o2/token';

    if (!$credentialId || !$credentialSecret || !$partnerTag) {
        throw new Exception('Amazon Creators APIの認証情報が設定されていません。.envに AMAZON_CREDENTIAL_ID, AMAZON_CREDENTIAL_SECRET, AMAZON_ASSOCIATE_TAG を設定してください。');
    }

    // アクセストークン取得
    $accessToken = getCreatorsAccessToken($credentialId, $credentialSecret, $tokenEndpoint);

    // Creators API searchItems エンドポイント
    $apiEndpoint = 'https://creatorsapi.amazon/catalog/v1/searchItems';

    $requestPayload = [
        'keywords' => $query,
        'searchIndex' => 'Books',
        'itemCount' => $itemsPerPage,
        'itemPage' => $page,
        'partnerTag' => $partnerTag,
        'partnerType' => 'Associates',
        'marketplace' => $marketplace,
        'sortBy' => 'Relevance',
        'availability' => 'Available',
        'resources' => [
            'images.primary.large',
            'images.primary.medium',
            'images.primary.small',
            'itemInfo.title',
            'itemInfo.byLineInfo',
            'itemInfo.contentInfo',
            'itemInfo.productInfo',
            'itemInfo.features',
            'itemInfo.manufactureInfo',
            'itemInfo.classifications',
            'itemInfo.externalIds'
        ]
    ];

    $payload = json_encode($requestPayload);

    $ch = curl_init($apiEndpoint);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Bearer ' . $accessToken,
        'Content-Type: application/json; charset=utf-8',
        'x-marketplace: ' . $marketplace
    ]);
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);

    if ($curlError) {
        writeApiLog('Amazon Creators API 検索 cURLエラー: ' . $curlError, 'ERROR');
        throw new Exception('Amazon Creators APIへの接続に失敗しました: ' . $curlError);
    }

    if ($httpCode !== 200) {
        $errorData = json_decode($response, true);
        $errorMessage = $errorData['errors'][0]['message']
            ?? $errorData['Errors'][0]['Message']
            ?? $errorData['message']
            ?? ('Amazon Creators APIエラー (HTTP ' . $httpCode . ')');

        writeApiLog('Amazon Creators API エラー: ' . $errorMessage . ' (Payload: ' . $payload . ')', 'ERROR');
        throw new Exception($errorMessage);
    }

    return json_decode($response, true);
}

/**
 * Amazon Creators APIの結果をフロントエンド形式に整形
 */
function formatCreatorsResults($data) {
    // lowerCamelCase と PascalCase の双方に対応
    $items = $data['searchResult']['items']
        ?? $data['SearchResult']['Items']
        ?? [];

    if (empty($items)) {
        return [];
    }

    $results = [];
    foreach ($items as $item) {
        $itemInfo = $item['itemInfo'] ?? $item['ItemInfo'] ?? [];
        $images = $item['images']['primary'] ?? $item['Images']['Primary'] ?? [];

        // タイトル
        $title = $itemInfo['title']['displayValue']
            ?? $itemInfo['Title']['DisplayValue']
            ?? '不明なタイトル';

        // 著者
        $authors = '';
        $contributors = $itemInfo['byLineInfo']['contributors']
            ?? $itemInfo['ByLineInfo']['Contributors']
            ?? [];

        if (is_array($contributors)) {
            $authorsList = [];
            foreach ($contributors as $contributor) {
                $name = $contributor['name'] ?? $contributor['Name'] ?? null;
                if ($name) {
                    $authorsList[] = $name;
                }
            }
            $authors = implode(', ', $authorsList);
        }

        // 表紙画像URL（大きい順）
        $thumbnail = $images['large']['url']
            ?? $images['Large']['URL']
            ?? $images['medium']['url']
            ?? $images['Medium']['URL']
            ?? $images['small']['url']
            ?? $images['Small']['URL']
            ?? null;

        // 出版日
        $publishedDate = $itemInfo['contentInfo']['publicationDate']['displayValue']
            ?? $itemInfo['ContentInfo']['PublicationDate']['DisplayValue']
            ?? '';

        // 説明（ページ数、出版社、ISBN、特徴など）
        $descParts = [];

        $pages = $itemInfo['contentInfo']['pagesCount']['displayValue']
            ?? $itemInfo['ContentInfo']['PagesCount']['DisplayValue']
            ?? null;
        if ($pages) {
            $descParts[] = 'ページ数: ' . $pages;
        }

        $manufacturer = $itemInfo['byLineInfo']['manufacturer']['displayValue']
            ?? $itemInfo['ByLineInfo']['Manufacturer']['DisplayValue']
            ?? null;
        if ($manufacturer) {
            $descParts[] = '出版社: ' . $manufacturer;
        }

        $isbns = $itemInfo['externalIds']['isbns']['displayValues']
            ?? $itemInfo['ExternalIds']['ISBNs']['DisplayValues']
            ?? [];
        if (!empty($isbns[0])) {
            $descParts[] = 'ISBN: ' . $isbns[0];
        }

        $features = $itemInfo['features']['displayValues']
            ?? $itemInfo['Features']['DisplayValues']
            ?? [];
        if (is_array($features) && !empty($features)) {
            $descParts = array_merge($descParts, array_slice($features, 0, 2));
        }

        $description = implode(' / ', $descParts);

        // ASIN
        $asin = $item['asin'] ?? $item['ASIN'] ?? null;

        $results[] = [
            'title' => $title,
            'authors' => $authors,
            'thumbnail' => $thumbnail,
            'publishedDate' => $publishedDate,
            'description' => $description,
            'asin' => $asin
        ];
    }

    return $results;
}

try {
    $query = isset($_GET['q']) ? trim($_GET['q']) : '';
    $page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;

    if (empty($query)) {
        http_response_code(400);
        echo json_encode(['error' => '検索キーワードを入力してください'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // Amazon Creators APIで検索
    $data = searchBooksOnCreatorsApi($query, $page, 10);

    // 整形
    $results = formatCreatorsResults($data);

    // 総ページ数（最大10ページ）
    $totalCount = $data['searchResult']['totalResultCount']
        ?? $data['SearchResult']['TotalResultCount']
        ?? 0;

    $totalPages = $totalCount > 0 ? min(10, (int)ceil($totalCount / 10)) : 1;

    echo json_encode([
        'results' => $results,
        'currentPage' => $page,
        'totalPages' => $totalPages,
        'hasNextPage' => $page < $totalPages
    ], JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
