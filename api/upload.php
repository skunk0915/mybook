<?php
/**
 * 画像アップロードAPI
 * POST /api/upload.php
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

// プリフライトリクエスト対応
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception('POSTメソッドのみサポートしています');
    }

    // 画像URLからのダウンロード処理
    $imageUrl = $_POST['imageUrl'] ?? null;

    if ($imageUrl) {
        // URLから画像をダウンロード
        $imageData = @file_get_contents($imageUrl);

        if ($imageData === false) {
            throw new Exception('画像のダウンロードに失敗しました');
        }

        // 一時ファイルに保存
        $tmpFile = tempnam(sys_get_temp_dir(), 'book_');
        file_put_contents($tmpFile, $imageData);

        // MIMEタイプを取得
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mimeType = finfo_file($finfo, $tmpFile);
        finfo_close($finfo);

        // 拡張子を決定
        $extension = '';
        switch ($mimeType) {
            case 'image/jpeg':
                $extension = 'jpg';
                break;
            case 'image/png':
                $extension = 'png';
                break;
            case 'image/gif':
                $extension = 'gif';
                break;
            case 'image/webp':
                $extension = 'webp';
                break;
            default:
                unlink($tmpFile);
                throw new Exception('サポートされていないファイル形式です');
        }

        // ユニークなファイル名を生成
        $fileName = uniqid('book_', true) . '.' . $extension;
        $uploadDir = dirname(__DIR__) . '/uploads/';

        // アップロードディレクトリが存在しない場合は作成
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0777, true);
        }

        $uploadPath = $uploadDir . $fileName;

        // ファイルを移動
        if (!rename($tmpFile, $uploadPath)) {
            unlink($tmpFile);
            throw new Exception('ファイルの保存に失敗しました');
        }

        // 相対パスを返す
        $relativePath = 'uploads/' . $fileName;

        echo json_encode([
            'success' => true,
            'path' => $relativePath,
            'filename' => $fileName
        ], JSON_UNESCAPED_UNICODE);

        exit;
    }

    if (!isset($_FILES['image'])) {
        throw new Exception('画像ファイルが送信されていません');
    }

    $file = $_FILES['image'];

    // エラーチェック
    if ($file['error'] !== UPLOAD_ERR_OK) {
        throw new Exception('ファイルアップロードエラー: ' . $file['error']);
    }

    // ファイルサイズチェック（5MB以下）
    $maxSize = 5 * 1024 * 1024;
    if ($file['size'] > $maxSize) {
        throw new Exception('ファイルサイズが大きすぎます（最大5MB）');
    }

    // MIME タイプチェック
    $allowedTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mimeType = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);

    if (!in_array($mimeType, $allowedTypes)) {
        throw new Exception('サポートされていないファイル形式です（JPEG, PNG, GIF, WebPのみ）');
    }

    // 拡張子を取得
    $extension = '';
    switch ($mimeType) {
        case 'image/jpeg':
            $extension = 'jpg';
            break;
        case 'image/png':
            $extension = 'png';
            break;
        case 'image/gif':
            $extension = 'gif';
            break;
        case 'image/webp':
            $extension = 'webp';
            break;
    }

    // ユニークなファイル名を生成
    $fileName = uniqid('book_', true) . '.' . $extension;
    $uploadDir = dirname(__DIR__) . '/uploads/';

    // アップロードディレクトリが存在しない場合は作成
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0777, true);
    }

    $uploadPath = $uploadDir . $fileName;

    // ファイルを移動
    if (!move_uploaded_file($file['tmp_name'], $uploadPath)) {
        throw new Exception('ファイルの保存に失敗しました');
    }

    // 相対パスを返す
    $relativePath = 'uploads/' . $fileName;

    echo json_encode([
        'success' => true,
        'path' => $relativePath,
        'filename' => $fileName
    ], JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}
