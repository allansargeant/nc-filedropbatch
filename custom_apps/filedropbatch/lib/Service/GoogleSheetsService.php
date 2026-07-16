<?php

declare(strict_types=1);

namespace OCA\FileDropBatch\Service;

use OCP\Http\Client\IClientService;

/**
 * Fetches a linked Google Sheet's rows via the Sheets API v4 and hands them
 * to CsvReader::parseRows() - the exact same header-validation/row-shaping
 * used for an uploaded CSV or a manually-entered row, so a sheet is just a
 * third source feeding the one shared row shape.
 */
class GoogleSheetsService {
    public function __construct(
        private GoogleAuthService $googleAuth,
        private CsvReader $csvReader,
        private IClientService $clientService,
    ) {
    }

    public function extractSpreadsheetId(string $url): ?string {
        if (preg_match('#/spreadsheets/d/([a-zA-Z0-9_-]+)#', $url, $matches) === 1) {
            return $matches[1];
        }

        // Also accept a bare spreadsheet ID pasted directly, not just a full URL.
        if (preg_match('#^[a-zA-Z0-9_-]{20,}$#', trim($url)) === 1) {
            return trim($url);
        }

        return null;
    }

    /**
     * @return array<int, array<string, string>> parsed rows, same shape CsvReader::parseRows() returns
     * @throws \RuntimeException if not connected, the sheet can't be read, or required headers are missing
     */
    public function fetchRows(string $spreadsheetId): array {
        $accessToken = $this->googleAuth->getValidAccessToken();

        $url = 'https://sheets.googleapis.com/v4/spreadsheets/' . rawurlencode($spreadsheetId) . '/values/A:Z';
        $client = $this->clientService->newClient();
        $response = $client->get($url, [
            'headers' => ['Authorization' => 'Bearer ' . $accessToken],
            'http_errors' => false,
        ]);

        $body = json_decode((string)$response->getBody(), true);
        if ($response->getStatusCode() >= 300) {
            $message = is_array($body) && isset($body['error']['message'])
                ? (string)$body['error']['message']
                : 'Google Sheets request failed (HTTP ' . $response->getStatusCode() . ')';
            throw new \RuntimeException($message);
        }

        $values = is_array($body) && isset($body['values']) && is_array($body['values']) ? $body['values'] : [];

        return $this->csvReader->parseRows($values);
    }
}
