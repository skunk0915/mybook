<?php
/**
 * 書籍検索API (Google Books APIを使用)
 * GET /api/search-books.php?q={検索キーワード}
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

// プリフライトリクエスト対応
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        throw new Exception('GETメソッドのみサポートしています');
    }

    if (!isset($_GET['q']) || trim($_GET['q']) === '') {
        throw new Exception('検索キーワードを指定してください');
    }

    $query = trim($_GET['q']);
    $maxResults = isset($_GET['max']) ? min((int)$_GET['max'], 40) : 10;

    // Google Books APIに検索リクエスト
    $apiUrl = 'https://www.googleapis.com/books/v1/volumes';
    $params = [
        'q' => $query,
        'maxResults' => $maxResults,
        'langRestrict' => 'ja', // 日本語の本を優先
        'printType' => 'books',
        'orderBy' => 'relevance'
    ];

    $url = $apiUrl . '?' . http_build_query($params);

    // cURLを使用してAPIリクエスト
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false); // 開発環境用（本番では true に設定）
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    if ($error) {
        throw new Exception('API通信エラー: ' . $error);
    }

    if ($httpCode !== 200) {
        throw new Exception('APIエラー (HTTP ' . $httpCode . ')');
    }

    $data = json_decode($response, true);

    if (!$data || !isset($data['items'])) {
        echo json_encode([
            'success' => true,
            'total' => 0,
            'books' => []
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // レスポンスを整形
    $books = [];
    foreach ($data['items'] as $item) {
        $volumeInfo = $item['volumeInfo'];

        // 画像URLを取得（大きいサイズを優先）
        $thumbnail = null;
        if (isset($volumeInfo['imageLinks'])) {
            // extraLargeがあればそれを優先
            if (isset($volumeInfo['imageLinks']['extraLarge'])) {
                $thumbnail = $volumeInfo['imageLinks']['extraLarge'];
            } elseif (isset($volumeInfo['imageLinks']['large'])) {
                $thumbnail = $volumeInfo['imageLinks']['large'];
            } elseif (isset($volumeInfo['imageLinks']['medium'])) {
                $thumbnail = $volumeInfo['imageLinks']['medium'];
            } elseif (isset($volumeInfo['imageLinks']['thumbnail'])) {
                $thumbnail = $volumeInfo['imageLinks']['thumbnail'];
            } elseif (isset($volumeInfo['imageLinks']['smallThumbnail'])) {
                $thumbnail = $volumeInfo['imageLinks']['smallThumbnail'];
            }

            // HTTPSに変換
            if ($thumbnail) {
                $thumbnail = str_replace('http://', 'https://', $thumbnail);

                // 高解像度の画像を取得するためにzoomパラメータを調整
                // zoom=1（デフォルト）をzoom=0に変更してより大きな画像を取得
                $thumbnail = preg_replace('/[&?]zoom=\d+/', '', $thumbnail);

                // URLにクエリパラメータがあるか確認
                if (strpos($thumbnail, '?') !== false) {
                    $thumbnail .= '&zoom=0';
                } else {
                    $thumbnail .= '?zoom=0';
                }
            }
        }

        $books[] = [
            'id' => $item['id'] ?? null,
            'title' => $volumeInfo['title'] ?? '(タイトルなし)',
            'authors' => $volumeInfo['authors'] ?? [],
            'publisher' => $volumeInfo['publisher'] ?? null,
            'publishedDate' => $volumeInfo['publishedDate'] ?? null,
            'description' => $volumeInfo['description'] ?? null,
            'thumbnail' => $thumbnail,
            'pageCount' => $volumeInfo['pageCount'] ?? null,
            'categories' => $volumeInfo['categories'] ?? [],
            'averageRating' => $volumeInfo['averageRating'] ?? null,
            'isbn' => getIsbn($volumeInfo),
            'infoLink' => $volumeInfo['infoLink'] ?? null
        ];
    }

    echo json_encode([
        'success' => true,
        'total' => $data['totalItems'] ?? 0,
        'books' => $books
    ], JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}

/**
 * ISBN番号を取得
 */
function getIsbn($volumeInfo) {
    if (!isset($volumeInfo['industryIdentifiers'])) {
        return null;
    }

    foreach ($volumeInfo['industryIdentifiers'] as $identifier) {
        if ($identifier['type'] === 'ISBN_13') {
            return $identifier['identifier'];
        }
    }

    foreach ($volumeInfo['industryIdentifiers'] as $identifier) {
        if ($identifier['type'] === 'ISBN_10') {
            return $identifier['identifier'];
        }
    }

    return null;
}
