<?php

declare(strict_types=1);

$summary = \App\Services\LotterySeeder::run();
echo "Seeded sample history: {$summary['from']} through {$summary['to']} (Asia/Yangon).\n";
echo "Daily times: 08:00 AM, 10:00 AM, 12:00 PM, 02:00 PM, 04:00 PM.\n";
echo "Results inserted: {$summary['inserted']}; existing results preserved: {$summary['preserved']}.\n";
echo $summary['adminCreated']
    ? "Admin created. Initial credentials: storage/initial-admin.txt (local installations).\n"
    : "Existing admin preserved; password unchanged.\n";
