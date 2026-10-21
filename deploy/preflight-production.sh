#!/usr/bin/env bash
# ==============================================================================
# SeWaroeng — Production Deployment Preflight Safety Script (cPanel Apache / VPS)
# Non-mutating environment, dependency, and configuration readiness inspector.
# Target: Production Environment (sewaroeng_production)
# Planned Hosts:
#   - Backend API: https://api.sewaroeng.neuracakrawira.asia
#   - Frontend Web: https://sewaroeng.neuracakrawira.asia
# ==============================================================================

set -uo pipefail

# ANSI color codes for readable output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
CYAN='\033[0;36m'
BOLD='\033[1m'
NC='\033[0m'

PASSED_COUNT=0
WARN_COUNT=0
FAIL_COUNT=0

log_pass() {
    echo -e "  [${GREEN}PASS${NC}] $1"
    PASSED_COUNT=$((PASSED_COUNT + 1))
}

log_warn() {
    echo -e "  [${YELLOW}WARN${NC}] $1"
    WARN_COUNT=$((WARN_COUNT + 1))
}

log_fail() {
    echo -e "  [${RED}FAIL${NC}] $1"
    FAIL_COUNT=$((FAIL_COUNT + 1))
}

log_info() {
    echo -e "  [${BLUE}INFO${NC}] $1"
}

log_section() {
    echo -e "\n${BOLD}=== $1 ===${NC}"
}

echo -e "${BOLD}====================================================================${NC}"
echo -e "${BOLD}       SeWaroeng Production Deployment Preflight Inspector          ${NC}"
echo -e "${BOLD}====================================================================${NC}"
echo -e "Date:     $(date -u '+%Y-%m-%d %H:%M:%SZ')"
echo -e "Hostname: $(hostname 2>/dev/null || echo 'unknown')"

# 1. Resolve Application Root
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(cd "${SCRIPT_DIR}/.." && pwd)"

BACKEND_DIR="${PROJECT_ROOT}/backend"
FRONTEND_DIR="${PROJECT_ROOT}/frontend"

ENV_FILE="${ENV_FILE:-${BACKEND_DIR}/.env}"
ENV_FILE_SET=false
SKIP_CONNECTIVITY=false
SKIP_PHP_CHECK=false
STRICT_MODE=false

while [[ $# -gt 0 ]]; do
    if [[ -z "$1" ]]; then
        echo -e "${RED}Error: Empty argument passed to command line.${NC}" >&2
        exit 1
    fi

    case "$1" in
        --env-file)
            if [[ $# -lt 2 || -z "$2" || "$2" == --* ]]; then
                echo -e "${RED}Error: --env-file requires a path argument.${NC}" >&2
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
        --skip-connectivity)
            SKIP_CONNECTIVITY=true
            shift
            ;;
        --skip-php-check)
            SKIP_PHP_CHECK=true
            shift
            ;;
        --strict)
            STRICT_MODE=true
            shift
            ;;
        -h|--help)
            echo "Usage: $0 [--env-file <path>] [--skip-connectivity] [--skip-php-check] [--strict]"
            exit 0
            ;;
        *)
            echo -e "${RED}Unknown argument: $1${NC}" >&2
            echo "Usage: $0 [--env-file <path>] [--skip-connectivity] [--skip-php-check] [--strict]" >&2
            exit 1
            ;;
    esac
done

# Strict Mode Invariants
if [[ "${STRICT_MODE}" == "true" ]]; then
    if [[ "${SKIP_CONNECTIVITY}" == "true" || "${SKIP_PHP_CHECK}" == "true" ]]; then
        echo -e "${RED}Error: Cannot combine --strict with --skip-connectivity or --skip-php-check in production.${NC}" >&2
        exit 1
    fi
fi

# ==============================================================================
# Helper Functions: Safe Environment Reading & Syntax Validation
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

log_info "Project root:     ${PROJECT_ROOT}"
log_info "Environment file: ${ENV_FILE}"

# ==============================================================================
# 1. Operating System & Platform Checks
# ==============================================================================
log_section "1. Operating System & Web Environment"

if [[ -f "/etc/os-release" ]]; then
    OS_NAME=$(grep -E '^PRETTY_NAME=' /etc/os-release | cut -d= -f2 | tr -d '"')
    log_pass "Operating System: ${OS_NAME}"
else
    log_warn "Could not identify Linux distribution via /etc/os-release (cPanel environment?)"
fi

CURRENT_USER=$(id -u -n 2>/dev/null || whoami)
if [[ "${CURRENT_USER}" == "root" ]]; then
    log_warn "Preflight is running as 'root'. Production cPanel services and PHP scripts should run as cPanel user."
else
    log_pass "Running as unprivileged host user: ${CURRENT_USER}"
fi

# ==============================================================================
# 2. PHP Runtime & Extensions
# ==============================================================================
log_section "2. PHP 8.4 Runtime & Required Extensions"

if [[ "${SKIP_PHP_CHECK}" == "true" ]]; then
    log_info "Skipping PHP CLI runtime and extensions check (--skip-php-check specified)"
elif command -v php >/dev/null 2>&1; then
    PHP_VER=$(php -r 'echo PHP_MAJOR_VERSION . "." . PHP_MINOR_VERSION;')
    PHP_FULL_VER=$(php -r 'echo PHP_VERSION;')
    MAJOR=$(echo "${PHP_VER}" | cut -d. -f1)
    MINOR=$(echo "${PHP_VER}" | cut -d. -f2)

    if [[ "${MAJOR}" -ge 8 && "${MINOR}" -ge 4 ]]; then
        log_pass "PHP CLI version >= 8.4 detected: ${PHP_FULL_VER}"
    else
        log_fail "PHP version is ${PHP_FULL_VER}. SeWaroeng requires PHP 8.4+."
    fi

    # Check Required Core Extensions
    REQUIRED_EXTS=("pdo" "pdo_pgsql" "pgsql" "mbstring" "xml" "ctype" "iconv" "intl" "bcmath" "curl" "zip")
    for ext in "${REQUIRED_EXTS[@]}"; do
        if php -m 2>/dev/null | grep -qi "^${ext}$"; then
            log_pass "PHP extension '${ext}' is loaded"
        else
            log_fail "Required PHP extension '${ext}' is MISSING in CLI PHP runtime"
        fi
    done
    if php -m 2>/dev/null | grep -qi "^redis$"; then
        log_pass "PHP extension 'redis' is loaded"
    else
        log_info "PHP extension 'redis' is not loaded (optional for database-backed cache/queue profile)"
    fi
else
    log_fail "PHP CLI executable not found in PATH! (Ensure /usr/local/bin or cPanel MultiPHP path is exported)"
fi

# ==============================================================================
# 3. Environment File & Production Configuration Audit
# ==============================================================================
log_section "3. Production Configuration Audit (.env)"

if [[ ! -f "${ENV_FILE}" ]]; then
    log_fail "Environment file not found at: ${ENV_FILE}"
else
    log_pass "Environment file exists: ${ENV_FILE}"

    # Syntax Validation
    if ! validate_env_file_syntax "${ENV_FILE}"; then
        log_fail "Environment file '${ENV_FILE}' failed syntax validation."
    else
        log_pass "Environment file syntax is valid (no unquoted whitespace, closed quotes, clean encoding)"
    fi

    # Mandatory Production Keys
    MANDATORY_KEYS=(
        "APP_NAME"
        "APP_ENV"
        "APP_KEY"
        "APP_DEBUG"
        "APP_URL"
        "DB_CONNECTION"
        "DB_HOST"
        "DB_PORT"
        "DB_DATABASE"
        "DB_USERNAME"
        "DB_PASSWORD"
        "CACHE_STORE"
        "QUEUE_CONNECTION"
        "SESSION_DRIVER"
    )

    MISSING_KEYS=0
    for key in "${MANDATORY_KEYS[@]}"; do
        val=$(get_env_val "${key}")
        if [[ -z "${val}" ]]; then
            log_fail "Mandatory production key '${key}' is missing or empty in ${ENV_FILE}"
            MISSING_KEYS=$((MISSING_KEYS + 1))
        else
            log_pass "Key present: ${key}"
        fi
    done

    CURRENT_CACHE=$(get_env_val "CACHE_STORE")
    CURRENT_QUEUE=$(get_env_val "QUEUE_CONNECTION")
    CURRENT_SESSION=$(get_env_val "SESSION_DRIVER")

    # If any component uses Redis, REDIS_HOST and REDIS_PORT are mandatory
    if [[ "${CURRENT_CACHE}" == "redis" || "${CURRENT_QUEUE}" == "redis" || "${CURRENT_SESSION}" == "redis" ]]; then
        for rkey in "REDIS_HOST" "REDIS_PORT"; do
            rval=$(get_env_val "${rkey}")
            if [[ -z "${rval}" ]]; then
                log_fail "Mandatory key '${rkey}' is missing or empty in ${ENV_FILE} (required when Redis driver is configured)"
                MISSING_KEYS=$((MISSING_KEYS + 1))
            else
                log_pass "Key present: ${rkey}"
            fi
        done
        if [[ "${SKIP_PHP_CHECK}" != "true" ]] && command -v php >/dev/null 2>&1 && ! php -m 2>/dev/null | grep -qi "^redis$"; then
            log_fail "PHP extension 'redis' is missing but required by CACHE_STORE/QUEUE_CONNECTION/SESSION_DRIVER!"
        fi
    else
        log_pass "Redis configuration keys optional (profil cPanel database-backed cache/queue/session aktif)"
    fi

    # Reject Fixture Identifiers in Production
    if [[ "${ENV_FILE}" =~ (fixture|fixtures|\.example|\.test|\.stub) ]] || \
       grep -q "IS_DEPLOYMENT_FIXTURE=true" "${ENV_FILE}" 2>/dev/null; then
        log_fail "CRITICAL SAFETY VIOLATION: Using a test fixture as production .env is strictly prohibited!"
    fi

    CURRENT_DB_PASS=$(get_env_val "DB_PASSWORD")
    if [[ "${CURRENT_DB_PASS}" =~ (dummy_fixture_password|REPLACE_WITH_STRONG_PRODUCTION_SECRET|secret123) ]]; then
        log_fail "CRITICAL SAFETY VIOLATION: Production database password is a known test fixture dummy password!"
    fi

    # Production Invariant: APP_ENV must be strictly 'production'
    CURRENT_APP_ENV=$(get_env_val "APP_ENV")
    if [[ "${CURRENT_APP_ENV}" == "production" ]]; then
        log_pass "APP_ENV is explicitly set to: production"
    elif [[ "${CURRENT_APP_ENV}" == "staging" ]]; then
        log_fail "SAFETY HAZARD: APP_ENV is set to 'staging' on a production deployment preflight!"
    else
        log_fail "APP_ENV is '${CURRENT_APP_ENV}'. Expected strictly 'production'."
    fi

    # Production Invariant: APP_DEBUG must be false
    CURRENT_APP_DEBUG=$(get_env_val "APP_DEBUG")
    if [[ "${CURRENT_APP_DEBUG}" == "false" || "${CURRENT_APP_DEBUG}" == "0" ]]; then
        log_pass "APP_DEBUG is disabled (false)"
    else
        log_fail "SECURITY VULNERABILITY: APP_DEBUG is '${CURRENT_APP_DEBUG}'. Must be 'false' in production."
    fi

    # Production Invariant: Target Hosts
    CURRENT_APP_URL=$(get_env_val "APP_URL")
    PROD_API_HOST="api.sewaroeng.neuracakrawira.asia"
    PROD_WEB_HOST="sewaroeng.neuracakrawira.asia"

    if [[ "${CURRENT_APP_URL%/}" == "https://${PROD_API_HOST}" ]]; then
        log_pass "APP_URL matches planned production API host: https://${PROD_API_HOST}"
    else
        log_fail "APP_URL mismatch: '${CURRENT_APP_URL}'. Expected 'https://${PROD_API_HOST}'."
    fi

    # Production Invariant: Database Connection & Name
    CURRENT_DB_CONN=$(get_env_val "DB_CONNECTION")
    CURRENT_DB_NAME=$(get_env_val "DB_DATABASE")
    CURRENT_DB_PORT=$(get_env_val "DB_PORT")

    if [[ "${CURRENT_DB_CONN}" == "pgsql" ]]; then
        log_pass "DB_CONNECTION is PostgreSQL ('pgsql')"
    else
        log_fail "CRITICAL ARCHITECTURE VIOLATION: DB_CONNECTION is '${CURRENT_DB_CONN}'. PostgreSQL 16+ is mandatory for RLS."
    fi

    if [[ "${CURRENT_DB_NAME}" =~ (staging|test|local|dev) ]]; then
        log_fail "CRITICAL SAFETY HAZARD: Production database name '${CURRENT_DB_NAME}' resembles staging or test!"
    elif [[ ! "${CURRENT_DB_NAME}" =~ ^[a-zA-Z0-9_]+$ ]]; then
        log_fail "Database name '${CURRENT_DB_NAME}' contains invalid characters."
    else
        log_pass "Production target database name: ${CURRENT_DB_NAME}"
    fi

    if [[ "${CURRENT_DB_PORT}" =~ ^[0-9]+$ ]]; then
        log_pass "DB_PORT is numeric: ${CURRENT_DB_PORT}"
    else
        log_fail "DB_PORT '${CURRENT_DB_PORT}' is not a valid integer."
    fi

    # Production Invariant: Cache & Queue Drivers (cPanel Database-backed or Redis)
    CURRENT_CACHE=$(get_env_val "CACHE_STORE")
    CURRENT_QUEUE=$(get_env_val "QUEUE_CONNECTION")
    CURRENT_SESSION=$(get_env_val "SESSION_DRIVER")

    if [[ "${CURRENT_CACHE}" == "database" ]]; then
        log_pass "CACHE_STORE is configured to 'database' (PostgreSQL database-backed cache & distributed locks)"
    elif [[ "${CURRENT_CACHE}" == "redis" ]]; then
        log_pass "CACHE_STORE is configured to 'redis' (Redis cache & distributed locks)"
    else
        log_fail "Invalid CACHE_STORE: '${CURRENT_CACHE}'. Production requires 'database' (cPanel PostgreSQL) or 'redis'."
    fi

    if [[ "${CURRENT_QUEUE}" == "database" ]]; then
        log_pass "QUEUE_CONNECTION is configured to 'database' (PostgreSQL database-backed queue)"
    elif [[ "${CURRENT_QUEUE}" == "redis" ]]; then
        log_pass "QUEUE_CONNECTION is configured to 'redis' (Redis queue)"
    else
        log_fail "Invalid QUEUE_CONNECTION: '${CURRENT_QUEUE}'. Production requires 'database' or 'redis'."
    fi

    if [[ "${CURRENT_SESSION}" == "database" ]]; then
        log_pass "SESSION_DRIVER is configured to 'database' (PostgreSQL database-backed sessions)"
    elif [[ "${CURRENT_SESSION}" =~ ^(file|cookie|redis)$ ]]; then
        log_pass "SESSION_DRIVER is configured to '${CURRENT_SESSION}'"
    else
        log_warn "SESSION_DRIVER is '${CURRENT_SESSION}'."
    fi
fi

# ==============================================================================
# 4. Live Connectivity & Database RLS Privilege Inspection
# ==============================================================================
log_section "4. Database & Infrastructure Connectivity"

if [[ "${SKIP_CONNECTIVITY}" == "true" ]]; then
    log_info "Skipping live database and Redis connectivity checks (--skip-connectivity specified)"
else
    DB_HOST=$(get_env_val "DB_HOST")
    DB_PORT=$(get_env_val "DB_PORT")
    DB_DATABASE=$(get_env_val "DB_DATABASE")
    DB_USERNAME=$(get_env_val "DB_USERNAME")
    DB_PASSWORD=$(get_env_val "DB_PASSWORD")
    REDIS_HOST=$(get_env_val "REDIS_HOST")
    REDIS_PORT=$(get_env_val "REDIS_PORT")
    REDIS_PASSWORD=$(get_env_val "REDIS_PASSWORD")

    # 4.1 PostgreSQL Connection & Privilege Checks
    if command -v psql >/dev/null 2>&1; then
        export PGPASSWORD="${DB_PASSWORD}"
        PSQL_TEST=$(psql -h "${DB_HOST}" -p "${DB_PORT}" -U "${DB_USERNAME}" -d "${DB_DATABASE}" -t -A -c "SELECT 1;" 2>&1 || true)
        if [[ "${PSQL_TEST}" == "1" ]]; then
            log_pass "PostgreSQL connection to '${DB_DATABASE}' on ${DB_HOST}:${DB_PORT} succeeded"

            # Check PostgreSQL version >= 16
            PG_VERSION_NUM=$(psql -h "${DB_HOST}" -p "${DB_PORT}" -U "${DB_USERNAME}" -d "${DB_DATABASE}" -t -A -c "SHOW server_version_num;" 2>/dev/null || echo "0")
            if [[ "${PG_VERSION_NUM}" -ge 160000 ]]; then
                PG_VER_STR=$(psql -h "${DB_HOST}" -p "${DB_PORT}" -U "${DB_USERNAME}" -d "${DB_DATABASE}" -t -A -c "SHOW server_version;" 2>/dev/null || echo "unknown")
                log_pass "PostgreSQL server version >= 16: ${PG_VER_STR}"
            else
                log_fail "PostgreSQL server version (${PG_VERSION_NUM}) is older than 16.0. Version 16+ is required for SeWaroeng RLS."
            fi

            # Check btree_gist extension
            BTREE_CHECK=$(psql -h "${DB_HOST}" -p "${DB_PORT}" -U "${DB_USERNAME}" -d "${DB_DATABASE}" -t -A -c "SELECT 1 FROM pg_extension WHERE extname = 'btree_gist';" 2>/dev/null || echo "0")
            if [[ "${BTREE_CHECK}" == "1" ]]; then
                log_pass "PostgreSQL extension 'btree_gist' is installed"
            else
                log_fail "PostgreSQL extension 'btree_gist' is MISSING in database '${DB_DATABASE}'."
            fi

            # Check Application Role RLS Privileges (NOSUPERUSER NOBYPASSRLS)
            USER_PRIVS=$(psql -h "${DB_HOST}" -p "${DB_PORT}" -U "${DB_USERNAME}" -d "${DB_DATABASE}" -t -A -c "SELECT rolsuper, rolbypassrls FROM pg_roles WHERE rolname = '${DB_USERNAME}';" 2>/dev/null || echo "")
            if [[ -n "${USER_PRIVS}" ]]; then
                IS_SUPER=$(echo "${USER_PRIVS}" | cut -d'|' -f1)
                IS_BYPASS=$(echo "${USER_PRIVS}" | cut -d'|' -f2)
                if [[ "${IS_SUPER}" == "f" && "${IS_BYPASS}" == "f" ]]; then
                    log_pass "Database role '${DB_USERNAME}' has strict least-privilege (NOSUPERUSER NOBYPASSRLS)"
                else
                    log_fail "SECURITY VULNERABILITY: Role '${DB_USERNAME}' has rolsuper=${IS_SUPER}, rolbypassrls=${IS_BYPASS}!"
                fi
            else
                log_warn "Could not inspect role privileges for '${DB_USERNAME}' directly via pg_roles"
            fi

            # Detect deployment lifecycle stage: First Deployment vs Existing Schema
            HAS_MIGRATIONS_TABLE=$(psql -h "${DB_HOST}" -p "${DB_PORT}" -U "${DB_USERNAME}" -d "${DB_DATABASE}" -t -A -c "SELECT 1 FROM information_schema.tables WHERE table_schema = 'public' AND table_name = 'migrations';" 2>/dev/null || echo "0")
            
            if [[ "${HAS_MIGRATIONS_TABLE}" != "1" ]]; then
                log_info "Deployment Stage: FIRST DEPLOYMENT (Clean unmigrated database detected)"
                log_info "Infrastructure and domain tables will be initialized in deployment Step 5 by 'sewaroeng_prod_migrator'."
            else
                log_pass "Deployment Stage: UPDATE DEPLOYMENT (Existing schema detected, migrations table present)"
                
                # In an existing schema, verify that expected infrastructure tables exist
                SYS_TABLES=()
                if [[ "${CURRENT_CACHE}" == "database" ]]; then
                    SYS_TABLES+=("cache" "cache_locks")
                fi
                if [[ "${CURRENT_QUEUE}" == "database" ]]; then
                    SYS_TABLES+=("jobs" "failed_jobs" "job_batches")
                fi
                if [[ "${CURRENT_SESSION}" == "database" ]]; then
                    SYS_TABLES+=("sessions")
                fi

                for tbl in "${SYS_TABLES[@]}"; do
                    TBL_CHECK=$(psql -h "${DB_HOST}" -p "${DB_PORT}" -U "${DB_USERNAME}" -d "${DB_DATABASE}" -t -A -c "SELECT 1 FROM information_schema.tables WHERE table_schema = 'public' AND table_name = '${tbl}';" 2>/dev/null || echo "0")
                    if [[ "${TBL_CHECK}" == "1" ]]; then
                        log_pass "Required infrastructure table '${tbl}' exists in existing schema"
                    else
                        log_warn "Infrastructure table '${tbl}' is not yet created in '${DB_DATABASE}' (will be reconciled via migration)"
                    fi
                done
            fi

            # Check Schema Migrator Identity (if credentials provided)
            MIGRATOR_USER=$(get_env_val "DB_MIGRATOR_USERNAME")
            MIGRATOR_PASS=$(get_env_val "DB_MIGRATOR_PASSWORD")
            if [[ -z "${MIGRATOR_USER}" ]]; then
                MIGRATOR_USER="sewaroeng_prod_migrator"
            fi

            if [[ -n "${MIGRATOR_PASS}" ]]; then
                export PGPASSWORD="${MIGRATOR_PASS}"
                MIGRATOR_TEST=$(psql -h "${DB_HOST}" -p "${DB_PORT}" -U "${MIGRATOR_USER}" -d "${DB_DATABASE}" -t -A -c "SELECT 1;" 2>&1 || true)
                if [[ "${MIGRATOR_TEST}" == "1" ]]; then
                    log_pass "Schema migrator role '${MIGRATOR_USER}' connection succeeded"
                    CAN_CREATE_SCHEMA=$(psql -h "${DB_HOST}" -p "${DB_PORT}" -U "${MIGRATOR_USER}" -d "${DB_DATABASE}" -t -A -c "SELECT has_schema_privilege('${MIGRATOR_USER}', 'public', 'CREATE');" 2>/dev/null || echo "f")
                    if [[ "${CAN_CREATE_SCHEMA}" == "t" ]]; then
                        log_pass "Migrator role '${MIGRATOR_USER}' has CREATE privilege on schema 'public' (DDL verified)"
                    else
                        log_fail "Migrator role '${MIGRATOR_USER}' lacks CREATE privilege on schema 'public'! Migrations will fail."
                    fi
                else
                    log_fail "Migrator connection FAILED for '${MIGRATOR_USER}' to ${DB_HOST}:${DB_PORT}/${DB_DATABASE}"
                fi
                unset PGPASSWORD
            else
                log_info "DB_MIGRATOR_PASSWORD not set in preflight .env: Migrator identity will be validated during deployment execution"
            fi
        else
            log_fail "PostgreSQL connection FAILED to ${DB_HOST}:${DB_PORT}/${DB_DATABASE}"
        fi
        unset PGPASSWORD
    else
        log_warn "psql client not found in PATH; skipping direct psql connectivity probes"
    fi

    # 4.2 Redis Connectivity Check
    if [[ "${CURRENT_CACHE}" != "redis" && "${CURRENT_QUEUE}" != "redis" && "${CURRENT_SESSION}" != "redis" ]]; then
        log_pass "Redis connectivity check skipped (profil cPanel database-backed cache/queue/session aktif — no Redis dependency)"
    elif command -v redis-cli >/dev/null 2>&1; then
        REDIS_AUTH_OPT=()
        if [[ -n "${REDIS_PASSWORD}" && "${REDIS_PASSWORD}" != "null" ]]; then
            REDIS_AUTH_OPT=("-a" "${REDIS_PASSWORD}")
        fi
        REDIS_PING=$(redis-cli -h "${REDIS_HOST}" -p "${REDIS_PORT}" "${REDIS_AUTH_OPT[@]}" ping 2>/dev/null || echo "FAIL")
        if [[ "${REDIS_PING}" =~ PONG ]]; then
            log_pass "Redis ping succeeded on ${REDIS_HOST}:${REDIS_PORT}"
        else
            log_fail "Redis connection FAILED on ${REDIS_HOST}:${REDIS_PORT}"
        fi
    elif command -v php >/dev/null 2>&1; then
        # Fallback via PHP Redis extension
        REDIS_PHP_TEST=$(php -r '
            $r = new Redis();
            $host = "'"${REDIS_HOST}"'";
            $port = (int)"'"${REDIS_PORT}"'";
            $pass = "'"${REDIS_PASSWORD}"'";
            try {
                if (@$r->connect($host, $port, 2)) {
                    if ($pass && $pass !== "null") { $r->auth($pass); }
                    echo ($r->ping() ? "PONG" : "FAIL");
                } else { echo "FAIL"; }
            } catch (\Throwable $e) { echo "FAIL"; }
        ' 2>/dev/null || echo "FAIL")
        if [[ "${REDIS_PHP_TEST}" == "PONG" ]]; then
            log_pass "Redis connection verified via PHP Redis extension"
        else
            log_fail "Redis connection FAILED via PHP Redis probe on ${REDIS_HOST}:${REDIS_PORT}"
        fi
    fi
fi

# ==============================================================================
# 5. Apache & cPanel Safety Inspection
# ==============================================================================
log_section "5. Apache & cPanel Document Root Safety"

# Check backend/public/.htaccess
if [[ -f "${BACKEND_DIR}/public/.htaccess" ]]; then
    log_pass "Backend public .htaccess exists"
    if grep -q "HTTP_AUTHORIZATION" "${BACKEND_DIR}/public/.htaccess"; then
        log_pass ".htaccess includes Authorization header forwarding rule"
    else
        log_warn ".htaccess is missing HTTP_AUTHORIZATION rule (Bearer tokens may be dropped by Apache)"
    fi
else
    log_warn "Backend public .htaccess not found at ${BACKEND_DIR}/public/.htaccess"
fi

# Check storage directory permissions
if [[ -d "${BACKEND_DIR}/storage" ]]; then
    if [[ -w "${BACKEND_DIR}/storage" ]]; then
        log_pass "Storage directory is writable: ${BACKEND_DIR}/storage"
    else
        log_fail "Storage directory is NOT writable: ${BACKEND_DIR}/storage"
    fi
fi

if [[ -d "${BACKEND_DIR}/bootstrap/cache" ]]; then
    if [[ -w "${BACKEND_DIR}/bootstrap/cache" ]]; then
        log_pass "Bootstrap cache directory is writable: ${BACKEND_DIR}/bootstrap/cache"
    else
        log_fail "Bootstrap cache directory is NOT writable: ${BACKEND_DIR}/bootstrap/cache"
    fi
fi

# ==============================================================================
# 6. Third-Party Gateway Status Audit
# ==============================================================================
log_section "6. Third-Party Integration Configuration Status"

# Midtrans
MIDTRANS_KEY=$(get_env_val "MIDTRANS_SERVER_KEY")
MIDTRANS_PROD=$(get_env_val "MIDTRANS_IS_PRODUCTION")
if [[ -z "${MIDTRANS_KEY}" ]]; then
    log_pass "Midtrans: Unconfigured (Safe Inactive Default — transactions will not execute)"
elif [[ "${MIDTRANS_PROD}" == "true" ]]; then
    log_pass "Midtrans: Configured for LIVE production transactions"
else
    log_warn "Midtrans: Key present but MIDTRANS_IS_PRODUCTION is false (SANDBOX mode in production!)"
fi

# Duitku
DUITKU_KEY=$(get_env_val "DUITKU_API_KEY")
DUITKU_PROD=$(get_env_val "DUITKU_IS_PRODUCTION")
if [[ -z "${DUITKU_KEY}" ]]; then
    log_pass "Duitku: Unconfigured (Safe Inactive Default — transactions will not execute)"
elif [[ "${DUITKU_PROD}" == "true" ]]; then
    log_pass "Duitku: Configured for LIVE production transactions"
else
    log_warn "Duitku: Key present but DUITKU_IS_PRODUCTION is false (SANDBOX mode in production!)"
fi

# WhatsApp
WA_SECRET=$(get_env_val "META_WHATSAPP_APP_SECRET")
if [[ -z "${WA_SECRET}" ]]; then
    log_pass "Meta WhatsApp: Webhook secret unset (Safe Inactive Default)"
else
    log_pass "Meta WhatsApp: Webhook secret configured"
fi

# ==============================================================================
# Preflight Summary & Exit
# ==============================================================================
echo -e "\n${BOLD}====================================================================${NC}"
echo -e "${BOLD}              Production Preflight Summary                         ${NC}"
echo -e "${BOLD}====================================================================${NC}"
echo -e "Passed Checks:   ${GREEN}${BOLD}${PASSED_COUNT}${NC}"
echo -e "Warnings:        ${YELLOW}${BOLD}${WARN_COUNT}${NC}"
echo -e "Failed Checks:   ${RED}${BOLD}${FAIL_COUNT}${NC}"

if [[ ${FAIL_COUNT} -eq 0 ]]; then
    echo -e "\n${GREEN}${BOLD}OVERALL STATUS: FULL_PREFLIGHT_PASSED${NC}"
    echo -e "All production preflight checks passed. Proceed to deployment planning."
    exit 0
else
    echo -e "\n${RED}${BOLD}OVERALL STATUS: PREFLIGHT_FAILED${NC}"
    echo -e "Found ${FAIL_COUNT} critical failure(s). Resolve all errors before proceeding with production deployment."
    exit 1
fi
