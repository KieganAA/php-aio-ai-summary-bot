<?php
declare(strict_types=1);

namespace Src\Service;

interface ReportGeneratorInterface
{
    /**
     * @param string $transcript Full transcript of messages.
     * @param array $meta Arbitrary metadata such as chat_title, chat_id, date.
     */
    public function summarize(string $transcript, array $meta): string;

    /**
     * Return style identifier (classic|executive).
     */
    public function getStyle(): string;
}
