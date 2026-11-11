<?php
/**
 * Tiny dependency-free PDF 1.4 writer (A4 portrait, 595 x 842 pt).
 *
 *   $pdf = new PdfDoc('Invoice LXA/26-27/0001');
 *   $pdf->addPage();
 *   $pdf->setFont('Helvetica-Bold', 18)->setFill(8, 38, 77)->text(40, 60, 'TAX INVOICE');
 *   $bytes = $pdf->output();
 *
 * Coordinates are in points from the TOP-LEFT corner (converted to PDF space internally);
 * text y is the baseline. Uses the 14 standard fonts (Helvetica family only) with
 * WinAnsiEncoding, so no font files are embedded. Those fonts have no ₹ glyph - callers
 * print "INR". Text is converted from UTF-8 to Windows-1252; characters outside it
 * (e.g. Devanagari) become "?".
 */
declare(strict_types=1);

if (!defined('LAXYAM_BOOTSTRAPPED')) {
    http_response_code(404);
    exit;
}

final class PdfDoc
{
    public const W = 595.28;
    public const H = 841.89;

    /** Adobe AFM advance widths (1/1000 em) for WinAnsi codes 32..255. */
    private const WIDTHS = [
        'Helvetica' => [
            278, 278, 355, 556, 556, 889, 667, 191, 333, 333, 389, 584, 278, 333, 278, 278,
            556, 556, 556, 556, 556, 556, 556, 556, 556, 556, 278, 278, 584, 584, 584, 556,
            1015, 667, 667, 722, 722, 667, 611, 778, 722, 278, 500, 667, 556, 833, 722, 778,
            667, 778, 722, 667, 611, 722, 667, 944, 667, 667, 611, 278, 278, 278, 469, 556,
            333, 556, 556, 500, 556, 556, 278, 556, 556, 222, 222, 500, 222, 833, 556, 556,
            556, 556, 333, 500, 278, 556, 500, 722, 500, 500, 500, 334, 260, 334, 584, 350,
            556, 350, 222, 556, 333, 1000, 556, 556, 333, 1000, 667, 333, 1000, 350, 611, 350,
            350, 222, 222, 333, 333, 350, 556, 1000, 333, 1000, 500, 333, 944, 350, 500, 667,
            278, 333, 556, 556, 556, 556, 260, 556, 333, 737, 370, 556, 584, 333, 737, 333,
            400, 584, 333, 333, 333, 556, 537, 278, 333, 333, 365, 556, 834, 834, 834, 611,
            667, 667, 667, 667, 667, 667, 1000, 722, 667, 667, 667, 667, 278, 278, 278, 278,
            722, 722, 778, 778, 778, 778, 778, 584, 778, 722, 722, 722, 722, 667, 667, 611,
            556, 556, 556, 556, 556, 556, 889, 500, 556, 556, 556, 556, 278, 278, 278, 278,
            556, 556, 556, 556, 556, 556, 556, 584, 611, 556, 556, 556, 556, 500, 556, 500,
        ],
        'Helvetica-Bold' => [
            278, 333, 474, 556, 556, 889, 722, 238, 333, 333, 389, 584, 278, 333, 278, 278,
            556, 556, 556, 556, 556, 556, 556, 556, 556, 556, 333, 333, 584, 584, 584, 611,
            975, 722, 722, 722, 722, 667, 611, 778, 722, 278, 556, 722, 611, 833, 722, 778,
            667, 778, 722, 667, 611, 722, 667, 944, 667, 667, 611, 333, 278, 333, 584, 556,
            333, 556, 611, 556, 611, 556, 333, 611, 611, 278, 278, 556, 278, 889, 611, 611,
            611, 611, 389, 556, 333, 611, 556, 778, 556, 556, 500, 389, 280, 389, 584, 350,
            556, 350, 278, 556, 500, 1000, 556, 556, 333, 1000, 667, 333, 1000, 350, 611, 350,
            350, 278, 278, 500, 500, 350, 556, 1000, 333, 1000, 556, 333, 944, 350, 500, 667,
            278, 333, 556, 556, 556, 556, 280, 556, 333, 737, 370, 556, 584, 333, 737, 333,
            400, 584, 333, 333, 333, 611, 556, 278, 333, 333, 365, 556, 834, 834, 834, 611,
            722, 722, 722, 722, 722, 722, 1000, 722, 667, 667, 667, 667, 278, 278, 278, 278,
            722, 722, 778, 778, 778, 778, 778, 584, 778, 722, 722, 722, 722, 667, 667, 611,
            556, 556, 556, 556, 556, 556, 889, 556, 556, 556, 556, 556, 278, 278, 278, 278,
            611, 611, 611, 611, 611, 611, 611, 584, 611, 611, 611, 611, 611, 556, 611, 556,
        ],
    ];

    /** Font name => resource name. Oblique variants share the upright widths. */
    private const FONTS = ['Helvetica' => 'F1', 'Helvetica-Bold' => 'F2', 'Helvetica-Oblique' => 'F3', 'Helvetica-BoldOblique' => 'F4'];

    private array $pages = [];
    private int $page = -1;
    private array $images = [];
    private string $font = 'Helvetica';
    private float $size = 10;

    public function __construct(private string $title = '', private string $author = '')
    {
    }

    public function addPage(): self
    {
        $this->pages[] = '';
        $this->page = count($this->pages) - 1;
        return $this;
    }

    public function pageCount(): int
    {
        return count($this->pages);
    }

    private function out(string $s): void
    {
        if ($this->page < 0) {
            $this->addPage();
        }
        $this->pages[$this->page] .= $s . "\n";
    }

    private static function n(float $v): string
    {
        $s = rtrim(rtrim(number_format($v, 3, '.', ''), '0'), '.');
        return $s === '-0' || $s === '' ? '0' : $s;
    }

    // ---------------------------------------------------------------
    // State: fonts and colours (RGB 0-255)
    // ---------------------------------------------------------------
    /** Every text() call writes its own Tf, so this only changes the current state. */
    public function setFont(string $font, float $size): self
    {
        if (!isset(self::FONTS[$font])) {
            throw new InvalidArgumentException("Unsupported font {$font}");
        }
        $this->font = $font;
        $this->size = $size;
        return $this;
    }

    public function setFill(int $r, int $g, int $b): self
    {
        $this->out(sprintf('%s %s %s rg', self::n($r / 255), self::n($g / 255), self::n($b / 255)));
        return $this;
    }

    public function setStroke(int $r, int $g, int $b): self
    {
        $this->out(sprintf('%s %s %s RG', self::n($r / 255), self::n($g / 255), self::n($b / 255)));
        return $this;
    }

    public function setLineWidth(float $w): self
    {
        $this->out(self::n($w) . ' w');
        return $this;
    }

    /** Hex colour helper: '#08264D' => [8, 38, 77]. */
    public static function hex(string $hex): array
    {
        $hex = ltrim($hex, '#');
        return [hexdec(substr($hex, 0, 2)), hexdec(substr($hex, 2, 2)), hexdec(substr($hex, 4, 2))];
    }

    // ---------------------------------------------------------------
    // Text
    // ---------------------------------------------------------------
    /** UTF-8 -> Windows-1252 bytes (unknown characters become "?"). */
    public static function encode(string $utf8): string
    {
        $utf8 = strtr($utf8, ['₹' => 'INR ', "\u{00A0}" => ' ', "\u{2011}" => '-', "\u{2212}" => '-', "\t" => ' ']);
        $utf8 = preg_replace('/[\x00-\x1F\x7F]/u', '', $utf8) ?? '';
        return (string) mb_convert_encoding($utf8, 'Windows-1252', 'UTF-8');
    }

    /** Width in points of UTF-8 text in a font/size. */
    public function width(string $text, ?string $font = null, ?float $size = null): float
    {
        $font ??= $this->font;
        $size ??= $this->size;
        $table = self::WIDTHS[str_contains($font, 'Bold') ? 'Helvetica-Bold' : 'Helvetica'];
        $w = 0;
        foreach (str_split(self::encode($text)) as $ch) {
            $o = ord($ch);
            $w += $o >= 32 ? $table[$o - 32] : 0;
        }
        return $w * $size / 1000;
    }

    private static function literal(string $bytes): string
    {
        $out = '';
        foreach (str_split($bytes) as $ch) {
            $o = ord($ch);
            $out .= match (true) {
                $ch === '\\', $ch === '(', $ch === ')' => '\\' . $ch,
                $o < 32 || $o > 126 => sprintf('\\%03o', $o),
                default => $ch,
            };
        }
        return '(' . $out . ')';
    }

    /**
     * Draw one line of text. $align: left (x = left edge), right (x = right edge), center (x = centre).
     * $angle rotates counter-clockwise around (x, y) in degrees.
     */
    public function text(float $x, float $y, string $text, string $align = 'left', float $angle = 0): self
    {
        if ($text === '') {
            return $this;
        }
        $w = $this->width($text);
        $dx = match ($align) { 'right' => -$w, 'center' => -$w / 2, default => 0 };
        $py = self::H - $y;
        if ($angle != 0) {
            $a = deg2rad($angle);
            [$c, $s] = [cos($a), sin($a)];
            $tx = $x + $dx * $c;
            $ty = $py + $dx * $s;
            $this->out(sprintf('BT /%s %s Tf %s %s %s %s %s %s Tm %s Tj ET', self::FONTS[$this->font], self::n($this->size),
                self::n($c), self::n($s), self::n(-$s), self::n($c), self::n($tx), self::n($ty), self::literal(self::encode($text))));
        } else {
            $this->out(sprintf('BT /%s %s Tf %s %s Td %s Tj ET', self::FONTS[$this->font], self::n($this->size),
                self::n($x + $dx), self::n($py), self::literal(self::encode($text))));
        }
        return $this;
    }

    /** Word-wrap text to a width; honours explicit newlines; breaks over-long words. */
    public function wrap(string $text, float $maxWidth, ?string $font = null, ?float $size = null): array
    {
        $lines = [];
        foreach (preg_split('/\R/u', trim($text)) as $para) {
            $line = '';
            foreach (preg_split('/\s+/u', trim($para)) as $word) {
                if ($word === '') {
                    continue;
                }
                $try = $line === '' ? $word : $line . ' ' . $word;
                if ($this->width($try, $font, $size) <= $maxWidth) {
                    $line = $try;
                    continue;
                }
                if ($line !== '') {
                    $lines[] = $line;
                }
                // Break a single word that is wider than the box.
                while ($this->width($word, $font, $size) > $maxWidth && mb_strlen($word) > 1) {
                    $cut = mb_strlen($word);
                    while ($cut > 1 && $this->width(mb_substr($word, 0, $cut), $font, $size) > $maxWidth) {
                        $cut--;
                    }
                    $lines[] = mb_substr($word, 0, $cut);
                    $word = mb_substr($word, $cut);
                }
                $line = $word;
            }
            $lines[] = $line;
        }
        return $lines;
    }

    /** Wrapped paragraph from (x, y-baseline). Returns the y of the next line. */
    public function paragraph(float $x, float $y, float $width, string $text, float $leading, string $align = 'left'): float
    {
        foreach ($this->wrap($text, $width) as $line) {
            $ax = match ($align) { 'right' => $x + $width, 'center' => $x + $width / 2, default => $x };
            $this->text($ax, $y, $line, $align);
            $y += $leading;
        }
        return $y;
    }

    // ---------------------------------------------------------------
    // Shapes ($mode: S stroke, F fill, B fill + stroke)
    // ---------------------------------------------------------------
    public function line(float $x1, float $y1, float $x2, float $y2): self
    {
        $this->out(sprintf('%s %s m %s %s l S', self::n($x1), self::n(self::H - $y1), self::n($x2), self::n(self::H - $y2)));
        return $this;
    }

    public function rect(float $x, float $y, float $w, float $h, string $mode = 'S'): self
    {
        $this->out(sprintf('%s %s %s %s re %s', self::n($x), self::n(self::H - $y - $h), self::n($w), self::n($h), self::op($mode)));
        return $this;
    }

    /** Rectangle with rounded corners (Bezier approximation). */
    public function roundedRect(float $x, float $y, float $w, float $h, float $r, string $mode = 'S'): self
    {
        $r = min($r, $w / 2, $h / 2);
        $k = 0.5523 * $r;
        $Y = fn(float $v) => self::n(self::H - $v);
        $X = fn(float $v) => self::n($v);
        $p = sprintf('%s %s m ', $X($x + $r), $Y($y));
        $p .= sprintf('%s %s l ', $X($x + $w - $r), $Y($y));
        $p .= sprintf('%s %s %s %s %s %s c ', $X($x + $w - $r + $k), $Y($y), $X($x + $w), $Y($y + $r - $k), $X($x + $w), $Y($y + $r));
        $p .= sprintf('%s %s l ', $X($x + $w), $Y($y + $h - $r));
        $p .= sprintf('%s %s %s %s %s %s c ', $X($x + $w), $Y($y + $h - $r + $k), $X($x + $w - $r + $k), $Y($y + $h), $X($x + $w - $r), $Y($y + $h));
        $p .= sprintf('%s %s l ', $X($x + $r), $Y($y + $h));
        $p .= sprintf('%s %s %s %s %s %s c ', $X($x + $r - $k), $Y($y + $h), $X($x), $Y($y + $h - $r + $k), $X($x), $Y($y + $h - $r));
        $p .= sprintf('%s %s l ', $X($x), $Y($y + $r));
        $p .= sprintf('%s %s %s %s %s %s c h ', $X($x), $Y($y + $r - $k), $X($x + $r - $k), $Y($y), $X($x + $r), $Y($y));
        $this->out($p . self::op($mode));
        return $this;
    }

    private static function op(string $mode): string
    {
        return match (strtoupper($mode)) { 'F' => 'f', 'B', 'FD', 'DF' => 'B', default => 'S' };
    }

    /** Run drawing commands rotated by $angle degrees around (cx, cy). */
    public function rotated(float $cx, float $cy, float $angle, callable $draw): self
    {
        $a = deg2rad($angle);
        [$c, $s] = [cos($a), sin($a)];
        $py = self::H - $cy;
        // translate(c) * rotate * translate(-c)
        $e = $cx - $c * $cx + $s * $py;
        $f = $py - $s * $cx - $c * $py;
        $this->out(sprintf('q %s %s %s %s %s %s cm', self::n($c), self::n($s), self::n(-$s), self::n($c), self::n($e), self::n($f)));
        $draw($this);
        $this->out('Q');
        return $this;
    }

    // ---------------------------------------------------------------
    // Images (JPEG passthrough; PNG/WebP/GIF converted with GD)
    // ---------------------------------------------------------------
    /** Register image bytes; returns a handle for image() or null when unsupported. */
    public function addImage(string $bytes): ?string
    {
        $jpeg = self::toJpeg($bytes);
        if ($jpeg === null) {
            return null;
        }
        $info = self::jpegInfo($jpeg);
        if ($info === null) {
            return null;
        }
        $name = 'I' . (count($this->images) + 1);
        $this->images[$name] = $info + ['data' => $jpeg];
        return $name;
    }

    /** [width, height] in pixels of a registered image. */
    public function imageSize(string $handle): array
    {
        return [$this->images[$handle]['w'], $this->images[$handle]['h']];
    }

    public function image(string $handle, float $x, float $y, float $w, float $h): self
    {
        $this->out(sprintf('q %s 0 0 %s %s %s cm /%s Do Q', self::n($w), self::n($h), self::n($x), self::n(self::H - $y - $h), $handle));
        return $this;
    }

    /** JPEG bytes as-is; other formats re-encoded on a white background when GD is available. */
    private static function toJpeg(string $bytes): ?string
    {
        if (str_starts_with($bytes, "\xFF\xD8")) {
            return $bytes;
        }
        if (!function_exists('imagecreatefromstring') || !function_exists('imagejpeg')) {
            return null;
        }
        $src = @imagecreatefromstring($bytes);
        if (!$src) {
            return null;
        }
        $w = imagesx($src);
        $h = imagesy($src);
        $dst = imagecreatetruecolor($w, $h);
        imagefill($dst, 0, 0, imagecolorallocate($dst, 255, 255, 255));
        imagealphablending($dst, true);
        imagecopy($dst, $src, 0, 0, 0, 0, $w, $h);
        ob_start();
        imagejpeg($dst, null, 92);
        $out = (string) ob_get_clean();
        return $out !== '' ? $out : null;
    }

    /** Parse the SOF marker for size and colour components. */
    private static function jpegInfo(string $data): ?array
    {
        $len = strlen($data);
        $i = 2;
        while ($i + 9 < $len) {
            if ($data[$i] !== "\xFF") {
                $i++;
                continue;
            }
            $marker = ord($data[$i + 1]);
            if ($marker === 0xD8 || $marker === 0x01 || ($marker >= 0xD0 && $marker <= 0xD7) || $marker === 0xFF) {
                $i += $marker === 0xFF ? 1 : 2;
                continue;
            }
            $segLen = (ord($data[$i + 2]) << 8) | ord($data[$i + 3]);
            if (in_array($marker, [0xC0, 0xC1, 0xC2, 0xC3, 0xC5, 0xC6, 0xC7, 0xC9, 0xCA, 0xCB, 0xCD, 0xCE, 0xCF], true)) {
                return [
                    'bpc' => ord($data[$i + 4]),
                    'h' => (ord($data[$i + 5]) << 8) | ord($data[$i + 6]),
                    'w' => (ord($data[$i + 7]) << 8) | ord($data[$i + 8]),
                    'comp' => ord($data[$i + 9]),
                ];
            }
            $i += 2 + $segLen;
        }
        return null;
    }

    // ---------------------------------------------------------------
    // Serialisation
    // ---------------------------------------------------------------
    public function output(): string
    {
        if (!$this->pages) {
            $this->addPage();
        }
        $compress = function_exists('gzcompress');
        $objects = []; // id => body (without "n 0 obj")
        $next = 1;
        $alloc = function () use (&$next): int {
            return $next++;
        };

        $catalogId = $alloc();
        $pagesId = $alloc();
        $fontIds = [];
        foreach (self::FONTS as $font => $res) {
            $fontIds[$res] = $alloc();
            $objects[$fontIds[$res]] = "<< /Type /Font /Subtype /Type1 /BaseFont /{$font} /Encoding /WinAnsiEncoding >>";
        }
        $imageIds = [];
        foreach ($this->images as $name => $img) {
            $imageIds[$name] = $id = $alloc();
            $cs = match ($img['comp']) { 1 => '/DeviceGray', 4 => '/DeviceCMYK', default => '/DeviceRGB' };
            $decode = $img['comp'] === 4 ? ' /Decode [1 0 1 0 1 0 1 0]' : '';
            $objects[$id] = ['dict' => "<< /Type /XObject /Subtype /Image /Width {$img['w']} /Height {$img['h']} /ColorSpace {$cs} /BitsPerComponent {$img['bpc']}{$decode} /Filter /DCTDecode", 'stream' => $img['data']];
        }
        $resources = '<< /ProcSet [/PDF /Text /ImageB /ImageC] /Font << ' . implode(' ', array_map(fn($r, $id) => "/{$r} {$id} 0 R", array_keys($fontIds), $fontIds)) . ' >>'
            . ($imageIds ? ' /XObject << ' . implode(' ', array_map(fn($r, $id) => "/{$r} {$id} 0 R", array_keys($imageIds), $imageIds)) . ' >>' : '') . ' >>';
        $resId = $alloc();
        $objects[$resId] = $resources;

        $kids = [];
        foreach ($this->pages as $content) {
            $pageId = $alloc();
            $contentId = $alloc();
            $kids[] = "{$pageId} 0 R";
            $objects[$pageId] = "<< /Type /Page /Parent {$pagesId} 0 R /MediaBox [0 0 " . self::n(self::W) . ' ' . self::n(self::H) . "] /Resources {$resId} 0 R /Contents {$contentId} 0 R >>";
            $data = $compress ? gzcompress($content, 6) : $content;
            $objects[$contentId] = ['dict' => '<<' . ($compress ? ' /Filter /FlateDecode' : ''), 'stream' => $data];
        }
        $objects[$pagesId] = '<< /Type /Pages /Kids [' . implode(' ', $kids) . '] /Count ' . count($kids) . ' >>';
        $objects[$catalogId] = "<< /Type /Catalog /Pages {$pagesId} 0 R >>";
        $infoId = $alloc();
        $date = 'D:' . date('YmdHis') . str_replace(':', "'", date('P')) . "'";
        $objects[$infoId] = '<< /Producer (Laxyam Academy) /Title ' . self::literal(self::encode($this->title))
            . ($this->author !== '' ? ' /Author ' . self::literal(self::encode($this->author)) : '') . " /CreationDate ({$date}) >>";

        $pdf = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
        $offsets = [];
        ksort($objects);
        foreach ($objects as $id => $body) {
            $offsets[$id] = strlen($pdf);
            if (is_array($body)) {
                $pdf .= "{$id} 0 obj\n{$body['dict']} /Length " . strlen($body['stream']) . " >>\nstream\n{$body['stream']}\nendstream\nendobj\n";
            } else {
                $pdf .= "{$id} 0 obj\n{$body}\nendobj\n";
            }
        }
        $xref = strlen($pdf);
        $count = $next; // object 0 + 1..next-1
        $pdf .= "xref\n0 {$count}\n0000000000 65535 f \n";
        for ($i = 1; $i < $count; $i++) {
            $pdf .= sprintf("%010d 00000 n \n", $offsets[$i]);
        }
        $pdf .= "trailer\n<< /Size {$count} /Root {$catalogId} 0 R /Info {$infoId} 0 R >>\nstartxref\n{$xref}\n%%EOF\n";
        return $pdf;
    }
}
