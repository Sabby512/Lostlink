<?php
// "Possible match" for the logged-in user: found items from other people that look like this user's lost reports.
// Anyone can call it (students and admins). Only public information is returned: no exact location, date or private details.
require __DIR__ . '/../lib/config.php';
require_once __DIR__ . '/../lib/match_lib.php';
$uid = require_login();
$pdo = db();
out(array_map(fn($m) => ['lostId' => $m['lostId'], 'lostName' => $m['lostName'], 'foundId' => $m['foundId'], 'name' => $m['name'],
                         'category' => $m['category'], 'area' => $m['area'], 'photo' => $m['photo'], 'level' => $m['level'], 'createdAt' => $m['createdAt']],
              possible_matches_for($pdo, $uid, 5)));
