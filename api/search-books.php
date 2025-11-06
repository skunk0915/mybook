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
 * .envファイルを読み込む
 */
function loadEnv($path) {
    if (!file_exists($path)) {
        return false;
    }

    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        // コメント行をスキップ
        if (strpos(trim($line), '#') === 0) {
            continue;
        }

        // キーと値を分割
        list($name, $value) = explode('=', $line, 2);
        $name = trim($name);
        $value = trim($value);

        // 環境変数として設定
        if (!array_key_exists($name, $_ENV)) {
            putenv("$name=$value");
            $_ENV[$name] = $value;
        }
    }

    return true;
}

/**
 * AWS Signature Version 4による署名を生成
 */
function generateAwsSignature($method, $uri, $queryString, $payload, $headers, $accessKey, $secretKey, $region, $service) {
    $algorithm = 'AWS4-HMAC-SHA256';
    $amzDate = gmdate('Ymd\THis\Z');
    $dateStamp = gmdate('Ymd');

    // Canonical Request を作成
    $canonicalHeaders = '';
    $signedHeaders = '';
    ksort($headers);
    foreach ($headers as $key => $value) {
        $canonicalHeaders .= strtolower($key) . ':' . trim($value) . "\n";
        $signedHeaders .= strtolower($key) . ';';
    }
    $signedHeaders = rtrim($signedHeaders, ';');

    $canonicalRequest = implode("\n", [
        $method,
        $uri,
        $queryString,
        $canonicalHeaders,
        $signedHeaders,
        hash('sha256', $payload)
    ]);

    // String to Sign を作成
    $credentialScope = "$dateStamp/$region/$service/aws4_request";
    $stringToSign = implode("\n", [
        $algorithm,
        $amzDate,
        $credentialScope,
        hash('sha256', $canonicalRequest)
    ]);

    // 署名キーを生成
    $kDate = hash_hmac('sha256', $dateStamp, 'AWS4' . $secretKey, true);
    $kRegion = hash_hmac('sha256', $region, $kDate, true);
    $kService = hash_hmac('sha256', $service, $kRegion, true);
    $kSigning = hash_hmac('sha256', 'aws4_request', $kService, true);

    // 署名を生成
    $signature = hash_hmac('sha256', $stringToSign, $kSigning);

    // Authorization ヘッダーを生成
    $authorizationHeader = "$algorithm Credential=$accessKey/$credentialScope, SignedHeaders=$signedHeaders, Signature=$signature";

    return [
        'authorization' => $authorizationHeader,
        'x-amz-date' => $amzDate
    ];
}

/**
 * Amazon PA API v5で書籍を検索
 */
function searchBooksOnAmazon($query, $page = 1, $itemsPerPage = 10) {
    // 環境変数を読み込み
    $envPath = dirname(__DIR__) . '/.env';
    if (!loadEnv($envPath)) {
        throw new Exception('.envファイルが見つかりません。.env.exampleを参考に.envファイルを作成してください。');
    }

    $accessKey = getenv('AWS_ACCESS_KEY_ID');
    $secretKey = getenv('AWS_SECRET_ACCESS_KEY');
    $associateTag = getenv('ASSOCIATE_TAG');
    $region = getenv('AWS_REGION') ?: 'us-west-2';
    $host = getenv('PA_API_HOST') ?: 'webservices.amazon.co.jp';
    $marketplace = getenv('MARKETPLACE') ?: 'www.amazon.co.jp';

    if (!$accessKey || !$secretKey || !$associateTag) {
        throw new Exception('AWS認証情報が設定されていません。.envファイルを確認してください。');
    }

    // PA API v5 SearchItems リクエストボディ
    $requestPayload = [
        'Keywords' => $query,
        'Resources' => [
            'Images.Primary.Large',
            'Images.Primary.Medium',
            'Images.Primary.Small',
            'ItemInfo.Title',
            'ItemInfo.ByLineInfo',
            'ItemInfo.ContentInfo',
            'ItemInfo.ProductInfo'
        ],
        'SearchIndex' => 'Books',
        'ItemCount' => $itemsPerPage,
        'ItemPage' => $page,
        'PartnerTag' => $associateTag,
        'PartnerType' => 'Associates',
        'Marketplace' => $marketplace
    ];

    $payload = json_encode($requestPayload);
    $uri = '/paapi5/searchitems';
    $method = 'POST';
    $service = 'ProductAdvertisingAPI';

    // ヘッダーを準備
    $headers = [
        'content-encoding' => 'amz-1.0',
        'content-type' => 'application/json; charset=utf-8',
        'host' => $host,
        'x-amz-target' => 'com.amazon.paapi5.v1.ProductAdvertisingAPIv1.SearchItems'
    ];

    // AWS署名を生成
    $auth = generateAwsSignature($method, $uri, '', $payload, $headers, $accessKey, $secretKey, $region, $service);

    // cURLでリクエスト
    $ch = curl_init('https://' . $host . $uri);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: ' . $auth['authorization'],
        'Content-Type: ' . $headers['content-type'],
        'Content-Encoding: ' . $headers['content-encoding'],
        'Host: ' . $headers['host'],
        'X-Amz-Date: ' . $auth['x-amz-date'],
        'X-Amz-Target: ' . $headers['x-amz-target']
    ]);
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

    if (curl_errno($ch)) {
        throw new Exception('Amazon PA APIへの接続に失敗しました: ' . curl_error($ch));
    }

    curl_close($ch);

    if ($httpCode !== 200) {
        $errorData = json_decode($response, true);
        $errorMessage = isset($errorData['Errors'][0]['Message'])
            ? $errorData['Errors'][0]['Message']
            : 'Amazon PA APIからエラーが返されました (HTTP ' . $httpCode . ')';
        throw new Exception($errorMessage);
    }

    return json_decode($response, true);
}

/**
 * Amazon PA APIの結果を整形
 */
function formatAmazonResults($data) {
    if (!isset($data['SearchResult']['Items'])) {
        return [];
    }

    $results = [];
    foreach ($data['SearchResult']['Items'] as $item) {
        $itemInfo = $item['ItemInfo'] ?? [];
        $images = $item['Images']['Primary'] ?? [];

        // タイトルを取得
        $title = isset($itemInfo['Title']['DisplayValue'])
            ? $itemInfo['Title']['DisplayValue']
            : '不明なタイトル';

        // 著者を取得
        $authors = '';
        if (isset($itemInfo['ByLineInfo']['Contributors'])) {
            $authorsList = [];
            foreach ($itemInfo['ByLineInfo']['Contributors'] as $contributor) {
                if (isset($contributor['Name'])) {
                    $authorsList[] = $contributor['Name'];
                }
            }
            $authors = implode(', ', $authorsList);
        }

        // 表紙画像URLを取得（大きい順）
        $thumbnail = null;
        if (isset($images['Large']['URL'])) {
            $thumbnail = $images['Large']['URL'];
        } elseif (isset($images['Medium']['URL'])) {
            $thumbnail = $images['Medium']['URL'];
        } elseif (isset($images['Small']['URL'])) {
            $thumbnail = $images['Small']['URL'];
        }

        // 出版日を取得
        $publishedDate = '';
        if (isset($itemInfo['ContentInfo']['PublicationDate']['DisplayValue'])) {
            $publishedDate = $itemInfo['ContentInfo']['PublicationDate']['DisplayValue'];
        }

        // 説明を取得（Amazon PA APIでは直接取得できないため、代わりにページ数などを表示）
        $description = '';
        if (isset($itemInfo['ContentInfo']['PagesCount']['DisplayValue'])) {
            $description = 'ページ数: ' . $itemInfo['ContentInfo']['PagesCount']['DisplayValue'];
        }

        // ASINを取得
        $asin = $item['ASIN'] ?? null;

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
    // クエリパラメータの取得
    $query = isset($_GET['q']) ? trim($_GET['q']) : '';
    $page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;

    if (empty($query)) {
        http_response_code(400);
        echo json_encode(['error' => '検索キーワードを入力してください'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // Amazon PA APIで検索
    $data = searchBooksOnAmazon($query, $page, 10);

    // 結果を整形
    $results = formatAmazonResults($data);

    // 総ページ数を計算（Amazon PA APIの制限により最大10ページ）
    $totalPages = isset($data['SearchResult']['TotalResultCount'])
        ? min(10, ceil($data['SearchResult']['TotalResultCount'] / 10))
        : 1;

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
