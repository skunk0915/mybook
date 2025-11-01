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

// クエリパラメータの取得
$query = isset($_GET['q']) ? trim($_GET['q']) : '';

if (empty($query)) {
    http_response_code(400);
    echo json_encode(['error' => '検索キーワードを入力してください'], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    // Google Books APIのURL構築
    // 日本語の書籍を優先的に検索
    $apiUrl = 'https://www.googleapis.com/books/v1/volumes?q='
        . urlencode($query)
        . '&langRestrict=ja'  // 日本語の書籍を優先
        . '&maxResults=10'     // 最大10件の結果
        . '&printType=books'   // 書籍のみ
        . '&orderBy=relevance'; // 関連性の高い順

    // cURLでGoogle Books APIを呼び出し
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $apiUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

    if (curl_errno($ch)) {
        throw new Exception('検索APIへの接続に失敗しました: ' . curl_error($ch));
    }

    curl_close($ch);

    if ($httpCode !== 200) {
        throw new Exception('検索APIからエラーが返されました (HTTP ' . $httpCode . ')');
    }

    $data = json_decode($response, true);

    if (!$data || !isset($data['items'])) {
        // 結果が見つからない場合は空の配列を返す
        echo json_encode(['results' => []], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // 結果を整形
    $results = [];
    foreach ($data['items'] as $item) {
        $volumeInfo = $item['volumeInfo'];

        // 表紙画像のURLを取得（大きい順に試行）
        $thumbnail = null;
        if (isset($volumeInfo['imageLinks'])) {
            $imageLinks = $volumeInfo['imageLinks'];
            // HTTPSのURLに変換
            if (isset($imageLinks['extraLarge'])) {
                $thumbnail = str_replace('http://', 'https://', $imageLinks['extraLarge']);
            } elseif (isset($imageLinks['large'])) {
                $thumbnail = str_replace('http://', 'https://', $imageLinks['large']);
            } elseif (isset($imageLinks['medium'])) {
                $thumbnail = str_replace('http://', 'https://', $imageLinks['medium']);
            } elseif (isset($imageLinks['small'])) {
                $thumbnail = str_replace('http://', 'https://', $imageLinks['small']);
            } elseif (isset($imageLinks['thumbnail'])) {
                $thumbnail = str_replace('http://', 'https://', $imageLinks['thumbnail']);
            } elseif (isset($imageLinks['smallThumbnail'])) {
                $thumbnail = str_replace('http://', 'https://', $imageLinks['smallThumbnail']);
            }

            // zoom=1パラメータを追加して高解像度版を取得
            if ($thumbnail) {
                $thumbnail = preg_replace('/&zoom=\d+/', '', $thumbnail);
                $thumbnail .= '&zoom=1';
            }
        }

        // 著者名の取得
        $authors = isset($volumeInfo['authors'])
            ? implode(', ', $volumeInfo['authors'])
            : '';

        // 出版日の取得
        $publishedDate = isset($volumeInfo['publishedDate'])
            ? $volumeInfo['publishedDate']
            : '';

        // 説明文の取得
        $description = isset($volumeInfo['description'])
            ? mb_substr($volumeInfo['description'], 0, 200) . '...'
            : '';

        $results[] = [
            'title' => $volumeInfo['title'] ?? '不明なタイトル',
            'authors' => $authors,
            'thumbnail' => $thumbnail,
            'publishedDate' => $publishedDate,
            'description' => $description,
            'googleBooksId' => $item['id'] ?? null
        ];
    }

    echo json_encode(['results' => $results], JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
