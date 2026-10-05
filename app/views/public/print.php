<?php /** The invoice, print-ready: no chrome, a print stylesheet; the browser's "Save as PDF" is the PDF (D9). Data: d, token */ $inv = $d['invoice']; ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex, nofollow"><meta name="referrer" content="no-referrer">
    <title>Invoice <?= e((string) $inv['number']) ?></title>
    <link rel="stylesheet" type="text/css" href="/assets/css/bootstrap.min.css">
    <style>
        body { background: #fff; color: #111; font-size: 13px; } .invoice-document { max-width: 820px; margin: 0 auto; padding: 16px; }
        details > summary { list-style: none; } details[open] > summary { font-weight: 600; }
        @media print { .no-print { display: none !important; } body { font-size: 11px; } details > * { display: block; } .card { border: 0; } thead { display: table-header-group; } tr { page-break-inside: avoid; } }
    </style>
</head>
<body>
    <div class="no-print text-center py-2"><button type="button" class="btn btn-primary" onclick="window.print()" id="print-now-btn">Print or save as PDF</button> <a href="/i/<?= e($token) ?>" class="btn btn-light">Back to the invoice</a></div>
    <?= view('invoices/partials/document.php', ['d' => $d, 'mode' => 'print', 'token' => $token]) ?>
</body>
</html>
