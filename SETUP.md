# 開発環境セットアップ手順(2台構成)

このプロジェクトを **PC と ノートPC の2台** で開発し、**iPhone実機でGPSテスト**するための手順。
コード共有もHTTPS配信も **Git + GitHub** ひとつで解決する。

```
  [PC] ──push──▶  GitHub (Public)  ◀──pull── [ノートPC]
                      │
                 GitHub Pages (HTTPS)
                      │
                      ▼
             iPhone Safari で GPS 実機テスト
```

## 必要なツール(各PCに1回)

| ツール | 用途 | 確認コマンド |
|---|---|---|
| Git | コード管理・同期 | `git --version` |
| GitHub CLI (`gh`) | リポジトリ作成・認証 | `gh --version` |
| Node.js / npm | ローカルプレビュー | `node --version` |
| Claude Code | 開発 | — |

---

## Step 1: Git ユーザー設定(各PCで1回)

```
git config --global user.name "Kenny"
git config --global user.email "hillsfactory1966@gmail.com"
```

## Step 2: GitHub にログイン(各PCで1回・ブラウザ認証)

```
gh auth login
```
選択: `GitHub.com` → `HTTPS` → `Yes` → `Login with a web browser` → 表示コードをブラウザに貼付。
確認: `gh auth status` で `Logged in to github.com` と出ればOK。

## Step 3: リポジトリを GitHub に作成(このPCで1回のみ)

このフォルダは `git init` 済み。GitHubへ上げる:

```
gh repo create bus-gps --public --source="." --remote=origin --push
```

## Step 4: ノートPCで受け取る(ノートPCで1回)

```
gh repo clone <GitHubユーザー名>/bus-gps
```

## Step 5: 日々のワークフロー(両PC共通)

**鉄則: 作業前に `pull`、作業後に `commit`→`push`。1台ずつ順番に。**

```
git pull
# ...編集...
git add -A
git commit -m "変更内容"
git push
```

## Step 6: iPhone実機テスト(HTTPS配信)

GitHub Pages を有効化(Publicリポジトリ):

```
gh api -X POST repos/<ユーザー名>/bus-gps/pages -f "source[branch]=main" -f "source[path]=/"
```
数分後、iPhoneのSafariで開く:
`https://<ユーザー名>.github.io/bus-gps/bus_tracker.html`
※ iOS設定 → プライバシーとセキュリティ → 位置情報サービス → Safari を許可。

## Step 7: ローカルプレビュー(普段の確認)

自PCのブラウザなら `http://localhost` でもGPSは動く(HTTPS扱い)。iPhoneが絡む時だけHTTPSが必要。

```
npx serve .
```
表示された `http://localhost:3000` 等を PC の Chrome で開く。

---

## 補足

- GPSは `file://` やプレビューiframe内では動かない(`Origin does not have permission`)。必ず `http://localhost` かHTTPS経由で開く。
- Privateにしたい場合、Pagesは無料プランで使えないので、iPhoneテストは `npx localtunnel --port 3000` で一時的にHTTPS URLを発行する方式にする。
