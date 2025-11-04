<?php
/**
 * 画像プロキシAPI
 * CORS問題を回避するため、サーバー側で外部画像を取得してクライアントに返す
 * GET /api/image-proxy.php?url={画像URL}
 */

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

    if (!isset($_GET['url']) || trim($_GET['url']) === '') {
        throw new Exception('画像URLを指定してください');
    }

    $imageUrl = $_GET['url'];

    // URLの検証（Google Books APIのURLのみ許可）
    if (strpos($imageUrl, 'books.google.com') === false &&
        strpos($imageUrl, 'books.googleusercontent.com') === false) {
        throw new Exception('許可されていないURLです');
    }

    // HTTPSに変換
    $imageUrl = str_replace('http://', 'https://', $imageUrl);

    // cURLで画像を取得
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $imageUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36'
    ]);

    $imageData = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $contentType = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    $error = curl_error($ch);
    curl_close($ch);

    if ($error) {
        throw new Exception('画像の取得に失敗しました: ' . $error);
    }

    if ($httpCode !== 200) {
        throw new Exception('画像の取得に失敗しました (HTTP ' . $httpCode . ')');
    }

    if (!$imageData) {
        throw new Exception('画像データが空です');
    }

    // Content-Typeを設定して画像データを返す
    if ($contentType) {
        header('Content-Type: ' . $contentType);
    } else {
        header('Content-Type: image/jpeg'); // デフォルト
    }

    header('Content-Length: ' . strlen($imageData));
    header('Cache-Control: public, max-age=86400'); // 1日キャッシュ

    echo $imageData;

} catch (Exception $e) {
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}
