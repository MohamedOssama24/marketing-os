<?php
declare(strict_types=1);

/**
 * Monta un video MP4 da uno storyboard, gratis e in locale:
 * GD disegna le scene (colori, logo, testi del brand), FFmpeg anima le foto, unisce le scene e aggiunge la musica.
 */
final class VideoRenderer
{
    public const FORMATS = ['story' => [1080, 1920], 'square' => [1080, 1080]];
    private const FPS = 30;
    private const FADE = 0.35;
    private const TIMEOUT_SECONDS = 240;

    /** Scrive il video in $outputPath. */
    public static function render(array $scenes, array $brand, string $format, ?string $logoPath, ?string $musicPath, string $outputPath): void
    {
        [$w, $h] = self::FORMATS[$format] ?? throw new InvalidArgumentException("Formato video non valido: {$format}");
        $dir = sys_get_temp_dir() . '/video-' . bin2hex(random_bytes(6));
        if (!mkdir($dir, 0700, true)) throw new RuntimeException('Impossibile creare la cartella temporanea del video.');

        try {
            $style = [
                'w' => $w, 'h' => $h,
                'primary' => self::rgb($brand['primary_color'] ?? null) ?? [91, 75, 219],
                'secondary' => self::rgb($brand['secondary_color'] ?? null),
                'bold' => FontProvider::ttf($brand['font_family'] ?? null, 800),
                'regular' => FontProvider::ttf($brand['font_family'] ?? null, 500),
                'logo' => $logoPath ? (@imagecreatefromstring((string)file_get_contents($logoPath)) ?: null) : null,
                'name' => (string)($brand['name'] ?? ''),
            ];
            $style['secondary'] ??= self::mix($style['primary'], [255, 255, 255], 0.6);

            $clips = [];
            $total = 0.0;
            foreach ($scenes as $i => $scene) {
                $duration = (float)$scene['duration'];
                $frames = (int)round($duration * self::FPS);
                $fade = sprintf('fade=t=in:st=0:d=%1$.2f,fade=t=out:st=%2$.2f:d=%1$.2f', self::FADE, $duration - self::FADE);
                $clip = "{$dir}/scene-{$i}.mp4";
                $overlay = "{$dir}/overlay-{$i}.png";

                if ($scene['type'] === 'photo') {
                    // Foto più grande del video, così lo zoom lento (effetto Ken Burns) resta nitido.
                    $photo = "{$dir}/photo-{$i}.jpg";
                    self::coverPhoto($scene['photo'], $photo, (int)($w * 1.5), (int)($h * 1.5));
                    imagepng(self::photoOverlay($style, $scene), $overlay);
                    $zoom = "zoompan=z='1+0.12*on/{$frames}':x='iw/2-(iw/zoom/2)':y='ih/2-(ih/zoom/2)':d={$frames}:s={$w}x{$h}:fps=" . self::FPS;
                    self::ffmpeg(['-i', $photo, '-loop', '1', '-t', (string)$duration, '-i', $overlay,
                        '-filter_complex', "[0:v]{$zoom}[bg];[bg][1:v]overlay=0:0,{$fade},format=yuv420p[v]",
                        '-map', '[v]', '-frames:v', (string)$frames, ...self::encoding(), $clip]);
                } else {
                    imagepng(self::cardFrame($style, $scene), $overlay);
                    self::ffmpeg(['-loop', '1', '-t', (string)$duration, '-i', $overlay,
                        '-vf', "{$fade},format=yuv420p", '-frames:v', (string)$frames, ...self::encoding(), $clip]);
                }
                $clips[] = $clip;
                $total += $duration;
            }

            $list = "{$dir}/scenes.txt";
            file_put_contents($list, implode("\n", array_map(fn($c) => "file '" . basename($c) . "'", $clips)) . "\n");
            $video = "{$dir}/video.mp4";
            self::ffmpeg(['-f', 'concat', '-safe', '0', '-i', $list, '-c', 'copy', $video]);

            // Musica in loop con dissolvenza in entrata e in uscita; senza musica una traccia muta, per compatibilità con i social.
            $audioInput = $musicPath ? ['-stream_loop', '-1', '-i', $musicPath] : ['-f', 'lavfi', '-i', 'anullsrc=r=44100:cl=stereo'];
            $fadeOut = max(0.0, $total - 1.5);
            self::ffmpeg(['-i', $video, ...$audioInput, '-map', '0:v', '-map', '1:a', '-c:v', 'copy', '-c:a', 'aac', '-b:a', '160k',
                '-af', sprintf('afade=t=in:st=0:d=0.5,afade=t=out:st=%.2f:d=1.5', $fadeOut), '-shortest', '-movflags', '+faststart', $outputPath]);
        } finally {
            foreach (glob("{$dir}/*") ?: [] as $file) @unlink($file);
            @rmdir($dir);
        }
    }

    /** Apertura, scena di solo testo o chiusura: un fotogramma intero nei colori del brand. */
    private static function cardFrame(array $s, array $scene): GdImage
    {
        [$w, $h] = [$s['w'], $s['h']];
        $story = $h > $w;
        $im = imagecreatetruecolor($w, $h);
        imagealphablending($im, true);

        $isText = $scene['type'] === 'text';
        $bg = $isText ? self::mix($s['primary'], [255, 255, 255], 0.86) : $s['primary'];
        $ink = $isText ? self::readableOnLight($s['primary']) : self::textOn($bg);
        imagefilledrectangle($im, 0, 0, $w, $h, self::color($im, $bg));

        // Cerchi decorativi nel colore secondario, come nelle grafiche dei post.
        $deco = self::color($im, $isText ? $s['primary'] : $s['secondary'], 100);
        imagefilledellipse($im, (int)($w * 0.95), (int)($h * 0.08), (int)($w * 0.9), (int)($w * 0.9), $deco);
        imagefilledellipse($im, (int)($w * 0.05), (int)($h * 0.95), (int)($w * 0.7), (int)($w * 0.7), $deco);

        $maxWidth = (int)($w * 0.82);
        if ($scene['type'] === 'intro') {
            $logoBox = $story ? 240 : 180;
            self::drawLogo($im, $s, (int)(($w - $logoBox) / 2), (int)($h * ($story ? 0.2 : 0.12)), $logoBox);
            $y = (int)($h * ($story ? 0.42 : 0.4));
            if (!empty($scene['badge'])) {
                $y = self::drawPill($im, $s['bold'], $story ? 30 : 26, mb_strtoupper($scene['badge']), (int)($w / 2), $y, $s['secondary'], self::textOn($s['secondary']));
                $y += 40;
            }
            self::drawText($im, $s['bold'], $scene['headline'], $story ? 92 : 78, 40, $maxWidth, 5, (int)($w / 2), $y, $ink, 'center');
        } elseif ($scene['type'] === 'text') {
            $quote = self::color($im, $s['primary']);
            imagettftext($im, $story ? 220 : 180, 0, (int)($w * 0.09), (int)($h * ($story ? 0.36 : 0.36)), $quote, $s['bold'], '“');
            self::drawText($im, $s['bold'], $scene['text'], $story ? 66 : 58, 34, $maxWidth, 7, (int)($w * 0.09), (int)($h * ($story ? 0.4 : 0.4)), $ink, 'left');
            if ($s['name'] !== '') imagettftext($im, 26, 0, (int)($w * 0.09), $h - (int)($h * 0.07), self::color($im, $ink, 40), $s['regular'], mb_strtoupper($s['name']));
        } else {
            $logoBox = $story ? 200 : 160;
            self::drawLogo($im, $s, (int)(($w - $logoBox) / 2), (int)($h * ($story ? 0.24 : 0.14)), $logoBox);
            $y = self::drawText($im, $s['bold'], $scene['headline'], $story ? 84 : 72, 40, $maxWidth, 3, (int)($w / 2), (int)($h * ($story ? 0.46 : 0.45)), $ink, 'center');
            if (!empty($scene['text'])) {
                self::drawPill($im, $s['bold'], $story ? 36 : 32, $scene['text'], (int)($w / 2), $y + 50, $s['secondary'], self::textOn($s['secondary']));
            }
        }
        return $im;
    }

    /** Livello trasparente sopra la foto: piccolo logo e fascia scura con la frase della scena (fa da sottotitolo). */
    private static function photoOverlay(array $s, array $scene): GdImage
    {
        [$w, $h] = [$s['w'], $s['h']];
        $story = $h > $w;
        $im = imagecreatetruecolor($w, $h);
        imagealphablending($im, false);
        imagefilledrectangle($im, 0, 0, $w, $h, imagecolorallocatealpha($im, 0, 0, 0, 127));
        imagesavealpha($im, true);

        $size = $story ? 54 : 46;
        [$fit, $lines] = self::fit($scene['text'], $s['bold'], $size, 30, (int)($w * 0.8), 4);
        $lineHeight = self::lineHeight($s['bold'], $fit);
        $pad = 48;
        $bandHeight = (int)(count($lines) * $lineHeight + $pad * 2);
        $margin = (int)($w * 0.06);
        $top = $h - $bandHeight - (int)($h * ($story ? 0.12 : 0.07));
        // Fascia disegnata senza fusione: gli angoli arrotondati non si sommano alla trasparenza del rettangolo.
        self::roundedRect($im, $margin, $top, $w - $margin, $top + $bandHeight, 32, imagecolorallocatealpha($im, 10, 10, 20, 38));
        imagealphablending($im, true);
        self::drawLines($im, $s['bold'], $fit, $lines, $margin + $pad, $top + $pad, $lineHeight, [255, 255, 255], 'left', 0);

        self::drawLogo($im, $s, $margin, (int)($h * 0.05), $story ? 130 : 110);
        return $im;
    }

    // ---------------------------------------------------------------- disegno

    private static function drawLogo(GdImage $im, array $s, int $x, int $y, int $box): void
    {
        $white = imagecolorallocate($im, 255, 255, 255);
        $blending = imagealphablending($im, false);
        self::roundedRect($im, $x, $y, $x + $box, $y + $box, (int)($box * 0.18), $white);
        imagealphablending($im, true);
        $logo = $s['logo'];
        if (!$logo) {
            $initial = mb_strtoupper(mb_substr($s['name'] ?: '?', 0, 1));
            self::drawText($im, $s['bold'], $initial, (int)($box * 0.45), 10, $box, 1, $x + (int)($box / 2), $y + (int)($box * 0.22), $s['primary'], 'center');
            return;
        }
        $pad = (int)($box * 0.12);
        $lw = imagesx($logo);
        $lh = imagesy($logo);
        $scale = min(($box - 2 * $pad) / $lw, ($box - 2 * $pad) / $lh);
        $dw = (int)($lw * $scale);
        $dh = (int)($lh * $scale);
        imagecopyresampled($im, $logo, $x + (int)(($box - $dw) / 2), $y + (int)(($box - $dh) / 2), 0, 0, $dw, $dh, $lw, $lh);
    }

    /** Etichetta arrotondata centrata in $cx; restituisce la coordinata y del suo bordo inferiore. */
    private static function drawPill(GdImage $im, string $font, int $size, string $text, int $cx, int $y, array $bg, array $fg): int
    {
        $box = imagettfbbox($size, 0, $font, $text);
        $tw = $box[2] - $box[0];
        $th = self::lineHeight($font, $size);
        $padX = (int)($size * 0.9);
        $padY = (int)($size * 0.45);
        $x1 = $cx - (int)($tw / 2) - $padX;
        $x2 = $cx + (int)($tw / 2) + $padX;
        $y2 = $y + $th + 2 * $padY;
        $blending = imagealphablending($im, false);
        self::roundedRect($im, $x1, $y, $x2, $y2, (int)(($y2 - $y) / 2), self::color($im, $bg));
        imagealphablending($im, true);
        self::drawLines($im, $font, $size, [$text], $cx, $y + $padY, $th, $fg, 'center', 0);
        return $y2;
    }

    /** Testo adattato alla larghezza (rimpicciolisce se servono troppe righe); restituisce la y sotto l'ultima riga. */
    private static function drawText(GdImage $im, string $font, string $text, int $size, int $minSize, int $maxWidth, int $maxLines, int $x, int $y, array $rgb, string $align): int
    {
        [$fit, $lines] = self::fit($text, $font, $size, $minSize, $maxWidth, $maxLines);
        $lineHeight = self::lineHeight($font, $fit);
        self::drawLines($im, $font, $fit, $lines, $x, $y, $lineHeight, $rgb, $align, 0);
        return (int)($y + count($lines) * $lineHeight);
    }

    private static function drawLines(GdImage $im, string $font, int $size, array $lines, int $x, int $y, int $lineHeight, array $rgb, string $align, int $alpha): void
    {
        $color = self::color($im, $rgb, $alpha);
        foreach ($lines as $i => $line) {
            $box = imagettfbbox($size, 0, $font, $line);
            $lineX = $align === 'center' ? (int)($x - ($box[2] - $box[0]) / 2) : $x;
            // imagettftext usa la linea di base: la si sposta dell'ascendente per allineare in alto.
            imagettftext($im, $size, 0, $lineX, (int)($y + $i * $lineHeight + $size * 1.28), $color, $font, $line);
        }
    }

    /** @return array{0: int, 1: list<string>} dimensione scelta e righe */
    private static function fit(string $text, string $font, int $size, int $minSize, int $maxWidth, int $maxLines): array
    {
        do {
            $lines = self::wrap($text, $font, $size, $maxWidth);
            if (count($lines) <= $maxLines || $size <= $minSize) break;
            $size = max($minSize, (int)($size * 0.92));
        } while (true);
        if (count($lines) > $maxLines) {
            $lines = array_slice($lines, 0, $maxLines);
            $lines[$maxLines - 1] = rtrim($lines[$maxLines - 1], ' .,;:') . '…';
        }
        return [$size, $lines];
    }

    private static function wrap(string $text, string $font, int $size, int $maxWidth): array
    {
        $lines = [];
        $line = '';
        foreach (preg_split('/\s+/u', trim($text)) ?: [] as $word) {
            $candidate = $line === '' ? $word : "{$line} {$word}";
            $box = imagettfbbox($size, 0, $font, $candidate);
            if ($line !== '' && ($box[2] - $box[0]) > $maxWidth) {
                $lines[] = $line;
                $line = $word;
            } else {
                $line = $candidate;
            }
        }
        if ($line !== '') $lines[] = $line;
        return $lines;
    }

    /** GD misura i font in punti (1 pt ≈ 1,33 px): interlinea di circa 1,2 volte l'altezza reale del carattere. */
    private static function lineHeight(string $font, int $size): int
    {
        return (int)round($size * 1.62);
    }

    private static function roundedRect(GdImage $im, int $x1, int $y1, int $x2, int $y2, int $r, int $color): void
    {
        $r = min($r, (int)(($x2 - $x1) / 2), (int)(($y2 - $y1) / 2));
        imagefilledrectangle($im, $x1 + $r, $y1, $x2 - $r, $y2, $color);
        imagefilledrectangle($im, $x1, $y1 + $r, $x2, $y2 - $r, $color);
        foreach ([[$x1 + $r, $y1 + $r], [$x2 - $r, $y1 + $r], [$x1 + $r, $y2 - $r], [$x2 - $r, $y2 - $r]] as [$cx, $cy]) {
            imagefilledellipse($im, $cx, $cy, $r * 2, $r * 2, $color);
        }
    }

    /** Ritaglia la foto al centro per riempire esattamente $w x $h (come object-fit: cover). */
    private static function coverPhoto(string $source, string $target, int $w, int $h): void
    {
        $src = @imagecreatefromstring((string)file_get_contents($source));
        if (!$src) throw new GenerationException('Una delle foto non è leggibile.');
        $sw = imagesx($src);
        $sh = imagesy($src);
        $scale = max($w / $sw, $h / $sh);
        $cw = (int)($w / $scale);
        $ch = (int)($h / $scale);
        $dst = imagecreatetruecolor($w, $h);
        imagecopyresampled($dst, $src, 0, 0, (int)(($sw - $cw) / 2), (int)(($sh - $ch) / 2), $w, $h, $cw, $ch);
        imagejpeg($dst, $target, 90);
    }

    // ---------------------------------------------------------------- FFmpeg

    private static function encoding(): array
    {
        return ['-c:v', 'libx264', '-preset', 'veryfast', '-crf', '20', '-r', (string)self::FPS, '-pix_fmt', 'yuv420p', '-an'];
    }

    /** Esegue FFmpeg senza shell (argomenti come array) e con un tempo massimo. */
    private static function ffmpeg(array $args): void
    {
        $command = ['timeout', (string)self::TIMEOUT_SECONDS, 'ffmpeg', '-hide_banner', '-loglevel', 'error', '-y', ...array_map('strval', $args)];
        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($process)) throw new RuntimeException('Impossibile avviare FFmpeg.');
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($process);
        if ($code !== 0) {
            error_log('FFmpeg (' . $code . '): ' . mb_substr($stderr . $stdout, -2000));
            throw new GenerationException($code === 124 ? 'Il montaggio del video ha richiesto troppo tempo.' : 'Montaggio del video non riuscito.');
        }
    }

    // ---------------------------------------------------------------- colori

    private static function rgb(?string $hex): ?array
    {
        if (!$hex || !preg_match('/^#([0-9a-f]{2})([0-9a-f]{2})([0-9a-f]{2})$/i', $hex, $m)) return null;
        return [hexdec($m[1]), hexdec($m[2]), hexdec($m[3])];
    }

    private static function color(GdImage $im, array $rgb, int $alpha = 0): int
    {
        return imagecolorallocatealpha($im, (int)$rgb[0], (int)$rgb[1], (int)$rgb[2], $alpha);
    }

    private static function mix(array $a, array $b, float $amount): array
    {
        return array_map(fn($i) => (int)round($a[$i] + ($b[$i] - $a[$i]) * $amount), [0, 1, 2]);
    }

    private static function luminance(array $rgb): float
    {
        [$r, $g, $b] = array_map(function ($v) {
            $c = $v / 255;
            return $c <= 0.03928 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4;
        }, $rgb);
        return 0.2126 * $r + 0.7152 * $g + 0.0722 * $b;
    }

    private static function textOn(array $rgb): array
    {
        return self::luminance($rgb) > 0.45 ? [28, 31, 46] : [255, 255, 255];
    }

    private static function readableOnLight(array $rgb): array
    {
        for ($i = 0; $i < 6 && self::luminance($rgb) > 0.3; $i++) $rgb = self::mix($rgb, [0, 0, 0], 0.2);
        return $rgb;
    }
}
