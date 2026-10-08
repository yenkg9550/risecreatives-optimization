# RiseCreatives Optimization

展躍網路客製化 WordPress 優化外掛。把常用的效能優化、上傳限制、前端框架載入等設定整合在同一個外掛的後台選單「**展躍系統**」中，不必每個網站各裝一堆小外掛。

- 目前版本：**v1.3.2**

---

## 功能

後台選單「展躍系統」下依序為：

| 選單 | 說明 |
|---|---|
| 框架管理 | 依頁面從 CDN 載入 Slick Carousel、AOS、GSAP、Swiper，只在指定頁面載入，避免全站拖慢速度 |
| 上傳限制 | 限制媒體庫上傳的檔案大小與類型；可選擇上傳時自動轉成 WebP、依檔名自動產生圖片 Alt 文字 |
| 一般設定 | 登入頁 Logo、`[risecreatives_copyright]` 版權短代碼 |
| 效能設定 | Emoji、預設圖片尺寸、修訂版本數量、XML-RPC、REST API、Heartbeat、oEmbed、前台 Dashicons／jQuery Migrate／區塊樣式、版本查詢參數等優化開關 |
| 編輯器設定 | 停用 Gutenberg，改用傳統編輯器與傳統小工具 |
| 備份管理 | 自動／手動備份資料庫與檔案，可存在本機、GitHub（私有儲存庫）或 Google Drive，支援下載、刪除與一鍵還原 |
| 版本資訊 | 顯示目前／最新版本、檢查與執行更新、設定更新來源、系統資訊 |

另外，外掛會輸出一組安全標頭（HSTS、CSP、Referrer-Policy、Permissions-Policy 等）。

---

## 安裝

1. 到本儲存庫的 [Releases](../../releases) 下載最新版的 `risecreatives-optimization.zip`。
2. WordPress 後台 →「外掛」→「安裝外掛」→「上傳外掛」，選擇 ZIP 檔安裝並啟用。
3. 啟用後，左側選單會出現「展躍系統」。

> 請使用 Release 附加的 `risecreatives-optimization.zip`，不要用 GitHub 頁面上的「Download ZIP」（資料夾名稱會多出分支名稱，需要改名後才能正確安裝）。

建議環境：程式碼使用 PHP 7 語法與 WordPress 5.3 以上才有的函式，建議使用 WordPress 5.8 以上（`Update URI` 檔頭自 5.8 起生效）。

---

## 更新

外掛接入 WordPress 內建的外掛更新機制，更新來源為本儲存庫的 **GitHub Releases**。

### 設定更新來源

到「展躍系統 → 版本資訊」：

1. **GitHub 儲存庫**：填入 `yenkg9550/risecreatives-optimization`（也可貼完整網址）。
2. **存取 Token**：公開儲存庫不需要；私有儲存庫請使用僅具備唯讀權限的 Fine-grained Token。
3. 儲存後按「立即檢查更新」。

也可以改在 `wp-config.php` 以常數設定（優先於後台設定，後台欄位會鎖定；Token 不存入資料庫）：

```php
define('RISECREATIVES_GITHUB_REPO',  'yenkg9550/risecreatives-optimization');
define('RISECREATIVES_GITHUB_TOKEN', 'github_pat_xxx'); // 私有儲存庫才需要
```

### 更新行為

- 每 12 小時自動檢查一次，也可在版本資訊頁手動檢查。
- 有新版時，「外掛」列表與「儀表板 → 更新」會出現更新提示，可一鍵更新，也支援 WordPress 的外掛自動更新開關。
- 若在「效能設定」勾選了「停用自動更新」，外掛不會自動更新，需到版本資訊頁手動按「立即更新」。
- 更新前建議先備份網站；更新後請清除快取並確認網站運作正常。
# risecreatives-optimization
