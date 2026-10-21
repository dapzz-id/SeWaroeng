#!/usr/bin/env bash
# ==============================================================================
# SeWaroeng — Guarded Staging Deployment Script
# Safe, forward-only deployment pipeline with dry-run default guard.
# Target: Ubuntu 22.04+ LTS, PHP 8.4-FPM, PostgreSQL 16+, Redis 7+
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
    echo -e "\n${RED}${BOLD}DEPLOYMENT FAILED!${NC}"
    echo -e "Failed at line ${line_num} while executing: ${command}"
    echo -e "Exit status: ${exit_code}"
    echo -e "Target Environment: ${RESOLVED_ENV:-unknown}"
    echo -e "Target Database:    ${RESOLVED_DB:-unknown}"
    echo -e "Any completed steps prior to failure are retained. Resolve the issue and re-run."
    unset PGPASSWORD 2>/dev/null || true
    exit "${exit_code}"
}
trap 'on_error $? $LINENO "$BASH_COMMAND"' ERR

# Allow override if deployed to /var/www/sewaroeng
if [[ -d "/var/www/sewaroeng/backend" && "${PROJECT_ROOT}" != "/var/www/sewaroeng" ]]; then
    if [[ "${USE_CANONICAL_PATH:-false}" == "true" ]]; then
        PROJECT_ROOT="/var/www/sewaroeng"
    fi
fi

BACKEND_DIR="${PROJECT_ROOT}/backend"
FRONTEND_DIR="${PROJECT_ROOT}/frontend"
BACKUP_DIR="${BACKUP_DIR:-/var/backups/sewaroeng}"

# 1. Parse Arguments (Default is DRY-RUN mode)
EXECUTE_MODE=false
MODE_CHOSEN=""
SKIP_FRONTEND_BUILD=false
SKIP_BACKUP=false
SKIP_CONNECTIVITY=false
SKIP_PHP_CHECK=false
STAGING_API_HOST="${STAGING_API_HOST:-}"
STAGING_WEB_HOST="${STAGING_WEB_HOST:-}"
ENV_FILE="${ENV_FILE:-${BACKEND_DIR}/.env}"

API_HOST_SET=false
WEB_HOST_SET=false
ENV_FILE_SET=false

print_usage() {
    echo -e "${BOLD}Usage:${NC} $0 [OPTIONS]"
    echo ""
    echo "Options:"
    echo "  --dry-run                 Run preflight checks and output deployment plan without mutating state (DEFAULT)"
    echo "  --apply, --execute        Execute the deployment steps (mutates dependencies, schema, and caches)"
    echo "  --api-host <FQDN>         Staging backend API hostname (required, or via STAGING_API_HOST)"
    echo "  --web-host <FQDN>         Staging frontend Web hostname (required, or via STAGING_WEB_HOST)"
    echo "  --env-file <path>         Path to environment file to validate (default: backend/.env)"
    echo "  --skip-connectivity       Skip network connectivity checks in preflight (DRY-RUN / simulation ONLY)"
    echo "  --skip-php-check          Skip PHP runtime check in preflight (DRY-RUN / simulation ONLY)"
    echo "  --skip-frontend-build     Skip Flutter Web build (use existing compiled bundle in frontend/build/web)"
    echo "  --skip-backup             Skip pre-migration database dump (CAUTION: Not recommended)"
    echo "  -h, --help                Show this help message"
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
            if [[ "${API_HOST_SET}" == "true" && "${STAGING_API_HOST}" != "$2" ]]; then
                echo -e "${RED}Error: Conflicting --api-host options specified ('${STAGING_API_HOST}' vs '$2').${NC}" >&2
                exit 1
            fi
            STAGING_API_HOST="$2"
            API_HOST_SET=true
            shift 2
            ;;
        --web-host)
            if [[ $# -lt 2 || -z "$2" || "$2" == --* ]]; then
                echo -e "${RED}Error: --web-host requires a non-empty hostname argument.${NC}" >&2
                exit 1
            fi
            if [[ "${WEB_HOST_SET}" == "true" && "${STAGING_WEB_HOST}" != "$2" ]]; then
                echo -e "${RED}Error: Conflicting --web-host options specified ('${STAGING_WEB_HOST}' vs '$2').${NC}" >&2
                exit 1
            fi
            STAGING_WEB_HOST="$2"
            WEB_HOST_SET=true
            shift 2
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

# ==============================================================================
# Safety Gate: Validate Execution Mode and Bypass Guard
# ==============================================================================
if [[ "${EXECUTE_MODE}" == "true" ]]; then
    if [[ "${SKIP_CONNECTIVITY}" == "true" ]]; then
        echo -e "\n${RED}${BOLD}CRITICAL SAFETY VIOLATION:${NC} --skip-connectivity is strictly forbidden in --apply / --execute mode." >&2
        echo -e "Live deployments must verify all database and Redis connectivity prerequisites." >&2
        exit 1
    fi
    if [[ "${SKIP_PHP_CHECK}" == "true" ]]; then
        echo -e "\n${RED}${BOLD}CRITICAL SAFETY VIOLATION:${NC} --skip-php-check is strictly forbidden in --apply / --execute mode." >&2
        echo -e "Live deployments must verify that PHP 8.4 runtime and required extensions are installed." >&2
        exit 1
    fi
fi

# ==============================================================================
# Safety Gate: Environment File Integrity & Test Fixture Lockout
# ==============================================================================
if [[ ! -e "${ENV_FILE}" ]]; then
    echo -e "\n${RED}${BOLD}ABORTED:${NC} Environment file not found: ${ENV_FILE}" >&2
    exit 1
fi

if [[ -d "${ENV_FILE}" ]]; then
    echo -e "\n${RED}${BOLD}ABORTED:${NC} Environment path is a directory, not a regular file: ${ENV_FILE}" >&2
    exit 1
fi

if [[ ! -r "${ENV_FILE}" ]]; then
    echo -e "\n${RED}${BOLD}ABORTED:${NC} Environment file is not readable: ${ENV_FILE}" >&2
    exit 1
fi

if [[ ! -s "${ENV_FILE}" ]]; then
    echo -e "\n${RED}${BOLD}ABORTED:${NC} Environment file is empty: ${ENV_FILE}" >&2
    exit 1
fi

validate_env_file_syntax() {
    local file="$1"
    if [[ ! -f "${file}" ]]; then
        echo -e "${RED}Error: Environment file not found: ${file}${NC}" >&2
        return 1
    fi
    if [[ ! -r "${file}" ]]; then
        echo -e "${RED}Error: Environment file is not readable: ${file}${NC}" >&2
        return 1
    fi
    if [[ ! -s "${file}" ]]; then
        echo -e "${RED}Error: Environment file is empty: ${file}${NC}" >&2
        return 1
    fi
    # Check for binary / non-printable characters
    if grep -q '[^[:print:][:space:]]' "${file}" 2>/dev/null; then
        echo -e "${RED}Error: Environment file '${file}' contains binary or non-printable characters.${NC}" >&2
        return 1
    fi
    local line_num=0
    while IFS= read -r line || [[ -n "${line}" ]]; do
        line_num=$((line_num + 1))
        line="${line%$'\r'}"
        local trimmed="${line#"${line%%[![:space:]]*}"}"
        [[ -z "${trimmed}" || "${trimmed}" =~ ^# ]] && continue
        if [[ ! "${trimmed}" =~ ^[a-zA-Z_][a-zA-Z0-9_]*= ]]; then
            echo -e "${RED}Error: Environment file line ${line_num} violates KEY=VALUE format: '${trimmed:0:40}'${NC}" >&2
            return 1
        fi
        local val_part="${trimmed#*=}"
        # Parity Check 1: Unclosed quotes
        if [[ "${val_part}" =~ ^\" && ! "${val_part}" =~ \"[[:space:]]*(#.*)?$ ]]; then
            echo -e "${RED}Error: Environment file line ${line_num} has unclosed double quote: '${val_part:0:40}'${NC}" >&2
            return 1
        fi
        if [[ "${val_part}" =~ ^\' && ! "${val_part}" =~ \'[[:space:]]*(#.*)?$ ]]; then
            echo -e "${RED}Error: Environment file line ${line_num} has unclosed single quote: '${val_part:0:40}'${NC}" >&2
            return 1
        fi
        # Parity Check 2: Unquoted whitespace (Dotenv strictly forbids unquoted values with spaces)
        if [[ ! "${val_part}" =~ ^[\"\'] ]]; then
            local without_comment="${val_part%%#*}"
            without_comment="${without_comment%"${without_comment##*[![:space:]]}"}"
            if [[ "${without_comment}" =~ [[:space:]] ]]; then
                echo -e "${RED}Error: Environment file line ${line_num} has unquoted whitespace in value: '${val_part:0:40}' (Dotenv requires values containing spaces to be enclosed in quotes).${NC}" >&2
                return 1
            fi
        fi
    done < "${file}"
    return 0
}

if ! validate_env_file_syntax "${ENV_FILE}"; then
    echo -e "\n${RED}${BOLD}ABORTED:${NC} Environment file syntax validation failed: ${ENV_FILE}" >&2
    exit 1
fi

is_test_fixture() {
    local file="$1"
    if [[ "${file}" =~ (fixture|fixtures|\.example|\.test|\.stub) ]]; then
        return 0
    fi
    if [[ ! -f "${file}" ]]; then
        return 1
    fi
    if grep -q -E "^(IS_DEPLOYMENT_FIXTURE=true|DEPLOYMENT_FIXTURE_PURPOSE=)" "${file}" 2>/dev/null; then
        return 0
    fi
    if grep -q "staging_fixture_dummy_password" "${file}" 2>/dev/null; then
        return 0
    fi
    if grep -q "test-fixture.internal" "${file}" 2>/dev/null; then
        return 0
    fi
    if grep -q "base64:u1vOq9P0r2s4t6u8v0w2x4y6z8A0B2C4D6E8F0G2H4I=" "${file}" 2>/dev/null; then
        return 0
    fi
    return 1
}

IS_FIXTURE_CONFIG=false
if is_test_fixture "${ENV_FILE}"; then
    IS_FIXTURE_CONFIG=true
fi

# Lockout: Fixtures cannot authorize live deployment
if [[ "${EXECUTE_MODE}" == "true" && "${IS_FIXTURE_CONFIG}" == "true" ]]; then
    echo -e "\n${RED}${BOLD}CRITICAL SAFETY VIOLATION:${NC} Test fixture detected as environment configuration during --apply mode." >&2
    echo -e "Source: ${ENV_FILE}" >&2
    echo -e "Test fixtures are strictly limited to local dry-run simulations. Live deployments must supply an authentic staging configuration file." >&2
    exit 1
fi

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

RESOLVED_ENV=$(get_env_val "APP_ENV")
RESOLVED_DB=$(get_env_val "DB_DATABASE")
RESOLVED_CONN=$(get_env_val "DB_CONNECTION")
RESOLVED_DEBUG=$(get_env_val "APP_DEBUG")
RESOLVED_APP_URL=$(get_env_val "APP_URL")
RESOLVED_DB_HOST=$(get_env_val "DB_HOST")
RESOLVED_DB_PORT=$(get_env_val "DB_PORT")

RESOLVED_ENV_PATH="$(cd "$(dirname "${ENV_FILE}")" 2>/dev/null && pwd)/$(basename "${ENV_FILE}")"

echo -e "${BOLD}====================================================================${NC}"
echo -e "${BOLD}           SeWaroeng Staging Deployment Pipeline                    ${NC}"
echo -e "${BOLD}====================================================================${NC}"
echo -e "Timestamp:   $(date -u '+%Y-%m-%d %H:%M:%SZ')"
echo -e "Root:        ${PROJECT_ROOT}"
echo -e "Env Source:  ${RESOLVED_ENV_PATH}"
echo -e "Env Type:    $([[ "${IS_FIXTURE_CONFIG}" == "true" ]] && echo "${CYAN}TEST_FIXTURE (Dry-run / Simulation Only)${NC}" || echo "${GREEN}STANDARD_CONFIG${NC}")"

if [[ "${EXECUTE_MODE}" == "false" ]]; then
    echo -e "Mode:        ${CYAN}${BOLD}DRY-RUN (Preflight & Plan Only - No State Changed)${NC}"
else
    echo -e "Mode:        ${YELLOW}${BOLD}APPLY / EXECUTE (Mutating State On Target)${NC}"
fi

echo -e "\n${BOLD}[Environment Target Confirmation]${NC}"
echo -e "  APP_ENV:     ${RESOLVED_ENV}"
echo -e "  APP_DEBUG:   ${RESOLVED_DEBUG}"
echo -e "  APP_URL:     ${RESOLVED_APP_URL}"
echo -e "  DB_CONN:     ${RESOLVED_CONN}"
echo -e "  DB_HOST:     ${RESOLVED_DB_HOST:-127.0.0.1}:${RESOLVED_DB_PORT:-5432}"
echo -e "  DB_DATABASE: ${RESOLVED_DB}"

# Guard 1: APP_ENV must be strictly 'staging'
if [[ "${RESOLVED_ENV}" != "staging" ]]; then
    echo -e "\n${RED}${BOLD}CRITICAL SAFETY VIOLATION:${NC} APP_ENV is '${RESOLVED_ENV}'. Staging script is only permitted to target 'staging'." >&2
    echo -e "Refusing to execute on non-staging environment." >&2
    exit 1
fi

# Guard 2: APP_DEBUG must be 'false'
if [[ "${RESOLVED_DEBUG}" != "false" && "${RESOLVED_DEBUG}" != "0" ]]; then
    echo -e "\n${RED}${BOLD}CRITICAL SAFETY VIOLATION:${NC} APP_DEBUG is '${RESOLVED_DEBUG}'. Must be 'false' to prevent stack trace leaks." >&2
    exit 1
fi

# Guard 3: Database target must be confirmed non-production
if [[ "${RESOLVED_DB}" =~ (prod|production|live) ]]; then
    echo -e "\n${RED}${BOLD}CRITICAL SAFETY VIOLATION:${NC} Database name '${RESOLVED_DB}' resembles production! Aborting immediately." >&2
    exit 1
fi

# Guard 4: DB Driver must be PostgreSQL
if [[ "${RESOLVED_CONN}" != "pgsql" ]]; then
    echo -e "\n${RED}${BOLD}CRITICAL SAFETY VIOLATION:${NC} Database connection is '${RESOLVED_CONN}'. Expected 'pgsql'." >&2
    exit 1
fi

# Guard 4B: Database name must be a valid PostgreSQL identifier (alphanumeric and underscore)
if [[ ! "${RESOLVED_DB}" =~ ^[a-zA-Z0-9_]+$ ]]; then
    echo -e "\n${RED}${BOLD}CRITICAL SAFETY VIOLATION:${NC} Database name '${RESOLVED_DB}' contains invalid characters. Must be a valid identifier (^[a-zA-Z0-9_]+$). Aborting." >&2
    exit 1
fi

# Guard 4C: DB_PORT must be valid integer
if [[ -n "${RESOLVED_DB_PORT}" && ! "${RESOLVED_DB_PORT}" =~ ^[0-9]+$ ]]; then
    echo -e "\n${RED}${BOLD}CRITICAL SAFETY VIOLATION:${NC} DB_PORT '${RESOLVED_DB_PORT}' is not a valid integer." >&2
    exit 1
fi

# Guard 5: Staging Hostnames Explicitly Required
if [[ -z "${STAGING_API_HOST}" || -z "${STAGING_WEB_HOST}" ]]; then
    echo -e "\n${RED}${BOLD}CRITICAL CONFIGURATION ERROR:${NC} Staging hostnames are not configured." >&2
    echo -e "Staging hostnames must be explicitly supplied via:" >&2
    echo -e "  1. CLI arguments:      --api-host <staging-api-host> --web-host <staging-web-host>" >&2
    echo -e "  2. Environment vars:   export STAGING_API_HOST=<...> STAGING_WEB_HOST=<...>" >&2
    echo -e "Guessing or defaulting unselected staging domains is strictly prohibited." >&2
    exit 1
fi

# Guard 6: Cross-Validate Hostnames and Isolation from Production
if [[ -f "${SCRIPT_DIR}/validate-domains.sh" ]]; then
    if ! bash "${SCRIPT_DIR}/validate-domains.sh" \
        --mode staging \
        --api-host "${STAGING_API_HOST}" \
        --web-host "${STAGING_WEB_HOST}" \
        --app-url "${RESOLVED_APP_URL}" \
        --flutter-api-url "https://${STAGING_API_HOST}/api/v1"; then
        echo -e "\n${RED}${BOLD}CRITICAL SAFETY VIOLATION:${NC} Staging domain validation failed." >&2
        exit 1
    fi
fi

# ==============================================================================
# Step 1: Preflight Verification
# ==============================================================================
echo -e "\n${BOLD}[Step 1/7] Running Preflight Validation...${NC}"

if [[ -f "${SCRIPT_DIR}/preflight.sh" ]]; then
    PREFLIGHT_ARGS=(--env-file "${ENV_FILE}")
    if [[ "${EXECUTE_MODE}" == "true" ]]; then
        PREFLIGHT_ARGS+=(--strict)
    else
        if [[ "${SKIP_CONNECTIVITY}" == "true" ]]; then
            PREFLIGHT_ARGS+=(--skip-connectivity)
        fi
        if [[ "${SKIP_PHP_CHECK}" == "true" ]]; then
            PREFLIGHT_ARGS+=(--skip-php-check)
        fi
    fi

    if ! bash "${SCRIPT_DIR}/preflight.sh" "${PREFLIGHT_ARGS[@]}"; then
        echo -e "\n${RED}${BOLD}ABORTED:${NC} Preflight checks failed. Correct the errors before deploying." >&2
        exit 1
    fi
else
    echo -e "${YELLOW}Warning: preflight.sh not found in ${SCRIPT_DIR}, performing basic environment checks...${NC}"
fi

# If in DRY-RUN mode, print execution plan and exit safely
if [[ "${EXECUTE_MODE}" == "false" ]]; then
    echo -e "\n${CYAN}${BOLD}=== DRY-RUN DEPLOYMENT PLAN ===${NC}"
    echo -e "Target API Host: ${BOLD}${STAGING_API_HOST}${NC}"
    echo -e "Target Web Host: ${BOLD}${STAGING_WEB_HOST}${NC}"
    echo -e "The following operations WILL be executed when invoked with ${YELLOW}--apply${NC}:"
    echo -e "  1. Install locked Composer dependencies: ${BOLD}composer install --no-dev --prefer-dist --no-interaction --optimize-autoloader${NC}"
    if [[ "${SKIP_FRONTEND_BUILD}" == "false" ]]; then
        echo -e "  2. Compile Flutter Web staging bundle:   ${BOLD}flutter build web --dart-define=APP_ENV=staging --dart-define=API_BASE_URL=https://${STAGING_API_HOST}/api/v1${NC}"
    else
        echo -e "  2. Skip Flutter Web build:               (Using pre-existing bundle in frontend/build/web)"
    fi
    if [[ "${SKIP_BACKUP}" == "false" ]]; then
        echo -e "  3. Binary database backup:               ${BOLD}pg_dump -U <user> -Fc ${RESOLVED_DB} > ${BACKUP_DIR}/pre_migrate_<timestamp>.dump${NC}"
    else
        echo -e "  3. Binary database backup:               SKIPPED via --skip-backup"
    fi
    echo -e "  4. Forward-only database migration:      ${BOLD}php artisan migrate --force${NC}"
    echo -e "  5. Optimize and cache Laravel config:    ${BOLD}config:cache, route:cache, view:cache, event:cache${NC}"
    echo -e "  6. Graceful queue worker restart:        ${BOLD}php artisan queue:restart${NC} (and systemctl restart sewaroeng-worker if available)"
    echo -e "  7. Post-deployment probe verification:   ${BOLD}GET /up (liveness) & GET /api/v1/health (readiness)${NC}"
    echo -e "\n${GREEN}${BOLD}Dry-run verification completed successfully.${NC}"
    echo -e "To execute these changes on staging, run:"
    echo -e "  ${YELLOW}$0 --apply${NC}\n"
    exit 0
fi

# ==============================================================================
# Step 2: Install Composer Production Dependencies
# ==============================================================================
echo -e "\n${BOLD}[Step 2/7] Installing locked PHP dependencies (--no-dev)...${NC}"
(
    cd "${BACKEND_DIR}"
    composer install --no-dev --prefer-dist --no-interaction --optimize-autoloader
)
echo -e "${GREEN}PHP dependencies installed successfully.${NC}"

# ==============================================================================
# Step 3: Frontend Web Asset Build
# ==============================================================================
echo -e "\n${BOLD}[Step 3/7] Preparing Flutter Web Staging Release Bundle...${NC}"
if [[ "${SKIP_FRONTEND_BUILD}" == "true" ]]; then
    if [[ -f "${FRONTEND_DIR}/build/web/index.html" ]]; then
        echo -e "${GREEN}Using existing Flutter Web bundle at ${FRONTEND_DIR}/build/web.${NC}"
    else
        echo -e "${RED}${BOLD}ERROR:${NC} --skip-frontend-build requested, but ${FRONTEND_DIR}/build/web/index.html does not exist!"
        exit 1
    fi
else
    if command -v flutter >/dev/null 2>&1; then
        (
            cd "${FRONTEND_DIR}"
            flutter pub get
            flutter build web --release \
                --dart-define=APP_ENV=staging \
                --dart-define=API_BASE_URL=https://${STAGING_API_HOST}/api/v1
        )
        echo -e "${GREEN}Flutter Web staging bundle compiled successfully.${NC}"
    else
        if [[ -f "${FRONTEND_DIR}/build/web/index.html" ]]; then
            echo -e "${YELLOW}Flutter SDK not found on host, but pre-built bundle exists. Proceeding with existing bundle.${NC}"
        else
            echo -e "${RED}${BOLD}ERROR:${NC} Flutter SDK not found and no pre-built bundle exists at ${FRONTEND_DIR}/build/web."
            echo -e "Remediation: Build frontend bundle in CI or install Flutter SDK on deployment host."
            exit 1
        fi
    fi
fi

# ==============================================================================
# Step 4: Pre-Migration Backup & Schema Migration
# ==============================================================================
echo -e "\n${BOLD}[Step 4/7] Database Safety & Forward-Only Migration...${NC}"

if [[ "${SKIP_BACKUP}" == "false" ]]; then
    TIMESTAMP=$(date -u '+%Y%m%d_%H%M%S')
    mkdir -p "${BACKUP_DIR}" 2>/dev/null || BACKUP_DIR="/tmp/sewaroeng_backups"
    mkdir -p "${BACKUP_DIR}"
    BACKUP_FILE="${BACKUP_DIR}/pre_migrate_${RESOLVED_DB}_${TIMESTAMP}.dump"

    echo -e "Creating pre-migration binary database backup at: ${BACKUP_FILE}..."
    if ! command -v pg_dump >/dev/null 2>&1; then
        echo -e "${RED}${BOLD}PRE-MIGRATION BACKUP FAILED:${NC} pg_dump binary not found in PATH."
        echo -e "Refusing to run schema migrations without a verified backup. Install postgresql-client or pass --skip-backup if explicitly intended."
        exit 1
    fi

    DB_HOST=$(get_env_val "DB_HOST")
    DB_PORT=$(get_env_val "DB_PORT")
    DB_USER=$(get_env_val "DB_USERNAME")
    export PGPASSWORD=$(get_env_val "DB_PASSWORD")

    if ! pg_dump -h "${DB_HOST}" -p "${DB_PORT}" -U "${DB_USER}" -Fc "${RESOLVED_DB}" > "${BACKUP_FILE}"; then
        unset PGPASSWORD
        rm -f "${BACKUP_FILE}" 2>/dev/null || true
        echo -e "${RED}${BOLD}PRE-MIGRATION BACKUP FAILED:${NC} pg_dump failed to produce backup for '${RESOLVED_DB}'."
        echo -e "Aborting deployment to protect database state. Migrations were NOT run."
        exit 1
    fi
    unset PGPASSWORD
    echo -e "${GREEN}Pre-migration backup completed: $(ls -lh "${BACKUP_FILE}" | awk '{print $5}')${NC}"
else
    echo -e "${YELLOW}Pre-migration database backup skipped via --skip-backup flag.${NC}"
fi

# Pre-migration invariant: Verify btree_gist extension is installed in the target database
echo -e "Verifying required 'btree_gist' extension in target database '${RESOLVED_DB}'..."
HAS_BTREE_GIST=$(php -r "
    require '${BACKEND_DIR}/vendor/autoload.php';
    \$app = require '${BACKEND_DIR}/bootstrap/app.php';
    \$kernel = \$app->make(Illuminate\Contracts\Console\Kernel::class);
    \$kernel->bootstrap();
    try {
        \$stmt = Illuminate\Support\Facades\DB::connection()->getPdo()->query(\"SELECT extname FROM pg_extension WHERE extname = 'btree_gist'\");
        echo \$stmt->fetchColumn() ? 'YES' : 'NO';
    } catch (Throwable \$e) {
        echo 'ERROR: ' . \$e->getMessage();
    }
" 2>/dev/null || echo "PHP_EXEC_ERROR")

if [[ "${HAS_BTREE_GIST}" != "YES" ]]; then
    echo -e "${RED}${BOLD}MIGRATION PREREQUISITE FAILED:${NC} Extension 'btree_gist' is missing from database '${RESOLVED_DB}'."
    echo -e "PostgreSQL exclusion constraints in migrations will fail without btree_gist."
    echo -e "Remediation (run as PostgreSQL superuser):"
    echo -e "  sudo -u postgres psql -d ${RESOLVED_DB} -c \"CREATE EXTENSION IF NOT EXISTS btree_gist;\""
    exit 1
fi
echo -e "${GREEN}Extension 'btree_gist' verified in '${RESOLVED_DB}'.${NC}"

# Pre-migration verification: Check effective database identity
EFFECTIVE_DB=$(php -r "
    require '${BACKEND_DIR}/vendor/autoload.php';
    \$app = require '${BACKEND_DIR}/bootstrap/app.php';
    \$kernel = \$app->make(Illuminate\Contracts\Console\Kernel::class);
    \$kernel->bootstrap();
    try {
        echo Illuminate\Support\Facades\DB::connection()->getPdo()->query('SELECT current_database()')->fetchColumn();
    } catch (Throwable \$e) {
        echo 'ERROR';
    }
" 2>/dev/null || echo "PHP_EXEC_ERROR")

if [[ "${EFFECTIVE_DB}" != "${RESOLVED_DB}" ]]; then
    echo -e "${RED}${BOLD}CRITICAL DATABASE IDENTITY MISMATCH:${NC} Connected to '${EFFECTIVE_DB}' but expecting '${RESOLVED_DB}'."
    echo -e "Aborting migration to prevent cross-database contamination."
    exit 1
fi

if [[ "${EFFECTIVE_DB}" =~ (prod|production|live) || "${EFFECTIVE_DB}" == "sewaroeng" ]]; then
    echo -e "${RED}${BOLD}CRITICAL SAFETY VIOLATION:${NC} Target database '${EFFECTIVE_DB}' is production or development!"
    echo -e "Staging migrations are prohibited from running against '${EFFECTIVE_DB}'."
    exit 1
fi

echo -e "Confirmed target database is '${EFFECTIVE_DB}' (verified non-production staging database)."
echo -e "Executing forward-only database migrations (php artisan migrate --force)..."
(
    cd "${BACKEND_DIR}"
    php artisan migrate --force
)
echo -e "${GREEN}Database migrations applied successfully.${NC}"

# ==============================================================================
# Step 5: Optimization & Caching
# ==============================================================================
echo -e "\n${BOLD}[Step 5/7] Optimizing Laravel Framework Caches...${NC}"
(
    cd "${BACKEND_DIR}"
    php artisan config:cache
    php artisan route:cache
    php artisan view:cache
    php artisan event:cache
)
echo -e "${GREEN}Framework configuration and route caches compiled.${NC}"

# ==============================================================================
# Step 6: Queue Worker Graceful Restart
# ==============================================================================
echo -e "\n${BOLD}[Step 6/7] Signalling Queue Worker Restart...${NC}"
(
    cd "${BACKEND_DIR}"
    php artisan queue:restart
)
echo -e "${GREEN}Signal dispatched: Queue workers will gracefully reload after finishing active jobs.${NC}"

if command -v systemctl >/dev/null 2>&1; then
    if systemctl is-active --quiet sewaroeng-worker.service 2>/dev/null; then
        echo -e "Restarting systemd service sewaroeng-worker.service..."
        systemctl restart sewaroeng-worker.service || echo -e "${YELLOW}Could not restart worker via systemctl (requires sudo?)${NC}"
    fi
fi

# ==============================================================================
# Step 7: Post-Deployment Health & Probe Verification
# ==============================================================================
echo -e "\n${BOLD}[Step 7/7] Post-Deployment Health Probe Verification...${NC}"

HEALTH_CHECK_PASSED=false

# Test via Artisan / Internal PHP Probe (always available without public DNS)
INTERNAL_HEALTH=$(php -r "
    require '${BACKEND_DIR}/vendor/autoload.php';
    \$app = require '${BACKEND_DIR}/bootstrap/app.php';
    \$kernel = \$app->make(Illuminate\Contracts\Console\Kernel::class);
    \$kernel->bootstrap();
    try {
        Illuminate\Support\Facades\DB::connection()->getPdo();
        Illuminate\Support\Facades\Redis::connection()->ping();
        echo 'HEALTH_OK';
    } catch (Throwable \$e) {
        echo 'HEALTH_FAILED: ' . \$e->getMessage();
    }
" 2>/dev/null || echo "INTERNAL_ERROR")

if [[ "${INTERNAL_HEALTH}" == "HEALTH_OK" ]]; then
    echo -e "${GREEN}Internal Health Probe: OK (PostgreSQL PDO & Redis ping confirmed)${NC}"
    HEALTH_CHECK_PASSED=true
else
    echo -e "${RED}Internal Health Probe Failed: ${INTERNAL_HEALTH}${NC}"
fi

# Optional HTTP Probe if curl is available and domain or localhost responds
if command -v curl >/dev/null 2>&1; then
    echo -e "Testing HTTP Liveness probe (http://127.0.0.1/up or ${RESOLVED_APP_URL}/up)..."
    HTTP_CODE=$(curl -s -o /dev/null -w "%{http_code}" "${RESOLVED_APP_URL}/up" 2>/dev/null || echo "000")
    if [[ "${HTTP_CODE}" == "200" ]]; then
        echo -e "${GREEN}HTTP Liveness (/up): HTTP 200 OK${NC}"
    else
        echo -e "${YELLOW}HTTP Liveness probe returned status ${HTTP_CODE} (Nginx/DNS may not be active yet).${NC}"
    fi
fi

# ==============================================================================
# Deployment Summary
# ==============================================================================
echo -e "\n${BOLD}====================================================================${NC}"
if [[ "${HEALTH_CHECK_PASSED}" == "true" ]]; then
    echo -e "${GREEN}${BOLD}       STAGING DEPLOYMENT COMPLETED SUCCESSFULLY                    ${NC}"
else
    echo -e "${YELLOW}${BOLD}   STAGING DEPLOYMENT FINISHED WITH POST-CHECK WARNINGS             ${NC}"
fi
echo -e "${BOLD}====================================================================${NC}"
echo -e "Environment:     ${RESOLVED_ENV}"
echo -e "Database:        ${RESOLVED_DB}"
echo -e "Rollback Note:   If rollback is required, redeploy previous Git release and"
echo -e "                 restore database via: pg_restore -d ${RESOLVED_DB} <backup_file>"
echo -e "Queue Workers:   Supervised by systemd sewaroeng-worker.service"
echo -e "Scheduler:       Supervised by systemd sewaroeng-scheduler.timer / cron\n"
