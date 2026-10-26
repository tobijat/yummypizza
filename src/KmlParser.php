<?php

declare(strict_types=1);

namespace YummyPizza;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;
use RuntimeException;

final class ParseResult
{
    /**
     * @param list<Place> $places
     * @param list<string> $skipped
     */
    public function __construct(
        public readonly array $places,
        public readonly array $skipped,
    ) {
    }

    public function summary(): string
    {
        $visits = 0;
        $multiple = 0;
        foreach ($this->places as $place) {
            $count = count($place->visits);
            $visits += $count;
            if ($count > 1) {
                $multiple++;
            }
        }

        $lines = [
            'Parsed ' . count($this->places) . ' places and ' . $visits . ' visits.',
            $multiple . ' places have more than one visit.',
        ];

        if ($this->skipped === []) {
            $lines[] = 'Nothing was skipped.';
        } else {
            $lines[] = count($this->skipped) . ' entries were skipped:';
            foreach ($this->skipped as $skipped) {
                $lines[] = '  ' . $skipped;
            }
        }

        return implode(PHP_EOL, $lines) . PHP_EOL;
    }
}

final class KmlParser
{
    private const QUALITY = '/^Q\s*:?\s*(\d{1,2})$/u';

    private const PRICE = '/^PL\s*:?\s*(\d{1,2})$/u';

    // Same pin scores as the previous app. Lower is better, matching school grades.
    private const GRADES = [
        '#icon-22' => 1,   // blue
        '#icon-61' => 2,   // green
        '#icon-157' => 3,  // yellow
        '#icon-123' => 4,  // red
    ];

    public function download(string $url): string
    {
        $context = stream_context_create([
            'http' => [
                'timeout' => 30,
                'header' => "User-Agent: yummypizza/2\r\nAccept: application/xml,text/xml,*/*\r\n",
            ],
        ]);

        $kml = @file_get_contents($url, false, $context);
        if (!is_string($kml) || $kml === '') {
            throw new RuntimeException('Could not download the pizza map.');
        }
        if (strlen($kml) > 5_000_000) {
            throw new RuntimeException('The pizza map download is unexpectedly large.');
        }
        if (!str_contains($kml, '<kml')) {
            throw new RuntimeException('The pizza map download was not KML.');
        }

        return preg_replace('/^\xEF\xBB\xBF/', '', $kml) ?? $kml;
    }

    public function parse(string $kml): ParseResult
    {
        $document = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $loaded = $document->loadXML($kml, LIBXML_NONET);
        $errors = libxml_get_errors();
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if ($loaded === false) {
            $detail = $errors[0]->message ?? 'invalid XML';
            throw new RuntimeException('Could not read the pizza map: ' . trim($detail));
        }

        $xpath = new DOMXPath($document);
        $nodes = $xpath->query('//*[local-name()="Placemark"]');
        if ($nodes === false) {
            throw new RuntimeException('Could not read placemarks from the pizza map.');
        }

        $skipped = [];
        /** @var array<string, Place> $places */
        $places = [];

        foreach ($nodes as $node) {
            if (!$node instanceof DOMElement) {
                continue;
            }

            $name = $this->childText($xpath, $node, 'name');
            if ($name === '') {
                $skipped[] = 'Unnamed placemark';
                continue;
            }

            $grade = $this->grade($this->childText($xpath, $node, 'styleUrl'));
            if ($grade === null) {
                $skipped[] = $name . ': unknown pin';
                continue;
            }

            $coordinates = $this->coordinates($this->pointCoordinates($xpath, $node));
            if ($coordinates === null) {
                $skipped[] = $name . ': missing coordinates';
                continue;
            }

            [$latitude, $longitude, $latitudeText, $longitudeText] = $coordinates;
            $uniqueLocation = hash('sha256', $name . '|' . $latitudeText . '|' . $longitudeText);
            $description = $this->markup($this->childElement($xpath, $node, 'description'));
            $visits = $this->visits($description, $name, $uniqueLocation, $skipped);
            if ($visits === []) {
                continue;
            }

            $place = new Place($name, $grade, $latitude, $longitude, $uniqueLocation, $visits);
            if (isset($places[$uniqueLocation])) {
                $place = $places[$uniqueLocation]->withMoreVisits($visits);
            }
            $places[$uniqueLocation] = $place;
        }

        return new ParseResult(array_values($places), $skipped);
    }

    /**
     * @param list<string> $skipped
     * @return list<Visit>
     */
    private function visits(string $description, string $name, string $uniqueLocation, array &$skipped): array
    {
        $chunks = [];
        $current = ['date' => null, 'lines' => []];
        $started = false;

        foreach ($this->lines($description) as $line) {
            $date = $this->dateLine($line);
            if ($date === '') {
                continue;
            }
            if (is_string($date)) {
                if ($started) {
                    $chunks[] = $current;
                }
                $current = ['date' => $date, 'lines' => []];
                $started = true;
                continue;
            }
            $started = true;
            $current['lines'][] = $line;
        }
        if ($started) {
            $chunks[] = $current;
        }

        $visits = [];
        foreach ($chunks as $index => $chunk) {
            $quality = null;
            $price = null;
            $text = [];
            foreach ($chunk['lines'] as $line) {
                if (preg_match(self::QUALITY, $line, $matches) === 1) {
                    $quality = (int) $matches[1];
                    continue;
                }
                if (preg_match(self::PRICE, $line, $matches) === 1) {
                    $price = (int) $matches[1];
                    continue;
                }
                $text[] = $line;
            }

            $label = $name . ($chunk['date'] === null ? '' : ' (' . $this->displayDate($chunk['date']) . ')');
            if ($quality === null || $price === null || $quality < 1 || $quality > 6 || $price < 1 || $price > 6) {
                $skipped[] = $label . ': missing quality or price';
                continue;
            }

            $date = $chunk['date'];
            $visits[] = new Visit(
                $date,
                trim(implode("\n", $text)),
                $quality,
                $price,
                hash('sha256', $uniqueLocation . '|' . ($date ?? '') . '|' . $quality . '|' . $price . '|' . $index),
            );
        }

        if ($visits === [] && $chunks === []) {
            $skipped[] = $name . ': no scored visit';
        }

        return $visits;
    }

    /**
     * @return list<string>
     */
    private function lines(string $description): array
    {
        $text = str_replace(["\u{00A0}", "\u{202F}", "\u{2007}", "\u{FEFF}"], ' ', $description);
        $text = preg_replace('/<br\s*\/?>/i', "\n", $text) ?? $text;
        $text = str_replace('|', "\n", $text);

        $lines = [];
        foreach (preg_split("/\r\n|\n|\r/", $text) ?: [] as $line) {
            $line = trim($line);
            if ($line !== '') {
                $lines[] = $line;
            }
        }

        return $lines;
    }

    private function dateLine(string $line): ?string
    {
        if (preg_match('/^(\d{1,2})\.(\d{1,2})\.(\d{4})$/', $line, $matches) !== 1) {
            return null;
        }

        $day = (int) $matches[1];
        $month = (int) $matches[2];
        $year = (int) $matches[3];
        if (!checkdate($month, $day, $year) || $year < 1990 || $year > 2100) {
            return '';
        }

        return sprintf('%04d-%02d-%02d', $year, $month, $day);
    }

    private function displayDate(string $iso): string
    {
        $time = strtotime($iso . ' 00:00:00');
        if ($time === false) {
            return $iso;
        }

        return date('j.n.Y', $time);
    }

    private function grade(string $styleUrl): ?int
    {
        if (preg_match('/#(icon-\d+)/', $styleUrl, $matches) !== 1) {
            return null;
        }

        return self::GRADES['#' . $matches[1]] ?? null;
    }

    /**
     * @return array{0: float, 1: float, 2: string, 3: string}|null
     */
    private function coordinates(string $raw): ?array
    {
        $raw = preg_replace('/\s+/', '', $raw) ?? '';
        $parts = explode(',', $raw);
        if (count($parts) < 2 || !is_numeric($parts[0]) || !is_numeric($parts[1])) {
            return null;
        }

        $longitude = (float) $parts[0];
        $latitude = (float) $parts[1];
        if ($latitude < -90 || $latitude > 90 || $longitude < -180 || $longitude > 180) {
            return null;
        }

        return [$latitude, $longitude, trim($parts[1]), trim($parts[0])];
    }

    private function pointCoordinates(DOMXPath $xpath, DOMElement $placemark): string
    {
        $nodes = $xpath->query('.//*[local-name()="Point"]/*[local-name()="coordinates"]', $placemark);
        if ($nodes === false || $nodes->length === 0) {
            return '';
        }

        $node = $nodes->item(0);

        return $node instanceof DOMNode ? trim($node->textContent) : '';
    }

    private function childText(DOMXPath $xpath, DOMElement $parent, string $name): string
    {
        $element = $this->childElement($xpath, $parent, $name);
        if (!$element instanceof DOMElement) {
            return '';
        }
        if ($name === 'description') {
            return $this->markup($element);
        }

        return trim($element->textContent);
    }

    private function childElement(DOMXPath $xpath, DOMElement $parent, string $name): ?DOMElement
    {
        $nodes = $xpath->query('./*[local-name()="' . $name . '"]', $parent);
        if ($nodes === false || $nodes->length === 0) {
            return null;
        }

        $node = $nodes->item(0);

        return $node instanceof DOMElement ? $node : null;
    }

    private function markup(?DOMNode $node): string
    {
        if (!$node instanceof DOMNode) {
            return '';
        }

        $chunks = [];
        foreach ($node->childNodes as $child) {
            $localName = strtolower($child->localName ?? $child->nodeName);
            if ($child instanceof DOMElement && $localName === 'br') {
                $chunks[] = "\n";
                continue;
            }
            if ($child instanceof DOMElement) {
                $chunks[] = $this->markup($child);
                continue;
            }
            $chunks[] = $child->textContent;
        }

        return trim(implode('', $chunks));
    }
}
