#!/usr/bin/env bash
set -Eeuo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
cd "$SCRIPT_DIR"

BRANCH="main"
NO_PULL=0
SKIP_WORKERS=0
NO_NGINX=0

usage() {
    cat << 'EOF'
Pemakaian: ./deploy.sh [opsi]

Opsi:
  --no-pull         Lewati git pull (pakai kode lokal apa adanya)
  --skip-workers    Jangan perbarui container queue, scheduler, dan reverb
  --no-nginx        Tukar container Blue-Green tanpa mengubah/reload Nginx
  --branch <nama>   Branch Git yang ditarik (bawaan: main)
  -h, --help        Tampilkan bantuan ini
EOF
}

while [[ $# -gt 0 ]]; do
    case "$1" in
        --no-pull)
            NO_PULL=1
            shift
            ;;
        --skip-workers)
            SKIP_WORKERS=1
            shift
            ;;
        --no-nginx)
            NO_NGINX=1
            shift
            ;;
        --branch)
            if [[ $# -lt 2 || -z "${2:-}" ]]; then
                echo "Error: --branch membutuhkan nama branch." >&2
                exit 1
            fi
            BRANCH="$2"
            shift 2
            ;;
        --branch=*)
            BRANCH="${1#*=}"
            shift
            ;;
        -h|--help)
            usage
            exit 0
            ;;
        *)
            echo "Opsi tidak dikenal: $1" >&2
            usage >&2
            exit 1
            ;;
    esac
done

if [[ ! -f .env ]]; then
    echo "Error: file .env tidak ditemukan di $SCRIPT_DIR." >&2
    exit 1
fi

read_env() {
    local key="$1"
    local default_val="${2:-}"
    local line=""
    line="$(grep -E "^${key}=" .env | tail -n 1 || true)"
    if [[ -z "$line" ]]; then
        printf '%s' "$default_val"
        return
    fi

    local val="${line#*=}"
    val="${val%$'\r'}"
    val="${val#\"}"
    val="${val%\"}"
    val="${val#\'}"
    val="${val%\'}"

    if [[ -z "$val" ]]; then
        printf '%s' "$default_val"
    else
        printf '%s' "$val"
    fi
}

APP_PORT="$(read_env APP_PORT 8000)"
APP_GREEN_PORT="$(read_env APP_GREEN_PORT 8002)"
NGINX_UPSTREAM_FILE="$(read_env NGINX_UPSTREAM_FILE /etc/nginx/conf.d/sicma-upstream.conf)"

if [[ "$APP_PORT" == "$APP_GREEN_PORT" ]]; then
    echo "Error: APP_PORT ($APP_PORT) dan APP_GREEN_PORT ($APP_GREEN_PORT) tidak boleh sama." >&2
    exit 1
fi

if [[ "$NO_NGINX" -eq 0 ]]; then
    if [[ ! -f "$NGINX_UPSTREAM_FILE" ]]; then
        cat >&2 << EOF
Error: file upstream Nginx tidak ditemukan di: $NGINX_UPSTREAM_FILE
Siapkan sekali di VPS dengan:
  printf 'upstream sicma_app {\n    server 127.0.0.1:%s;\n    keepalive 16;\n}\n' "$APP_PORT" | sudo tee "$NGINX_UPSTREAM_FILE"
Lalu pastikan blok location / di /etc/nginx/sites-available/sicma memakai proxy_pass http://sicma_app;
EOF
        exit 1
    fi
    sudo -v
fi

if [[ "$NO_PULL" -eq 0 ]]; then
    echo "==> Menarik perubahan terbaru dari origin/$BRANCH..."
    git pull --ff-only origin "$BRANCH"
fi

# Deteksi slot aktif saat ini agar deploy diarahkan ke slot cadangan.
if [[ "$NO_NGINX" -eq 0 && -f "$NGINX_UPSTREAM_FILE" ]] && grep -qE "127\.0\.0\.1:${APP_GREEN_PORT}([^0-9]|$)" "$NGINX_UPSTREAM_FILE"; then
    OLD_SERVICE="app_green"
    OLD_PORT="$APP_GREEN_PORT"
    TARGET_SERVICE="app"
    TARGET_PORT="$APP_PORT"
elif [[ "$NO_NGINX" -eq 0 && -f "$NGINX_UPSTREAM_FILE" ]] && grep -qE "127\.0\.0\.1:${APP_PORT}([^0-9]|$)" "$NGINX_UPSTREAM_FILE"; then
    OLD_SERVICE="app"
    OLD_PORT="$APP_PORT"
    TARGET_SERVICE="app_green"
    TARGET_PORT="$APP_GREEN_PORT"
else
    RUNNING_SERVICES="$(docker compose --profile bluegreen ps --status running --services 2>/dev/null || true)"
    if echo "$RUNNING_SERVICES" | grep -qx 'app_green' && ! echo "$RUNNING_SERVICES" | grep -qx 'app'; then
        OLD_SERVICE="app_green"
        OLD_PORT="$APP_GREEN_PORT"
        TARGET_SERVICE="app"
        TARGET_PORT="$APP_PORT"
    else
        OLD_SERVICE="app"
        OLD_PORT="$APP_PORT"
        TARGET_SERVICE="app_green"
        TARGET_PORT="$APP_GREEN_PORT"
    fi
fi

echo "==> Slot aktif: $OLD_SERVICE (port $OLD_PORT) -> Slot rilis baru: $TARGET_SERVICE (port $TARGET_PORT)"

echo "==> Membangun image sicma-app:local (1x build; web tetap online)..."
docker compose build app

echo "==> Memastikan mysql dan redis aktif tanpa restart..."
docker compose up -d --no-build mysql redis

rollback_target() {
    echo "ERROR: Deploy gagal pada slot $TARGET_SERVICE (port $TARGET_PORT). Mengembalikan kondisi sebelumnya..." >&2
    docker compose --profile bluegreen logs --tail=40 "$TARGET_SERVICE" >&2 || true
    docker compose --profile bluegreen stop "$TARGET_SERVICE" >/dev/null 2>&1 || true
}

echo "==> Menyalakan slot baru: $TARGET_SERVICE..."
if ! docker compose --profile bluegreen up -d --no-build --no-deps --force-recreate "$TARGET_SERVICE"; then
    rollback_target
    exit 1
fi

echo "==> Menunggu healthcheck /up di 127.0.0.1:$TARGET_PORT..."
ready=0
for _ in $(seq 1 45); do
    code="$(curl -sS -o /dev/null -w '%{http_code}' "http://127.0.0.1:${TARGET_PORT}/up" 2>/dev/null || true)"
    if [[ "$code" == "200" ]]; then
        ready=1
        break
    fi
    sleep 1
done

if [[ "$ready" -ne 1 ]]; then
    echo "Error: /up di port $TARGET_PORT tidak merespons 200 dalam 45 detik." >&2
    rollback_target
    exit 1
fi

echo "==> Menjalankan migrate --force dan optimize di $TARGET_SERVICE..."
if ! docker compose --profile bluegreen exec -T "$TARGET_SERVICE" php artisan migrate --force; then
    rollback_target
    exit 1
fi

if ! docker compose --profile bluegreen exec -T "$TARGET_SERVICE" php artisan optimize; then
    rollback_target
    exit 1
fi

up_code="$(curl -sS -o /dev/null -w '%{http_code}' "http://127.0.0.1:${TARGET_PORT}/up" 2>/dev/null || true)"
home_code="$(curl -sS -o /dev/null -w '%{http_code}' "http://127.0.0.1:${TARGET_PORT}/" 2>/dev/null || true)"
if [[ "$up_code" != "200" || "$home_code" != "200" ]]; then
    echo "Error: verifikasi slot baru gagal (/up=$up_code, /=$home_code)." >&2
    rollback_target
    exit 1
fi
echo "==> Verifikasi slot baru lulus (/up=$up_code, /=$home_code)."

if [[ "$NO_NGINX" -eq 0 ]]; then
    BACKUP_UPSTREAM="$(mktemp)"
    cp "$NGINX_UPSTREAM_FILE" "$BACKUP_UPSTREAM"

    echo "==> Mengalihkan upstream Nginx ke 127.0.0.1:$TARGET_PORT..."
    cat << EOF | sudo tee "$NGINX_UPSTREAM_FILE" >/dev/null
upstream sicma_app {
    server 127.0.0.1:${TARGET_PORT};
    keepalive 16;
}
EOF

    if ! sudo nginx -t || ! sudo systemctl reload nginx; then
        echo "Error: konfigurasi Nginx gagal di-reload, mengembalikan upstream sebelumnya..." >&2
        sudo cp "$BACKUP_UPSTREAM" "$NGINX_UPSTREAM_FILE"
        rm -f "$BACKUP_UPSTREAM"
        sudo nginx -t >/dev/null 2>&1 && sudo systemctl reload nginx >/dev/null 2>&1 || true
        rollback_target
        exit 1
    fi
    rm -f "$BACKUP_UPSTREAM"
fi

if [[ "$OLD_SERVICE" != "$TARGET_SERVICE" ]]; then
    echo "==> Menunggu koneksi aktif di slot lama ($OLD_SERVICE) selesai (2 detik)..."
    sleep 2
    docker compose --profile bluegreen stop "$OLD_SERVICE" >/dev/null 2>&1 || true
fi

if [[ "$SKIP_WORKERS" -eq 0 ]]; then
    if docker compose ps --status running --services 2>/dev/null | grep -qx 'queue'; then
        echo "==> Mengirim sinyal graceful queue:restart..."
        docker compose exec -T queue php artisan queue:restart >/dev/null 2>&1 || true
    fi
    echo "==> Memperbarui worker (queue, scheduler, reverb) tanpa menyentuh mysql/redis/web..."
    docker compose up -d --no-build --no-deps queue scheduler reverb
fi

docker image prune -f >/dev/null 2>&1 || true

COMMIT_SHA="$(git rev-parse --short HEAD 2>/dev/null || echo 'unknown')"
echo "Selesai! Slot aktif sekarang: $TARGET_SERVICE (127.0.0.1:$TARGET_PORT) | Commit: $COMMIT_SHA"
