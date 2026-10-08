<?php
// includes/class-backup-remote.php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * 備份的遠端目的地：GitHub（私有儲存庫的草稿 Release 附件）
 *
 * - 備份檔含有資料庫（使用者密碼雜湊、設定等），因此只允許私有儲存庫；公開儲存庫會直接拒絕。
 * - 使用「草稿 Release」存放：不會建立 tag、不會出現在公開頁面，單檔上限 2 GB。
 * - Token 需具備該儲存庫的 Contents 讀寫權限（Fine-grained token：Contents → Read and write）。
 */
class RiseCreatives_Backup_GitHub {
    const MAX_BYTES = 2147483648; // GitHub Release 附件上限 2 GiB

    public static function normalize_repo($input) {
        $repo = trim((string) $input);
        $repo = preg_replace('#^https?://github\.com/#i', '', $repo);
        $repo = preg_replace('#\.git$#i', '', $repo);
        $repo = trim($repo, '/');
        $repo = preg_replace('#/+$#', '', $repo);
        if (!preg_match('#^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+$#', $repo)) {
            return '';
        }
        return $repo;
    }

    private static function api($method, $path, $token, $body = null) {
        $args = array(
            'method'      => $method,
            'timeout'     => 60,
            'redirection' => 3,
            'headers'     => array(
                'Accept'               => 'application/vnd.github+json',
                'Authorization'        => 'Bearer ' . $token,
                'User-Agent'           => 'RiseCreatives-Optimization-Backup',
                'X-GitHub-Api-Version' => '2022-11-28',
            ),
        );
        if ($body !== null) {
            $args['headers']['Content-Type'] = 'application/json';
            $args['body'] = wp_json_encode($body);
        }

        $res = wp_remote_request('https://api.github.com' . $path, $args);
        if (is_wp_error($res)) {
            throw new Exception('無法連線到 GitHub：' . $res->get_error_message());
        }

        return array(
            'code' => (int) wp_remote_retrieve_response_code($res),
            'data' => json_decode(wp_remote_retrieve_body($res), true),
        );
    }

    private static function error_text($res, $fallback) {
        if (is_array($res['data']) && !empty($res['data']['message'])) {
            return $fallback . '（' . $res['data']['message'] . '，HTTP ' . $res['code'] . '）';
        }
        return $fallback . '（HTTP ' . $res['code'] . '）';
    }

    /**
     * 檢查連線、權限，並確認儲存庫為私有
     */
    public static function test($s) {
        $repo  = self::normalize_repo($s['github_repo']);
        $token = (string) $s['github_token'];

        if ($repo === '' || $token === '') {
            throw new Exception('請先填寫 GitHub 儲存庫與 Token。');
        }

        $res = self::api('GET', '/repos/' . $repo, $token);
        if ($res['code'] === 404) {
            throw new Exception('找不到儲存庫 ' . $repo . '，或 Token 沒有存取該儲存庫的權限。');
        }
        if ($res['code'] === 401) {
            throw new Exception('Token 無效或已過期。');
        }
        if ($res['code'] !== 200 || !is_array($res['data'])) {
            throw new Exception(self::error_text($res, '無法讀取儲存庫'));
        }
        if (empty($res['data']['private'])) {
            throw new Exception('這是公開儲存庫。備份含有資料庫，為避免外洩，只允許上傳到「私有」儲存庫。');
        }
        if (isset($res['data']['permissions']) && empty($res['data']['permissions']['push'])) {
            throw new Exception('這個 Token 對儲存庫沒有寫入權限（需要 Contents：Read and write）。');
        }

        return '連線成功：' . $repo . '（私有儲存庫）';
    }

    public static function upload($path, $name, $s) {
        if (!function_exists('curl_init')) {
            throw new Exception('此主機沒有安裝 PHP cURL 擴充，無法上傳到 GitHub。');
        }

        $size = (int) filesize($path);
        if ($size > self::MAX_BYTES) {
            throw new Exception('備份檔超過 GitHub 單檔 2 GB 的上限（' . size_format($size) . '），請縮小備份範圍。');
        }

        self::test($s);
        $repo  = self::normalize_repo($s['github_repo']);
        $token = (string) $s['github_token'];

        $tag = 'backup-' . preg_replace('/\.zip$/', '', $name);
        $rel = self::api('POST', '/repos/' . $repo . '/releases', $token, array(
            'tag_name'   => $tag,
            'name'       => $name,
            'body'       => 'RiseCreatives Optimization 自動備份（' . home_url() . '）',
            'draft'      => true,
            'prerelease' => true,
        ));
        if ($rel['code'] !== 201 || empty($rel['data']['id'])) {
            throw new Exception(self::error_text($rel, '建立 Release 失敗'));
        }
        $release_id = (int) $rel['data']['id'];

        try {
            $url = 'https://uploads.github.com/repos/' . $repo . '/releases/' . $release_id . '/assets?name=' . rawurlencode($name);
            self::curl_upload($url, $path, $size, $token);
        } catch (Throwable $e) {
            try {
                self::api('DELETE', '/repos/' . $repo . '/releases/' . $release_id, $token);
            } catch (Throwable $ignore) {
            }
            throw $e;
        }

        return array(
            'id'  => $release_id,
            'url' => isset($rel['data']['html_url']) ? $rel['data']['html_url'] : '',
        );
    }

    private static function curl_upload($url, $path, $size, $token) {
        $fh = fopen($path, 'rb');
        if (!$fh) {
            throw new Exception('無法讀取備份檔。');
        }

        $ch = curl_init($url);
        $opts = array(
            CURLOPT_CUSTOMREQUEST  => 'POST',
            CURLOPT_UPLOAD         => true,
            CURLOPT_INFILE         => $fh,
            CURLOPT_INFILESIZE     => $size,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 3600,
            CURLOPT_CONNECTTIMEOUT => 30,
            CURLOPT_HTTPHEADER     => array(
                'Accept: application/vnd.github+json',
                'Authorization: Bearer ' . $token,
                'Content-Type: application/zip',
                'User-Agent: RiseCreatives-Optimization-Backup',
                'X-GitHub-Api-Version: 2022-11-28',
            ),
        );
        $ca = ABSPATH . WPINC . '/certificates/ca-bundle.crt';
        if (file_exists($ca)) {
            $opts[CURLOPT_CAINFO] = $ca;
        }
        curl_setopt_array($ch, $opts);

        $body  = curl_exec($ch);
        $code  = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        fclose($fh);

        if ($body === false) {
            throw new Exception('上傳到 GitHub 失敗：' . $error);
        }
        if ($code !== 201) {
            $data = json_decode($body, true);
            $msg  = is_array($data) && !empty($data['message']) ? $data['message'] : '';
            throw new Exception('上傳到 GitHub 失敗（HTTP ' . $code . ($msg ? '，' . $msg : '') . '）');
        }
    }

    public static function delete($info, $s) {
        $repo  = self::normalize_repo($s['github_repo']);
        $token = (string) $s['github_token'];
        if ($repo === '' || $token === '' || empty($info['id'])) {
            throw new Exception('缺少 GitHub 設定，無法刪除遠端副本。');
        }

        $res = self::api('DELETE', '/repos/' . $repo . '/releases/' . (int) $info['id'], $token);
        if ($res['code'] !== 204 && $res['code'] !== 404) {
            throw new Exception(self::error_text($res, '刪除 GitHub 上的備份失敗'));
        }
    }
}

/**
 * 備份的遠端目的地：Google Drive
 *
 * 使用 OAuth（範圍 drive.file：只能存取本外掛自己建立的檔案與資料夾）。
 * 設定方式：在 Google Cloud Console 建立「網頁應用程式」OAuth 用戶端，
 * 把後台頁面顯示的「已授權的重新導向 URI」加進去，再把 Client ID／Secret 填入後台並按「授權」。
 * 注意：OAuth 同意畫面若維持「測試中」，refresh token 7 天後就會失效，請將發布狀態改為「正式版（In production）」。
 */
class RiseCreatives_Backup_GDrive {
    const CHUNK = 8388608; // 8 MiB（必須是 256 KiB 的倍數）

    private static $access_cache = array();

    public static function auth_url($client_id, $redirect_uri, $state) {
        return 'https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query(array(
            'client_id'     => $client_id,
            'redirect_uri'  => $redirect_uri,
            'response_type' => 'code',
            'scope'         => 'https://www.googleapis.com/auth/drive.file',
            'access_type'   => 'offline',
            'prompt'        => 'consent',
            'state'         => $state,
        ), '', '&', PHP_QUERY_RFC3986);
    }

    private static function google_error($res, $fallback) {
        $data = json_decode(wp_remote_retrieve_body($res), true);
        $code = (int) wp_remote_retrieve_response_code($res);
        $msg  = '';
        if (is_array($data)) {
            if (!empty($data['error_description'])) {
                $msg = $data['error_description'];
            } elseif (!empty($data['error']['message'])) {
                $msg = $data['error']['message'];
            } elseif (!empty($data['error']) && is_string($data['error'])) {
                $msg = $data['error'];
            }
        }
        return $fallback . '（HTTP ' . $code . ($msg ? '，' . $msg : '') . '）';
    }

    private static function token_request($params) {
        $res = wp_remote_post('https://oauth2.googleapis.com/token', array(
            'timeout' => 30,
            'body'    => $params,
        ));
        if (is_wp_error($res)) {
            throw new Exception('無法連線到 Google：' . $res->get_error_message());
        }
        $data = json_decode(wp_remote_retrieve_body($res), true);
        if ((int) wp_remote_retrieve_response_code($res) !== 200 || !is_array($data)) {
            throw new Exception(self::google_error($res, 'Google 授權失敗'));
        }
        return $data;
    }

    public static function exchange_code($code, $client_id, $client_secret, $redirect_uri) {
        return self::token_request(array(
            'code'          => $code,
            'client_id'     => $client_id,
            'client_secret' => $client_secret,
            'redirect_uri'  => $redirect_uri,
            'grant_type'    => 'authorization_code',
        ));
    }

    private static function access_token($s) {
        if ($s['gdrive_refresh_token'] === '' || $s['gdrive_client_id'] === '' || $s['gdrive_client_secret'] === '') {
            throw new Exception('尚未完成 Google Drive 授權。');
        }
        $key = md5($s['gdrive_refresh_token']);
        if (isset(self::$access_cache[$key]) && self::$access_cache[$key]['expires'] > time() + 60) {
            return self::$access_cache[$key]['token'];
        }

        try {
            $data = self::token_request(array(
                'client_id'     => $s['gdrive_client_id'],
                'client_secret' => $s['gdrive_client_secret'],
                'refresh_token' => $s['gdrive_refresh_token'],
                'grant_type'    => 'refresh_token',
            ));
        } catch (Exception $e) {
            throw new Exception($e->getMessage() . ' 授權可能已失效，請在備份頁面重新按「授權 Google Drive」。');
        }

        self::$access_cache[$key] = array(
            'token'   => $data['access_token'],
            'expires' => time() + (isset($data['expires_in']) ? (int) $data['expires_in'] : 3000),
        );
        return $data['access_token'];
    }

    private static function request($method, $url, $token, $headers = array(), $body = null, $timeout = 60) {
        $args = array(
            'method'      => $method,
            'timeout'     => $timeout,
            'redirection' => 0,
            'headers'     => array_merge(array('Authorization' => 'Bearer ' . $token), $headers),
        );
        if ($body !== null) {
            $args['body'] = $body;
        }
        $res = wp_remote_request($url, $args);
        if (is_wp_error($res)) {
            throw new Exception('無法連線到 Google Drive：' . $res->get_error_message());
        }
        return $res;
    }

    public static function test($s) {
        $token = self::access_token($s);
        $res   = self::request('GET', 'https://www.googleapis.com/drive/v3/about?fields=user(emailAddress)', $token);
        if ((int) wp_remote_retrieve_response_code($res) !== 200) {
            throw new Exception(self::google_error($res, '讀取 Google Drive 失敗'));
        }
        $data  = json_decode(wp_remote_retrieve_body($res), true);
        $email = isset($data['user']['emailAddress']) ? $data['user']['emailAddress'] : '';
        return '連線成功' . ($email ? '：' . $email : '');
    }

    private static function ensure_folder($s, $token) {
        $id = $s['gdrive_folder_id'];
        if ($id !== '') {
            $res = self::request('GET', 'https://www.googleapis.com/drive/v3/files/' . rawurlencode($id) . '?fields=id,trashed', $token);
            if ((int) wp_remote_retrieve_response_code($res) === 200) {
                $data = json_decode(wp_remote_retrieve_body($res), true);
                if (is_array($data) && empty($data['trashed'])) {
                    return $id;
                }
            }
        }

        $host = (string) wp_parse_url(home_url(), PHP_URL_HOST);
        $name = 'RiseCreatives Backups - ' . $host;
        $q    = "name = '" . str_replace("'", "\\'", $name) . "' and mimeType = 'application/vnd.google-apps.folder' and trashed = false";

        $res = self::request('GET', 'https://www.googleapis.com/drive/v3/files?fields=files(id)&q=' . rawurlencode($q), $token);
        if ((int) wp_remote_retrieve_response_code($res) === 200) {
            $data = json_decode(wp_remote_retrieve_body($res), true);
            if (!empty($data['files'][0]['id'])) {
                return $data['files'][0]['id'];
            }
        }

        $res = self::request('POST', 'https://www.googleapis.com/drive/v3/files?fields=id', $token,
            array('Content-Type' => 'application/json; charset=UTF-8'),
            wp_json_encode(array('name' => $name, 'mimeType' => 'application/vnd.google-apps.folder'))
        );
        $data = json_decode(wp_remote_retrieve_body($res), true);
        if ((int) wp_remote_retrieve_response_code($res) !== 200 || empty($data['id'])) {
            throw new Exception(self::google_error($res, '無法在 Google Drive 建立備份資料夾'));
        }
        return $data['id'];
    }

    public static function upload($path, $name, $s) {
        $size = (int) filesize($path);
        if ($size <= 0) {
            throw new Exception('備份檔是空的。');
        }

        $token  = self::access_token($s);
        $folder = self::ensure_folder($s, $token);

        // 取得續傳網址
        $res = self::request('POST', 'https://www.googleapis.com/upload/drive/v3/files?uploadType=resumable&fields=id', $token, array(
            'Content-Type'            => 'application/json; charset=UTF-8',
            'X-Upload-Content-Type'   => 'application/zip',
            'X-Upload-Content-Length' => (string) $size,
        ), wp_json_encode(array('name' => $name, 'parents' => array($folder))));

        if ((int) wp_remote_retrieve_response_code($res) !== 200) {
            throw new Exception(self::google_error($res, '無法開始上傳到 Google Drive'));
        }
        $session = wp_remote_retrieve_header($res, 'location');
        if (!$session) {
            throw new Exception('Google Drive 沒有回傳上傳網址。');
        }

        $fh = fopen($path, 'rb');
        if (!$fh) {
            throw new Exception('無法讀取備份檔。');
        }

        $offset  = 0;
        $retries = 0;
        $file    = null;

        try {
            while ($offset < $size) {
                fseek($fh, $offset);
                $chunk = fread($fh, self::CHUNK);
                $end   = $offset + strlen($chunk) - 1;

                try {
                    $res  = self::request('PUT', $session, $token, array(
                        'Content-Range' => 'bytes ' . $offset . '-' . $end . '/' . $size,
                        'Content-Type'  => 'application/zip',
                    ), $chunk, 300);
                    $code = (int) wp_remote_retrieve_response_code($res);
                } catch (Exception $e) {
                    $code = 0;
                    $res  = null;
                }

                if ($code === 200 || $code === 201) {
                    $file = json_decode(wp_remote_retrieve_body($res), true);
                    $offset = $size;
                    break;
                }
                if ($code === 308) {
                    $range = wp_remote_retrieve_header($res, 'range');
                    $offset = $range && preg_match('/bytes=0-(\d+)/', $range, $m) ? ((int) $m[1] + 1) : $offset;
                    $retries = 0;
                    continue;
                }

                // 失敗：查詢伺服器已收到多少再續傳
                if (++$retries > 3) {
                    throw new Exception($res ? self::google_error($res, '上傳到 Google Drive 失敗') : '上傳到 Google Drive 失敗（連線中斷）');
                }
                sleep(2 * $retries);
                try {
                    $q = self::request('PUT', $session, $token, array('Content-Range' => 'bytes */' . $size, 'Content-Length' => '0'));
                    $qc = (int) wp_remote_retrieve_response_code($q);
                    if ($qc === 200 || $qc === 201) {
                        $file = json_decode(wp_remote_retrieve_body($q), true);
                        $offset = $size;
                        break;
                    }
                    if ($qc === 308) {
                        $range  = wp_remote_retrieve_header($q, 'range');
                        $offset = $range && preg_match('/bytes=0-(\d+)/', $range, $m) ? ((int) $m[1] + 1) : 0;
                    }
                } catch (Exception $e) {
                    // 下一輪再試
                }
            }
        } finally {
            fclose($fh);
        }

        if (empty($file['id'])) {
            throw new Exception('Google Drive 上傳未完成。');
        }

        return array(
            'id'        => $file['id'],
            'url'       => 'https://drive.google.com/file/d/' . $file['id'] . '/view',
            'folder_id' => $folder,
        );
    }

    public static function delete($info, $s) {
        if (empty($info['id'])) {
            throw new Exception('缺少 Google Drive 檔案代碼。');
        }
        $token = self::access_token($s);
        $res   = self::request('DELETE', 'https://www.googleapis.com/drive/v3/files/' . rawurlencode($info['id']), $token);
        $code  = (int) wp_remote_retrieve_response_code($res);
        if ($code !== 204 && $code !== 404 && $code !== 200) {
            throw new Exception(self::google_error($res, '刪除 Google Drive 上的備份失敗'));
        }
    }
}
