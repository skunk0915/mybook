<?php
/**
 * 書籍管理API
 * GET    /api/books.php       - 全書籍取得（フィルタ・ソート対応）
 * GET    /api/books.php?id=1  - 特定書籍取得
 * POST   /api/books.php       - 書籍登録
 * PUT    /api/books.php?id=1  - 書籍更新
 * DELETE /api/books.php?id=1  - 書籍削除
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

// プリフライトリクエスト対応
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once 'db.php';

try {
    $database = new Database();
    $db = $database->getConnection();
    $method = $_SERVER['REQUEST_METHOD'];

    switch ($method) {
        case 'GET':
            handleGet($db);
            break;
        case 'POST':
            handlePost($db);
            break;
        case 'PUT':
            handlePut($db);
            break;
        case 'DELETE':
            handleDelete($db);
            break;
        default:
            throw new Exception('サポートされていないメソッドです');
    }
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}

/**
 * GET: 書籍取得
 */
function handleGet($db) {
    if (isset($_GET['id'])) {
        // 特定の書籍を取得
        $stmt = $db->prepare('SELECT * FROM books WHERE id = ?');
        $stmt->execute([$_GET['id']]);
        $book = $stmt->fetch();

        if ($book) {
            echo json_encode($book, JSON_UNESCAPED_UNICODE);
        } else {
            http_response_code(404);
            echo json_encode(['error' => '書籍が見つかりません'], JSON_UNESCAPED_UNICODE);
        }
    } else {
        // 全書籍を取得（フィルタ・ソート対応）
        $sql = 'SELECT * FROM books WHERE 1=1';
        $params = [];

        // フィルタリング: 状態
        if (isset($_GET['status']) && $_GET['status'] !== '') {
            $statuses = explode(',', $_GET['status']);
            $placeholders = str_repeat('?,', count($statuses) - 1) . '?';
            $sql .= " AND status IN ($placeholders)";
            $params = array_merge($params, $statuses);
        }

        // フィルタリング: 期間指定
        if (isset($_GET['start_from']) && $_GET['start_from'] !== '') {
            $sql .= ' AND start_date >= ?';
            $params[] = $_GET['start_from'];
        }
        if (isset($_GET['start_to']) && $_GET['start_to'] !== '') {
            $sql .= ' AND start_date <= ?';
            $params[] = $_GET['start_to'];
        }
        if (isset($_GET['end_from']) && $_GET['end_from'] !== '') {
            $sql .= ' AND end_date >= ?';
            $params[] = $_GET['end_from'];
        }
        if (isset($_GET['end_to']) && $_GET['end_to'] !== '') {
            $sql .= ' AND end_date <= ?';
            $params[] = $_GET['end_to'];
        }

        // ソート
        $orderBy = 'start_date';
        $orderDir = 'DESC';

        if (isset($_GET['sort'])) {
            switch ($_GET['sort']) {
                case 'start_date_asc':
                    $orderBy = 'start_date';
                    $orderDir = 'ASC';
                    break;
                case 'start_date_desc':
                    $orderBy = 'start_date';
                    $orderDir = 'DESC';
                    break;
                case 'end_date_asc':
                    $orderBy = 'end_date';
                    $orderDir = 'ASC';
                    break;
                case 'end_date_desc':
                    $orderBy = 'end_date';
                    $orderDir = 'DESC';
                    break;
                case 'rating_asc':
                    $orderBy = 'rating';
                    $orderDir = 'ASC';
                    break;
                case 'rating_desc':
                    $orderBy = 'rating';
                    $orderDir = 'DESC';
                    break;
            }
        }

        $sql .= " ORDER BY $orderBy $orderDir";

        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $books = $stmt->fetchAll();

        echo json_encode($books, JSON_UNESCAPED_UNICODE);
    }
}

/**
 * POST: 書籍登録
 */
function handlePost($db) {
    $input = json_decode(file_get_contents('php://input'), true);

    if (!isset($input['title']) || trim($input['title']) === '') {
        http_response_code(400);
        echo json_encode(['error' => 'タイトルは必須です'], JSON_UNESCAPED_UNICODE);
        return;
    }

    $sql = 'INSERT INTO books (title, start_date, end_date, rating, review, cover_image, status)
            VALUES (?, ?, ?, ?, ?, ?, ?)';

    $stmt = $db->prepare($sql);
    $stmt->execute([
        $input['title'],
        $input['start_date'] ?? null,
        $input['end_date'] ?? null,
        $input['rating'] ?? null,
        $input['review'] ?? null,
        $input['cover_image'] ?? null,
        $input['status'] ?? '未読'
    ]);

    $id = $db->lastInsertId();

    // 作成した書籍を返す
    $stmt = $db->prepare('SELECT * FROM books WHERE id = ?');
    $stmt->execute([$id]);
    $book = $stmt->fetch();

    http_response_code(201);
    echo json_encode($book, JSON_UNESCAPED_UNICODE);
}

/**
 * PUT: 書籍更新
 */
function handlePut($db) {
    if (!isset($_GET['id'])) {
        http_response_code(400);
        echo json_encode(['error' => 'IDが指定されていません'], JSON_UNESCAPED_UNICODE);
        return;
    }

    $input = json_decode(file_get_contents('php://input'), true);

    if (!isset($input['title']) || trim($input['title']) === '') {
        http_response_code(400);
        echo json_encode(['error' => 'タイトルは必須です'], JSON_UNESCAPED_UNICODE);
        return;
    }

    $sql = 'UPDATE books
            SET title = ?, start_date = ?, end_date = ?, rating = ?,
                review = ?, cover_image = ?, status = ?,
                updated_at = datetime(\'now\', \'localtime\')
            WHERE id = ?';

    $stmt = $db->prepare($sql);
    $stmt->execute([
        $input['title'],
        $input['start_date'] ?? null,
        $input['end_date'] ?? null,
        $input['rating'] ?? null,
        $input['review'] ?? null,
        $input['cover_image'] ?? null,
        $input['status'] ?? '未読',
        $_GET['id']
    ]);

    if ($stmt->rowCount() === 0) {
        http_response_code(404);
        echo json_encode(['error' => '書籍が見つかりません'], JSON_UNESCAPED_UNICODE);
        return;
    }

    // 更新した書籍を返す
    $stmt = $db->prepare('SELECT * FROM books WHERE id = ?');
    $stmt->execute([$_GET['id']]);
    $book = $stmt->fetch();

    echo json_encode($book, JSON_UNESCAPED_UNICODE);
}

/**
 * DELETE: 書籍削除
 */
function handleDelete($db) {
    if (!isset($_GET['id'])) {
        http_response_code(400);
        echo json_encode(['error' => 'IDが指定されていません'], JSON_UNESCAPED_UNICODE);
        return;
    }

    // 削除前に画像パスを取得
    $stmt = $db->prepare('SELECT cover_image FROM books WHERE id = ?');
    $stmt->execute([$_GET['id']]);
    $book = $stmt->fetch();

    if (!$book) {
        http_response_code(404);
        echo json_encode(['error' => '書籍が見つかりません'], JSON_UNESCAPED_UNICODE);
        return;
    }

    // 書籍を削除
    $stmt = $db->prepare('DELETE FROM books WHERE id = ?');
    $stmt->execute([$_GET['id']]);

    // 画像ファイルが存在する場合は削除
    if ($book['cover_image']) {
        $imagePath = dirname(__DIR__) . '/' . $book['cover_image'];
        if (file_exists($imagePath)) {
            unlink($imagePath);
        }
    }

    echo json_encode(['message' => '書籍を削除しました'], JSON_UNESCAPED_UNICODE);
}
