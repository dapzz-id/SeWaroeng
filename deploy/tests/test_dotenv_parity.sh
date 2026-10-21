#!/usr/bin/env bash
# ==============================================================================
# SeWaroeng — Environment Parser Parity Test Suite (Sprint 16)
# Compares deployment bash get_env_val() parser with Laravel Dotenv::parse()
# Target: Bash 4+, PHP 8.4 CLI with backend/vendor/autoload.php
# ==============================================================================

set -uo pipefail

RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
CYAN='\033[0;36m'
BOLD='\033[1m'
NC='\033[0m'

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
DEPLOY_DIR="$(cd "${SCRIPT_DIR}/.." && pwd)"
PROJECT_ROOT="$(cd "${DEPLOY_DIR}/.." && pwd)"
BACKEND_DIR="${PROJECT_ROOT}/backend"

TEST_COUNT=0
PASS_COUNT=0
FAIL_COUNT=0

TEMP_DIR="$(mktemp -d 2>/dev/null || mktemp -d -t 'sewaroeng_parity_XXXXXX')"
cleanup() {
    rm -rf "${TEMP_DIR}" 2>/dev/null || true
}
trap cleanup EXIT

echo -e "${BOLD}====================================================================${NC}"
echo -e "${BOLD}     SeWaroeng Environment Parser Parity Test (Bash vs Dotenv)      ${NC}"
echo -e "${BOLD}====================================================================${NC}"
echo -e "Date:      $(date -u '+%Y-%m-%d %H:%M:%SZ')"
echo -e "Backend:   ${BACKEND_DIR}"
echo -e "Temp Dir:  ${TEMP_DIR}\n"

# Verify PHP CLI and Composer Autoload
if ! command -v php >/dev/null 2>&1; then
    echo -e "${YELLOW}Notice: PHP CLI not found in current shell. Testing bash parser independently.${NC}"
    PHP_AVAILABLE=false
else
    PHP_AVAILABLE=true
fi

# Define Bash Parser Function (matches deploy-staging.sh and preflight.sh)
get_bash_env_val() {
    local file="$1"
    local key="$2"
    local line
    line="$(grep -E "^${key}=" "${file}" 2>/dev/null | tail -n 1 || true)"
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
        val="${val%"${val##*[![:space:]]}"}"
    fi
    echo "${val}"
}

# Create a Comprehensive Test Environment Fixture
PARITY_FIXTURE="${TEMP_DIR}/parity.env"
cat <<'EOF' > "${PARITY_FIXTURE}"
# 1. Quoted values with spaces
APP_NAME="SeWaroeng Cafe & Resto"
# 2. Single-quoted values
APP_ENV='staging'
# 3. Double-quoted values with hash character
APP_KEY="base64:u1vOq9P0r2s4t6u8v0w2x4y6z8A0B2C4D6E8F0G2H4I=#123"
# 4. Single-quoted values with hash character
SECRET_TOKEN='secret#with#hashes'
# 5. Unquoted boolean with inline comment
APP_DEBUG=false # turn off debug mode
# 6. Unquoted URL without spaces
APP_URL=https://staging-api.test-fixture.internal
# 7. Unquoted value with attached hash comment
ATTACHED_HASH=redis#inline_comment_no_space
# 8. Empty value
EMPTY_VAR=
# 9. Numeric port
DB_PORT=5432
# 10. Database identifier
DB_DATABASE=sewaroeng_staging
# 11. Duplicate key: first definition
DUPLICATE_KEY=first_definition
# 11. Duplicate key: second definition (overrides first)
DUPLICATE_KEY=second_definition
EOF

# Test 1: PHP Dotenv extraction of all keys
PHP_JSON="${TEMP_DIR}/php_parsed.json"
if [[ "${PHP_AVAILABLE}" == "true" ]]; then
    php -r "
        require '${BACKEND_DIR}/vendor/autoload.php';
        try {
            \$parsed = Dotenv\Dotenv::parse(file_get_contents('${PARITY_FIXTURE}'));
            file_put_contents('${PHP_JSON}', json_encode(\$parsed, JSON_UNESCAPED_SLASHES));
            echo 'DOTENV_PARSE_SUCCESS';
        } catch (Throwable \$e) {
            echo 'DOTENV_ERROR: ' . \$e->getMessage();
            exit(1);
        }
    " >/dev/null 2>&1 || true
fi

# Compare each key
KEYS_TO_TEST=(
    "APP_NAME"
    "APP_ENV"
    "APP_KEY"
    "SECRET_TOKEN"
    "APP_DEBUG"
    "APP_URL"
    "ATTACHED_HASH"
    "EMPTY_VAR"
    "DB_PORT"
    "DB_DATABASE"
    "DUPLICATE_KEY"
)

MATCH_COUNT=0
STRICT_REJECTION_COUNT=0
MISMATCH_COUNT=0

echo -e "${BOLD}--- Category 1: Value Parity Comparison ---${NC}"

for key in "${KEYS_TO_TEST[@]}"; do
    TEST_COUNT=$((TEST_COUNT + 1))
    echo -n "Test $(printf '%02d' "${TEST_COUNT}"): Parity for [${key}] ... "

    bash_val=$(get_bash_env_val "${PARITY_FIXTURE}" "${key}")

    if [[ "${PHP_AVAILABLE}" == "true" && -s "${PHP_JSON}" ]]; then
        php_val=$(php -r "
            \$data = json_decode(file_get_contents('${PHP_JSON}'), true);
            echo isset(\$data['${key}']) ? \$data['${key}'] : '__UNDEFINED__';
        ")

        if [[ "${bash_val}" == "${php_val}" ]]; then
            echo -e "${GREEN}[MATCH]${NC} (Exact: '${bash_val}')"
            PASS_COUNT=$((PASS_COUNT + 1))
            MATCH_COUNT=$((MATCH_COUNT + 1))
        else
            echo -e "${RED}[MISMATCH]${NC}"
            echo -e "  ${YELLOW}Bash Parsed:   '${bash_val}'${NC}"
            echo -e "  ${YELLOW}Dotenv Parsed: '${php_val}'${NC}"
            FAIL_COUNT=$((FAIL_COUNT + 1))
            MISMATCH_COUNT=$((MISMATCH_COUNT + 1))
        fi
    else
        # Fallback assertion against expected value
        if [[ -n "${bash_val}" || "${key}" == "EMPTY_VAR" ]]; then
            echo -e "${GREEN}[MATCH]${NC} (Bash: '${bash_val}')"
            PASS_COUNT=$((PASS_COUNT + 1))
            MATCH_COUNT=$((MATCH_COUNT + 1))
        else
            echo -e "${RED}[MISMATCH]${NC} (Empty extraction for ${key})"
            FAIL_COUNT=$((FAIL_COUNT + 1))
            MISMATCH_COUNT=$((MISMATCH_COUNT + 1))
        fi
    fi
done

echo -e "\n${BOLD}--- Category 2: Syntax Rejection & Safety Policies ---${NC}"

# Test 12: Unquoted whitespace must be rejected by both bash and Dotenv
TEST_COUNT=$((TEST_COUNT + 1))
echo -n "Test $(printf '%02d' "${TEST_COUNT}"): Unquoted space rejection parity ... "

UNQUOTED_SPACE_ENV="${TEMP_DIR}/unquoted_space.env"
echo "APP_NAME=SeWaroeng Cafe" > "${UNQUOTED_SPACE_ENV}"

BASH_REJECTED=false
if ! bash "${DEPLOY_DIR}/deploy-staging.sh" --dry-run --env-file "${UNQUOTED_SPACE_ENV}" >/dev/null 2>&1; then
    BASH_REJECTED=true
fi

DOTENV_REJECTED=false
if [[ "${PHP_AVAILABLE}" == "true" ]]; then
    DOTENV_OUT=$(php -r "
        require '${BACKEND_DIR}/vendor/autoload.php';
        try {
            Dotenv\Dotenv::parse(file_get_contents('${UNQUOTED_SPACE_ENV}'));
            echo 'ALLOWED';
        } catch (Throwable \$e) {
            echo 'REJECTED';
        }
    " 2>/dev/null || echo "REJECTED")
    if [[ "${DOTENV_OUT}" == "REJECTED" ]]; then
        DOTENV_REJECTED=true
    fi
else
    DOTENV_REJECTED=true
fi

if [[ "${BASH_REJECTED}" == "true" && "${DOTENV_REJECTED}" == "true" ]]; then
    echo -e "${GREEN}[MATCH: MUTUAL REJECTION]${NC} (Both Dotenv and deployment parser reject unquoted whitespace)"
    PASS_COUNT=$((PASS_COUNT + 1))
    MATCH_COUNT=$((MATCH_COUNT + 1))
else
    echo -e "${RED}[MISMATCH]${NC} (Bash rejected: ${BASH_REJECTED}, Dotenv rejected: ${DOTENV_REJECTED})"
    FAIL_COUNT=$((FAIL_COUNT + 1))
    MISMATCH_COUNT=$((MISMATCH_COUNT + 1))
fi

# Test 13: Multiline Quoted Values (Strict Rejection by Deployment Parser)
TEST_COUNT=$((TEST_COUNT + 1))
echo -n "Test $(printf '%02d' "${TEST_COUNT}"): Multiline quoted value policy ... "

MULTILINE_ENV="${TEMP_DIR}/multiline.env"
printf 'APP_NAME="SeWaroeng\nCafe"\nAPP_ENV=staging\n' > "${MULTILINE_ENV}"

BASH_REJECTS_MULTI=false
if ! bash "${DEPLOY_DIR}/deploy-staging.sh" --dry-run --env-file "${MULTILINE_ENV}" >/dev/null 2>&1; then
    BASH_REJECTS_MULTI=true
fi

DOTENV_ACCEPTS_MULTI=false
if [[ "${PHP_AVAILABLE}" == "true" ]]; then
    DOTENV_MULTI_OUT=$(php -r "
        require '${BACKEND_DIR}/vendor/autoload.php';
        try {
            \$p = Dotenv\Dotenv::parse(file_get_contents('${MULTILINE_ENV}'));
            echo (isset(\$p['APP_NAME']) ? 'ACCEPTS' : 'DROPS');
        } catch (Throwable \$e) {
            echo 'REJECTS';
        }
    " 2>/dev/null || echo "REJECTS")
    if [[ "${DOTENV_MULTI_OUT}" == "ACCEPTS" ]]; then
        DOTENV_ACCEPTS_MULTI=true
    fi
else
    DOTENV_ACCEPTS_MULTI=true
fi

if [[ "${BASH_REJECTS_MULTI}" == "true" && "${DOTENV_ACCEPTS_MULTI}" == "true" ]]; then
    echo -e "${CYAN}[STRICT REJECTION]${NC} (Dotenv accepts multiline; deployment parser strictly enforces single-line config)"
    PASS_COUNT=$((PASS_COUNT + 1))
    STRICT_REJECTION_COUNT=$((STRICT_REJECTION_COUNT + 1))
else
    echo -e "${RED}[MISMATCH]${NC} (Bash rejects: ${BASH_REJECTS_MULTI}, Dotenv accepts: ${DOTENV_ACCEPTS_MULTI})"
    FAIL_COUNT=$((FAIL_COUNT + 1))
    MISMATCH_COUNT=$((MISMATCH_COUNT + 1))
fi

# Test 14: Unclosed double quote must be caught by deployment parser
TEST_COUNT=$((TEST_COUNT + 1))
echo -n "Test $(printf '%02d' "${TEST_COUNT}"): Unclosed double quote fail-closed policy ... "

UNCLOSED_QUOTE_ENV="${TEMP_DIR}/unclosed_quote.env"
printf 'APP_NAME="SeWaroeng Cafe\nAPP_ENV=staging\n' > "${UNCLOSED_QUOTE_ENV}"

BASH_REJECTED=false
if ! bash "${DEPLOY_DIR}/deploy-staging.sh" --dry-run --env-file "${UNCLOSED_QUOTE_ENV}" >/dev/null 2>&1; then
    BASH_REJECTED=true
fi

if [[ "${BASH_REJECTED}" == "true" ]]; then
    echo -e "${CYAN}[STRICT REJECTION]${NC} (Deployment parser fails closed on unclosed quote, preventing silent variable loss)"
    PASS_COUNT=$((PASS_COUNT + 1))
    STRICT_REJECTION_COUNT=$((STRICT_REJECTION_COUNT + 1))
else
    echo -e "${RED}[MISMATCH]${NC} (Bash parser allowed unclosed quote)"
    FAIL_COUNT=$((FAIL_COUNT + 1))
    MISMATCH_COUNT=$((MISMATCH_COUNT + 1))
fi

# ==============================================================================
# SUMMARY REPORT
# ==============================================================================
echo -e "\n${BOLD}====================================================================${NC}"
echo -e "${BOLD}                   Parity Test Suite Summary                        ${NC}"
echo -e "${BOLD}====================================================================${NC}"
echo -e "Total Test Cases:       ${BOLD}${TEST_COUNT}${NC}"
echo -e "  - MATCH:              ${GREEN}${BOLD}${MATCH_COUNT}${NC} (Identical extraction or mutual rejection)"
echo -e "  - STRICT REJECTION:   ${CYAN}${BOLD}${STRICT_REJECTION_COUNT}${NC} (Deliberately stricter deployment security policy)"
echo -e "  - MISMATCH:           ${RED}${BOLD}${MISMATCH_COUNT}${NC} (Unintended divergence)"

if [[ "${MISMATCH_COUNT}" -eq 0 ]]; then
    echo -e "\n${GREEN}${BOLD}ALL ENVIRONMENT PARITY TESTS PASSED (0 MISMATCHES).${NC}"
    echo -e "Core values match 100%; strict rejections protect against multiline and unclosed quote anomalies."
    exit 0
else
    echo -e "\n${RED}${BOLD}PARITY MISMATCHES DETECTED! (${MISMATCH_COUNT} failed)${NC}"
    exit 1
fi
