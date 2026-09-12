<?php
return ['default' => env('STORAGE_DISK', 'local'), 'disks' => ['local' => ['driver' => 'local'], 's3' => ['driver' => 's3-compatible']]];
