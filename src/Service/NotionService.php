<?php
declare(strict_types=1);

namespace Src\Service;

class NotionService
{
    public function __construct(private string $token, private string $databaseId)
    {
    }

    public function addReport(string $title, string $text): void
    {
        if ($this->token === '' || $this->databaseId === '') {
            return;
        }
        $data = [
            'parent' => ['database_id' => $this->databaseId],
            'properties' => [
                'Name' => ['title' => [[ 'text' => ['content' => $title] ]]],
            ],
            'children' => [
                [
                    'object' => 'block',
                    'type' => 'paragraph',
                    'paragraph' => [
                        'rich_text' => [[ 'text' => ['content' => $text] ]]
                    ]
                ]
            ]
        ];
        $ch = curl_init('https://api.notion.com/v1/pages');
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $this->token,
            'Notion-Version: 2022-06-28'
        ]);
        curl_setopt($ch, CURLOPT_TIMEOUT, 600);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 30);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_exec($ch);
        curl_close($ch);
    }
}
