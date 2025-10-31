/**
 * 共通JavaScript機能
 */

// API ベース URL
const API_BASE_URL = 'api';

/**
 * 日付をフォーマット
 * @param {string} dateStr - 日付文字列
 * @returns {string} フォーマットされた日付
 */
function formatDate(dateStr) {
    if (!dateStr) return '未設定';
    const date = new Date(dateStr);
    return date.toLocaleDateString('ja-JP');
}

/**
 * 日時をフォーマット
 * @param {string} dateTimeStr - 日時文字列
 * @returns {string} フォーマットされた日時
 */
function formatDateTime(dateTimeStr) {
    if (!dateTimeStr) return '未設定';
    const date = new Date(dateTimeStr);
    return date.toLocaleString('ja-JP');
}

/**
 * 評価を星で表示
 * @param {number} rating - 評価値（1-5）
 * @returns {string} 星の文字列
 */
function formatRating(rating) {
    if (!rating) return '未評価';
    return '★'.repeat(rating) + '☆'.repeat(5 - rating);
}

/**
 * エラーハンドリング
 * @param {Error} error - エラーオブジェクト
 */
function handleError(error) {
    console.error('Error:', error);
    alert('エラーが発生しました: ' + error.message);
}

/**
 * ローディング表示
 * @param {string} elementId - 要素ID
 * @param {boolean} show - 表示/非表示
 */
function showLoading(elementId, show = true) {
    const element = document.getElementById(elementId);
    if (element) {
        if (show) {
            element.innerHTML = '<div class="loading">読み込み中...</div>';
        } else {
            element.innerHTML = '';
        }
    }
}

/**
 * メッセージトースト表示
 * @param {string} message - メッセージ
 * @param {string} type - タイプ（success/error）
 */
function showToast(message, type = 'success') {
    const toast = document.createElement('div');
    toast.className = type === 'error' ? 'error-message' : 'success-message';
    toast.textContent = message;
    toast.style.position = 'fixed';
    toast.style.top = '20px';
    toast.style.left = '50%';
    toast.style.transform = 'translateX(-50%)';
    toast.style.zIndex = '10000';

    document.body.appendChild(toast);

    setTimeout(() => {
        toast.remove();
    }, 3000);
}

// PWA インストールプロンプト
let deferredPrompt;

window.addEventListener('beforeinstallprompt', (e) => {
    // デフォルトのインストールプロンプトを抑制
    e.preventDefault();
    deferredPrompt = e;

    // インストールボタンを表示（オプション）
    const installButton = document.getElementById('install-button');
    if (installButton) {
        installButton.style.display = 'block';

        installButton.addEventListener('click', async () => {
            if (deferredPrompt) {
                deferredPrompt.prompt();
                const { outcome } = await deferredPrompt.userChoice;
                console.log(`User response to the install prompt: ${outcome}`);
                deferredPrompt = null;
                installButton.style.display = 'none';
            }
        });
    }
});

// PWA インストール完了
window.addEventListener('appinstalled', () => {
    console.log('PWA installed');
    deferredPrompt = null;
});

// オフライン/オンライン検知
window.addEventListener('online', () => {
    showToast('オンラインに復帰しました', 'success');
});

window.addEventListener('offline', () => {
    showToast('オフラインモードです', 'error');
});
