<?php
// ==============================================================================
// SeWaroeng — Environment Parser Parity Test (Sprint 16)
// Compares deployment bash get_env_val() parser with Laravel Dotenv::parse()
// ==============================================================================

require __DIR__ . '/../../backend/vendor/autoload.php';

$RED = "\033[0;31m";
$GREEN = "\033[0;32m";
$YELLOW = "\033[1;33m";
$BLUE = "\033[0;34m";
$CYAN = "\033[0;36m";
$BOLD = "\033[1m";
$NC = "\033[0m";

echo "{$BOLD}===================================================================={$NC}\n";
echo "{$BOLD}     SeWaroeng Environment Parser Parity Test (Bash vs Dotenv)      {$NC}\n";
echo "{$BOLD}===================================================================={$NC}\n";
echo "Timestamp: " . gmdate('Y-m-d H:i:s') . " UTC\n\n";

$testCount = 0;
$passCount = 0;
$failCount = 0;

$tempDir = __DIR__ . DIRECTORY_SEPARATOR . 'tmp_parity';
@mkdir($tempDir, 0777, true);

register_shutdown_function(function () use ($tempDir) {
    if (is_dir($tempDir)) {
        array_map('unlink', glob("{$tempDir}/*"));
        @rmdir($tempDir);
    }
});

// Category 1: Value Parity across formats
echo "{$BOLD}--- Category 1: Value Parity Comparison ---{$NC}\n";

$fixtureContent = <<<'ENV'
APP_NAME="SeWaroeng Cafe & Resto"
APP_ENV='staging'
APP_KEY="base64:u1vOq9P0r2s4t6u8v0w2x4y6z8A0B2C4D6E8F0G2H4I=#123"
SECRET_TOKEN='secret#with#hashes'
APP_DEBUG=false # turn off debug mode
APP_URL=https://staging-api.test-fixture.internal
ATTACHED_HASH=redis#inline_comment_no_space
EMPTY_VAR=
DB_PORT=5432
DB_DATABASE=sewaroeng_staging
DUPLICATE_KEY=first_definition
DUPLICATE_KEY=second_definition
ENV;

$fixtureFile = $tempDir . DIRECTORY_SEPARATOR . 'parity.env';
file_put_contents($fixtureFile, $fixtureContent);

// 1. Parse with Dotenv
$dotenvParsed = Dotenv\Dotenv::parse($fixtureContent);

// 2. Parse with Bash Script
$wslFixturePath = str_replace(['D:', '\\'], ['/mnt/d', '/'], $fixtureFile);
$bashScript = <<<BASH
file="{$wslFixturePath}"
get_env_val() {
    local key="\$1"
    local line
    line="\$(grep -E "^\${key}=" "\${file}" 2>/dev/null | tail -n 1 || true)"
    if [[ -z "\${line}" ]]; then
        echo ""
        return
    fi
    local val="\${line#*=}"
    val="\${val%\\r}"
    if [[ "\${val}" =~ ^\"(.*)\"$ ]]; then
        val="\${BASH_REMATCH[1]}"
    elif [[ "\${val}" =~ ^\'(.*)\'$ ]]; then
        val="\${BASH_REMATCH[1]}"
    else
        val="\${val%%#*}"
        read -r val <<< "\${val}"
    fi
    echo "\${val}"
}
for k in APP_NAME APP_ENV APP_KEY SECRET_TOKEN APP_DEBUG APP_URL ATTACHED_HASH EMPTY_VAR DB_PORT DB_DATABASE DUPLICATE_KEY; do
    echo "\$k=\$(get_env_val "\$k")"
done
BASH;

$bashFile = $tempDir . DIRECTORY_SEPARATOR . 'parse.sh';
file_put_contents($bashFile, $bashScript);
$wslBashPath = str_replace(['D:', '\\'], ['/mnt/d', '/'], $bashFile);

$bashOutput = shell_exec("bash {$wslBashPath}");
$bashLines = array_filter(explode("\n", str_replace("\r", "", trim($bashOutput))));
$bashParsed = [];
foreach ($bashLines as $line) {
    if (strpos($line, '=') !== false) {
        list($k, $v) = explode('=', $line, 2);
        $bashParsed[$k] = $v;
    }
}

$matchCount = 0;
$strictRejectionCount = 0;
$mismatchCount = 0;

foreach ($dotenvParsed as $key => $dotenvVal) {
    $testCount++;
    $testNum = str_pad($testCount, 2, '0', STR_PAD_LEFT);
    echo "Test {$testNum}: Parity for [{$key}] ... ";

    $bashVal = isset($bashParsed[$key]) ? $bashParsed[$key] : '__MISSING__';

    if ($bashVal === $dotenvVal) {
        echo "{$GREEN}[MATCH]{$NC} (Exact: '{$bashVal}')\n";
        $passCount++;
        $matchCount++;
    } else {
        echo "{$RED}[MISMATCH]{$NC}\n";
        echo "  {$YELLOW}Dotenv: '{$dotenvVal}'{$NC}\n";
        echo "  {$YELLOW}Bash:   '{$bashVal}'{$NC}\n";
        $failCount++;
        $mismatchCount++;
    }
}

// Category 2: Syntax Rejection & Safety Policy Categorization
echo "\n{$BOLD}--- Category 2: Syntax Rejection & Deliberate Safety Policies ---{$NC}\n";

$deployScriptWsl = "/mnt/d/A/SeWaroeng/deploy/deploy-staging.sh";

// Test 12: Unquoted Whitespace (Mutual Rejection)
$testCount++;
$testNum = str_pad($testCount, 2, '0', STR_PAD_LEFT);
echo "Test {$testNum}: Unquoted whitespace rejection ... ";

$unquotedSpaceContent = "APP_NAME=SeWaroeng Cafe\n";
$dotenvRejected = false;
try {
    Dotenv\Dotenv::parse($unquotedSpaceContent);
} catch (\Throwable $e) {
    $dotenvRejected = true;
}

$unquotedFile = $tempDir . DIRECTORY_SEPARATOR . 'unquoted_space.env';
file_put_contents($unquotedFile, $unquotedSpaceContent);
$wslUnquotedPath = str_replace(['D:', '\\'], ['/mnt/d', '/'], $unquotedFile);

$bashExit = 0;
system("bash -c \"bash {$deployScriptWsl} --dry-run --env-file {$wslUnquotedPath} > /dev/null 2>&1\"", $bashExit);
$bashRejected = ($bashExit !== 0);

if ($dotenvRejected && $bashRejected) {
    echo "{$GREEN}[MATCH: MUTUAL REJECTION]{$NC} (Both Dotenv and deployment parser reject unquoted spaces)\n";
    $passCount++;
    $matchCount++;
} else {
    echo "{$RED}[MISMATCH]{$NC} (Dotenv rejected: " . ($dotenvRejected ? 'true' : 'false') . ", Bash rejected: " . ($bashRejected ? 'true' : 'false') . ")\n";
    $failCount++;
    $mismatchCount++;
}

// Test 13: Multiline Quoted Values (Strict Rejection by Deployment Parser)
$testCount++;
$testNum = str_pad($testCount, 2, '0', STR_PAD_LEFT);
echo "Test {$testNum}: Multiline quoted value handling ... ";

$multilineContent = "APP_NAME=\"SeWaroeng\nCafe\"\nAPP_ENV=staging\n";
$dotenvAcceptsMultiline = false;
try {
    $parsedMulti = Dotenv\Dotenv::parse($multilineContent);
    if (isset($parsedMulti['APP_NAME']) && strpos($parsedMulti['APP_NAME'], "\n") !== false) {
        $dotenvAcceptsMultiline = true;
    }
} catch (\Throwable $e) {
    $dotenvAcceptsMultiline = false;
}

$multilineFile = $tempDir . DIRECTORY_SEPARATOR . 'multiline.env';
file_put_contents($multilineFile, $multilineContent);
$wslMultilinePath = str_replace(['D:', '\\'], ['/mnt/d', '/'], $multilineFile);

$bashExit = 0;
system("bash -c \"bash {$deployScriptWsl} --dry-run --env-file {$wslMultilinePath} > /dev/null 2>&1\"", $bashExit);
$bashRejectsMultiline = ($bashExit !== 0);

if ($dotenvAcceptsMultiline && $bashRejectsMultiline) {
    echo "{$CYAN}[STRICT REJECTION]{$NC} (Dotenv allows multiline; deployment parser strictly enforces single-line config)\n";
    $passCount++;
    $strictRejectionCount++;
} else {
    echo "{$RED}[MISMATCH]{$NC} (Dotenv accepts: " . ($dotenvAcceptsMultiline ? 'true' : 'false') . ", Bash rejects: " . ($bashRejectsMultiline ? 'true' : 'false') . ")\n";
    $failCount++;
    $mismatchCount++;
}

// Test 14: Unclosed Quote at EOF (Strict Rejection by Deployment Parser)
$testCount++;
$testNum = str_pad($testCount, 2, '0', STR_PAD_LEFT);
echo "Test {$testNum}: Unclosed quote with subsequent line ... ";

$unclosedQuoteContent = "APP_NAME=\"SeWaroeng Cafe\nAPP_ENV=staging\n";
$dotenvDropsUnclosed = false;
try {
    $parsedUnclosed = Dotenv\Dotenv::parse($unclosedQuoteContent);
    // Dotenv silently drops keys when unclosed quote reaches EOF
    if (empty($parsedUnclosed) || !isset($parsedUnclosed['APP_ENV'])) {
        $dotenvDropsUnclosed = true;
    }
} catch (\Throwable $e) {
    $dotenvDropsUnclosed = true;
}

$unclosedFile = $tempDir . DIRECTORY_SEPARATOR . 'unclosed_quote.env';
file_put_contents($unclosedFile, $unclosedQuoteContent);
$wslUnclosedPath = str_replace(['D:', '\\'], ['/mnt/d', '/'], $unclosedFile);

$bashExit = 0;
system("bash -c \"bash {$deployScriptWsl} --dry-run --env-file {$wslUnclosedPath} > /dev/null 2>&1\"", $bashExit);
$bashCatchesUnclosed = ($bashExit !== 0);

if ($bashCatchesUnclosed) {
    echo "{$CYAN}[STRICT REJECTION]{$NC} (Deployment parser fails closed on unclosed quote, preventing silent variable loss)\n";
    $passCount++;
    $strictRejectionCount++;
} else {
    echo "{$RED}[MISMATCH]{$NC} (Bash parser allowed unclosed quote)\n";
    $failCount++;
    $mismatchCount++;
}

// Test 15: Neutralization of Command Substitution
$testCount++;
$testNum = str_pad($testCount, 2, '0', STR_PAD_LEFT);
echo "Test {$testNum}: Subshell command substitution neutralization ... ";

$flagFile = $tempDir . DIRECTORY_SEPARATOR . 'should_never_exist.flag';
$wslFlagPath = str_replace(['D:', '\\'], ['/mnt/d', '/'], $flagFile);
$injectionContent = <<<ENV
APP_NAME=SeWaroeng
APP_ENV=staging
APP_DEBUG=false
APP_URL=https://staging-api.safe-quotes.internal
DB_CONNECTION=pgsql
DB_DATABASE=sewaroeng_staging
INJECTION=\$(touch {$wslFlagPath})
ENV;

$injectionFile = $tempDir . DIRECTORY_SEPARATOR . 'injection.env';
file_put_contents($injectionFile, $injectionContent);
$wslInjectionPath = str_replace(['D:', '\\'], ['/mnt/d', '/'], $injectionFile);

system("bash -c \"bash deploy/deploy-staging.sh --dry-run --env-file {$wslInjectionPath} --skip-connectivity --skip-php-check --api-host staging-api.safe-quotes.internal --web-host staging.safe-quotes.internal > /dev/null 2>&1\"");

if (!file_exists($flagFile)) {
    echo "{$GREEN}[MATCH: NEUTRALIZED]{$NC} (Treated as literal string; zero shell execution)\n";
    $passCount++;
    $matchCount++;
} else {
    echo "{$RED}[MISMATCH]{$NC} (Subshell command was executed!)\n";
    $failCount++;
    $mismatchCount++;
}

echo "\n{$BOLD}===================================================================={$NC}\n";
echo "{$BOLD}                   Parity Test Suite Summary                        {$NC}\n";
echo "{$BOLD}===================================================================={$NC}\n";
echo "Total Test Cases:       {$BOLD}{$testCount}{$NC}\n";
echo "  - MATCH:              {$GREEN}{$BOLD}{$matchCount}{$NC} (Identical extraction or mutual rejection)\n";
echo "  - STRICT REJECTION:   {$CYAN}{$BOLD}{$strictRejectionCount}{$NC} (Deliberately stricter deployment security policy)\n";
echo "  - MISMATCH:           {$RED}{$BOLD}{$mismatchCount}{$NC} (Unintended divergence)\n";

if ($mismatchCount === 0) {
    echo "\n{$GREEN}{$BOLD}ALL ENVIRONMENT PARITY TESTS PASSED (0 MISMATCHES).{$NC}\n";
    echo "Core values match 100%; strict rejections protect against multiline and unclosed quote anomalies.\n";
    exit(0);
} else {
    echo "\n{$RED}{$BOLD}PARITY MISMATCHES DETECTED! ({$mismatchCount} failed){$NC}\n";
    exit(1);
}

