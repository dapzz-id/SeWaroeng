<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>{{ $exportData->title }}</title>
    <style>
        @page {
            margin: 15mm 15mm 20mm 15mm;
            size: A4 portrait;
        }
        body {
            font-family: 'Helvetica Neue', Helvetica, Arial, sans-serif;
            font-size: 9pt;
            line-height: 1.4;
            color: #2d3748;
            margin: 0;
            padding: 0;
        }
        header {
            border-bottom: 2px solid #2b6cb0;
            padding-bottom: 8px;
            margin-bottom: 12px;
        }
        .brand-title {
            font-size: 14pt;
            font-weight: bold;
            color: #2b6cb0;
            margin: 0;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        .report-title {
            font-size: 13pt;
            font-weight: bold;
            color: #1a202c;
            margin: 2px 0 6px 0;
        }
        .meta-grid {
            width: 100%;
            margin-bottom: 12px;
            border-collapse: collapse;
        }
        .meta-grid td {
            font-size: 8.5pt;
            padding: 2px 4px;
            vertical-align: top;
        }
        .meta-label {
            color: #718096;
            font-weight: bold;
            width: 18%;
        }
        .meta-val {
            color: #2d3748;
            width: 32%;
        }
        .kpi-container {
            width: 100%;
            margin-bottom: 14px;
            border-collapse: collapse;
        }
        .kpi-card {
            background-color: #ebf8ff;
            border: 1px solid #bee3f8;
            border-radius: 4px;
            padding: 6px 8px;
            text-align: center;
        }
        .kpi-label {
            font-size: 7.5pt;
            color: #4a5568;
            font-weight: bold;
            text-transform: uppercase;
            margin-bottom: 3px;
        }
        .kpi-value {
            font-size: 10.5pt;
            font-weight: bold;
            color: #2b6cb0;
        }
        .section-title {
            font-size: 10pt;
            font-weight: bold;
            color: #2c5282;
            margin-top: 14px;
            margin-bottom: 6px;
            border-bottom: 1px solid #e2e8f0;
            padding-bottom: 3px;
            text-transform: uppercase;
        }
        table.data-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 12px;
        }
        table.data-table th {
            background-color: #2b6cb0;
            color: #ffffff;
            font-size: 8pt;
            font-weight: bold;
            padding: 5px 6px;
            text-align: left;
            border: 1px solid #2b6cb0;
        }
        table.data-table td {
            padding: 4px 6px;
            border: 1px solid #e2e8f0;
            font-size: 8pt;
        }
        table.data-table tr:nth-child(even) td {
            background-color: #f7fafc;
        }
        table.data-table tr.total-row td {
            background-color: #edf2f7;
            font-weight: bold;
            border-top: 2px solid #cbd5e0;
        }
        .text-right {
            text-align: right;
        }
        .text-center {
            text-align: center;
        }
        footer {
            position: fixed;
            bottom: -12mm;
            left: 0;
            right: 0;
            height: 8mm;
            border-top: 1px solid #e2e8f0;
            padding-top: 4px;
            font-size: 7.5pt;
            color: #a0aec0;
            text-align: center;
        }
    </style>
</head>
<body>
    <footer>
        Dokumen ini dibuat otomatis oleh SeWaroeng Financial Engine &bull; Rahasia & Hak Milik Internal
    </footer>

    <header>
        <div class="brand-title">SeWaroeng Point of Sale & Accounting</div>
        <div class="report-title">{{ $exportData->title }}</div>
    </header>

    <table class="meta-grid">
        <tr>
            <td class="meta-label">Nama Warung:</td>
            <td class="meta-val">{{ $exportData->tenantName }}</td>
            <td class="meta-label">Periode:</td>
            <td class="meta-val">{{ $exportData->period }}</td>
        </tr>
        <tr>
            <td class="meta-label">Outlet:</td>
            <td class="meta-val">{{ $exportData->outletName }}</td>
            <td class="meta-label">Dicetak Pada:</td>
            <td class="meta-val">{{ $exportData->generatedAt }}</td>
        </tr>
    </table>

    @if(!empty($exportData->summaryCards))
        <table class="kpi-container">
            <tr>
                @foreach($exportData->summaryCards as $card)
                    <td style="padding: 2px;">
                        <div class="kpi-card">
                            <div class="kpi-label">{{ $card['label'] }}</div>
                            <div class="kpi-value">{{ $card['value'] }}</div>
                        </div>
                    </td>
                @endforeach
            </tr>
        </table>
    @endif

    @foreach($exportData->sheets as $sheet)
        @foreach($sheet->sections as $section)
            @if($section->title)
                <div class="section-title">{{ $section->title }}</div>
            @endif

            <table class="data-table">
                <thead>
                    <tr>
                        @foreach($section->headers as $idx => $header)
                            <th class="{{ $idx > 0 && (str_contains($header, '(Rp)') || str_contains($header, 'Minor') || str_contains($header, 'Saldo') || str_contains($header, 'Debit') || str_contains($header, 'Kredit')) ? 'text-right' : '' }}">
                                {{ $header }}
                            </th>
                        @endforeach
                    </tr>
                </thead>
                <tbody>
                    @foreach($section->rows as $row)
                        <tr>
                            @foreach($row as $colIdx => $val)
                                <td class="{{ $colIdx > 0 && (is_numeric($val) || (is_string($val) && (str_starts_with($val, 'Rp') || str_starts_with($val, '-Rp')))) ? 'text-right' : '' }}">
                                    {{ $val ?? '' }}
                                </td>
                            @endforeach
                        </tr>
                    @endforeach

                    @if($section->totals !== null)
                        <tr class="total-row">
                            @foreach($section->totals as $colIdx => $val)
                                <td class="{{ $colIdx > 0 && (is_numeric($val) || (is_string($val) && (str_starts_with($val, 'Rp') || str_starts_with($val, '-Rp')))) ? 'text-right' : '' }}">
                                    {{ $val ?? '' }}
                                </td>
                            @endforeach
                        </tr>
                    @endif
                </tbody>
            </table>
        @endforeach
    @endforeach
</body>
</html>
