const CACHE_NAME = 'mybook-v1';
const URLS_TO_CACHE = [
    '/',
    '/index.html',
    '/detail.html',
    '/register.html',
    '/css/style.css',
    '/manifest.json'
];

// Service Worker インストール時
self.addEventListener('install', (event) => {
    console.log('Service Worker: Installing...');
    event.waitUntil(
        caches.open(CACHE_NAME)
            .then((cache) => {
                console.log('Service Worker: Caching files');
                return cache.addAll(URLS_TO_CACHE);
            })
            .catch((err) => {
                console.log('Service Worker: Cache failed', err);
            })
    );
});

// Service Worker アクティベート時
self.addEventListener('activate', (event) => {
    console.log('Service Worker: Activating...');
    event.waitUntil(
        caches.keys().then((cacheNames) => {
            return Promise.all(
                cacheNames.map((cache) => {
                    if (cache !== CACHE_NAME) {
                        console.log('Service Worker: Clearing old cache');
                        return caches.delete(cache);
                    }
                })
            );
        })
    );
});

// フェッチイベント（ネットワークファースト戦略）
self.addEventListener('fetch', (event) => {
    // APIリクエストの場合はネットワークファースト
    if (event.request.url.includes('/api/')) {
        event.respondWith(
            fetch(event.request)
                .catch(() => {
                    return new Response(
                        JSON.stringify({ error: 'オフラインです' }),
                        {
                            headers: { 'Content-Type': 'application/json' }
                        }
                    );
                })
        );
        return;
    }

    // 画像などの静的ファイルはキャッシュファースト
    event.respondWith(
        caches.match(event.request)
            .then((response) => {
                // キャッシュがあればそれを返す
                if (response) {
                    return response;
                }

                // キャッシュになければネットワークから取得
                return fetch(event.request)
                    .then((response) => {
                        // レスポンスが有効でない場合はそのまま返す
                        if (!response || response.status !== 200 || response.type !== 'basic') {
                            return response;
                        }

                        // レスポンスをクローンしてキャッシュに保存
                        const responseToCache = response.clone();
                        caches.open(CACHE_NAME)
                            .then((cache) => {
                                cache.put(event.request, responseToCache);
                            });

                        return response;
                    })
                    .catch(() => {
                        // ネットワークエラー時はオフラインページを返す
                        return caches.match('/index.html');
                    });
            })
    );
});
