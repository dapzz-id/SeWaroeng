<?php

namespace App\Domain\Finance\Export;

/**
 * Normalizer and security sanitizer for financial export representations.
 *
 * Extends FinancialReportNormalizer to provide canonical mapping and security sanitization
 * against CSV formula injection while preserving accounting semantics.
 */
class FinancialExportNormalizer extends FinancialReportNormalizer {}
