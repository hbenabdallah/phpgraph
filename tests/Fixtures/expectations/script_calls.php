<?php

// A script outside any function (legacy pages, batches, PDF reports): the file calls the methods of its variables, which
// keep their types across a function or a class declared in the middle of it (jac2-web: `$pdf = new PDFbt(); $pdf->initPDF()`).

namespace App;

class Report
{
    public function render(): string { return ''; }
    public static function make(): Report { return new Report(); }
    public function page(): Page { return new Page(); }
}

class Page
{
    public function number(): int { return 0; }
}

$report = new Report();
$report->render(); // @phpgraph-expect calls App\Report::render INFERRED
$made = Report::make(); // @phpgraph-expect calls App\Report::make INFERRED
$made->page()->number(); // @phpgraph-expect calls App\Report::page INFERRED @phpgraph-expect calls App\Page::number INFERRED

function footer(Page $page): int
{
    // The script's variables are not the function's.
    $report = $page;

    return $report->number(); // @phpgraph-expect calls App\Page::number INFERRED
}

// Back in the script after the function: still a Report.
$report->render(); // @phpgraph-expect calls App\Report::render INFERRED

if ($argc > 1) {
    $page = new Page();
} else {
    $page = $report->page(); // @phpgraph-expect calls App\Report::page INFERRED
}
$page->number(); // @phpgraph-expect calls App\Page::number INFERRED

class Footer
{
    public function render(): string { return ''; }
}

// After a class declared in the script, too.
$report->render(); // @phpgraph-expect calls App\Report::render INFERRED

$items = array_map(static fn (Page $item): int => $item->number(), [$page]); // @phpgraph-expect calls App\Page::number INFERRED
