<?php
// Photo privacy: students only ever get a black & white photo, the colour original is admin/reporter-only.
//   Storage bucket "items"      (public)  <- what students see. Greyscale while "black & white" is on.
//   Storage bucket "originals"  (private) <- the colour original, served only by api/item_photo.php (reporter + admins)
//                                            and api/admin_items.php?action=photo&id=ID (admins).
// The browser (db.js) makes the black & white copy and sends the colour original too. There is no server-side image
// conversion on Vercel (no GD), so photos that were never converted are converted by an admin's browser (db.js does this
// automatically through api/admin_items.php?action=bw_pending / bw_upload).
require_once __DIR__ . '/match_lib.php';
require_once __DIR__ . '/storage.php';

/** Turn black & white on/off for one item's photo. Throws RuntimeException on failure. */
function set_photo_bw(array $item, bool $on): void {
  $name = basename((string)$item['photo']);
  if ($name === '') return;
  if ($on) {
    if ((int)($item['photo_bw'] ?? 0) === 1) return;     // already converted by the browser
    throw new RuntimeException('This older photo is converted to black & white by an admin browser (open the admin dashboard and wait a moment), not by the server.', 409);
  }
  $o = storage_get('originals', $name);                   // off: put the colour original back as the public photo
  if ($o) {
    if (!storage_put('items', $name, $o['data'], 'image/jpeg')) throw new RuntimeException('Could not restore the original photo.', 500);
    storage_delete('originals', $name);
  }
}
/** Kept so items.php keeps working: the server cannot convert images here, the browser already did. */
function apply_default_bw(PDO $pdo, int $itemId): void {}

/** Store the colour original that the browser sent. $publicName = the file already saved in the "items" bucket. */
function store_original_upload(string $dataUrl, string $publicName): bool {
  if (!preg_match('#^data:image/(?:jpeg|png|webp|gif);base64,(.+)$#s', $dataUrl, $m)) return false;
  $bin = base64_decode($m[1], true);
  if ($bin === false || $bin === '' || strlen($bin) > 5 * 1024 * 1024) return false;
  $mime = (new finfo(FILEINFO_MIME_TYPE))->buffer($bin);
  if (!isset(IMG_TYPES[$mime])) return false;
  return storage_put('originals', basename($publicName), $bin, $mime);
}
