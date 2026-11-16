<?php

namespace App\Http\Responses;

use App\Support\Export\TableExport;
use Closure;
use Illuminate\Contracts\Support\Responsable;
use RuntimeException;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Downloads a list export: streams the table without building the file in
 * memory. Once the file is written, passes the row count to $done (for the log).
 */
final class TableDownload implements Responsable
{
    /**
     * @param  iterable<mixed>  $rows  Export rows (models etc.)
     * @param  string  $name  File name prefix; the date and extension are appended
     * @param  string  $title  Table name (the XLSX sheet)
     * @param  (Closure(int): void)|null  $done
     */
    public function __construct(
        private readonly TableExport $export,
        private readonly iterable $rows,
        private readonly string $name,
        private readonly string $title,
        private readonly ?Closure $done = null,
    ) {}

    public function toResponse($request): StreamedResponse
    {
        $response = new StreamedResponse($this->stream(...), 200, [
            'Content-Type' => $this->export->format()->contentType(),
        ]);
        $response->headers->set('Content-Disposition', HeaderUtils::makeDisposition(
            HeaderUtils::DISPOSITION_ATTACHMENT,
            $this->export->filename($this->name),
        ));

        return $response;
    }

    private function stream(): void
    {
        $out = fopen('php://output', 'wb');
        if ($out === false) {
            throw new RuntimeException('Не удалось открыть поток вывода для выгрузки');
        }

        try {
            $count = $this->export->write($out, $this->rows, $this->title);
        } finally {
            fclose($out);
        }

        if ($this->done) {
            ($this->done)($count);
        }
    }
}
