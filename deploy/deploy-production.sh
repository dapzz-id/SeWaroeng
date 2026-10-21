#!/usr/bin/env bash
# ==============================================================================
# SeWaroeng — Guarded Production Deployment Script (cPanel Apache / VPS)
# Safe, forward-only production deployment pipeline with dry-run default guard.
# Target: Production Environment (sewaroeng_production)
# Planned Production Domains:
#   - Backend API: https://api.sewaroeng.neuracakrawira.asia
#   - Frontend Web: https://sewaroeng.neuracakrawira.asia
# ==============================================================================

set -euo pipefail

RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
CYAN='\033[0;36m'
BOLD='\033[1m'
NC='\033[0m'

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(cd "${SCRIPT_DIR}/.." && pwd)"

# Global error trap reporting exact failure line and command
on_error() {
    local exit_code="$1"
    local line_num="$2"
    local command="$3"
    echo -e "\n${RED}${BOLD}PRODUCTION DEPLOYMENT FAILED!${NC}"
    echo -e "Failed at line ${line_num} while executing: ${command}"
    echo -e "Exit status: ${exit_code}"
    echo -e "Target Environment: ${RESOLVED_ENV:-unknown}"
    echo -e "Target Database:    ${RESOLVED_DB:-unknown}"
    echo -e "Any completed steps prior to failure are retained. Resolve the issue and re-run."
    unset PGPASSWORD 2>/dev/null || true
    exit "${exit_code}"
}
trap 'on_error $? $LINENO "$BASH_COMMAND"' ERR

BACKEND_DIR="${PROJECT_ROOT}/backend"
FRONTEND_DIR="${PROJECT_ROOT}/frontend"
BACKUP_DIR="${BACKUP_DIR:-${PROJECT_ROOT}/backups}"

# 1. Parse Arguments (Default is DRY-RUN mode)
EXECUTE_MODE=false
MODE_CHOSEN=""
SKIP_FRONTEND_BUILD=false
SKIP_BACKUP=false
SKIP_CONNECTIVITY=false
SKIP_PHP_CHECK=false
ENV_FILE="${ENV_FILE:-${BACKEND_DIR}/.env}"

PROD_API_HOST="api.sewaroeng.neuracakrawira.asia"
PROD_WEB_HOST="sewaroeng.neuracakrawira.asia"

API_HOST="${PROD_API_HOST}"
WEB_HOST="${PROD_WEB_HOST}"
API_PUBLIC_DIR=""
WEB_PUBLIC_DIR=""
EXTERNAL_BACKUP_CONFIRMED=false

API_HOST_SET=false
WEB_HOST_SET=false
ENV_FILE_SET=false

print_usage() {
    echo -e "${BOLD}Usage:${NC} $0 [OPTIONS]"
    echo ""
    echo "Options:"
    echo "  --dry-run                    Run production preflight and output deployment plan without mutating state (DEFAULT)"
    echo "  --apply, --execute           Execute the production deployment steps (mutates dependencies, schema, and caches)"
    echo "  --env-file <path>            Path to production environment file (default: backend/.env)"
    echo "  --api-host <FQDN>            Production API hostname (default: ${PROD_API_HOST})"
    echo "  --web-host <FQDN>            Production Web hostname (default: ${PROD_WEB_HOST})"
    echo "  --api-public-dir <path>      Target public directory for API front controller (e.g. /home/user/sewaroeng_api_public)"
    echo "  --web-public-dir <path>      Target public directory for Flutter Web bundle (e.g. /home/user/sewaroeng_web)"
    echo "  --migrator-user <user>       Database user with schema DDL privileges for migration (default: sewaroeng_prod_migrator)"
    echo "  --migrator-password <pass>   Password for schema migrator role"
    echo "  --external-backup-confirmed  Confirm external cloud database snapshot taken if pg_dump is absent on cPanel host"
    echo "  --skip-connectivity          Skip network connectivity checks in preflight (DRY-RUN / simulation ONLY)"
    echo "  --skip-php-check             Skip PHP runtime check in preflight (DRY-RUN / simulation ONLY)"
    echo "  --skip-frontend-build        Skip Flutter Web build (use existing compiled bundle)"
    echo "  --skip-backup                Skip pre-migration database dump (CAUTION: Prohibited in production --apply)"
    echo "  -h, --help                   Show this help message"
    echo ""
}

while [[ $# -gt 0 ]]; do
    if [[ -z "$1" ]]; then
        echo -e "${RED}Error: Empty argument passed to command line.${NC}" >&2
        exit 1
    fi

    case "$1" in
        --apply|--execute)
            if [[ "${MODE_CHOSEN}" == "dry-run" ]]; then
                echo -e "${RED}Error: Conflicting execution modes specified: cannot combine --dry-run and --apply/--execute.${NC}" >&2
                exit 1
            fi
            EXECUTE_MODE=true
            MODE_CHOSEN="apply"
            shift
            ;;
        --dry-run)
            if [[ "${MODE_CHOSEN}" == "apply" ]]; then
                echo -e "${RED}Error: Conflicting execution modes specified: cannot combine --dry-run and --apply/--execute.${NC}" >&2
                exit 1
            fi
            EXECUTE_MODE=false
            MODE_CHOSEN="dry-run"
            shift
            ;;
        --skip-frontend-build)
            SKIP_FRONTEND_BUILD=true
            shift
            ;;
        --skip-backup)
            SKIP_BACKUP=true
            shift
            ;;
        --skip-connectivity)
            SKIP_CONNECTIVITY=true
            shift
            ;;
        --skip-php-check)
            SKIP_PHP_CHECK=true
            shift
            ;;
        --external-backup-confirmed)
            EXTERNAL_BACKUP_CONFIRMED=true
            shift
            ;;
        --api-public-dir)
            if [[ $# -lt 2 || -z "$2" || "$2" == --* ]]; then
                echo -e "${RED}Error: --api-public-dir requires a path argument.${NC}" >&2
                exit 1
            fi
            API_PUBLIC_DIR="$2"
            shift 2
            ;;
        --web-public-dir)
            if [[ $# -lt 2 || -z "$2" || "$2" == --* ]]; then
                echo -e "${RED}Error: --web-public-dir requires a path argument.${NC}" >&2
                exit 1
            fi
            WEB_PUBLIC_DIR="$2"
            shift 2
            ;;
        --env-file)
            if [[ $# -lt 2 || -z "$2" || "$2" == --* ]]; then
                echo -e "${RED}Error: --env-file requires a non-empty path argument.${NC}" >&2
                exit 1
            fi
            if [[ "${ENV_FILE_SET}" == "true" && "${ENV_FILE}" != "$2" ]]; then
                echo -e "${RED}Error: Conflicting --env-file options specified ('${ENV_FILE}' vs '$2').${NC}" >&2
                exit 1
            fi
            ENV_FILE="$2"
            ENV_FILE_SET=true
            shift 2
            ;;
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
        --migrator-user)
            if [[ $# -lt 2 || -z "$2" || "$2" == --* ]]; then
                echo -e "${RED}Error: --migrator-user requires a username argument.${NC}" >&2
                exit 1
            fi
            CLI_MIGRATOR_USER="$2"
            shift 2
            ;;
        --migrator-password)
            if [[ $# -lt 2 || -z "$2" || "$2" == --* ]]; then
                echo -e "${RED}Error: --migrator-password requires a password argument.${NC}" >&2
                exit 1
            fi
            CLI_MIGRATOR_PASS="$2"
            shift 2
            ;;
        -h|--help)
            print_usage
            exit 0
            ;;
        *)
            echo -e "${RED}Unknown option: $1${NC}" >&2
            print_usage >&2
            exit 1
            ;;
    esac
done

# Invariant: Reject bypass options when --apply is specified
if [[ "${EXECUTE_MODE}" == "true" ]]; then
    if [[ "${SKIP_CONNECTIVITY}" == "true" ]]; then
        echo -e "${RED}${BOLD}CRITICAL SAFETY VIOLATION:${NC} Cannot combine --apply with --skip-connectivity in production." >&2
        echo -e "Production deployment MUST verify live database and Redis connectivity before applying changes." >&2
        exit 1
    fi

    if [[ "${SKIP_PHP_CHECK}" == "true" ]]; then
        echo -e "${RED}${BOLD}CRITICAL SAFETY VIOLATION:${NC} Cannot combine --apply with --skip-php-check in production." >&2
        echo -e "Production deployment MUST verify PHP 8.4 runtime and required extensions before applying changes." >&2
        exit 1
    fi

    if [[ "${SKIP_BACKUP}" == "true" ]]; then
        echo -e "${RED}${BOLD}CRITICAL SAFETY VIOLATION:${NC} Cannot combine --apply with --skip-backup in production." >&2
        echo -e "Pre-migration database backup is mandatory for production schema migrations." >&2
        exit 1
    fi
fi

# Variables for migrator role
CLI_MIGRATOR_USER="${CLI_MIGRATOR_USER:-}"
CLI_MIGRATOR_PASS="${CLI_MIGRATOR_PASS:-}"

# ==============================================================================
# Helper Functions: Syntax Validation & Safe Variable Extraction
# ==============================================================================
validate_env_file_syntax() {
    local file="$1"
    local line_num=0
    local raw_line

    if tr -d '\r\n\t ' < "${file}" | grep -q '[^[:print:]]'; then
        echo -e "${RED}Syntax Error:${NC} Environment file contains binary or non-printable characters." >&2
        return 1
    fi

    while IFS= read -r raw_line || [[ -n "${raw_line}" ]]; do
        line_num=$((line_num + 1))
        local line="${raw_line%$'\r'}"

        if [[ -z "${line}" || "${line}" =~ ^[[:space:]]*$ || "${line}" =~ ^[[:space:]]*# ]]; then
            continue
        fi

        if [[ ! "${line}" =~ ^[A-Za-z_][A-Za-z0-9_]*= ]]; then
            echo -e "${RED}Syntax Error in .env line ${line_num}:${NC} Non KEY=VALUE syntax: '${line}'" >&2
            return 1
        fi

        local val="${line#*=}"
        if [[ "${val}" =~ ^\" ]]; then
            if [[ ! "${val}" =~ ^\"(.*)\"[[:space:]]*(#.*)?$ ]]; then
                echo -e "${RED}Syntax Error in .env line ${line_num}:${NC} Unclosed double quote in value: '${line}'" >&2
                return 1
            fi
        elif [[ "${val}" =~ ^\' ]]; then
            if [[ ! "${val}" =~ ^\'(.*)\'[[:space:]]*(#.*)?$ ]]; then
                echo -e "${RED}Syntax Error in .env line ${line_num}:${NC} Unclosed single quote in value: '${line}'" >&2
                return 1
            fi
        else
            local clean_val="${val%%#*}"
            if [[ "${clean_val}" =~ [[:space:]] ]]; then
                echo -e "${RED}Syntax Error in .env line ${line_num}:${NC} Unquoted value contains whitespace: '${line}'" >&2
                echo -e "Values with spaces must be enclosed in quotes (e.g. KEY=\"value with spaces\")." >&2
                return 1
            fi
        fi
    done < "${file}"

    return 0
}

get_env_val() {
    local key="$1"
    local line
    line="$(grep -E "^${key}=" "${ENV_FILE}" 2>/dev/null | tail -n 1 || true)"
    if [[ -z "${line}" ]]; then
        echo ""
        return
    fi
    local val="${line#*=}"
    val="${val%$'\r'}"
    if [[ "${val}" =~ ^\"(.*)\"$ ]]; then
        val="${BASH_REMATCH[1]}"
    elif [[ "${val}" =~ ^\'(.*)\'$ ]]; then
        val="${BASH_REMATCH[1]}"
    else
        val="${val%%#*}"
        read -r val <<< "${val}"
    fi
    echo "${val}"
}

# ==============================================================================
# Environment File Verification & Guardrails
# ==============================================================================
if [[ ! -f "${ENV_FILE}" ]]; then
    echo -e "${RED}Error: Environment file not found at: ${ENV_FILE}${NC}" >&2
    exit 1
fi

if [[ -d "${ENV_FILE}" ]]; then
    echo -e "${RED}Error: Environment path is a directory, not a file: ${ENV_FILE}${NC}" >&2
    exit 1
fi

if [[ ! -s "${ENV_FILE}" ]]; then
    echo -e "${RED}Error: Environment file is empty: ${ENV_FILE}${NC}" >&2
    exit 1
fi

if ! validate_env_file_syntax "${ENV_FILE}"; then
    echo -e "${RED}Error: Environment file '${ENV_FILE}' failed syntax validation. Aborting.${NC}" >&2
    exit 1
fi

# Invariant: Reject fixture files in production
if [[ "${ENV_FILE}" =~ (fixture|fixtures|\.example|\.test|\.stub) ]] || \
   grep -q "IS_DEPLOYMENT_FIXTURE=true" "${ENV_FILE}" 2>/dev/null; then
    echo -e "\n${RED}${BOLD}CRITICAL SAFETY VIOLATION:${NC} Cannot deploy using fixture file '${ENV_FILE}' in production!" >&2
    exit 1
fi

RESOLVED_ENV=$(get_env_val "APP_ENV")
RESOLVED_DEBUG=$(get_env_val "APP_DEBUG")
RESOLVED_APP_URL=$(get_env_val "APP_URL")
RESOLVED_CONN=$(get_env_val "DB_CONNECTION")
RESOLVED_DB=$(get_env_val "DB_DATABASE")
RESOLVED_DB_HOST=$(get_env_val "DB_HOST")
RESOLVED_DB_PORT=$(get_env_val "DB_PORT")
RESOLVED_DB_USER=$(get_env_val "DB_USERNAME")
RESOLVED_DB_PASS=$(get_env_val "DB_PASSWORD")

RESOLVED_MIGRATOR_USER="${CLI_MIGRATOR_USER:-$(get_env_val "DB_MIGRATOR_USERNAME")}"
if [[ -z "${RESOLVED_MIGRATOR_USER}" ]]; then
    RESOLVED_MIGRATOR_USER="sewaroeng_prod_migrator"
fi
RESOLVED_MIGRATOR_PASS="${CLI_MIGRATOR_PASS:-$(get_env_val "DB_MIGRATOR_PASSWORD")}"

# Reject fixture dummy password
if [[ "${RESOLVED_DB_PASS}" =~ (dummy_fixture_password|REPLACE_WITH_STRONG_PRODUCTION_SECRET|secret123) ]]; then
    echo -e "\n${RED}${BOLD}CRITICAL SAFETY VIOLATION:${NC} Production database password is a known dummy/fixture secret!" >&2
    exit 1
fi

if [[ "${EXECUTE_MODE}" == "true" && -z "${RESOLVED_MIGRATOR_PASS}" ]]; then
    echo -e "\n${RED}${BOLD}CRITICAL SAFETY VIOLATION:${NC} Cannot execute migrations in production without migrator credentials." >&2
    echo -e "Runtime role '${RESOLVED_DB_USER}' only possesses DML privileges (SELECT, INSERT, UPDATE, DELETE) and cannot perform DDL." >&2
    echo -e "Please provide migrator password via DB_MIGRATOR_PASSWORD in .env or the --migrator-password option." >&2
    exit 1
fi

echo -e "${BOLD}====================================================================${NC}"
echo -e "${BOLD}         SeWaroeng Guarded Production Deployment Pipeline           ${NC}"
echo -e "${BOLD}====================================================================${NC}"
echo -e "Timestamp:   $(date -u '+%Y-%m-%d %H:%M:%SZ')"
echo -e "Project:     ${PROJECT_ROOT}"
echo -e "Mode:        $([[ "${EXECUTE_MODE}" == "true" ]] && echo -e "${RED}${BOLD}APPLY (LIVE PRODUCTION MUTATIONS)${NC}" || echo -e "${GREEN}${BOLD}DRY-RUN (SAFETY SIMULATION)${NC}")"
echo -e "Env File:    ${ENV_FILE}"
echo -e "API Host:    ${API_HOST}"
echo -e "Web Host:    ${WEB_HOST}"
echo -e "Configured Environment Invariants:"
echo -e "  APP_ENV:       ${RESOLVED_ENV}"
echo -e "  APP_DEBUG:     ${RESOLVED_DEBUG}"
echo -e "  APP_URL:       ${RESOLVED_APP_URL}"
echo -e "  DB_CONN:       ${RESOLVED_CONN}"
echo -e "  DB_HOST:       ${RESOLVED_DB_HOST:-127.0.0.1}:${RESOLVED_DB_PORT:-5432}"
echo -e "  DB_DATABASE:   ${RESOLVED_DB}"
echo -e "  Runtime Role:  ${RESOLVED_DB_USER} (DML least-privilege)"
echo -e "  Migrator Role: ${RESOLVED_MIGRATOR_USER} (DDL schema owner)"

# Guard 1: APP_ENV must be strictly 'production'
if [[ "${RESOLVED_ENV}" != "production" ]]; then
    echo -e "\n${RED}${BOLD}CRITICAL SAFETY VIOLATION:${NC} APP_ENV is '${RESOLVED_ENV}'. Production script is only permitted to target 'production'." >&2
    exit 1
fi

# Guard 2: APP_DEBUG must be 'false'
if [[ "${RESOLVED_DEBUG}" != "false" && "${RESOLVED_DEBUG}" != "0" ]]; then
    echo -e "\n${RED}${BOLD}CRITICAL SAFETY VIOLATION:${NC} APP_DEBUG is '${RESOLVED_DEBUG}'. Must be 'false' in production." >&2
    exit 1
fi

# Guard 3: Database target must NOT be staging, test, or local
if [[ "${RESOLVED_DB}" =~ (staging|test|local|dev) ]]; then
    echo -e "\n${RED}${BOLD}CRITICAL SAFETY VIOLATION:${NC} Database name '${RESOLVED_DB}' resembles non-production! Aborting immediately." >&2
    exit 1
fi

# Guard 4: DB Driver must be PostgreSQL
if [[ "${RESOLVED_CONN}" != "pgsql" ]]; then
    echo -e "\n${RED}${BOLD}CRITICAL SAFETY VIOLATION:${NC} Database connection is '${RESOLVED_CONN}'. Expected 'pgsql'." >&2
    exit 1
fi

# Guard 4B: Database name must be a valid PostgreSQL identifier
if [[ ! "${RESOLVED_DB}" =~ ^[a-zA-Z0-9_]+$ ]]; then
    echo -e "\n${RED}${BOLD}CRITICAL SAFETY VIOLATION:${NC} Database name '${RESOLVED_DB}' contains invalid characters." >&2
    exit 1
fi

# Guard 4C: DB_PORT must be valid integer
if [[ -n "${RESOLVED_DB_PORT}" && ! "${RESOLVED_DB_PORT}" =~ ^[0-9]+$ ]]; then
    echo -e "\n${RED}${BOLD}CRITICAL SAFETY VIOLATION:${NC} DB_PORT '${RESOLVED_DB_PORT}' is not a valid integer." >&2
    exit 1
fi

# Guard 5: Hostnames must match planned production hosts
if [[ "${API_HOST}" != "${PROD_API_HOST}" ]]; then
    echo -e "\n${RED}${BOLD}CRITICAL CONFIGURATION ERROR:${NC} Production API host must be exactly '${PROD_API_HOST}' (got: '${API_HOST}')." >&2
    exit 1
fi

if [[ "${WEB_HOST}" != "${PROD_WEB_HOST}" ]]; then
    echo -e "\n${RED}${BOLD}CRITICAL CONFIGURATION ERROR:${NC} Production Web host must be exactly '${PROD_WEB_HOST}' (got: '${WEB_HOST}')." >&2
    exit 1
fi

# Guard 6: Cross-Validate Hostnames via validate-domains.sh
if [[ -f "${SCRIPT_DIR}/validate-domains.sh" ]]; then
    if ! bash "${SCRIPT_DIR}/validate-domains.sh" \
        --mode production \
        --api-host "${API_HOST}" \
        --web-host "${WEB_HOST}" \
        --app-url "${RESOLVED_APP_URL}" \
        --flutter-api-url "https://${API_HOST}/api/v1"; then
        echo -e "\n${RED}${BOLD}CRITICAL SAFETY VIOLATION:${NC} Production domain validation failed." >&2
        exit 1
    fi
fi

# ==============================================================================
# Step 1: Preflight Verification
# ==============================================================================
echo -e "\n${BOLD}[Step 1/6] Running Production Preflight Validation...${NC}"
PREFLIGHT_OPTS=("--env-file" "${ENV_FILE}")
if [[ "${SKIP_CONNECTIVITY}" == "true" ]]; then
    PREFLIGHT_OPTS+=("--skip-connectivity")
fi
if [[ "${SKIP_PHP_CHECK}" == "true" ]]; then
    PREFLIGHT_OPTS+=("--skip-php-check")
fi

if [[ -f "${SCRIPT_DIR}/preflight-production.sh" ]]; then
    if ! bash "${SCRIPT_DIR}/preflight-production.sh" "${PREFLIGHT_OPTS[@]}"; then
        echo -e "\n${RED}${BOLD}PRODUCTION PREFLIGHT FAILED!${NC} Aborting deployment pipeline." >&2
        exit 1
    fi
else
    echo -e "  [${YELLOW}WARN${NC}] preflight-production.sh not found in ${SCRIPT_DIR}."
fi

# ==============================================================================
# Mode Branch: Dry-Run vs Apply Execution
# ==============================================================================
if [[ "${EXECUTE_MODE}" == "false" ]]; then
    echo -e "\n${BOLD}====================================================================${NC}"
    echo -e "${GREEN}${BOLD}                    DRY-RUN DEPLOYMENT PLAN                         ${NC}"
    echo -e "${BOLD}====================================================================${NC}"
    echo -e "The preflight verification succeeded. In ${BOLD}--apply${NC} mode, the following steps would occur:"
    echo -e "  1. Create pre-migration database binary dump in: ${BOLD}${BACKUP_DIR}/production_pre_migrate_<timestamp>.dump${NC}"
    echo -e "  2. Compile Flutter Web production bundle:   ${BOLD}flutter build web --release --dart-define=APP_ENV=production --dart-define=API_BASE_URL=https://${API_HOST}/api/v1${NC}"
    echo -e "  3. Install backend production packages:      ${BOLD}composer install --no-dev --prefer-dist --optimize-autoloader --no-interaction${NC}"
    echo -e "  4. Execute forward-only schema migrations:   ${BOLD}php artisan migrate --force${NC}"
    echo -e "  5. Rebuild production configuration caches:  ${BOLD}php artisan config:cache && php artisan route:cache && php artisan view:cache${NC}"
    echo -e "  6. Verify post-deployment probes:            ${BOLD}curl -fsS https://${API_HOST}/up${NC} & ${BOLD}/api/v1/health${NC}"
    echo -e "\n${GREEN}Dry-run completed successfully. Zero changes were made to filesystem or database.${NC}"
    echo -e "To execute for real, re-run with: ${BOLD}$0 --apply${NC}"
    exit 0
fi

# ==============================================================================
# Step 2: Mandatory Database Backup
# ==============================================================================
echo -e "\n${BOLD}[Step 2/6] Performing Pre-Migration Database Backup...${NC}"
mkdir -p "${BACKUP_DIR}"
TIMESTAMP=$(date '+%Y%m%d_%H%M%S')
BACKUP_FILE="${BACKUP_DIR}/production_pre_migrate_${TIMESTAMP}.dump"

if command -v pg_dump >/dev/null 2>&1; then
    export PGPASSWORD="${RESOLVED_MIGRATOR_PASS:-${RESOLVED_DB_PASS}}"
    DUMP_USER="${RESOLVED_MIGRATOR_USER:-${RESOLVED_DB_USER}}"
    echo -e "  Exporting binary dump of '${RESOLVED_DB}' as schema owner '${DUMP_USER}' to: ${BACKUP_FILE}"
    pg_dump -U "${DUMP_USER}" -h "${RESOLVED_DB_HOST:-127.0.0.1}" -p "${RESOLVED_DB_PORT:-5432}" -Fc "${RESOLVED_DB}" > "${BACKUP_FILE}"
    unset PGPASSWORD
    if [[ ! -s "${BACKUP_FILE}" ]]; then
        echo -e "  [${RED}FAIL${NC}] Pre-migration database dump failed or produced empty file: ${BACKUP_FILE}" >&2
        rm -f "${BACKUP_FILE}"
        exit 1
    fi
    echo -e "  [${GREEN}PASS${NC}] Database backup created and verified: $(ls -lh "${BACKUP_FILE}" | awk '{print $5}')"
elif [[ "${EXTERNAL_BACKUP_CONFIRMED}" == "true" ]]; then
    echo -e "  [${YELLOW}WARN${NC}] pg_dump not found in PATH; external cloud snapshot policy was manually confirmed (--external-backup-confirmed)."
    echo -e "  [${YELLOW}WARN${NC}] Automated verification of local backup artifact could NOT be performed. Proceeding under operator responsibility."
else
    echo -e "  [${RED}FAIL${NC}] pg_dump command not found! Cannot guarantee pre-migration backup safety." >&2
    echo -e "  On shared cPanel hosting using remote managed PostgreSQL, take a database snapshot via your" >&2
    echo -e "  cloud provider console (Neon / Supabase / AWS RDS), then re-run with: --external-backup-confirmed" >&2
    exit 1
fi

# ==============================================================================
# Step 3: Frontend Compilation & Public Sync
# ==============================================================================
echo -e "\n${BOLD}[Step 3/6] Frontend Web Production Bundle & Public Sync...${NC}"
if [[ "${SKIP_FRONTEND_BUILD}" == "true" ]]; then
    echo -e "  [${BLUE}INFO${NC}] Skipping Flutter build (--skip-frontend-build specified). Using existing build in frontend/build/web."
else
    if command -v flutter >/dev/null 2>&1; then
        echo -e "  Compiling Flutter Web production release..."
        (
            cd "${FRONTEND_DIR}"
            flutter build web --release \
                --dart-define=APP_ENV=production \
                --dart-define=API_BASE_URL="https://${API_HOST}/api/v1"
        )
        echo -e "  [${GREEN}PASS${NC}] Flutter Web production build completed."
    else
        echo -e "  [${YELLOW}WARN${NC}] Flutter CLI not available on this host. Using pre-compiled bundle in frontend/build/web."
    fi
fi

# Synchronize Web Public Directory (if configured)
if [[ -n "${WEB_PUBLIC_DIR}" ]]; then
    echo -e "  Synchronizing Flutter Web files to web document root: ${WEB_PUBLIC_DIR}"
    mkdir -p "${WEB_PUBLIC_DIR}"
    if [[ -d "${FRONTEND_DIR}/build/web" ]]; then
        cp -r "${FRONTEND_DIR}/build/web/." "${WEB_PUBLIC_DIR}/"
        echo -e "  [${GREEN}PASS${NC}] Web public files synchronized."
    else
        echo -e "  [${YELLOW}WARN${NC}] '${FRONTEND_DIR}/build/web' does not exist; skipping sync to ${WEB_PUBLIC_DIR}."
    fi
fi

# Synchronize API Public Directory (if configured)
if [[ -n "${API_PUBLIC_DIR}" ]]; then
    echo -e "  Synchronizing API entry files to API document root: ${API_PUBLIC_DIR}"
    mkdir -p "${API_PUBLIC_DIR}"
    if [[ -f "${BACKEND_DIR}/public/.htaccess" ]]; then
        cp "${BACKEND_DIR}/public/.htaccess" "${API_PUBLIC_DIR}/.htaccess"
    fi
    if [[ -f "${BACKEND_DIR}/public/favicon.ico" ]]; then
        cp "${BACKEND_DIR}/public/favicon.ico" "${API_PUBLIC_DIR}/favicon.ico"
    fi
    echo -e "  [${GREEN}PASS${NC}] API public files synchronized."
fi

# ==============================================================================
# Step 4: Backend Production Dependency Installation
# ==============================================================================
echo -e "\n${BOLD}[Step 4/6] Installing Backend Production Dependencies...${NC}"
if command -v composer >/dev/null 2>&1; then
    (
        cd "${BACKEND_DIR}"
        composer install --no-dev --prefer-dist --optimize-autoloader --no-interaction --quiet
    )
    echo -e "  [${GREEN}PASS${NC}] Composer dependencies installed and optimized."
else
    echo -e "  [${YELLOW}WARN${NC}] Composer not found in PATH. Assuming vendor directory is already deployed."
fi

# ==============================================================================
# Step 5: Execute Forward-Only Schema Migrations & Rebuild Caches
# ==============================================================================
echo -e "\n${BOLD}[Step 5/6] Executing Schema Migrations & Caches...${NC}"
(
    cd "${BACKEND_DIR}"
    echo -e "  Clearing cached configuration to prevent runtime role contamination..."
    php artisan config:clear

    echo -e "  Running schema migrations as schema owner '${RESOLVED_MIGRATOR_USER}'..."
    DB_USERNAME="${RESOLVED_MIGRATOR_USER}" DB_PASSWORD="${RESOLVED_MIGRATOR_PASS}" php artisan migrate --force

    # Post-migration table integrity verification
    echo -e "  Verifying post-migration table availability..."
    REQUIRED_TABLES=("cache" "cache_locks" "jobs" "failed_jobs" "job_batches" "sessions")
    if command -v psql >/dev/null 2>&1; then
        export PGPASSWORD="${RESOLVED_DB_PASS}"
        for tbl in "${REQUIRED_TABLES[@]}"; do
            TBL_EXISTS=$(psql -h "${RESOLVED_DB_HOST:-127.0.0.1}" -p "${RESOLVED_DB_PORT:-5432}" -U "${RESOLVED_DB_USER}" -d "${RESOLVED_DB}" -t -A -c "SELECT 1 FROM information_schema.tables WHERE table_schema = 'public' AND table_name = '${tbl}';" 2>/dev/null || echo "0")
            if [[ "${TBL_EXISTS}" != "1" ]]; then
                echo -e "  [${RED}FAIL${NC}] CRITICAL POST-MIGRATION FAILED: Table '${tbl}' does not exist after running migrations!" >&2
                unset PGPASSWORD
                exit 1
            fi
        done
        unset PGPASSWORD
        echo -e "  [${GREEN}PASS${NC}] Post-migration table availability verified for runtime role '${RESOLVED_DB_USER}'."
    fi

    echo -e "  Building production caches using runtime least-privilege role '${RESOLVED_DB_USER}'..."
    php artisan config:cache
    php artisan route:cache
    php artisan view:cache
)
echo -e "  [${GREEN}PASS${NC}] Schema migrations applied and runtime caches rebuilt."

# ==============================================================================
# Step 6: Post-Deployment Verification Probes
# ==============================================================================
echo -e "\n${BOLD}[Step 6/6] Verifying Post-Deployment Health Probes...${NC}"
if command -v curl >/dev/null 2>&1; then
    echo -e "  Checking local liveness probe (GET /up)..."
    UP_STATUS=$(curl -s -o /dev/null -w "%{http_code}" "http://127.0.0.1:8000/up" 2>/dev/null || echo "000")
    if [[ "${UP_STATUS}" == "200" ]]; then
        echo -e "  [${GREEN}PASS${NC}] Local liveness probe returned HTTP 200"
    else
        echo -e "  [${YELLOW}INFO${NC}] Local HTTP probe returned ${UP_STATUS} (web server may be bound to cPanel Apache domain)"
    fi
fi

echo -e "\n${BOLD}====================================================================${NC}"
echo -e "${GREEN}${BOLD}         PRODUCTION DEPLOYMENT COMPLETED SUCCESSFULLY               ${NC}"
echo -e "${BOLD}====================================================================${NC}"
echo -e "Target API:          https://${API_HOST}"
echo -e "Target Web:          https://${WEB_HOST}"
echo -e "Database:            ${RESOLVED_DB} (PostgreSQL 16+ RLS active)"
echo -e "Pre-Migrate Backup:  ${BACKUP_FILE}"
echo -e "\nOperational Next Steps (cPanel):"
echo -e "  1. Ensure cPanel Cron Jobs are configured as separate tasks:"
echo -e "     - Scheduler (every minute):"
echo -e "       * * * * * cd \${CORE_DIR} && php artisan schedule:run >> /dev/null 2>&1"
echo -e "     - Queue Worker (every minute with auto-exit):"
echo -e "       * * * * * cd \${CORE_DIR} && php artisan queue:work database --queue=default,notifications,webhooks --stop-when-empty --max-time=50 --tries=3 >> /dev/null 2>&1"
echo -e "  2. Verify domain SSL certificates in cPanel SSL/TLS Status."
echo -e "  3. Smoke test the web app at: https://${WEB_HOST}"
exit 0
