#!/usr/bin/env bash
# ==============================================================================
# SeWaroeng — CLI Deployment Safety Gate & Preflight Regression Test Suite
# Tests parameter safety, guardrails, fixture lockout, and isolation boundaries.
# Target: Bash 4+, Linux/macOS/WSL/Git-Bash (Offline, Non-mutating)
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

TEST_COUNT=0
PASS_COUNT=0
FAIL_COUNT=0

# Create isolated scratch environment
TEMP_DIR="$(mktemp -d 2>/dev/null || mktemp -d -t 'sewaroeng_test_XXXXXX')"
SPY_BIN_DIR="${TEMP_DIR}/bin"
mkdir -p "${SPY_BIN_DIR}"
SPY_MUTATING_LOG="${TEMP_DIR}/spy_mutating.log"
SPY_READONLY_LOG="${TEMP_DIR}/spy_readonly.log"
touch "${SPY_MUTATING_LOG}" "${SPY_READONLY_LOG}"

# Create spy stubs for mutating / dangerous external commands
create_mutating_spy() {
    local cmd="$1"
    cat <<EOF > "${SPY_BIN_DIR}/${cmd}"
#!/usr/bin/env bash
echo "SPY_MUTATING: ${cmd} \$*" >> "${SPY_MUTATING_LOG}"
exit 0
EOF
    chmod +x "${SPY_BIN_DIR}/${cmd}"
}

# 1. Network & remote commands
create_mutating_spy "ssh"
create_mutating_spy "scp"
create_mutating_spy "rsync"
create_mutating_spy "curl"
create_mutating_spy "wget"

# 2. Database client commands
create_mutating_spy "pg_dump"
create_mutating_spy "psql"
create_mutating_spy "pg_restore"
create_mutating_spy "redis-cli"

# 3. Application build & runtime commands (distinguish --version from mutating build/install)
cat <<EOF > "${SPY_BIN_DIR}/composer"
#!/usr/bin/env bash
if [[ "\$*" =~ (--version|-V|version) ]]; then
    echo "SPY_READONLY: composer \$*" >> "${SPY_READONLY_LOG}"
    echo "Composer version 2.7.2 2024-03-11"
    exit 0
fi
echo "SPY_MUTATING: composer \$*" >> "${SPY_MUTATING_LOG}"
exit 0
EOF
chmod +x "${SPY_BIN_DIR}/composer"

cat <<EOF > "${SPY_BIN_DIR}/flutter"
#!/usr/bin/env bash
if [[ "\$*" =~ (--version|version) ]]; then
    echo "SPY_READONLY: flutter \$*" >> "${SPY_READONLY_LOG}"
    echo "Flutter 3.24.0"
    exit 0
fi
echo "SPY_MUTATING: flutter \$*" >> "${SPY_MUTATING_LOG}"
exit 0
EOF
chmod +x "${SPY_BIN_DIR}/flutter"

# 4. Privilege & system management commands
create_mutating_spy "sudo"
create_mutating_spy "nginx"
create_mutating_spy "install"
create_mutating_spy "chown"
create_mutating_spy "chmod"

# 5. Smart systemctl spy (distinguishes read-only queries vs mutating service restarts)
cat <<EOF > "${SPY_BIN_DIR}/systemctl"
#!/usr/bin/env bash
case "\${1:-}" in
    is-active|status|is-enabled)
        echo "SPY_READONLY: systemctl \$*" >> "${SPY_READONLY_LOG}"
        exit 0
        ;;
    *)
        echo "SPY_MUTATING: systemctl \$*" >> "${SPY_MUTATING_LOG}"
        exit 0
        ;;
esac
EOF
chmod +x "${SPY_BIN_DIR}/systemctl"

# 6. Smart php spy (distinguishes read-only queries vs mutating artisan/pdo commands)
cat <<EOF > "${SPY_BIN_DIR}/php"
#!/usr/bin/env bash
if [[ "\$*" =~ (migrate:status|PHP_VERSION|version_compare|-v|--version|-m) ]]; then
    echo "SPY_READONLY: php \$*" >> "${SPY_READONLY_LOG}"
    exit 0
fi
echo "SPY_MUTATING: php \$*" >> "${SPY_MUTATING_LOG}"
exit 0
EOF
chmod +x "${SPY_BIN_DIR}/php"

# Prepend spy bin to PATH
export PATH="${SPY_BIN_DIR}:${PATH}"

cleanup() {
    rm -rf "${TEMP_DIR}" 2>/dev/null || true
}
trap cleanup EXIT

# Test runner helper
assert_case() {
    local test_name="$1"
    local expected_code="$2"
    local pattern="$3"
    shift 3
    local cmd=("$@")

    TEST_COUNT=$((TEST_COUNT + 1))
    echo -n "Test $(printf '%02d' "${TEST_COUNT}"): ${test_name} ... "

    # Truncate spy logs before test execution
    : > "${SPY_MUTATING_LOG}"
    : > "${SPY_READONLY_LOG}"

    local stdout_file="${TEMP_DIR}/test_out.log"
    local stderr_file="${TEMP_DIR}/test_err.log"
    local actual_code=0

    "${cmd[@]}" > "${stdout_file}" 2> "${stderr_file}" || actual_code=$?

    local combined_output
    combined_output="$(cat "${stdout_file}" "${stderr_file}" 2>/dev/null || true)"

    local test_passed=true
    local failure_reason=""

    if [[ "${actual_code}" -ne "${expected_code}" ]]; then
        test_passed=false
        failure_reason="Expected exit code ${expected_code}, got ${actual_code}"
    elif [[ -n "${pattern}" ]] && ! echo "${combined_output}" | grep -q -E -e "${pattern}"; then
        test_passed=false
        failure_reason="Output did not match expected regex: '${pattern}'"
    fi

    # In ALL test cases (both success dry-runs and rejected cases), verify NO mutating command was invoked
    if [[ -s "${SPY_MUTATING_LOG}" ]]; then
        test_passed=false
        failure_reason="Mutating command was invoked unexpectedly! ($(cat "${SPY_MUTATING_LOG}"))"
    fi

    if [[ "${test_passed}" == "true" ]]; then
        echo -e "${GREEN}PASS${NC}"
        PASS_COUNT=$((PASS_COUNT + 1))
    else
        echo -e "${RED}FAIL${NC}"
        echo -e "  ${YELLOW}Reason: ${failure_reason}${NC}"
        echo -e "  ${CYAN}Command: ${cmd[*]}${NC}"
        echo -e "  ${CYAN}Combined Output:${NC}\n${combined_output}\n"
        FAIL_COUNT=$((FAIL_COUNT + 1))
    fi
}

echo -e "${BOLD}====================================================================${NC}"
echo -e "${BOLD}     SeWaroeng Deployment Safety & CLI Guard Regression Tests       ${NC}"
echo -e "${BOLD}====================================================================${NC}"
echo -e "Date:     $(date -u '+%Y-%m-%d %H:%M:%SZ')"
echo -e "Deploy:   ${DEPLOY_DIR}"
echo -e "Temp Dir: ${TEMP_DIR}\n"

# ==============================================================================
# SECTION A: MANDATORY SUCCESS CASES (SAFE DRY-RUNS & SIMULATIONS)
# ==============================================================================
echo -e "${BOLD}--- Category A: Mandatory Success Cases ---${NC}"

# A1: validate-domains.sh with valid non-production staging hosts
assert_case \
    "validate-domains with valid distinct staging hosts" \
    0 \
    "DOMAIN VALIDATION PASSED" \
    bash "${DEPLOY_DIR}/validate-domains.sh" \
        --mode staging \
        --api-host "staging-api.sewaroeng-test.internal" \
        --web-host "staging-web.sewaroeng-test.internal"

# A2: render-nginx-config.sh dry-run with valid hostnames
assert_case \
    "render-nginx-config in --dry-run mode" \
    0 \
    "DRY-RUN SUCCESS" \
    bash "${DEPLOY_DIR}/render-nginx-config.sh" \
        --dry-run \
        --api-host "staging-api.sewaroeng-test.internal" \
        --web-host "staging-web.sewaroeng-test.internal"

# A3: preflight.sh offline simulation with fixture and allowed skips
assert_case \
    "preflight.sh simulation with fixture and skips" \
    0 \
    "PREFLIGHT SIMULATION PASSED \(OFFLINE / FIXTURE MODE\)" \
    bash "${DEPLOY_DIR}/preflight.sh" \
        --env-file "${DEPLOY_DIR}/fixtures/staging.env.fixture" \
        --skip-connectivity \
        --skip-php-check

# A4: deploy-staging.sh dry-run with fixture and allowed skips
assert_case \
    "deploy-staging.sh --dry-run with valid fixture and allowed skips" \
    0 \
    "=== DRY-RUN DEPLOYMENT PLAN ===" \
    bash "${DEPLOY_DIR}/deploy-staging.sh" \
        --dry-run \
        --env-file "${DEPLOY_DIR}/fixtures/staging.env.fixture" \
        --skip-connectivity \
        --skip-php-check \
        --api-host "staging-api.test-fixture.internal" \
        --web-host "staging.test-fixture.internal"

# A5: Safe reading of quotes, comments, and neutralizing command substitutions
SAFE_QUOTES_ENV="${TEMP_DIR}/safe_quotes.env"
PAYLOAD_FLAG="${TEMP_DIR}/should_never_exist.flag"
cat <<EOF > "${SAFE_QUOTES_ENV}"
# Valid staging configuration with quotes, inline comments, and command substitution attempt
APP_NAME="SeWaroeng Cafe & Resto"
APP_ENV='staging'
APP_KEY="base64:u1vOq9P0r2s4t6u8v0w2x4y6z8A0B2C4D6E8F0G2H4I="
APP_DEBUG=false # unquoted inline comment
APP_URL='https://staging-api.safe-quotes.internal'
DB_CONNECTION=pgsql
DB_HOST=127.0.0.1
DB_PORT=5432
DB_DATABASE=sewaroeng_staging
DB_USERNAME=sewaroeng_app
DB_PASSWORD='dummy_password'
REDIS_CLIENT=phpredis
REDIS_HOST=127.0.0.1
REDIS_PASSWORD=null
REDIS_PORT=6379
CACHE_STORE=redis
CACHE_PREFIX=sewaroeng_staging_cache_
SESSION_DRIVER=redis
QUEUE_CONNECTION=redis
CORS_ALLOWED_ORIGINS=https://staging.safe-quotes.internal
TEST_INJECTION="\$(touch \"${PAYLOAD_FLAG}\")"
EOF

assert_case \
    "Safe reading of quotes, inline comments, and neutralizing subshells" \
    0 \
    "=== DRY-RUN DEPLOYMENT PLAN ===" \
    bash "${DEPLOY_DIR}/deploy-staging.sh" \
        --dry-run \
        --env-file "${SAFE_QUOTES_ENV}" \
        --skip-connectivity \
        --skip-php-check \
        --api-host "staging-api.safe-quotes.internal" \
        --web-host "staging.safe-quotes.internal"

# Prove command substitution was NOT evaluated
TEST_COUNT=$((TEST_COUNT + 1))
echo -n "Test $(printf '%02d' "${TEST_COUNT}"): Prove embedded command substitution in .env was not executed ... "
if [[ -f "${PAYLOAD_FLAG}" ]]; then
    echo -e "${RED}FAIL (Payload file was created! Command injection vulnerability!)${NC}"
    FAIL_COUNT=$((FAIL_COUNT + 1))
else
    echo -e "${GREEN}PASS (Payload was not executed)${NC}"
    PASS_COUNT=$((PASS_COUNT + 1))
fi

# ==============================================================================
# SECTION B: MANDATORY REJECTED CASES (SAFETY GUARDS & LOCKOUTS)
# ==============================================================================
echo -e "\n${BOLD}--- Category B: Mandatory Rejected Cases ---${NC}"

# B1: Reject --apply with --skip-connectivity
assert_case \
    "Reject --apply with --skip-connectivity" \
    1 \
    "--skip-connectivity is strictly forbidden in --apply" \
    bash "${DEPLOY_DIR}/deploy-staging.sh" \
        --apply \
        --skip-connectivity \
        --env-file "${DEPLOY_DIR}/fixtures/staging.env.fixture" \
        --api-host "staging-api.test-fixture.internal" \
        --web-host "staging.test-fixture.internal"

# B2: Reject --apply with --skip-php-check
assert_case \
    "Reject --apply with --skip-php-check" \
    1 \
    "--skip-php-check is strictly forbidden in --apply" \
    bash "${DEPLOY_DIR}/deploy-staging.sh" \
        --apply \
        --skip-php-check \
        --env-file "${DEPLOY_DIR}/fixtures/staging.env.fixture" \
        --api-host "staging-api.test-fixture.internal" \
        --web-host "staging.test-fixture.internal"

# B3: Reject --execute alias with bypass flags
assert_case \
    "Reject --execute with --skip-connectivity" \
    1 \
    "--skip-connectivity is strictly forbidden in --apply" \
    bash "${DEPLOY_DIR}/deploy-staging.sh" \
        --execute \
        --skip-connectivity \
        --env-file "${DEPLOY_DIR}/fixtures/staging.env.fixture" \
        --api-host "staging-api.test-fixture.internal" \
        --web-host "staging.test-fixture.internal"

# B4: Reject official test fixture from authorizing --apply
assert_case \
    "Reject staging.env.fixture during --apply" \
    1 \
    "Test fixture detected as environment configuration during --apply mode" \
    bash "${DEPLOY_DIR}/deploy-staging.sh" \
        --apply \
        --env-file "${DEPLOY_DIR}/fixtures/staging.env.fixture" \
        --api-host "staging-api.sewaroeng.org" \
        --web-host "staging.sewaroeng.org"

# B5: Reject custom test fixture with fixture marker during --apply
CUSTOM_FIXTURE="${TEMP_DIR}/custom.env"
cat <<EOF > "${CUSTOM_FIXTURE}"
APP_NAME=SeWaroeng
APP_ENV=staging
APP_DEBUG=false
APP_URL=https://staging-api.custom.internal
IS_DEPLOYMENT_FIXTURE=true
DB_CONNECTION=pgsql
DB_DATABASE=sewaroeng_staging
EOF

assert_case \
    "Reject custom fixture with IS_DEPLOYMENT_FIXTURE=true during --apply" \
    1 \
    "Test fixture detected as environment configuration during --apply mode" \
    bash "${DEPLOY_DIR}/deploy-staging.sh" \
        --apply \
        --env-file "${CUSTOM_FIXTURE}" \
        --api-host "staging-api.custom.internal" \
        --web-host "staging.custom.internal"

# B6: Reject custom fixture containing fixture dummy password
CUSTOM_FIXTURE_PASS="${TEMP_DIR}/custom_pass.env"
cat <<EOF > "${CUSTOM_FIXTURE_PASS}"
APP_NAME=SeWaroeng
APP_ENV=staging
APP_DEBUG=false
APP_URL=https://staging-api.custom.internal
DB_CONNECTION=pgsql
DB_DATABASE=sewaroeng_staging
DB_PASSWORD=staging_fixture_dummy_password
EOF

assert_case \
    "Reject custom fixture with dummy fixture password during --apply" \
    1 \
    "Test fixture detected as environment configuration during --apply mode" \
    bash "${DEPLOY_DIR}/deploy-staging.sh" \
        --apply \
        --env-file "${CUSTOM_FIXTURE_PASS}" \
        --api-host "staging-api.custom.internal" \
        --web-host "staging.custom.internal"

# B7: Reject non-existent --env-file
assert_case \
    "Reject non-existent --env-file" \
    1 \
    "Environment file not found" \
    bash "${DEPLOY_DIR}/deploy-staging.sh" \
        --dry-run \
        --env-file "${TEMP_DIR}/does_not_exist.env" \
        --api-host "staging-api.example.com" \
        --web-host "staging.example.com"

# B8: Reject directory passed as --env-file
assert_case \
    "Reject directory passed as --env-file" \
    1 \
    "directory, not a regular file" \
    bash "${DEPLOY_DIR}/deploy-staging.sh" \
        --dry-run \
        --env-file "${TEMP_DIR}" \
        --api-host "staging-api.example.com" \
        --web-host "staging.example.com"

# B9: Reject empty --env-file
EMPTY_ENV="${TEMP_DIR}/empty.env"
: > "${EMPTY_ENV}"
assert_case \
    "Reject empty --env-file" \
    1 \
    "Environment file is empty" \
    bash "${DEPLOY_DIR}/deploy-staging.sh" \
        --dry-run \
        --env-file "${EMPTY_ENV}" \
        --api-host "staging-api.example.com" \
        --web-host "staging.example.com"

# B10: Reject binary/non-printable bytes in --env-file
BINARY_ENV="${TEMP_DIR}/corrupt_binary.env"
printf 'APP_NAME=SeWaroeng\x00\x01\x02\nAPP_ENV=staging\n' > "${BINARY_ENV}"
assert_case \
    "Reject binary/corrupt content in --env-file" \
    1 \
    "contains binary or non-printable characters" \
    bash "${DEPLOY_DIR}/deploy-staging.sh" \
        --dry-run \
        --env-file "${BINARY_ENV}" \
        --api-host "staging-api.example.com" \
        --web-host "staging.example.com"

# B11: Reject non KEY=VALUE syntax in --env-file
INVALID_SYNTAX_ENV="${TEMP_DIR}/invalid_syntax.env"
cat <<EOF > "${INVALID_SYNTAX_ENV}"
APP_NAME=SeWaroeng
this is not a valid env line!
APP_ENV=staging
EOF

assert_case \
    "Reject non KEY=VALUE syntax in --env-file" \
    1 \
    "violates KEY=VALUE format" \
    bash "${DEPLOY_DIR}/deploy-staging.sh" \
        --dry-run \
        --env-file "${INVALID_SYNTAX_ENV}" \
        --api-host "staging-api.example.com" \
        --web-host "staging.example.com"

# B12: Reject shell script control structures in --env-file
SHELL_CODE_ENV="${TEMP_DIR}/shell_code.env"
cat <<EOF > "${SHELL_CODE_ENV}"
APP_NAME=SeWaroeng
if [[ -f /etc/passwd ]]; then echo pwned; fi
APP_ENV=staging
EOF

assert_case \
    "Reject shell script control structures in --env-file" \
    1 \
    "violates KEY=VALUE format" \
    bash "${DEPLOY_DIR}/deploy-staging.sh" \
        --dry-run \
        --env-file "${SHELL_CODE_ENV}" \
        --api-host "staging-api.example.com" \
        --web-host "staging.example.com"

# B12B: Reject unquoted whitespace in value (Dotenv parity)
UNQUOTED_SPACE_ENV="${TEMP_DIR}/unquoted_space.env"
cat <<EOF > "${UNQUOTED_SPACE_ENV}"
APP_NAME=SeWaroeng Cafe
APP_ENV=staging
EOF

assert_case \
    "Reject unquoted whitespace in value (Dotenv parity)" \
    1 \
    "has unquoted whitespace in value" \
    bash "${DEPLOY_DIR}/deploy-staging.sh" \
        --dry-run \
        --env-file "${UNQUOTED_SPACE_ENV}" \
        --api-host "staging-api.example.com" \
        --web-host "staging.example.com"

# B12C: Reject unclosed quote in value (Dotenv parity)
UNCLOSED_QUOTE_ENV="${TEMP_DIR}/unclosed_quote.env"
cat <<EOF > "${UNCLOSED_QUOTE_ENV}"
APP_NAME="SeWaroeng Cafe
APP_ENV=staging
EOF

assert_case \
    "Reject unclosed double quote in value" \
    1 \
    "has unclosed double quote" \
    bash "${DEPLOY_DIR}/deploy-staging.sh" \
        --dry-run \
        --env-file "${UNCLOSED_QUOTE_ENV}" \
        --api-host "staging-api.example.com" \
        --web-host "staging.example.com"

# B13: Reject shell injection in DB_DATABASE identifier
INJECTION_DB_ENV="${TEMP_DIR}/injection_db.env"
cat <<EOF > "${INJECTION_DB_ENV}"
APP_NAME=SeWaroeng
APP_ENV=staging
APP_DEBUG=false
APP_URL=https://staging-api.custom.internal
DB_CONNECTION=pgsql
DB_DATABASE=sewaroeng_staging;reboot
EOF

assert_case \
    "Reject shell injection in DB_DATABASE identifier" \
    1 \
    "Database name .* contains invalid characters" \
    bash "${DEPLOY_DIR}/deploy-staging.sh" \
        --dry-run \
        --env-file "${INJECTION_DB_ENV}" \
        --api-host "staging-api.custom.internal" \
        --web-host "staging.custom.internal"

# B14: Reject invalid non-numeric DB_PORT
INVALID_PORT_ENV="${TEMP_DIR}/invalid_port.env"
cat <<EOF > "${INVALID_PORT_ENV}"
APP_NAME=SeWaroeng
APP_ENV=staging
APP_DEBUG=false
APP_URL=https://staging-api.custom.internal
DB_CONNECTION=pgsql
DB_DATABASE=sewaroeng_staging
DB_PORT=5432;bad
EOF

assert_case \
    "Reject non-numeric DB_PORT" \
    1 \
    "DB_PORT .* is not a valid integer" \
    bash "${DEPLOY_DIR}/deploy-staging.sh" \
        --dry-run \
        --env-file "${INVALID_PORT_ENV}" \
        --api-host "staging-api.custom.internal" \
        --web-host "staging.custom.internal"

# B15: Reject staging targeting canonical production API hostname
assert_case \
    "Reject staging targeting planned production API hostname" \
    1 \
    "ENVIRONMENT LEAK HAZARD" \
    bash "${DEPLOY_DIR}/validate-domains.sh" \
        --mode staging \
        --api-host "api.sewaroeng.neuracakrawira.asia" \
        --web-host "staging-web.neuracakrawira.asia"

# B16: Reject staging targeting canonical production Web hostname
assert_case \
    "Reject staging targeting planned production Web hostname" \
    1 \
    "ENVIRONMENT LEAK HAZARD" \
    bash "${DEPLOY_DIR}/validate-domains.sh" \
        --mode staging \
        --api-host "staging-api.neuracakrawira.asia" \
        --web-host "sewaroeng.neuracakrawira.asia"

# B17: Reject identical API and Web hostnames
assert_case \
    "Reject identical API and Web hostnames" \
    1 \
    "identical" \
    bash "${DEPLOY_DIR}/validate-domains.sh" \
        --mode staging \
        --api-host "staging.sewaroeng.org" \
        --web-host "staging.sewaroeng.org"

# B18: Reject conflicting execution modes (--dry-run with --apply)
assert_case \
    "Reject conflicting execution modes (--dry-run with --apply)" \
    1 \
    "Conflicting execution modes" \
    bash "${DEPLOY_DIR}/deploy-staging.sh" \
        --dry-run \
        --apply \
        --env-file "${DEPLOY_DIR}/fixtures/staging.env.fixture" \
        --api-host "staging-api.test-fixture.internal" \
        --web-host "staging.test-fixture.internal"

# B19: Reject conflicting execution modes (--apply with --dry-run)
assert_case \
    "Reject conflicting execution modes (--apply with --dry-run)" \
    1 \
    "Conflicting execution modes" \
    bash "${DEPLOY_DIR}/deploy-staging.sh" \
        --apply \
        --dry-run \
        --env-file "${DEPLOY_DIR}/fixtures/staging.env.fixture" \
        --api-host "staging-api.test-fixture.internal" \
        --web-host "staging.test-fixture.internal"

# B20: Reject conflicting duplicate --env-file options
assert_case \
    "Reject conflicting duplicate --env-file options" \
    1 \
    "Conflicting --env-file options" \
    bash "${DEPLOY_DIR}/deploy-staging.sh" \
        --dry-run \
        --env-file "${DEPLOY_DIR}/fixtures/staging.env.fixture" \
        --env-file "${CUSTOM_FIXTURE}" \
        --api-host "staging-api.test-fixture.internal" \
        --web-host "staging.test-fixture.internal"

# B21: Reject conflicting duplicate --api-host options
assert_case \
    "Reject conflicting duplicate --api-host options" \
    1 \
    "Conflicting --api-host options" \
    bash "${DEPLOY_DIR}/deploy-staging.sh" \
        --dry-run \
        --env-file "${DEPLOY_DIR}/fixtures/staging.env.fixture" \
        --api-host "staging-api-1.test-fixture.internal" \
        --api-host "staging-api-2.test-fixture.internal" \
        --web-host "staging.test-fixture.internal"

# B22: Reject empty argument token ("")
assert_case \
    "Reject empty argument token in deploy-staging.sh" \
    1 \
    "Empty argument passed to command line" \
    bash "${DEPLOY_DIR}/deploy-staging.sh" ""

# B23: Reject unknown CLI option in deploy-staging.sh
assert_case \
    "Reject unknown CLI option in deploy-staging.sh" \
    1 \
    "Unknown argument: --dangerous-unrecognized-flag" \
    bash "${DEPLOY_DIR}/deploy-staging.sh" \
        --dangerous-unrecognized-flag

# B24: Reject missing argument value to --api-host
assert_case \
    "Reject missing argument value to --api-host" \
    1 \
    "--api-host requires a non-empty hostname argument" \
    bash "${DEPLOY_DIR}/deploy-staging.sh" \
        --api-host

# B25: Reject preflight.sh combining --strict with --skip-connectivity
assert_case \
    "Reject preflight.sh combining --strict with --skip-connectivity" \
    1 \
    "Cannot combine --strict with --skip-connectivity" \
    bash "${DEPLOY_DIR}/preflight.sh" \
        --strict \
        --skip-connectivity \
        --env-file "${DEPLOY_DIR}/fixtures/staging.env.fixture"

# B26: Reject render-nginx-config.sh combining --dry-run and --install
assert_case \
    "Reject render-nginx-config.sh combining --dry-run and --install" \
    1 \
    "cannot combine --dry-run and --install" \
    bash "${DEPLOY_DIR}/render-nginx-config.sh" \
        --dry-run \
        --install \
        --api-host "staging-api.sewaroeng.org" \
        --web-host "staging.sewaroeng.org"

# B27: Reject non-staging APP_ENV in deploy-staging.sh
NON_STAGING_ENV="${TEMP_DIR}/prod.env"
cat <<EOF > "${NON_STAGING_ENV}"
APP_NAME=SeWaroeng
APP_ENV=production
APP_DEBUG=false
APP_URL=https://staging-api.sewaroeng.org
DB_CONNECTION=pgsql
DB_DATABASE=sewaroeng_staging
EOF

assert_case \
    "Reject APP_ENV=production in staging deployment script" \
    1 \
    "APP_ENV is 'production'. Staging script is only permitted to target 'staging'" \
    bash "${DEPLOY_DIR}/deploy-staging.sh" \
        --dry-run \
        --env-file "${NON_STAGING_ENV}" \
        --api-host "staging-api.sewaroeng.org" \
        --web-host "staging.sewaroeng.org"

# B28: Reject production database name pattern
PROD_DB_ENV="${TEMP_DIR}/prod_db.env"
cat <<EOF > "${PROD_DB_ENV}"
APP_NAME=SeWaroeng
APP_ENV=staging
APP_DEBUG=false
APP_URL=https://staging-api.sewaroeng.org
DB_CONNECTION=pgsql
DB_DATABASE=sewaroeng_production_db
EOF

assert_case \
    "Reject database name resembling production in staging script" \
    1 \
    "Database name 'sewaroeng_production_db' resembles production" \
    bash "${DEPLOY_DIR}/deploy-staging.sh" \
        --dry-run \
        --env-file "${PROD_DB_ENV}" \
        --api-host "staging-api.sewaroeng.org" \
        --web-host "staging.sewaroeng.org"

# ==============================================================================
# Category C: Production Pipeline & Preflight Safety Guards (Sprint 17)
# ==============================================================================
echo -e "\n${BOLD}--- Category C: Production Pipeline & Preflight Safety Guards ---${NC}"

VALID_PROD_ENV="${TEMP_DIR}/valid_prod.env"
cat <<EOF > "${VALID_PROD_ENV}"
APP_NAME=SeWaroeng
APP_ENV=production
APP_KEY="base64:u1vOq9P0r2s4t6u8v0w2x4y6z8A0B2C4D6E8F0G2H4I="
APP_DEBUG=false
APP_URL=https://api.sewaroeng.neuracakrawira.asia
CORS_ALLOWED_ORIGINS=https://sewaroeng.neuracakrawira.asia
DB_CONNECTION=pgsql
DB_HOST=127.0.0.1
DB_PORT=5432
DB_DATABASE=sewaroeng_production
DB_USERNAME=sewaroeng_prod_app
DB_PASSWORD=legitimate_strong_prod_pass_123!
CACHE_STORE=database
QUEUE_CONNECTION=database
SESSION_DRIVER=database
DB_MIGRATOR_USERNAME=sewaroeng_prod_migrator
DB_MIGRATOR_PASSWORD=legitimate_migrator_pass_123!
EOF

# C1: preflight-production.sh dry-run simulation
assert_case \
    "preflight-production.sh simulation with valid prod env" \
    0 \
    "OVERALL STATUS: FULL_PREFLIGHT_PASSED" \
    bash "${DEPLOY_DIR}/preflight-production.sh" \
        --env-file "${VALID_PROD_ENV}" \
        --skip-connectivity \
        --skip-php-check

# C2: deploy-production.sh dry-run simulation
assert_case \
    "deploy-production.sh --dry-run with valid prod env" \
    0 \
    "DRY-RUN DEPLOYMENT PLAN" \
    bash "${DEPLOY_DIR}/deploy-production.sh" \
        --dry-run \
        --env-file "${VALID_PROD_ENV}" \
        --skip-connectivity \
        --skip-php-check

# C3: deploy-production.sh rejects --apply with --skip-connectivity
assert_case \
    "Reject deploy-production.sh --apply with --skip-connectivity" \
    1 \
    "Cannot combine --apply with --skip-connectivity in production" \
    bash "${DEPLOY_DIR}/deploy-production.sh" \
        --apply \
        --env-file "${VALID_PROD_ENV}" \
        --skip-connectivity

# C4: deploy-production.sh rejects --apply with --skip-backup
assert_case \
    "Reject deploy-production.sh --apply with --skip-backup" \
    1 \
    "Cannot combine --apply with --skip-backup in production" \
    bash "${DEPLOY_DIR}/deploy-production.sh" \
        --apply \
        --env-file "${VALID_PROD_ENV}" \
        --skip-backup

# C5: deploy-production.sh rejects APP_ENV=staging
STAGING_FOR_PROD_ENV="${TEMP_DIR}/staging_for_prod.env"
cp "${VALID_PROD_ENV}" "${STAGING_FOR_PROD_ENV}"
sed -i 's/APP_ENV=production/APP_ENV=staging/' "${STAGING_FOR_PROD_ENV}"

assert_case \
    "Reject deploy-production.sh targeting APP_ENV=staging" \
    1 \
    "APP_ENV is 'staging'. Production script is only permitted to target 'production'" \
    bash "${DEPLOY_DIR}/deploy-production.sh" \
        --dry-run \
        --env-file "${STAGING_FOR_PROD_ENV}"

# C6: deploy-production.sh rejects non-production database name
STAGING_DB_FOR_PROD="${TEMP_DIR}/staging_db_for_prod.env"
cp "${VALID_PROD_ENV}" "${STAGING_DB_FOR_PROD}"
sed -i 's/DB_DATABASE=sewaroeng_production/DB_DATABASE=sewaroeng_staging/' "${STAGING_DB_FOR_PROD}"

assert_case \
    "Reject deploy-production.sh targeting staging database" \
    1 \
    "Database name 'sewaroeng_staging' resembles non-production" \
    bash "${DEPLOY_DIR}/deploy-production.sh" \
        --dry-run \
        --env-file "${STAGING_DB_FOR_PROD}"

# C7: deploy-production.sh rejects non-canonical production hostnames
assert_case \
    "Reject deploy-production.sh with mismatched API hostname" \
    1 \
    "Production API host must be exactly 'api.sewaroeng.neuracakrawira.asia'" \
    bash "${DEPLOY_DIR}/deploy-production.sh" \
        --dry-run \
        --env-file "${VALID_PROD_ENV}" \
        --api-host "staging-api.example.com"

# C8: deploy-production.sh rejects fixture file
assert_case \
    "Reject deploy-production.sh using fixture file" \
    1 \
    "Cannot deploy using fixture file" \
    bash "${DEPLOY_DIR}/deploy-production.sh" \
        --dry-run \
        --env-file "${DEPLOY_DIR}/fixtures/staging.env.fixture"

# C9: preflight-production.sh rejects APP_DEBUG=true
DEBUG_TRUE_ENV="${TEMP_DIR}/debug_true.env"
cp "${VALID_PROD_ENV}" "${DEBUG_TRUE_ENV}"
sed -i 's/APP_DEBUG=false/APP_DEBUG=true/' "${DEBUG_TRUE_ENV}"

assert_case \
    "Reject preflight-production.sh with APP_DEBUG=true" \
    1 \
    "SECURITY VULNERABILITY: APP_DEBUG is 'true'" \
    bash "${DEPLOY_DIR}/preflight-production.sh" \
        --env-file "${DEBUG_TRUE_ENV}" \
        --skip-connectivity \
        --skip-php-check

# C10: deploy-production.sh rejects --apply without migrator credentials
NO_MIGRATOR_ENV="${TEMP_DIR}/no_migrator.env"
cp "${VALID_PROD_ENV}" "${NO_MIGRATOR_ENV}"
sed -i '/DB_MIGRATOR_PASSWORD/d' "${NO_MIGRATOR_ENV}"

assert_case \
    "Reject deploy-production.sh --apply without migrator credentials" \
    1 \
    "Cannot execute migrations in production without migrator credentials" \
    bash "${DEPLOY_DIR}/deploy-production.sh" \
        --apply \
        --env-file "${NO_MIGRATOR_ENV}"

# ==============================================================================
# SUMMARY REPORT
# ==============================================================================
echo -e "\n${BOLD}====================================================================${NC}"
echo -e "${BOLD}                       Test Suite Summary                           ${NC}"
echo -e "${BOLD}====================================================================${NC}"
echo -e "Total Test Cases:    ${BOLD}${TEST_COUNT}${NC}"
echo -e "Passed:              ${GREEN}${BOLD}${PASS_COUNT}${NC}"
echo -e "Failed:              ${RED}${BOLD}${FAIL_COUNT}${NC}"

if [[ "${FAIL_COUNT}" -eq 0 ]]; then
    echo -e "\n${GREEN}${BOLD}ALL CLI SAFETY AND GUARD TESTS PASSED SUCCESSFULLY.${NC}"
    echo -e "Verification Proof: No mutating commands or remote servers were invoked."
    exit 0
else
    echo -e "\n${RED}${BOLD}REGRESSION FAILURES DETECTED! (${FAIL_COUNT} failed)${NC}"
    exit 1
fi

