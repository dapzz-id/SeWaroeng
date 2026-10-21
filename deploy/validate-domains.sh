#!/usr/bin/env bash
# ==============================================================================
# SeWaroeng — Domain and Environment Configuration Validator
# Validates FQDN syntax, URI schemes, environment isolation, and Flutter API URLs.
# Target: Bash 4+, Linux/macOS/Git-Bash/WSL
# ==============================================================================

set -euo pipefail

RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
BOLD='\033[1m'
NC='\033[0m'

MODE="staging"
API_HOST="${STAGING_API_HOST:-}"
WEB_HOST="${STAGING_WEB_HOST:-}"
APP_URL=""
FLUTTER_API_URL=""
QUIET=false

# Canonical Planned Production Hosts
PROD_API_HOST="api.sewaroeng.neuracakrawira.asia"
PROD_WEB_HOST="sewaroeng.neuracakrawira.asia"

print_usage() {
    echo -e "${BOLD}Usage:${NC} $0 [OPTIONS]"
    echo ""
    echo "Options:"
    echo "  --mode [staging|production]     Target validation environment (default: staging)"
    echo "  --api-host <hostname>           Backend API hostname / FQDN (or via STAGING_API_HOST)"
    echo "  --web-host <hostname>           Frontend Web hostname / FQDN (or via STAGING_WEB_HOST)"
    echo "  --app-url <url>                 Backend APP_URL from .env to cross-validate"
    echo "  --flutter-api-url <url>         Flutter API_BASE_URL to cross-validate"
    echo "  --quiet                         Suppress informational output, exit code only"
    echo "  -h, --help                      Show this help message"
    echo ""
}

MODE_SET=false
API_HOST_SET=false
WEB_HOST_SET=false
APP_URL_SET=false
FLUTTER_API_URL_SET=false

while [[ $# -gt 0 ]]; do
    if [[ -z "$1" ]]; then
        echo -e "${RED}Error: Empty argument passed to command line.${NC}" >&2
        exit 1
    fi

    case "$1" in
        --mode)
            if [[ $# -lt 2 || -z "$2" || "$2" == --* ]]; then
                echo -e "${RED}Error: --mode requires an argument [staging|production].${NC}" >&2
                exit 1
            fi
            if [[ "${MODE_SET}" == "true" && "${MODE}" != "$2" ]]; then
                echo -e "${RED}Error: Conflicting --mode options specified ('${MODE}' vs '$2').${NC}" >&2
                exit 1
            fi
            MODE="$2"
            MODE_SET=true
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
        --app-url)
            if [[ $# -lt 2 || -z "$2" || "$2" == --* ]]; then
                echo -e "${RED}Error: --app-url requires a non-empty URL argument.${NC}" >&2
                exit 1
            fi
            if [[ "${APP_URL_SET}" == "true" && "${APP_URL}" != "$2" ]]; then
                echo -e "${RED}Error: Conflicting --app-url options specified ('${APP_URL}' vs '$2').${NC}" >&2
                exit 1
            fi
            APP_URL="$2"
            APP_URL_SET=true
            shift 2
            ;;
        --flutter-api-url)
            if [[ $# -lt 2 || -z "$2" || "$2" == --* ]]; then
                echo -e "${RED}Error: --flutter-api-url requires a non-empty URL argument.${NC}" >&2
                exit 1
            fi
            if [[ "${FLUTTER_API_URL_SET}" == "true" && "${FLUTTER_API_URL}" != "$2" ]]; then
                echo -e "${RED}Error: Conflicting --flutter-api-url options specified ('${FLUTTER_API_URL}' vs '$2').${NC}" >&2
                exit 1
            fi
            FLUTTER_API_URL="$2"
            FLUTTER_API_URL_SET=true
            shift 2
            ;;
        --quiet)
            QUIET=true
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

log_info() {
    if [[ "${QUIET}" == "false" ]]; then
        echo -e "  [${BLUE}INFO${NC}] $1"
    fi
}

log_pass() {
    if [[ "${QUIET}" == "false" ]]; then
        echo -e "  [${GREEN}PASS${NC}] $1"
    fi
}

log_fail() {
    echo -e "  [${RED}FAIL${NC}] $1" >&2
}

log_warn() {
    if [[ "${QUIET}" == "false" ]]; then
        echo -e "  [${YELLOW}WARN${NC}] $1"
    fi
}

validate_fqdn_syntax() {
    local host="$1"
    local label="$2"

    if [[ -z "${host}" ]]; then
        log_fail "${label} is empty or unconfigured! Staging hostnames must be explicitly supplied."
        return 1
    fi

    # Disallow protocols/schemes
    if [[ "${host}" =~ ^https?:// ]]; then
        log_fail "${label} contains a URI scheme ('${host}'). Provide only the hostname/FQDN (e.g. api.example.com)."
        return 1
    fi

    # Disallow trailing or embedded slashes / paths
    if [[ "${host}" == *"/"* ]]; then
        log_fail "${label} contains path slashes ('${host}'). Paths are not permitted in hostnames."
        return 1
    fi

    # Disallow port numbers
    if [[ "${host}" == *":"* ]]; then
        log_fail "${label} contains a port specification ('${host}'). Ports are not permitted in hostnames."
        return 1
    fi

    # Disallow credentials / userinfo
    if [[ "${host}" == *"@"* ]]; then
        log_fail "${label} contains user credentials ('${host}')."
        return 1
    fi

    # Disallow spaces
    if [[ "${host}" =~ [[:space:]] ]]; then
        log_fail "${label} contains whitespace ('${host}')."
        return 1
    fi

    # RFC 1123 Hostname Regex
    local rfc_regex='^([a-zA-Z0-9]|[a-zA-Z0-9][a-zA-Z0-9\-]*[a-zA-Z0-9])(\.([a-zA-Z0-9]|[a-zA-Z0-9][a-zA-Z0-9\-]*[a-zA-Z0-9]))+$'
    if [[ ! "${host}" =~ ${rfc_regex} ]]; then
        log_fail "${label} '${host}' is not a valid fully qualified domain name (RFC 1123)."
        return 1
    fi

    log_pass "${label} syntax valid: ${host}"
    return 0
}

validate_domains() {
    local errors=0

    if [[ "${QUIET}" == "false" ]]; then
        echo -e "${BOLD}=== SeWaroeng Domain Configuration Validation (Mode: ${MODE}) ===${NC}"
    fi

    case "${MODE}" in
        staging)
            # 1. Require explicit staging hostnames
            if [[ -z "${API_HOST}" ]]; then
                log_fail "STAGING_API_HOST is required. Staging hostnames have not been selected and must be explicitly provided."
                errors=$((errors + 1))
            fi

            if [[ -z "${WEB_HOST}" ]]; then
                log_fail "STAGING_WEB_HOST is required. Staging hostnames have not been selected and must be explicitly provided."
                errors=$((errors + 1))
            fi

            if [[ ${errors} -gt 0 ]]; then
                return 1
            fi

            # 2. Syntax validation
            validate_fqdn_syntax "${API_HOST}" "Staging API Host" || errors=$((errors + 1))
            validate_fqdn_syntax "${WEB_HOST}" "Staging Web Host" || errors=$((errors + 1))

            # 3. Collision guard: API and Web hostnames must be distinct
            if [[ "${API_HOST}" == "${WEB_HOST}" ]]; then
                log_fail "CRITICAL: API host and Web host are identical ('${API_HOST}'). They must be separate hostnames."
                errors=$((errors + 1))
            else
                log_pass "API host and Web host are distinct"
            fi

            # 4. Environment boundary guard: Staging must NOT target actual production hostnames
            if [[ "${API_HOST}" == "${PROD_API_HOST}" || "${WEB_HOST}" == "${PROD_WEB_HOST}" || "${API_HOST}" == "${PROD_WEB_HOST}" || "${WEB_HOST}" == "${PROD_API_HOST}" ]]; then
                log_fail "ENVIRONMENT LEAK HAZARD: Staging host configuration points to actual production hostname ('${PROD_API_HOST}' / '${PROD_WEB_HOST}')!"
                errors=$((errors + 1))
            else
                log_pass "Staging hostnames are isolated from planned production hostnames"
            fi

            # 5. Cross-validate APP_URL if provided
            if [[ -n "${APP_URL}" ]]; then
                local expected_app_url="https://${API_HOST}"
                local clean_app_url="${APP_URL%/}"
                if [[ "${clean_app_url}" != "${expected_app_url}" ]]; then
                    log_fail "APP_URL mismatch: .env has '${clean_app_url}', but configured API host requires '${expected_app_url}'."
                    errors=$((errors + 1))
                else
                    log_pass "APP_URL matches configured Staging API Host (${expected_app_url})"
                fi
            fi

            # 6. Cross-validate Flutter API_BASE_URL if provided
            if [[ -n "${FLUTTER_API_URL}" ]]; then
                local clean_flutter_url="${FLUTTER_API_URL%/}"
                if [[ ! "${clean_flutter_url}" =~ ^https:// ]]; then
                    log_fail "Flutter API_BASE_URL must use HTTPS scheme (got: '${clean_flutter_url}')."
                    errors=$((errors + 1))
                elif [[ "${clean_flutter_url}" != "https://${API_HOST}" && "${clean_flutter_url}" != "https://${API_HOST}/api/v1" ]]; then
                    log_fail "Flutter API_BASE_URL '${clean_flutter_url}' authority does not point to Staging API Host 'https://${API_HOST}'."
                    errors=$((errors + 1))
                else
                    log_pass "Flutter API_BASE_URL correctly targets Staging API Host (${clean_flutter_url})"
                fi
            fi
            ;;

        production)
            # Production mode: verify against the planned production targets
            API_HOST="${API_HOST:-${PROD_API_HOST}}"
            WEB_HOST="${WEB_HOST:-${PROD_WEB_HOST}}"

            if [[ "${API_HOST}" != "${PROD_API_HOST}" ]]; then
                log_fail "Production API Host must be exactly '${PROD_API_HOST}' (got: '${API_HOST}')."
                errors=$((errors + 1))
            else
                log_pass "Production API Host matches planned target: ${PROD_API_HOST}"
            fi

            if [[ "${WEB_HOST}" != "${PROD_WEB_HOST}" ]]; then
                log_fail "Production Web Host must be exactly '${PROD_WEB_HOST}' (got: '${WEB_HOST}')."
                errors=$((errors + 1))
            else
                log_pass "Production Web Host matches planned target: ${PROD_WEB_HOST}"
            fi

            if [[ -n "${APP_URL}" ]]; then
                local clean_app_url="${APP_URL%/}"
                if [[ "${clean_app_url}" != "https://${PROD_API_HOST}" ]]; then
                    log_fail "Production APP_URL must be exactly 'https://${PROD_API_HOST}' (got: '${clean_app_url}')."
                    errors=$((errors + 1))
                else
                    log_pass "Production APP_URL matches planned API target"
                fi
            fi

            if [[ -n "${FLUTTER_API_URL}" ]]; then
                local clean_flutter_url="${FLUTTER_API_URL%/}"
                if [[ "${clean_flutter_url}" != "https://${PROD_API_HOST}" && "${clean_flutter_url}" != "https://${PROD_API_HOST}/api/v1" ]]; then
                    log_fail "Production Flutter API URL must target 'https://${PROD_API_HOST}/api/v1' (got: '${clean_flutter_url}')."
                    errors=$((errors + 1))
                else
                    log_pass "Production Flutter API URL correctly targets planned production API"
                fi
            fi

            log_warn "Production hosts are PLANNED TARGETS ONLY. Remote DNS, TLS certificates, and infrastructure are NOT yet active."
            ;;

        *)
            log_fail "Invalid validation mode: '${MODE}'. Permitted: staging, production."
            errors=$((errors + 1))
            ;;
    esac

    if [[ ${errors} -eq 0 ]]; then
        if [[ "${QUIET}" == "false" ]]; then
            echo -e "\n${GREEN}${BOLD}DOMAIN VALIDATION PASSED:${NC} Host configuration is valid and isolated."
        fi
        return 0
    else
        echo -e "\n${RED}${BOLD}DOMAIN VALIDATION FAILED:${NC} Found ${errors} configuration errors." >&2
        return 1
    fi
}

validate_domains
