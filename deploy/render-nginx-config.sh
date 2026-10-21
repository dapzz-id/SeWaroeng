#!/usr/bin/env bash
# ==============================================================================
# SeWaroeng — Safe Nginx Virtual Host Renderer & Installer
# Renders host-specific configurations from templates with distinct destinations.
# Target: Ubuntu 22.04+ LTS / Debian 12+, Nginx 1.18+
# ==============================================================================

set -euo pipefail

RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
BOLD='\033[1m'
NC='\033[0m'

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(cd "${SCRIPT_DIR}/.." && pwd)"

TEMPLATES_DIR="${SCRIPT_DIR}/nginx/templates"
API_TEMPLATE="${TEMPLATES_DIR}/api.conf.template"
WEB_TEMPLATE="${TEMPLATES_DIR}/web.conf.template"

API_HOST="${STAGING_API_HOST:-}"
WEB_HOST="${STAGING_WEB_HOST:-}"
APP_ROOT="/var/www/sewaroeng"
PHP_SOCKET="unix:/run/php/php8.4-fpm.sock"
OUTPUT_DIR="${SCRIPT_DIR}/nginx/rendered"
INSTALL_MODE=false
DRY_RUN=false
RELOAD_NGINX=false

print_usage() {
    echo -e "${BOLD}Usage:${NC} $0 [OPTIONS]"
    echo ""
    echo "Options:"
    echo "  --api-host <FQDN>       Backend API hostname (required, or via STAGING_API_HOST)"
    echo "  --web-host <FQDN>       Frontend Web hostname (required, or via STAGING_WEB_HOST)"
    echo "  --app-root <path>       Application root path (default: /var/www/sewaroeng)"
    echo "  --php-socket <path>     PHP-FPM socket path (default: unix:/run/php/php8.4-fpm.sock)"
    echo "  --output-dir <path>     Directory to write rendered configs (default: deploy/nginx/rendered)"
    echo "  --install               Copy rendered configs to /etc/nginx/sites-available and enable symlinks"
    echo "  --reload                Reload Nginx if syntax test passes (requires --install)"
    echo "  --dry-run               Render in-memory / stdout only without modifying filesystem"
    echo "  -h, --help              Show this help message"
    echo ""
}

API_HOST_SET=false
WEB_HOST_SET=false
APP_ROOT_SET=false
PHP_SOCKET_SET=false
OUTPUT_DIR_SET=false

while [[ $# -gt 0 ]]; do
    if [[ -z "$1" ]]; then
        echo -e "${RED}Error: Empty argument passed to command line.${NC}" >&2
        exit 1
    fi

    case "$1" in
        --api-host)
            if [[ $# -lt 2 || -z "$2" || "$2" == --* ]]; then
                echo -e "${RED}Error: --api-host requires a non-empty hostname argument.${NC}" >&2
                exit 1
            fi
            if [[ "${API_HOST_SET}" == "true" && "${API_HOST}" != "$2" ]]; then
                echo -e "${RED}Error: Conflicting --api-host options specified ('${API_HOST}' vs '$2').${NC}" >&2
                exit 1
            fi
            API_HOST="$2"
            API_HOST_SET=true
            shift 2
            ;;
        --web-host)
            if [[ $# -lt 2 || -z "$2" || "$2" == --* ]]; then
                echo -e "${RED}Error: --web-host requires a non-empty hostname argument.${NC}" >&2
                exit 1
            fi
            if [[ "${WEB_HOST_SET}" == "true" && "${WEB_HOST}" != "$2" ]]; then
                echo -e "${RED}Error: Conflicting --web-host options specified ('${WEB_HOST}' vs '$2').${NC}" >&2
                exit 1
            fi
            WEB_HOST="$2"
            WEB_HOST_SET=true
            shift 2
            ;;
        --app-root)
            if [[ $# -lt 2 || -z "$2" || "$2" == --* ]]; then
                echo -e "${RED}Error: --app-root requires a non-empty path argument.${NC}" >&2
                exit 1
            fi
            if [[ "${APP_ROOT_SET}" == "true" && "${APP_ROOT}" != "$2" ]]; then
                echo -e "${RED}Error: Conflicting --app-root options specified ('${APP_ROOT}' vs '$2').${NC}" >&2
                exit 1
            fi
            APP_ROOT="$2"
            APP_ROOT_SET=true
            shift 2
            ;;
        --php-socket)
            if [[ $# -lt 2 || -z "$2" || "$2" == --* ]]; then
                echo -e "${RED}Error: --php-socket requires a non-empty socket path argument.${NC}" >&2
                exit 1
            fi
            if [[ "${PHP_SOCKET_SET}" == "true" && "${PHP_SOCKET}" != "$2" ]]; then
                echo -e "${RED}Error: Conflicting --php-socket options specified ('${PHP_SOCKET}' vs '$2').${NC}" >&2
                exit 1
            fi
            PHP_SOCKET="$2"
            PHP_SOCKET_SET=true
            shift 2
            ;;
        --output-dir)
            if [[ $# -lt 2 || -z "$2" || "$2" == --* ]]; then
                echo -e "${RED}Error: --output-dir requires a non-empty directory path.${NC}" >&2
                exit 1
            fi
            if [[ "${OUTPUT_DIR_SET}" == "true" && "${OUTPUT_DIR}" != "$2" ]]; then
                echo -e "${RED}Error: Conflicting --output-dir options specified ('${OUTPUT_DIR}' vs '$2').${NC}" >&2
                exit 1
            fi
            OUTPUT_DIR="$2"
            OUTPUT_DIR_SET=true
            shift 2
            ;;
        --install)
            if [[ "${DRY_RUN}" == "true" ]]; then
                echo -e "${RED}Error: Conflicting options: cannot combine --dry-run and --install.${NC}" >&2
                exit 1
            fi
            INSTALL_MODE=true
            shift
            ;;
        --reload)
            RELOAD_NGINX=true
            shift
            ;;
        --dry-run)
            if [[ "${INSTALL_MODE}" == "true" ]]; then
                echo -e "${RED}Error: Conflicting options: cannot combine --dry-run and --install.${NC}" >&2
                exit 1
            fi
            DRY_RUN=true
            shift
            ;;
        -h|--help)
            print_usage
            exit 0
            ;;
        *)
            echo -e "${RED}Unknown argument: $1${NC}" >&2
            print_usage
            exit 1
            ;;
    esac
done

echo -e "${BOLD}====================================================================${NC}"
echo -e "${BOLD}       SeWaroeng Safe Nginx Virtual Host Renderer & Installer       ${NC}"
echo -e "${BOLD}====================================================================${NC}"

# 1. Validate Template Existence
if [[ ! -f "${API_TEMPLATE}" ]]; then
    echo -e "${RED}CRITICAL: API template missing: ${API_TEMPLATE}${NC}" >&2
    exit 1
fi

if [[ ! -f "${WEB_TEMPLATE}" ]]; then
    echo -e "${RED}CRITICAL: Web template missing: ${WEB_TEMPLATE}${NC}" >&2
    exit 1
fi

# 2. Validate Domain Parameters via validate-domains.sh
VALIDATOR="${SCRIPT_DIR}/validate-domains.sh"
if [[ -f "${VALIDATOR}" ]]; then
    if ! bash "${VALIDATOR}" --mode staging --api-host "${API_HOST}" --web-host "${WEB_HOST}"; then
        echo -e "${RED}Domain validation failed. Aborting template rendering.${NC}" >&2
        exit 1
    fi
else
    # Fallback validation if standalone validator is absent
    if [[ -z "${API_HOST}" || -z "${WEB_HOST}" ]]; then
        echo -e "${RED}CRITICAL: Both --api-host and --web-host must be explicitly provided.${NC}" >&2
        exit 1
    fi
    if [[ "${API_HOST}" == "${WEB_HOST}" ]]; then
        echo -e "${RED}CRITICAL: API host and Web host must be distinct.${NC}" >&2
        exit 1
    fi
fi

# 3. Define and Verify Distinct Destination Paths
API_FILENAME="${API_HOST}.conf"
WEB_FILENAME="${WEB_HOST}.conf"

if [[ "${API_FILENAME}" == "${WEB_FILENAME}" ]]; then
    echo -e "${RED}CRITICAL ERROR: Destination filenames collided: ${API_FILENAME}! Aborting.${NC}" >&2
    exit 1
fi

echo -e "API Target File: ${BOLD}${API_FILENAME}${NC}"
echo -e "Web Target File: ${BOLD}${WEB_FILENAME}${NC}"

# 4. Render Configurations
render_template() {
    local template="$1"
    local host="$2"
    sed -e "s|\${API_HOST}|${host}|g" \
        -e "s|\${WEB_HOST}|${host}|g" \
        -e "s|\${APP_ROOT}|${APP_ROOT}|g" \
        -e "s|\${PHP_SOCKET}|${PHP_SOCKET}|g" \
        "${template}"
}

if [[ "${DRY_RUN}" == "true" ]]; then
    echo -e "\n${BLUE}--- [DRY-RUN] Rendered API Configuration (${API_FILENAME}) ---${NC}"
    render_template "${API_TEMPLATE}" "${API_HOST}" | sed -n '1,25p'
    echo -e "  ... [truncated for display] ..."

    echo -e "\n${BLUE}--- [DRY-RUN] Rendered Web Configuration (${WEB_FILENAME}) ---${NC}"
    render_template "${WEB_TEMPLATE}" "${WEB_HOST}" | sed -n '1,25p'
    echo -e "  ... [truncated for display] ..."

    echo -e "\n${GREEN}${BOLD}DRY-RUN SUCCESS:${NC} Templates rendered cleanly with distinct targets."
    exit 0
fi

# Write rendered files to output directory
mkdir -p "${OUTPUT_DIR}"
API_RENDERED="${OUTPUT_DIR}/${API_FILENAME}"
WEB_RENDERED="${OUTPUT_DIR}/${WEB_FILENAME}"

render_template "${API_TEMPLATE}" "${API_HOST}" > "${API_RENDERED}"
render_template "${WEB_TEMPLATE}" "${WEB_HOST}" > "${WEB_RENDERED}"

# Post-render sanity checks
test -s "${API_RENDERED}" || { echo -e "${RED}Rendered API config is empty!${NC}" >&2; exit 1; }
test -s "${WEB_RENDERED}" || { echo -e "${RED}Rendered Web config is empty!${NC}" >&2; exit 1; }

if ! grep -q "server_name ${API_HOST};" "${API_RENDERED}"; then
    echo -e "${RED}Rendered API config failed server_name verification.${NC}" >&2
    exit 1
fi

if ! grep -q "server_name ${WEB_HOST};" "${WEB_RENDERED}"; then
    echo -e "${RED}Rendered Web config failed server_name verification.${NC}" >&2
    exit 1
fi

echo -e "Rendered API vhost: ${GREEN}${API_RENDERED}${NC}"
echo -e "Rendered Web vhost: ${GREEN}${WEB_RENDERED}${NC}"

# 5. Safe System Installation (if requested)
if [[ "${INSTALL_MODE}" == "true" ]]; then
    SITES_AVAILABLE="/etc/nginx/sites-available"
    SITES_ENABLED="/etc/nginx/sites-enabled"

    if [[ ! -d "${SITES_AVAILABLE}" || ! -d "${SITES_ENABLED}" ]]; then
        echo -e "${RED}System Nginx directories (${SITES_AVAILABLE}, ${SITES_ENABLED}) not found.${NC}" >&2
        echo -e "Ensure Nginx is installed on the host before running with --install." >&2
        exit 1
    fi

    SYS_API_DEST="${SITES_AVAILABLE}/${API_FILENAME}"
    SYS_WEB_DEST="${SITES_AVAILABLE}/${WEB_FILENAME}"

    # Overwrite safety guard
    if [[ "${SYS_API_DEST}" == "${SYS_WEB_DEST}" ]]; then
        echo -e "${RED}CRITICAL: Destination paths in sites-available collided! Aborting.${NC}" >&2
        exit 1
    fi

    echo -e "\nInstalling virtual hosts to ${SITES_AVAILABLE}..."
    cp "${API_RENDERED}" "${SYS_API_DEST}"
    cp "${WEB_RENDERED}" "${SYS_WEB_DEST}"

    echo -e "Creating atomic symlinks in ${SITES_ENABLED}..."
    ln -sf "${SYS_API_DEST}" "${SITES_ENABLED}/${API_FILENAME}"
    ln -sf "${SYS_WEB_DEST}" "${SITES_ENABLED}/${WEB_FILENAME}"

    # Symlink verification
    echo -e "Verifying enabled symlink integrity..."
    if [[ ! -L "${SITES_ENABLED}/${API_FILENAME}" ]]; then
        echo -e "${RED}API symlink was not created in ${SITES_ENABLED}!${NC}" >&2
        exit 1
    fi

    if [[ ! -L "${SITES_ENABLED}/${WEB_FILENAME}" ]]; then
        echo -e "${RED}Web symlink was not created in ${SITES_ENABLED}!${NC}" >&2
        exit 1
    fi

    API_LINK_TARGET="$(readlink -f "${SITES_ENABLED}/${API_FILENAME}" || true)"
    WEB_LINK_TARGET="$(readlink -f "${SITES_ENABLED}/${WEB_FILENAME}" || true)"

    if [[ "${API_LINK_TARGET}" != "${SYS_API_DEST}" ]]; then
        echo -e "${RED}API symlink resolves to unexpected target: '${API_LINK_TARGET}' (expected '${SYS_API_DEST}')${NC}" >&2
        exit 1
    fi

    if [[ "${WEB_LINK_TARGET}" != "${SYS_WEB_DEST}" ]]; then
        echo -e "${RED}Web symlink resolves to unexpected target: '${WEB_LINK_TARGET}' (expected '${SYS_WEB_DEST}')${NC}" >&2
        exit 1
    fi

    echo -e "${GREEN}Symlinks verified successfully:${NC}"
    echo -e "  ${SITES_ENABLED}/${API_FILENAME} -> ${API_LINK_TARGET}"
    echo -e "  ${SITES_ENABLED}/${WEB_FILENAME} -> ${WEB_LINK_TARGET}"

    # Syntax test before reload
    echo -e "\nTesting Nginx configuration syntax..."
    if ! nginx -t; then
        echo -e "${RED}nginx -t failed! Refusing to reload Nginx. Investigate errors above.${NC}" >&2
        exit 1
    fi
    echo -e "${GREEN}Nginx syntax test passed.${NC}"

    if [[ "${RELOAD_NGINX}" == "true" ]]; then
        echo -e "Reloading Nginx service..."
        systemctl reload nginx
        echo -e "${GREEN}Nginx service reloaded successfully.${NC}"
    else
        echo -e "${YELLOW}Notice: Nginx was not reloaded. Pass --reload or run 'sudo systemctl reload nginx' when ready.${NC}"
    fi
fi

echo -e "\n${GREEN}${BOLD}RENDER PROCESS COMPLETED SUCCESSFULLY.${NC}"
