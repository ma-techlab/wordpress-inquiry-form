#!/usr/bin/env bash
#
# 配布用ZIPを作る：dist/mervelta-inquiry-manager.zip
#
# - ZIPに入れるのはプラグインのフォルダ（mervelta-inquiry-manager/）だけ。
#   開発用のファイル（vendor、composer.*、phpcs.xml.dist、docs、bin、.git）は
#   リポジトリの最上位にあるので、そもそも対象に入らない。
# - ZIPの中の最上位は mervelta-inquiry-manager/ になる（WordPressの「プラグインのアップロード」の形）。
# - 隠しファイル（.DS_Store など）とエディタの一時ファイルは入れない。
# - 作ったあと中身を検査し、開発用のファイルが混ざっていれば失敗にする。
#
# 使い方：bash bin/build-zip.sh

set -euo pipefail

SLUG="mervelta-inquiry-manager"
ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
DIST_DIR="${ROOT_DIR}/dist"
ZIP_PATH="${DIST_DIR}/${SLUG}.zip"

if ! command -v zip > /dev/null 2>&1; then
	echo "エラー：zip コマンドがありません。'sudo apt install zip' で入れてください。" >&2
	exit 1
fi

if ! command -v unzip > /dev/null 2>&1; then
	echo "エラー：unzip コマンドがありません（中身の検査に使います）。'sudo apt install unzip' で入れてください。" >&2
	exit 1
fi

if [[ ! -f "${ROOT_DIR}/${SLUG}/${SLUG}.php" ]]; then
	echo "エラー：${ROOT_DIR}/${SLUG}/${SLUG}.php が見つかりません。" >&2
	exit 1
fi

# 出力前に、全PHPファイルの文法を確かめる（壊れたZIPを作らないため）.
echo "php -l で文法を確認しています…"
while IFS= read -r -d '' file; do
	php -l "${file}" > /dev/null
done < <(find "${ROOT_DIR}/${SLUG}" -name '*.php' -print0)

mkdir -p "${DIST_DIR}"

# 前回の出力（このスクリプトが作るファイル1つだけ）を消してから作る。追記で古いファイルが残らないようにするため.
rm -f "${ZIP_PATH}"

(
	cd "${ROOT_DIR}"
	zip -r -X -q "${ZIP_PATH}" "${SLUG}" \
		-x "${SLUG}/.*" "${SLUG}/*/.*" "*/.DS_Store" "*~" "*.swp"
)

# 中身の検査：開発用のファイル・隠しファイルが入っていないこと、最上位が SLUG/ であること.
LISTING="$(unzip -Z1 "${ZIP_PATH}")"

if grep -Ev "^${SLUG}/" <<< "${LISTING}" > /dev/null; then
	echo "エラー：ZIPの最上位に ${SLUG}/ 以外のものがあります。" >&2
	exit 1
fi

if grep -E '(^|/)(vendor|docs|bin|dist|\.git)(/|$)|(^|/)composer\.(json|lock)$|(^|/)phpcs\.xml(\.dist)?$|(^|/)\.[^/]+' <<< "${LISTING}"; then
	echo "エラー：上の開発用・隠しファイルがZIPに入っています。" >&2
	exit 1
fi

echo "作成しました：${ZIP_PATH}"
