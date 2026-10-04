#!/usr/bin/env bash
# =============================================================================
# release.sh — 一鍵發布外掛新版本
#
# 用法：
#   ./release.sh
#       以「外掛主檔目前的版本」發布：Release 的版本（tag、標題）一定與外掛主檔的
#       Version、RISECREATIVES_OPT_VERSION 完全一致。commit 摘要依本次異動自動產生。
#
#   發布新版本時，請先把外掛主檔的 Version 與 RISECREATIVES_OPT_VERSION 改成新版本號，
#   再執行 ./release.sh；或加上 --bump 讓腳本幫你把主檔版本改成 +0.0.1。
#
#   ./release.sh [版本號] [-m "更新摘要"] [--bump|--minor|--major] [-y] [-e] [--dry-run]
#
# 範例：
#   ./release.sh                       以主檔目前的版本發布（版本一致、摘要自動產生）
#   ./release.sh --bump                主檔版本 +0.0.1 後發布（1.3.1 → 1.3.2）
#   ./release.sh --minor               主檔版本次版本 +1 後發布（1.3.5 → 1.4.0）
#   ./release.sh --major               主檔版本主版本 +1 後發布（1.3.5 → 2.0.0）
#   ./release.sh 1.5.0                 把主檔版本改成 1.5.0 後發布
#   ./release.sh -m "修正上傳限制"      自訂更新摘要
#   ./release.sh --dry-run             只檢查與預覽，不做任何變更（建議先跑一次）
#
# 參數：
#   -m, --message   自訂更新摘要（未指定時，會依本次異動的檔案自動產生）
#   --bump          主檔版本 +0.0.1（修訂號）
#   --minor         主檔版本次版本 +1，修訂號歸零
#   --major         主檔版本主版本 +1，次版本與修訂號歸零
#   -y, --yes       略過確認提示
#   -e, --edit      產生更新說明後開啟編輯器讓你修改（使用 ${EDITOR}，預設 vi）
#   --dry-run       只做檢查並預覽，不修改任何檔案
#
# 流程：
#   1. 檢查環境（git / zip / gh、gh 已登入、分支、遠端、tag 是否重複、PHP 語法）
#   2. 讀取外掛主檔的版本（Version 與 RISECREATIVES_OPT_VERSION 必須一致）作為 Release 版本；
#      只有加上 --bump／--minor／--major 或指定版本號時，才會由腳本修改主檔版本
#   3. git add -A
#   4. 更新說明 release-notes/v<版本號>.md：已存在就直接使用（手寫版優先），不存在才自動產生；一併提交並作為 Release 說明
#   5. git commit（訊息開頭加上版本號，摘要自動依異動產生：「v1.3.2: 更新 上傳限制、效能設定（…）」）並建立 tag
#   6. 打包 dist/risecreatives-optimization.zip（只含外掛本體，不含 README、本腳本等）
#   7. 推送到 GitHub（分支與 tag）
#   8. 以 gh 建立 GitHub Release，附上 ZIP 與更新說明
#
# 事前準備（只需一次）：
#   - 安裝 GitHub CLI：brew install gh
#   - 登入：gh auth login
#
# 安全機制：推送前任何步驟失敗，會自動還原本機的 commit、tag 與版本號修改，
#           遠端不受影響。
# =============================================================================

set -Eeuo pipefail

# ----- 設定 -----
PLUGIN_SLUG="risecreatives-optimization"
MAIN_FILE="risecreatives-optimization.php"
BRANCH="main"
NOTES_DIR="release-notes"
DIST_DIR="dist"
ZIP_NAME="${PLUGIN_SLUG}.zip"

cd "$(dirname "${BASH_SOURCE[0]}")"
ROOT="$(pwd)"

# ----- 輸出工具 -----
if [ -t 1 ]; then
    C_RED=$'\033[31m'; C_GREEN=$'\033[32m'; C_YELLOW=$'\033[33m'; C_BLUE=$'\033[34m'; C_OFF=$'\033[0m'
else
    C_RED=""; C_GREEN=""; C_YELLOW=""; C_BLUE=""; C_OFF=""
fi
info() { printf '%s==>%s %s\n' "$C_BLUE" "$C_OFF" "$*"; }
ok()   { printf '%s ✓ %s %s\n' "$C_GREEN" "$C_OFF" "$*"; }
warn() { printf '%s ! %s %s\n' "$C_YELLOW" "$C_OFF" "$*" >&2; }
die()  { printf '%s ✗ %s %s\n' "$C_RED" "$C_OFF" "$*" >&2; exit 1; }

usage() {
    awk 'NR==1{next} /^# =+$/ {c++; next} c==1 {print} c>=2 {exit}' "${BASH_SOURCE[0]}" | sed 's/^# \{0,1\}//'
}

# ----- 狀態旗標（用於失敗時還原） -----
BUMPED=0
COMMITTED=0
TAGGED=0
NOTES_CREATED=0
PUSHED=0
SUCCESS=0
START_HEAD=""
CURRENT=""
STAGE_DIR=""
TAG=""
NOTES_FILE=""

# ----- 版本號處理 -----
get_current_version() {
    grep -E '^[[:space:]]*\*[[:space:]]*Version:' "$MAIN_FILE" | sed -n 1p \
        | sed -E 's/.*Version:[[:space:]]*//' | tr -d '[:space:]'
}

get_constant_version() {
    grep -E "RISECREATIVES_OPT_VERSION" "$MAIN_FILE" | grep -E "define" | sed -n 1p \
        | sed -E "s/.*,[[:space:]]*['\"]([^'\"]*)['\"].*/\1/"
}

# $1 > $2 ?
version_gt() {
    local IFS=.
    local a=($1) b=($2) i
    for i in 0 1 2; do
        if [ $((10#${a[i]:-0})) -gt $((10#${b[i]:-0})) ]; then return 0; fi
        if [ $((10#${a[i]:-0})) -lt $((10#${b[i]:-0})) ]; then return 1; fi
    done
    return 1
}

bump_version() {
    VERSION_NEW="$1" perl -pi -e '
        s/^(\s*\*\s*Version:\s*)\S+/${1}$ENV{VERSION_NEW}/;
        s/(define\(\s*[\x27"]RISECREATIVES_OPT_VERSION[\x27"]\s*,\s*[\x27"])[^\x27"]*([\x27"])/${1}$ENV{VERSION_NEW}${2}/;
    ' "$MAIN_FILE"
}

# 依目前版本計算下一個版本（$2：patch / minor / major）
next_version() {
    local IFS=.
    local p=($1)
    local ma=$((10#${p[0]:-0})) mi=$((10#${p[1]:-0})) pa=$((10#${p[2]:-0}))
    case "$2" in
        major) echo "$((ma + 1)).0.0" ;;
        minor) echo "${ma}.$((mi + 1)).0" ;;
        *)     echo "${ma}.${mi}.$((pa + 1))" ;;
    esac
}

# 已發布的最新版本（依 git tag；沒有則為空）
latest_tag_version() {
    git tag --list 'v[0-9]*' | sed 's/^v//' | grep -E '^[0-9]+\.[0-9]+\.[0-9]+$' \
        | sort -t. -k1,1n -k2,2n -k3,3n | tail -n 1 || true
}

# 檔案 → 功能模組名稱（用於自動產生 commit 摘要）
module_of() {
    case "$1" in
        risecreatives-optimization.php) echo "外掛主檔" ;;
        uninstall.php) echo "解除安裝" ;;
        includes/class-upload-restrictions.php|templates/admin-upload-restrictions*.php) echo "上傳限制" ;;
        includes/class-performance-settings.php|templates/admin-performance.php) echo "效能設定" ;;
        includes/class-security-headers.php) echo "安全標頭" ;;
        includes/class-general-settings.php|templates/admin-general.php) echo "一般設定" ;;
        includes/class-scripts-manager.php|templates/admin-scripts.php|assets/js/scripts-init.js) echo "框架管理" ;;
        includes/class-editor-settings.php|templates/admin-editor.php) echo "編輯器設定" ;;
        includes/class-updater.php|templates/admin-version.php) echo "版本資訊與更新檢查" ;;
        assets/*) echo "後台資源" ;;
        languages/*) echo "語系檔" ;;
        README.md|.gitignore|.gitattributes|release.sh) echo "專案文件與發布設定" ;;
        *) echo "其他" ;;
    esac
}

# 依目前未提交的異動，自動產生一行摘要
auto_summary() {
    local mods="" mod_count=0 n_mod=0 n_add=0 n_del=0 add_lines=0 del_lines=0
    local line code path m a d p

    while IFS= read -r line; do
        [ -n "$line" ] || continue
        code="${line:0:2}"
        path="${line:3}"
        case "$path" in *" -> "*) path="${path##* -> }" ;; esac
        path="${path#\"}"; path="${path%\"}"
        case "$path" in "$DIST_DIR"/*|"$NOTES_DIR"/*) continue ;; esac

        case "$code" in
            "??"|A?|" A") n_add=$((n_add + 1)) ;;
            *D*)          n_del=$((n_del + 1)) ;;
            *)            n_mod=$((n_mod + 1)) ;;
        esac

        m="$(module_of "$path")"
        if ! printf '%s\n' "$mods" | grep -q -x -F "$m"; then
            mods="${mods}${m}"$'\n'
            mod_count=$((mod_count + 1))
        fi

        # 新增（未追蹤）的檔案：以檔案行數計入新增行數
        if [ "$code" = "??" ] && [ -f "$path" ]; then
            add_lines=$((add_lines + $(wc -l < "$path" | tr -d ' ')))
        fi
    done < <(git status --porcelain -uall)

    # 已追蹤檔案的新增／刪除行數
    while IFS=$'\t' read -r a d p; do
        [ -n "${p:-}" ] || continue
        case "$p" in "$DIST_DIR"/*|"$NOTES_DIR"/*) continue ;; esac
        case "$a" in ''|*[!0-9]*) continue ;; esac
        case "$d" in ''|*[!0-9]*) continue ;; esac
        add_lines=$((add_lines + a))
        del_lines=$((del_lines + d))
    done < <(git diff HEAD --numstat)

    if [ $((n_add + n_mod + n_del)) -eq 0 ]; then
        echo "版本更新"
        return
    fi

    # 具體的功能模組排前面；「其他」與「專案文件」類排最後
    local ordered="" generic=""
    while IFS= read -r m; do
        [ -n "$m" ] || continue
        case "$m" in
            "其他"|"專案文件與發布設定") generic="${generic}${m}"$'\n' ;;
            *) ordered="${ordered}${m}"$'\n' ;;
        esac
    done <<< "$mods"
    ordered="${ordered}${generic}"

    local names="" i=0
    while IFS= read -r m; do
        [ -n "$m" ] || continue
        i=$((i + 1))
        if [ "$i" -le 4 ]; then
            names="${names:+${names}、}$m"
        fi
    done <<< "$ordered"
    if [ "$mod_count" -gt 4 ]; then
        names="${names}等 ${mod_count} 個項目"
    fi

    local parts=""
    if [ "$n_mod" -gt 0 ]; then parts="修改 ${n_mod}"; fi
    if [ "$n_add" -gt 0 ]; then parts="${parts:+${parts}、}新增 ${n_add}"; fi
    if [ "$n_del" -gt 0 ]; then parts="${parts:+${parts}、}刪除 ${n_del}"; fi

    echo "更新 ${names}（${parts} 個檔案；+${add_lines}/-${del_lines} 行）"
}

# ----- 結束時的清理與還原 -----
cleanup() {
    local code=$?
    if [ -n "$STAGE_DIR" ] && [ -d "$STAGE_DIR" ]; then
        rm -rf "$STAGE_DIR"
    fi

    if [ "$SUCCESS" -ne 1 ] && [ "$PUSHED" -ne 1 ] && { [ "$BUMPED" -eq 1 ] || [ "$COMMITTED" -eq 1 ] || [ "$TAGGED" -eq 1 ]; }; then
        warn "發布中斷，正在還原本機的變更（遠端不受影響）..."
        if [ "$TAGGED" -eq 1 ]; then
            git tag -d "$TAG" >/dev/null 2>&1 || true
        fi
        if [ "$COMMITTED" -eq 1 ] && [ -n "$START_HEAD" ]; then
            git reset --soft "$START_HEAD" >/dev/null 2>&1 || true
        fi
        git reset -q >/dev/null 2>&1 || true
        if [ "$NOTES_CREATED" -eq 1 ]; then
            rm -f "$NOTES_FILE"
        fi
        if [ "$BUMPED" -eq 1 ] && [ -n "$CURRENT" ]; then
            bump_version "$CURRENT" || true
        fi
        warn "已還原：已移除本次建立的 commit／tag／更新說明，版本號改回 v${CURRENT}。你原本的修改都還在。"
    fi

    if [ "$PUSHED" -eq 1 ] && [ "$SUCCESS" -ne 1 ]; then
        warn "程式碼與 tag 已推送到 GitHub，但建立 Release 失敗。可在修正問題後手動執行："
        warn "  gh release create \"$TAG\" \"$DIST_DIR/$ZIP_NAME\" --title \"$TAG\" --notes-file \"$NOTES_FILE\" --verify-tag --latest"
    fi

    exit "$code"
}
trap cleanup EXIT

# ----- 解析參數 -----
VERSION=""
SUMMARY=""
ASSUME_YES=0
EDIT_NOTES=0
DRY_RUN=0
BUMP_KIND=""

while [ $# -gt 0 ]; do
    case "$1" in
        -m|--message)
            [ $# -ge 2 ] || die "-m 後面需要填寫更新摘要"
            SUMMARY="$2"; shift 2 ;;
        -y|--yes)   ASSUME_YES=1; shift ;;
        -e|--edit)  EDIT_NOTES=1; shift ;;
        --dry-run)  DRY_RUN=1; shift ;;
        --bump)     BUMP_KIND="patch"; shift ;;
        --minor)    BUMP_KIND="minor"; shift ;;
        --major)    BUMP_KIND="major"; shift ;;
        -h|--help)  usage; trap - EXIT; exit 0 ;;
        -*)         die "未知的參數：$1（使用 --help 查看用法）" ;;
        *)
            [ -z "$VERSION" ] || die "只能指定一個版本號"
            VERSION="$1"; shift ;;
    esac
done

# ----- 檢查環境 -----
info "檢查環境..."

for cmd in git zip gh perl; do
    command -v "$cmd" >/dev/null 2>&1 || die "找不到 ${cmd}。請先安裝（GitHub CLI：brew install gh）。"
done

gh auth status >/dev/null 2>&1 || die "GitHub CLI 尚未登入，請先執行：gh auth login"

git rev-parse --is-inside-work-tree >/dev/null 2>&1 || die "這裡不是 Git 儲存庫。"
[ -f "$MAIN_FILE" ] || die "找不到外掛主檔 ${MAIN_FILE}，請在外掛資料夾根目錄執行。"

ORIGIN_URL="$(git remote get-url origin 2>/dev/null || true)"
[ -n "$ORIGIN_URL" ] || die "尚未設定遠端 origin。"

CUR_BRANCH="$(git branch --show-current)"
[ "$CUR_BRANCH" = "$BRANCH" ] || die "目前分支是「${CUR_BRANCH:-（分離狀態）}」，請先切換到 ${BRANCH}。"

git fetch origin "$BRANCH" --quiet 2>/dev/null || warn "無法從遠端取得最新資料，略過落後檢查。"
BEHIND="$(git rev-list --count "HEAD..origin/$BRANCH" 2>/dev/null || echo 0)"
[ "$BEHIND" -eq 0 ] || die "本機落後遠端 $BEHIND 個提交，請先執行 git pull。"

git fetch origin --tags --quiet 2>/dev/null || true

CURRENT="$(get_current_version)"
CONST_VERSION="$(get_constant_version)"
[ -n "$CURRENT" ] || die "無法從 ${MAIN_FILE} 讀取目前的 Version。"

# 決定 Release 版本：預設取自外掛主檔（Release 版本 = 系統版本）
VERSION_RE='^[0-9]+\.[0-9]+\.[0-9]+$'
VERSION_SOURCE="main"
if [ -n "$VERSION" ]; then
    VERSION="${VERSION#v}"
    VERSION_SOURCE="explicit"
elif [ -n "$BUMP_KIND" ]; then
    [[ "$CURRENT" =~ $VERSION_RE ]] || die "主檔版本「${CURRENT}」格式不正確（需為 x.y.z），無法自動遞增。"
    VERSION="$(next_version "$CURRENT" "$BUMP_KIND")"
    VERSION_SOURCE="bump"
else
    VERSION="$CURRENT"
    if [ "$CURRENT" != "$CONST_VERSION" ]; then
        die "主檔的 Version（${CURRENT}）與 RISECREATIVES_OPT_VERSION（${CONST_VERSION}）不一致。Release 版本必須與外掛系統版本一致，請先把兩處改成相同的版本號。"
    fi
fi

[[ "$VERSION" =~ $VERSION_RE ]] || die "版本號格式不正確：「${VERSION}」（需為 x.y.z，例如 1.3.1）"
TAG="v$VERSION"
NOTES_FILE="$NOTES_DIR/$TAG.md"

TAG_EXISTS_MSG="此版本（${TAG}）已經發布過了。請先把外掛主檔的 Version 與 RISECREATIVES_OPT_VERSION 改成新的版本號（例如 $(next_version "$VERSION" patch)），或執行 ./release.sh --bump 讓腳本幫你修改。"
if git rev-parse -q --verify "refs/tags/$TAG" >/dev/null; then
    die "$TAG_EXISTS_MSG"
fi
if git ls-remote --exit-code --tags origin "refs/tags/$TAG" >/dev/null 2>&1; then
    die "$TAG_EXISTS_MSG"
fi

# 新版本必須大於已發布的最新版本
LATEST_TAG_VERSION="$(latest_tag_version)"
if [ -n "$LATEST_TAG_VERSION" ] && ! version_gt "$VERSION" "$LATEST_TAG_VERSION"; then
    die "版本（${VERSION}）必須大於已發布的最新版本（${LATEST_TAG_VERSION}）。"
fi

# PHP 語法檢查（有安裝 php 才執行，避免把壞掉的版本發布給客戶網站）
if command -v php >/dev/null 2>&1; then
    while IFS= read -r f; do
        if ! php -l "$f" >/dev/null 2>&1; then
            php -l "$f" || true
            die "PHP 語法錯誤：$f"
        fi
    done < <(find . -name '*.php' -not -path './.git/*' -not -path "./$DIST_DIR/*")
    ok "PHP 語法檢查通過"
else
    warn "未安裝 php，略過語法檢查（建議安裝後再發布）。"
fi

# 更新摘要：未指定時依本次異動自動產生
if [ -z "$SUMMARY" ]; then
    SUMMARY="$(auto_summary)"
fi

# ----- 預覽 -----
LAST_TAG="$(git describe --tags --abbrev=0 --match 'v[0-9]*' 2>/dev/null || true)"

echo
info "發布預覽"
echo "  儲存庫：$ORIGIN_URL"
echo "  分支　：$BRANCH"
if [ "$VERSION_SOURCE" = "main" ]; then
    echo "  版本　：${TAG}（取自外掛主檔，Release 版本與外掛系統版本一致）"
else
    echo "  版本　：v${CURRENT} → ${TAG}（將同步修改外掛主檔的兩處版本）"
fi
echo "  Commit：$TAG: $SUMMARY"
echo "  上一版：${LAST_TAG:-（尚無 tag，這是第一次發布）}"
echo "  將納入提交的異動："
CHANGES="$(git status --short -uall | grep -v -E "^\?\? ($DIST_DIR/|$NOTES_DIR/$TAG\.md)" || true)"
if [ -n "$CHANGES" ]; then
    printf '%s\n' "$CHANGES" | sed -n '1,50p' | sed 's/^/    /'
    if [ "$(printf '%s\n' "$CHANGES" | wc -l)" -gt 50 ]; then echo "    ...（僅顯示前 50 筆）"; fi
else
    echo "    （目前沒有其他未提交的異動，只會有版本號與更新說明）"
fi
echo

if [ "$DRY_RUN" -eq 1 ]; then
    ok "--dry-run：以上檢查皆通過，未做任何變更。"
    SUCCESS=1
    exit 0
fi

if [ "$ASSUME_YES" -ne 1 ]; then
    read -r -p "確定要發布 $TAG 嗎？ [y/N] " ANSWER
    case "$ANSWER" in
        y|Y|yes|YES) ;;
        *) die "已取消。" ;;
    esac
fi

START_HEAD="$(git rev-parse HEAD)"

# ----- 1. 修改版本號 -----
if [ "$VERSION_SOURCE" != "main" ] && { [ "$VERSION" != "$CURRENT" ] || [ "$CURRENT" != "$CONST_VERSION" ]; }; then
    info "修改版本號 v$CURRENT → v$VERSION"
    BUMPED=1
    bump_version "$VERSION"
    [ "$(get_current_version)" = "$VERSION" ] || die "主檔 Version 修改失敗。"
    [ "$(get_constant_version)" = "$VERSION" ] || die "RISECREATIVES_OPT_VERSION 修改失敗。"
    ok "已更新 $MAIN_FILE 的兩處版本號"
fi

# ----- 2. git add -----
info "git add -A"
git add -A

# ----- 3. 產生更新說明 -----
mkdir -p "$NOTES_DIR"
if [ -f "$NOTES_FILE" ]; then
    # 已有手寫的更新說明（例如 v1.3.0 相較 v1.2.9 的完整異動）：直接使用，不覆蓋
    info "使用既有的更新說明 ${NOTES_FILE}（不重新產生）"
else
info "產生更新說明 $NOTES_FILE"
NOTES_CREATED=1

{
    echo "# $TAG"
    echo
    echo "> 發布日期：$(date +%Y-%m-%d)"
    echo
    echo "## 更新摘要"
    echo
    echo "$SUMMARY"
    echo
    echo "## 本次異動檔案"
    echo
    FILE_LIST=""
    while IFS=$'\t' read -r st p1 p2; do
        [ -n "${st:-}" ] || continue
        case "$p1" in "$NOTES_DIR"/*|"$DIST_DIR"/*) continue ;; esac
        case "$st" in
            A)  FILE_LIST="${FILE_LIST}- 新增 \`$p1\`"$'\n' ;;
            M)  FILE_LIST="${FILE_LIST}- 修改 \`$p1\`"$'\n' ;;
            D)  FILE_LIST="${FILE_LIST}- 刪除 \`$p1\`"$'\n' ;;
            R*) FILE_LIST="${FILE_LIST}- 更名 \`$p1\` → \`$p2\`"$'\n' ;;
            *)  FILE_LIST="${FILE_LIST}- 異動 \`$p1\`"$'\n' ;;
        esac
    done < <(git diff --cached --name-status -M)
    if [ -n "$FILE_LIST" ]; then printf '%s' "$FILE_LIST"; else echo "- （無）"; fi
    echo

    if [ -n "$LAST_TAG" ]; then
        COMMIT_RANGE="$LAST_TAG..HEAD"
        COMMIT_TITLE="## 提交紀錄（自 $LAST_TAG 起）"
    else
        COMMIT_RANGE="HEAD"
        COMMIT_TITLE="## 提交紀錄"
    fi
    COMMIT_LINES="$(git log --no-merges --pretty=format:'- %s' "$COMMIT_RANGE" 2>/dev/null | grep -v -E '^- v[0-9]+\.[0-9]+\.[0-9]+' || true)"
    if [ -n "$COMMIT_LINES" ]; then
        echo "$COMMIT_TITLE"
        echo
        echo "$COMMIT_LINES"
        echo
    fi

    echo "## 升級注意事項"
    echo
    echo "- 更新前建議先備份網站；更新後請清除快取並確認網站運作正常。"
} > "$NOTES_FILE"
fi

if [ "$EDIT_NOTES" -eq 1 ]; then
    "${EDITOR:-vi}" "$NOTES_FILE"
fi
ok "已產生更新說明"

# ----- 4. git commit（訊息開頭加上版本號）與 tag -----
git add "$NOTES_FILE"
info "git commit：$TAG: $SUMMARY"
git commit -q -m "$TAG: $SUMMARY"
COMMITTED=1

git tag -a "$TAG" -m "$TAG: $SUMMARY"
TAGGED=1
ok "已建立 commit 與 tag $TAG"

# ----- 5. 打包 ZIP -----
info "打包 $DIST_DIR/$ZIP_NAME"
rm -rf "$DIST_DIR"
mkdir -p "$DIST_DIR"
STAGE_DIR="$(mktemp -d)"

# 以 git archive 匯出「已提交的檔案」，並遵守 .gitattributes 的 export-ignore
# （README、本腳本、更新說明等不會進入外掛 ZIP）
git archive --format=tar --prefix="${PLUGIN_SLUG}/" "$TAG" | tar -x -C "$STAGE_DIR"
( cd "$STAGE_DIR" && zip -rqX "$ROOT/$DIST_DIR/$ZIP_NAME" "$PLUGIN_SLUG" -x '*.DS_Store' )

if command -v unzip >/dev/null 2>&1; then
    ZIP_MAIN="$(unzip -p "$DIST_DIR/$ZIP_NAME" "$PLUGIN_SLUG/$MAIN_FILE")"
    grep -q -E "Version:[[:space:]]*$VERSION([[:space:]]|$)" <<< "$ZIP_MAIN" \
        || die "ZIP 內的主檔版本與 $VERSION 不符。"
    grep -q -E "RISECREATIVES_OPT_VERSION['\"][[:space:]]*,[[:space:]]*['\"]${VERSION}['\"]" <<< "$ZIP_MAIN" \
        || die "ZIP 內的 RISECREATIVES_OPT_VERSION 與 ${VERSION} 不符。"
    ZIP_LIST="$(unzip -l "$DIST_DIR/$ZIP_NAME")"
    if grep -q -E "release\.sh|README\.md|$NOTES_DIR/|\.git/" <<< "$ZIP_LIST"; then
        die "ZIP 內含不該出現的檔案（release.sh／README.md／release-notes／.git），請檢查 .gitattributes。"
    fi
fi
ok "ZIP 完成：$(du -h "$DIST_DIR/$ZIP_NAME" | cut -f1)，最外層資料夾為 $PLUGIN_SLUG/"

# ----- 6. 推送 -----
info "推送到 GitHub（$BRANCH 與 ${TAG}）"
git push --atomic origin "$BRANCH" "refs/tags/$TAG"
PUSHED=1
ok "已推送"

# ----- 7. 建立 GitHub Release -----
info "建立 GitHub Release $TAG"
gh release create "$TAG" "$DIST_DIR/$ZIP_NAME" \
    --title "$TAG" \
    --notes-file "$NOTES_FILE" \
    --verify-tag \
    --latest

SUCCESS=1
echo
ok "發布完成！"
RELEASE_URL="$(gh release view "$TAG" --json url -q .url 2>/dev/null || true)"
[ -z "$RELEASE_URL" ] || echo "  Release：$RELEASE_URL"
echo "  ZIP    ：$ROOT/$DIST_DIR/$ZIP_NAME"
echo "  客戶網站最多 12 小時內會看到更新，也可到「展躍系統 → 版本資訊」按「立即檢查更新」。"
