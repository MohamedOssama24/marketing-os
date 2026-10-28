<?php
declare(strict_types=1);

// Prova del montaggio video senza database: foto e logo generati, brand e testo di esempio.
// Uso: php tests/video_render_check.php [story|square] [cartella di uscita]
require __DIR__ . '/../src/bootstrap.php';

$format = $argv[1] ?? 'story';
$outDir = $argv[2] ?? sys_get_temp_dir();
$tmp = sys_get_temp_dir();

$photos = [];
foreach ([[33, 150, 243], [255, 178, 54]] as $i => [$r, $g, $b]) {
    $im = imagecreatetruecolor(1600, 1200);
    for ($y = 0; $y < 1200; $y++) imageline($im, 0, $y, 1600, $y, imagecolorallocate($im, (int)($r * $y / 1200), (int)($g * $y / 1200), (int)(80 + $b * (1 - $y / 1200) / 2)));
    imagefilledellipse($im, 800, 600, 700, 700, imagecolorallocate($im, 240, 240, 245));
    imagejpeg($im, $photos[] = "{$tmp}/prova-foto-{$i}.jpg", 90);
}
$logo = imagecreatetruecolor(600, 140);
imagefilledrectangle($logo, 0, 0, 600, 140, imagecolorallocate($logo, 255, 255, 255));
imagettftext($logo, 60, 0, 20, 100, imagecolorallocate($logo, 31, 54, 92), FontProvider::ttf(null, 800), 'EUSPRAY');
imagepng($logo, $logoPath = "{$tmp}/prova-logo.png");

$brand = ['name' => 'Euspray', 'primary_color' => '#2196F3', 'secondary_color' => '#1F365C', 'font_family' => null, 'website_url' => 'https://www.euspray.com'];
$item = [
    'title' => 'Ugelli spruzzatori per ogni settore',
    'body' => "Scopri la nostra gamma di ugelli spruzzatori 🚿 per polverizzare liquidi in ogni settore industriale. Ugelli a cono pieno, a cono vuoto e a getto piatto, con accessori dedicati. Assistenza tecnica e servizio personalizzato per la tua linea di produzione.\n\n👉 Richiedi un preventivo\n\n#ugelli #industria\n\n---\nNote per il revisore: verificare le foto.",
    'visual' => ['headline' => 'Ugelli per ogni esigenza', 'badge' => 'Novità', 'asset_id' => null],
];
$scenes = VideoStoryboard::fromPost($item, $brand, $photos);
$output = "{$outDir}/prova-video-{$format}.mp4";
$start = microtime(true);
VideoRenderer::render($scenes, $brand, $format, $logoPath, null, $output);
printf("Scene: %d (%s)\nTempo di montaggio: %.1f s\nFile: %s (%d KB)\n", count($scenes), implode(', ', array_column($scenes, 'type')), microtime(true) - $start, $output, filesize($output) / 1024);
